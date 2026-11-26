<?php
/** Integração operacional com faturador certificado externo (InvoiceXpress).
 * O UniGest NÃO possui certificação AT e não gera por si faturas fiscais.
 */
declare(strict_types=1);

function fiscal_setup(PDO $db):void {
    $db->exec("CREATE TABLE IF NOT EXISTS fiscal_connections (
        company_id INTEGER PRIMARY KEY,
        provider TEXT NOT NULL DEFAULT 'invoicexpress',
        account TEXT NOT NULL,
        account_id INTEGER NOT NULL DEFAULT 0,
        secret_encrypted TEXT NOT NULL,
        configured_at TEXT NOT NULL,
        FOREIGN KEY(company_id) REFERENCES companies(id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS fiscal_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INTEGER NOT NULL,
        order_id INTEGER NOT NULL,
        provider TEXT NOT NULL DEFAULT 'invoicexpress',
        document_type TEXT NOT NULL DEFAULT 'invoice_receipts',
        provider_id INTEGER,
        idempotency_uid TEXT NOT NULL UNIQUE,
        status TEXT NOT NULL DEFAULT 'creating',
        invoice_number TEXT NOT NULL DEFAULT '',
        atcud TEXT NOT NULL DEFAULT '',
        document_url TEXT NOT NULL DEFAULT '',
        internal_total_cents INTEGER NOT NULL,
        provider_total_cents INTEGER,
        error_message TEXT NOT NULL DEFAULT '',
        created_by INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        UNIQUE(company_id,order_id),
        FOREIGN KEY(company_id) REFERENCES companies(id)
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS fiscal_company_recent ON fiscal_documents(company_id,id DESC)');
    // Atualização V20 do relógio dos triggers fiscais (executada uma vez por base de dados).
    if(!one($db,'SELECT 1 FROM schema_migrations WHERE name=?',['fiscal-audit-clock-pt-v20'])){
        foreach(['audit_fiscal_connections_insert','audit_fiscal_connections_update','audit_fiscal_connections_delete','audit_fiscal_insert','audit_fiscal_update'] as $trigger)
            $db->exec('DROP TRIGGER IF EXISTS '.$trigger);
        $db->prepare('INSERT OR IGNORE INTO schema_migrations(name,applied_at) VALUES(?,?)')->execute(['fiscal-audit-clock-pt-v20',now()]);
    }
    // Eventos de configuração sem registar a chave API ou o texto cifrado.
    foreach (['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $event=>$alias) {
        $db->exec("CREATE TRIGGER IF NOT EXISTS audit_fiscal_connections_".strtolower($event)." AFTER $event ON fiscal_connections BEGIN
          INSERT INTO audit_logs(company_id,user_id,source,entity,entity_id,operation,details,created_at)
          VALUES($alias.company_id,g360_actor(),g360_source(),'fiscal_connections',$alias.company_id,'$event',
            SUBSTR('Integração fiscal: ' || $alias.provider || '; conta ' || $alias.account,1,160),g360_pttime());
        END");
    }
    // Inscreve as operações fiscais no sistema de logs, incluindo estados e datas.
    foreach (['INSERT'=>'NEW','UPDATE'=>'NEW'] as $event=>$alias) {
        $db->exec("CREATE TRIGGER IF NOT EXISTS audit_fiscal_".strtolower($event)." AFTER $event ON fiscal_documents BEGIN
          INSERT INTO audit_logs(company_id,user_id,source,entity,entity_id,operation,details,created_at)
          VALUES($alias.company_id,g360_actor(),g360_source(),'fiscal_documents',$alias.id,'$event',
            SUBSTR('Estado fiscal: ' || $alias.status || '; pedido: ' || $alias.order_id,1,300),g360_pttime());
        END");
    }
}

function fiscal_key_file():string {return __DIR__.'/storage/fiscal_master.key';}
function fiscal_master_key(bool $create=false):string {
    $filename=fiscal_key_file();
    // Para dados existentes, o ficheiro legado é PRIORITÁRIO: nunca perde acesso às credenciais cifradas.
    if(!is_file($filename)){
        $envKey=g360_env('FISCAL_MASTER_KEY_BASE64');
        if($envKey!==''){
            $decoded=base64_decode($envKey,true);
            if($decoded===false || strlen($decoded)!==32)throw new RuntimeException('FISCAL_MASTER_KEY_BASE64 inválida: usa 32 bytes em Base64.');
            return $decoded;
        }
    }
    if(!is_file($filename) && $create){
        $key=random_bytes(32);
        // x garante não substituir uma chave existente em condições concorrentes.
        $f=@fopen($filename,'x');
        if($f){fwrite($f,$key);fclose($f);@chmod($filename,0600);}
    }
    $key=@file_get_contents($filename);
    if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Chave de encriptação fiscal indisponível. Restaura storage/fiscal_master.key a partir da cópia de segurança.');
    return $key;
}
function fiscal_encrypt(string $secret):string {
    $iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($secret,'aes-256-gcm',fiscal_master_key(true),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('Não foi possível cifrar a credencial.');
    return base64_encode($iv.$tag.$cipher);
}
function fiscal_decrypt(string $encrypted):string {
    $packed=base64_decode($encrypted,true);
    if($packed===false||strlen($packed)<29)throw new RuntimeException('Credencial fiscal danificada.');
    $value=openssl_decrypt(substr($packed,28),'aes-256-gcm',fiscal_master_key(),OPENSSL_RAW_DATA,substr($packed,0,12),substr($packed,12,16));
    if($value===false)throw new RuntimeException('Não foi possível decifrar a credencial fiscal.');
    return $value;
}
function fiscal_connection(PDO $db,int $cid):?array {
    return one($db,'SELECT * FROM fiscal_connections WHERE company_id=?',[$cid]);
}
function fiscal_credentials(PDO $db,int $cid):array {
    $connection=fiscal_connection($db,$cid);
    if(!$connection)throw new RuntimeException('Configura primeiro a conta do fornecedor certificado em Faturação AT.');
    return ['account'=>(string)$connection['account'],'account_id'=>(int)$connection['account_id'],'api_key'=>fiscal_decrypt((string)$connection['secret_encrypted'])];
}
function fiscal_account_name(string $account):string {
    $account=strtolower(trim($account));
    // Aceita apenas o identificador ou o endereço oficial completo.
    $account=preg_replace('~^https?://~','',$account);
    $account=preg_replace('~\.(?:web|app)\.invoicexpress\.com/?$~','',$account);
    if(!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,58}[a-z0-9])?$/',$account))
        throw new RuntimeException('Conta inválida. Introduz o nome da conta, por exemplo unigeb.');
    return $account;
}
/** HTTPS + destino fixo; sem redirects ou URL arbitrária do utilizador. */
function fiscal_request(array $credentials,string $method,string $path,array $query=[],?array $payload=null,array $accepted=[200]):array {
    if(!extension_loaded('curl'))throw new RuntimeException('Ativa a extensão cURL no PHP para comunicar com o fornecedor certificado.');
    $account=fiscal_account_name((string)$credentials['account']);
    if(!preg_match('/^\/[a-zA-Z0-9_\/-]+\.json$/',$path))throw new RuntimeException('Endereço da API não autorizado.');
    $query['api_key']=(string)$credentials['api_key'];
    $url='https://'.$account.'.app.invoicexpress.com'.$path.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $ch=curl_init($url);
    if($ch===false)throw new RuntimeException('Não foi possível iniciar ligação HTTPS.');
    $options=[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_MAXREDIRS=>0,CURLOPT_USERAGENT=>'Gestao360-Fiscal-Connector/1.0',
    ];
    if($payload!==null)$options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    curl_setopt_array($ch,$options);
    $body=curl_exec($ch);
    $http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $curlError=curl_error($ch);
    curl_close($ch);
    if($body===false)throw new RuntimeException('Falha na ligação HTTPS ao InvoiceXpress: '.$curlError.'; verifica o estado no fornecedor antes de tentar novamente.');
    $data=json_decode($body,true);
    if(!in_array($http,$accepted,true)) {
        $safeRaw=(string)$body;
        $secret=(string)$credentials['api_key'];
        if($secret!=='')$safeRaw=str_replace($secret,'[oculto]',$safeRaw);
        $message=fiscal_provider_error($http,is_array($data)?$data:[],$safeRaw);
        throw new RuntimeException($message,$http);
    }
    // Alguns endpoints de transição de estado devolvem HTTP 200 sem corpo JSON.
    if($body==='' && $method==='PUT')return ['status'=>$http,'data'=>[]];
    if(!is_array($data))throw new RuntimeException('Resposta inesperada do InvoiceXpress: JSON em falta ou inválido.');
    return ['status'=>$http,'data'=>$data];
}
/** Unidades permitidas pelo InvoiceXpress: nunca enviar abreviaturas locais como «un». */
function fiscal_item_unit(string $preferred='product'):string {
    $allowed=['service','hour','day','month','tax','unit','article','product','other'];
    if(!in_array($preferred,$allowed,true))
        throw new InvalidArgumentException('Unidade fiscal inválida. Utiliza uma unidade aceite pelo InvoiceXpress.');
    return $preferred;
}

/** Validação local antes de reservar um identificador ou contactar o fornecedor. */
function fiscal_validate_invoice_payload(array $payload,string $documentType):void {
    if(!in_array($documentType,['invoices','invoice_receipts'],true))
        throw new InvalidArgumentException('Tipo de fatura não suportado.');
    $doc=$payload['invoice']??null;
    if(!is_array($doc)||empty($doc['items'])||!is_array($doc['items'])||count($doc['items'])>100)
        throw new InvalidArgumentException('A fatura deve conter entre 1 e 100 artigos.');
    foreach($doc['items'] as $position=>$line){
        if(!is_array($line))throw new InvalidArgumentException('Artigo fiscal inválido.');
        $name=trim((string)($line['name']??''));
        if($name===''||mb_strlen($name)>60)throw new InvalidArgumentException('Nome do artigo '.($position+1).' inválido. O limite é 60 caracteres.');
        fiscal_item_unit((string)($line['unit']??''));
        if(!is_numeric($line['quantity']??null)||(float)$line['quantity']<=0)
            throw new InvalidArgumentException('Quantidade inválida no artigo '.($position+1).'.');
        if(!is_numeric($line['unit_price']??null)||(float)$line['unit_price']<0)
            throw new InvalidArgumentException('Preço inválido no artigo '.($position+1).'.');
        if(trim((string)($line['tax']['name']??''))==='')
            throw new InvalidArgumentException('Seleciona uma taxa de IVA válida no artigo '.($position+1).'.');
    }
    if(strlen((string)($payload['proprietary_uid']??''))<16)
        throw new InvalidArgumentException('Identificador antidualidade da fatura em falta.');
}

/** Remove texto técnico e credenciais dos erros devolvidos ao utilizador. */
function fiscal_provider_error(int $http, array $data, string $raw=''):string {
    $messages=[];
    foreach(($data['errors']??[]) as $entry){
        if(is_string($entry))$messages[]=$entry;
        elseif(is_array($entry))$messages[]=(string)($entry['error']??$entry['message']??'');
    }
    if(!$messages&&isset($data['error']))$messages[]=(string)$data['error'];
    $message=implode('; ',array_filter($messages));
    if($message==='' && $raw!=='')$message=strip_tags($raw);
    $message=mb_substr(trim($message),0,360);
    if($http===422){
        if(preg_match('/\bunit\b.*(?:lista|accepted|aceites|accepted values)/iu',$message))
            return 'O InvoiceXpress recusou uma unidade de medida. Seleciona produto, artigo, unidade ou serviço e volta a rever o rascunho.';
        return 'O InvoiceXpress rejeitou os dados da fatura (422). '.($message?:'Revê os produtos, impostos e dados do cliente.');
    }
    if($http===409)return 'Já existe uma operação de criação com este identificador no InvoiceXpress. Verifica primeiro se o documento foi criado; não repitas a emissão.';
    if($http===429)return 'Limite de pedidos à API atingido. Aguarda antes de consultar novamente. Não repitas automaticamente a criação.';
    if($http===401||$http===403)return 'A chave API não tem acesso à conta InvoiceXpress. Confirma o nome da conta, a validade da chave e as permissões.';
    if($http===404)return 'A conta ou o recurso pedido não foi encontrado no InvoiceXpress. Confirma o subdomínio e o documento.';
    return 'InvoiceXpress devolveu HTTP '.$http.($message!==''?': '.$message:' — verifica o estado no fornecedor.');
}

function fiscal_taxes(array $credentials):array {
    $result=fiscal_request($credentials,'GET','/taxes.json');
    $taxes=$result['data']['taxes']??null;
    if(!is_array($taxes))throw new RuntimeException('O fornecedor não devolveu uma lista de taxas utilizável.');
    $out=[];
    foreach($taxes as $tax){
        if(!isset($tax['id'],$tax['name'],$tax['value'])||!is_numeric($tax['value']))continue;
        $region=(string)($tax['region']??'');
        if(!in_array($region,['PT','PT-MA','PT-AC'],true))continue;
        $out[(int)$tax['id']]=['id'=>(int)$tax['id'],'name'=>(string)$tax['name'],
           'rate'=>(float)$tax['value'],'region'=>$region];
    }
    if(!$out)throw new RuntimeException('Configura taxas portuguesas válidas na conta do fornecedor antes de faturar.');
    return $out;
}
function fiscal_paid_order(PDO $db,int $cid,int $orderId):array {
    $order=one($db,'SELECT o.*,c.name customer_name,c.nif customer_nif FROM orders o LEFT JOIN customers c ON c.id=o.customer_id AND c.company_id=o.company_id WHERE o.id=? AND o.company_id=?',[$orderId,$cid]);
    if(!$order||$order['status']!=='pago')throw new RuntimeException('Só podes preparar uma fatura de um pedido pago desta empresa.');
    $paid=scalar($db,'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND order_id=?',[$cid,$orderId]);
    if($paid!==(int)$order['total_cents']||$paid<1)throw new RuntimeException('Os pagamentos do pedido não coincidem com o respetivo total.');
    $items=all($db,'SELECT id,name,qty,price_cents FROM order_items WHERE order_id=? ORDER BY id',[$orderId]);
    if(!$items||count($items)>100)throw new RuntimeException('Pedido sem linhas ou com demasiadas linhas.');
    $total=0;
    foreach($items as $item){
        if((int)$item['qty']<1||(int)$item['price_cents']<0)throw new RuntimeException('Pedido com linhas inválidas.');
        $total+=(int)$item['qty']*(int)$item['price_cents'];
    }
    if($total!==(int)$order['total_cents'])throw new RuntimeException('As linhas do pedido não coincidem com o total pago. Revê o pedido.');
    $order['items']=$items;
    return $order;
}
/** Converte preço unitário COM IVA em base sem imposto. O fornecedor calcula o IVA definitivo. */
function fiscal_net_price(int $grossCents,float $rate):string {
    if($grossCents<0||$rate<0||$rate>100)throw new RuntimeException('Preço/taxa não permitido.');
    return number_format(($grossCents/100)/(1+$rate/100),6,'.','');
}
function fiscal_safe_link(string $url):string {
    if(!filter_var($url,FILTER_VALIDATE_URL))return '';
    $parts=parse_url($url);
    if(($parts['scheme']??'')!=='https'||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))return '';
    $host=strtolower((string)($parts['host']??''));
    if($host!=='invoicexpress.com'&&!str_ends_with($host,'.invoicexpress.com'))return '';
    return $url;
}
function fiscal_company_ready(PDO $db,int $cid):array {
    $company=one($db,'SELECT * FROM companies WHERE id=?',[$cid]);
    if(!$company||!valid_nif((string)$company['nif'])||$company['nif']==='')
        throw new RuntimeException('Indica um NIF português válido nas Definições da empresa antes de faturar.');
    return $company;
}
function fiscal_sync_document(PDO $db,array $credentials,array $record):array {
    if((int)$record['provider_id']<1)throw new RuntimeException('O documento ainda não tem referência no fornecedor. Verifica a conta externa.');
    $docType=(string)($record['document_type']??'invoice_receipts');
    if(!in_array($docType,['invoices','invoice_receipts'],true))throw new RuntimeException('Tipo fiscal inesperado.');
    $answer=fiscal_request($credentials,'GET','/'.$docType.'/'.(int)$record['provider_id'].'.json');
    $wrapper=$docType==='invoice_receipts'?'invoice_receipt':'invoice';
    $invoice=$answer['data'][$wrapper]??[];
    if(!is_array($invoice)||(int)($invoice['id']??0)!==(int)$record['provider_id'])throw new RuntimeException('O documento não pôde ser confirmado no fornecedor.');
    $status=(string)($invoice['status']??'');
    $total=(int)round((float)($invoice['total']??-1)*100);
    $state=match($status){'draft'=>'draft','final','settled'=>'final','canceled'=>'canceled',default=>'unknown'};
    $problem=$total!==(int)$record['internal_total_cents']?'TOTAL DIVERGENTE: confere impostos/preços no fornecedor antes de finalizar.':'';
    if($state==='draft'&&in_array($record['status'],['finalizing','finalization_uncertain'],true))$state='finalization_uncertain';
    elseif($state==='draft'&&$problem)$state='draft_mismatch';
    $url=fiscal_safe_link((string)($invoice['permalink']??''));
    $db->prepare('UPDATE fiscal_documents SET status=?,invoice_number=?,atcud=?,document_url=?,provider_total_cents=?,error_message=?,updated_at=? WHERE id=? AND company_id=?')
      ->execute([$state,(string)($invoice['sequence_number']??''),(string)($invoice['atcud']??''),$url,$total,$problem,now(),$record['id'],$record['company_id']]);
    return one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[$record['id'],$record['company_id']]);
}

/** Confere que o NIF da conta externa corresponde ao NIF da empresa no UniGest. */
/** Valida a autenticação. O NIF só é verificável automaticamente se existir ID real. */
function fiscal_verify_account(array $credentials,string $expectedNif):array {
    $id=(int)($credentials['account_id']??0);
    if($id>0){
        $response=fiscal_request($credentials,'GET','/api/accounts/'.$id.'/get.json');
        $account=$response['data']['account']??null;
        if(!is_array($account) || preg_replace('/\D/','',(string)($account['fiscal_id']??''))!==preg_replace('/\D/','',$expectedNif))
            throw new RuntimeException('O NIF da conta InvoiceXpress não coincide com o NIF desta empresa.');
        if(($account['state']??'')!=='active')throw new RuntimeException('A conta InvoiceXpress não está ativa.');
        return $account;
    }
    // /taxes.json não exige ID e autentica a chave. Não devolve NIF nem estado da comunicação AT.
    fiscal_taxes($credentials);
    return ['verified_api'=>true,'nif_verified'=>false,'at_configured'=>null];
}

/** Permite associar um documento encontrado no fornecedor a um pedido que ficou pendente. Não envia POST. */
function fiscal_attach_existing(PDO $db,array $credentials,array $record,int $providerId):array {
    if($providerId<1||$providerId>2147483647)throw new InvalidArgumentException('Identificador fiscal inválido.');
    if((int)$record['provider_id']>0)throw new RuntimeException('Este pedido já está associado a um documento externo.');
    if(!in_array($record['status'],['uncertain','validation_failed','creating'],true))
        throw new RuntimeException('O pedido não está pendente de reconciliação.');
    $type=(string)$record['document_type'];
    $wrapper=$type==='invoice_receipts'?'invoice_receipt':'invoice';
    $response=fiscal_request($credentials,'GET','/'.$type.'/'.$providerId.'.json');
    $external=$response['data'][$wrapper]??null;
    if(!is_array($external)||(int)($external['id']??0)!==$providerId)
        throw new RuntimeException('O documento externo não foi confirmado.');
    $reference='G360-P'.(int)$record['company_id'].'-'.(int)$record['order_id'];
    if(trim((string)($external['reference']??''))!==$reference)
        throw new RuntimeException('A referência do documento não corresponde ao pedido UniGest. Não é seguro associá-lo.');
    $total=(int)round((float)($external['total']??-1)*100);
    if($total!==(int)$record['internal_total_cents'])
        throw new RuntimeException('O total do documento externo não corresponde ao pedido. Confirma o documento antes de o associar.');
    $other=one($db,'SELECT id FROM fiscal_documents WHERE company_id=? AND provider_id=? AND id<>?',[$record['company_id'],$providerId,$record['id']]);
    if($other)throw new RuntimeException('Este documento fiscal já está associado a outro pedido.');
    $db->prepare('UPDATE fiscal_documents SET provider_id=?,updated_at=? WHERE id=? AND company_id=? AND provider_id IS NULL')
       ->execute([$providerId,now(),$record['id'],$record['company_id']]);
    return fiscal_sync_document($db,$credentials,one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[$record['id'],$record['company_id']]));
}
