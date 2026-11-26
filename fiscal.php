<?php
/** Ações fiscais — só executa após pedido POST do utilizador autorizado. */
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/fiscal_lib.php';
try {
    must_company($db);
    $cid=company_id();
    require_once __DIR__.'/app/domain/company_features.php';
    if(!g360_feature($db,$cid,'fiscal_enabled'))throw new RuntimeException('Faturação desativada nesta empresa.');
    require_permission($db,$cid,'fiscal.use');
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Método não permitido.');
    check_token();
    fiscal_setup($db);
    $task=req('fiscal_action');
    if($task==='connect'){
        require_permission($db,$cid,'fiscal.configure');
        $company=fiscal_company_ready($db,$cid);
        $account=fiscal_account_name(req('account'));
        $accountId=filter_var($_POST['account_id']??'0', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>999999999]]);
        if($accountId===false)$accountId=0;
        $apiKey=req('api_key');
        if(strlen($apiKey)<10||strlen($apiKey)>250||preg_match('/\s/',$apiKey))throw new RuntimeException('Chave API inválida.');
        // A chave pode ser renovada na mesma conta; a conta não pode ser trocada
        // enquanto existirem documentos ligados, para impedir faturas em contas diferentes.
        $oldConnection=fiscal_connection($db,$cid);
        if($oldConnection && (string)$oldConnection['account']!==$account
           && (int)scalar($db,'SELECT COUNT(*) FROM fiscal_documents WHERE company_id=?',[$cid])>0)
            throw new RuntimeException('Esta empresa já possui processos fiscais ligados. Não mudes para outra conta InvoiceXpress; renova a chave na mesma conta ou pede suporte para uma migração auditada.');
        $creds=['account'=>$account,'account_id'=>$accountId,'api_key'=>$apiKey];
        if($accountId===0 && req('confirm_nif')!=='1')throw new RuntimeException('Confirma que verificaste o NIF da conta no InvoiceXpress.');
        fiscal_verify_account($creds,(string)$company['nif']);
        fiscal_taxes($creds); // Confirma autenticação e acesso às taxas.
        $encrypted=fiscal_encrypt($apiKey);
        $db->prepare('INSERT INTO fiscal_connections(company_id,provider,account,account_id,secret_encrypted,configured_at) VALUES(?,?,?,?,?,?) ON CONFLICT(company_id) DO UPDATE SET account=excluded.account,account_id=excluded.account_id,secret_encrypted=excluded.secret_encrypted,configured_at=excluded.configured_at')
           ->execute([$cid,'invoicexpress',$account,$accountId,$encrypted,now()]);
        flash('Chave API e acesso às taxas validados. Credenciais guardadas cifradas. Sem ID numérico, o NIF e a comunicação à AT devem ser confirmados manualmente no InvoiceXpress.');
    }elseif($task==='disconnect'){
        require_permission($db,$cid,'fiscal.configure');
        if(req('confirm_disconnect')!=='DESLIGAR')throw new RuntimeException('Escreve DESLIGAR para confirmar.');
        $open=(int)scalar($db,"SELECT COUNT(*) FROM fiscal_documents WHERE company_id=? AND status IN ('creating','uncertain','validation_failed','finalizing','finalization_uncertain')",[$cid]);
        if($open>0)throw new RuntimeException('Resolve primeiro os processos fiscais pendentes antes de desligar a conta, para não perderes a possibilidade de os confirmar.');
        $db->prepare('DELETE FROM fiscal_connections WHERE company_id=?')->execute([$cid]);
        flash('Conta externa desligada. O histórico das faturas permanece guardado.');
    }elseif($task==='draft'){
        $company=fiscal_company_ready($db,$cid);
        $credentials=fiscal_credentials($db,$cid);
        fiscal_verify_account($credentials,(string)$company['nif']);
        $orderId=n('order_id',1);
        $order=fiscal_paid_order($db,$cid,$orderId);
        $docType=req('document_type');
        if(!in_array($docType,['invoices','invoice_receipts'],true))throw new RuntimeException('Tipo de documento fiscal inválido.');
        $previous=one($db,'SELECT * FROM fiscal_documents WHERE company_id=? AND order_id=?',[$cid,$orderId]);
        if($previous){
            if($previous['status']!=='validation_failed')
                throw new RuntimeException('Já existe um processo fiscal associado a este pedido. Revê o histórico antes de continuar; não será criada outra fatura.');
            if($previous['document_type']!==$docType)
                throw new RuntimeException('Na correção deste rascunho mantém o tipo de documento original.');
            if((int)$previous['provider_id']>0)throw new RuntimeException('Este pedido já tem um documento no fornecedor.');
        }
        $taxes=fiscal_taxes($credentials);
        $selected=$_POST['tax']??[];
        if(!is_array($selected))throw new RuntimeException('Seleciona o IVA para cada artigo.');
        $invoiceItems=[];$anyExempt=false;
        foreach($order['items'] as $line){
            $itemId=(int)$line['id'];
            $taxId=filter_var($selected[$itemId]??null,FILTER_VALIDATE_INT);
            if($taxId===false || !isset($taxes[$taxId]))throw new RuntimeException('Seleciona um imposto válido para cada produto.');
            $tax=$taxes[$taxId];
            if($tax['rate']===0.0)$anyExempt=true;
            $productName=trim((string)$line['name']);
            if($productName==='')throw new RuntimeException('Um dos produtos não possui nome.');
            $invoiceItems[]=[
                'name'=>mb_substr($productName,0,60),
                'description'=>mb_substr($productName,0,200),
                'unit'=>fiscal_item_unit('product'),
                'quantity'=>(string)$line['qty'],
                'unit_price'=>fiscal_net_price((int)$line['price_cents'],$tax['rate']),
                'tax'=>['name'=>$tax['name']],
            ];
        }
        $exemption=strtoupper(req('exemption'));
        $validExemptions=['M01','M02','M04','M05','M06','M07','M09','M10','M11','M12','M13','M14','M15','M16','M19','M20','M21','M25','M30','M31','M32','M33','M35','M40','M41','M42','M43','M44','M45','M46','M99'];
        if($anyExempt&&!in_array($exemption,$validExemptions,true))
            throw new RuntimeException('Seleciona um motivo de isenção válido, confirmado pelo contabilista.');
        $clientName=trim(req('client_name'));
        if($clientName==='')$clientName='Consumidor final';
        if(mb_strlen($clientName)>120)throw new RuntimeException('Nome do cliente demasiado extenso.');
        $clientNif=req('client_nif');
        if($clientNif!==''&&!valid_nif($clientNif))throw new RuntimeException('NIF do cliente inválido.');
        $date=date('d/m/Y');
        $reference='G360-P'.$cid.'-'.$orderId;
        $payload=['invoice'=>[
            'date'=>$date,'due_date'=>$date,
            'reference'=>$reference,
            'observations'=>'Pedido UniGest #'.$orderId.'; pagamento interno em '.$order['closed_at'].'. Documento criado pelo fornecedor certificado.',
            'client'=>['name'=>$clientName,'code'=>'G360-'.$cid.'-'.($clientNif?:'CF-'.$orderId)],
            'items'=>$invoiceItems,
        ]];
        if($clientNif!=='')$payload['invoice']['client']['fiscal_id']=$clientNif;
        if($anyExempt)$payload['invoice']['tax_exemption']=$exemption;
        // A chave de idempotência NUNCA muda se já ocorreu um erro de validação.
        $uid=$previous?(string)$previous['idempotency_uid']:bin2hex(random_bytes(16));
        $payload['proprietary_uid']=$uid;
        fiscal_validate_invoice_payload($payload,$docType); // Nenhum pedido externo se os dados falharem.
        if($previous){
            $claim=$db->prepare("UPDATE fiscal_documents SET status='creating',error_message='',updated_at=? WHERE id=? AND company_id=? AND status='validation_failed' AND provider_id IS NULL");
            $claim->execute([now(),$previous['id'],$cid]);
            if($claim->rowCount()!==1)throw new RuntimeException('Este processo fiscal foi alterado noutra sessão. Atualiza a página.');
            $recordId=(int)$previous['id'];
        }else{
            // UNIQUE(company_id,order_id) impede tentativas simultâneas, mesmo noutro navegador.
            $db->prepare('INSERT INTO fiscal_documents(company_id,order_id,provider,document_type,idempotency_uid,status,internal_total_cents,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)')
               ->execute([$cid,$orderId,'invoicexpress',$docType,$uid,'creating',(int)$order['total_cents'],user_id(),now(),now()]);
            $recordId=(int)$db->lastInsertId();
        }
        try{
            $answer=fiscal_request($credentials,'POST','/'.$docType.'.json',[],$payload,[201]);
            $invoice=$answer['data'][$docType==='invoice_receipts'?'invoice_receipt':'invoice']??[];
            $externalId=(int)($invoice['id']??0);
            if($externalId<1)throw new RuntimeException('O fornecedor aceitou a criação, mas não devolveu um identificador. É necessária confirmação manual.');
            // Guardar o ID imediatamente, ANTES de qualquer consulta suplementar.
            $db->prepare('UPDATE fiscal_documents SET provider_id=?,status=?,error_message=?,updated_at=? WHERE id=? AND company_id=?')
               ->execute([$externalId,'draft','',now(),$recordId,$cid]);
            try{
                $record=fiscal_sync_document($db,$credentials,one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[$recordId,$cid]));
                if($record['status']==='draft_mismatch')
                    flash('Rascunho criado, mas o valor fiscal diverge do pedido. Corrige o IVA e revê o documento no InvoiceXpress antes de emitir.','error');
                else flash('Rascunho criado e confirmado no InvoiceXpress. Revê o documento antes da emissão fiscal.');
            }catch(Throwable $syncError){
                flash('Rascunho criado no InvoiceXpress com o identificador '.$externalId.'. A atualização dos valores falhou, mas o documento está associado ao pedido. Usa «Atualizar estado».','error');
            }
        }catch(Throwable $e){
            // 422 significa rejeição explícita por validação; não equivale a timeout.
            // Continua a existir UM SÓ processo e o mesmo proprietary_uid para correção manual.
            $saved=one($db,'SELECT provider_id FROM fiscal_documents WHERE id=? AND company_id=?',[$recordId,$cid]);
            if((int)($saved['provider_id']??0)>0)throw $e;
            $rejected=((int)$e->getCode()===422);
            $state=$rejected?'validation_failed':'uncertain';
            $message=$rejected
                ? 'O InvoiceXpress rejeitou o rascunho sem emitir um documento. Revê os dados e corrige este mesmo processo fiscal. '
                : 'A resposta do InvoiceXpress é inconclusiva. Confirma no fornecedor se existe um documento antes de agir. ';
            $db->prepare('UPDATE fiscal_documents SET status=?,error_message=?,updated_at=? WHERE id=? AND company_id=? AND provider_id IS NULL')
               ->execute([$state,mb_substr($message.$e->getMessage(),0,500),now(),$recordId,$cid]);
            throw new RuntimeException($message.$e->getMessage().' Nunca cries outra fatura para este pedido.');
        }
    }elseif($task==='recover_legacy_422'){
        // Registos antigos marcavam TODOS os erros como incertos, até um 422 explícito.
        // Esta transição não comunica com o fornecedor nem cria documentos.
        if(req('confirmation')!=='CONFIRMO 422' || req('checked_provider')!=='1')
            throw new RuntimeException('Confirma o erro HTTP 422 e verifica no InvoiceXpress que o pedido não possui documento.');
        $record=one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[n('document_id',1),$cid]);
        if(!$record || $record['status']!=='uncertain' || (int)$record['provider_id']>0)
            throw new RuntimeException('Só é possível rever registos antigos inconclusivos que não tenham ID externo.');
        $db->prepare("UPDATE fiscal_documents SET status='validation_failed',error_message=?,updated_at=? WHERE id=? AND company_id=? AND status='uncertain' AND provider_id IS NULL")
            ->execute(['Erro HTTP 422 da versão anterior confirmado pelo utilizador. Nenhum documento externo encontrado. Mantido o identificador original para evitar duplicações.',now(),$record['id'],$cid]);
        flash('Processo desbloqueado para corrigir o rascunho, preservando o identificador original. Não foi criada qualquer fatura.');
    }elseif($task==='attach_existing'){
        $record=one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[n('document_id',1),$cid]);
        if(!$record)throw new RuntimeException('Processo fiscal não encontrado.');
        if(req('confirm_reference')!=='ASSOCIAR')throw new RuntimeException('Escreve ASSOCIAR para confirmar.');
        $credentials=fiscal_credentials($db,$cid);
        $record=fiscal_attach_existing($db,$credentials,$record,n('provider_id',1));
        flash('Documento existente associado e confirmado. Nenhuma fatura adicional foi criada.');
    }elseif($task==='refresh'){
        $record=one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[n('document_id',1),$cid]);
        if(!$record)throw new RuntimeException('Documento não encontrado nesta empresa.');
        $credentials=fiscal_credentials($db,$cid);
        $record=fiscal_sync_document($db,$credentials,$record);
        flash('Estado atualizado a partir do fornecedor: '.(string)$record['status'].'.');
    }elseif($task==='finalize'){
        $record=one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[n('document_id',1),$cid]);
        if(!$record||!in_array($record['status'],['draft','draft_mismatch'],true))throw new RuntimeException('Só é possível finalizar rascunhos.');
        if(req('confirm_final')!=='EMITIR')throw new RuntimeException('Escreve EMITIR para confirmares a emissão definitiva.');
        $company=fiscal_company_ready($db,$cid);
        $credentials=fiscal_credentials($db,$cid);
        $account=fiscal_verify_account($credentials,(string)$company['nif']);
        if(($account['at_configured']??null)===false)throw new RuntimeException('A comunicação à AT não está configurada no InvoiceXpress.');
        if(($account['at_configured']??null)===null && req('confirm_at')!=='1')throw new RuntimeException('Confirma que verificaste no InvoiceXpress as séries, o ATCUD e a comunicação à AT antes de emitir.');
        $record=fiscal_sync_document($db,$credentials,$record);
        if($record['status']!=='draft'||(int)$record['provider_total_cents']!==(int)$record['internal_total_cents'])
            throw new RuntimeException('O rascunho não está pronto para emissão: verifica o total e o estado no fornecedor.');
        // Alguns fornecedores só atribuem a numeração/ATCUD definitivo ao finalizar.
        // Bloqueia séries expressamente inválidas; se ainda não houver código no
        // rascunho, valida a resposta após a emissão em vez de bloquear todas as séries.
        if(in_array(strtoupper(trim((string)$record['atcud'])),['N/D','N/A'],true))
            throw new RuntimeException('A série não está registada para ATCUD. Configura-a e valida-a no InvoiceXpress antes de finalizar.');
        // Bloquear uma segunda finalização enquanto a primeira estiver em curso.
        $locked=$db->prepare("UPDATE fiscal_documents SET status='finalizing',updated_at=? WHERE id=? AND company_id=? AND status='draft'");
        $locked->execute([now(),$record['id'],$cid]);
        if($locked->rowCount()!==1)throw new RuntimeException('Este documento está a ser alterado noutra sessão. Atualiza o estado.');
        try{
            fiscal_request($credentials,'PUT','/'.($record['document_type']??'invoice_receipts').'/'.(int)$record['provider_id'].'/change-state.json',[],['invoice'=>['state'=>'finalized']]);
        }catch(Throwable $e){
            // Nunca repetir o PUT após uma resposta inconclusiva.
            try{$record=fiscal_sync_document($db,$credentials,one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[$record['id'],$cid]));}
            catch(Throwable $ignore){}
            if($record['status']!=='final'){
                $db->prepare("UPDATE fiscal_documents SET status='finalization_uncertain',error_message=?,updated_at=? WHERE id=? AND company_id=?")
                    ->execute(['Finalização inconclusiva. Consulta o documento no InvoiceXpress. Não voltes a emitir.',now(),$record['id'],$cid]);
                throw new RuntimeException('Emissão inconclusiva: consulta o InvoiceXpress. Não voltes a emitir nem abras outra fatura. '.$e->getMessage());
            }
        }
        $record=fiscal_sync_document($db,$credentials,one($db,'SELECT * FROM fiscal_documents WHERE id=? AND company_id=?',[$record['id'],$cid]));
        if($record['status']!=='final')throw new RuntimeException('O fornecedor ainda não confirmou a finalização.');
        if(in_array(strtoupper(trim((string)$record['atcud'])),['','N/D','N/A'],true))
            throw new RuntimeException('Documento finalizado no InvoiceXpress, mas o ATCUD não está confirmado na resposta. NÃO emitas outra fatura: consulta imediatamente o documento e a série no fornecedor.');
        flash('Fatura finalizada no fornecedor certificado: '.($record['invoice_number']?:'#'.$record['provider_id']).'. A comunicação AT deve ser confirmada no histórico InvoiceXpress.');
    }elseif($task==='saft'){
        require_permission($db,$cid,'exports.use');
        $company=fiscal_company_ready($db,$cid);
        $credentials=fiscal_credentials($db,$cid);
        fiscal_verify_account($credentials,(string)$company['nif']);
        $month=n('month',1,12);$year=n('year',2017,2100);
        $answer=fiscal_request($credentials,'GET','/api/export_saft.json',['month'=>$month,'year'=>$year],null,[200,202]);
        if($answer['status']===202){flash('O fornecedor está a gerar o SAF-T (PT). Volta a pedir a exportação dentro de instantes.');}
        else{
            $url=fiscal_safe_link((string)($answer['data']['url']??''));
            if($url==='')throw new RuntimeException('O fornecedor não devolveu um URL HTTPS de SAF-T válido.');
            // URL fornecida pelo próprio serviço certificado; navegador recebe, não executa no servidor.
            header('Location: '.$url, true,303);exit;
        }
    }else throw new RuntimeException('Operação fiscal não reconhecida.');
}catch(Throwable $e){
    if(isset($db) && $db instanceof PDO && $db->inTransaction())$db->rollBack();
    flash($e->getMessage(),'error');
}
header('Location: index.php?page=fiscal',true,303);
exit;
