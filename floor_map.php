<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try {
    must_company($db);
    require_permission($db,company_id(),'tables.layout');
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Método não permitido.');
    check_token();require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_write($db,company_id());
    $tables=json_decode((string)($_POST['tables']??''),true);
    $pieces=json_decode((string)($_POST['pieces']??''),true);
    if(!is_array($tables)||!array_is_list($tables)||count($tables)>200||!is_array($pieces)||!array_is_list($pieces)||count($pieces)>100){
        throw new RuntimeException('Mapa inválido.');
    }
    $cid=company_id();require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'tables_enabled')){http_response_code(403);exit('Funcionalidade desativada nesta empresa.');}
    $width=filter_var($_POST['width']??null,FILTER_VALIDATE_INT);
    $height=filter_var($_POST['height']??null,FILTER_VALIDATE_INT);
    $revision=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
    if($width===false||$height===false||$width<500||$width>4000||$height<500||$height>4000) throw new RuntimeException('Dimensões da planta inválidas (500–4000).');
    if($revision===false||$revision<0) throw new RuntimeException('Versão do mapa inválida. Atualiza a página.');
    $db->beginTransaction();
    $db->prepare('INSERT OR IGNORE INTO floor_settings(company_id,width,height,revision) VALUES(?,?,?,0)')->execute([$cid,$width,$height]);
    $changed=$db->prepare('UPDATE floor_settings SET width=?, height=?, revision=revision+1 WHERE company_id=? AND revision=?');
    $changed->execute([$width,$height,$cid,$revision]);
    if(!$changed->rowCount()) throw new RuntimeException('Conflito de edição: a planta foi alterada noutra janela. Atualiza a página antes de continuares.');
    $seen=[];
    $update=$db->prepare('UPDATE dining_tables SET map_x=?,map_y=?,map_shape=?,map_width=?,map_height=? WHERE company_id=? AND id=?');
    foreach($tables as $t){
        if(!is_array($t)) throw new RuntimeException('Mesa inválida.');
        $id=filter_var($t['id']??null,FILTER_VALIDATE_INT);
        $x=filter_var($t['x']??null,FILTER_VALIDATE_INT);
        $y=filter_var($t['y']??null,FILTER_VALIDATE_INT);
        $w=filter_var($t['width']??null,FILTER_VALIDATE_INT);
        $h=filter_var($t['height']??null,FILTER_VALIDATE_INT);
        $shape=$t['shape']??'';
        if($id===false||$id<1||isset($seen[$id])||$x===false||$y===false||$w===false||$h===false||
           $w<48||$w>$width||$h<48||$h>$height||$x<0||$y<0||$x+$w>$width||$y+$h>$height||
           !in_array($shape,['round','square','rect'],true)) throw new RuntimeException('Posição ou tamanho de mesa inválidos.');
        scoped($db,'dining_tables',$id,$cid);
        $seen[$id]=true;
        $update->execute([$x,$y,$shape,$w,$h,$cid,$id]);
    }
    $validated=[];
    $defaults=['wall'=>[180,16],'door'=>[100,25],'counter'=>[170,80],'kitchen'=>[200,110],'window'=>[120,20],'label'=>[155,48]];
    foreach($pieces as $o){
        if(!is_array($o)||!isset($defaults[$o['kind']??''])) throw new RuntimeException('Elemento de planta inválido.');
        $kind=$o['kind'];$label=trim((string)($o['label']??''));
        $x=filter_var($o['x']??null,FILTER_VALIDATE_INT);
        $y=filter_var($o['y']??null,FILTER_VALIDATE_INT);
        $w=filter_var($o['width']??null,FILTER_VALIDATE_INT);
        $h=filter_var($o['height']??null,FILTER_VALIDATE_INT);
        $minW=($kind==='wall'||$kind==='window')?20:40;
        $minH=($kind==='wall'||$kind==='window')?8:20;
        if(mb_strlen($label)>60||$x===false||$y===false||$w===false||$h===false||
           $w<$minW||$h<$minH||$x<0||$y<0||$x+$w>$width||$y+$h>$height) throw new RuntimeException('Posição ou tamanho de elemento inválidos.');
        $validated[]=[$cid,$kind,$label,$x,$y,$w,$h];
    }
    // Atualização atómica: substitui elementos apenas depois de validar todo o lote.
    $db->prepare('DELETE FROM floor_objects WHERE company_id=?')->execute([$cid]);
    $add=$db->prepare('INSERT INTO floor_objects(company_id,kind,label,x,y,width,height) VALUES(?,?,?,?,?,?,?)');
    foreach($validated as $data) $add->execute($data);
    $db->commit();
    echo json_encode(['reply'=>'Planta guardada automaticamente.','tables'=>count($tables),'objects'=>count($pieces),'revision'=>$revision+1],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e){
    if(isset($db)&&$db->inTransaction()) $db->rollBack();
    $msg=$e->getMessage();
    http_response_code(str_starts_with($msg,'Conflito de edição:')?409:400);
    echo json_encode(['error'=>$msg],JSON_UNESCAPED_UNICODE);
}
