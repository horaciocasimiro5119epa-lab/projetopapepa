<?php
/**
 * UniGest V28 · Heartbeat privado, sem migrations nem criação de triggers.
 * Apenas leituras curtas no motor configurado, com sessão read-only.
 */
declare(strict_types=1);
ini_set('display_errors','0');
date_default_timezone_set('Europe/Lisbon');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){
    http_response_code(405);echo '{"error":"Método não permitido."}';exit;
}
if(session_status()!==PHP_SESSION_ACTIVE){
    session_start([
        'cookie_httponly'=>true,
        'cookie_samesite'=>'Lax',
        'use_strict_mode'=>true,
        'read_and_close'=>true,
    ]);
}
$userId=(int)($_SESSION['uid']??0);
$companyId=(int)($_SESSION['cid']??0);
if($userId<=0 || $companyId<=0){
    http_response_code(401);echo '{"session_valid":false,"error":"É necessário iniciar sessão."}';exit;
}
require_once __DIR__.'/app/config/env.php';
$ugDriver=g360_env('DB_DRIVER','sqlite');
if($ugDriver!=='mysql' && (!extension_loaded('pdo_sqlite') || !is_file(__DIR__.'/storage/gestao.sqlite'))){
    http_response_code(503);echo '{"error":"A base de dados não está disponível."}';exit;
}
try{
    if($ugDriver==='mysql'){
        require_once __DIR__.'/app/config/database.php';
        $db=new UgMysqlPDO('mysql:host='.g360_env('DB_HOST','127.0.0.1').';port='.g360_env('DB_PORT','3306').';dbname='.g360_env('DB_NAME','unigest').';charset=utf8mb4',g360_env('DB_USER','unigest'),g360_env('DB_PASSWORD'));
    }else $db=new PDO('sqlite:'.__DIR__.'/storage/gestao.sqlite',null,null,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT=>2,
    ]);
    $check=$db->prepare('SELECT r.role_key,r.permissions_json FROM members m LEFT JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.user_id=? AND m.company_id=? LIMIT 1');
    $check->execute([$userId,$companyId]);
    $role=$check->fetch();
    if(!$role){http_response_code(401);echo '{"session_valid":false,"error":"Acesso à empresa indisponível."}';exit;}
    $permissions=json_decode((string)($role['permissions_json']??'[]'),true);
    $allowOrders=($role['role_key']??'')==='dono' || (is_array($permissions) && in_array('orders.manage',$permissions,true));
    $statement=$db->prepare('SELECT COALESCE(MAX(id),0) FROM audit_logs WHERE company_id=?');
    $statement->execute([$companyId]);
    $revision=(int)$statement->fetchColumn();
    $openOrders=null;
    if($allowOrders){
        $statement=$db->prepare("SELECT COUNT(*) FROM orders WHERE company_id=? AND status='aberto'");
        $statement->execute([$companyId]);
        $openOrders=(int)$statement->fetchColumn();
    }
    $previous=max(0,(int)($_GET['revision']??0));
    echo json_encode([
        'session_valid'=>true,
        'revision'=>$revision,
        'changed'=>$revision!==$previous,
        'open_orders'=>$openOrders,
        'checked_at'=>date('H:i:s'),
    ],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    http_response_code(503);
    echo '{"error":"Não foi possível verificar as atualizações."}';
}
