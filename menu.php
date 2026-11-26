<?php
/**
 * UniGest · Menu digital público.
 * Consultar o catálogo é público; adicionar artigos exige autorização da mesa.
 * Todas as operações são validadas no servidor e limitadas por sessão/IP.
 */
require __DIR__.'/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$slug=strtolower(trim((string)($_GET['empresa']??$_POST['empresa']??'')));
if(!preg_match('/^[a-z0-9][a-z0-9-]{1,60}[a-z0-9]$/D',$slug)){
    http_response_code(404);exit('Menu não disponível.');
}
$company=one($db,'SELECT id,name FROM companies WHERE menu_slug=?',[$slug]);
if(!$company){http_response_code(404);exit('Este menu não foi encontrado.');}
$cid=(int)$company['id'];
require_once __DIR__.'/app/domain/company_features.php';
if(!g360_feature($db,$cid,'menu_enabled')){http_response_code(404);exit('Esta funcionalidade não está disponível neste estabelecimento.');}
require_once __DIR__.'/app/domain/menu_request_tracking.php';
ug_menu_tracking_setup($db);
$g360PublicPrefs=g360_preferences($db,$cid);
require_once __DIR__.'/app/domain/public_branding.php';
$ugMenuBanner=ug_public_banner_url($g360PublicPrefs,'menu',$cid);
$ugMenuTitle=ug_public_text($g360PublicPrefs,'menu_hero_title',(string)$company['name']);
$ugMenuSubtitle=ug_public_text($g360PublicPrefs,'menu_hero_subtitle','Descobre os produtos do estabelecimento. Podes consultar o menu e pedir a partir da tua mesa após a autorização da equipa.');
$ugMenuBadge=ug_public_text($g360PublicPrefs,'menu_hero_badge','O NOSSO MENU');
$sessionKey='menu_access_'.$cid;
$csrfKey='menu_csrf_'.$cid;
$noticeKey='menu_notice_'.$cid;
$nonceKey='menu_form_nonce_'.$cid;
$_SESSION[$csrfKey]??=bin2hex(random_bytes(24));
$_SESSION[$nonceKey]??=bin2hex(random_bytes(16));

