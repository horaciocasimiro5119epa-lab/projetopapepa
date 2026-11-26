<?php
// Public example: shared views, never sessions or private database queries.
function h($x):string{return htmlspecialchars((string)$x,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function money($x):string{return number_format($x/100,2,',',' ').' €';}
require __DIR__.'/app/view/assets.php';require_once __DIR__.'/app/view/icons.php';
$ugPreview=true;$totalProducts=68;$ugCustomers=124;$monthSales=1824500;$cashExpected=38650;
$daySales=124850;$currentRevenueCount=38;$openOrders=8;$low=3;$till=true;
$ugSeries=[['label'=>'06/11','cents'=>48200],['label'=>'07/11','cents'=>73500],['label'=>'08/11','cents'=>65200],['label'=>'09/11','cents'=>89400],['label'=>'10/11','cents'=>54100],['label'=>'11/11','cents'=>96800],['label'=>'12/11','cents'=>124850]];
$ugRecent=[['amount_cents'=>2450,'method'=>'cartão','created_at'=>'2026-11-12 14:32'],['amount_cents'=>1280,'method'=>'dinheiro','created_at'=>'2026-11-12 14:18'],['amount_cents'=>3670,'method'=>'cartão','created_at'=>'2026-11-12 14:06']];
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dashboard de exemplo | UniGest</title><?=g360_styles('dashboard')?><style>body[data-embedded]{min-height:0}body[data-embedded] .ug-preview-layout{min-height:0}body[data-embedded] .ug-preview-nav{position:static;min-height:0}body[data-embedded] .ug-preview-head{flex-wrap:wrap}@media(max-width:760px){body[data-embedded] .ug-preview-nav{display:none}}</style></head>
<body data-page="dashboard" class="ug-preview-body" <?=isset($_GET['embed'])?'data-embedded="true"':''?>><div class="ug-preview-layout">
<aside class="ug-preview-nav"><img src="logo.png" alt="UniGest" width="165"><div class="ug-preview-company"><strong>Café Central</strong><small>Empresa de demonstração</small></div><nav aria-label="Áreas do painel de demonstração"><a href="#ug-summary"><?=ug_icon('dashboard')?>Visão geral</a><a href="#ug-revenue"><?=ug_icon('chart')?>Recebimentos</a><a href="#ug-business"><?=ug_icon('box')?>Estabelecimento</a><a href="#ug-recent"><?=ug_icon('orders')?>Movimentos</a></nav><p>Explora os indicadores e seleciona um dia no gráfico. Todos os valores são fictícios.</p></aside>
<main class="ug-preview-shell"><header class="ug-preview-head"><strong>Visão geral</strong><span>Demonstração interativa · Dados fictícios</span><?php if(!isset($_GET['embed'])):?><a href="index.php">Voltar ao início</a><?php endif;?></header>
<?php require __DIR__.'/app/view/dashboard-summary.php';require __DIR__.'/app/view/dashboard-insights.php';?>
<p class="ug-preview-disclaimer">Os mesmos componentes do painel real, com valores de exemplo. Os módulos e permissões da tua conta determinam o que aparece na aplicação.</p></main></div>
<script src="<?=g360_asset('js','workspace-v62')?>" defer></script></body></html>
