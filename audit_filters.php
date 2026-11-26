<?php
/** Pesquisa segura e partilhada pela interface e pela exportação de logs. */
declare(strict_types=1);
function activity_query_filters(PDO $db,int $cid,array $params):array {
 $conditions=['l.company_id=?'];$values=[$cid];
 $entities=['products','dining_tables','reservations','orders','payments','stock_moves','tills','cash_movements','floor_settings','floor_objects','roles','members','companies','customers','order_items','fiscal_connections','fiscal_documents','ai_product_history'];
 $entity=trim((string)($params['entity']??''));
 if($entity!=='' && in_array($entity,$entities,true)){$conditions[]='l.entity=?';$values[]=$entity;}else{$entity='';}
 $operation=strtoupper(trim((string)($params['operation']??'')));
 if(!in_array($operation,['INSERT','UPDATE','DELETE'],true))$operation='';
 if($operation!==''){$conditions[]='l.operation=?';$values[]=$operation;}
 $actors=all($db,'SELECT DISTINCT u.id,u.name FROM audit_logs l JOIN users u ON u.id=l.user_id WHERE l.company_id=? ORDER BY u.name',[$cid]);
 $actor=(int)($params['actor']??0);
 if($actor>0){$conditions[]='l.user_id=?';$values[]=$actor;}
 $dayFrom=trim((string)($params['from']??''));$dayTo=trim((string)($params['to']??''));
 $timeFrom=trim((string)($params['from_time']??''));$timeTo=trim((string)($params['to_time']??''));
 foreach([$dayFrom,$dayTo] as $day){if($day!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day) || !($d=DateTimeImmutable::createFromFormat('!Y-m-d',$day)) || $d->format('Y-m-d')!==$day))throw new RuntimeException('Data do filtro de logs inválida.');}
 foreach([$timeFrom,$timeTo] as $time){if($time!=='' && (!preg_match('/^\d{2}:\d{2}$/',$time) || !($d=DateTimeImmutable::createFromFormat('!H:i',$time)) || $d->format('H:i')!==$time))throw new RuntimeException('Hora do filtro inválida. Usa HH:MM.');}
 if($timeFrom!=='' && $dayFrom==='')$dayFrom=date('Y-m-d');
 if($timeTo!=='' && $dayTo==='')$dayTo=$dayFrom?:date('Y-m-d');
 if($dayFrom!=='' && $dayTo!=='' && $dayFrom>$dayTo)throw new RuntimeException('A data inicial é posterior à final.');
 if($dayFrom!==''){$conditions[]='l.created_at>=?';$values[]=$dayFrom.' '.($timeFrom?:'00:00').':00';}
 if($dayTo!==''){$conditions[]='l.created_at<=?';$values[]=$dayTo.' '.($timeTo?:'23:59').':59';}
 $term=mb_substr(trim((string)($params['q']??'')),0,90);
 if($term!==''){$conditions[]='(l.details LIKE ? OR l.source LIKE ? OR l.entity LIKE ?)';$like='%'.$term.'%';array_push($values,$like,$like,$like);}
 return ['where'=>implode(' AND ',$conditions),'args'=>$values,'entity'=>$entity,'operation'=>$operation,'actors'=>$actors,'actor'=>$actor,'from'=>$dayFrom,'to'=>$dayTo,'from_time'=>$timeFrom,'to_time'=>$timeTo,'q'=>$term];
}
