<?php
/** Estado leve das mesas, com isolamento por empresa e permissões. */
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');
if(!user_id()||!company_id()||!member($db,company_id())){http_response_code(401);echo '{"error":"Sessão expirada."}';exit;}
$cid=company_id();require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'tables_enabled')){http_response_code(403);exit('Funcionalidade desativada nesta empresa.');}require_permission($db,$cid,'tables.view');
session_write_close();
$rows=all($db,"SELECT t.id,t.name,
 EXISTS(SELECT 1 FROM orders o WHERE o.company_id=t.company_id AND o.table_id=t.id AND o.status='aberto') AS has_order,
 EXISTS(SELECT 1 FROM public_menu_access a WHERE a.company_id=t.company_id AND a.table_id=t.id AND a.status='aprovado' AND a.expires_at>?) AS has_menu
 FROM dining_tables t WHERE t.company_id=? ORDER BY t.id",[now(),$cid]);
echo json_encode(['tables'=>$rows],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
