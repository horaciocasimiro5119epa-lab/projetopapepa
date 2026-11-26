<?php
/** Operações internas de autorização e pedidos do menu. */
require __DIR__.'/bootstrap.php';must_company($db);$cid=company_id();require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'menu_enabled')){http_response_code(403);exit('Funcionalidade desativada nesta empresa.');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
require_once __DIR__.'/app/domain/menu_request_tracking.php';
ug_menu_tracking_setup($db);
try{
 check_token();require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_write($db,$cid);require_permission($db,$cid,'orders.manage');
 $operation=(string)($_POST['operation']??'');
 $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
 if(!$id)throw new RuntimeException('Identificador inválido.');
 $db->exec('PRAGMA busy_timeout=5000');$db->beginTransaction();
 if(in_array($operation,['approve','reject'],true)){
  $access=one($db,'SELECT * FROM public_menu_access WHERE id=? AND company_id=?',[$id,$cid]);
  if(!$access||$access['status']!=='pendente'||$access['expires_at']<=now())throw new RuntimeException('Pedido de autorização indisponível ou expirado.');
  if($operation==='approve'){
   $existing=one($db,"SELECT id FROM public_menu_access WHERE company_id=? AND table_id=? AND status='aprovado' AND expires_at>?",[$cid,$access['table_id'],now()]);
   if($existing)throw new RuntimeException('Já existe uma sessão ativa nesta mesa.');
  }
  $db->prepare('UPDATE public_menu_access SET status=? WHERE id=? AND company_id=?')->execute([$operation==='approve'?'aprovado':'rejeitado',$id,$cid]);
  $message=$operation==='approve'?'Mesa autorizada.':'Acesso recusado.';
 }elseif(in_array($operation,['accept_item','reject_item'],true)){
  $request=one($db,'SELECT mr.*,ma.table_id,ma.status access_status,ma.expires_at FROM public_menu_requests mr JOIN public_menu_access ma ON ma.id=mr.access_id AND ma.company_id=mr.company_id WHERE mr.id=? AND mr.company_id=?',[$id,$cid]);
  if(!$request||$request['status']!=='pendente')throw new RuntimeException('Pedido já tratado ou inexistente.');
  if($operation==='reject_item'){
   $db->prepare("UPDATE public_menu_requests SET status='rejeitado',decision_at=? WHERE id=? AND company_id=?")->execute([now(),$id,$cid]);$message='Produto recusado.';
  }else{
   require_permission($db,$cid,'pos.use');
   if($request['access_status']!=='aprovado'||$request['expires_at']<=now())throw new RuntimeException('A autorização da mesa expirou.');
   if(!till($db,$cid))throw new RuntimeException('Abre a caixa antes de aceitar pedidos do menu.');
   $product=one($db,'SELECT * FROM products WHERE id=? AND company_id=? AND active=1',[$request['product_id'],$cid]);
   if(!$product)throw new RuntimeException('Produto indisponível.');
   $qty=(int)$request['qty'];
   $upd=$db->prepare('UPDATE products SET qty=qty-? WHERE id=? AND company_id=? AND qty>=?');$upd->execute([$qty,$product['id'],$cid,$qty]);
   if(!$upd->rowCount())throw new RuntimeException('Stock insuficiente.');
   $order=one($db,"SELECT * FROM orders WHERE company_id=? AND table_id=? AND status='aberto' ORDER BY id DESC LIMIT 1",[$cid,$request['table_id']]);
   if(!$order){$db->prepare("INSERT INTO orders(company_id,table_id,origin,created_at) VALUES(?,?,'mesa',?)")->execute([$cid,$request['table_id'],now()]);$orderId=(int)$db->lastInsertId();}
   else $orderId=(int)$order['id'];
   $paymentMode=($order['payment_mode']??'posterior')==='antecipado'?'aguarda_pagamento':'aguarda_servico';
   $db->prepare('INSERT INTO order_items(order_id,product_id,name,qty,price_cents,cost_cents,service_status) VALUES(?,?,?,?,?,?,?)')
     ->execute([$orderId,$product['id'],$product['name'],$qty,$product['price_cents'],$product['cost_cents'],$paymentMode]);
   $orderItemId=(int)$db->lastInsertId();
   $amount=$qty*(int)$product['price_cents'];
   $db->prepare('UPDATE orders SET subtotal_cents=subtotal_cents+?,total_cents=total_cents+? WHERE id=? AND company_id=?')->execute([$amount,$amount,$orderId,$cid]);
   $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$cid,$product['id'],-$qty,'Menu digital · Pedido #'.$orderId,user_id(),now()]);
   $db->prepare("UPDATE public_menu_requests SET status='aceite',order_id=?,order_item_id=?,decision_at=?,price_cents_snapshot=?,name_snapshot=? WHERE id=? AND company_id=?")
      ->execute([$orderId,$orderItemId,now(),(int)$product['price_cents'],(string)$product['name'],$id,$cid]);
   $message='Produto aceite e adicionado ao pedido #'.$orderId.'.';
  }
 }else throw new RuntimeException('Operação inválida.');
 $db->commit();flash($message);
 if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){
  unset($_SESSION['flash']);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
  echo json_encode(['ok'=>true,'message'=>$message,'operation'=>$operation,'id'=>$id],JSON_UNESCAPED_UNICODE);exit;
 }
}catch(Throwable $e){
 if($db->inTransaction())$db->rollBack();flash($e->getMessage(),'error');
 if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')){
  unset($_SESSION['flash']);http_response_code(422);header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;
 }
}
header('Location: index.php?page=orders');exit;
