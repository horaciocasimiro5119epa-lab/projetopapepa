<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/app/domain/company_features.php';
require_once __DIR__.'/app/domain/public_branding.php';
must_company($db);$cid=company_id();require_permission($db,$cid,'company.manage');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
try{
 check_token();require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_write($db,$cid);
 $prefs=g360_preferences($db,$cid);
 foreach(['menu_enabled','booking_enabled','pos_enabled','orders_enabled','tables_enabled','products_enabled','customers_enabled','cash_enabled','reports_enabled','exports_enabled','fiscal_enabled','ai_enabled','animations_enabled','logs_enabled','cash_movements_enabled','stock_alerts_enabled','receipt_email_enabled','receipt_print_enabled'] as $key)$prefs[$key]=isset($_POST[$key])?1:0;
 foreach(ug_plan_all_features() as $feature=>$_enabled){
  if(!ug_subscription_feature($db,$cid,$feature))$prefs[$feature]=0;
 }
 foreach(['menu_design','booking_design'] as $key){$v=(string)($_POST[$key]??'');if(!in_array($v,['elegante','minimalista','vibrante'],true))throw new RuntimeException('Design inválido.');$prefs[$key]=$v;}
 // Campos personalizados, com listas brancas, limites de tamanho e cores válidas.
 foreach(['menu','booking'] as $kind){
   foreach([
     'banner_mode'=>['none','preset','custom'],
     'banner_preset'=>array_keys(ug_banner_presets()),
     'banner_position'=>['center','top','bottom'],
     'layout'=>['wide','centered','compact'],
     'font'=>['modern','classic','rounded']
   ] as $suffix=>$allowed){
     $key=$kind.'_'.$suffix;
     $val=(string)($_POST[$key]??$prefs[$key]);
     if(!in_array($val,$allowed,true))throw new RuntimeException('Escolhe uma opção válida para '.$key.'.');
     $prefs[$key]=$val;
   }
   $overlay=filter_var($_POST[$kind.'_banner_overlay']??$prefs[$kind.'_banner_overlay'],FILTER_VALIDATE_INT);
   if($overlay===false||$overlay<0||$overlay>90)throw new RuntimeException('Intensidade do banner inválida.');
   $prefs[$kind.'_banner_overlay']=$overlay;
   $accent=(string)($_POST[$kind.'_accent']??$prefs[$kind.'_accent']);
   if(!preg_match('/^#[0-9a-fA-F]{6}$/D',$accent))throw new RuntimeException('A cor deve estar em formato hexadecimal.');
   $prefs[$kind.'_accent']=strtolower($accent);
 }
 $density=(string)($_POST['menu_card_density']??'comfortable');
 if(!in_array($density,['comfortable','compact'],true))throw new RuntimeException('Densidade inválida.');
 $prefs['menu_card_density']=$density;
 foreach(['menu_show_steps','menu_show_categories','menu_show_favorites','menu_show_stock','menu_show_images',
          'booking_show_steps','booking_show_meta','booking_allow_notes'] as $key)$prefs[$key]=isset($_POST[$key])?1:0;
 foreach([
   'menu_hero_title'=>80,'menu_hero_subtitle'=>260,'menu_hero_badge'=>48,'menu_section_title'=>80,'menu_footer_text'=>160,
   'booking_hero_title'=>80,'booking_hero_subtitle'=>260,'booking_hero_badge'=>48,
   'booking_success_message'=>180,'booking_button_label'=>45
 ] as $key=>$max){
   $val=trim((string)($_POST[$key]??''));
   if(mb_strlen($val)>$max)throw new RuntimeException('Texto demasiado longo: '.$key.'.');
   $prefs[$key]=$val;
 }
 $allowedDays=[30,60,90,180,365];
 $days=filter_var($_POST['booking_advance_days']??null,FILTER_VALIDATE_INT);
 if($days===false||!in_array($days,$allowedDays,true))throw new RuntimeException('Antecedência máxima inválida.');
 $prefs['booking_advance_days']=$days;
 $hours=filter_var($_POST['booking_min_notice_hours']??null,FILTER_VALIDATE_INT);
 if($hours===false||!in_array($hours,[0,2,6,12,24,48],true))throw new RuntimeException('Antecedência mínima inválida.');
 $prefs['booking_min_notice_hours']=$hours;
 $v=(string)($_POST['orders_design']??'');if(!in_array($v,['compacto','cartoes','alto_contraste'],true))throw new RuntimeException('Design dos pedidos inválido.');$prefs['orders_design']=$v;
 $count=filter_var($_POST['orders_panels']??null,FILTER_VALIDATE_INT);if($count===false||$count<1||$count>8)throw new RuntimeException('Escolhe entre 1 e 8 painéis de pedidos.');$prefs['orders_panels']=$count;
 foreach(['booking_open','booking_close'] as $time){$val=(string)($_POST[$time]??'');if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$val))throw new RuntimeException('Horário das reservas inválido.');$prefs[$time]=$val;}
 if($prefs['booking_open']>=$prefs['booking_close'])throw new RuntimeException('A abertura deve ser anterior ao fecho.');
 foreach(['booking_interval'=>[15,30,60],'booking_duration'=>[60,90,120,150,180]] as $key=>$valid){$v=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT);if($v===false||!in_array($v,$valid,true))throw new RuntimeException('Intervalo de reservas inválido.');$prefs[$key]=$v;}
 // Compatibilidade com preferências V33: módulos desligados anulam os controlos subordinados.
 if(!$prefs['cash_enabled'])$prefs['cash_movements_enabled']=0;
 if(!$prefs['pos_enabled']){$prefs['receipt_email_enabled']=0;$prefs['receipt_print_enabled']=0;}
 if(!$prefs['products_enabled'])$prefs['stock_alerts_enabled']=0;
 if($prefs['pos_enabled'] && (!$prefs['cash_enabled'] || !$prefs['orders_enabled'] || !$prefs['products_enabled']))throw new RuntimeException('Para utilizar a caixa POS, mantém ativos Caixa, Pedidos e Produtos.');
 if($prefs['menu_enabled'] && (!$prefs['orders_enabled']||!$prefs['tables_enabled']||!$prefs['products_enabled']))throw new RuntimeException('Para ativar o menu digital, mantém ativos os módulos Pedidos, Mesas e Produtos.');
 if($prefs['booking_enabled'] && !$prefs['tables_enabled'])throw new RuntimeException('Para ativar reservas, mantém o módulo Mesas ativo.');
 $max=filter_var($_POST['booking_max_guests']??null,FILTER_VALIDATE_INT);if($max===false||$max<1||$max>100)throw new RuntimeException('Lotação inválida.');$prefs['booking_max_guests']=$max;
 $email=trim((string)($_POST['receipt_sender_email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Email de origem dos recibos inválido.');$prefs['receipt_sender_email']=$email;
 $footer=trim((string)($_POST['receipt_business_footer']??''));if(mb_strlen($footer)>160)throw new RuntimeException('Rodapé demasiado longo.');$prefs['receipt_business_footer']=$footer;
 $db->prepare('INSERT INTO company_preferences(company_id,preferences_json) VALUES(?,?) ON CONFLICT(company_id) DO UPDATE SET preferences_json=excluded.preferences_json')->execute([$cid,json_encode($prefs,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
 if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest'){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'message'=>'Preferências guardadas.','features'=>array_intersect_key($prefs,array_fill_keys(array_keys(g360_feature_groups()['Preferências operacionais']),true))]);exit;}
 flash('Preferências da empresa atualizadas com sucesso.','ok');
}catch(Throwable $e){if(($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest'){http_response_code(422);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);exit;}flash('Não foi possível guardar: '.$e->getMessage(),'error');}
$section=(string)($_POST['settings_section']??'modules');
if(!in_array($section,['modules','menu','booking','orders','receipt'],true))$section='modules';
header('Location: index.php?page=settings#g360-sec-'.$section,true,303);exit;
