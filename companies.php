<?php
require __DIR__.'/bootstrap.php';
must_login();
$error='';
$intent=(string)($_SESSION['onboarding_intent']??'create');
$view=($_GET['view']??$intent)==='join'?'join':'create';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  check_token();
  $action=req('action');
  if($action==='choose'){
   $id=n('company_id',1);
   if(!member($db,$id))throw new RuntimeException('Não tens acesso a esta empresa.');
   $_SESSION['cid']=$id;
   unset($_SESSION['onboarding_intent']);
   header('Location: index.php?page=dashboard');exit;
  }
  if($action==='create'){
   $name=req('name');$nif=req('nif');
   if(mb_strlen($name)<2||mb_strlen($name)>120)throw new RuntimeException('Indica um nome de empresa entre 2 e 120 caracteres.');
   if(!valid_nif($nif))throw new RuntimeException('NIF inválido. Verifica os nove algarismos ou deixa o campo vazio.');
   require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_setup($db);
   $db->beginTransaction();
   $db->prepare('INSERT INTO companies(name,nif) VALUES(?,?)')->execute([$name,$nif]);
   $id=(int)$db->lastInsertId();
   $db->prepare('INSERT INTO members(user_id,company_id) VALUES(?,?)')->execute([user_id(),$id]);
   ensure_company_roles($db,$id);
   ug_subscription_begin_trial($db,$id);
   $db->commit();
   $_SESSION['cid']=$id;
   unset($_SESSION['onboarding_intent']);
   header('Location: index.php?page=dashboard');exit;
  }
  throw new RuntimeException('Operação inválida.');
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}
$list=all($db,'SELECT c.* FROM companies c INNER JOIN members m ON m.company_id=c.id WHERE m.user_id=? ORDER BY c.name',[user_id()]);
$account=one($db,'SELECT name,email FROM users WHERE id=?',[user_id()]);
?>
<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Empresas · UniGest</title><?=g360_styles('companies')?><link rel="icon" href="favicon.png?v=41" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=41"></head>
<body class="auth-body v24-companies-body" data-page="companies">
<header class="v24-public-top"><a href="index.php" class="brand"><img src="logo.png?v=41" class="g360-brand-full" width="204" height="68" alt="UniGest" loading="eager" decoding="async"></a><div class="v24-public-actions"><span class="v24-user-chip"><?=h($account['name']??'Utilizador')?></span><a class="btn btn-outline" href="account_switch.php">⇄ Trocar de conta</a></div></header>
<main class="v24-company-layout">
<div class="v24-page-heading"><span class="eyebrow">O TEU ESPAÇO DE TRABALHO</span><h1>Escolhe como queres começar</h1><p>As empresas são independentes. Só tens acesso às empresas a que pertences.</p></div>
<?php if($error):?><div class="alert error" role="alert"><?=h($error)?></div><?php endif;?>
<?php if($list):?><section class="v24-company-section"><div class="v24-section-title"><div><h2>As tuas empresas</h2><p>Entra num estabelecimento a que já tens acesso.</p></div><span class="tag"><?=count($list)?> empresa(s)</span></div>
<div class="v24-company-tiles"><?php foreach($list as $co):?><form method="post" class="v24-company-tile"><input type="hidden" name="csrf" value="<?=h(token())?>"><input type="hidden" name="action" value="choose"><input type="hidden" name="company_id" value="<?=(int)$co['id']?>"><div class="v24-company-logo">◈</div><h3><?=h($co['name'])?></h3><p>NIF <?=h($co['nif']?:'por definir')?></p><button class="btn btn-main btn-block">Abrir painel →</button></form><?php endforeach;?></div>
</section><?php endif;?>
<section class="v24-company-section"><div class="v24-onboard-tabs" role="tablist" aria-label="Opções de adesão"><a role="tab" aria-selected="<?=$view==='create'?'true':'false'?>" class="<?=$view==='create'?'selected':''?>" href="companies.php?view=create">＋ Criar uma empresa</a><a role="tab" aria-selected="<?=$view==='join'?'true':'false'?>" class="<?=$view==='join'?'selected':''?>" href="companies.php?view=join">⇄ Entrar numa empresa</a></div>
<?php if($view==='join'):?>
<div class="v24-join-guide"><div class="v24-join-icon">✉</div><div><span class="eyebrow">ADESÃO COMO COLABORADOR</span><h2>O dono adiciona-te pelo email</h2><p>Não precisas de criar outra empresa ou partilhar a tua palavra-passe. O responsável pela empresa deve seguir estas instruções:</p><ol><li>Entra na conta do dono, abre o painel e escolhe <strong>Empresa e equipa</strong>.</li><li>Na secção <strong>Colaboradores</strong>, seleciona <strong>Adicionar pelo email</strong>.</li><li>Introduz exatamente o teu email e escolhe o cargo e as permissões adequadas.</li><li>Depois de guardar, atualiza esta página para encontrares a empresa em <strong>As tuas empresas</strong>.</li></ol><div class="v24-email-share"><div><small>O email da tua conta</small><strong id="v24-account-email"><?=h($account['email']??'')?></strong></div><button class="btn btn-outline" type="button" data-copy-account-email>Copiar email</button></div><p class="hint">O email já tem de estar registado na UniGest. O convite não concede acesso automaticamente; é o dono quem autoriza.</p></div></div>
<?php else:?>
<div class="v24-create-area"><div class="v24-create-copy"><span class="eyebrow">PARA DONOS E RESPONSÁVEIS</span><h2>Começa com a tua empresa</h2><p>Cria a empresa e experimenta o UniGest durante 14 dias. Depois, escolhe o plano adequado ao número de produtos e às ferramentas que pretendes utilizar.</p><div class="v24-checkline">✓ Serás o primeiro dono da empresa</div><div class="v24-checkline">✓ Permissões personalizáveis para a equipa</div><div class="v24-checkline">✓ Dados separados de outras empresas</div><div class="v24-checkline">✓ 14 dias de demonstração com as funções Essenciais</div><a class="ug-plans-inline-link" href="plans.php">Ver todos os planos e preços →</a></div>
<form method="post" class="v24-create-form"><input type="hidden" name="csrf" value="<?=h(token())?>"><input type="hidden" name="action" value="create"><label>Nome da empresa<input name="name" minlength="2" maxlength="120" autocomplete="organization" required placeholder="Ex.: Restaurante Central"></label><label>NIF <span class="muted">(opcional)</span><input name="nif" pattern="[0-9]{9}" inputmode="numeric" maxlength="9" placeholder="123456789"></label><button type="submit" class="btn btn-main btn-block btn-xl">Criar empresa →</button></form></div>
<?php endif;?></section>
</main><?=g360_scripts('companies')?><?php require __DIR__.'/app/view/account-modal.php'; ?>
</body></html>
