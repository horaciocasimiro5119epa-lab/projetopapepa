<?php
/** Pode ser aberto antes da base de dados: /diagnostico.php */
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__.'/app/view/assets.php';
require_once __DIR__.'/app/config/env.php';
$driver=g360_env('DB_DRIVER','sqlite');
$checks=[
    ['PHP 8.1 ou superior',PHP_VERSION_ID>=80100,PHP_VERSION],
    ['PDO e '.$driver,extension_loaded('pdo_'.$driver) && class_exists('PDO'),'Necessário para a base de dados selecionada; MySQL requer importar o SQL de instalação'],
    ['Extensão mbstring',extension_loaded('mbstring'),'Recomendado para acentuação e pesquisas'],
    ['Extensão cURL',extension_loaded('curl'),'Necessário apenas para a IA externa'],
    ['Pasta storage gravável',is_dir(__DIR__.'/storage')&&is_writable(__DIR__.'/storage'),'Necessário para criar a base de dados'],
    ['CSS da aplicação',is_file(__DIR__.'/assets/css/core.css'),'Ficheiro css externo'],
    ['JavaScript da aplicação',is_file(__DIR__.'/assets/js/core.js'),'Ficheiro js externo'],
    ['Ecrã de login/registo',is_file(__DIR__.'/auth.php')&&is_file(__DIR__.'/index.php'),'Acesso pelo index.php'],
];
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico · UniGest</title><?=g360_styles('diagnostico')?><link rel="icon" href="favicon.png?v=37" type="image/png"><link rel="apple-touch-icon" href="apple-touch-icon.png?v=37"></head><body><main><h1>✦ Diagnóstico UniGest</h1><p>Este ecrã mostra os requisitos da instalação. Se o login não abrir, resolve primeiro os pontos assinalados.</p><table><?php foreach($checks as [$name,$ok,$detail]):?><tr><td><b class="<?=$ok?'ok':'no'?>"><?=$ok?'✓':'✕'?> <?=htmlspecialchars($name,ENT_QUOTES,'UTF-8')?></b></td><td><?=htmlspecialchars($detail,ENT_QUOTES,'UTF-8')?></td></tr><?php endforeach;?></table><p>No XAMPP: abre <code>php.ini</code>, ativa <code>extension=pdo_sqlite</code>, <code>extension=mbstring</code> e <code>extension=curl</code> se necessário, reinicia o Apache e volta a abrir <a href="index.php">index.php</a>.</p><p><small>Este diagnóstico não mostra nem verifica a chave de API por motivos de segurança.</small></p></main><?=g360_scripts('diagnostico')?></body></html>
