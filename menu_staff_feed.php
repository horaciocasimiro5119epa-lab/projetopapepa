<?php
/** Atualização dos pedidos pendentes, apenas para utilizadores autenticados e autorizados. */
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo '{"error":"Método não permitido."}';exit;}
if(!user_id() || !company_id() || !member($db,company_id())){
    http_response_code(401);echo '{"error":"É necessário iniciar sessão.","login_required":true}';exit;
}
$cid=company_id();require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'menu_enabled')){http_response_code(403);exit('Funcionalidade desativada nesta empresa.');}
if(!has_permission($db,$cid,'orders.manage')){
    http_response_code(403);echo '{"error":"Acesso não autorizado."}';exit;
}
// Libertar o bloqueio da sessão para não atrasar pedidos feitos noutras abas.
session_write_close();
try{
    require_once __DIR__.'/app/domain/menu_request_tracking.php';
    ug_menu_tracking_setup($db);
    require_once __DIR__.'/app/domain/menu_queue.php';
    $access=unigest_pending_menu_access($db,$cid);
    $products=unigest_pending_menu_products($db,$cid);
    $canProcessMenuItems=has_permission($db,$cid,'pos.use') && (bool)till($db,$cid);
    foreach($products as &$product){
        $product['image_url']=product_image($product);
        $product['can_accept']=$canProcessMenuItems && $product['access_status']==='aprovado' && $product['expires_at']>now();
        $product['accept_reason']=($product['access_status']!=='aprovado'||$product['expires_at']<=now())?'A autorização da mesa já não está ativa.':($canProcessMenuItems?'':'Abre a caixa e confirma que tens acesso ao POS antes de aceitar.');
        unset($product['image_path'],$product['expires_at']);
    }
    unset($product);
    $history=unigest_recent_menu_decisions($db,$cid);
    $signature=hash('sha256',json_encode([$access,$products,$history],JSON_THROW_ON_ERROR));
    if(hash_equals($signature,(string)($_GET['revision']??''))){
        echo json_encode(['changed'=>false,'revision'=>$signature,'count'=>count($access)+count($products)],JSON_UNESCAPED_UNICODE);
    }else{
        echo json_encode(['changed'=>true,'revision'=>$signature,'count'=>count($access)+count($products),'access'=>$access,'products'=>$products,'history'=>$history],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    }
}catch(Throwable $e){
    http_response_code(503);echo '{"error":"Não foi possível obter as atualizações. Tenta novamente."}';
}
