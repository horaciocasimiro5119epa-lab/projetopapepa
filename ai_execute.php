<?php
require __DIR__.'/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
    must_company($db);
    require_permission($db,company_id(),'ai.use');
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Método inválido.');
    check_token();
    $nonce=(string)($_POST['nonce']??'');
    $proposal=$_SESSION['ai_proposals'][$nonce]??null;
    unset($_SESSION['ai_proposals'][$nonce]);
    if(!$proposal || $proposal['expires']<time() || $proposal['company_id']!==company_id()) throw new RuntimeException('Proposta expirada. Pede novamente à IA.');
    $actions=$proposal['actions']??(isset($proposal['action'])?[$proposal['action']]:[]);
    if(!is_array($actions)||!$actions||count($actions)>8) throw new RuntimeException('Lote inválido.');
    $cid=company_id();require_once __DIR__.'/app/domain/company_features.php';if(!g360_feature($db,$cid,'ai_enabled')){http_response_code(403);throw new RuntimeException('A IA foi desativada nas opções desta empresa.');}
    $replies=[];
    $db->beginTransaction();
    foreach($actions as $action){
    if(!is_array($action)) throw new RuntimeException('Ação inválida.');
    $kind=$action['kind']??'';
    $requiredModule=g360_ai_module((string)$kind,(string)($action['topic']??''));
    if($requiredModule && !g360_feature($db,$cid,$requiredModule))throw new RuntimeException('A operação pertence a um módulo desativado nesta empresa.');
    require_permission($db,$cid,ai_action_permission($kind,$action));
    if($kind==='report' && ($action['topic']??'')==='tables')require_permission($db,$cid,'reports.view');
    if(in_array($kind,['new_order','duplicate_order'],true))require_permission($db,$cid,'pos.use');
    if(in_array($kind,['new_order','duplicate_order'],true) && !till($db,$cid))
        throw new RuntimeException('Abre primeiro a caixa para criar ou copiar pedidos.');
    if($kind==='report'){
        $topic=$action['topic']; $period=$action['period'];
        $from=match($period){'week'=>date('Y-m-d',strtotime('monday this week')),'month'=>date('Y-m-01'),default=>date('Y-m-d')};
        $to=date('Y-m-d');
        if(isset($action['date_from'])){$from=$action['date_from'];$to=$action['date_to'];}
        [$from,$to,$endExclusive]=valid_date_range($from,$to);
        $lines=[];
        if($topic==='availability'){
            $time=$action['time']??'20:00';$guests=(int)($action['guests']??1);
            $periodDays=(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
            if($periodDays>30)throw new RuntimeException('A disponibilidade só pode ser consultada até 31 dias.');
            $daysWithAvailability=[];
            for($d=new DateTimeImmutable($from);$d<=new DateTimeImmutable($to);$d=$d->modify('+1 day')){
                $slot=g360_availability($db,$cid,$d->format('Y-m-d'),$time,$guests);
                $names=array_map(static fn($t)=>$t['name'].' com '.$t['seats'].' lugares',$slot['available']);
                $countFree=count($names);
                if($countFree)$daysWithAvailability[]=$d->format('d/m/Y');
                $lines[]=$d->format('d/m/Y').' às '.$time.': '.$countFree.' '.($countFree===1?'mesa disponível':'mesas disponíveis').' para '.$guests.' '.($guests===1?'pessoa':'pessoas').'.'.($names?' Mesas livres: '.implode(', ',$names).'.':' Nenhuma mesa disponível neste horário.');
            }
            if($periodDays>0)$lines[]=$daysWithAvailability?'Dias com disponibilidade no horário solicitado: '.implode(', ',$daysWithAvailability).'.':'Não existem mesas disponíveis no horário solicitado durante os dias analisados.';
            $lines[]='A disponibilidade corresponde à hora indicada, com bloqueio aproximado de duas horas por reserva. Não representa disponibilidade garantida para o dia inteiro.';
        }elseif($topic==='profit'){
            require_permission($db,$cid,'profits.view');
            $p=g360_profit_period($db,$cid,$from,$endExclusive);
            $lines[]='Período de '.$from.' até '.$to.'.';
            $lines[]='Recebimentos de vendas: '.money($p['revenue']).'.';
            $lines[]='Custo conhecido dos produtos vendidos: '.money($p['cost']).'.';
            $lines[]=$p['margin']===null?'Margem não apurável porque existem '.$p['unknown_units'].' unidades vendidas sem registo histórico do custo.':'Margem bruta estimada: '.money($p['margin']).'.';
            $lines[]='Este valor não corresponde ao lucro líquido. Não inclui despesas fixas, impostos nem outros encargos.';
        }elseif(in_array($topic,['overview','sales'],true)){
            $sales=scalar($db,'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND created_at>=? AND created_at<?',[$cid,$from,$endExclusive]);
            $count=scalar($db,'SELECT COUNT(*) FROM payments WHERE company_id=? AND created_at>=? AND created_at<?',[$cid,$from,$endExclusive]);
            $displayFrom=DateTimeImmutable::createFromFormat('!Y-m-d',$from)->format('d/m/Y');
            $displayTo=DateTimeImmutable::createFromFormat('!Y-m-d',$to)->format('d/m/Y');
            $lines[]=$displayFrom===$displayTo?'Vendas de '.$displayFrom.'.':'Vendas entre '.$displayFrom.' e '.$displayTo.'.';
            $lines[]='Valor recebido: '.money($sales).'.';
            $lines[]='Número de pagamentos registados: '.$count.'.';
        }
        if($topic==='overview'){
            $lines[]='Pedidos criados no período: '.scalar($db,'SELECT COUNT(*) FROM orders WHERE company_id=? AND created_at>=? AND created_at<?',[$cid,$from,$endExclusive]);
            $lines[]='Reservas marcadas no período: '.scalar($db,'SELECT COUNT(*) FROM reservations WHERE company_id=? AND start_at>=? AND start_at<?',[$cid,$from,$endExclusive]);
            $lines[]='Produtos ativos atualmente: '.scalar($db,'SELECT COUNT(*) FROM products WHERE company_id=? AND active=1',[$cid]);
        }elseif($topic==='low_stock'){
            $rows=all($db,'SELECT name,qty,min_qty FROM products WHERE company_id=? AND active=1 AND qty<=min_qty ORDER BY qty ASC,name LIMIT 40',[$cid]);
            foreach($rows as $r)$lines[]=$r['name'].': existem '.$r['qty'].' unidades em stock. O mínimo definido é de '.$r['min_qty'].' unidades.';
        }elseif($topic==='open_orders'){
            $rows=all($db,"SELECT o.id,o.total_cents,t.name table_name FROM orders o LEFT JOIN dining_tables t ON t.id=o.table_id WHERE o.company_id=? AND o.status='aberto' ORDER BY o.id DESC LIMIT 35",[$cid]);
            foreach($rows as $r)$lines[]='Pedido #'.$r['id'].' · '.($r['table_name']??'Balcão').' · '.money($r['total_cents']);
        }elseif($topic==='reservations'){
            $rows=all($db,"SELECT r.id,r.customer,r.guests,r.start_at,r.status,t.name table_name FROM reservations r LEFT JOIN dining_tables t ON t.id=r.table_id AND t.company_id=r.company_id WHERE r.company_id=? AND r.start_at>=? AND r.start_at<? ORDER BY r.start_at LIMIT 100",[$cid,$from,$endExclusive]);
            foreach($rows as $r)$lines[]=$r['start_at'].' · #'.$r['id'].' · '.$r['customer'].' · '.($r['table_name']??'Sem mesa').' · '.$r['guests'].' pessoas · '.$r['status'];
        }elseif($topic==='products'){
            $rows=all($db,'SELECT name,qty,price_cents FROM products WHERE company_id=? AND active=1 ORDER BY name LIMIT 50',[$cid]);
            foreach($rows as $r)$lines[]=$r['name'].': '.$r['qty'].' unidades disponíveis. Preço de venda: '.money($r['price_cents']).'.';
        }elseif($topic==='tables'){
            $orders=all($db,"SELECT t.name,substr(o.created_at,1,10) day,COUNT(*) orders_count,COALESCE(SUM(o.total_cents),0) total FROM orders o JOIN dining_tables t ON t.id=o.table_id AND t.company_id=o.company_id WHERE o.company_id=? AND o.created_at>=? AND o.created_at<? GROUP BY t.id,substr(o.created_at,1,10) ORDER BY day,t.name LIMIT 100",[$cid,$from,$endExclusive]);
            foreach($orders as $r)$lines[]=$r['day'].' · '.$r['name'].' · '.$r['orders_count'].' pedidos · '.money($r['total']);
            $bookings=all($db,"SELECT t.name,r.start_at,r.status FROM reservations r LEFT JOIN dining_tables t ON t.id=r.table_id AND t.company_id=r.company_id WHERE r.company_id=? AND r.start_at>=? AND r.start_at<? ORDER BY r.start_at LIMIT 100",[$cid,$from,$endExclusive]);
            foreach($bookings as $r)$lines[]='Reserva '.$r['start_at'].' · '.($r['name']??'Sem mesa').' · '.$r['status'];
            if(!$orders&&!$bookings)$lines[]='Sem pedidos ou reservas registados neste período. Não é possível inferir ocupação sem registos.';
        }elseif($topic==='logs'){
            require_once __DIR__.'/audit_filters.php';
            $filter=activity_query_filters($db,$cid,[
                'from'=>$from,'to'=>$to,'from_time'=>$action['time_from']??'',
                'to_time'=>$action['time_to']??'','entity'=>$action['entity']??'',
                'actor'=>$action['actor_id']??0,
            ]);
            $logs=all($db,"SELECT l.created_at,l.entity,l.operation,l.details,COALESCE(u.name,'Sistema') actor FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id WHERE ".$filter['where']." ORDER BY l.created_at DESC,l.id DESC LIMIT 45",$filter['args']);
            foreach($logs as $r)$lines[]=$r['created_at'].' · '.$r['actor'].' · '.$r['entity'].' / '.$r['operation'].' · '.$r['details'];
            if(!$logs)$lines[]='Não existem alterações registadas para este período e filtros.';
        }elseif($topic==='fiscal'){
            require_once __DIR__.'/fiscal_lib.php';fiscal_setup($db);
            $rows=all($db,'SELECT order_id,status,invoice_number,atcud,created_at FROM fiscal_documents WHERE company_id=? AND created_at>=? AND created_at<? ORDER BY id DESC LIMIT 45',[$cid,$from,$endExclusive]);
            foreach($rows as $r)$lines[]=$r['created_at'].' · pedido #'.$r['order_id'].' · '.($r['invoice_number']?:'Sem número ainda').' · estado no fornecedor: '.$r['status'].' · ATCUD '.($r['atcud']?:'por validar');
            if(!$rows)$lines[]='Sem documentos fiscais ligados a pedidos neste período.';
            $lines[]='A comunicação efetiva à AT deve ser validada no histórico do fornecedor certificado.';
        }elseif($topic==='customers'){
            $rows=all($db,'SELECT name FROM customers WHERE company_id=? ORDER BY name LIMIT 60',[$cid]);
            foreach($rows as $r)$lines[]=$r['name'];
        }
        $reply=($lines?implode("\n",$lines):'Não foram encontrados resultados para o período e os filtros selecionados.');
    }elseif($kind==='create_table'){
        $db->prepare('INSERT INTO dining_tables(company_id,name,seats) VALUES(?,?,?)')->execute([$cid,$action['name'],$action['seats']]);
        $reply='Mesa criada. Podes posicioná-la no mapa em Mesas.';
    }elseif($kind==='duplicate_table'){
        $t=scoped($db,'dining_tables',$action['table_id'],$cid);
        $db->prepare('INSERT INTO dining_tables(company_id,name,seats,map_x,map_y,map_shape,map_width,map_height) VALUES(?,?,?,?,?,?,?,?)')->execute([$cid,$t['name'].' (cópia)',$t['seats'],-1,-1,$t['map_shape'],$t['map_width'],$t['map_height']]);
        $reply='Mesa duplicada. Ajusta a posição no mapa.';
    }elseif($kind==='update_table'){
        $t=scoped($db,'dining_tables',$action['table_id'],$cid);
        $columns=['name'=>'name','seats'=>'seats','shape'=>'map_shape'];
        $db->prepare('UPDATE dining_tables SET '.$columns[$action['field']].'=? WHERE company_id=? AND id=?')->execute([$action['value'],$cid,$t['id']]);
        $reply='Mesa atualizada.';
    }elseif($kind==='move_table'){
        $table=scoped($db,'dining_tables',$action['table_id'],$cid);
        $size=one($db,'SELECT width,height FROM floor_settings WHERE company_id=?',[$cid])?:['width'=>1000,'height'=>1000];
        if($action['x']<0||$action['y']<0||$action['x']+(int)$table['map_width']>(int)$size['width']||$action['y']+(int)$table['map_height']>(int)$size['height']) throw new RuntimeException('Posição fora da planta atual.');
        $db->prepare('UPDATE dining_tables SET map_x=?,map_y=? WHERE company_id=? AND id=?')->execute([$action['x'],$action['y'],$cid,$action['table_id']]);
        $reply='Mesa reposicionada no mapa.';
    }elseif($kind==='stock'){
        $p=scoped($db,'products',$action['product_id'],$cid);
        $delta=$action['delta'];
        if($p['qty']+$delta<0) throw new RuntimeException('Stock insuficiente.');
        // A transação já foi aberta para o lote completo.
        $s=$db->prepare('UPDATE products SET qty=qty+? WHERE company_id=? AND id=? AND qty+?>=0');
        $s->execute([$delta,$cid,$p['id'],$delta]);
        if(!$s->rowCount()) throw new RuntimeException('Não foi possível atualizar o stock.');
        $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$cid,$p['id'],$delta,'IA V13 · confirmação humana',user_id(),now()]);
        // A transação será confirmada apenas depois de todas as ações.
        $reply='Stock atualizado.';
    }elseif(in_array($kind,['duplicate_product','delete_product','restore_product','set_stock','update_product','undo_product'],true)){
        // A transação já foi aberta para o lote completo.
        if($kind==='undo_product'){
            $history=one($db,'SELECT * FROM ai_product_history WHERE id=? AND company_id=? AND user_id=? AND undone_at IS NULL',[$action['history_id'],$cid,user_id()]);
            if(!$history) throw new RuntimeException('Alteração não encontrada ou já desfeita.');
            $before=$history['before_json']?json_decode($history['before_json'],true):null;
            $after=$history['after_json']?json_decode($history['after_json'],true):null;
            $current=one($db,'SELECT * FROM products WHERE company_id=? AND id=?',[$cid,$history['product_id']]);
            if(!$current || !$after) throw new RuntimeException('Não é possível desfazer esta alteração.');
            foreach(['name','sku','category','price_cents','cost_cents','qty','min_qty','active','image_path'] as $field){
                if((string)$current[$field] !== (string)$after[$field]) throw new RuntimeException('O produto foi alterado entretanto. Desfazer cancelado.');
            }
            if($before===null){
                $db->prepare('UPDATE products SET active=0 WHERE id=? AND company_id=?')->execute([$history['product_id'],$cid]);
            }else{
                $db->prepare('UPDATE products SET name=?,sku=?,category=?,price_cents=?,cost_cents=?,qty=?,min_qty=?,active=?,image_path=? WHERE id=? AND company_id=?')->execute([$before['name'],$before['sku'],$before['category'],$before['price_cents'],$before['cost_cents'],$before['qty'],$before['min_qty'],$before['active'],$before['image_path'],$history['product_id'],$cid]);
            }
            $db->prepare('UPDATE ai_product_history SET undone_at=? WHERE id=?')->execute([now(),$history['id']]);
            $reply='Última alteração desfeita.';
        }else{
            $p=scoped($db,'products',(int)$action['product_id'],$cid);
            $before=$p;
            if($kind==='duplicate_product'){
                require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_product_space($db,$cid);
                if(!(int)$p['active']) throw new RuntimeException('Produto arquivado.');
                $sku='COPY-'.bin2hex(random_bytes(6));
                $db->prepare('INSERT INTO products(company_id,name,sku,category,price_cents,cost_cents,qty,min_qty,active,image_path) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$p['name'].' (cópia)',$sku,$p['category'],$p['price_cents'],$p['cost_cents'],0,$p['min_qty'],1,$p['image_path']]);
                $newId=(int)$db->lastInsertId();
                $before=null;
                $after=scoped($db,'products',$newId,$cid);
                $reply='Produto duplicado com stock inicial 0.';
            }else{
                if($kind==='delete_product'){
                    if(!(int)$p['active']) throw new RuntimeException('Produto já arquivado.');
                    $db->prepare('UPDATE products SET active=0 WHERE company_id=? AND id=?')->execute([$cid,$p['id']]);
                }elseif($kind==='restore_product'){
                    $db->prepare('UPDATE products SET active=1 WHERE company_id=? AND id=?')->execute([$cid,$p['id']]);
                }elseif($kind==='set_stock'){
                    $newQty=(int)$action['qty'];
                    $delta=$newQty-(int)$p['qty'];
                    $db->prepare('UPDATE products SET qty=? WHERE company_id=? AND id=?')->execute([$newQty,$cid,$p['id']]);
                    if($delta) $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')->execute([$cid,$p['id'],$delta,'IA V13 · reposição de stock',user_id(),now()]);
                }elseif($kind==='update_product'){
                    $map=['name'=>'name','category'=>'category','price'=>'price_cents','min_qty'=>'min_qty'];
                    $field=$action['field'];
                    if(!isset($map[$field])) throw new RuntimeException('Campo não permitido.');
                    $value=$field==='price'?cents($action['value']):($field==='min_qty'?(int)$action['value']:$action['value']);
                    $db->prepare('UPDATE products SET '.$map[$field].'=? WHERE company_id=? AND id=?')->execute([$value,$cid,$p['id']]);
                }
                $newId=(int)$p['id'];
                $after=scoped($db,'products',$newId,$cid);
                $reply='Produto atualizado.';
            }
            $db->prepare('INSERT INTO ai_product_history(company_id,user_id,product_id,before_json,after_json,kind,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$cid,user_id(),$newId,$before===null?null:json_encode($before,JSON_UNESCAPED_UNICODE),json_encode($after,JSON_UNESCAPED_UNICODE),$kind,now()]);
        }
        // A transação será confirmada apenas depois de todas as ações.
    }elseif($kind==='duplicate_order'){
        $sourceId=(int)($action['source_order_id']??0);
        $destId=(int)($action['destination_table_id']??0);
        $snap=order_copy_snapshot($db,$cid,$sourceId);
        if(!hash_equals((string)($action['fingerprint']??''),$snap['fingerprint']))
            throw new RuntimeException('O pedido original mudou após a proposta. Pede uma nova confirmação à IA.');
        if($snap['table_id']!==(int)($action['source_table_id']??0)) throw new RuntimeException('A mesa de origem do pedido mudou.');
        if($snap['table_id']===$destId) throw new RuntimeException('A mesa de destino deve ser diferente da mesa de origem.');
        $dest=scoped($db,'dining_tables',$destId,$cid);
        if(one($db,"SELECT id FROM orders WHERE company_id=? AND table_id=? AND status='aberto' LIMIT 1",[$cid,$destId]))
            throw new RuntimeException($dest['name'].' já tem um pedido aberto. Nenhum pedido foi copiado.');
        $db->prepare("INSERT INTO orders(company_id,table_id,origin,status,created_at,subtotal_cents,total_cents) VALUES(?,?,?,'aberto',?,?,?)")
            ->execute([$cid,$destId,'mesa',now(),$snap['total_cents'],$snap['total_cents']]);
        $newId=(int)$db->lastInsertId();
        foreach($snap['items'] as $item){
            $product=scoped($db,'products',$item['product_id'],$cid);
            if(!(int)$product['active']) throw new RuntimeException('Produto indisponível: '.$product['name']);
            $update=$db->prepare('UPDATE products SET qty=qty-? WHERE id=? AND company_id=? AND active=1 AND qty>=?');
            $update->execute([$item['qty'],$item['product_id'],$cid,$item['qty']]);
            if($update->rowCount()!==1) throw new RuntimeException('Stock insuficiente para '.$product['name'].'. Cópia cancelada.');
            $db->prepare("INSERT INTO order_items(order_id,product_id,name,qty,price_cents,cost_cents,service_status) VALUES(?,?,?,?,?,?,'aguarda_servico')")
                ->execute([$newId,$item['product_id'],$item['name'],$item['qty'],$item['price_cents'],$item['cost_cents']??$product['cost_cents']]);
            $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')
                ->execute([$cid,$item['product_id'],-$item['qty'],'IA · cópia do pedido #'.$sourceId.' para #'.$newId,user_id(),now()]);
        }
        $reply='Pedido #'.$newId.' criado em '.$dest['name'].' com '.count($snap['items']).' linha(s) e total '.money($snap['total_cents']).'. Original #'.$sourceId.' preservado. Pagamento previsto no fim do serviço.';
    }elseif($kind==='new_order'){
        $tableId=null;
        if($action['origin']==='mesa'){
            $tableId=$action['table_id'];scoped($db,'dining_tables',$tableId,$cid);
            $existing=one($db,"SELECT id FROM orders WHERE company_id=? AND table_id=? AND status='aberto' LIMIT 1",[$cid,$tableId]);
            if($existing) throw new RuntimeException('Já existe um pedido aberto nesta mesa: #'.$existing['id']);
        }
        $db->prepare('INSERT INTO orders(company_id,table_id,origin,created_at) VALUES(?,?,?,?)')->execute([$cid,$tableId,$action['origin'],now()]);
        $reply='Pedido #'.$db->lastInsertId().' criado.';
    }elseif($kind==='open_till'){
        if(till($db,$cid)) throw new RuntimeException('Já existe uma caixa aberta.');
        $db->prepare('INSERT INTO tills(company_id,user_id,opened_at,opening_cents) VALUES(?,?,?,?)')->execute([$cid,user_id(),now(),cents($action['opening'])]);
        $reply='Caixa aberta.';
    }elseif($kind==='reservation'){
        available_table($db,$cid,(string)$action['start_at'],(int)$action['guests'],(int)$action['table_id']);
        $db->prepare('INSERT INTO reservations(company_id,table_id,customer,guests,start_at) VALUES(?,?,?,?,?)')->execute([$cid,$action['table_id'],$action['customer'],$action['guests'],$action['start_at']]);
        $reply='Reserva criada com atribuição de mesa confirmada.';
    }elseif($kind==='reservation_edit'){
        $r=scoped($db,'reservations',(int)$action['reservation_id'],$cid);
        if($r['status']!=='confirmada')throw new RuntimeException('A reserva foi alterada entretanto.');
        $table=available_table($db,$cid,(string)$action['start_at'],(int)$action['guests'],(int)$action['table_id'],(int)$r['id']);
        $db->prepare("UPDATE reservations SET customer=?,guests=?,start_at=?,table_id=? WHERE company_id=? AND id=? AND status='confirmada'")
            ->execute([$action['customer'],$action['guests'],$action['start_at'],$table['id'],$cid,$r['id']]);
        $reply='Reserva de '.$r['customer'].' atualizada com sucesso. Nova data e hora: '.$action['start_at'].'. Mesa atribuída: '.$table['name'].'.';
    }elseif($kind==='cancel_reservation'){
        $r=scoped($db,'reservations',(int)$action['reservation_id'],$cid);
        if($r['status']!=='confirmada')throw new RuntimeException('A reserva já foi alterada.');
        $updated=$db->prepare("UPDATE reservations SET status='cancelada' WHERE company_id=? AND id=? AND status='confirmada'");
        $updated->execute([$cid,$r['id']]);
        if(!$updated->rowCount())throw new RuntimeException('Reserva já cancelada.');
        $reply='Reserva #'.$r['id'].' de '.$r['customer'].' cancelada.';
    }elseif($kind==='create_product'){
        require_once __DIR__.'/app/domain/subscriptions.php';ug_subscription_assert_product_space($db,$cid);
        $db->prepare('INSERT INTO products(company_id,name,sku,category,price_cents,cost_cents,qty,min_qty) VALUES(?,?,?,?,?,?,?,?)')->execute([$cid,$action['name'],'SKU-'.bin2hex(random_bytes(5)),$action['category'],cents($action['price']),0,$action['qty'],2]);
        $reply='Produto criado.';
    }elseif($kind==='cash_move'){
        $t=till($db,$cid);
        if(!$t) throw new RuntimeException('A caixa está fechada.');
        $db->prepare('INSERT INTO cash_movements(company_id,till_id,user_id,type,amount_cents,reason,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$t['id'],user_id(),$action['type'],cents($action['amount']),$action['reason'],now()]);
        $reply='Movimento de caixa registado.';
    }else throw new RuntimeException('Ação não suportada.');
    // Evita que um mapa aberto noutra janela sobreponha modificações da IA.
    if(in_array($kind,['create_table','duplicate_table','update_table','move_table'],true)){
        $db->prepare('INSERT OR IGNORE INTO floor_settings(company_id,width,height,revision) VALUES(?,?,?,0)')->execute([$cid,1000,1000]);
        $db->prepare('UPDATE floor_settings SET revision=revision+1 WHERE company_id=?')->execute([$cid]);
    }
    $replies[]=$reply;
    }
    $db->commit();
    $number=count($replies);
    $heading=$number===1?($actions[0]['kind']==='report'?'Consulta concluída.':'Operação concluída.'):'Foram concluídas '.$number.' ações solicitadas.';
    $response=$number===1 ? $heading."\n\n".trim((string)$replies[0]) : $heading."\n\n".implode("\n\n",array_map(static fn($i,$r)=>'Operação '.($i+1)."\n".trim((string)$r),array_keys($replies),$replies));
    echo json_encode(['reply'=>$response,'count'=>$number],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
    if($db->inTransaction()) $db->rollBack();
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
