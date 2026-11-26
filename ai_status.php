<?php
/** Estado da integração Gemini, sem testar a chave nem expor valores privados. */
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Método inválido.');
    if($method==='POST')check_token();
    must_company($db);
    $cid=company_id();
    require_permission($db,$cid,'ai.use');
    require_once __DIR__.'/app/domain/company_features.php';
    if(!g360_feature($db,$cid,'ai_enabled')){
        http_response_code(403);
        echo json_encode(['enabled'=>false,'message'=>'O assistente está desligado nesta empresa.'],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $local=is_file(__DIR__.'/config.local.php')?require __DIR__.'/config.local.php':[];
    if(!is_array($local))$local=[];
    $key=trim(g360_env('GEMINI_API_KEY',(string)($local['gemini_api_key']??'')));
    if($key==='COLOCA_A_TUA_CHAVE_AQUI')$key='';
    $provider=g360_env('AI_PROVIDER',(string)($local['ai_provider']??'gemini'));
    $ready=$key!=='' && $provider==='gemini' && extension_loaded('curl');
    if($method==='POST'){
        if(!$ready){
            http_response_code(400);
            echo json_encode(['enabled'=>true,'configured'=>false,'local'=>true,'message'=>'Não é possível testar o Gemini sem uma chave válida e a extensão PHP cURL ativa.'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        $model=g360_env('GEMINI_MODEL',(string)($local['gemini_model']??'gemini-2.5-flash-lite'));
        if(!preg_match('/^[a-zA-Z0-9._-]+$/D',$model))throw new RuntimeException('Modelo de IA inválido.');
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent';
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Não foi possível iniciar a ligação HTTP.');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-goog-api-key: '.$key],
            CURLOPT_POSTFIELDS=>json_encode(['contents'=>[['role'=>'user','parts'=>[['text'=>'Responde apenas OK.']]]],'generationConfig'=>['maxOutputTokens'=>32]],JSON_UNESCAPED_UNICODE)
        ]);
        $raw=curl_exec($ch);
        $http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        if($raw!==false && $http>=200 && $http<300){
            echo json_encode(['enabled'=>true,'configured'=>true,'verified'=>true,'local'=>true,'message'=>'Ligação Gemini confirmada. O servidor recebeu uma resposta do modelo.'],JSON_UNESCAPED_UNICODE);
            exit;
        }
        http_response_code(502);
        $error=match(true){$http===401||$http===403=>'A chave Gemini não foi aceite.',
            $http===404=>'O modelo configurado não foi encontrado.',
            $http===429=>'A quota Gemini está esgotada neste momento.',
            $http>=500=>'O serviço Gemini está temporariamente indisponível.',
            default=>'Não foi possível contactar o Gemini. Verifica SSL, rede e configuração cURL no servidor.'};
        echo json_encode(['enabled'=>true,'configured'=>true,'verified'=>false,'message'=>$error],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $msg=$ready?'Gemini configurado no servidor. A ligação é testada ao enviar uma mensagem.'
        :($provider!=='gemini'?'O fornecedor IA configurado não é suportado nesta versão.'
        :($key===''?'Modo local disponível. Para ativar a IA generativa, configura a chave GEMINI_API_KEY no servidor.'
        :'Chave encontrada, mas é necessário ativar a extensão PHP cURL.'));
    echo json_encode(['enabled'=>true,'configured'=>$ready,'local'=>true,'message'=>$msg],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
}catch(Throwable $e){
    if(http_response_code()<400)http_response_code(400);
    echo json_encode(['enabled'=>false,'message'=>'Não foi possível verificar o estado da IA. Confirma a sessão, as permissões e o PHP.'],JSON_UNESCAPED_UNICODE);
}
