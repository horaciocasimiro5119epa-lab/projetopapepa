<?php
require __DIR__.'/bootstrap.php';must_company($db);
$g360Ajax=($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest';
try{
 if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Método inválido.');
 check_token();$cid=company_id();require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_write($db,$cid);require_permission($db,$cid,'roles.manage');
 $actorRole=current_company_role($db,$cid);$actorIsOwner=($actorRole['role_key']??'')==='dono';
 $act=req('action');if($g360Ajax && !in_array($act,['role_update','member_reassign'],true))throw new RuntimeException('Operação exige confirmação manual.');$db->beginTransaction();
 $logOp='UPDATE';$logEntity='roles';
 if($act==='role_create'||$act==='role_update'){
  $name=req('name');if(mb_strlen($name)<2||mb_strlen($name)>60)throw new RuntimeException('Nome do cargo inválido.');
  $input=$_POST['permissions']??[];
  if(!is_array($input)||count($input)>count(permission_catalog()))throw new RuntimeException('Permissões inválidas.');
  $perms=array_values(array_unique(array_map('strval',$input)));
  foreach($perms as $permission)if(!isset(permission_catalog()[$permission]))throw new RuntimeException('Permissão desconhecida.');
  if(!$actorIsOwner){foreach($perms as $permission){if(!has_permission($db,$cid,$permission))throw new RuntimeException('Só podes atribuir permissões que já possuis.');}}
  $encoded=json_encode($perms,JSON_THROW_ON_ERROR);
  if($act==='role_create'){
   $key='custom_'.bin2hex(random_bytes(8));
   $db->prepare('INSERT INTO company_roles(company_id,role_key,name,permissions_json,builtin) VALUES(?,?,?,?,0)')->execute([$cid,$key,$name,$encoded]);
   $roleId=(int)$db->lastInsertId();$success='Novo cargo criado.';$logOp='INSERT';
  }else{
   $roleId=n('role_id',1);
   $role=one($db,'SELECT * FROM company_roles WHERE id=? AND company_id=?',[$roleId,$cid]);
   if(!$role||$role['role_key']==='dono')throw new RuntimeException('Não podes editar as permissões do Dono.');
   if(!$actorIsOwner){
    foreach((json_decode((string)$role['permissions_json'],true)?:[]) as $existingPermission)
     if(!has_permission($db,$cid,(string)$existingPermission))throw new RuntimeException('Não podes editar um cargo com permissões superiores às tuas.');
   }
   $db->prepare('UPDATE company_roles SET name=?,permissions_json=? WHERE id=? AND company_id=?')->execute([$name,$encoded,$roleId,$cid]);
   $success='Cargo e permissões atualizados.';
  }
  $log='Cargo '.$name.'; permissões atuais: '.implode(', ',$perms);
 }elseif($act==='role_delete'){
  $roleId=n('role_id',1);
  $role=one($db,'SELECT * FROM company_roles WHERE company_id=? AND id=?',[$cid,$roleId]);
  if(!$role)throw new RuntimeException('O cargo já não existe.');
  if($role['role_key']==='dono')throw new RuntimeException('O cargo Dono é protegido e não pode ser apagado.');
  if(!$actorIsOwner){
   foreach(json_decode((string)$role['permissions_json'],true)?:[] as $permission)
    if(!has_permission($db,$cid,(string)$permission))throw new RuntimeException('Não podes eliminar um cargo com permissões superiores às tuas.');
  }
  $members=(int)scalar($db,'SELECT COUNT(*) FROM members WHERE company_id=? AND role_id=?',[$cid,$roleId]);
  $replacement=filter_var($_POST['replacement_role_id']??0,FILTER_VALIDATE_INT);
  if($members>0){
   if(!$replacement||$replacement===$roleId)throw new RuntimeException('Este cargo tem '.$members.' membro(s). Seleciona outro cargo para os reatribuir antes da eliminação.');
   $target=one($db,'SELECT * FROM company_roles WHERE company_id=? AND id=?',[$cid,$replacement]);
   if(!$target)throw new RuntimeException('O cargo de destino não existe nesta empresa.');
   if(!$actorIsOwner){
    if($target['role_key']==='dono')throw new RuntimeException('Só o Dono pode atribuir o cargo Dono.');
    foreach(json_decode((string)$target['permissions_json'],true)?:[] as $permission)
     if(!has_permission($db,$cid,(string)$permission))throw new RuntimeException('Não podes atribuir permissões superiores às tuas.');
   }
   $db->prepare('UPDATE members SET role_id=?,role=? WHERE company_id=? AND role_id=?')
       ->execute([$replacement,'custom',$cid,$roleId]);
  }
  $db->prepare('DELETE FROM company_roles WHERE company_id=? AND id=?')->execute([$cid,$roleId]);
  $logOp='DELETE';$success='Cargo eliminado definitivamente do catálogo.'.($members?' '.$members.' membro(s) reatribuídos.':'');
  $log='Cargo '.$role['name'].' eliminado; '.$members.' membro(s) reatribuídos para cargo #'.(int)$replacement;
 }elseif($act==='member_assign'){
  $email=mb_strtolower(req('email'));
  if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Email inválido.');
  $roleId=n('role_id',1);
  $role=one($db,'SELECT * FROM company_roles WHERE company_id=? AND id=?',[$cid,$roleId]);
  if(!$role)throw new RuntimeException('Cargo inexistente.');
  if(!$actorIsOwner){
    if($role['role_key']==='dono')throw new RuntimeException('Só um dono pode atribuir o cargo Dono.');
    foreach((json_decode($role['permissions_json'],true)?:[]) as $permission){if(!has_permission($db,$cid,(string)$permission))throw new RuntimeException('Não podes atribuir um cargo com mais permissões do que as tuas.');}
  }
  $user=one($db,'SELECT id FROM users WHERE email=?',[$email]);
  if(!$user)throw new RuntimeException('Essa pessoa precisa de criar uma conta antes de receber o cargo.');
  $member=one($db,'SELECT m.role_id,r.role_key FROM members m LEFT JOIN company_roles r ON r.id=m.role_id WHERE m.company_id=? AND m.user_id=?',[$cid,$user['id']]);
  if(($member['role_key']??'')==='dono' && $role['role_key']!=='dono' && !$actorIsOwner)throw new RuntimeException('Só um dono pode alterar o cargo de outro dono.');
  if(($member['role_key']??'')==='dono' && $role['role_key']!=='dono'){
   $owners=scalar($db,"SELECT COUNT(*) FROM members m JOIN company_roles r ON r.id=m.role_id WHERE m.company_id=? AND r.role_key='dono'",[$cid]);
   if($owners<=1)throw new RuntimeException('Não é permitido remover o último dono da empresa.');
  }
  $db->prepare("INSERT INTO members(user_id,company_id,role,role_id) VALUES(?,?,'custom',?) ON CONFLICT(user_id,company_id) DO UPDATE SET role_id=excluded.role_id,role='custom'")
    ->execute([$user['id'],$cid,$roleId]);
  $logEntity='members';$success='Cargo atribuído à conta indicada.';
  $log='Cargo '.$role['name'].' atribuído ao utilizador #'.$user['id'];
 }elseif($act==='member_reassign'){
  $userId=n('user_id',1);$roleId=n('role_id',1);
  $member=one($db,'SELECT m.user_id,r.role_key FROM members m LEFT JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=? AND m.user_id=?',[$cid,$userId]);
  $target=one($db,'SELECT * FROM company_roles WHERE company_id=? AND id=?',[$cid,$roleId]);
  if(!$member||!$target)throw new RuntimeException('Membro ou cargo não encontrado nesta empresa.');
  if(!$actorIsOwner){
   if(($member['role_key']??'')==='dono'||$target['role_key']==='dono')throw new RuntimeException('Só o dono pode alterar estes cargos.');
   foreach(json_decode((string)$target['permissions_json'],true)?:[] as $permission)if(!has_permission($db,$cid,(string)$permission))throw new RuntimeException('Não podes atribuir um cargo com permissões superiores às tuas.');
  }
  if(($member['role_key']??'')==='dono' && $target['role_key']!=='dono' && scalar($db,"SELECT COUNT(*) FROM members m JOIN company_roles r ON r.id=m.role_id WHERE m.company_id=? AND r.role_key='dono'",[$cid])<=1)throw new RuntimeException('O último dono da empresa não pode perder esse cargo.');
  $db->prepare('UPDATE members SET role_id=?,role=? WHERE company_id=? AND user_id=?')->execute([$roleId,'custom',$cid,$userId]);
  $logEntity='members';$success='Cargo do colaborador atualizado automaticamente.';$log='Colaborador #'.$userId.' atribuído a '.$target['name'];
 }elseif($act==='member_remove'){
  $userId=n('user_id',1);
  $member=one($db,'SELECT m.user_id,r.role_key FROM members m JOIN company_roles r ON r.id=m.role_id WHERE m.company_id=? AND m.user_id=?',[$cid,$userId]);
  if(!$member)throw new RuntimeException('Utilizador não encontrado na empresa.');
  if($member['role_key']==='dono' && !$actorIsOwner)throw new RuntimeException('Só outro dono pode remover um dono.');
  if($member['role_key']==='dono' && scalar($db,"SELECT COUNT(*) FROM members m JOIN company_roles r ON r.id=m.role_id WHERE m.company_id=? AND r.role_key='dono'",[$cid])<=1)
    throw new RuntimeException('Não podes remover o último dono da empresa.');
  $db->prepare('DELETE FROM members WHERE company_id=? AND user_id=?')->execute([$cid,$userId]);
  $logEntity='members';$logOp='DELETE';$success='Acesso removido.';$log='Acesso do utilizador #'.$userId.' removido';
 }else throw new RuntimeException('Ação desconhecida.');
 $db->prepare("INSERT INTO audit_logs(company_id,user_id,source,entity,entity_id,operation,details,created_at) VALUES(?,?,'Equipa',?,?,?,?,?)")
  ->execute([$cid,user_id(),$logEntity,$roleId??$userId??0,$logOp,mb_substr($log,0,300),now()]);
 $db->commit();if($g360Ajax){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'message'=>$success]);exit;}flash($success);
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($g360Ajax){http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);exit;}flash($e->getMessage(),'error');}
header('Location: index.php?page=settings#g360-sec-team',true,303);exit;
