<?php
require __DIR__.'/bootstrap.php';
$g360Ajax=($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest';
must_company($db);
try {
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Método inválido.');
 check_token();$cid=company_id();require_permission($db,$cid,'company.manage');
 if(req('action')!=='company_edit')throw new RuntimeException('Operação inválida.');
 $name=req('name');$nif=req('nif');
 if(mb_strlen($name)<2 || mb_strlen($name)>120)throw new RuntimeException('O nome deve conter entre 2 e 120 caracteres.');
 if(!valid_nif($nif))throw new RuntimeException('NIF inválido: indica 9 dígitos com dígito de controlo válido ou deixa em branco.');
 $db->beginTransaction();
 $db->prepare('UPDATE companies SET name=?,nif=? WHERE id=?')->execute([$name,$nif,$cid]);
 $db->prepare("INSERT INTO audit_logs(company_id,user_id,source,entity,entity_id,operation,details,created_at) VALUES(?,?,'Definições','companies',?,'UPDATE',?,?)")
    ->execute([$cid,user_id(),$cid,'Nome e/ou NIF da empresa atualizados',now()]);
 $db->commit();if($g360Ajax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'message'=>'Dados da empresa atualizados.']);exit;}flash('Dados da empresa atualizados.');
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($g360Ajax){http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);exit;}flash($e->getMessage(),'error');}
header('Location: index.php?page=settings#g360-sec-company',true,303);exit;
