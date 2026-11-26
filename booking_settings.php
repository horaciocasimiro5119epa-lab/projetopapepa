<?php
/** UniGest V30.1 — endereço público: resposta JSON e redirecionamento seguros. */
require __DIR__.'/bootstrap.php';
must_company($db);
$cid=company_id();
require_permission($db,$cid,'company.manage');
require_once __DIR__.'/app/domain/company_features.php';
if(!g360_feature($db,$cid,'booking_enabled')){http_response_code(403);exit('Funcionalidade desativada para esta empresa.');}
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$isJson=str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');
$slug='';$ok=false;$status=200;$message='';
try{
    check_token();require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_write($db,$cid);
    $slug=strtolower(trim((string)($_POST['booking_slug']??'')));
    if(!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,59}[a-z0-9])$/D',$slug)){
        $status=422;
        throw new RuntimeException('Usa entre 3 e 61 caracteres: letras minúsculas, números ou hífen, sem hífen no início ou no fim.');
    }
    $other=one($db,'SELECT id FROM companies WHERE booking_slug=? AND id<>?',[$slug,$cid]);
    if($other){$status=409;throw new RuntimeException('Este endereço já pertence a outra empresa. Escolhe um diferente.');}
    $db->prepare('UPDATE companies SET booking_slug=? WHERE id=?')->execute([$slug,$cid]);
    $ok=true;
    $message='Endereço das reservas guardado com sucesso.';
}catch(PDOException $e){
    error_log('UniGest: não foi possível guardar o endereço público: '.$e->getMessage());
    $status=409;
    $message='Não foi possível guardar este endereço. Confirma que não está a ser utilizado por outra empresa.';
}catch(Throwable $e){
    if($status===200)$status=422;
    $message=$e->getMessage();
}
if($isJson){
    http_response_code($ok?200:$status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok'=>$ok,'message'=>$message,'slug'=>$ok?$slug:null],JSON_UNESCAPED_UNICODE);
    exit;
}
flash($message,$ok?'ok':'error');
header('Location: index.php?page=settings#g360-sec-booking',true,303);
exit;
