<?php
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function aiReply(array $data, int $status=200):never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
try {
    must_company($db);
    require_permission($db,company_id(),'ai.use');
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Método não permitido.');
    check_token();
    $message=trim((string)($_POST['message']??''));
    if($message==='' || mb_strlen($message)>900) throw new RuntimeException('Mensagem inválida (máximo 900 caracteres).');
    $cid=company_id();require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'ai_enabled'))aiReply(['error'=>'A IA foi desativada nas opções desta empresa.'],403);
    $t=till($db,$cid); // Também necessário para comandos diretos sem Gemini.
    // Interpretação determinística de cópia de pedidos com referências explícitas.
    // Funciona mesmo quando a IA externa não reconhece a expressão.
    $directAction=null;
    if(preg_match('~\b(?:duplicar|duplica|copiar|copia)\b.*?\bpedido\s*(?:n[.ºo]*\s*|#\s*)?(\d+)\b.*?\b(?:da|de)\s+mesa\s*(?:n[.ºo]*\s*)?(\d+)\b.*?\bpara\s+(?:a\s+)?mesa\s*(?:n[.ºo]*\s*)?(\d+)\b~iu',$message,$matches)){
        $sourceOrderId=(int)$matches[1];
        $findNamedTable=static function(PDO $db,int $cid,int $number):int {
            $candidates=[];
            foreach(all($db,'SELECT id,name FROM dining_tables WHERE company_id=?',[$cid]) as $table){
                $label=trim((string)$table['name']);
                if(preg_match('~^(?:mesa\s*)?(\d+)\s*$~iu',$label,$found) && (int)$found[1]===$number)
                    $candidates[]=(int)$table['id'];
            }
            if(count($candidates)!==1) throw new RuntimeException('Não foi possível identificar a mesa '.str_pad((string)$number,2,'0',STR_PAD_LEFT).' sem ambiguidade. Verifica os nomes das mesas.');
            return $candidates[0];
        };
        $directAction=['kind'=>'duplicate_order','source_order_id'=>$sourceOrderId,
            'source_table_id'=>$findNamedTable($db,$cid,(int)$matches[2]),
            'destination_table_id'=>$findNamedTable($db,$cid,(int)$matches[3])];
    }
    if($directAction===null && preg_match('~\b(?:cancelar|cancela|anular|anula)\b.*?\breserva\s*(?:n[.ºo]*\s*|#\s*)?(\d+)\b~iu',$message,$cancelMatch)){
        $directAction=['kind'=>'cancel_reservation','reservation_id'=>(int)$cancelMatch[1]];
    }
    if($directAction===null && preg_match('~\b(?:quantas?|quais|mostra|ver|consulta|existem|há)\b.*?\b(?:mesas?|dias?)\b.*?\b(?:vagas?|livres?|dispon[ií]ve(?:is|l))\b|\b(?:quais|quantas?)\s+os?\s+dias?\s+(?:que\s+)?(?:t[eê]m|h[aá])\s+vagas?~iu',$message)){
        $date=date('Y-m-d');
        if(preg_match('/\b(\d{4}-\d{2}-\d{2})\b/',$message,$dm))$date=$dm[1];
        if(preg_match('/\bamanh[aã]\b/iu',$message))$date=date('Y-m-d',strtotime('+1 day'));
        $time='20:00';$timeSpecified=false;
        if(preg_match('/\b(?:[aà]s?\s*)?(\d{1,2})(?::(\d{2}))?\s*(?:h|horas)\b/iu',$message,$tm)){$time=sprintf('%02d:%02d',(int)$tm[1],(int)($tm[2]??0));$timeSpecified=true;}
        if(preg_match('/\b(\d{2}:\d{2})\b/',$message,$tm)){$time=$tm[1];$timeSpecified=true;}
        $words=['uma'=>1,'um'=>1,'duas'=>2,'dois'=>2,'três'=>3,'tres'=>3,'quatro'=>4,'cinco'=>5,'seis'=>6,'sete'=>7,'oito'=>8,'nove'=>9,'dez'=>10];
        $guests=1;if(preg_match('/\b(?:para|de)\s*(\d+)\s*pessoas?\b/iu',$message,$gm))$guests=(int)$gm[1];
        elseif(preg_match('/\b(?:para|de)\s*(uma|um|duas|dois|três|tres|quatro|cinco|seis|sete|oito|nove|dez)\s+pessoas?\b/iu',$message,$gm))$guests=$words[mb_strtolower($gm[1])]??1;
        $days=preg_match('/\bdias?\b/iu',$message) && preg_match('/\bquais|pr[oó]ximos|semana\b/iu',$message)?7:1;
        if($days>1 && preg_match('/\b(\d{1,2})\s+dias\b/iu',$message,$dm))$days=min(31,max(1,(int)$dm[1]));
        if(!$timeSpecified && $days===1 && $date===date('Y-m-d'))$time=date('H:i');
        $directAction=['kind'=>'report','topic'=>'availability','period'=>'today','date_from'=>$date,'date_to'=>date('Y-m-d',strtotime($date.' +'.($days-1).' days')),'time'=>$time,'guests'=>$guests];
    }
    if($directAction===null){
        require_once __DIR__.'/app/domain/ai_local.php';
        $directAction=g360_ai_local_report($message);
    }
    if($directAction===null){
    $local=is_file(__DIR__.'/config.local.php') ? require __DIR__.'/config.local.php' : [];
    // Preferir .env/variável de ambiente; config.local.php preservado como fallback de migração.
    $key=g360_env('GEMINI_API_KEY',(string)($local['gemini_api_key']??''));
    if($key==='COLOCA_A_TUA_CHAVE_AQUI') $key=(string)($local['gemini_api_key']??'');
    $model=g360_env('GEMINI_MODEL',(string)($local['gemini_model']??'gemini-2.5-flash-lite'));
    $provider=g360_env('AI_PROVIDER',(string)($local['ai_provider']??'gemini'));
    if($provider!=='gemini')throw new RuntimeException('Fornecedor IA não suportado nesta versão.');
    if(!preg_match('/^[a-zA-Z0-9._-]+$/', $model)) throw new RuntimeException('Modelo Gemini inválido.');
    if(!$key) aiReply(['reply'=>'A IA generativa ainda não tem uma chave Gemini configurada no servidor. Entretanto podes consultar vendas, pedidos, reservas, stock e relatórios com as sugestões do painel. Para ativar respostas livres, configura GEMINI_API_KEY no ficheiro .env privado e ativa cURL no PHP.', 'mode'=>'setup']);
    if(!extension_loaded('curl')) throw new RuntimeException('Ativa a extensão cURL no PHP.');

    $products=all($db,'SELECT id,name,sku,category,qty,price_cents,active FROM products WHERE company_id=? ORDER BY name LIMIT 100',[$cid]);
    $floor=one($db,'SELECT width,height FROM floor_settings WHERE company_id=?',[$cid])?:['width'=>1000,'height'=>1000];
    $tables=all($db,'SELECT id,name,seats,map_x,map_y,map_shape,map_width,map_height FROM dining_tables WHERE company_id=? ORDER BY name LIMIT 100',[$cid]);
    $orders=all($db,"SELECT id,origin,table_id,status,total_cents FROM orders WHERE company_id=? ORDER BY id DESC LIMIT 80",[$cid]);
    // O pedido mencionado pode ser antigo e não aparecer na lista recente.
    $referencedOrder=null;
    if(preg_match('/\bpedido\s*(?:n[.ºo]*\s*|#\s*)?(\d+)\b/iu',$message,$m)){
        $candidate=one($db,'SELECT id FROM orders WHERE company_id=? AND id=?',[$cid,(int)$m[1]]);
        if($candidate){
            $snap=order_copy_snapshot($db,$cid,(int)$candidate['id']);
            $referencedOrder=['id'=>$snap['id'],'table_id'=>$snap['table_id'],'status'=>$snap['status'],'items'=>$snap['items']];
        }
    }
    $t=till($db,$cid);
    $sales=scalar($db,'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND created_at>=?',[$cid,date('Y-m-d')]);
    $reservations=has_permission($db,$cid,'reservations.manage')?all($db,'SELECT id,table_id,customer,guests,start_at,status FROM reservations WHERE company_id=? AND start_at>=? ORDER BY start_at DESC LIMIT 200',[$cid,date('Y-m-d',strtotime('-365 days'))]):[];
    $tableHistory=(has_permission($db,$cid,'tables.view')&&has_permission($db,$cid,'reports.view'))?all($db,"SELECT t.id table_id,t.name,substr(o.created_at,1,10) day,COUNT(*) orders_count,SUM(o.total_cents) total_cents FROM orders o JOIN dining_tables t ON t.id=o.table_id AND t.company_id=o.company_id WHERE o.company_id=? AND o.created_at>=? GROUP BY t.id,substr(o.created_at,1,10) ORDER BY day DESC LIMIT 100",[$cid,date('Y-m-d',strtotime('-365 days'))]):[];
    $customers=all($db,'SELECT id,name FROM customers WHERE company_id=? ORDER BY id DESC LIMIT 60',[$cid]);
    $logActors=has_permission($db,$cid,'logs.view')?all($db,'SELECT DISTINCT u.id,u.name FROM audit_logs l JOIN users u ON u.id=l.user_id WHERE l.company_id=? LIMIT 50',[$cid]):[];
    $recentLogSummary=has_permission($db,$cid,'logs.view')?all($db,'SELECT entity,operation,created_at,details FROM audit_logs WHERE company_id=? ORDER BY id DESC LIMIT 12',[$cid]):[];
    $fiscalSummary=[];
    if(has_permission($db,$cid,'fiscal.use')){
       require_once __DIR__.'/fiscal_lib.php';fiscal_setup($db);
       $fiscalSummary=all($db,'SELECT order_id,status,invoice_number,created_at FROM fiscal_documents WHERE company_id=? ORDER BY id DESC LIMIT 25',[$cid]);
    }
    // Nunca enviar ao modelo dados de áreas que o colaborador não está autorizado a consultar.
    if(!has_permission($db,$cid,'products.view'))$products=[];
    if(!has_permission($db,$cid,'tables.view'))$tables=[];
    if(!has_permission($db,$cid,'orders.manage')){$orders=[];$referencedOrder=null;}
    if(!has_permission($db,$cid,'customers.manage'))$customers=[];
    if(!has_permission($db,$cid,'reports.view'))$sales=null;
    $context=json_encode(['today'=>date('Y-m-d H:i'),'sales_today_cents'=>$sales,'till_open'=>(bool)$t,'products'=>$products,'floor_dimensions'=>$floor,'tables'=>$tables,'table_activity_past_year'=>$tableHistory,'recent_orders'=>$orders,'referenced_order'=>$referencedOrder,'reservations_last_year'=>$reservations,'customers'=>$customers,'log_actors'=>$logActors,'last_logs'=>$recentLogSummary,'fiscal_documents'=>$fiscalSummary,'availability_rule'=>'Consulta por hora; por omissão 20:00, 7 dias para pergunta sobre dias livres; reservas bloqueiam um intervalo de aproximadamente 2 horas.'],JSON_UNESCAPED_UNICODE);
    $system=<<<'PROMPT'
És a IA de operações do UniGest V22 para uma única empresa, em português de Portugal.
A tua função é PREPARAR UMA OU MAIS OPERAÇÕES REAIS do sistema para cada pedido, NÃO apenas conversar.
Produz sempre JSON válido com {"reply":"descrição curta", "actions":[{...},{...}]}. O array actions pode conter 1 a 8 operações; cada uma usa os formatos abaixo. Se faltar informação, devolve actions:[] e faz uma pergunta em reply.
Para qualquer pedido claro, escolhe uma das ações. Mesmo para perguntas e análises, escolhe "report": a consulta SÓ é executada no PHP após confirmação explícita do utilizador.
Se o pedido for ambíguo ou impossível sem dados essenciais, usa {"reply":"pergunta de clarificação concreta", "actions":[]}; NUNCA inventes informações.
NÃO anuncies operações como feitas; és só o planeador de uma proposta. A confirmação e execução são feitas pelo PHP.
Ações permitidas:
- {"kind":"report","topic":"overview|sales|low_stock|open_orders|reservations|products|tables|customers|logs|fiscal|availability|profit","period":"today|week|month","date_from":"YYYY-MM-DD","date_to":"YYYY-MM-DD"} ,"time_from":"HH:MM","time_to":"HH:MM","entity":"products|stock_moves|orders|reservations|fiscal_documents|roles|companies","actor_id":N} ; time_from/time_to/entity/actor_id são opcionais, apenas para logs; datas opcionais para pesquisar qualquer dia ou intervalo histórico (máximo 366 dias). Para uma pergunta sobre um dia passado preenche date_from=date_to com a data pedida. topic=availability responde a QUANTAS MESAS ESTÃO DISPONÍVEIS e EM QUE DIAS HÁ MESAS DISPONÍVEIS. Inclui time no formato HH:MM e guests de 1 a 100. Na ausência de hora usa explicitamente 20:00, e para dias livres consulta no máximo os próximos 7 dias; nunca interpretes disponibilidade sem horário como garantia para o dia inteiro. topic=profit só se o utilizador tiver permissão profits.view; é margem bruta estimada e não lucro líquido. topic=tables deve contar pedidos e reservas por mesa no período. topic=reservations lista também reservas canceladas e concluídas nesse intervalo, com horas.
- {"kind":"report","topic":"availability","date_from":"YYYY-MM-DD","date_to":"YYYY-MM-DD","time":"20:00","guests":2} consulta mesas livres à hora escolhida; se pedir dias livres, consulta os próximos sete dias.
- {"kind":"report","topic":"profit","period":"today|week|month"} consulta margens por período, sempre mediante autorização.
- {"kind":"stock","product_id":N,"delta":N} para adicionar (+) ou retirar (-).
- {"kind":"set_stock","product_id":N,"qty":N} para repor a quantidade total.
- {"kind":"duplicate_product","product_id":N} copiar/colar produto cria registo duplicado com stock zero.
- {"kind":"delete_product","product_id":N} arquivar, não destruir dados.
- {"kind":"restore_product","product_id":N} reativar produto.
- {"kind":"update_product","product_id":N,"field":"name|category|price|min_qty","value":"valor"}.
- {"kind":"undo_product"} desfazer uma alteração de produto da IA, se possível.
- {"kind":"create_product","name":"Nome","category":"Categoria","price":"1.50","qty":N}.
- {"kind":"create_table","name":"Mesa 1","seats":4}.
- {"kind":"duplicate_table","table_id":N}.
- {"kind":"update_table","table_id":N,"field":"name|seats|shape","value":"valor"}.
- {"kind":"move_table","table_id":N,"x":N,"y":N} coordenadas dentro de floor_dimensions menos a largura/altura atual da mesa; se não há posição clara, pede esclarecimento.
- {"kind":"duplicate_order","source_order_id":N,"destination_table_id":N,"source_table_id":N} copia TODOS os artigos e quantidades do pedido original para um NOVO pedido na mesa de destino. Usa o ID do pedido (ex.: "pedido 1" = source_order_id 1), NÃO o número de mesa. Se o utilizador mencionar a mesa de origem ("mesa 02"), preenche source_table_id com o ID real dessa mesa para validação; nunca inventes. O pedido original NÃO é alterado. Não copia pagamentos. Requer confirmação e desconta stock dos artigos copiados. Não é transferência do pedido.
- {"kind":"new_order","origin":"balcao"} ou {"kind":"new_order","origin":"mesa","table_id":N}.
- {"kind":"open_till","opening":"0.00"}.
- {"kind":"reservation","customer":"Nome","guests":N,"start_at":"YYYY-MM-DD HH:MM","table_id":N} ; table_id é OPCIONAL, só especifica se o utilizador escolher uma mesa concreta. Sem mesa escolhida, o servidor escolhe AUTOMATICAMENTE a menor mesa com capacidade e disponível no horário, evitando choques de 2 horas. A data e hora devem ser explícitas.
- {"kind":"reservation_edit","reservation_id":N,"customer":"Nome","guests":N,"start_at":"YYYY-MM-DD HH:MM","table_id":N} para alterar uma reserva confirmada sem a cancelar. Os campos customer, guests e start_at são opcionais e mantêm os valores existentes quando omitidos. table_id opcional: por omissão escolhe automaticamente a mesa mais adequada, sem contar a própria reserva como conflito. Nunca inventes o ID da reserva; se houver nomes repetidos, pede esclarecimento.
- {"kind":"cancel_reservation","reservation_id":N} para CANCELAR uma reserva confirmada. Pesquisa pela identificação da reserva, cliente, data e hora disponíveis no contexto; se houver várias possíveis pergunta qual é, nunca adivinhes.
- {"kind":"cash_move","type":"entrada|saida","amount":"5.00","reason":"motivo"}.
Relaciona referências como "Mesa 02" pelos NOMES das mesas (não confundas o número escrito no nome com o ID da base de dados). Usa apenas IDs presentes no contexto desta empresa; o pedido referenciado pode constar de referenced_order mesmo que seja antigo. Sem inventar. Se o produto/mesa não existir, pergunta qual é o correto.
Se a mesa destino tiver um pedido aberto, informa o utilizador e não planeies a cópia. NUNCA fazer pagamentos, eliminar dados definitivamente, usar ferramentas externas, nem emitir faturação fiscal. Para consultas fiscais apenas lista os estados de documentos no fornecedor, nunca afirmes que foram comunicados à AT sem comprovativo do fornecedor. Para consultar quem fez operações e horas usa report topic=logs, com filtros de data/hora e actor_id do contexto. Para pagamentos e faturas usa report topic=sales ou fiscal. Não envies dados de áreas sem permissão.
Para qualquer ação de escrita, descreve brevemente o que será proposto e não digas "feito" antes de confirmar.
Se forem pedidas várias ações, propõe TODAS numa lista actions, na ordem solicitada (máximo 8). Cada operação deve ser completa, com IDs válidos. Nunca assumes que uma operação anterior criou um ID que ainda não existe. O utilizador confirma o lote completo uma única vez; se qualquer passo falhar, o servidor reverte o lote todo. Não inventes operações. Responde com lista vazia quando faltam dados.
PROMPT;

    // Contexto curto de diálogo por empresa; ajuda a resolver perguntas de seguimento.
    $thread=$_SESSION['ai_chat'][$cid]??[];
    $contents=[];
    foreach(array_slice($thread,-4) as $turn){
        $contents[]=['role'=>'user','parts'=>[['text'=>$turn['user']]]];
        $contents[]=['role'=>'model','parts'=>[['text'=>json_encode(['reply'=>$turn['reply']],JSON_UNESCAPED_UNICODE)]]];
    }
    $contents[]=['role'=>'user','parts'=>[['text'=>"Contexto atual da empresa (usa APENAS IDs atuais):\n".$context."\n\nPedido do utilizador:\n".$message]]];
    // Gemini REST API: a chave permanece exclusivamente no servidor PHP.
    $payload=[
        'systemInstruction'=>['parts'=>[['text'=>$system]]],
        'contents'=>$contents,
        'generationConfig'=>['temperature'=>0.2,'responseMimeType'=>'application/json']
    ];
    $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_TIMEOUT=>40, CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)
    ]);
    $raw=curl_exec($ch);
    $curlError=$raw===false ? curl_error($ch) : '';
    $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($raw===false) throw new RuntimeException('Falha de rede/SSL ao contactar Gemini: '.$curlError);
    $api=json_decode($raw,true);
    if($http<200 || $http>=300){
        $reason=(string)($api['error']['message']??'Erro desconhecido da API.');
        // Não expor informação arbitrária da API ou a chave ao utilizador.
        if($http===400) $detail='Pedido ou modelo inválido. Confirma o nome do modelo.';
        elseif($http===401 || $http===403) $detail='Chave Gemini inválida, revogada ou sem permissões.';
        elseif($http===404) $detail='Modelo Gemini indisponível. Confirma o nome do modelo.';
        elseif($http===429) $detail='Limite gratuito ou quota excedida. Tenta novamente mais tarde.';
        elseif($http>=500) $detail='Serviço Gemini temporariamente indisponível.';
        else $detail='Falha ao contactar o Gemini.';
        error_log('Gestao360 Gemini HTTP '.$http.': '.substr($reason,0,300));
        throw new RuntimeException($detail.' (HTTP '.$http.')');
    }
    $content='';
    foreach(($api['candidates'][0]['content']['parts']??[]) as $part){
        $content.=(string)($part['text']??'');
    }
    $result=json_decode($content,true);
    if(!is_array($result)) throw new RuntimeException('Resposta da IA inválida.');
    }else{
        $result=['reply'=>$directAction['kind']==='cancel_reservation'?'Preparei o cancelamento da reserva. Confirma antes de aplicar.':($directAction['kind']==='report'?'Preparei uma consulta aos dados atuais da empresa. Confirma para veres os resultados com os filtros de acesso aplicados.':'Preparei uma cópia do pedido para outra mesa. Confirma os artigos, os preços e o stock antes de executar.'),
                 'actions'=>[$directAction]];
    }
    $reply=mb_substr((string)($result['reply']??'Operação preparada.'),0,900);
    $requested=$result['actions']??(isset($result['action'])?[$result['action']]:[]);
    $_SESSION['ai_chat'][$cid]??=[];
    $_SESSION['ai_chat'][$cid][]= ['user'=>$message,'reply'=>$reply];
    $_SESSION['ai_chat'][$cid]=array_slice($_SESSION['ai_chat'][$cid],-4);
    if(!is_array($requested)||!array_is_list($requested)||count($requested)>8) throw new RuntimeException('A IA devolveu um lote inválido (máximo 8 ações).');
    if(!$requested) aiReply(['reply'=>$reply?:'Preciso de mais informações para preparar a operação.','mode'=>'clarification']);
    $batch=[];$labels=[];
    foreach($requested as $action){
    $rawAction=$action; // preserva campos originais antes da normalização para os lotes multi-ação
    if(!is_array($action)) throw new RuntimeException('Uma operação do lote é inválida.');
    $kind=(string)($action['kind']??'');
    $requiredModule=g360_ai_module((string)$kind,(string)($action['topic']??''));
    if($requiredModule && !g360_feature($db,$cid,$requiredModule))throw new RuntimeException('A operação pertence a um módulo desativado nesta empresa.');
    require_permission($db,$cid,ai_action_permission($kind,$action));
    if($kind==='report' && ($action['topic']??'')==='tables')require_permission($db,$cid,'reports.view');
    if(in_array($kind,['new_order','duplicate_order'],true))require_permission($db,$cid,'pos.use');
    if(in_array($kind,['new_order','duplicate_order'],true) && !$t)
        throw new RuntimeException('Abre primeiro a caixa em Movimentos de caixa antes de criar pedidos.');
    $allowed=['stock','new_order','open_till','reservation','cancel_reservation','reservation_edit','create_product','cash_move','duplicate_product','delete_product','restore_product','set_stock','update_product','undo_product','report','create_table','duplicate_table','update_table','move_table','duplicate_order'];
    if(!in_array($kind,$allowed,true)) throw new RuntimeException('Uma das operações não é suportada.');
    // O servidor não confia nos IDs nem nas quantidades sugeridas pelo modelo.
    if($kind==='report'){
        $topic=(string)($action['topic']??'');
        $period=(string)($action['period']??'today');
        $topics=['overview'=>'Resumo da empresa','sales'=>'Vendas','low_stock'=>'Produtos a repor','open_orders'=>'Pedidos em aberto','reservations'=>'Reservas','products'=>'Catálogo','tables'=>'Movimento de mesas','customers'=>'Clientes','logs'=>'Histórico de ações com horas','fiscal'=>'Estados de documentos fiscais','availability'=>'Disponibilidade de mesas por data e hora','profit'=>'Margem bruta de vendas'];
        if(!isset($topics[$topic])||!in_array($period,['today','week','month'],true)) throw new RuntimeException('Consulta inválida.');
        $action=['kind'=>'report','topic'=>$topic,'period'=>$period];
        if(isset($rawAction['date_from']) || isset($rawAction['date_to'])){
            [$from,$to]=valid_date_range((string)($rawAction['date_from']??''),(string)($rawAction['date_to']??''));
            $action['date_from']=$from;$action['date_to']=$to;
        }
        if($topic==='availability'){
            $clock=(string)($rawAction['time']??'20:00');
            $guests=filter_var($rawAction['guests']??1,FILTER_VALIDATE_INT);
            if(!preg_match('/^\d{2}:\d{2}$/',$clock)||$guests===false||$guests<1||$guests>100)throw new RuntimeException('Hora ou número de pessoas inválidos.');
            $testDate=$action['date_from']??date('Y-m-d');
            g360_availability($db,$cid,$testDate,$clock,$guests);
            $action['time']=$clock;$action['guests']=$guests;
            if(!isset($action['date_from']))$action['date_from']=$action['date_to']=date('Y-m-d');
            if((new DateTimeImmutable($action['date_from']))->diff(new DateTimeImmutable($action['date_to']))->days>30)throw new RuntimeException('Consulta de disponibilidade limitada a 31 dias.');
        }
        if($topic==='logs'){
            foreach(['time_from','time_to'] as $f){
                $value=(string)($rawAction[$f]??'');
                if($value!=='' && (!preg_match('/^\d{2}:\d{2}$/',$value)|| !($dt=DateTimeImmutable::createFromFormat('!H:i',$value)) || $dt->format('H:i')!==$value)) throw new RuntimeException('Hora de consulta inválida.');
                if($value!=='')$action[$f]=$value;
            }
            $area=(string)($rawAction['entity']??'');
            if($area!==''){
                if(!in_array($area,['products','stock_moves','orders','reservations','fiscal_documents','roles','companies','payments','dining_tables','tills','cash_movements'],true))throw new RuntimeException('Área de logs inválida.');
                $action['entity']=$area;
            }
            if(isset($rawAction['actor_id'])){
                $actorId=filter_var($rawAction['actor_id'],FILTER_VALIDATE_INT);
                if(!$actorId || !one($db,'SELECT 1 FROM audit_logs WHERE company_id=? AND user_id=? LIMIT 1',[$cid,$actorId]))throw new RuntimeException('Utilizador não encontrado nos registos da empresa.');
                $action['actor_id']=$actorId;
            }
            if(!isset($action['date_from']) && (isset($action['time_from'])||isset($action['time_to'])))$action['date_from']=$action['date_to']=date('Y-m-d');
        }
        $label='Consulta de '.$topics[$topic].(isset($action['date_from'])?' entre '.$action['date_from'].' e '.$action['date_to']:' para o período selecionado').(isset($action['time'])?' às '.$action['time']:'').(isset($action['time_from'])?' após as '.$action['time_from']:'').(isset($action['time_to'])?' até às '.$action['time_to']:'').'.';
        $reply='A consulta está preparada. Depois da tua confirmação, apresentarei as informações atualizadas com datas e horários completos.';
    }elseif($kind==='duplicate_order'){
        $sourceId=filter_var($action['source_order_id']??null,FILTER_VALIDATE_INT);
        $destinationId=filter_var($action['destination_table_id']??null,FILTER_VALIDATE_INT);
        if(!$sourceId || !$destinationId) throw new RuntimeException('Indica o número do pedido original e a mesa de destino.');
        $snap=order_copy_snapshot($db,$cid,$sourceId);
        $destination=scoped($db,'dining_tables',$destinationId,$cid);
        if($snap['table_id']===null) throw new RuntimeException('O pedido original não pertence a uma mesa.');
        $sourceTable=scoped($db,'dining_tables',$snap['table_id'],$cid);
        if(isset($rawAction['source_table_id']) && (int)$rawAction['source_table_id']!==$snap['table_id'])
            throw new RuntimeException('O pedido #'.$sourceId.' pertence a '.$sourceTable['name'].', não à mesa de origem indicada.');
        if($destinationId===$snap['table_id']) throw new RuntimeException('A mesa de destino não pode ser a mesma da origem.');
        if(one($db,"SELECT id FROM orders WHERE company_id=? AND table_id=? AND status='aberto' LIMIT 1",[$cid,$destinationId]))
            throw new RuntimeException('A '.$destination['name'].' já tem um pedido aberto. Fecha-o antes de criar uma cópia.');
        $stockTotals=[];
        foreach($snap['items'] as $item) $stockTotals[$item['product_id']]=($stockTotals[$item['product_id']]??0)+$item['qty'];
        foreach($stockTotals as $pid=>$qty){
            $product=scoped($db,'products',(int)$pid,$cid);
            if(!(int)$product['active'] || (int)$product['qty']<$qty)
                throw new RuntimeException('Stock insuficiente ou produto inativo: '.$product['name'].' (necessárias '.$qty.' unidades).');
        }
        $action=['kind'=>'duplicate_order','source_order_id'=>$sourceId,'destination_table_id'=>$destinationId,
            'source_table_id'=>$snap['table_id'],'fingerprint'=>$snap['fingerprint']];
        $lineDescriptions=array_map(static fn($i)=>$i['qty'].' × '.$i['name'].' ('.money($i['price_cents']).'/un.)',$snap['items']);
        $label='Copiar pedido #'.$sourceId.' de '.$sourceTable['name'].' para '.$destination['name'].' · '.
               implode('; ',$lineDescriptions).' · total '.money($snap['total_cents']).
               ' · desconta stock; novo pedido em aberto, SEM cobrar e SEM alterar o original';
    }elseif($kind==='create_table'){
        $name=trim((string)($action['name']??''));
        $seats=filter_var($action['seats']??null,FILTER_VALIDATE_INT);
        if($name===''||mb_strlen($name)>80||$seats===false||$seats<1||$seats>100) throw new RuntimeException('Mesa inválida.');
        $action=['kind'=>$kind,'name'=>$name,'seats'=>$seats];
        $label='Criar mesa '.$name.' com '.$seats.' lugares';
    }elseif(in_array($kind,['duplicate_table','update_table','move_table'],true)){
        $table=scoped($db,'dining_tables',(int)($action['table_id']??0),$cid);
        $action=['kind'=>$kind,'table_id'=>(int)$table['id']];
        if($kind==='duplicate_table'){
            $label='Duplicar '.$table['name'].' (nova mesa vazia)';
        }elseif($kind==='move_table'){
            $x=filter_var($rawAction['x']??null,FILTER_VALIDATE_INT);
            $y=filter_var($rawAction['y']??null,FILTER_VALIDATE_INT);
            if($x===false||$y===false||$x<0||$y<0||$x+(int)$table['map_width']>(int)$floor['width']||$y+(int)$table['map_height']>(int)$floor['height']) throw new RuntimeException('Posição fora dos limites da planta.');
            $action['x']=$x;$action['y']=$y;
            $label='Mover '.$table['name'].' no mapa para ('.$x.', '.$y.')';
        }else{
            $field=(string)($rawAction['field']??'');
            $value=trim((string)($rawAction['value']??''));
            if(!in_array($field,['name','seats','shape'],true)) throw new RuntimeException('Campo inválido.');
            if($field==='name' && ($value===''||mb_strlen($value)>80)) throw new RuntimeException('Nome inválido.');
            if($field==='seats' && (!ctype_digit($value)||(int)$value<1||(int)$value>100)) throw new RuntimeException('Lugares inválidos.');
            if($field==='shape' && !in_array($value,['round','square','rect'],true)) throw new RuntimeException('Formato inválido.');
            $action['field']=$field; $action['value']=$value;
            $label='Alterar '. $table['name'].' · '.$field.' = '.$value;
        }
    }elseif($kind==='stock'){
        $p=scoped($db,'products',(int)($action['product_id']??0),$cid);
        $delta=(int)($action['delta']??0);
        if(!$delta || abs($delta)>10000 || $p['qty']+$delta<0) throw new RuntimeException('Alteração de stock inválida.');
        $label=($delta>0?'Adicionar ':'Retirar ').abs($delta).' unidade(s) '.($delta>0?'ao':'do').' stock de '.$p['name'];
        $action=['kind'=>$kind,'product_id'=>(int)$p['id'],'delta'=>$delta];
    }elseif($kind==='new_order'){
        $origin=(string)($action['origin']??'');
        if(!in_array($origin,['mesa','balcao'],true)) throw new RuntimeException('Origem inválida.');
        $action=['kind'=>$kind,'origin'=>$origin];
        $label='Criar pedido de balcão';
        if($origin==='mesa'){
            $table=scoped($db,'dining_tables',(int)($rawAction['table_id']??0),$cid);
            $action['table_id']=(int)$table['id'];
            $label='Criar pedido para '.$table['name'];
        }
    }elseif($kind==='open_till'){
        if($t) throw new RuntimeException('A caixa já está aberta.');
        $opening=cents((string)($action['opening']??''));
        $action=['kind'=>$kind,'opening'=>number_format($opening/100,2,'.','')];
        $label='Abrir caixa com '.money($opening);
    }elseif($kind==='reservation'){
        $customer=trim((string)($action['customer']??''));
        $guests=filter_var($action['guests']??null,FILTER_VALIDATE_INT);
        $date=(string)($action['start_at']??'');
        if($customer===''||mb_strlen($customer)>100||$guests===false) throw new RuntimeException('Reserva incompleta.');
        $preferred=isset($action['table_id'])?(int)$action['table_id']:null;
        $table=available_table($db,$cid,$date,$guests,$preferred?:null);
        $action=['kind'=>$kind,'table_id'=>(int)$table['id'],'customer'=>$customer,'guests'=>$guests,'start_at'=>$date];
        $label='Reservar automaticamente '.$table['name'].' para '.$customer.' em '.$date.' ('.$guests.' pessoas)';
    }elseif($kind==='reservation_edit'){
        $r=scoped($db,'reservations',(int)($rawAction['reservation_id']??0),$cid);
        if($r['status']!=='confirmada')throw new RuntimeException('A reserva não está confirmada.');
        $name=trim((string)($rawAction['customer']??$r['customer']));
        $guests=(int)($rawAction['guests']??$r['guests']);
        $when=(string)($rawAction['start_at']??$r['start_at']);
        if($name===''||mb_strlen($name)>120)throw new RuntimeException('Nome inválido.');
        $preferred=(int)($rawAction['table_id']??0);
        $table=available_table($db,$cid,$when,$guests,$preferred?:null,(int)$r['id']);
        $action=['kind'=>'reservation_edit','reservation_id'=>(int)$r['id'],'customer'=>$name,'guests'=>$guests,'start_at'=>$when,'table_id'=>(int)$table['id']];
        $label='Alterar a reserva de '.$r['customer'].' para '.$name.', '.$when.', '.$guests.' pessoas, na '.$table['name'];
    }elseif($kind==='cancel_reservation'){
        $reservation=scoped($db,'reservations',(int)($action['reservation_id']??0),$cid);
        if($reservation['status']!=='confirmada')throw new RuntimeException('Esta reserva já não está confirmada.');
        $action=['kind'=>$kind,'reservation_id'=>(int)$reservation['id']];
        $table=$reservation['table_id']?scoped($db,'dining_tables',(int)$reservation['table_id'],$cid):null;
        $label='Cancelar reserva #'.$reservation['id'].' de '.$reservation['customer'].' em '.$reservation['start_at'].' · '.($table['name']??'sem mesa');
    }elseif($kind==='create_product'){
        $name=trim((string)($action['name']??''));
        $category=trim((string)($action['category']??'Geral'));
        $qty=(int)($action['qty']??0);
        $price=cents((string)($action['price']??''));
        if($name===''||mb_strlen($name)>120||mb_strlen($category)>80||$qty<0||$qty>100000) throw new RuntimeException('Produto inválido.');
        $action=['kind'=>$kind,'name'=>$name,'category'=>$category?:'Geral','qty'=>$qty,'price'=>number_format($price/100,2,'.','')];
        $label='Criar '.$name.' · '.money($price).' · '.$qty.' unidades';
    }elseif(in_array($kind,['duplicate_product','delete_product','restore_product','set_stock','update_product'],true)){
        $p=scoped($db,'products',(int)($action['product_id']??0),$cid);
        $action=['kind'=>$kind,'product_id'=>(int)$p['id']];
        if($kind==='duplicate_product'){
            if(!(int)$p['active']) throw new RuntimeException('Produto arquivado.');
            $label='Duplicar produto: '.$p['name'];
        }elseif($kind==='delete_product'){
            if(!(int)$p['active']) throw new RuntimeException('Produto já arquivado.');
            $label='Arquivar produto: '.$p['name'].' (sem apagar vendas)';
        }elseif($kind==='restore_product'){
            if((int)$p['active']) throw new RuntimeException('Produto já ativo.');
            $label='Restaurar produto: '.$p['name'];
        }elseif($kind==='set_stock'){
            $qty=filter_var($rawAction['qty']??null,FILTER_VALIDATE_INT);
            if($qty===false || $qty<0 || $qty>1000000) throw new RuntimeException('Quantidade inválida.');
            $action['qty']=$qty;
            $label='Definir stock de '.$p['name'].' para '.$qty.' unidades';
        }else{
            $field=(string)($rawAction['field']??'');
            $value=trim((string)($rawAction['value']??''));
            if(!in_array($field,['name','category','price','min_qty'],true)) throw new RuntimeException('Campo não permitido.');
            if(in_array($field,['name','category'],true)){
                if($value==='' || mb_strlen($value)>($field==='name'?120:80)) throw new RuntimeException('Texto inválido.');
            }elseif($field==='min_qty'){
                if(!ctype_digit($value) || (int)$value>1000000) throw new RuntimeException('Stock mínimo inválido.');
            }else{
                $value=number_format(cents($value)/100,2,'.','');
            }
            $action['field']=$field;$action['value']=$value;
            $label='Atualizar '.(['name'=>'nome','category'=>'categoria','price'=>'preço','min_qty'=>'stock mínimo'][$field]).' de '.$p['name'].' para '.$value;
        }
    }elseif($kind==='undo_product'){
        $last=one($db,'SELECT id FROM ai_product_history WHERE company_id=? AND user_id=? AND undone_at IS NULL ORDER BY id DESC LIMIT 1',[$cid,user_id()]);
        if(!$last) throw new RuntimeException('Não existem alterações da IA para desfazer.');
        $action=['kind'=>$kind,'history_id'=>(int)$last['id']];
        $label='Desfazer última alteração de produto efetuada pela IA';
    }else{
        $type=(string)($action['type']??'');
        $reason=trim((string)($action['reason']??''));
        $amount=cents((string)($action['amount']??''));
        if(!$t||!in_array($type,['entrada','saida'],true)||$amount<1||$reason===''||mb_strlen($reason)>200) throw new RuntimeException('Movimento inválido.');
        $action=['kind'=>$kind,'type'=>$type,'amount'=>number_format($amount/100,2,'.',''),'reason'=>$reason];
        $label=ucfirst($type).' de '.money($amount).' · '.$reason;
    }
    $batch[]=$action;
    $labels[]=$label;
    }
    // Propostas guardadas exclusivamente na sessão, sem aceitar comandos do navegador.
    $nonce=bin2hex(random_bytes(16));
    $_SESSION['ai_proposals']??=[];
    $_SESSION['ai_proposals']=array_slice($_SESSION['ai_proposals'],-8,null,true);
    $_SESSION['ai_proposals'][$nonce]=['company_id'=>$cid,'expires'=>time()+300,'actions'=>$batch];
    aiReply(['reply'=>$reply,'mode'=>$directAction!==null?'local':'ai','pending'=>['nonce'=>$nonce,'label'=>count($batch)===1?'Uma operação preparada para confirmação.':count($batch).' operações preparadas para confirmação.','steps'=>$labels]]);

}catch(Throwable $e){
    aiReply(['error'=>$e->getMessage()],400);
}
