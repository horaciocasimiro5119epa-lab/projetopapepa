<?php
/** Reservas públicas sem sessão: rate limit, CSRF, honeypot e transação atómica. */
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store');
$slug=(string)($_GET['empresa']??$_POST['empresa']??'');
if(!preg_match('/^[a-z0-9][a-z0-9-]{2,60}$/',$slug)){http_response_code(404);exit('Endereço de reservas inválido.');}
$company=one($db,'SELECT id,name FROM companies WHERE booking_slug=?',[$slug]);
if(!$company){http_response_code(404);exit('Esta página de reservas não existe.');}
$cid=(int)$company['id'];$error='';$success='';
require_once __DIR__.'/app/domain/company_features.php';
if(!g360_feature($db,$cid,'booking_enabled')){http_response_code(404);exit('Esta funcionalidade não está disponível neste estabelecimento.');}
$g360PublicPrefs=g360_preferences($db,$cid);
require_once __DIR__.'/app/domain/public_branding.php';
$ugBookingBanner=ug_public_banner_url($g360PublicPrefs,'booking',$cid);
$ugBookingTitle=ug_public_text($g360PublicPrefs,'booking_hero_title','Escolhe o teu momento.');
$ugBookingSubtitle=ug_public_text($g360PublicPrefs,'booking_hero_subtitle','Escolhe a data, a hora e o número de pessoas. O UniGest procura uma mesa disponível para o teu pedido.');
$ugBookingBadge=ug_public_text($g360PublicPrefs,'booking_hero_badge','RESERVAS ONLINE');
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals((string)($_SESSION['public_booking_csrf']??''),(string)($_POST['csrf']??'')))throw new RuntimeException('A sessão expirou. Atualiza a página.');
  if(trim((string)($_POST['website']??''))!=='')throw new RuntimeException('Não foi possível aceitar o pedido.');
  if((time()-(int)($_SESSION['public_booking_started']??time()))<3)throw new RuntimeException('Aguarda alguns segundos antes de enviar.');
  $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');$ipHash=hash_hmac('sha256',$ip,hash('sha256',__FILE__));
  $db->exec("DELETE FROM public_booking_attempts WHERE created_at < datetime('now','-2 days')");
  $db->beginTransaction();
  $recent=(int)scalar($db,"SELECT COUNT(*) FROM public_booking_attempts WHERE company_id=? AND ip_hash=? AND created_at>=datetime('now','-1 hour')",[$cid,$ipHash]);
  if($recent>=5)throw new RuntimeException('Foram enviados demasiados pedidos. Tenta novamente mais tarde.');
  $db->prepare("INSERT INTO public_booking_attempts(company_id,ip_hash,created_at) VALUES(?,?,datetime('now'))")->execute([$cid,$ipHash]);
  $name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$phone=trim((string)($_POST['phone']??''));
  $notes=!empty($g360PublicPrefs['booking_allow_notes'])?trim((string)($_POST['notes']??'')):'';$guests=filter_var($_POST['guests']??null,FILTER_VALIDATE_INT);
  $when=str_replace('T',' ',(string)($_POST['start_at']??''));
  if(mb_strlen($name)<2||mb_strlen($name)>120||$guests===false||$guests<1||$guests>(int)$g360PublicPrefs['booking_max_guests']||mb_strlen($notes)>500||mb_strlen($phone)>35)throw new RuntimeException('Verifica o nome, o número de pessoas e os restantes dados.');
  if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Endereço de email inválido.');
  if($email===''&&$phone==='')throw new RuntimeException('Indica um email ou um contacto telefónico.');
  $dt=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$when);
  if(!$dt||$dt->format('Y-m-d H:i')!==$when||$dt<new DateTimeImmutable('now')||$dt>new DateTimeImmutable('+'.(int)$g360PublicPrefs['booking_advance_days'].' days')||$dt<new DateTimeImmutable('+'.(int)$g360PublicPrefs['booking_min_notice_hours'].' hours'))throw new RuntimeException('Escolhe uma data futura válida, até um ano.');
  if(!g360_reservation_slot_allowed($g360PublicPrefs,$when,$guests))throw new RuntimeException('Escolhe um horário permitido nas configurações deste estabelecimento.');
  $table=available_table($db,$cid,$when,$guests);
  $db->prepare('INSERT INTO reservations(company_id,table_id,customer,guests,start_at,notes,contact_phone,contact_email) VALUES(?,?,?,?,?,?,?,?)')->execute([$cid,$table['id'],$name,$guests,$when,$notes,$phone,$email]);
  $existing=one($db,'SELECT id FROM customers WHERE company_id=? AND name=? AND (phone=? OR email=?) LIMIT 1',[$cid,$name,$phone,$email]);
  if(!$existing)$db->prepare('INSERT INTO customers(company_id,name,phone,email) VALUES(?,?,?,?)')->execute([$cid,$name,$phone,$email]);
  $db->commit();$success='Reserva registada com sucesso para '.$when.'. A mesa foi atribuída automaticamente. '.trim((string)$g360PublicPrefs['booking_success_message']);
  unset($_SESSION['public_booking_csrf']);
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
$_SESSION['public_booking_csrf']=bin2hex(random_bytes(24));$_SESSION['public_booking_started']=time();
function pubh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Reservar · <?=pubh($company['name'])?> · UniGest</title><link rel="stylesheet" href="assets/css/public-booking.css"><link rel="stylesheet" href="assets/css/public-branding-v55.css?v=<?=filemtime(__DIR__.'/assets/css/public-branding-v55.css')?>"><link rel="icon" href="favicon.png?v=41" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=41"><link rel="stylesheet" href="assets/css/public-v62.css?v=62"></head><body data-booking-start="<?=pubh($g360PublicPrefs['booking_open'])?>" data-booking-end="<?=pubh($g360PublicPrefs['booking_close'])?>" data-booking-interval="<?=(int)$g360PublicPrefs['booking_interval']?>" data-booking-notice-hours="<?=(int)$g360PublicPrefs['booking_min_notice_hours']?>" data-public-design="<?=pubh($g360PublicPrefs['booking_design'])?>" data-ug-layout="<?=pubh($g360PublicPrefs['booking_layout'])?>" data-ug-font="<?=pubh($g360PublicPrefs['booking_font'])?>" data-ug-banner-position="<?=pubh($g360PublicPrefs['booking_banner_position'])?>" style="--ug-brand-color:<?=pubh(ug_public_color($g360PublicPrefs,'booking'))?>;--ug-banner-opacity:<?=(int)$g360PublicPrefs['booking_banner_overlay']/100?>"><main class="booking-shell"><header><a href="index.php" class="brand"><img src="logo.png?v=41" class="g360-brand-full" width="170" height="57" alt="UniGest"></a><span>Reservas online</span></header><section class="booking-card"><div class="booking-intro">
<?php if($ugBookingBanner): ?><div class="ug-booking-banner"><img src="<?=pubh($ugBookingBanner)?>" alt="" decoding="async"><span aria-hidden="true"></span></div><?php endif; ?>
<span class="eyebrow"><?=pubh($ugBookingBadge)?> · <?=pubh($company['name'])?></span><h1><?=pubh($ugBookingTitle)?></h1><p><?=pubh($ugBookingSubtitle)?></p><?php if(!empty($g360PublicPrefs['booking_show_meta'])): ?><div class="booking-meta"><div><strong>Sem login</strong><small>Os teus clientes reservam de forma simples e rápida.</small></div><div><strong>Atribuição automática</strong><small>A mesa é escolhida com base na disponibilidade e na lotação.</small></div><div><strong>Dados completos</strong><small>O pedido entra nas reservas e, quando aplicável, também em clientes.</small></div></div><?php endif; ?><?php if(!empty($g360PublicPrefs['booking_show_steps'])): ?><ul><li><b>1</b><div><strong>Escolhe a data</strong><small>Indica quando queres reservar e quantas pessoas vão comparecer.</small></div></li><li><b>2</b><div><strong>Envia o pedido</strong><small>O estabelecimento recebe os dados imediatamente no painel da UniGest.</small></div></li><li><b>3</b><div><strong>Chega com tranquilidade</strong><small>O estabelecimento pode acompanhar a tua chegada e gerir melhor o serviço.</small></div></li></ul><?php endif; ?></div><form method="post" autocomplete="on"><input type="hidden" name="empresa" value="<?=pubh($slug)?>"><input type="hidden" name="csrf" value="<?=pubh($_SESSION['public_booking_csrf'])?>"><div class="trap" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div><?php if($error):?><div class="message error"><?=pubh($error)?></div><?php endif;?><?php if($success):?><div class="message success"><?=pubh($success)?></div><?php endif;?><label>Nome completo<input name="name" maxlength="120" required autocomplete="name"></label><div class="pair"><label>Contacto telefónico<input name="phone" maxlength="35" autocomplete="tel"></label><label>Email<input type="email" name="email" maxlength="160" autocomplete="email"></label></div><div class="pair"><label>Data da reserva<input type="date" id="booking-public-date" min="<?=date('Y-m-d')?>" max="<?=date('Y-m-d',strtotime('+'.(int)$g360PublicPrefs['booking_advance_days'].' days'))?>" value="<?=date('Y-m-d')?>" required></label><label>Hora disponível<select id="booking-public-time" aria-label="Selecionar horário"><option>Escolhe uma data</option></select><input type="hidden" name="start_at" id="booking-public-start" required></label><label>Número de pessoas<input type="number" name="guests" min="1" max="<?=(int)$g360PublicPrefs['booking_max_guests']?>" value="2" required></label></div><?php if(!empty($g360PublicPrefs['booking_allow_notes'])): ?><label>Observações<textarea name="notes" maxlength="500" rows="4" placeholder="Alergias, preferências ou outras informações"></textarea></label><?php endif; ?><button type="submit"><?=pubh(ug_public_text($g360PublicPrefs,'booking_button_label','Pedir reserva'))?> →</button><small>Indica um telefone ou email. Os dados são enviados ao estabelecimento para gerir a reserva.</small></form></section></main><script src="assets/js/public-booking.js" defer></script></body></html>