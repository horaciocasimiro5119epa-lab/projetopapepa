<?php
/**
 * Ações do backoffice e do POS.
 * Validação CSRF, isolamento por empresa e transações nas operações financeiras.
 */
require __DIR__.'/bootstrap.php';
must_company($db);
$cid=company_id();
require_once __DIR__.'/app/domain/company_features.php';
$page=req('return') ?: 'dashboard';
if(!in_array($page,['dashboard','pos','orders','tables','reservations','products','customers','cash','reports','exports','ai10','logs','settings','team'],true)) $page='dashboard';
$redirectExtra='';
$g360Ajax=($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='XMLHttpRequest';
$g360AjaxAllowed=['product_edit','customer_edit','table_edit','reservation_edit','order_payment_mode','item_service_status'];
$g360Error='';

try{
    if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Utiliza os formulários para realizar operações.');
    check_token();
    require_once __DIR__.'/app/domain/subscriptions.php';
    ug_subscription_assert_write($db,$cid);
    $action=req('action');
    if($g360Ajax && !in_array($action,$g360AjaxAllowed,true))throw new RuntimeException('Esta operação exige confirmação manual.');
    if(!in_array($action,['product','product_edit','stock','customer','customer_edit','table','table_edit','reservation','reservation_edit','reservation_status','open_till','cash_move','close_till','new_order','add_item','remove_item','cancel_order','pay_order','order_payment_mode','item_service_status'],true))
        throw new RuntimeException('Operação não reconhecida.');
    require_permission($db,$cid,action_permission($action));
    $module=g360_module_for_action($action);if($module && !g360_feature($db,$cid,$module))throw new RuntimeException('Este módulo foi desativado nas definições da empresa.');
    if($action==='cash_move' && !g360_feature($db,$cid,'cash_enabled'))throw new RuntimeException('A gestão de caixa está desativada nesta empresa.');
    if(in_array($action,['new_order','add_item','remove_item','cancel_order','pay_order','order_payment_mode'],true))require_permission($db,$cid,'pos.use');
    if(in_array($action,['new_order','add_item','remove_item','cancel_order','pay_order','order_payment_mode'],true) && !till($db,$cid))
        throw new RuntimeException('Abre primeiro a caixa em Movimentos de caixa para utilizares o POS.');
    $success='Operação concluída com sucesso.';

    switch($action){
        case 'order_payment_mode':
            $orderId=n('order_id',1);
            // Compatível com formulários antigos, mas a V28 utiliza uma checkbox.
            $mode=array_key_exists('prepayment',$_POST)?'antecipado':(isset($_POST['payment_mode'])?req('payment_mode'):'posterior');
            if(!in_array($mode,['antecipado','posterior'],true))throw new RuntimeException('Modo de pagamento inválido.');
            $db->beginTransaction();
            $order=scoped($db,'orders',$orderId,$cid);
            if($order['status']!=='aberto')throw new RuntimeException('Não é possível alterar o pagamento de um pedido já fechado.');
            if($mode==='antecipado' && (int)scalar($db,"SELECT COUNT(*) FROM order_items WHERE order_id=? AND service_status IN ('em_preparacao','pronto','feito')",[$orderId])>0)
                throw new RuntimeException('O serviço deste pedido já começou. Não é possível ativar pré-pagamento a meio do serviço.');
            $db->prepare("UPDATE orders SET payment_mode=? WHERE id=? AND company_id=? AND status='aberto'")->execute([$mode,$orderId,$cid]);
            $status=$mode==='antecipado'?'aguarda_pagamento':'aguarda_servico';
            $db->prepare("UPDATE order_items SET service_status=? WHERE order_id=? AND service_status IN ('aguarda_pagamento','aguarda_servico','pago_aguarda_servico')")->execute([$status,$orderId]);
            $db->commit();
            $success=$mode==='antecipado'?'Pré-pagamento ativado. Os artigos aguardam pagamento antes do serviço.':'Pré-pagamento desativado. Os artigos podem ser servidos antes do pagamento.';
            $page='orders';break;
        case 'item_service_status':
            $order=scoped($db,'orders',n('order_id',1),$cid);
            $item=one($db,'SELECT id FROM order_items WHERE id=? AND order_id=?',[n('item_id',1),$order['id']]);
            if(!$item)throw new RuntimeException('O artigo não pertence a este pedido.');
            $status=req('service_status');
            if($order['status']==='cancelado')throw new RuntimeException('Não é possível alterar um pedido cancelado.');
            $allowed=($order['status']==='pago')?['pago_aguarda_servico','em_preparacao','pronto','feito']:
                (($order['payment_mode']??'posterior')==='antecipado'?['aguarda_pagamento']:['aguarda_servico','em_preparacao','pronto','feito']);
            if(!in_array($status,$allowed,true))throw new RuntimeException('Estado incompatível com o modo de pagamento do pedido.');
            $db->prepare('UPDATE order_items SET service_status=? WHERE id=? AND order_id=?')->execute([$status,$item['id'],$order['id']]);
            $success='Estado do artigo atualizado.';$page='orders';break;
        case 'product':
            $db->beginTransaction();
            ug_subscription_assert_product_space($db,$cid);
            $name=req('name');
            $category=req('category') ?: 'Geral';
            if(mb_strlen($name)<2||mb_strlen($name)>120||mb_strlen($category)>80) throw new RuntimeException('Nome ou categoria inválidos.');
            $db->prepare('INSERT INTO products(company_id,name,sku,category,price_cents,cost_cents,qty,min_qty) VALUES(?,?,?,?,?,?,?,?)')
               ->execute([$cid,$name,req('sku')?:'SKU-'.bin2hex(random_bytes(5)),$category,cents(req('price')),cents(req('cost')),n('qty'),n('min_qty')]);
            $img=save_product_image();
            if(!$img && req('suggestion_slug')!=='')$img=g360_catalog_image(req('suggestion_slug'));
            if($img) $db->prepare('UPDATE products SET image_path=? WHERE id=? AND company_id=?')->execute([$img,(int)$db->lastInsertId(),$cid]);
            $db->commit();
            $success='Produto criado.';
            break;

        case 'product_edit':
            $id=n('product_id',1);
            scoped($db,'products',$id,$cid);
            $name=req('name'); $category=req('category') ?: 'Geral';
            if(mb_strlen($name)<2||mb_strlen($name)>120||mb_strlen($category)>80) throw new RuntimeException('Nome ou categoria inválidos.');
            $db->prepare('UPDATE products SET name=?,category=?,sku=?,price_cents=?,cost_cents=?,min_qty=? WHERE id=? AND company_id=?')
               ->execute([$name,$category,req('sku'),cents(req('price')),cents(req('cost')),n('min_qty'),$id,$cid]);
            $img=save_product_image(); if($img) $db->prepare('UPDATE products SET image_path=? WHERE id=? AND company_id=?')->execute([$img,$id,$cid]);
            $success='Dados do produto atualizados.';
            break;

        case 'stock':
            $id=n('product_id',1);
            $delta=n('delta',-100000,100000);
            if($delta===0) throw new RuntimeException('A alteração de stock não pode ser zero.');
            $reason=req('reason') ?: 'Acerto de stock';
            $db->beginTransaction();
            $p=scoped($db,'products',$id,$cid);
            $update=$db->prepare('UPDATE products SET qty=qty+? WHERE id=? AND company_id=? AND qty+?>=0');
            $update->execute([$delta,$id,$cid,$delta]);
            if(!$update->rowCount()) throw new RuntimeException('Stock insuficiente.');
            $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')
               ->execute([$cid,$id,$delta,$reason,user_id(),now()]);
            $db->commit();
            $success='Stock de '.$p['name'].' ajustado em '.$delta.' unidade(s).';
            break;

        case 'customer':
            $name=req('name');
            if($name===''||mb_strlen($name)>120) throw new RuntimeException('Introduz o nome do cliente.');
            $db->prepare('INSERT INTO customers(company_id,name,nif,phone,email) VALUES(?,?,?,?,?)')
               ->execute([$cid,$name,req('nif'),req('phone'),req('email')]);
            break;

        case 'customer_edit':
            $id=n('customer_id',1);scoped($db,'customers',$id,$cid);
            $name=req('name');
            if($name===''||mb_strlen($name)>120) throw new RuntimeException('Nome de cliente inválido.');
            $db->prepare('UPDATE customers SET name=?,nif=?,phone=?,email=? WHERE company_id=? AND id=?')
               ->execute([$name,req('nif'),req('phone'),req('email'),$cid,$id]);
            $success='Cliente atualizado.';
            break;

        case 'table_edit':
            $id=n('table_id',1);scoped($db,'dining_tables',$id,$cid);
            $name=req('name');
            if($name===''||mb_strlen($name)>80) throw new RuntimeException('Nome de mesa inválido.');
            $db->prepare('UPDATE dining_tables SET name=?,seats=? WHERE company_id=? AND id=?')
               ->execute([$name,n('seats',1,100),$cid,$id]);
            $success='Mesa atualizada.';
            break;

        case 'table':
            $name=req('name');
            if($name===''||mb_strlen($name)>80) throw new RuntimeException('Nome da mesa inválido.');
            $db->prepare('INSERT INTO dining_tables(company_id,name,seats) VALUES(?,?,?)')
               ->execute([$cid,$name,n('seats',1,100)]);
            $db->prepare('INSERT OR IGNORE INTO floor_settings(company_id,width,height,revision) VALUES(?,?,?,0)')->execute([$cid,1000,1000]);
            $db->prepare('UPDATE floor_settings SET revision=revision+1 WHERE company_id=?')->execute([$cid]);
            break;

        case 'reservation':
            $customer=req('customer');
            $when=str_replace('T',' ',req('start_at'));
            $guest=n('guests',1,100);
            if($customer===''||mb_strlen($customer)>120) throw new RuntimeException('Indica um nome válido.');
            $db->beginTransaction();
            $preferred=n('table_id',0);
            $table=available_table($db,$cid,$when,$guest,$preferred?:null);
            $db->prepare('INSERT INTO reservations(company_id,table_id,customer,guests,start_at,notes) VALUES(?,?,?,?,?,?)')
               ->execute([$cid,$table['id'],$customer,$guest,$when,req('notes')]);
            $db->commit();
            $success='Reserva de '.$customer.' registada.';
            break;

        case 'reservation_edit':
            $id=n('reservation_id',1);
            $old=scoped($db,'reservations',$id,$cid);
            if($old['status']!=='confirmada')throw new RuntimeException('Só podes editar reservas confirmadas.');
            $name=trim(req('customer'));$guests=n('guests',1,100);$when=str_replace('T',' ',req('start_at'));
            if($name===''||mb_strlen($name)>120)throw new RuntimeException('Nome inválido.');
            $db->beginTransaction();
            $preferred=n('table_id',0);
            $table=available_table($db,$cid,$when,$guests,$preferred?:null,$id);
            $db->prepare("UPDATE reservations SET customer=?,guests=?,start_at=?,table_id=?,notes=? WHERE id=? AND company_id=? AND status='confirmada'")
                ->execute([$name,$guests,$when,$table['id'],req('notes'),$id,$cid]);
            $db->commit();
            $success='Reserva de '.$name.' atualizada para '.$when.' na '.$table['name'].'.';
            break;

        case 'reservation_status':
            $id=n('reservation_id',1);
            $reservation=scoped($db,'reservations',$id,$cid);
            if(!in_array($reservation['status'],['confirmada','chegou'],true))throw new RuntimeException('Esta reserva já foi encerrada.');
            $status=req('status');
            if(!in_array($status,['confirmada','chegou','cancelada','concluida'],true)) throw new RuntimeException('Estado inválido.');
            $db->prepare('UPDATE reservations SET status=? WHERE id=? AND company_id=?')->execute([$status,$id,$cid]);
            break;

        case 'open_till':
            $opening=cents(req('opening'));
            $db->beginTransaction();
            if(till($db,$cid)) throw new RuntimeException('Já existe uma caixa aberta.');
            $db->prepare('INSERT INTO tills(company_id,user_id,opened_at,opening_cents) VALUES(?,?,?,?)')
               ->execute([$cid,user_id(),now(),$opening]);
            $db->commit();
            $success='Caixa aberta com fundo de '.money($opening).'.';
            break;

        case 'cash_move':
            $t=till($db,$cid);
            if(!$t) throw new RuntimeException('Abre primeiro a caixa.');
            $type=req('type');
            if(!in_array($type,['entrada','saida'],true)) throw new RuntimeException('Movimento inválido.');
            $amount=cents(req('amount'));
            $reason=req('reason');
            if($amount<=0||$reason==='') throw new RuntimeException('Indica o montante e o motivo.');
            $db->prepare('INSERT INTO cash_movements(company_id,till_id,user_id,type,amount_cents,reason,created_at) VALUES(?,?,?,?,?,?,?)')
               ->execute([$cid,$t['id'],user_id(),$type,$amount,$reason,now()]);
            break;

        case 'close_till':
            $t=till($db,$cid);
            if(!$t) throw new RuntimeException('Não existe nenhuma caixa aberta.');
            $closing=cents(req('closing'));
            $expected=(int)$t['opening_cents']
                + scalar($db,"SELECT COALESCE(SUM(CASE WHEN type='entrada' THEN amount_cents ELSE -amount_cents END),0) FROM cash_movements WHERE company_id=? AND till_id=?",[$cid,$t['id']])
                + scalar($db,"SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND till_id=? AND method='dinheiro'",[$cid,$t['id']]);
            $db->prepare('UPDATE tills SET closed_at=?,closing_cents=?,notes=? WHERE id=? AND company_id=? AND closed_at IS NULL')
               ->execute([now(),$closing,req('notes'),$t['id'],$cid]);
            $success='Caixa fechada. Esperado: '.money($expected).' · Contado: '.money($closing).' · Diferença: '.money($closing-$expected).'.';
            break;

        case 'new_order':
            $origin=req('origin');
            if(!in_array($origin,['mesa','balcao'],true)) throw new RuntimeException('Tipo de pedido inválido.');
            $tableId=null;
            if($origin==='mesa'){
                $tableId=n('table_id',1);
                scoped($db,'dining_tables',$tableId,$cid);
                $existing=one($db,"SELECT id FROM orders WHERE company_id=? AND table_id=? AND status='aberto' ORDER BY id DESC LIMIT 1",[$cid,$tableId]);
                if($existing){
                    $redirectExtra='order='.(int)$existing['id'];
                    $success='A mesa já tinha um pedido. Foi selecionado o pedido existente.';
                    $page='pos';
                    break;
                }
            }
            $db->prepare('INSERT INTO orders(company_id,table_id,origin,created_at) VALUES(?,?,?,?)')
               ->execute([$cid,$tableId,$origin,now()]);
            $redirectExtra='order='.(int)$db->lastInsertId();
            $success='Novo pedido criado.';
            $page='pos';
            break;

        case 'add_item':
            $orderId=n('order_id',1);
            $productId=n('product_id',1);
            $quantity=n('qty',1,1000);
            $db->beginTransaction();
            $order=scoped($db,'orders',$orderId,$cid);
            if($order['status']!=='aberto') throw new RuntimeException('Este pedido já foi fechado.');
            $product=scoped($db,'products',$productId,$cid);
            if(!(int)$product['active']) throw new RuntimeException('Este produto está desativado.');
            $update=$db->prepare('UPDATE products SET qty=qty-? WHERE id=? AND company_id=? AND qty>=?');
            $update->execute([$quantity,$productId,$cid,$quantity]);
            if(!$update->rowCount()) throw new RuntimeException('Stock insuficiente para adicionar '.$product['name'].'.');
            $item=one($db,"SELECT * FROM order_items WHERE order_id=? AND product_id=? AND price_cents=? AND cost_cents=? AND service_status IN ('aguarda_pagamento','aguarda_servico','pago_aguarda_servico') LIMIT 1",[$orderId,$productId,$product['price_cents'],$product['cost_cents']]);
            if($item){
                $db->prepare('UPDATE order_items SET qty=qty+? WHERE id=?')->execute([$quantity,$item['id']]);
            }else{
                $db->prepare('INSERT INTO order_items(order_id,product_id,name,qty,price_cents,cost_cents,service_status) VALUES(?,?,?,?,?,?,?)')
                   ->execute([$orderId,$productId,$product['name'],$quantity,$product['price_cents'],$product['cost_cents'],($order['payment_mode']??'posterior')==='antecipado'?'aguarda_pagamento':'aguarda_servico']);
            }
            $total=$quantity*(int)$product['price_cents'];
            $db->prepare('UPDATE orders SET subtotal_cents=subtotal_cents+?,total_cents=total_cents+? WHERE id=? AND company_id=?')
               ->execute([$total,$total,$orderId,$cid]);
            $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')
               ->execute([$cid,$productId,-$quantity,'Pedido #'.$orderId,user_id(),now()]);
            $db->commit();
            $redirectExtra='order='.$orderId;
            $page='pos';
            $success=$quantity.' × '.$product['name'].' adicionado(s).';
            break;

        case 'remove_item':
            $orderId=n('order_id',1);
            $itemId=n('item_id',1);
            $quantity=n('qty',1,1000);
            $db->beginTransaction();
            $order=scoped($db,'orders',$orderId,$cid);
            if($order['status']!=='aberto') throw new RuntimeException('O pedido já está fechado.');
            $item=one($db,'SELECT * FROM order_items WHERE id=? AND order_id=?',[$itemId,$orderId]);
            if(!$item) throw new RuntimeException('Linha não encontrada.');
            $quantity=min($quantity,(int)$item['qty']);
            if($quantity===(int)$item['qty']){
                $db->prepare('DELETE FROM order_items WHERE id=? AND order_id=?')->execute([$itemId,$orderId]);
            }else{
                $db->prepare('UPDATE order_items SET qty=qty-? WHERE id=? AND order_id=?')->execute([$quantity,$itemId,$orderId]);
            }
            $price=$quantity*(int)$item['price_cents'];
            $db->prepare('UPDATE products SET qty=qty+? WHERE id=? AND company_id=?')->execute([$quantity,$item['product_id'],$cid]);
            $db->prepare('UPDATE orders SET subtotal_cents=subtotal_cents-?,total_cents=total_cents-? WHERE id=? AND company_id=?')->execute([$price,$price,$orderId,$cid]);
            $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')
               ->execute([$cid,$item['product_id'],$quantity,'Removido do pedido #'.$orderId,user_id(),now()]);
            $db->commit();
            $redirectExtra='order='.$orderId;
            $page='pos';
            $success='Produto retirado do pedido e stock devolvido.';
            break;

        case 'cancel_order':
            $orderId=n('order_id',1);
            $db->beginTransaction();
            $order=scoped($db,'orders',$orderId,$cid);
            if($order['status']!=='aberto') throw new RuntimeException('Só é possível cancelar pedidos abertos.');
            $items=all($db,'SELECT * FROM order_items WHERE order_id=?',[$orderId]);
            foreach($items as $item){
                $db->prepare('UPDATE products SET qty=qty+? WHERE company_id=? AND id=?')
                   ->execute([$item['qty'],$cid,$item['product_id']]);
                $db->prepare('INSERT INTO stock_moves(company_id,product_id,delta,reason,user_id,created_at) VALUES(?,?,?,?,?,?)')
                   ->execute([$cid,$item['product_id'],$item['qty'],'Cancelamento pedido #'.$orderId,user_id(),now()]);
            }
            $db->prepare("UPDATE orders SET status='cancelado',closed_at=? WHERE id=? AND company_id=?")->execute([now(),$orderId,$cid]);
            $db->commit();
            $page='pos';
            $success='Pedido cancelado e stock devolvido.';
            break;

        case 'pay_order':
            $orderId=n('order_id',1);
            $method=req('method');
            $receiptNif=req('receipt_nif');$receiptEmail=req('receipt_email');
            if($receiptNif!=='' && !valid_nif($receiptNif))throw new RuntimeException('NIF do cliente inválido.');
            if($receiptEmail!=='' && (!filter_var($receiptEmail,FILTER_VALIDATE_EMAIL)||strlen($receiptEmail)>160))throw new RuntimeException('Email inválido para o recibo.');
            if(!in_array($method,['dinheiro','cartao','mbway','transferencia'],true)) throw new RuntimeException('Método de pagamento inválido.');
            $db->beginTransaction();
            $order=scoped($db,'orders',$orderId,$cid);
            if($order['status']!=='aberto') throw new RuntimeException('Este pedido já foi fechado.');
            if((int)$order['total_cents']<=0) throw new RuntimeException('Adiciona produtos antes de cobrar.');
            $t=till($db,$cid);
            if(!$t) throw new RuntimeException('Abre a caixa antes de cobrar.');
            $update=$db->prepare("UPDATE orders SET status='pago',closed_at=? WHERE id=? AND company_id=? AND status='aberto'");
            $update->execute([now(),$orderId,$cid]);
            if($update->rowCount()!==1) throw new RuntimeException('Pedido já fechado.');
            $db->prepare('INSERT INTO payments(company_id,order_id,till_id,method,amount_cents,created_at) VALUES(?,?,?,?,?,?)')
               ->execute([$cid,$orderId,$t['id'],$method,$order['total_cents'],now()]);
            $db->prepare("UPDATE order_items SET service_status='pago_aguarda_servico' WHERE order_id=? AND service_status IN ('aguarda_pagamento','aguarda_servico')")->execute([$orderId]);
            $db->exec("CREATE TABLE IF NOT EXISTS order_receipts (company_id INTEGER NOT NULL,order_id INTEGER NOT NULL,client_nif TEXT NOT NULL DEFAULT '',client_email TEXT NOT NULL DEFAULT '',created_at TEXT NOT NULL,PRIMARY KEY(company_id,order_id))");
            $db->prepare('INSERT INTO order_receipts(company_id,order_id,client_nif,client_email,created_at) VALUES(?,?,?,?,?)')->execute([$cid,$orderId,$receiptNif,$receiptEmail,now()]);
            $db->commit();
            header('Location: receipt.php?order='.$orderId,true,303);exit;

        default:
            throw new RuntimeException('Ação não reconhecida.');
    }
    if($g360Ajax){header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>true,'message'=>$success],JSON_UNESCAPED_UNICODE);exit;}
    flash($success);
}catch(Throwable $e){
    if($db->inTransaction()) $db->rollBack();
    $message=$e instanceof PDOException ? 'Não foi possível guardar: verifica os dados e se há referências repetidas.' : $e->getMessage();
    if($g360Ajax){http_response_code(422);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode(['ok'=>false,'message'=>$message],JSON_UNESCAPED_UNICODE);exit;}
    flash($message,'error');
    if($page==='pos' && !$redirectExtra && ($_POST['order_id']??null)) $redirectExtra='order='.(int)$_POST['order_id'];
}
back($page,$redirectExtra);
