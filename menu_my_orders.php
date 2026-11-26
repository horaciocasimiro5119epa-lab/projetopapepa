<?php
/** Estado dos artigos pedidos no menu pelo navegador que possui a sessão da mesa. */
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);echo '{"error":"Método não permitido."}';exit;}
$slug=strtolower(trim((string)($_GET['empresa']??'')));
if(!preg_match('/^[a-z0-9][a-z0-9-]{1,60}[a-z0-9]$/D',$slug)){http_response_code(404);echo '{"error":"Menu não encontrado."}';exit;}
$company=one($db,'SELECT id FROM companies WHERE menu_slug=?',[$slug]);
if(!$company){http_response_code(404);echo '{"error":"Menu não encontrado."}';exit;}
$cid=(int)$company['id'];
require_once __DIR__.'/app/domain/company_features.php';
if(!g360_feature($db,$cid,'menu_enabled')){http_response_code(404);echo '{"error":"Menu indisponível."}';exit;}
$secret=(string)($_SESSION['menu_access_'.$cid]??'');
session_write_close();
require_once __DIR__.'/app/domain/menu_request_tracking.php';
try{
  ug_menu_tracking_setup($db);
  $rows=ug_menu_tracking_rows($db,$cid,$secret,60);
  echo json_encode(['ok'=>true,'items'=>$rows,'counts'=>ug_menu_tracking_counts($rows)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo '{"error":"Não foi possível consultar o estado dos pedidos."}';}
