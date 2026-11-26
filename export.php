<?php
/** CSV internos com prevenção contra injeção de fórmulas em folhas de cálculo. */
require __DIR__.'/bootstrap.php';
must_company($db);
require_permission($db,company_id(),'exports.use');
require_once __DIR__.'/app/domain/company_features.php';
if(!g360_feature($db,company_id(),'exports_enabled')){http_response_code(403);exit('Exportações desativadas nesta empresa.');}
$type=(string)($_GET['type']??'');
$exports=[
    'products'=>['products',['name','sku','category','price_cents','cost_cents','qty','min_qty']],
    'customers'=>['customers',['name','nif','phone','email']],
    'reservations'=>['reservations',['customer','table_id','guests','start_at','status','notes']],
    'orders'=>['orders',['id','origin','status','created_at','total_cents']],
    'payments'=>['payments',['order_id','method','amount_cents','created_at']],
    'stock'=>['stock_moves',['product_id','delta','reason','created_at']],
];
if(!isset($exports[$type])){http_response_code(400);exit('Exportação não suportada.');}
$requiredFeature=g360_export_feature($type);
if($requiredFeature && !g360_feature($db,company_id(),$requiredFeature)){http_response_code(403);exit('Os dados deste módulo não estão disponíveis nesta empresa.');}
[$table,$columns]=$exports[$type];
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="gestao360_'.$type.'_'.date('Ymd').'.csv"');
header('Cache-Control: no-store');
$out=fopen('php://output','w');
fwrite($out,"\xEF\xBB\xBF");
fputcsv($out,$columns,';','"','');
foreach(all($db,'SELECT '.implode(',',$columns).' FROM '.$table.' WHERE company_id=?',[company_id()]) as $row){
    $safe=[];
    foreach($columns as $column){
        $value=$row[$column];
        // Campos textuais de origem externa nunca devem ser interpretados como fórmulas.
        if(is_string($value) && preg_match('/^\s*[=+@-]/u',$value) && !preg_match('/^-?\d+(?:\.\d+)?$/',$value)) $value="'".$value;
        $safe[]=$value;
    }
    fputcsv($out,$safe,';','"','');
}
fclose($out);
