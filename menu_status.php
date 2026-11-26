<?php
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$slug=(string)($_GET['empresa']??'');
if(!preg_match('/^[a-z0-9][a-z0-9-]{1,60}[a-z0-9]$/',$slug)){http_response_code(404);exit('{}');}
$company=one($db,'SELECT id FROM companies WHERE menu_slug=?',[$slug]);
if(!$company){http_response_code(404);exit('{}');}
$cid=(int)$company['id'];require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'menu_enabled')){http_response_code(404);exit('{}');}$secret=(string)($_SESSION['menu_access_'.$cid]??'');
session_write_close(); // Não bloquear ações noutras páginas do mesmo navegador.
$access=$secret!==''?one($db,'SELECT status FROM public_menu_access WHERE company_id=? AND secret_hash=? AND expires_at>?',[$cid,hash('sha256',$secret),now()]):null;
echo json_encode(['status'=>$access['status']??'sem_acesso'],JSON_UNESCAPED_UNICODE);
