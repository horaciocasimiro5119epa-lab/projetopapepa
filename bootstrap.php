<?php

declare(strict_types=1);

require_once __DIR__.'/app/config/env.php';
require_once __DIR__.'/app/view/assets.php';
require_once __DIR__.'/app/domain/catalog.php';
require_once __DIR__.'/app/domain/availability.php';
require_once __DIR__.'/app/domain/profit.php';
require_once __DIR__.'/app/domain/accounts.php';
require_once __DIR__.'/app/domain/auth_security.php';

// Compatibilidade com instalações locais onde mbstring está desativado.
// Para suporte completo a pesquisa Unicode, recomendamos ativar mbstring.
if(!function_exists('mb_strtolower')){
    function mb_strtolower(string $value, ?string $encoding=null):string{return strtolower($value);}
}
if(!function_exists('mb_strlen')){
    function mb_strlen(string $value, ?string $encoding=null):int{return strlen($value);}
}
if(!function_exists('mb_substr')){
    function mb_substr(string $value,int $start,?int $length=null,?string $encoding=null):string{
        return $length===null?substr($value,$start):substr($value,$start,$length);
    }
}

ini_set('display_errors','0');
ini_set('log_errors','1');
date_default_timezone_set('Europe/Lisbon');
if(session_status()!==PHP_SESSION_ACTIVE){
    ini_set('session.gc_maxlifetime',strval(7*86400));
    session_start([
        'cookie_httponly'=>true,
        'cookie_samesite'=>'Lax',
        'use_strict_mode'=>true
    ]);
}
$dir=__DIR__.'/storage';
if(!is_dir($dir)) mkdir($dir,0700,true);
$ugDriver=g360_env('DB_DRIVER','sqlite');
if(!in_array($ugDriver,['sqlite','mysql'],true))throw new RuntimeException('DB_DRIVER inválido.');
if(!extension_loaded('pdo_'.$ugDriver)){
    http_response_code(503);
    echo '<!doctype html><html lang="pt-PT"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configurar UniGest</title><link rel="stylesheet" href="assets/css/setup.css"><article><h1>✦ UniGest — configuração necessária</h1><p>A extensão PHP <code>pdo_sqlite</code> não está ativa. Sem ela, a aplicação não consegue criar a base de dados.</p><ol><li>No XAMPP, abre <strong>php.ini</strong> pelo painel do Apache.</li><li>Ativa a linha <code>extension=pdo_sqlite</code> (remove o ponto e vírgula inicial).</li><li>Reinicia o Apache e atualiza esta página.</li></ol><p>Verifica também o estado no <a href="diagnostico.php">diagnóstico de instalação</a>.</p></article></html>';
    exit;
}
if($ugDriver==='mysql'){
 require_once __DIR__.'/app/config/database.php';
 $db=new UgMysqlPDO('mysql:host='.g360_env('DB_HOST','127.0.0.1').';port='.g360_env('DB_PORT','3306').';dbname='.g360_env('DB_NAME','unigest').';charset=utf8mb4',g360_env('DB_USER','unigest'),g360_env('DB_PASSWORD'));
 $db->exec('SET @ug_offset='.$db->quote(date('P')));
}else $db=new PDO('sqlite:'.$dir.'/gestao.sqlite',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
$db->exec('PRAGMA journal_mode=WAL;');
$db->exec('CREATE TABLE IF NOT EXISTS auth_failed_attempts(id INTEGER PRIMARY KEY, fingerprint TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE INDEX IF NOT EXISTS ix_auth_failed_attempts ON auth_failed_attempts(fingerprint, created_at)');
// Autor da operação disponível aos triggers nesta ligação SQLite.
$db->sqliteCreateFunction('g360_actor',static fn()=>(int)($_SESSION['uid']??0),0);
$db->sqliteCreateFunction('g360_pttime',static fn():string=>date('Y-m-d H:i:s'),0);
$db->sqliteCreateFunction('g360_source',static function():string { $file=basename((string)($_SERVER['SCRIPT_NAME']??'')); return $file==='ai_execute.php'?'IA':($file==='floor_map.php'?'Mapa':'Aplicação'); },0);
$db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY,email TEXT NOT NULL UNIQUE,password TEXT NOT NULL,name TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS companies(id INTEGER PRIMARY KEY,name TEXT NOT NULL,nif TEXT DEFAULT '',created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS members(user_id INTEGER NOT NULL,company_id INTEGER NOT NULL,role TEXT NOT NULL DEFAULT 'admin',PRIMARY KEY(user_id,company_id),FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(company_id) REFERENCES companies(id));
CREATE TABLE IF NOT EXISTS products(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,name TEXT NOT NULL,sku TEXT NOT NULL DEFAULT '',category TEXT NOT NULL DEFAULT 'Geral',price_cents INTEGER NOT NULL DEFAULT 0,cost_cents INTEGER NOT NULL DEFAULT 0,qty INTEGER NOT NULL DEFAULT 0,min_qty INTEGER NOT NULL DEFAULT 2,active INTEGER DEFAULT 1,UNIQUE(company_id,sku));
CREATE TABLE IF NOT EXISTS customers(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,name TEXT NOT NULL,nif TEXT DEFAULT '',phone TEXT DEFAULT '',email TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS dining_tables(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,name TEXT NOT NULL,seats INTEGER DEFAULT 4,status TEXT DEFAULT 'livre');
CREATE TABLE IF NOT EXISTS reservations(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,table_id INTEGER,customer TEXT NOT NULL,guests INTEGER NOT NULL,start_at TEXT NOT NULL,notes TEXT DEFAULT '',status TEXT DEFAULT 'confirmada');
CREATE TABLE IF NOT EXISTS tills(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,user_id INTEGER NOT NULL,opened_at TEXT NOT NULL,opening_cents INTEGER NOT NULL,closed_at TEXT,closing_cents INTEGER,notes TEXT DEFAULT '');
CREATE TABLE IF NOT EXISTS cash_movements(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,till_id INTEGER NOT NULL,user_id INTEGER NOT NULL,type TEXT NOT NULL,amount_cents INTEGER NOT NULL,reason TEXT NOT NULL,created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,table_id INTEGER,customer_id INTEGER,origin TEXT NOT NULL,status TEXT DEFAULT 'aberto',created_at TEXT NOT NULL,closed_at TEXT,subtotal_cents INTEGER DEFAULT 0,total_cents INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS order_items(id INTEGER PRIMARY KEY,order_id INTEGER NOT NULL,product_id INTEGER NOT NULL,name TEXT NOT NULL,qty INTEGER NOT NULL,price_cents INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS payments(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,order_id INTEGER NOT NULL,till_id INTEGER NOT NULL,method TEXT NOT NULL,amount_cents INTEGER NOT NULL,created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS stock_moves(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,product_id INTEGER NOT NULL,delta INTEGER NOT NULL,reason TEXT NOT NULL,user_id INTEGER NOT NULL,created_at TEXT NOT NULL);
CREATE INDEX IF NOT EXISTS idx_orders_company_time ON orders(company_id,created_at);
CREATE INDEX IF NOT EXISTS idx_payments_company_time ON payments(company_id,created_at);
CREATE INDEX IF NOT EXISTS idx_reservations_company_time ON reservations(company_id,start_at);");

// Migração automática V11 sem apagar produtos existentes.
if(!in_array('image_path',array_column($db->query('PRAGMA table_info(products)')->fetchAll(),'name'),true)) $db->exec("ALTER TABLE products ADD COLUMN image_path TEXT NOT NULL DEFAULT ''");
// V22: snapshot do custo no momento da venda. Antigos pedidos ficam sem custo conhecido.
if(!in_array('cost_cents',array_column($db->query('PRAGMA table_info(order_items)')->fetchAll(),'name'),true)) $db->exec('ALTER TABLE order_items ADD COLUMN cost_cents INTEGER DEFAULT NULL');
// V25: estado de serviço por linha, pré-pagamento e menus públicos por empresa.
foreach (['payment_mode'=>"TEXT NOT NULL DEFAULT 'posterior'"] as $column=>$definition) {
 if(!in_array($column,array_column($db->query('PRAGMA table_info(orders)')->fetchAll(),'name'),true))$db->exec("ALTER TABLE orders ADD COLUMN $column $definition");
}
if(!in_array('service_status',array_column($db->query('PRAGMA table_info(order_items)')->fetchAll(),'name'),true))$db->exec("ALTER TABLE order_items ADD COLUMN service_status TEXT NOT NULL DEFAULT 'aguarda_pagamento'");
if(!in_array('menu_slug',array_column($db->query('PRAGMA table_info(companies)')->fetchAll(),'name'),true))$db->exec('ALTER TABLE companies ADD COLUMN menu_slug TEXT DEFAULT NULL');
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS unigest_menu_slug_unique ON companies(menu_slug) WHERE menu_slug IS NOT NULL');
$db->exec("CREATE TABLE IF NOT EXISTS public_menu_access(
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,table_id INTEGER NOT NULL,
 secret_hash TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pendente',
 created_at TEXT NOT NULL,expires_at TEXT NOT NULL,
 UNIQUE(company_id,secret_hash))");
$db->exec('CREATE INDEX IF NOT EXISTS menu_access_company ON public_menu_access(company_id,status,created_at)');
$db->exec("CREATE TABLE IF NOT EXISTS public_menu_requests(
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL,access_id INTEGER NOT NULL,
 product_id INTEGER NOT NULL,qty INTEGER NOT NULL,status TEXT NOT NULL DEFAULT 'pendente',
 created_at TEXT NOT NULL)");
$db->exec('CREATE INDEX IF NOT EXISTS menu_requests_company ON public_menu_requests(company_id,status,created_at)');
$db->exec('CREATE INDEX IF NOT EXISTS ug_menu_requests_by_access ON public_menu_requests(company_id,access_id,id DESC)');
// V13: planta personalizada por empresa (coordenadas relativas de 0 a 1000).
$mapCols=array_column($db->query('PRAGMA table_info(dining_tables)')->fetchAll(), 'name');
if(!in_array('map_x',$mapCols,true)) $db->exec('ALTER TABLE dining_tables ADD COLUMN map_x INTEGER NOT NULL DEFAULT -1');
if(!in_array('map_y',$mapCols,true)) $db->exec('ALTER TABLE dining_tables ADD COLUMN map_y INTEGER NOT NULL DEFAULT -1');
if(!in_array('map_shape',$mapCols,true)) $db->exec("ALTER TABLE dining_tables ADD COLUMN map_shape TEXT NOT NULL DEFAULT 'round'");
// V15: cada mesa tem largura/altura independentes; gravações do mapa são versionadas.
$mapCols=array_column($db->query('PRAGMA table_info(dining_tables)')->fetchAll(), 'name');
$addTableWidth=!in_array('map_width',$mapCols,true);
if($addTableWidth) $db->exec('ALTER TABLE dining_tables ADD COLUMN map_width INTEGER NOT NULL DEFAULT 96');
if(!in_array('map_height',$mapCols,true)) $db->exec('ALTER TABLE dining_tables ADD COLUMN map_height INTEGER NOT NULL DEFAULT 96');
// Migra apenas uma vez as mesas retangulares criadas antes da V15.
if($addTableWidth) $db->exec("UPDATE dining_tables SET map_width=130 WHERE map_shape='rect' AND map_width=96 AND map_height=96");
$db->exec("CREATE TABLE IF NOT EXISTS floor_objects(
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, kind TEXT NOT NULL,
 label TEXT NOT NULL DEFAULT '', x INTEGER NOT NULL DEFAULT 100, y INTEGER NOT NULL DEFAULT 100,
 width INTEGER NOT NULL DEFAULT 160, height INTEGER NOT NULL DEFAULT 60
)");
$db->exec('CREATE TABLE IF NOT EXISTS floor_settings(company_id INTEGER PRIMARY KEY, width INTEGER NOT NULL DEFAULT 1000, height INTEGER NOT NULL DEFAULT 1000)');
$floorCols=array_column($db->query('PRAGMA table_info(floor_settings)')->fetchAll(), 'name');
if(!in_array('revision',$floorCols,true)) $db->exec('ALTER TABLE floor_settings ADD COLUMN revision INTEGER NOT NULL DEFAULT 0');
function product_image(array $p):string {
 $path=(string)($p['image_path']??'');
 if(preg_match('~^uploads/products/[a-f0-9]{32}\.(?:jpg|png|webp)$~',$path))return $path;
 foreach(g360_suggested_products() as $suggested)if($path===$suggested['image'])return $path;
 return g360_catalog_guess_image((string)($p['name']??'')) ?? 'assets/img/product-placeholder.svg';
}
function save_product_image():?string {
 if(!isset($_FILES['image'])||($_FILES['image']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
 $f=$_FILES['image'];
 if($f['error']!==UPLOAD_ERR_OK||$f['size']>3145728||$f['size']<1)throw new RuntimeException('Imagem inválida (máximo 3 MB).');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
 $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
 if(!$ext||!getimagesize($f['tmp_name']))throw new RuntimeException('Usa uma imagem JPG, PNG ou WebP.');
 $dir=__DIR__.'/uploads/products';
 if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir))throw new RuntimeException('Não foi possível criar a pasta de imagens.');
 $name=bin2hex(random_bytes(16)).'.'.$ext;
 if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Não foi possível guardar a fotografia.');
 return 'uploads/products/'.$name;
}
// Migração simples para bases já existentes da V2.
$productCols=array_column($db->query('PRAGMA table_info(products)')->fetchAll(),'name');
if(!in_array('category',$productCols,true)){
    $db->exec("ALTER TABLE products ADD COLUMN category TEXT NOT NULL DEFAULT 'Geral'");
}

function h($x):string{return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function token():string{return $_SESSION['csrf']??=bin2hex(random_bytes(24));}
function check_token():void{if(!hash_equals(token(),(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Atualiza a página.');}
function cents($v):int{
    $s=str_replace(',','.',trim((string)$v));
    if(!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/',$s)) throw new RuntimeException('Montante inválido.');
    return (int) round((float)$s*100);
}
function money($v):string{return number_format(((int)$v)/100,2,',',' ').' €';}
function req($key):string{return trim((string)($_POST[$key]??''));}
function n($key,$min=0,$max=1000000):int{$v=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT);if($v===false||$v<$min||$v>$max)throw new RuntimeException('Número inválido: '.$key);return $v;}
function back($page='dashboard',$extra=''):never{header('Location: index.php?page='.rawurlencode($page).($extra?('&'.$extra):''));exit;}
function flash($text,$type='ok'):void{$_SESSION['flash']=['text'=>$text,'type'=>$type];}
function user_id():int{return (int)($_SESSION['uid']??0);}
function company_id():int{return (int)($_SESSION['cid']??0);}
function must_login():void{if(!user_id()){header('Location: index.php',true,302);exit;}}
function member(PDO $db,int $cid):bool{$s=$db->prepare('SELECT 1 FROM members WHERE user_id=? AND company_id=?');$s->execute([user_id(),$cid]);return (bool)$s->fetchColumn();}
function must_company(PDO $db):void{must_login();if(!company_id()||!member($db,company_id())){unset($_SESSION['cid']);header('Location: companies.php');exit;}}
function one(PDO $db,string $sql,array $values=[]):?array{$s=$db->prepare($sql);$s->execute($values);return $s->fetch()?:null;}
function all(PDO $db,string $sql,array $values=[]):array{$s=$db->prepare($sql);$s->execute($values);return $s->fetchAll();}
function scalar(PDO $db,string $sql,array $values=[]):int{$s=$db->prepare($sql);$s->execute($values);return (int)$s->fetchColumn();}
function till(PDO $db,int $cid):?array{return one($db,'SELECT * FROM tills WHERE company_id=? AND closed_at IS NULL ORDER BY id DESC LIMIT 1',[$cid]);}
$db->exec('CREATE TABLE IF NOT EXISTS ai_product_history(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,user_id INTEGER NOT NULL,product_id INTEGER NOT NULL,before_json TEXT,after_json TEXT,kind TEXT NOT NULL,created_at TEXT NOT NULL,undone_at TEXT)');
function scoped(PDO $db,string $table,int $id,int $cid):array{$allowed=['products','customers','dining_tables','orders','reservations'];if(!in_array($table,$allowed,true))throw new RuntimeException('Recurso inválido.');$r=one($db,"SELECT * FROM $table WHERE id=? AND company_id=?",[$id,$cid]);if(!$r)throw new RuntimeException('Registo não encontrado nesta empresa.');return $r;}
function now():string{return date('Y-m-d H:i:s');}
/** Obtém uma fotografia verificável do pedido, sempre dentro da empresa ativa. */
function order_copy_snapshot(PDO $db,int $cid,int $orderId):array {
    $order=scoped($db,'orders',$orderId,$cid);
    if($order['status']==='cancelado') throw new RuntimeException('Não é permitido duplicar pedidos cancelados.');
    $lines=all($db,'SELECT product_id,name,qty,price_cents FROM order_items WHERE order_id=? ORDER BY id',[$orderId]);
    if(!$lines) throw new RuntimeException('O pedido #'.$orderId.' não contém artigos para duplicar.');
    if(count($lines)>100) throw new RuntimeException('O pedido tem demasiadas linhas para duplicar de uma vez.');
    $total=0;
    foreach($lines as &$line){
        $line=['product_id'=>(int)$line['product_id'],'name'=>(string)$line['name'],'qty'=>(int)$line['qty'],'price_cents'=>(int)$line['price_cents']];
        if($line['qty']<1 || $line['qty']>1000 || $line['price_cents']<0) throw new RuntimeException('O pedido tem quantidades ou preços inválidos.');
        $total += $line['qty'] * $line['price_cents'];
        if($total>1000000000) throw new RuntimeException('Total do pedido demasiado elevado.');
    }
    unset($line);
    $snapshot=['id'=>(int)$order['id'],'table_id'=>$order['table_id']===null?null:(int)$order['table_id'],
        'status'=>(string)$order['status'],'total_cents'=>$total,'items'=>$lines];
    $snapshot['fingerprint']=hash('sha256',json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    return $snapshot;
}

function validate_reservation(PDO $db,int $cid,int $tableId,string $when,int $guests):array{
    $table=scoped($db,'dining_tables',$tableId,$cid);
    if($guests<1 || $guests>(int)$table['seats']) throw new RuntimeException('A mesa não tem capacidade para o número de pessoas indicado.');
    if(!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/',$when)) throw new RuntimeException('Data e hora inválidas.');
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$when);
    if(!$dt || $dt->format('Y-m-d H:i')!==$when) throw new RuntimeException('Data e hora inválidas.');
    require_once __DIR__.'/app/domain/company_features.php';
    $buffer=max(0,(int)g360_preferences($db,$cid)['booking_duration']-1);
    $conflict=scalar($db,"SELECT COUNT(*) FROM reservations WHERE company_id=? AND table_id=? AND status IN ('confirmada','chegou') AND datetime(start_at) BETWEEN datetime(?,'-'||?||' minutes') AND datetime(?,'+'||?||' minutes')",[$cid,$tableId,$when,$buffer,$when,$buffer]);
    if($conflict) throw new RuntimeException('Já existe uma reserva nesta mesa, muito próxima dessa hora.');
    return $table;
}

function category_class(string $category):string{
    $slug=preg_replace('/[^a-z0-9]+/i','-',strtolower($category));
    return trim($slug?:'geral','-');
}

// Auditoria automática das alterações a dados de cada empresa. Não regista palavras-passe,
// chaves API nem o conteúdo integral de dados pessoais.
$db->exec("CREATE TABLE IF NOT EXISTS audit_logs(
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 company_id INTEGER NOT NULL,
 user_id INTEGER NOT NULL DEFAULT 0,
 source TEXT NOT NULL DEFAULT 'sistema',
 entity TEXT NOT NULL,
 entity_id INTEGER NOT NULL,
 operation TEXT NOT NULL,
 details TEXT NOT NULL DEFAULT '',
 created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
)");
$db->exec('CREATE INDEX IF NOT EXISTS idx_audit_company_time ON audit_logs(company_id,id DESC)');
// Migração V20: a hora dos novos eventos vem sempre de PHP/Europe-Lisbon,
// não do fuso horário do sistema operativo (pode ser UTC em servidores).
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)');
// V28: correção única dos estados herdados da V25. Não voltar a varrer os pedidos a cada pedido HTTP.
if(!(int)scalar($db,"SELECT COUNT(*) FROM schema_migrations WHERE name='v28_payment_mode_status'")){
    $db->beginTransaction();
    try{
        $db->exec("UPDATE order_items SET service_status=(SELECT CASE WHEN o.payment_mode='antecipado' THEN 'aguarda_pagamento' ELSE 'aguarda_servico' END FROM orders o WHERE o.id=order_items.order_id) WHERE service_status!='feito' AND EXISTS (SELECT 1 FROM orders o WHERE o.id=order_items.order_id AND o.status='aberto')");
        $db->prepare('INSERT OR IGNORE INTO schema_migrations(name,applied_at) VALUES(?,?)')->execute(['v28_payment_mode_status',date('Y-m-d H:i:s')]);
        $db->commit();
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

// V23: URLs públicos por empresa, contactos de reserva e limitação de abuso.
$db->exec("CREATE TABLE IF NOT EXISTS public_booking_attempts(id INTEGER PRIMARY KEY,company_id INTEGER NOT NULL,ip_hash TEXT NOT NULL,created_at TEXT NOT NULL)");
foreach(['companies'=>['booking_slug'=>'TEXT'], 'reservations'=>['contact_phone'=>"TEXT DEFAULT ''",'contact_email'=>"TEXT DEFAULT ''"]] as $tbl=>$columns){
    $present=array_column($db->query('PRAGMA table_info('.$tbl.')')->fetchAll(PDO::FETCH_ASSOC),'name');
    foreach($columns as $column=>$type)if(!in_array($column,$present,true))$db->exec('ALTER TABLE '.$tbl.' ADD COLUMN '.$column.' '.$type);
}
$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_public_booking_slug ON companies(booking_slug) WHERE booking_slug IS NOT NULL');

if(!$db->query("SELECT 1 FROM schema_migrations WHERE name='audit-clock-pt-v20' LIMIT 1")->fetchColumn()){
    foreach(['products','customers','dining_tables','reservations','tills','cash_movements','orders','payments','stock_moves','floor_objects','floor_settings','ai_product_history','order_items'] as $eventEntity){
        foreach(['insert','update','delete'] as $eventKind){
            $db->exec('DROP TRIGGER IF EXISTS audit_'.$eventEntity.'_'.$eventKind);
        }
    }
    $db->prepare('INSERT OR IGNORE INTO schema_migrations(name,applied_at) VALUES(?,?)')
       ->execute(['audit-clock-pt-v20',date('Y-m-d H:i:s')]);
}
// Instala apenas triggers que ainda não existam. Registos são criados na mesma transação
// que a alteração original, incluindo as operações feitas pela IA e pelo mapa.
foreach(['products','customers','dining_tables','reservations','tills','cash_movements','orders','payments','stock_moves','floor_objects','floor_settings','ai_product_history'] as $entity){
    foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $event=>$alias){
        $trigger='audit_'.$entity.'_'.strtolower($event);
        $entityId=$entity==='floor_settings'?'company_id':'id';
        $detail=match($entity){
            'products' => $event==='UPDATE' ? "'Stock: ' || OLD.qty || ' → ' || NEW.qty || '; nome: ' || OLD.name || ' → ' || NEW.name" : "'Produto: ' || COALESCE($alias.name,'')",
            'dining_tables' => "'Mesa: ' || COALESCE($alias.name,'')",
            'stock_moves' => "'Variação: ' || $alias.delta || '; motivo: ' || COALESCE($alias.reason,'')",
            'payments' => "'Pagamento: ' || $alias.amount_cents || ' cêntimos; método: ' || $alias.method",
            'cash_movements' => "'Movimento: ' || $alias.type || ' ' || $alias.amount_cents || ' cêntimos'",
            'orders' => "'Pedido; estado: ' || $alias.status",
            'reservations' => "'Reserva; estado: ' || $alias.status",
            'floor_settings' => "'Planta ' || $alias.width || ' × ' || $alias.height",
            'floor_objects' => "'Elemento: ' || $alias.kind",
            default => "'Alteração registada'",
        };
        $db->exec("CREATE TRIGGER IF NOT EXISTS $trigger AFTER $event ON $entity BEGIN
            INSERT INTO audit_logs(company_id,user_id,source,entity,entity_id,operation,details,created_at)
            VALUES($alias.company_id,g360_actor(),g360_source(),'$entity',$alias.$entityId,'$event',SUBSTR($detail,1,300),g360_pttime());
        END");
    }
}

// Linhas dos pedidos pertencem à empresa do pedido.
foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as $event=>$alias){
    $trigger='audit_order_items_'.strtolower($event);
    $db->exec("CREATE TRIGGER IF NOT EXISTS $trigger AFTER $event ON order_items BEGIN
        INSERT INTO audit_logs(company_id,user_id,source,entity,entity_id,operation,details,created_at)
        VALUES((SELECT company_id FROM orders WHERE id=$alias.order_id),g360_actor(),g360_source(),'order_items',$alias.id,'$event',
          'Produto: ' || COALESCE($alias.name,'') || '; quantidade: ' || $alias.qty,g360_pttime());
    END");
}

require_once __DIR__.'/roles.php';
