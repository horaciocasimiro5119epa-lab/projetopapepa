<?php
/**
 * Ecrã de entrada reutilizado pelo index.php (convidados) e por /auth.php.
 */
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/app/domain/terms.php';
ug_terms_setup($db);
$addingAccount=(($_GET['add']??'')==='1'||($_POST['add_account']??'')==='1');
if(user_id() && $addingAccount)remember_authenticated_account(user_id());
if(user_id() && !$addingAccount){
    header('Location: '.(company_id() && member($db,company_id()) ? 'index.php' : 'companies.php'));
    exit;
}
$error='';
$mode=(($_GET['mode']??'')==='register')?'register':'login';
$email='';
$name='';
$accountIntent='create';
if($_SERVER['REQUEST_METHOD']==='POST'){
    $mode=req('action')==='register'?'register':'login';
    $email=mb_strtolower(req('email'));
    $name=req('name');
    $accountIntent=($_POST['account_intent']??'create')==='join'?'join':'create';
    try{
        check_token();
        if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) throw new RuntimeException('Introduz um email válido.');
        $password=(string)($_POST['password']??'');
        if($mode==='register'){
            ug_terms_require($_POST);
            if(mb_strlen($name)<2 || mb_strlen($name)>120) throw new RuntimeException('Indica um nome entre 2 e 120 caracteres.');
            if(strlen($password)<8 || strlen($password)>1024) throw new RuntimeException('A palavra-passe deve ter entre 8 e 1024 caracteres.');
            $confirmation=(string)($_POST['password_confirm']??'');
            if(!hash_equals($password,$confirmation)) throw new RuntimeException('As palavras-passe não coincidem.');
            $db->beginTransaction();
            $db->prepare('INSERT INTO users(email,password,name) VALUES(?,?,?)')->execute([$email,password_hash($password,PASSWORD_DEFAULT),$name]);
            $id=(int)$db->lastInsertId();
            ug_terms_record($db,$id);
            $db->commit();
        }else{
            if(!auth_attempt_allowed($db,$email)) throw new RuntimeException('Demasiadas tentativas de acesso. Aguarda 15 minutos antes de tentar novamente.');
            $user=one($db,'SELECT id,password FROM users WHERE email=?',[$email]);
            if(!$user || !password_verify($password,(string)$user['password'])){
                auth_failed_attempt($db,$email);
                throw new RuntimeException('Email ou palavra-passe incorretos.');
            }
            auth_clear_failed_attempts($db,$email);
            $id=(int)$user['id'];
        }
        activate_authenticated_account($id);
        if(($_POST['remember_session']??'')==='1'){
            $_SESSION['ug_remember_until']=time()+7*86400;
            $secure=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off');
            setcookie(session_name(),session_id(),['expires'=>$_SESSION['ug_remember_until'],'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        }
        if($mode==='register')$_SESSION['onboarding_intent']=$accountIntent;
        else unset($_SESSION['onboarding_intent']);
        header('Location: companies.php');
        exit;
    }catch(PDOException $e){
        if($db->inTransaction())$db->rollBack();
        $error='Este email já está registado, ou ocorreu um erro na base de dados.';
    }catch(Throwable $e){
        if($db->inTransaction())$db->rollBack();
        $error=$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0b0b12">
    <title><?= $mode==='register'?'Criar conta':'Iniciar sessão' ?> | UniGest</title>
    <?=g360_styles('auth')?>
<link rel="icon" href="favicon.png?v=51" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=51"></head>
<body class="auth-body" data-page="auth" data-auth-mode="<?=h($mode)?>">
<div class="auth-layout v49-auth-shell">
  <section class="auth-visual" aria-label="Apresentação UniGest">
    <a class="auth-logo" href="index.php" title="Voltar à página inicial"><img src="logo.png?v=51" class="g360-brand-full" width="185" height="60" alt="UniGest" loading="eager"></a>
    <div class="auth-hero">
      <span class="auth-kicker"><span class="auth-kicker-dot"></span> A TUA PLATAFORMA DE GESTÃO</span>
      <h1>Tudo organizado.<br><em>Mais tempo para o negócio.</em></h1>
      <p>Organiza vendas, pedidos, reservas, produtos e equipa numa plataforma simples, rápida e adaptável ao teu estabelecimento.</p>
    </div>
    <div class="auth-spotlight" aria-label="Funcionalidades do UniGest">
      <div class="auth-spotlight-top"><span>O teu negócio, organizado</span><span class="auth-spotlight-status"><i></i> Painel integrado</span></div>
      <div class="auth-spotlight-grid">
        <div><span class="auth-spotlight-icon">▦</span><strong>Visão geral</strong><small>Indicadores num só lugar</small></div>
        <div><span class="auth-spotlight-icon">▣</span><strong>Caixa POS</strong><small>Vendas e pagamentos</small></div>
        <div><span class="auth-spotlight-icon">◷</span><strong>Reservas</strong><small>Confirmações e horários</small></div>
      </div>
      <p>Interface organizada e adaptável aos módulos do teu negócio.</p>
    </div>
    <p class="auth-foot">UniGest · Gestão simples, num só sistema</p>
  </section>
  <main class="auth-form-side" id="auth-content">
    <div class="auth-card">
      <div class="auth-card-top"><span class="mini-logo">A TUA CONTA</span><span class="tag"><span aria-hidden="true">●</span> ACESSO PROTEGIDO</span></div>
      <div class="auth-card-intro">
        <h2 id="auth-title"><?= $mode==='register'?'Cria a tua conta':'Bem-vindo de volta' ?></h2>
        <p class="muted" id="auth-description"><?= $mode==='register'?'Regista-te para criar a tua empresa ou entrar numa equipa.':'Acede ao teu espaço de trabalho UniGest.' ?></p>
      </div>
      <?php if($error): ?><div class="alert error" role="alert"><?=h($error)?></div><?php endif; ?>
      <div class="tabs" role="group" aria-label="Escolher tipo de acesso">
        <button class="tab <?=$mode==='login'?'active':''?>" type="button" data-auth-tab="login" aria-pressed="<?=$mode==='login'?'true':'false'?>">Iniciar sessão</button>
        <button class="tab <?=$mode==='register'?'active':''?>" type="button" data-auth-tab="register" aria-pressed="<?=$mode==='register'?'true':'false'?>">Criar conta</button>
      </div>
      <form method="post" action="auth.php<?=$addingAccount?'?add=1':''?>" id="auth-form" autocomplete="on">
        <input type="hidden" name="add_account" value="<?=$addingAccount?'1':'0'?>">
        <input type="hidden" name="csrf" value="<?=h(token())?>">
        <input type="hidden" name="action" id="auth-action" value="<?=h($mode)?>">
        <div id="name-field" <?=$mode==='register'?'':'hidden'?>>
          <label for="auth-name">Nome completo</label>
          <input id="auth-name" type="text" name="name" maxlength="120" minlength="2" placeholder="O teu nome" autocomplete="name" value="<?=h($name)?>" <?=$mode==='register'?'required':''?>>
        </div>
        <div>
          <label for="auth-email">Email</label>
          <input id="auth-email" type="email" name="email" maxlength="190" placeholder="nome@empresa.pt" autocomplete="email" value="<?=h($email)?>" required>
        </div>
        <div>
          <label for="auth-password">Palavra-passe</label>
          <div class="password-wrap"><input id="auth-password" type="password" name="password" minlength="8" maxlength="1024" required autocomplete="<?=$mode==='register'?'new-password':'current-password'?>" placeholder="Mínimo de 8 caracteres"><button type="button" data-show-password="auth-password" aria-label="Mostrar palavra-passe" aria-pressed="false"><svg class="auth-eye-icon" aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="auth-eye-slash" d="M3 21 21 3"/></svg></button></div>
        </div>
        <div id="confirm-field" <?=$mode==='register'?'':'hidden'?>>
          <label for="auth-confirm">Confirmar palavra-passe</label>
          <div class="password-wrap"><input id="auth-confirm" type="password" name="password_confirm" minlength="8" maxlength="1024" placeholder="Repete a palavra-passe" autocomplete="new-password" <?=$mode==='register'?'required':''?>><button type="button" data-show-password="auth-confirm" aria-label="Mostrar palavra-passe" aria-pressed="false"><svg class="auth-eye-icon" aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-6.5 10-6.5S22 12 22 12s-3.6 6.5-10 6.5S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path class="auth-eye-slash" d="M3 21 21 3"/></svg></button></div>
        </div>
        <fieldset class="v24-register-options" id="account-intent-field" <?=$mode==='register'?'':'hidden'?>>
          <legend>O que pretendes fazer primeiro?</legend>
          <label class="v24-intent-card"><input type="radio" name="account_intent" value="create" <?=$accountIntent==='create'?'checked':''?>><span><strong>Criar a minha empresa</strong><small>Cria um estabelecimento e começa um período de teste de 14 dias.</small></span></label>
          <label class="v24-intent-card"><input type="radio" name="account_intent" value="join" <?=$accountIntent==='join'?'checked':''?>><span><strong>Entrar numa empresa</strong><small>O responsável da empresa autoriza o teu acesso pelo email.</small></span></label>
        </fieldset>
        <div id="terms-field" <?=$mode==='register'?'':'hidden'?>><label class="ug-terms-check"><input type="checkbox" name="accept_terms" id="accept-terms" value="1" <?=$mode==='register'?'required':''?>> <span>Li e aceito os <a href="terms.php" target="_blank" rel="noopener">Termos e Condições do UniGest</a> (abre num novo separador).</span></label><input type="hidden" name="terms_version" value="<?=h(UG_TERMS_VERSION)?>"></div>
        <label class="ug-remember-option"><input type="checkbox" name="remember_session" value="1"> Manter sessão iniciada durante 7 dias neste navegador</label>
        <button class="btn btn-main btn-block" id="auth-submit" type="submit"><?= $mode==='register'?'Criar conta →':'Entrar no UniGest →' ?></button>
        <div id="auth-login-benefits" class="auth-login-benefits" <?=$mode==='register'?'hidden':''?>><span>◈ Acesso aos módulos autorizados</span><span>✓ As tuas empresas e permissões são mantidas</span></div>
      </form>
      <p class="auth-note"><span aria-hidden="true">◈</span> Os teus dados de acesso são privados. As permissões dependem da empresa a que pertences. <a href="plans.php">Conhecer planos e preços</a>.</p>
      <a class="v24-back-home" href="index.php">← Voltar à página inicial</a>
    </div>
  </main>
</div>
<?=g360_scripts('auth')?>
</body>
</html>
