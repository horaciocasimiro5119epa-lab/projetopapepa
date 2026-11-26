<?php
/** Exportar logs filtrados. Requer a mesma permissão da interface. */
require __DIR__.'/bootstrap.php';
require __DIR__.'/audit_filters.php';
must_company($db);
$cid=company_id();require_permission($db,$cid,'logs.view');
require_once __DIR__.'/app/domain/company_features.php';
if(!g360_feature($db,$cid,'logs_enabled')){http_response_code(403);exit('Logs desativados nesta empresa.');}
if(!g360_feature($db,$cid,'exports_enabled')){http_response_code(403);exit('Exportações desativadas nesta empresa.');}
try{$filter=activity_query_filters($db,$cid,$_GET);}catch(Throwable $e){http_response_code(400);exit('Filtro inválido.');}
$rows=all($db,'SELECT l.id,l.created_at,u.name actor,l.source,l.entity,l.entity_id,l.operation,l.details FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id WHERE '.$filter['where'].' ORDER BY l.created_at DESC,l.id DESC LIMIT 10000',$filter['args']);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="gestao360_logs_'.date('Ymd_His').'.csv"');
header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,['ID','Data e hora (Portugal)','Utilizador','Origem','Área','ID do elemento','Operação','Detalhes'],';');
foreach($rows as $row){$cells=array_values($row);foreach($cells as &$cell){$cell=(string)($cell??'');if(preg_match('/^[=+@\-\t\r]/u',$cell))$cell="'".$cell;}unset($cell);fputcsv($out,$cells,';');}
fclose($out);
