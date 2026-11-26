<?php
/** UniGest V55 — envio seguro de banners por empresa, sem guardar ficheiros executáveis. */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/app/domain/company_features.php';
require_once __DIR__.'/app/domain/public_branding.php';
must_company($db);
$cid=company_id();
require_permission($db,$cid,'company.manage');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
$json=str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT']??'')),'application/json');
$ok=false;$error='';$url='';$kind=(string)($_POST['kind']??'');$newFile='';$http=200;
try{
    check_token();
    require_once __DIR__.'/app/domain/subscriptions.php';
    ug_subscription_assert_write($db,$cid);
    if(!in_array($kind,['menu','booking'],true))throw new RuntimeException('Tipo de banner inválido.');
    if(!g360_feature($db,$cid,$kind==='menu'?'menu_enabled':'booking_enabled'))throw new RuntimeException('Módulo desativado para a empresa.');
    $action=(string)($_POST['action']??'upload');
    if(!in_array($action,['upload','remove'],true))throw new RuntimeException('Ação inválida.');
    if($action==='upload'){
        $file=$_FILES['banner']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Seleciona uma imagem válida até 4 MB.');
        if(!is_uploaded_file((string)$file['tmp_name'])||$file['size']<1||$file['size']>4*1024*1024)throw new RuntimeException('O ficheiro tem de ser uma imagem até 4 MB.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
        if(!$ext)throw new RuntimeException('Formato não suportado. Utiliza JPG, PNG ou WebP.');
        $dimensions=@getimagesize($file['tmp_name']);
        if(!$dimensions||$dimensions['mime']!==$mime||$dimensions[0]<800||$dimensions[1]<300||$dimensions[0]>7000||$dimensions[1]>7000||$dimensions[0]*$dimensions[1]>30000000)throw new RuntimeException('O banner deve ter pelo menos 800 × 300 píxeis e dimensões equilibradas.');
        $relativeDir='uploads/banners/'.$cid;
        $absoluteDir=__DIR__.'/'.$relativeDir;
        if(!is_dir($absoluteDir)&&!mkdir($absoluteDir,0755,true)&&!is_dir($absoluteDir))throw new RuntimeException('Não foi possível criar a pasta dos banners.');
        $newFile=$relativeDir.'/'.bin2hex(random_bytes(16)).'.'.$ext;
        if(!move_uploaded_file($file['tmp_name'],__DIR__.'/'.$newFile))throw new RuntimeException('Não foi possível carregar o banner.');
        chmod(__DIR__.'/'.$newFile,0644);
    }
    $db->beginTransaction();
    $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
    $stmt=$db->prepare('SELECT preferences_json FROM company_preferences WHERE company_id=?'.$lock);
    $stmt->execute([$cid]);
    $old=json_decode((string)($stmt->fetchColumn()?:'{}'),true);
    if(!is_array($old))$old=[];
    $previous=(string)($old[$kind.'_banner_path']??'');
    if($action==='upload'){
        $old[$kind.'_banner_path']=$newFile;
        $old[$kind.'_banner_mode']='custom';
        $url=$newFile;
    }else{
        $old[$kind.'_banner_path']='';
        $old[$kind.'_banner_mode']='preset';
    }
    $db->prepare('INSERT INTO company_preferences(company_id,preferences_json) VALUES(?,?) ON CONFLICT(company_id) DO UPDATE SET preferences_json=excluded.preferences_json')->execute([$cid,json_encode($old,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    $db->commit();
    // Só apagar imagens locais deste estabelecimento, nunca caminhos fornecidos pelo cliente.
    if($previous!==$newFile && preg_match('~^uploads/banners/'.preg_quote((string)$cid,'~').'/[a-f0-9]{32}\.(?:jpg|png|webp)$~D',$previous) && is_file(__DIR__.'/'.$previous))@unlink(__DIR__.'/'.$previous);
    $ok=true;
}catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    if($newFile!==''&&is_file(__DIR__.'/'.$newFile))@unlink(__DIR__.'/'.$newFile);
    $http=422;$error=$e->getMessage();
}
if($json){
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($http);
    echo json_encode(['ok'=>$ok,'message'=>$ok?'Banner atualizado com sucesso.':$error,'url'=>$ok?$url:null],JSON_UNESCAPED_UNICODE);
    exit;
}
flash($ok?'Banner atualizado com sucesso.':$error,$ok?'ok':'error');
header('Location: index.php?page=settings#g360-sec-'.($kind==='menu'?'menu':'booking'),true,303);
exit;
