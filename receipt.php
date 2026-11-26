<?php
/** Comprovativo interno de pagamento. NÃO é fatura, fatura-recibo ou documento fiscal. */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/app/domain/company_features.php';
must_company($db);$cid=company_id();require_permission($db,$cid,'pos.use');
if(!g360_feature($db,$cid,'pos_enabled')){http_response_code(403);exit('Caixa POS desativada nesta empresa.');}
$orderId=filter_input(INPUT_GET,'order',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if(!$orderId){http_response_code(400);exit('Pedido inválido.');}
$order=one($db,"SELECT o.*,p.method,p.created_at payment_at FROM orders o LEFT JOIN payments p ON p.order_id=o.id AND p.company_id=o.company_id WHERE o.id=? AND o.company_id=? AND o.status='pago' ORDER BY p.id DESC",[$orderId,$cid]);
if(!$order){http_response_code(404);exit('Pagamento não encontrado nesta empresa.');}
$company=one($db,'SELECT name,nif FROM companies WHERE id=?',[$cid]);
$lines=all($db,'SELECT name,qty,price_cents FROM order_items WHERE order_id=? ORDER BY id',[$orderId]);
$receipt=one($db,'SELECT * FROM order_receipts WHERE company_id=? AND order_id=?',[$cid,$orderId])?:['client_nif'=>'','client_email'=>''];
$prefs=g360_preferences($db,$cid);
$notice='';$error='';
function g360_receipt_lines(array $rows):string {
 $output='';foreach($rows as $line){$output.=($line['qty'].' x '.$line['name'].'  '.money((int)$line['qty']*(int)$line['price_cents']))."\n";}return $output;
}
if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
 try{
   if(!g360_feature($db,$cid,'receipt_email_enabled'))throw new RuntimeException('O envio de recibos está desativado nesta empresa.');
   check_token();$email=trim((string)($_POST['email']??''));
   if(strlen($email)>160||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$email))throw new RuntimeException('Introduz um email válido.');
   $sender=trim((string)($prefs['receipt_sender_email']??''));
   if(!filter_var($sender,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$sender))throw new RuntimeException('Configura primeiro um email de origem válido em Empresa e equipa → Recibos.');
   $db->exec("CREATE TABLE IF NOT EXISTS receipt_email_log(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,order_id INTEGER NOT NULL,recipient TEXT NOT NULL,created_at TEXT NOT NULL)");
   $recent=scalar($db,"SELECT COUNT(*) FROM receipt_email_log WHERE company_id=? AND order_id=? AND created_at>=datetime('now','-1 hour')",[$cid,$orderId]);
   if((int)$recent>=5)throw new RuntimeException('Limite de 5 envios por hora para este pedido.');
   $message="COMPROVATIVO INTERNO DE PAGAMENTO — NÃO É DOCUMENTO FISCAL\n";
   $message.=$company['name']."\nPedido #".$orderId."\nData: ".$order['payment_at']."\n";
   if($receipt['client_nif'])$message.='NIF indicado pelo cliente: '.$receipt['client_nif']."\n";
   $message.="\n".g360_receipt_lines($lines)."\nTotal pago: ".money($order['total_cents'])."\n";
   $message.='Método: '.$order['method']."\n\n".$prefs['receipt_business_footer']."\n";
   $subject='=?UTF-8?B?'.base64_encode('Comprovativo de pagamento #'.$orderId.' · '.$company['name']).'?=';
   $headers=['From: '.$sender,'Reply-To: '.$sender,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','X-Auto-Response-Suppress: All'];
   if(!mail($email,$subject,$message,implode("\r\n",$headers)))throw new RuntimeException('O serviço de email deste servidor não está configurado ou não aceitou o envio. Podes imprimir o comprovativo.');
   $db->prepare('INSERT INTO receipt_email_log(company_id,order_id,recipient,created_at) VALUES(?,?,?,?)')->execute([$cid,$orderId,$email,now()]);
   $notice='O servidor aceitou o envio para '.$email.'. A entrega à caixa de correio depende do serviço de email.';
 }catch(Throwable $e){$error=$e->getMessage();}
}
$methodLabels=['dinheiro'=>'Dinheiro','cartao'=>'Cartão','mbway'=>'MB Way','transferencia'=>'Transferência'];
?>
<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comprovativo #<?=(int)$orderId?> · <?=h($company['name'])?></title><link rel="icon" href="favicon.png?v=41" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=41"><link rel="stylesheet" href="assets/css/receipt.css"><link rel="stylesheet" href="assets/css/public-v62.css?v=62"></head>
<body class="receipt-screen"><main class="receipt-shell">
<header class="receipt-toolbar"><a href="index.php?page=pos" class="receipt-back">← Voltar à caixa</a><img src="logo-icon.png?v=41" alt="UniGest" width="46" height="46"></header>
<div class="receipt-layout"><section class="receipt-paper" id="printable-receipt">
  <header class="receipt-paper-head"><img src="logo.png?v=41" class="unigest-receipt-brand" alt="UniGest" width="180" height="60"><h1><?=h($company['name'])?></h1><?php if($company['nif']):?><p>NIF da empresa: <?=h($company['nif'])?></p><?php endif; ?></header>
  <p class="receipt-type">COMPROVATIVO INTERNO DE PAGAMENTO</p><p class="receipt-fiscal-warning">Não é fatura nem documento fiscal.</p>
  <div class="receipt-meta"><div><span>N.º do pedido</span><strong>#<?=(int)$orderId?></strong></div><div><span>Data de pagamento</span><strong><?=h($order['payment_at']??$order['closed_at'])?></strong></div><div><span>Método</span><strong><?=h($methodLabels[$order['method']]??$order['method'])?></strong></div><?php if($receipt['client_nif']):?><div><span>NIF indicado pelo cliente</span><strong><?=h($receipt['client_nif'])?></strong></div><?php endif;?></div>
  <div class="receipt-items"><div class="receipt-item receipt-item-heading"><span>Artigo</span><span>Valor</span></div><?php foreach($lines as $line):?><div class="receipt-item"><span><strong><?=h($line['name'])?></strong><small><?=(int)$line['qty']?> × <?=money($line['price_cents'])?></small></span><b><?=money((int)$line['qty']*(int)$line['price_cents'])?></b></div><?php endforeach;?></div>
  <div class="receipt-total"><span>Total pago</span><strong><?=money($order['total_cents'])?></strong></div><p class="receipt-footer-text"><?=h($prefs['receipt_business_footer'])?></p><small class="receipt-fiscal-warning">A emissão de fatura/fatura-recibo legal faz-se separadamente através de software certificado.</small>
