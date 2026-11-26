<?php
/** UniGest V18 — permissões efetivas por empresa; verificar sempre no servidor. */
function permission_catalog():array {
 return [
  'dashboard.view'=>'Visão geral', 'pos.use'=>'Usar POS / registadora',
  'orders.manage'=>'Criar e alterar pedidos', 'tables.view'=>'Consultar mesas e planta',
  'tables.manage'=>'Criar e editar mesas', 'tables.layout'=>'Construir e redimensionar a planta', 'reservations.manage'=>'Gerir e cancelar reservas',
  'products.view'=>'Consultar catálogo', 'products.manage'=>'Criar e editar produtos',
  'stock.manage'=>'Alterar stock', 'customers.manage'=>'Gerir clientes',
  'cash.manage'=>'Abrir, movimentar e fechar caixa', 'reports.view'=>'Consultar relatórios', 'profits.view'=>'Consultar margens e rentabilidade',
  'exports.use'=>'Exportar dados', 'fiscal.use'=>'Faturar através de fornecedor certificado', 'fiscal.configure'=>'Configurar integração fiscal', 'ai.use'=>'Utilizar IA', 'logs.view'=>'Consultar registos',
  'company.manage'=>'Editar nome e NIF', 'roles.manage'=>'Gerir cargos e membros'
 ];
}
function default_role_permissions(string $key):array {
 $all=array_keys(permission_catalog());
 if($key==='dono') return $all;
 if($key==='gerente') return array_values(array_diff($all,['company.manage','roles.manage','fiscal.configure','tables.layout','profits.view']));
 return ['dashboard.view','pos.use','orders.manage','tables.view','reservations.manage','products.view','customers.manage'];
}
$db->exec("CREATE TABLE IF NOT EXISTS company_roles(
 id INTEGER PRIMARY KEY, company_id INTEGER NOT NULL, role_key TEXT NOT NULL,
 name TEXT NOT NULL, permissions_json TEXT NOT NULL DEFAULT '[]', builtin INTEGER NOT NULL DEFAULT 0,
 UNIQUE(company_id,role_key), FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE
)");
$memberFields=array_column($db->query('PRAGMA table_info(members)')->fetchAll(),'name');
if(!in_array('role_id',$memberFields,true)) $db->exec('ALTER TABLE members ADD COLUMN role_id INTEGER REFERENCES company_roles(id)');
function ensure_company_roles(PDO $db,int $company):void {
 // Os cargos Gerente e Funcionário são modelos iniciais, não obrigatórios.
 // Só são inseridos quando a empresa ainda não tem NENHUM cargo. Assim,
 // eliminar um modelo não o faz reaparecer após reiniciar a aplicação.
 $existing=(int)scalar($db,'SELECT COUNT(*) FROM company_roles WHERE company_id=?',[$company]);
 $seed=$existing===0?['dono'=>'Dono','gerente'=>'Gerente','funcionario'=>'Funcionário']:['dono'=>'Dono'];
 foreach($seed as $key=>$name){
  $db->prepare('INSERT OR IGNORE INTO company_roles(company_id,role_key,name,permissions_json,builtin) VALUES(?,?,?,?,1)')
     ->execute([$company,$key,$name,json_encode(default_role_permissions($key),JSON_THROW_ON_ERROR)]);
 }
 $rows=all($db,'SELECT user_id,role,role_id FROM members WHERE company_id=? ORDER BY user_id',[$company]);
 $ownerExists=(bool)one($db,"SELECT 1 FROM members m INNER JOIN company_roles r ON r.id=m.role_id WHERE m.company_id=? AND r.role_key='dono' LIMIT 1",[$company]);
 $ownerId=(int)($rows[0]['user_id']??0);
 foreach($rows as $row){
  if(!empty($row['role_id']))continue;
  $key=(!$ownerExists && (int)$row['user_id']===$ownerId)?'dono':(in_array($row['role'],['admin','manager'],true)?'gerente':'funcionario');
  $roleId=(int)scalar($db,'SELECT COALESCE((SELECT id FROM company_roles WHERE company_id=? AND role_key=?),0)',[$company,$key]);
  if(!$roleId && $key!=='dono'){
   // Não devolver acesso de administrador a membros sem cargo.
   $roleId=(int)scalar($db,"SELECT COALESCE((SELECT id FROM company_roles WHERE company_id=? AND role_key='sem_acesso'),0)",[$company]);
   if(!$roleId){
    $db->prepare("INSERT OR IGNORE INTO company_roles(company_id,role_key,name,permissions_json,builtin) VALUES(?,'sem_acesso','Sem acesso','[]',0)")->execute([$company]);
    $roleId=(int)scalar($db,"SELECT id FROM company_roles WHERE company_id=? AND role_key='sem_acesso'",[$company]);
   }
  }
  if($roleId) $db->prepare('UPDATE members SET role_id=? WHERE company_id=? AND user_id=? AND role_id IS NULL')->execute([$roleId,$company,$row['user_id']]);
  if($key==='dono') $ownerExists=true;
 }
}
// Migrar empresas já existentes de modo não destrutivo.
foreach(all($db,'SELECT id FROM companies') as $row) ensure_company_roles($db,(int)$row['id']);
function current_company_role(PDO $db,int $company,?int $user=null):?array {
 return one($db,'SELECT r.* FROM members m JOIN company_roles r ON r.id=m.role_id AND r.company_id=m.company_id WHERE m.company_id=? AND m.user_id=?',[$company,$user??user_id()]);
}
function has_permission(PDO $db,int $company,string $permission):bool {
 if(!array_key_exists($permission,permission_catalog()))return false;
 $role=current_company_role($db,$company);
 if(!$role)return false;
 if($role['role_key']==='dono')return true;
 $permissions=json_decode((string)$role['permissions_json'],true);
 return is_array($permissions) && in_array($permission,$permissions,true);
}
function require_permission(PDO $db,int $company,string $permission):void {
 if(!has_permission($db,$company,$permission))throw new RuntimeException('Sem permissão para esta operação ('.(permission_catalog()[$permission]??$permission).').');
}
function page_permission(string $page):string {
 return [
  'dashboard'=>'dashboard.view','pos'=>'pos.use','orders'=>'orders.manage','tables'=>'tables.view',
  'reservations'=>'reservations.manage','products'=>'products.view','customers'=>'customers.manage',
  'cash'=>'cash.manage','reports'=>'reports.view','exports'=>'exports.use','fiscal'=>'fiscal.use','ai10'=>'ai.use',
  'logs'=>'logs.view','settings'=>'company.manage','team'=>'roles.manage'
 ][$page]??'dashboard.view';
}
function can_access_page(PDO $db,int $company,string $page):bool {
 if($page==='team')return has_permission($db,$company,'roles.manage')||has_permission($db,$company,'company.manage');
 if($page==='settings'){
  foreach(['company.manage','roles.manage','cash.manage','reports.view','profits.view','exports.use','fiscal.use','ai.use','logs.view'] as $perm)
   if(has_permission($db,$company,$perm))return true;
  return false;
 }
 if($page==='reports')return has_permission($db,$company,'reports.view')||has_permission($db,$company,'profits.view');
 return has_permission($db,$company,page_permission($page));
}
function action_permission(string $action):string {
 return match($action){
  'product','product_edit'=>'products.manage', 'stock'=>'stock.manage',
  'table','table_edit'=>'tables.manage', 'reservation','reservation_edit','reservation_status'=>'reservations.manage',
  'customer','customer_edit'=>'customers.manage', 'open_till','close_till','cash_move'=>'cash.manage',
  'new_order','add_item','remove_item','cancel_order','pay_order','order_payment_mode','item_service_status'=>'orders.manage',
  default=>'roles.manage'
 };
}
function ai_action_permission(string $kind,array $action=[]):string {
 return match($kind){
  'report'=>match($action['topic']??''){
   'products','low_stock'=>'products.view','tables'=>'tables.view',
   'reservations'=>'reservations.manage','customers'=>'customers.manage',
   'logs'=>'logs.view','fiscal'=>'fiscal.use','availability'=>'tables.view','profit'=>'profits.view',
   'open_orders'=>'orders.manage',default=>'reports.view'
  },
  'stock','set_stock'=>'stock.manage',
  'duplicate_product','delete_product','restore_product','update_product','undo_product','create_product'=>'products.manage',
  'create_table','duplicate_table','update_table'=>'tables.manage', 'move_table'=>'tables.layout',
  'new_order','duplicate_order'=>'orders.manage',
  'open_till','cash_move'=>'cash.manage',
  'reservation','reservation_edit','cancel_reservation'=>'reservations.manage',default=>'roles.manage'
 };
}
function valid_nif(string $nif):bool {
 if($nif==='')return true;
 if(!preg_match('/^[0-9]{9}$/',$nif))return false;
 $sum=0;
 for($i=0;$i<8;$i++)$sum+=(int)$nif[$i]*(9-$i);
 $digit=11-($sum%11);
 if($digit>=10)$digit=0;
 return $digit===(int)$nif[8];
}
function available_table(PDO $db,int $company,string $when,int $guests,?int $preferred=null,?int $ignoreReservation=null):array {
 if(!preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d$/',$when))throw new RuntimeException('Data e hora inválidas.');
 $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$when);
 if(!$d||$d->format('Y-m-d H:i')!==$when)throw new RuntimeException('Data e hora inválidas.');
 if($guests<1||$guests>100)throw new RuntimeException('Número de pessoas inválido.');
 $tables=all($db,'SELECT * FROM dining_tables WHERE company_id=? AND seats>=? '.($preferred?'AND id=? ':'').'ORDER BY seats ASC,id ASC', $preferred?[$company,$guests,$preferred]:[$company,$guests]);
 foreach($tables as $table){
  require_once __DIR__.'/app/domain/company_features.php';
  $buffer=max(0,(int)g360_preferences($db,$company)['booking_duration']-1);
  $clashes=scalar($db,"SELECT COUNT(*) FROM reservations WHERE company_id=? AND table_id=? AND status IN ('confirmada','chegou') AND id<>? AND datetime(start_at) BETWEEN datetime(?,'-'||?||' minutes') AND datetime(?,'+'||?||' minutes')",[$company,$table['id'],$ignoreReservation??0,$when,$buffer,$when,$buffer]);
  // Se a reserva é para as próximas 2 horas, não ocupar uma mesa com um pedido ainda aberto.
  if(!$clashes && $d<=new DateTimeImmutable('+2 hours') && $d>=new DateTimeImmutable('-2 hours'))
    $clashes=scalar($db,"SELECT COUNT(*) FROM orders WHERE company_id=? AND table_id=? AND status='aberto'",[$company,$table['id']]);
  if(!$clashes)return $table;
 }
 $alternatives=[];
 if(!$preferred){
  foreach([-120,-90,-60,-30,30,60,90,120,150,180] as $shift){
   $candidate=$d->modify(($shift>=0?'+':'').$shift.' minutes');
   if($candidate<new DateTimeImmutable('now'))continue;
   $free=g360_availability($db,$company,$candidate->format('Y-m-d'),$candidate->format('H:i'),$guests);
   if(!empty($free['available']))$alternatives[]=$candidate->format('d/m/Y').' às '.$candidate->format('H:i');
   if(count($alternatives)>=3)break;
  }
 }
 throw new RuntimeException($preferred?'A mesa escolhida não tem disponibilidade para essa hora.':('Não existem mesas disponíveis com capacidade para essa data e hora.'.($alternatives?' Horários alternativos: '.implode(', ',$alternatives).'.':'')));
}
function valid_date_range(string $from,string $to):array {
 foreach([$from,$to] as $v){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v); if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Data inválida.');}
 if($from>$to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366)throw new RuntimeException('Intervalo inválido (máximo 366 dias).');
 return [$from,$to,(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d')];
}