function menu_access(PDO $db,int $companyId,string $secret):?array {
    if($secret==='')return null;
    return one($db,"SELECT a.*,t.name AS table_name,t.seats AS table_seats
        FROM public_menu_access a
        JOIN dining_tables t ON t.id=a.table_id AND t.company_id=a.company_id
        WHERE a.company_id=? AND a.secret_hash=? AND a.expires_at>?",
        [$companyId,hash('sha256',$secret),now()]);
}
$access=menu_access($db,$cid,(string)($_SESSION[$sessionKey]??''));
$error='';
$notice=(string)($_SESSION[$noticeKey]??'');unset($_SESSION[$noticeKey]);
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals((string)$_SESSION[$csrfKey],(string)($_POST['csrf']??'')))
            throw new RuntimeException('A sessão expirou. Atualiza a página e volta a tentar.');
        if(trim((string)($_POST['website']??''))!=='')throw new RuntimeException('Pedido não aceite.');
        $operation=(string)($_POST['operation']??'');
        if($operation==='request_access'){
            if($access && $access['status']==='aprovado')
                throw new RuntimeException('A tua mesa já está autorizada.');
            $tableId=filter_var($_POST['table_id']??null,FILTER_VALIDATE_INT);
            $table=$tableId?one($db,'SELECT id,name,seats FROM dining_tables WHERE id=? AND company_id=?',[$tableId,$cid]):null;
            if(!$table)throw new RuntimeException('Escolhe uma mesa válida.');
            if($access && $access['status']==='pendente' && (int)$access['table_id']===(int)$tableId){
                $_SESSION[$noticeKey]='O pedido para a mesa '.$table['name'].' já está à espera de aprovação.';
            }else{
                $ip=(string)($_SERVER['REMOTE_ADDR']??'desconhecido');
                $ipHash=hash_hmac('sha256',$ip,hash('sha256',__FILE__));
                $db->exec("DELETE FROM public_booking_attempts WHERE created_at < datetime('now','-2 days')");
                $attempts=(int)scalar($db,"SELECT COUNT(*) FROM public_booking_attempts WHERE company_id=? AND ip_hash=? AND created_at>=datetime('now','-1 hour')",[$cid,$ipHash]);
                if($attempts>=8)throw new RuntimeException('Limite de pedidos de acesso atingido. Pede ajuda a um colaborador.');
                $tableAttempts=(int)scalar($db,'SELECT COUNT(*) FROM public_menu_access WHERE company_id=? AND table_id=? AND created_at>=?',[ $cid,$tableId,date('Y-m-d H:i:s',time()-3600)]);
                if($tableAttempts>=20)throw new RuntimeException('Esta mesa já recebeu demasiados pedidos. Fala com um colaborador.');
                $db->beginTransaction();
                $db->prepare("INSERT INTO public_booking_attempts(company_id,ip_hash,created_at) VALUES(?,?,datetime('now'))")->execute([$cid,$ipHash]);
                if($access && $access['status']==='pendente'){
                    $db->prepare("UPDATE public_menu_access SET status='rejeitado' WHERE id=? AND company_id=? AND status='pendente'")->execute([$access['id'],$cid]);
                }
                $secret=bin2hex(random_bytes(32));
                $db->prepare("INSERT INTO public_menu_access(company_id,table_id,secret_hash,status,created_at,expires_at) VALUES(?,?,?,'pendente',?,?)")
                   ->execute([$cid,$tableId,hash('sha256',$secret),now(),date('Y-m-d H:i:s',time()+3*3600)]);
                $db->commit();
                $_SESSION[$sessionKey]=$secret;
                $_SESSION[$noticeKey]='Pedido enviado para a mesa '.$table['name'].'. Um colaborador irá confirmar a tua presença.';
            }
        }elseif($operation==='add'){
            // Nonce de utilização única impede pedidos repetidos por duplo clique ou reenvio de formulário.
            if(!hash_equals((string)$_SESSION[$nonceKey],(string)($_POST['request_nonce']??'')))
                throw new RuntimeException('Este pedido já foi submetido. Atualiza o menu antes de tentar novamente.');
            $access=menu_access($db,$cid,(string)($_SESSION[$sessionKey]??''));
            if(!$access || $access['status']!=='aprovado')throw new RuntimeException('A tua mesa ainda não foi autorizada.');
            $pid=filter_var($_POST['product_id']??null,FILTER_VALIDATE_INT);
            $qty=filter_var($_POST['qty']??null,FILTER_VALIDATE_INT);
            if(!$pid || !$qty || $qty<1 || $qty>10)throw new RuntimeException('Escolhe uma quantidade entre 1 e 10.');
            $product=one($db,'SELECT id,name,active,qty,price_cents FROM products WHERE company_id=? AND id=? AND active=1',[$cid,$pid]);
            if(!$product || (int)$product['qty']<$qty)throw new RuntimeException('Este produto não tem stock suficiente.');
            $recent=(int)scalar($db,'SELECT COUNT(*) FROM public_menu_requests WHERE access_id=? AND created_at>=?',[$access['id'],date('Y-m-d H:i:s',time()-3600)]);
            if($recent>=25)throw new RuntimeException('Limite de pedidos atingido. Pede ajuda a um colaborador.');
            $db->prepare("INSERT INTO public_menu_requests(company_id,access_id,product_id,qty,status,created_at,name_snapshot,price_cents_snapshot) VALUES(?,?,?,?,'pendente',?,?,?)")
               ->execute([$cid,$access['id'],$pid,$qty,now(),(string)$product['name'],(int)$product['price_cents']]);
            $_SESSION[$nonceKey]=bin2hex(random_bytes(16));
            $_SESSION[$noticeKey]='Pedido enviado: '.$qty.' × '.$product['name'].'. Aguarda a confirmação do estabelecimento.';
        }elseif($operation==='cancel_pending'){
            $secret=(string)($_SESSION[$sessionKey]??'');
            $owned= $secret!==''?one($db,'SELECT id FROM public_menu_access WHERE company_id=? AND secret_hash=? AND created_at>=?',[$cid,hash('sha256',$secret),date('Y-m-d H:i:s',time()-86400)]):null;
            if(!$owned)throw new RuntimeException('Não foi possível identificar a sessão desta mesa.');
            $requestId=filter_var($_POST['request_id']??null,FILTER_VALIDATE_INT);
            if(!$requestId)throw new RuntimeException('Artigo inválido.');
            $db->beginTransaction();
            $stmt=$db->prepare("UPDATE public_menu_requests SET status='cancelado',decision_at=? WHERE id=? AND company_id=? AND access_id=? AND status='pendente'");
            $stmt->execute([now(),$requestId,$cid,(int)$owned['id']]);
            if(!$stmt->rowCount())throw new RuntimeException('O artigo já foi tratado pela equipa ou não pertence a esta sessão.');
            $db->commit();
            $_SESSION[$noticeKey]='O envio deste artigo foi cancelado antes da aprovação.';
        }else{throw new RuntimeException('Operação desconhecida.');}
        header('Location: menu.php?empresa='.rawurlencode($slug),true,303);exit;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
    $access=menu_access($db,$cid,(string)($_SESSION[$sessionKey]??''));
}
$tables=all($db,'SELECT id,name,seats FROM dining_tables WHERE company_id=? ORDER BY name',[$cid]);
$products=all($db,'SELECT id,name,category,price_cents,qty,image_path FROM products WHERE company_id=? AND active=1 ORDER BY category,name',[$cid]);
$categories=[];foreach($products as $p){$categories[trim((string)$p['category'])?:'Geral'][]=$p;}
$authorized=$access && $access['status']==='aprovado';
$recentRequests=ug_menu_tracking_rows($db,$cid,(string)($_SESSION[$sessionKey]??''),60);
$trackingCounts=ug_menu_tracking_counts($recentRequests);
$pending=$access && $access['status']==='pendente';
$rejected=$access && $access['status']==='rejeitado';
function menuh(mixed $value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
?>
<!doctype html>
<html lang="pt-PT">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#100c1b"><meta name="description" content="Menu digital de <?=menuh($company['name'])?>. Consulta os produtos e pede a partir da tua mesa com aprovação do estabelecimento.">
<title>Menu digital · <?=menuh($company['name'])?> | UniGest</title>
<link rel="stylesheet" href="assets/css/public-menu.css?v=<?=filemtime(__DIR__.'/assets/css/public-menu.css')?>">
<link rel="stylesheet" href="assets/css/ux-v54.css?v=<?=filemtime(__DIR__.'/assets/css/ux-v54.css')?>">
<link rel="stylesheet" href="assets/css/public-branding-v55.css?v=<?=filemtime(__DIR__.'/assets/css/public-branding-v55.css')?>">
<script src="assets/js/public-menu.js?v=<?=filemtime(__DIR__.'/assets/js/public-menu.js')?>" defer></script>
<script src="assets/js/menu-orders-v57.js?v=<?=filemtime(__DIR__.'/assets/js/menu-orders-v57.js')?>" defer></script>
<link rel="stylesheet" href="assets/css/menu-orders-v57.css?v=<?=filemtime(__DIR__.'/assets/css/menu-orders-v57.css')?>">
<link rel="icon" href="favicon.png?v=41" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=41"><link rel="stylesheet" href="assets/css/public-v62.css?v=62"></head>
<body data-public-design="<?=menuh($g360PublicPrefs['menu_design'])?>" data-menu-status="<?=menuh($pending?'pendente':($authorized?'aprovado':'sem_acesso'))?>" data-menu-slug="<?=menuh($slug)?>" data-ug-layout="<?=menuh($g360PublicPrefs['menu_layout'])?>" data-ug-font="<?=menuh($g360PublicPrefs['menu_font'])?>" data-ug-density="<?=menuh($g360PublicPrefs['menu_card_density'])?>" data-ug-banner-position="<?=menuh($g360PublicPrefs['menu_banner_position'])?>" data-ug-show-images="<?=(int)$g360PublicPrefs['menu_show_images']?>" style="--ug-brand-color:<?=menuh(ug_public_color($g360PublicPrefs,'menu'))?>;--ug-banner-opacity:<?=(int)$g360PublicPrefs['menu_banner_overlay']/100?>">
<header class="menu-header"><div class="menu-header-inner">
  <a href="index.php" class="menu-brand" aria-label="UniGest, página inicial"><img src="logo.png?v=41" class="g360-brand-full" width="170" height="57" alt="UniGest"></a>
  <div class="menu-business"><span class="menu-business-caption">MENU DO ESTABELECIMENTO</span><strong><?=menuh($company['name'])?></strong></div>
  <span class="menu-header-status <?= $authorized?'active':'' ?>"><span class="menu-status-dot"></span><?= $authorized?menuh($access['table_name']):'Menu digital'?></span>
</div></header>
<main class="menu-wrap">
  <section class="menu-hero" aria-labelledby="menu-title">
  <?php if($ugMenuBanner): ?><img class="ug-public-banner-image" src="<?=menuh($ugMenuBanner)?>" alt="" decoding="async" fetchpriority="high"><span class="ug-public-banner-shade" aria-hidden="true"></span><?php endif; ?>
  <span class="menu-eyebrow"><?=menuh($ugMenuBadge)?></span><h1 id="menu-title"><?=menuh($ugMenuTitle)?></h1><p><?=menuh($ugMenuSubtitle)?></p>
  <?php if(!empty($g360PublicPrefs['menu_show_steps'])): ?><div class="menu-hero-steps"><span><b>01</b> Escolher mesa</span><span><b>02</b> Aguardar autorização</span><span><b>03</b> Fazer o pedido</span></div><?php endif; ?></section>
  <?php if($error): ?><div class="menu-alert error" role="alert"><span>!</span><p><?=menuh($error)?></p></div><?php endif; ?>
  <?php if($notice): ?><div class="menu-alert success" role="status"><span>✓</span><p><?=menuh($notice)?></p></div><?php endif; ?>
  <?php if($authorized): ?>
    <section class="menu-access-card is-authorized" aria-live="polite"><div class="menu-access-icon">✓</div><div><span class="menu-eyebrow">ACESSO CONFIRMADO</span><h2><?=menuh($access['table_name'])?> autorizada</h2><p>Já podes enviar pedidos. Um colaborador irá aceitá-los e confirmar a sua disponibilidade.</p></div><span class="menu-access-chip">Autorizado</span></section>
  <?php elseif($pending): ?>
    <section class="menu-access-card is-pending" aria-live="polite"><div class="menu-access-icon menu-spinner" aria-hidden="true">◷</div><div><span class="menu-eyebrow">A AGUARDAR A EQUIPA</span><h2>Autorização pendente · <?=menuh($access['table_name'])?></h2><p>Um colaborador está a verificar a tua mesa. O estado atualiza-se automaticamente, sem teres de recarregar a página.</p><p class="menu-waiting"><span></span> A verificar autorização...</p></div><a class="menu-secondary-link" href="menu.php?empresa=<?=rawurlencode($slug)?>">Atualizar agora</a></section>
  <?php else: ?>
    <section class="menu-access-card" aria-labelledby="menu-access-title"><div class="menu-access-icon">⌗</div><div class="menu-access-content"><span class="menu-eyebrow">COMEÇAR A PEDIR</span><h2 id="menu-access-title">Em que mesa estás?</h2><p>Seleciona a mesa em que te encontras. Um colaborador terá de confirmar o acesso antes de poderes enviar pedidos. Não é necessário criar uma conta.</p>
      <?php if($rejected):?><p class="menu-rejected">O acesso anterior foi recusado. Confirma o número da mesa com a equipa e volta a solicitar.</p><?php endif; ?>
      <form class="menu-table-form" method="post" action="menu.php?empresa=<?=rawurlencode($slug)?>"><input type="hidden" name="csrf" value="<?=menuh($_SESSION[$csrfKey])?>"><input type="hidden" name="operation" value="request_access"><label class="menu-honeypot" aria-hidden="true">Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      <label class="menu-field">Selecionar mesa<select name="table_id" required><option value="">Escolhe a tua mesa...</option><?php foreach($tables as $t):?><option value="<?=(int)$t['id']?>"><?=menuh($t['name'])?> · <?=(int)$t['seats']?> lugares</option><?php endforeach;?></select></label>
      <button type="submit" class="menu-button" <?=!$tables?'disabled':''?>>Solicitar autorização <span aria-hidden="true">→</span></button></form>
      <?php if(!$tables):?><p class="menu-note">Este estabelecimento ainda não configurou mesas. Fala com a equipa.</p><?php endif;?>
    </div></section>
  <?php endif; ?>
  <?php if((string)($_SESSION[$sessionKey]??'')!=='' && ($access || $recentRequests)): ?>
  <section class="ug-menu-tracking" data-ug-menu-tracking data-ug-csrf="<?=menuh($_SESSION[$csrfKey])?>" aria-labelledby="ug-tracking-title">
    <header class="ug-tracking-header">
      <div><span class="menu-eyebrow">ACOMPANHA O TEU PEDIDO</span><h2 id="ug-tracking-title">Os meus pedidos</h2><p>Vê os artigos que enviaste, os que aguardam confirmação e os que já foram servidos. Os estados são atualizados pela equipa.</p></div>
      <span class="ug-tracking-live" data-ug-tracking-sync aria-live="polite">A ligar ao serviço…</span>
    </header>
    <div class="ug-tracking-overview" aria-label="Resumo dos pedidos">
       <div><small>Enviados</small><strong data-ug-count="total"><?=(int)$trackingCounts['total']?></strong></div>
       <div><small>Por aprovar</small><strong data-ug-count="pendente"><?=(int)$trackingCounts['pendente']?></strong></div>
       <div><small>Aceites</small><strong data-ug-count="aceite"><?=(int)$trackingCounts['aceite']?></strong></div>
       <div><small>Concluídos</small><strong data-ug-count="concluido"><?=(int)$trackingCounts['concluido']?></strong></div>
    </div>
    <nav class="ug-tracking-filters" aria-label="Filtrar os teus artigos">
      <button type="button" class="is-current" data-ug-state-filter="all" aria-pressed="true">Todos</button>
      <button type="button" data-ug-state-filter="pendente" aria-pressed="false">Por aprovar</button>
      <button type="button" data-ug-state-filter="aceite" aria-pressed="false">Aceites</button>
      <button type="button" data-ug-state-filter="concluido" aria-pressed="false">Concluídos</button>
      <button type="button" data-ug-state-filter="rejeitado" aria-pressed="false">Recusados / cancelados</button>
    </nav>
    <div class="ug-tracking-list" data-ug-tracking-list aria-live="polite">
    <?php foreach($recentRequests as $rr): ?>
      <article class="ug-tracking-item" data-ug-item-state="<?=menuh($rr['state'])?>">
        <span class="ug-tracking-item-icon" aria-hidden="true">▤</span>
        <div class="ug-tracking-item-main"><strong><?=(int)$rr['qty']?> × <?=menuh($rr['product_name'])?></strong><small><?=menuh(substr($rr['created_at'],8,2).'/'.substr($rr['created_at'],5,2).' · '.substr($rr['created_at'],11,5))?><?php if($rr['order_id']): ?> · Pedido #<?=(int)$rr['order_id']?><?php endif; ?></small><p><?=menuh($rr['detail'])?></p></div>
        <div class="ug-tracking-item-side"><b class="ug-tracking-badge" data-state="<?=menuh($rr['state'])?>"><?=menuh($rr['label'])?></b><small><?=money((int)$rr['price_cents']*(int)$rr['qty'])?></small><?php if($rr['state']==='pendente'): ?><form class="ug-cancel-form" method="post" action="menu.php?empresa=<?=rawurlencode($slug)?>"><input type="hidden" name="csrf" value="<?=menuh($_SESSION[$csrfKey])?>"><input type="hidden" name="operation" value="cancel_pending"><input type="hidden" name="request_id" value="<?=(int)$rr['id']?>"><button type="submit">Cancelar envio</button></form><?php endif; ?></div>
      </article>
    <?php endforeach; ?>
    </div>
    <p class="ug-tracking-empty" data-ug-tracking-empty <?=$recentRequests?'hidden':''?>>Ainda não enviaste artigos nesta sessão.</p>
    <footer class="ug-tracking-footer"><span>Os estados correspondem aos registos do estabelecimento. O valor é indicativo e não constitui um recibo.</span><button type="button" data-ug-tracking-refresh>Atualizar estados ↻</button></footer>
  </section>
  <?php endif; ?>
  <section class="menu-catalogue" aria-labelledby="menu-catalogue-title"><div class="menu-catalogue-heading"><div><span class="menu-eyebrow">CATÁLOGO</span><h2 id="menu-catalogue-title"><?=menuh(ug_public_text($g360PublicPrefs,'menu_section_title','Explora o nosso menu'))?></h2><p><?=count($products)?> produtos disponíveis no catálogo</p></div><label class="menu-search"><span aria-hidden="true">⌕</span><input type="search" id="menu-search" placeholder="Pesquisar produtos..." aria-label="Pesquisar produtos" autocomplete="off"></label></div>
   <div class="ug-menu-tools" aria-label="Ferramentas do catálogo"><label>Ordenar <select id="ug-menu-sort"><option value="name">Nome (A–Z)</option><option value="price-asc">Preço: menor primeiro</option><option value="price-desc">Preço: maior primeiro</option></select></label><label><input type="checkbox" id="ug-menu-only-available"> Só disponíveis</label><?php if(!empty($g360PublicPrefs['menu_show_favorites'])): ?><label><input type="checkbox" id="ug-menu-only-favorites"> Favoritos ♡</label><?php endif; ?><small id="ug-menu-results" role="status"></small></div>
   <?php if($categories && !empty($g360PublicPrefs['menu_show_categories'])):?><nav class="menu-category-nav" aria-label="Categorias de produtos"><a class="is-selected" href="#menu-catalogue-title">Todos</a><?php $catNum=0;foreach($categories as $category=>$items):?><a href="#menu-cat-<?=++$catNum?>"><?=menuh($category)?></a><?php endforeach;?></nav><?php endif; ?>
   <?php $catNum=0;foreach($categories as $category=>$items): ?>
   <section class="menu-category" id="menu-cat-<?=++$catNum?>" data-menu-category><div class="menu-category-header"><h3><?=menuh($category)?></h3><span><?=count($items)?> artigos</span></div><div class="menu-grid">
     <?php foreach($items as $item):$available=(int)$item['qty']>0; ?>
     <article class="menu-product <?=!$available?'sold-out':''?>" data-menu-product data-product-id="<?=(int)$item['id']?>" data-price="<?=(int)$item['price_cents']?>" data-available="<?=$available?'1':'0'?>" data-search="<?=menuh(mb_strtolower($item['name'].' '.$category))?>">
       <?php if(!empty($g360PublicPrefs['menu_show_favorites'])): ?><button type="button" class="ug-favorite" aria-label="Adicionar <?=menuh($item['name'])?> aos favoritos" aria-pressed="false" data-ug-fav>♡</button><?php endif; ?><div class="menu-product-image"><img loading="lazy" decoding="async" src="<?=menuh(product_image($item))?>" alt="Imagem do produto <?=menuh($item['name'])?>"><?php if(!empty($g360PublicPrefs['menu_show_stock'])): ?><span class="menu-product-stock <?=$available?'available':'unavailable'?>"><?=$available?'Disponível':'Esgotado'?></span><?php endif; ?></div>
       <div class="menu-product-content"><h4><?=menuh($item['name'])?></h4><small><?=menuh($category)?></small><div class="menu-product-price"><?=money($item['price_cents'])?></div></div>
       <?php if($authorized && $available): ?>
         <form class="menu-order" method="post" action="menu.php?empresa=<?=rawurlencode($slug)?>"><input type="hidden" name="csrf" value="<?=menuh($_SESSION[$csrfKey])?>"><input type="hidden" name="request_nonce" value="<?=menuh($_SESSION[$nonceKey])?>"><input type="hidden" name="operation" value="add"><input type="hidden" name="product_id" value="<?=(int)$item['id']?>"><label>Quantidade<select name="qty" aria-label="Quantidade de <?=menuh($item['name'])?>"><?php for($n=1;$n<=min(10,(int)$item['qty']);$n++):?><option value="<?=$n?>"><?=$n?></option><?php endfor;?></select></label><button class="menu-button" type="submit">Pedir <span aria-hidden="true">＋</span></button></form>
       <?php elseif(!$authorized): ?><div class="menu-product-locked">⌗ Autoriza a tua mesa para pedir</div><?php else: ?><div class="menu-product-locked">Produto temporariamente indisponível</div><?php endif; ?>
     </article>
     <?php endforeach; ?>
   </div></section>
   <?php endforeach; ?>
   <?php if(!$products):?><div class="menu-empty">Este estabelecimento ainda não adicionou produtos ao seu catálogo.</div><?php endif; ?>
   <p id="menu-search-empty" class="menu-empty" hidden>Não foram encontrados produtos com esse nome.</p>
  </section>
</main>
<footer class="menu-footer"><span>✦ UniGest · Menu digital</span><span><?=menuh(ug_public_text($g360PublicPrefs,'menu_footer_text','Pedidos sujeitos a confirmação pelo estabelecimento'))?></span></footer>
</body></html>
