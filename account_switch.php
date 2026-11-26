<?php
require __DIR__.'/bootstrap.php';
must_login();
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  check_token();
  $action=req('action');
  $target=n('account_id',1);
  $known=available_accounts();
  if(!isset($known[$target]))throw new RuntimeException('Esta conta deixou de estar autenticada. Entra novamente.');
  if($action==='switch'){
   if(!one($db,'SELECT id FROM users WHERE id=?',[$target]))throw new RuntimeException('Conta não encontrada.');
   activate_authenticated_account($target,true);
   header('Location: companies.php');exit;
  }
  if($action==='remove'){
   if($target===user_id())throw new RuntimeException('Para remover a conta ativa, termina sessão ou troca primeiro de conta.');
   unset($_SESSION['authenticated_accounts'][$target]);
   flash('Conta desligada deste navegador.');
   header('Location: account_switch.php');exit;
  }
  throw new RuntimeException('Operação não reconhecida.');
 }catch(Throwable $e){$error=$e->getMessage();}
}
$known=available_accounts();
if(!isset($known[user_id()])){remember_authenticated_account(user_id());$known=available_accounts();}
$accounts=[];
foreach(array_keys($known) as $id){$acc=one($db,'SELECT id,name,email FROM users WHERE id=?',[$id]);if($acc)$accounts[]=$acc;}
$flash=$_SESSION['flash']??null;
// Compatibilidade com mensagens de versões antigas: nunca tratar texto como array.
if(is_string($flash))$flash=['text'=>$flash,'type'=>'ok'];
elseif(is_array($flash))$flash=['text'=>(string)($flash['text']??''),'type'=>in_array($flash['type']??'ok',['ok','error','warning'],true)?$flash['type']:'ok'];
else $flash=null;unset($_SESSION['flash']);
?>
<!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Trocar de conta · UniGest</title><?=g360_styles('accounts')?><link rel="icon" href="favicon.png?v=41" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=41"></head><body class="auth-body v24-accounts-page" data-page="accounts">
<header class="v24-public-top"><a href="index.php" class="brand"><img src="logo.png?v=41" class="g360-brand-full" width="204" height="68" alt="UniGest" loading="eager" decoding="async"></a><div class="v24-public-actions"><a href="companies.php" class="btn btn-outline">Empresas</a><a href="index.php" class="btn btn-outline">Página inicial</a></div></header>
<main class="v24-accounts-layout"><span class="eyebrow">GESTÃO DE SESSÕES</span><h1>Trocar de conta</h1><p>Trabalha com várias contas no mesmo navegador. Apenas aparecem contas em que já iniciaste sessão e que ainda estão autorizadas nesta sessão.</p>
<?php if($error):?><div class="alert error" role="alert"><?=h($error)?></div><?php endif;?>
<?php if($flash):?><div class="alert <?=h($flash['type'])?>"><?=h($flash['text'])?></div><?php endif;?>
<div class="v24-account-list"><?php foreach($accounts as $acc):$active=(int)$acc['id']===user_id();?><article class="v24-account-entry <?=$active?'active':''?>"><div class="v24-account-avatar"><?=h(mb_substr($acc['name'],0,1))?></div><div class="v24-account-info"><strong><?=h($acc['name'])?></strong><small><?=h($acc['email'])?></small><?php if($active):?><span class="v24-current-label">● Conta atual</span><?php endif;?></div><div class="v24-account-actions">
<?php if($active):?><a class="btn btn-outline" href="companies.php">Continuar</a><?php else:?><form method="post"><input type="hidden" name="csrf" value="<?=h(token())?>"><input type="hidden" name="action" value="switch"><input type="hidden" name="account_id" value="<?=(int)$acc['id']?>"><button class="btn btn-main" type="submit">Utilizar conta</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h(token())?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="account_id" value="<?=(int)$acc['id']?>"><button class="btn btn-outline" type="submit" aria-label="Retirar conta do navegador">Retirar</button></form><?php endif;?></div></article><?php endforeach;?></div>
<a class="v24-add-account" href="auth.php?mode=login&add=1"><span>＋</span><div><strong>Adicionar outra conta</strong><small>Inicia sessão com outro email. A conta atual mantém-se disponível para troca.</small></div><span aria-hidden="true">→</span></a>
<p class="v24-session-note">Por segurança, as contas adicionais deixam de estar disponíveis após 12 horas sem nova autenticação. Terminar sessão desliga todas as contas deste navegador.</p>
</main><?=g360_scripts('accounts')?></body></html>
