<?php require __DIR__.'/bootstrap.php';$_SESSION=[];
setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
session_regenerate_id(true);header('Location: index.php');exit;