</section><aside class="receipt-actions"><span class="receipt-eyebrow">PAGAMENTO CONCLUÍDO</span><h2>O teu pedido está pago.</h2><p>O NIF indicado durante a cobrança é apresentado no comprovativo. As opções de impressão e envio dependem das definições da empresa.</p><?php if(g360_feature($db,$cid,'receipt_print_enabled')): ?><button class="receipt-primary" type="button" onclick="window.print()">▤ Imprimir comprovativo</button><?php endif; ?>
<?php if(g360_feature($db,$cid,'receipt_email_enabled')): ?><form method="post" action="receipt.php?order=<?=(int)$orderId?>" class="receipt-email"><input type="hidden" name="csrf" value="<?=h(token())?>"><label>Enviar por email<input type="email" name="email" maxlength="160" value="<?=h($receipt['client_email'])?>" placeholder="cliente@exemplo.pt" required></label><button type="submit">Enviar comprovativo</button></form><?php endif; ?>
<?php if($notice):?><p class="receipt-notice" role="status"><?=h($notice)?></p><?php endif;?><?php if($error):?><p class="receipt-error" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if(g360_feature($db,$cid,'fiscal_enabled') && has_permission($db,$cid,'fiscal.use')): ?><a class="receipt-link" href="index.php?page=fiscal&fiscal_order=<?=(int)$orderId?>">Faturação fiscal no InvoiceXpress ↗</a><?php endif; ?><a class="receipt-link" href="index.php?page=orders">Ver lista de pedidos →</a><p class="receipt-help">O envio exige que o serviço de email do servidor esteja configurado. O envio aceite pelo servidor não garante entrega.</p>
</aside></div></main></body></html>
