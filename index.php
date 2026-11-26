<?php
require __DIR__.'/bootstrap.php';
// A página principal é pública e igual para visitantes e utilizadores autenticados.
// Apenas ?page=... abre áreas privadas do painel.
if (!isset($_GET['page']) || trim((string)$_GET['page']) === '') {
    require __DIR__.'/landing.php';
    exit;
}
if (!user_id()) {
    header('Location: index.php', true, 302);
    exit;
}
must_company($db);
$cid = company_id();

require_once __DIR__.'/app/domain/subscriptions.php';
$ugCurrentSub  = ug_subscription_read($db, $cid);
$ugMaxProducts = ug_subscription_max_products($db, $cid);

$company = one($db, 'SELECT * FROM companies WHERE id=?', [$cid]);

$pages = [
    'dashboard'    => 'Visão geral',
    'pos'          => 'Caixa registadora',
    'orders'       => 'Pedidos',
    'tables'       => 'Mesas',
    'reservations' => 'Reservas',
    'products'     => 'Produtos e stock',
    'customers'    => 'Clientes',
    'cash'         => 'Movimentos de caixa',
    'reports'      => 'Relatórios',
    'exports'      => 'Exportações',
    'fiscal'       => 'Faturação AT',
    'ai10'         => 'IA de gestão',
    'logs'         => 'Logs de atividade',
    'settings'     => 'Gestão e controlo',
];
$page = (string)($_GET['page'] ?? 'dashboard');

require_once __DIR__.'/app/domain/company_features.php';
$g360CurrentPrefs = g360_preferences($db, $cid);
$g360On = static fn(string $key): bool => g360_feature_active($g360CurrentPrefs, $key);

$module = g360_module_for_page($page);
if ($module && empty($g360CurrentPrefs[$module])) {
    http_response_code(403);
    echo '<!doctype html><html lang="pt-PT"><meta charset="utf-8"><title>Módulo desativado</title><link rel="stylesheet" href="assets/css/core.css"><body class="auth-body"><main class="company-screen"><img src="logo-icon.png?v=41" width="68" height="68" alt="UniGest"><h1>Funcionalidade desativada</h1><p>Esta empresa desativou temporariamente o módulo.</p><a class="btn btn-main" href="index.php?page=dashboard">Voltar ao painel</a></main></body></html>';
    exit;
}
if ($page === 'team') {
    header('Location: index.php?page=settings', true, 302);
    exit;
}
if (!isset($pages[$page])) {
    $page = 'dashboard';
}
if (!can_access_page($db, $cid, $page) && !isset($_GET['page'])) {
    foreach (array_keys($pages) as $accessible) {
        if (can_access_page($db, $cid, $accessible)) {
            header('Location: index.php?page='.$accessible);
            exit;
        }
    }
}
if (!can_access_page($db, $cid, $page)) {
    http_response_code(403);
    echo '<!doctype html><html lang="pt-PT"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Sem permissão</title><link rel="stylesheet" href="assets/css/core.css"><body class="auth-body"><main class="company-screen"><h1>Área sem permissão</h1><p>O cargo atribuído à tua conta não permite aceder a esta página.</p><a class="btn btn-main" href="companies.php">Escolher empresa</a></main></body></html>';
    exit;
}

$g360UnifiedRoutes = [
    'cash'    => 'cash',
    'reports' => 'reports',
    'exports' => 'exports',
    'fiscal'  => 'fiscalhub',
    'ai10'    => 'ai',
    'logs'    => 'logs',
];
if (isset($g360UnifiedRoutes[$page])) {
    $routeArgs = $_GET;
    unset($routeArgs['page']);
    $query = http_build_query(array_merge(['page' => 'settings'], $routeArgs), '', '&', PHP_QUERY_RFC3986);
    header('Location: index.php?'.$query.'#g360-sec-'.$g360UnifiedRoutes[$page], true, 302);
    exit;
}

$selectedOrderId = max(0, (int)($_GET['order'] ?? 0));
$products = all($db, 'SELECT * FROM products WHERE company_id=? AND active=1 ORDER BY category,name', [$cid]);
$tables   = all($db, 'SELECT * FROM dining_tables WHERE company_id=? ORDER BY name', [$cid]);
$orders   = all($db, "SELECT o.*, t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id=o.table_id WHERE o.company_id=? ORDER BY CASE WHEN o.status='aberto' THEN 0 ELSE 1 END, o.id DESC LIMIT 250", [$cid]);
$reservations = all($db, 'SELECT r.*, t.name AS table_name FROM reservations r LEFT JOIN dining_tables t ON t.id=r.table_id WHERE r.company_id=? ORDER BY r.start_at DESC LIMIT 100', [$cid]);

$till      = till($db, $cid);
$today     = date('Y-m-d');
$daySales  = scalar($db, 'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND created_at>=?', [$cid, $today]);
$weekSales = scalar($db, 'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND created_at>=?', [$cid, date('Y-m-d', strtotime('monday this week'))]);
$monthSales = scalar($db, 'SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND created_at>=?', [$cid, date('Y-m-01')]);

$low           = scalar($db, 'SELECT COUNT(*) FROM products WHERE company_id=? AND qty<=min_qty', [$cid]);
$openOrders    = scalar($db, "SELECT COUNT(*) FROM orders WHERE company_id=? AND status='aberto'", [$cid]);
$totalProducts = scalar($db, 'SELECT COUNT(*) FROM products WHERE company_id=?', [$cid]);
$ugAtProductLimit = $ugMaxProducts !== null && $totalProducts >= $ugMaxProducts;

$cashExpected = 0;
if ($till) {
    $cashExpected = (int)$till['opening_cents']
        + scalar($db, "SELECT COALESCE(SUM(CASE WHEN type='entrada' THEN amount_cents ELSE -amount_cents END),0) FROM cash_movements WHERE company_id=? AND till_id=?", [$cid, $till['id']])
        + scalar($db, "SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE company_id=? AND till_id=? AND method='dinheiro'", [$cid, $till['id']]);
}

$flash = $_SESSION['flash'] ?? null;
if (is_string($flash)) {
    $flash = ['text' => $flash, 'type' => 'ok'];
} elseif (is_array($flash)) {
    $flash = [
        'text' => (string)($flash['text'] ?? ''),
        'type' => in_array($flash['type'] ?? 'ok', ['ok', 'error', 'warning'], true) ? $flash['type'] : 'ok',
    ];
} else {
    $flash = null;
}
unset($_SESSION['flash']);

$productCategories = [];
foreach ($products as $product) {
    $cat = $product['category'] ?: 'Geral';
    $productCategories[$cat][] = $product;
}
ksort($productCategories);

$openOrdersList = array_values(array_filter($orders, fn($o) => $o['status'] === 'aberto'));
if (!$selectedOrderId && $page === 'pos' && $openOrdersList) {
    $selectedOrderId = (int)$openOrdersList[0]['id'];
}
$selectedOrder = $selectedOrderId
    ? one($db, "SELECT o.*, t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id=o.table_id WHERE o.id=? AND o.company_id=?", [$selectedOrderId, $cid])
    : null;
$selectedItems = $selectedOrder
    ? all($db, 'SELECT oi.*, p.image_path FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id AND p.company_id=? WHERE oi.order_id=? ORDER BY oi.id DESC', [$cid, $selectedOrder['id']])
    : [];

$currentRevenueCount = scalar($db, 'SELECT COUNT(*) FROM payments WHERE company_id=? AND created_at>=?', [$cid, $today]);
$topProducts = all($db, "SELECT oi.name, SUM(oi.qty) AS units, SUM(oi.qty*oi.price_cents) AS total FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.company_id=? AND o.status='pago' GROUP BY oi.name ORDER BY total DESC LIMIT 5", [$cid]);

function form_start($return) {
    echo '<form method="post" action="actions.php" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.h(token()).'"><input type="hidden" name="return" value="'.h($return).'">';
}
?>
<!doctype html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=h($pages[$page])?> · UniGest</title>
<?=g360_styles($page)?>
<link rel="icon" href="favicon.png?v=41" type="image/png">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=41">
</head>

<body data-page="<?=h($page)?>" data-animations="<?=!empty($g360CurrentPrefs['animations_enabled'])?'on':'off'?>" data-orders-design="<?=h($g360CurrentPrefs['orders_design'])?>" data-orders-panels="<?=(int)$g360CurrentPrefs['orders_panels']?>">
<div class="app-shell" id="app-shell">
    <div class="sidebar-backdrop" id="sidebar-backdrop" hidden></div>

    <aside class="sidebar glass" id="sidebar" aria-label="Menu do painel">
        <button type="button" class="unigest-sidebar-collapse" id="unigest-sidebar-collapse" aria-label="Recolher menu lateral" aria-expanded="true" aria-controls="sidebar" title="Recolher menu">‹</button>
        <a class="brand" href="index.php" title="Página inicial da UniGest">
            <img src="logo.png?v=41" class="g360-brand-full" width="180" height="60" alt="UniGest">
        </a>
        <div class="company-chip glass-lite">
            <div class="company-symbol">◈</div>
            <div>
                <strong><?=h($company['name'])?></strong>
                <small><?=h(current_company_role($db, $cid)['name'] ?? 'Equipa')?> · Empresa</small>
            </div>
            <a href="companies.php" title="Trocar empresa">⇄</a>
        </div>
        <nav class="nav-list v20-sidebar-nav" aria-label="Navegação principal">
        <?php
        $menuGroups = [
            ['label' => 'PRINCIPAL',          'items' => ['dashboard','pos','orders','tables','reservations','products','customers'], 'always' => true],
            ['label' => 'GESTÃO E CONTROLO',  'items' => ['settings'], 'always' => false],
        ];
        require_once __DIR__.'/app/view/icons.php';
        $icons = [
            'dashboard'    => 'dashboard',
            'pos'          => 'sales',
            'orders'       => 'orders',
            'tables'       => 'table',
            'reservations' => 'calendar',
            'products'     => 'box',
            'customers'    => 'team',
            'cash'         => 'sales',
            'reports'      => 'chart',
            'exports'      => 'download',
            'fiscal'       => 'sales',
            'ai10'         => 'spark',
            'logs'         => 'orders',
            'settings'     => 'settings',
        ];
        ?>
        <?php foreach ($menuGroups as $group):
            $items = array_values(array_filter(
                $group['items'],
                fn($menuPage) => can_access_page($db, $cid, $menuPage)
                    && (!g360_module_for_page($menuPage) || g360_feature($db, $cid, g360_module_for_page($menuPage)))
            ));
            if (!$items) continue;
            $isExpanded = in_array($page, $items, true) || $group['always'];
        ?>
            <details class="v20-nav-group" <?=$isExpanded ? 'open' : ''?>>
                <summary><?=h($group['label'])?><span class="v20-sidebar-caret">⌄</span></summary>
                <?php foreach ($items as $k): ?>
                    <a class="nav-item <?=$page === $k ? 'selected' : ''?>" href="?page=<?=h($k)?>" <?=$page === $k ? 'aria-current="page"' : ''?>>
                        <span class="nav-icon"><?=ug_icon($icons[$k])?></span>
                        <span><?=h($pages[$k])?></span>
                        <?php if ($k === 'orders' && $openOrders): ?><b><?=$openOrders?></b><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
        </nav>
        <a class="ug-plan-sidebar" href="plans.php">
            <span aria-hidden="true">✦</span>
            <span>Planos e subscrição<small><?=h($ugCurrentSub['state']==='legacy' ? 'Acesso existente' : ($ugCurrentSub['state']==='trial' ? 'Período de teste' : ($ugCurrentSub['state']==='active' ? 'Plano ativo' : 'Renovação necessária')))?></small></span>
        </a>
        <div class="sidebar-footer">
            <?php if ($g360On('cash_enabled')): ?>
                <div class="side-stat glass-lite">
                    <small>Caixa atual</small>
                    <strong><?=money($cashExpected)?></strong>
                </div>
            <?php endif; ?>
            <a class="sidebar-account-switch" href="account_switch.php" title="Trocar de conta"><span aria-hidden="true">⇄</span><span class="sidebar-footer-label">Trocar de conta</span></a>
            <a class="logout-link" href="logout.php" title="Terminar sessão"><span aria-hidden="true">↪</span><span class="sidebar-footer-label">Terminar sessão</span></a>
        </div>
    </aside>

    <div class="main-area">
        <header class="topbar">
            <button type="button" class="icon-btn mobile-menu" id="toggle-menu" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false">☰</button>
            <div>
                <div class="eyebrow">GESTÃO EMPRESARIAL · <?=h(strtoupper($pages[$page]))?></div>
                <h1><?=h($pages[$page])?></h1>
            </div>
            <div class="top-actions">
                <span class="date-chip"><?=date('d/m/Y')?></span>
                <?php if ($g360On('cash_enabled')): ?><span class="status-pill <?=$till ? 'active' : ''?>"><i></i><?=$till ? 'Caixa aberta' : 'Caixa fechada'?></span><?php endif; ?>
                <a class="avatar" href="account_switch.php" title="Contas autenticadas">◎</a>
            </div>
        </header>

        <main class="content">
            <?php if ($flash): ?><div class="alert <?=h($flash['type'])?>"><?=h($flash['text'])?></div><?php endif; ?>

            <div class="ug-subscription-banner" role="status">
                <div>
                    <strong><?=h($ugCurrentSub['state']==='legacy' ? 'Plano existente' : ($ugCurrentSub['state']==='trial' ? 'Período de experimentação' : ($ugCurrentSub['state']==='active' ? 'Subscrição ativa' : 'Subscrição terminada')))?></strong>
                    <span>
                    <?php if ($ugCurrentSub['state'] === 'legacy'): ?>
                        O acesso atual foi preservado para a tua empresa.
                    <?php elseif ($ugCurrentSub['state'] === 'expired'): ?>
                        As operações estão suspensas até à renovação. Os dados continuam guardados.
                    <?php else: ?>
                        <?=h('Validade: '.date('d/m/Y', strtotime($ugCurrentSub['ends_at'])))?> · <?=$ugMaxProducts === null ? 'Sem limite de produtos' : 'Máximo de '.(int)$ugMaxProducts.' produtos'?>
                    <?php endif; ?>
                    </span>
                </div>
                <a href="plans.php">Ver planos e limites →</a>
            </div>

            <?php if ($page === 'dashboard'): ?>
                <?php require __DIR__.'/app/view/dashboard-summary.php'; ?>
                <?php require __DIR__.'/app/view/dashboard-insights.php'; ?>

                <div class="v18-dashboard-columns">
                    <section class="panel glass">
                        <div class="panel-heading"><h3>Acesso rápido</h3><span class="tag">Operações</span></div>
                        <div class="v18-dash-actions">
                            <?php if (has_permission($db, $cid, 'pos.use') && g360_feature($db, $cid, 'pos_enabled')): ?>
                                <a href="?page=<?=$till ? 'pos' : (has_permission($db, $cid, 'cash.manage') ? 'cash' : 'pos')?>" class="v18-dash-link">
                                    <span>⌘</span><strong><?=$till ? 'Abrir POS' : 'Caixa fechada'?></strong><small><?=$till ? 'Criar e consultar pedidos' : 'É preciso abrir a caixa primeiro'?></small>
                                </a>
                            <?php endif; ?>
                            <?php if (has_permission($db, $cid, 'reservations.manage') && g360_feature($db, $cid, 'booking_enabled')): ?>
                                <a href="?page=reservations" class="v18-dash-link"><span>◷</span><strong>Agenda</strong><small>Reservas e disponibilidade</small></a>
                            <?php endif; ?>
                            <?php if (has_permission($db, $cid, 'products.view') && g360_feature($db, $cid, 'products_enabled')): ?>
                                <a href="?page=products" class="v18-dash-link"><span>◈</span><strong>Inventário</strong><small>Produtos e stock</small></a>
                            <?php endif; ?>
                            <?php if (has_permission($db, $cid, 'fiscal.use') && $g360On('fiscal_enabled')): ?>
                                <a href="?page=fiscal" class="v18-dash-link"><span>▣</span><strong>Faturação AT</strong><small>Documentos fiscais e SAF-T</small></a>
                            <?php endif; ?>
                            <?php if (has_permission($db, $cid, 'ai.use') && $g360On('ai_enabled')): ?>
                                <a href="?page=ai10" class="v18-dash-link"><span>✦</span><strong>IA de gestão</strong><small>Ações com confirmação</small></a>
                            <?php endif; ?>
                        </div>
                    </section>

                    <?php if ($g360On('booking_enabled') && has_permission($db, $cid, 'reservations.manage')): ?>
                        <section class="panel glass">
                            <div class="panel-heading">
                                <h3>Próximas reservas</h3>
                                <a href="?page=reservations">Abrir agenda →</a>
                            </div>
                            <div class="v18-next-reservations">
                            <?php
                            $soon = all($db, "SELECT r.customer, r.guests, r.start_at, t.name AS table_name FROM reservations r LEFT JOIN dining_tables t ON t.id=r.table_id AND t.company_id=r.company_id WHERE r.company_id=? AND r.status='confirmada' AND r.start_at>=? ORDER BY r.start_at LIMIT 5", [$cid, date('Y-m-d H:i')]);
                            foreach ($soon as $res): ?>
                                <div class="v18-reservation-summary">
                                    <time><?=h(substr($res['start_at'], 0, 16))?></time>
                                    <strong><?=h($res['customer'])?></strong>
                                    <small><?=h($res['table_name'] ?? 'A atribuir')?> · <?=$res['guests']?> pessoas</small>
                                </div>
                            <?php endforeach;
                            if (!$soon): ?><p class="muted">Sem reservas próximas.</p><?php endif; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'pos' && !$till): ?>
                <section class="panel glass v18-pos-locked">
                    <div class="v18-lock-icon">⌘</div>
                    <span class="tag">CAIXA FECHADA</span>
                    <h2>Abre primeiro a caixa registadora</h2>
                    <p>Para registar pedidos, alterar quantidades no POS ou cobrar, uma pessoa autorizada tem de abrir a caixa.</p>
                    <?php if (has_permission($db, $cid, 'cash.manage')): ?>
                        <a class="btn btn-main btn-xl" href="?page=cash">Abrir caixa →</a>
                    <?php else: ?>
                        <p class="alert warning">Pede ao gerente ou dono para abrir a caixa.</p>
                    <?php endif; ?>
                </section>

            <?php elseif ($page === 'pos'): ?>
                <section class="pos-layout">
                    <aside class="pos-sidebar glass">
                        <div class="panel-heading"><h3>Pedido / Mesa</h3><span class="tag"><?=$selectedOrder ? 'Ativo' : 'Sem seleção'?></span></div>

                        <div class="mini-toolbar">
                            <?php form_start('pos'); ?>
                                <input type="hidden" name="action" value="new_order">
                                <input type="hidden" name="origin" value="balcao">
                                <button class="btn btn-main btn-block">＋ Novo pedido balcão</button>
                            </form>
                            <?php if ($g360On('tables_enabled')): ?>
                                <button class="btn btn-outline btn-block" type="button" data-toggle="table-order-form">＋ Novo pedido mesa</button>
                            <?php endif; ?>
                        </div>

                        <?php if ($g360On('tables_enabled')): ?>
                            <div class="drawer-card glass-lite" id="table-order-form" hidden>
                                <?php form_start('pos'); ?>
                                    <input type="hidden" name="action" value="new_order">
                                    <input type="hidden" name="origin" value="mesa">
                                    <label>Mesa
                                        <select name="table_id" required>
                                            <?php foreach ($tables as $tb): ?>
                                                <option value="<?=$tb['id']?>"><?=h($tb['name'])?> (<?=$tb['seats']?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <button class="btn btn-main btn-block">Criar pedido mesa</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if ($g360On('tables_enabled') && $tables): ?>
                            <div class="pos-table-section">
                                <div class="section-mini-heading"><strong>Selecionar mesa</strong><small>Toque numa mesa para abrir o pedido</small></div>
                                <div class="pos-table-grid">
                                <?php foreach ($tables as $tb):
                                    $tableOpen = null;
                                    foreach ($openOrdersList as $orderCandidate) {
                                        if ((int)$orderCandidate['table_id'] === (int)$tb['id']) {
                                            $tableOpen = $orderCandidate;
                                            break;
                                        }
                                    }
                                    if ($tableOpen): ?>
                                        <a class="pos-table-btn busy" href="?page=pos&order=<?=$tableOpen['id']?>"><strong>⌗ <?=h($tb['name'])?></strong><small>Pedido #<?=$tableOpen['id']?></small></a>
                                    <?php else: ?>
                                        <?php form_start('pos'); ?>
                                            <input type="hidden" name="action" value="new_order">
                                            <input type="hidden" name="origin" value="mesa">
                                            <input type="hidden" name="table_id" value="<?=$tb['id']?>">
                                            <button class="pos-table-btn" type="submit"><strong>⌗ <?=h($tb['name'])?></strong><small>Livre · <?=$tb['seats']?> lugares</small></button>
                                        </form>
                                    <?php endif;
                                endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="section-mini-heading"><strong>Pedidos em aberto</strong><small><?=$openOrders?> pendente(s)</small></div>
                        <div class="order-picker">
                            <?php foreach ($openOrdersList as $order): ?>
                                <a class="order-choice <?=$selectedOrderId === (int)$order['id'] ? 'active' : ''?>" href="?page=pos&order=<?=$order['id']?>">
                                    <div>
                                        <strong>#<?=$order['id']?> · <?=h($order['origin'] === 'mesa' ? ($order['table_name'] ?: 'Mesa') : 'Balcão')?></strong>
                                        <small><?=substr($order['created_at'], 11, 5)?></small>
                                    </div>
                                    <b><?=money($order['total_cents'])?></b>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$openOrdersList): ?><div class="empty-mini">Ainda não existem pedidos em aberto.</div><?php endif; ?>
                        </div>

                        <?php if ($g360On('cash_enabled')): ?>
                            <div class="pos-box-summary glass-lite">
                                <small>Dinheiro previsto</small>
                                <strong><?=money($cashExpected)?></strong>
                                <p><?=$till ? 'Caixa pronta para cobrar pedidos.' : 'Abre a caixa para poder cobrar.'?></p>
                                <a class="btn btn-outline btn-block" href="?page=cash"><?=$till ? 'Gerir caixa' : 'Abrir caixa'?></a>
                            </div>
                        <?php endif; ?>
                    </aside>

                    <section class="pos-center glass">
                        <div class="panel-heading pos-heading">
                            <div>
                                <h3>Produtos</h3>
                                <p class="muted">Botões grandes para tablet, balcão e POS.</p>
                            </div>
                            <div class="search-box"><input type="search" id="pos-search" placeholder="Pesquisar produto..."></div>
                        </div>

                        <div class="category-tabs" id="category-tabs">
                            <button class="category-pill active" data-category="all">Todos</button>
                            <?php foreach (array_keys($productCategories) as $cat): ?>
                                <button class="category-pill" data-category="<?=h(category_class($cat))?>"><?=h($cat)?></button>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!$products): ?>
                            <div class="empty-state"><div><h3>Ainda não há produtos</h3><p>Adiciona os produtos reais do teu estabelecimento para os poderes utilizar no POS.</p><a href="?page=products" class="btn btn-main">Ir para produtos</a></div></div>
                        <?php endif; ?>

                        <div class="pos-products-grid" id="pos-products-grid">
                        <?php foreach ($productCategories as $cat => $items):
                            foreach ($items as $p): ?>
                                <div class="pos-product" data-category="<?=h(category_class($cat))?>" data-name="<?=h($p['name'])?>">
                                <?php if ($selectedOrder && $selectedOrder['status'] === 'aberto'): ?>
                                    <button type="button" class="product-big-btn pos-add-qty-trigger <?=$p['qty'] < 1 ? 'disabled' : ''?>" <?=$p['qty'] < 1 ? 'disabled' : ''?> data-order-id="<?=$selectedOrder['id']?>" data-product-id="<?=$p['id']?>" data-product-name="<?=h($p['name'])?>" data-stock="<?=$p['qty']?>" data-price="<?=money($p['price_cents'])?>">
                                        <img class="product-photo" src="<?=h(product_image($p))?>" alt="Fotografia de <?=h($p['name'])?>" loading="lazy"><span class="pill-cat"><?=h($cat)?></span>
                                        <strong><?=h($p['name'])?></strong>
                                        <em><?=money($p['price_cents'])?></em>
                                        <small>Stock: <?=$p['qty']?> · Escolher quantidade</small>
                                    </button>
                                <?php else: ?>
                                    <div class="product-big-btn disabled static">
                                        <img class="product-photo" src="<?=h(product_image($p))?>" alt="Fotografia de <?=h($p['name'])?>" loading="lazy"><span class="pill-cat"><?=h($cat)?></span>
                                        <strong><?=h($p['name'])?></strong>
                                        <em><?=money($p['price_cents'])?></em>
                                        <small>Seleciona ou cria um pedido</small>
                                    </div>
                                <?php endif; ?>
                                </div>
                            <?php endforeach;
                        endforeach; ?>
                        </div>
                    </section>

                    <aside class="pos-cart glass">
                        <div class="panel-heading"><h3>Resumo do pedido</h3><span class="tag"><?=$selectedOrder ? '#'.$selectedOrder['id'] : '—'?></span></div>
                        <?php if ($selectedOrder): ?>
                            <div class="cart-head glass-lite">
                                <div>
                                    <strong><?=h($selectedOrder['origin'] === 'mesa' ? ($selectedOrder['table_name'] ?: 'Mesa') : 'Pedido de balcão')?></strong>
                                    <small><?=h($selectedOrder['created_at'])?></small>
                                </div>
                                <div class="tag <?=$selectedOrder['status'] === 'pago' ? 'tag-ok' : ''?>"><?=h($selectedOrder['status'])?></div>
                            </div>
                            <div class="cart-items">
                                <?php foreach ($selectedItems as $item): ?>
                                    <div class="cart-row">
                                        <div class="g360-cart-product"><img src="<?=h(product_image($item))?>" alt="" loading="lazy"><div><strong><?=h($item['name'])?></strong><small><?=$item['qty']?> × <?=money($item['price_cents'])?></small></div></div>
                                        <div class="cart-item-actions">
                                            <b><?=money($item['qty'] * $item['price_cents'])?></b>
                                            <?php if ($selectedOrder['status'] === 'pago'): ?><a href="receipt.php?order=<?=(int)$selectedOrder['id']?>" class="btn btn-main btn-block">Ver / imprimir comprovativo</a><?php endif; ?>
                                            <?php if ($selectedOrder['status'] === 'aberto'): ?>
                                                <button type="button" class="btn btn-xs btn-outline cart-remove-qty-trigger" data-order-id="<?=$selectedOrder['id']?>" data-item-id="<?=$item['id']?>" data-item-name="<?=h($item['name'])?>" data-max-qty="<?=$item['qty']?>">− Alterar quantidade</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (!$selectedItems): ?><div class="empty-mini">Ainda não existem produtos neste pedido.</div><?php endif; ?>
                            </div>
                            <div class="cart-total glass-lite">
                                <small>Total</small>
                                <strong><?=money($selectedOrder['total_cents'])?></strong>
                            </div>
                            <?php if ($selectedOrder['status'] === 'aberto'): ?>
                                <?php form_start('pos'); ?>
                                    <input type="hidden" name="action" value="pay_order">
                                    <input type="hidden" name="order_id" value="<?=$selectedOrder['id']?>">
                                    <label>Método de pagamento
                                        <select name="method">
                                            <option value="dinheiro">Dinheiro</option>
                                            <option value="cartao">Cartão</option>
                                            <option value="mbway">MB Way</option>
                                            <option value="transferencia">Transferência</option>
                                        </select>
                                    </label>
                                    <div class="g360-checkout-fields">
                                        <label>NIF do cliente (opcional)<input name="receipt_nif" inputmode="numeric" maxlength="9" pattern="[0-9]{9}" placeholder="9 dígitos"></label>
                                        <?php if ($g360On('receipt_email_enabled')): ?><label>Email para recibo (opcional)<input type="email" name="receipt_email" maxlength="160" placeholder="cliente@exemplo.pt"></label><?php endif; ?>
                                    </div>
                                    <p class="muted g360-checkout-note">Depois de cobrar podes imprimir ou enviar um comprovativo. A fatura fiscal é emitida separadamente pelo fornecedor certificado.</p>
                                    <button class="btn btn-main btn-block btn-xl" <?=$till ? '' : 'disabled title="Abre a caixa primeiro"'?>>Cobrar e gerar recibo</button>
                                </form>
                                <?php form_start('pos'); ?>
                                    <input type="hidden" name="action" value="cancel_order">
                                    <input type="hidden" name="order_id" value="<?=$selectedOrder['id']?>">
                                    <button type="submit" class="btn btn-outline btn-block cancel-order" data-confirm="Queres cancelar este pedido e devolver os produtos ao stock?">Cancelar pedido</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="empty-state tall">Cria ou seleciona um pedido para começares a adicionar produtos.</div>
                        <?php endif; ?>
                    </aside>
                </section>

                <dialog class="product-modal generic-action-modal quantity-modal" id="pos-add-qty-modal" aria-labelledby="pos-add-title">
                    <div class="product-modal-head">
                        <span class="modal-eyebrow">CAIXA · ARTIGOS</span>
                        <h2 id="pos-add-title">Adicionar ao pedido</h2>
                        <p id="pos-add-name">Produto selecionado</p>
                        <button type="button" class="product-modal-close" data-qty-close aria-label="Fechar">×</button>
                    </div>
                    <form method="post" action="actions.php" id="pos-add-qty-form">
                        <input type="hidden" name="csrf" value="<?=h(token())?>">
                        <input type="hidden" name="return" value="pos">
                        <input type="hidden" name="action" value="add_item">
                        <input type="hidden" name="order_id" id="pos-add-order-id">
                        <input type="hidden" name="product_id" id="pos-add-product-id">
                        <div class="generic-modal-body">
                            <div class="quantity-current-card"><span>Stock disponível</span><strong id="pos-add-stock">0</strong><span>unidades</span></div>
                            <label>Quantidade a adicionar<input type="number" name="qty" id="pos-add-amount" min="1" max="1000" value="1" required></label>
                            <div class="quantity-preview" id="pos-add-preview" aria-live="polite"></div>
                        </div>
                        <div class="product-modal-footer">
                            <button type="button" class="btn btn-outline" data-qty-close>Cancelar</button>
                            <button type="submit" class="btn btn-main">Adicionar ao pedido</button>
                        </div>
                    </form>
                </dialog>

                <dialog class="product-modal generic-action-modal quantity-modal" id="pos-remove-qty-modal" aria-labelledby="pos-remove-title">
                    <div class="product-modal-head">
                        <span class="modal-eyebrow">CAIXA · ARTIGOS</span>
                        <h2 id="pos-remove-title">Retirar do pedido</h2>
                        <p id="pos-remove-name">Artigo selecionado</p>
                        <button type="button" class="product-modal-close" data-qty-close aria-label="Fechar">×</button>
                    </div>
                    <form method="post" action="actions.php" id="pos-remove-qty-form">
                        <input type="hidden" name="csrf" value="<?=h(token())?>">
                        <input type="hidden" name="return" value="pos">
                        <input type="hidden" name="action" value="remove_item">
                        <input type="hidden" name="order_id" id="pos-remove-order-id">
                        <input type="hidden" name="item_id" id="pos-remove-item-id">
                        <div class="generic-modal-body">
                            <div class="quantity-current-card"><span>Unidades neste pedido</span><strong id="pos-remove-current">0</strong><span>unidades</span></div>
                            <label>Quantidade a retirar<input type="number" name="qty" id="pos-remove-amount" min="1" max="1000" value="1" required></label>
                            <div class="quantity-preview" id="pos-remove-preview" aria-live="polite"></div>
                        </div>
                        <div class="product-modal-footer">
                            <button type="button" class="btn btn-outline" data-qty-close>Cancelar</button>
                            <button type="submit" class="btn btn-main">Confirmar remoção</button>
                        </div>
                    </form>
                </dialog>

            <?php elseif ($page === 'orders'): ?>
                <?php
                $ugOrderItemsById   = [];
                $ugOrderServiceById = [];
                $ugOrderIds = array_map(static fn($row) => (int)$row['id'], $orders);
                if ($ugOrderIds) {
                    $placeholders = implode(',', array_fill(0, count($ugOrderIds), '?'));
                    $ugLines = all($db, "SELECT oi.*, p.image_path FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id AND p.company_id=? WHERE oi.order_id IN ($placeholders) ORDER BY oi.id", array_merge([$cid], $ugOrderIds));
                    foreach ($ugLines as $line) {
                        $ugOrderItemsById[(int)$line['order_id']][] = $line;
                    }
                }
                $ugStatusTotals = ['aberto' => 0, 'pago' => 0, 'cancelado' => 0, 'servir' => 0, 'feito' => 0];
                foreach ($orders as $row) {
                    $oid   = (int)$row['id'];
                    $lines = $ugOrderItemsById[$oid] ?? [];
                    $total = count($lines);
                    $completed = 0;
                    $awaitPayment = 0;
                    $preparing = 0;
                    $ready = 0;
                    foreach ($lines as $line) {
                        if (($line['service_status'] ?? '') === 'feito')             $completed++;
                        if (($line['service_status'] ?? '') === 'aguarda_pagamento') $awaitPayment++;
                        if (($line['service_status'] ?? '') === 'em_preparacao')     $preparing++;
                        if (($line['service_status'] ?? '') === 'pronto')            $ready++;
                    }
                    if (isset($ugStatusTotals[$row['status']])) $ugStatusTotals[$row['status']]++;
                    $ugOrderServiceById[$oid] = ($row['status'] === 'cancelado' ? 'cancelado' : ($total === 0 ? 'vazio' : ($completed === $total ? 'feito' : ($awaitPayment > 0 ? 'pagamento' : ($ready > 0 && $completed + $ready === $total ? 'pronto' : ($preparing > 0 ? 'preparacao' : ($completed > 0 ? 'parcial' : 'servir')))))));
                    if ($ugOrderServiceById[$oid] === 'feito') $ugStatusTotals['feito']++;
                    if (in_array($ugOrderServiceById[$oid], ['servir', 'parcial', 'pagamento', 'preparacao', 'pronto'], true)) $ugStatusTotals['servir']++;
                }
                ?>
                <section class="ug-orders-overview" aria-labelledby="ug-orders-overview-title">
                    <div class="ug-orders-overview-copy">
                        <span class="eyebrow">GESTÃO DO SERVIÇO</span>
                        <h2 id="ug-orders-overview-title">Pedidos, do início à entrega.</h2>
                        <p>Consulta os pedidos do balcão e das mesas, acompanha os artigos e atualiza o estado de serviço. Os pedidos do menu ficam aqui depois da aprovação.</p>
                    </div>
                    <div class="ug-orders-summary-cards">
                        <div><small>Em aberto</small><strong data-ug-order-summary="aberto"><?= $ugStatusTotals['aberto'] ?></strong></div>
                        <div><small>Por servir</small><strong data-ug-order-summary="servir"><?= $ugStatusTotals['servir'] ?></strong></div>
                        <div><small>Serviço concluído</small><strong data-ug-order-summary="feito"><?= $ugStatusTotals['feito'] ?></strong></div>
                        <div><small>Pagos</small><strong data-ug-order-summary="pago"><?= $ugStatusTotals['pago'] ?></strong></div>
                    </div>
                </section>

                <?php if (has_permission($db, $cid, 'orders.manage') && $g360On('menu_enabled')):
                    $menuAccess = [];
                    $menuRequests = [];
                    $menuHistory = [];
                    $ugMenuInboxError = '';
                    try {
                        require_once __DIR__.'/app/domain/menu_request_tracking.php';
                        ug_menu_tracking_setup($db);
                        require_once __DIR__.'/app/domain/menu_queue.php';
                        $menuAccess   = unigest_pending_menu_access($db, $cid);
                        $menuRequests = unigest_pending_menu_products($db, $cid);
                        $menuHistory  = unigest_recent_menu_decisions($db, $cid);
                    } catch (Throwable $e) {
                        error_log('UniGest pedidos: falha na consulta da fila de menu: '.$e->getMessage());
                        $ugMenuInboxError = 'Não foi possível atualizar as aprovações do menu. Os pedidos internos continuam disponíveis. Verifica a base de dados e as permissões de escrita.';
                    }
                    $ugCanAcceptMenuItem = has_permission($db, $cid, 'pos.use') && (bool)$till;
                ?>
                <section class="panel glass unigest-menu-inbox" data-menu-staff data-csrf="<?=h(token())?>">
                    <?php if ($ugMenuInboxError): ?><p class="alert warning" role="status"><?=h($ugMenuInboxError)?></p><?php endif; ?>
                    <div class="panel-heading unigest-menu-inbox-heading">
                        <div>
                            <span class="eyebrow">MENU DIGITAL · EQUIPA</span>
                            <h3>Centro de aprovação de pedidos</h3>
                            <p class="muted">Primeiro autoriza o acesso à mesa. Depois confirma cada artigo enviado. As decisões ficam registadas no histórico e o cliente acompanha os estados no menu.</p>
                        </div>
                        <span class="tag" data-menu-pending-count><?=count($menuAccess) + count($menuRequests)?> pendentes</span>
                    </div>
                    <div class="ug-staff-queue-summary">
                        <div><small>Acessos por confirmar</small><strong data-ug-access-count><?=count($menuAccess)?></strong></div>
                        <div><small>Artigos por aprovar</small><strong data-ug-product-count><?=count($menuRequests)?></strong></div>
                        <div><small>Últimas decisões</small><strong data-ug-decisions-count><?=count($menuHistory)?></strong></div>
                    </div>
                    <div class="unigest-inbox-section">
                        <div class="unigest-inbox-heading"><h4>⌗ Pedidos de acesso às mesas</h4><span><?=count($menuAccess)?> em espera</span></div>
                        <div data-menu-access-list class="unigest-menu-queue">
                        <?php foreach ($menuAccess as $a): ?>
                            <article class="unigest-inbox-card">
                                <span class="unigest-inbox-icon">⌗</span>
                                <div class="unigest-inbox-copy">
                                    <strong>Autorização de acesso · <?=h($a['table_name'])?></strong>
                                    <p>O cliente pretende entrar na mesa <b><?=h($a['table_name'])?></b> · <?=(int)$a['table_seats']?> lugares</p>
                                    <small>Pedido em <?=h(substr($a['created_at'], 8, 2).'/'.substr($a['created_at'], 5, 2).' às '.substr($a['created_at'], 11, 5))?></small>
                                </div>
                                <div class="unigest-inbox-buttons">
                                    <?php foreach (['approve' => 'Autorizar esta mesa', 'reject' => 'Recusar'] as $op => $text): ?>
                                        <form method="post" action="menu_staff.php">
                                            <input type="hidden" name="csrf" value="<?=h(token())?>">
                                            <input type="hidden" name="operation" value="<?=$op?>">
                                            <input type="hidden" name="id" value="<?=(int)$a['id']?>">
                                            <button class="btn btn-xs <?=$op === 'approve' ? 'btn-main' : 'btn-outline'?>"><?=$text?></button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$menuAccess): ?><p class="unigest-inbox-empty">Não existem pedidos de autorização pendentes.</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="unigest-inbox-section">
                        <div class="unigest-inbox-heading"><h4>＋ Artigos à espera de confirmação</h4><span><?=count($menuRequests)?> pedidos</span></div>
                        <div data-menu-request-list class="unigest-menu-queue">
                        <?php foreach ($menuRequests as $r): ?>
                            <article class="unigest-inbox-card">
                                <span class="unigest-inbox-icon has-photo"><img src="<?=h(product_image($r))?>" alt="" loading="lazy" decoding="async"></span>
                                <div class="unigest-inbox-copy">
                                    <strong><?=h($r['table_name'])?> · <?=h($r['product_name'])?></strong>
                                    <p><?=(int)$r['qty']?> × <?=h($r['product_name'])?></p>
                                    <small><?=h(substr($r['created_at'], 8, 2).'/'.substr($r['created_at'], 5, 2).' às '.substr($r['created_at'], 11, 5))?></small>
                                    <?php if (!$ugCanAcceptMenuItem): ?>
                                        <small class="unigest-inbox-warning">Abre a caixa e confirma o acesso ao POS para aceitar este artigo.</small>
                                    <?php elseif ($r['access_status'] !== 'aprovado' || $r['expires_at'] <= now()): ?>
                                        <small class="unigest-inbox-warning">Autorização da mesa indisponível.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="unigest-inbox-buttons">
                                    <?php foreach (['accept_item' => 'Aceitar artigo', 'reject_item' => 'Recusar'] as $op => $text):
                                        if ($op === 'accept_item' && (!$ugCanAcceptMenuItem || $r['access_status'] !== 'aprovado' || $r['expires_at'] <= now())) continue;
                                    ?>
                                        <form method="post" action="menu_staff.php">
                                            <input type="hidden" name="csrf" value="<?=h(token())?>">
                                            <input type="hidden" name="operation" value="<?=$op?>">
                                            <input type="hidden" name="id" value="<?=(int)$r['id']?>">
                                            <button class="btn btn-xs <?=$op === 'accept_item' ? 'btn-main' : 'btn-outline'?>"><?=$text?></button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$menuRequests): ?><p class="unigest-inbox-empty">Não existem produtos por confirmar.</p><?php endif; ?>
                        </div>
                    </div>
                    <details class="ug-staff-history" <?=(!$menuAccess && !$menuRequests) ? 'open' : ''?>>
                        <summary><span>Histórico de decisões recentes</span><small>Artigos aceites e recusados</small></summary>
                        <div data-menu-history-list class="ug-staff-history-list">
                        <?php foreach ($menuHistory as $history): ?>
                            <article class="ug-staff-history-row">
                                <div>
                                    <strong><?=h($history['table_name'])?> · <?=(int)$history['qty']?> × <?=h($history['product_name'])?></strong>
                                    <small><?=h(substr($history['created_at'], 8, 2).'/'.substr($history['created_at'], 5, 2).' às '.substr($history['created_at'], 11, 5))?><?php if ($history['order_id']): ?> · Pedido #<?=(int)$history['order_id']?><?php endif; ?></small>
                                </div>
                                <span data-state="<?=h($history['status'])?>"><?=$history['status'] === 'aceite' ? 'Aceite' : ($history['status'] === 'cancelado' ? 'Cancelado pelo cliente' : 'Recusado')?></span>
                            </article>
                        <?php endforeach; ?>
                        <?php if (!$menuHistory): ?><p class="unigest-inbox-empty">Ainda não existem decisões recentes.</p><?php endif; ?>
                        </div>
                    </details>
                    <div class="unigest-inbox-footer">
                        <span class="unigest-connection-dot"></span>
                        <small data-menu-sync-status>Atualização automática quando o painel está aberto.</small>
                    </div>
                </section>
                <?php endif; ?>

                <div class="section-grid two-thirds">
                    <section class="panel glass">
                        <h3>Pedidos abertos</h3>
                        <?php if ($g360On('pos_enabled')): ?>
                            <p class="muted">O POS também permite cobrar e adicionar produtos diretamente.</p>
                            <a class="btn btn-outline" href="?page=pos">Abrir interface POS →</a>
                        <?php else: ?>
                            <p class="muted">Consulta e acompanha os pedidos ativos desta empresa.</p>
                        <?php endif; ?>
                    </section>
                    <section class="panel glass">
                        <h3>Movimento comercial</h3>
                        <div class="big-value"><?=$openOrders?></div>
                        <p class="muted">Pedidos à espera de pagamento</p>
                    </section>
                </div>

                <section class="panel glass unigest-orders-controls">
                    <label>Pesquisar pedidos<input type="search" id="unigest-orders-search" placeholder="Pedido #, nome da mesa ou balcão"></label>
                    <label>Estado do pedido
                        <select id="unigest-orders-status">
                            <option value="all">Todos</option>
                            <option value="aberto">Abertos</option>
                            <option value="pago">Pagos</option>
                            <option value="cancelado">Cancelados</option>
                        </select>
                    </label>
                    <label>Origem
                        <select id="unigest-orders-origin">
                            <option value="all">Mesas e balcão</option>
                            <option value="mesa">Mesa</option>
                            <option value="balcao">Balcão</option>
                        </select>
                    </label>
                    <label>Ordenar
                        <select id="unigest-orders-sort">
                            <option value="newest">Mais recentes</option>
                            <option value="oldest">Mais antigos</option>
                            <option value="highest">Maior valor</option>
                            <option value="lowest">Menor valor</option>
                        </select>
                    </label>
                    <div class="ug-orders-service-filters" role="group" aria-label="Filtrar pelo estado do serviço">
                        <button type="button" data-ug-orders-service="all" class="is-current" aria-pressed="true">Todos</button>
                        <button type="button" data-ug-orders-service="servir" aria-pressed="false">Por servir</button>
                        <button type="button" data-ug-orders-service="pagamento" aria-pressed="false">À espera de pagamento</button>
                        <button type="button" data-ug-orders-service="preparacao" aria-pressed="false">Em preparação</button>
                        <button type="button" data-ug-orders-service="pronto" aria-pressed="false">Prontos</button>
                        <button type="button" data-ug-orders-service="feito" aria-pressed="false">Concluídos</button>
                    </div>
                    <div class="ug-orders-control-footer">
                        <span id="unigest-orders-count" class="muted"></span>
                        <button type="button" class="btn btn-outline btn-xs" id="ug-orders-refresh">Atualizar pedidos ↻</button>
                    </div>
                    <p class="ug-orders-outdated" id="ug-orders-outdated" role="status" hidden>Foram aprovados artigos do menu. Atualiza a lista para veres os pedidos e os estados mais recentes.</p>
                </section>

                <div id="unigest-orders-list">
                <?php foreach ($orders as $o): ?>
                    <section class="panel glass order-panel" data-order-id="<?=$o['id']?>" data-order-service="<?=h($ugOrderServiceById[(int)$o['id']] ?? 'vazio')?>" data-order-origin="<?=h($o['origin'] === 'mesa' ? 'mesa' : 'balcao')?>" data-order-status="<?=h($o['status'])?>" data-order-value="<?=$o['total_cents']?>" data-order-time="<?=h($o['created_at'])?>" data-order-search="<?=h(strtolower($o['id'].' '.($o['table_name'] ?? 'balcao')))?>">
                        <div class="panel-heading">
                            <div><h3>Pedido #<?=$o['id']?> · <?=h($o['origin'] === 'mesa' ? ($o['table_name'] ?? 'Mesa') : 'Balcão')?></h3><small><?=h($o['created_at'])?></small></div>
                            <div class="inline-actions">
                                <span class="ug-order-service-badge" data-service="<?=h($ugOrderServiceById[(int)$o['id']] ?? 'vazio')?>"><?=h(match($ugOrderServiceById[(int)$o['id']] ?? 'vazio') {
                                    'feito'      => 'Serviço concluído',
                                    'servir'     => 'Por servir',
                                    'parcial'    => 'Parcialmente servido',
                                    'pagamento'  => 'À espera de pagamento',
                                    'preparacao' => 'Em preparação',
                                    'pronto'     => 'Pronto para servir',
                                    'cancelado'  => 'Cancelado',
                                    default      => 'Sem artigos',
                                })?></span>
                                <span class="tag <?=$o['status'] === 'pago' ? 'tag-ok' : ''?>"><?=h($o['status'])?></span>
                                <?php if ($o['status'] === 'aberto' && $g360On('pos_enabled')): ?>
                                    <a class="btn btn-xs btn-outline" href="?page=pos&order=<?=$o['id']?>">Abrir no POS</a>
                                <?php elseif ($o['status'] === 'pago' && has_permission($db, $cid, 'fiscal.use') && $g360On('fiscal_enabled')): ?>
                                    <a class="btn btn-xs btn-main" href="?page=fiscal&fiscal_order=<?=(int)$o['id']?>">Faturação AT →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="unigest-order-payment-row">
                            <div class="unigest-order-payment-info">
                                <span class="unigest-payment-symbol" aria-hidden="true">€</span>
                                <span>
                                    <strong>Quando paga o cliente?</strong>
                                    <small><?=($o['payment_mode'] ?? 'posterior') === 'antecipado' ? 'Pagamento antes da preparação e do serviço' : 'Pagamento no fim, depois do serviço'?></small>
                                </span>
                            </div>
                            <?php if (has_permission($db, $cid, 'orders.manage') && $o['status'] === 'aberto'): ?>
                                <form data-autosave="order" class="unigest-prepay-form" method="post" action="actions.php" data-prepay-form>
                                    <input type="hidden" name="csrf" value="<?=h(token())?>">
                                    <input type="hidden" name="return" value="orders">
                                    <input type="hidden" name="action" value="order_payment_mode">
                                    <input type="hidden" name="order_id" value="<?=$o['id']?>">
                                    <label class="unigest-switch">
                                        <input type="checkbox" name="prepayment" value="1" <?=($o['payment_mode'] ?? 'posterior') === 'antecipado' ? 'checked' : ''?> aria-label="Ativar pré-pagamento para o pedido <?=$o['id']?>">
                                        <span class="unigest-switch-track" aria-hidden="true"></span>
                                        <strong>Pré-pagamento</strong>
                                    </label>
                                    <button type="submit" class="btn btn-outline btn-xs" data-autosave-submit>Guardar alteração</button>
                                </form>
                            <?php else: ?>
                                <span class="unigest-payment-badge"><?=($o['payment_mode'] ?? 'posterior') === 'antecipado' ? 'Pré-pagamento' : 'Pagamento no fim'?></span>
                            <?php endif; ?>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Produto</th><th>Qtd.</th><th>Preço</th><th>Subtotal</th><th>Serviço</th></tr></thead>
                                <tbody>
                                <?php $orderLines = $ugOrderItemsById[(int)$o['id']] ?? [];
                                foreach ($orderLines as $item): ?>
                                    <tr>
                                        <td><span class="g360-inline-product"><img src="<?=h(product_image($item))?>" alt="" loading="lazy"><?=h($item['name'])?></span></td>
                                        <td><?=$item['qty']?></td>
                                        <td><?=money($item['price_cents'])?></td>
                                        <td><?=money($item['qty'] * $item['price_cents'])?></td>
                                        <td>
                                        <?php if (has_permission($db, $cid, 'orders.manage')):
                                            $available = ($o['status'] === 'pago')
                                                ? ['pago_aguarda_servico' => 'Pago, à espera do serviço', 'em_preparacao' => 'Em preparação', 'pronto' => 'Pronto para servir', 'feito' => 'Serviço concluído']
                                                : (($o['payment_mode'] ?? 'posterior') === 'antecipado'
                                                    ? ['aguarda_pagamento' => 'À espera de pagamento']
                                                    : ['aguarda_servico' => 'À espera do serviço', 'em_preparacao' => 'Em preparação', 'pronto' => 'Pronto para servir', 'feito' => 'Serviço concluído']);
                                            $currentStatus = (string)($item['service_status'] ?? '');
                                            if (!isset($available[$currentStatus])) $currentStatus = array_key_first($available);
                                        ?>
                                            <form method="post" action="actions.php" class="unigest-service-form" data-autosave="order">
                                                <input type="hidden" name="csrf" value="<?=h(token())?>">
                                                <input type="hidden" name="return" value="orders">
                                                <input type="hidden" name="action" value="item_service_status">
                                                <input type="hidden" name="order_id" value="<?=$o['id']?>">
                                                <input type="hidden" name="item_id" value="<?=$item['id']?>">
                                                <select name="service_status" data-autosave-select aria-label="Estado de serviço do artigo">
                                                    <?php foreach ($available as $value => $description): ?>
                                                        <option value="<?=h($value)?>" <?=$currentStatus === $value ? 'selected' : ''?>><?=h($description)?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="btn btn-xs btn-outline" data-autosave-submit>Guardar</button>
                                            </form>
                                        <?php else: ?>
                                            <?=h(['aguarda_pagamento' => 'À espera de pagamento', 'aguarda_servico' => 'À espera do serviço', 'pago_aguarda_servico' => 'Pago, à espera do serviço', 'em_preparacao' => 'Em preparação', 'pronto' => 'Pronto para servir', 'feito' => 'Serviço concluído'][$item['service_status']] ?? $item['service_status'])?>
                                        <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach;
                                if (!$orderLines): ?><tr><td colspan="5">Sem linhas.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="order-bottom">
                            <strong>Total <?=money($o['total_cents'])?></strong>
                            <?php if ($o['status'] === 'pago'): ?><a class="btn btn-outline btn-sm" href="receipt.php?order=<?=(int)$o['id']?>">▤ Recibo / email</a><?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
                <?php if (!$orders): ?>
                    <div class="ug-orders-no-records" role="status">Ainda não existem pedidos nesta empresa. Para criar um pedido, abre a <a href="?page=pos">Caixa POS</a>. Os artigos do menu digital só aparecem como pedidos internos depois de serem aceites.</div>
                <?php endif; ?>
                </div>

            <?php elseif ($page === 'tables'): ?>
                <section class="panel glass floor-header">
                    <div>
                        <span class="tag">EDITOR DO ESTABELECIMENTO</span>
                        <h2>Mapa das mesas</h2>
                        <p class="muted">Desenha a planta do teu espaço. Arrasta as mesas e coloca paredes, portas ou balcões. O mapa é opcional e fica guardado por empresa.</p>
                    </div>
                    <div class="inline-actions floor-switch">
                        <button type="button" class="btn btn-main" data-map-view="map" aria-pressed="true">◈ Mapa interativo</button>
                        <button type="button" class="btn btn-outline" data-map-view="list" aria-pressed="false">▤ Lista de mesas</button>
                    </div>
                </section>
                <section id="floor-map-panel" class="panel glass floor-panel <?=has_permission($db, $cid, 'tables.layout') ? '' : 'floor-readonly'?>">
                    <?php if (has_permission($db, $cid, 'tables.layout')): ?>
                        <div class="floor-build-control"><div><strong>Modo de construção</strong><small>Ativa apenas quando quiseres alterar a planta. As alterações são guardadas automaticamente.</small></div><button type="button" id="floor-edit-toggle" class="btn btn-outline" aria-pressed="false">Ativar construção</button></div>
                    <?php else: ?>
                        <div class="floor-build-control"><strong>Planta em modo de consulta</strong><small>O teu cargo não tem permissão para construir a planta.</small></div>
                    <?php endif; ?>
                    <?php $floorSize = one($db, 'SELECT width,height,revision FROM floor_settings WHERE company_id=?', [$cid]) ?: ['width' => 1000, 'height' => 1000, 'revision' => 0]; ?>
                    <div class="floor-editor-tools" id="floor-editor-tools" hidden>
                        <details class="settings-drawer">
                            <summary>⚙ Dimensões e configuração da planta</summary>
                            <div class="floor-size-settings">
                                <label>Largura da planta <input id="floor-width" type="number" min="500" max="4000" step="100" value="<?=$floorSize['width']?>"></label>
                                <label>Altura da planta <input id="floor-height" type="number" min="500" max="4000" step="100" value="<?=$floorSize['height']?>"></label>
                                <p class="muted">Medidas em unidades de desenho (500 a 4000). As mesas mantêm as posições ao alterar o tamanho; podes arrastá-las depois.</p>
                            </div>
                        </details>
                        <details class="settings-drawer">
                            <summary>✥ Ferramentas de edição (elementos, formatos e organização)</summary>
                            <div class="floor-tools">
                                <div class="floor-tools-group">
                                    <label for="floor-piece-kind">Adicionar elemento</label>
                                    <select id="floor-piece-kind">
                                        <option value="wall">Parede</option>
                                        <option value="door">Porta</option>
                                        <option value="counter">Balcão</option>
                                        <option value="kitchen">Cozinha</option>
                                        <option value="window">Janela</option>
                                        <option value="label">Zona / etiqueta</option>
                                    </select>
                                    <button type="button" class="btn btn-outline" id="floor-add-piece">+ Colocar</button>
                                </div>
                                <div class="floor-tools-group">
                                    <label for="floor-shape">Formato de mesa</label>
                                    <select id="floor-shape"><option value="round">Redonda</option><option value="square">Quadrada</option><option value="rect">Retangular</option></select>
                                    <span class="floor-help">Seleciona uma mesa para alterar o formato</span>
                                </div>
                                <div class="floor-tools-group floor-actions">
                                    <button type="button" class="btn btn-outline" id="floor-reset">Reorganizar</button>
                                    <span class="floor-autosave-pill"><span class="autosave-dot"></span> Gravação automática · 1 s</span>
                                </div>
                            </div>
                        </details>
                    </div>
                    <div class="floor-inspector" id="floor-inspector" hidden>
                        <div class="floor-inspector-title">
                            <span class="floor-inspector-icon">⇲</span>
                            <div><strong id="floor-selection-name">Elemento selecionado</strong><small>Arrasta o canto inferior direito ou introduz o tamanho exato</small></div>
                            <button type="button" id="floor-deselect" class="floor-inspector-close" aria-label="Fechar edição">×</button>
                        </div>
                        <div class="floor-inspector-fields">
                            <label class="floor-inspector-shape" id="floor-inspector-shape-wrap">Formato
                                <select id="floor-item-shape"><option value="round">Redonda</option><option value="square">Quadrada</option><option value="rect">Retangular</option></select>
                            </label>
                            <label>Largura <span>px</span><input id="floor-item-width" type="number" min="8" max="4000" step="1" inputmode="numeric"></label>
                            <label>Altura <span>px</span><input id="floor-item-height" type="number" min="8" max="4000" step="1" inputmode="numeric"></label>
                            <p>As dimensões são individuais. Podes voltar a alterá-las quando quiseres.</p>
                        </div>
                    </div>
                    <div class="floor-status" id="floor-status" role="status">Arrasta para mover · usa o canto inferior direito para esticar · gravação automática ativa.</div>
                    <div class="floor-viewport" id="floor-viewport">
                        <div class="floor-canvas" id="floor-canvas" data-readonly="<?=has_permission($db, $cid, 'tables.layout') ? '0' : '1'?>" data-building="0" data-csrf="<?=h(token())?>" data-width="<?=$floorSize['width']?>" data-height="<?=$floorSize['height']?>" data-revision="<?=$floorSize['revision']?>" style="width:<?=$floorSize['width']?>px;height:<?=$floorSize['height']?>px;">
                            <span class="floor-grid-label">PLANTA DO ESTABELECIMENTO</span>
                            <?php foreach ($tables as $i => $tb):
                                $busy = one($db, "SELECT id,total_cents FROM orders WHERE company_id=? AND table_id=? AND status='aberto' ORDER BY id DESC LIMIT 1", [$cid, $tb['id']]);
                                $menuBusy = $g360On('menu_enabled') && (int)scalar($db, "SELECT COUNT(*) FROM public_menu_access WHERE company_id=? AND table_id=? AND status='aprovado' AND expires_at>?", [$cid, $tb['id'], now()]) > 0;
                                $px = (int)$tb['map_x'] >= 0 ? (int)$tb['map_x'] : 80 + ($i % 6) * 146;
                                $py = (int)$tb['map_y'] >= 0 ? (int)$tb['map_y'] : 150 + intdiv($i, 6) * 210;
                            ?>
                                <button type="button" class="floor-table <?=($busy || $menuBusy) ? 'is-busy' : ''?>" data-table-id="<?=$tb['id']?>" data-shape="<?=h($tb['map_shape'])?>" data-x="<?=$px?>" data-y="<?=$py?>" data-width="<?=$tb['map_width']?>" data-height="<?=$tb['map_height']?>" style="--fw:<?=$tb['map_width']?>px;--fh:<?=$tb['map_height']?>px;--tx:<?=$px / $floorSize['width'] * 100?>%;--ty:<?=$py / $floorSize['height'] * 100?>%" title="<?=h($tb['name'])?> · <?=$tb['seats']?> lugares">
                                    <span class="floor-table-icon">⌗</span><strong><?=h($tb['name'])?></strong>
                                    <small><?=$tb['seats']?> lugares · <?=($busy || $menuBusy) ? 'Ocupada' : 'Livre'?></small>
                                    <span class="floor-resize-handle" title="Arrastar para redimensionar" aria-hidden="true"></span>
                                </button>
                            <?php endforeach; ?>
                            <?php $pieces = all($db, 'SELECT * FROM floor_objects WHERE company_id=? ORDER BY id', [$cid]);
                            foreach ($pieces as $piece): ?>
                                <div class="floor-object" data-piece-id="<?=$piece['id']?>" data-kind="<?=h($piece['kind'])?>" data-label="<?=h($piece['label'])?>" data-x="<?=$piece['x']?>" data-y="<?=$piece['y']?>" data-width="<?=$piece['width']?>" data-height="<?=$piece['height']?>" style="--tx:<?=$piece['x'] / $floorSize['width'] * 100?>%;--ty:<?=$piece['y'] / $floorSize['height'] * 100?>%;--fw:<?=$piece['width']?>px;--fh:<?=$piece['height']?>px" tabindex="0" role="button" aria-label="Elemento <?=h($piece['label'])?>">
                                    <span><?=h($piece['label'])?></span>
                                    <button type="button" class="floor-delete" title="Remover elemento" aria-label="Remover elemento">×</button>
                                    <span class="floor-resize-handle" title="Arrastar para redimensionar" aria-hidden="true"></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$tables): ?><div class="floor-placeholder">Adiciona a tua primeira mesa no formulário abaixo para começares o mapa.</div><?php endif; ?>
                        </div>
                    </div>
                    <div class="floor-caption">
                        <span><i class="floor-dot green"></i> Mesa livre</span>
                        <?php if ($g360On('orders_enabled')): ?><span><i class="floor-dot orange"></i> Pedido aberto</span><?php endif; ?>
                        <span>↔ Arrasta para mover · ⇲ Estica para redimensionar · Guardado automaticamente</span>
                    </div>
                </section>
                <div class="section-grid two-thirds" id="floor-list-panel" hidden>
                    <section class="panel glass">
                        <h3>Lista de mesas</h3>
                        <div class="table-grid large">
                            <?php foreach ($tables as $tb):
                                $busy = one($db, "SELECT o.id,total_cents FROM orders o WHERE o.company_id=? AND o.table_id=? AND o.status='aberto' ORDER BY o.id DESC LIMIT 1", [$cid, $tb['id']]);
                                $menuBusy = $g360On('menu_enabled') && (int)scalar($db, "SELECT COUNT(*) FROM public_menu_access WHERE company_id=? AND table_id=? AND status='aprovado' AND expires_at>?", [$cid, $tb['id'], now()]) > 0;
                            ?>
                                <div class="dining-table <?=($busy || $menuBusy) ? 'busy' : ''?>" data-list-table-id="<?=$tb['id']?>">
                                    <span>⌗</span><strong><?=h($tb['name'])?></strong><small><?=$tb['seats']?> lugares</small>
                                    <em><?=$busy ? 'Pedido #'.$busy['id'].' aberto' : ($menuBusy ? 'Menu digital autorizado · Ocupada' : 'Disponível')?></em>
                                    <?php if (has_permission($db, $cid, 'tables.manage')): ?>
                                        <button type="button" class="btn btn-xs btn-outline table-edit-trigger" data-id="<?=$tb['id']?>" data-name="<?=h($tb['name'])?>" data-seats="<?=$tb['seats']?>">✎ Editar mesa</button>
                                    <?php endif; ?>
                                    <?php if ($busy && $g360On('pos_enabled')): ?>
                                        <a class="btn btn-xs btn-main" href="?page=pos&order=<?=$busy['id']?>">Ir para POS</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$tables): ?><div class="empty-mini">Ainda não existem mesas registadas.</div><?php endif; ?>
                        </div>
                    </section>
                    <section class="panel glass">
                        <h3>Como utilizar</h3>
                        <p>Escolhe <strong>Mapa interativo</strong> para posicionar mesas e objetos. A disposição fica guardada nesta empresa e não altera pedidos já existentes.</p>
                    </section>
                </div>
                <?php if (has_permission($db, $cid, 'tables.manage')): ?>
                    <section class="panel glass floor-new-table">
                        <div><h3>Adicionar mesa ao estabelecimento</h3><p class="muted">Depois de a criares, posiciona-a no mapa.</p></div>
                        <?php form_start('tables'); ?>
                            <input type="hidden" name="action" value="table">
                            <label>Nome da mesa<input name="name" required placeholder="Ex.: Mesa 01"></label>
                            <label>Lugares<input type="number" name="seats" value="4" min="1" max="100" required></label>
                            <button class="btn btn-main">+ Adicionar mesa</button>
                        </form>
                    </section>
                <?php endif; ?>
                <dialog id="table-edit-modal" class="product-modal generic-action-modal" aria-label="Editar mesa">
                    <div class="product-modal-head"><h2>Editar mesa</h2><button type="button" class="product-modal-close" data-edit-close="table-edit-modal">×</button></div>
                    <div class="generic-modal-body">
                        <form data-autosave="edit" method="post" action="actions.php">
                            <input type="hidden" name="csrf" value="<?=h(token())?>">
                            <input type="hidden" name="return" value="tables">
                            <input type="hidden" name="action" value="table_edit">
                            <input type="hidden" name="table_id" id="table-edit-id">
                            <label>Nome<input name="name" id="table-edit-name" required maxlength="80"></label>
                            <label>Número de lugares<input name="seats" id="table-edit-seats" type="number" min="1" max="100" required></label>
                            <button class="btn btn-main btn-xl" type="submit">Guardar alterações</button>
                        </form>
                    </div>
                </dialog>

            <?php elseif ($page === 'reservations'): ?>
                <?php require __DIR__.'/pages/reservations.php'; ?>

            <?php elseif ($page === 'products'): ?>
                <div class="ug-products-limit">
                    <strong>Capacidade do catálogo:</strong>
                    <?=number_format($totalProducts, 0, ',', ' ')?> / <?=$ugMaxProducts === null ? 'sem limite' : number_format($ugMaxProducts, 0, ',', ' ')?> produtos ·
                    <a href="plans.php">Ver ou mudar plano</a>
                </div>

                <div class="section-grid two-thirds">
                    <section class="panel glass">
                        <div class="panel-heading"><h3>Inventário por categoria</h3><span class="tag"><?=count($products)?> produto(s)</span></div>
                        <?php if (!$products): ?><div class="empty-mini">Ainda não existem produtos. Adiciona o primeiro produto ou escolhe um dos produtos sugeridos no formulário.</div><?php endif; ?>
                        <div class="g360-stock-filters" aria-label="Filtrar produtos por categoria">
                            <button type="button" class="g360-stock-filter is-active" data-stock-category="all" aria-pressed="true">Todas as categorias <span><?=count($products)?></span></button>
                            <?php foreach ($productCategories as $categoryName => $categoryProducts): ?>
                                <button type="button" class="g360-stock-filter" data-stock-category="<?=h(category_class($categoryName))?>" aria-pressed="false"><?=h($categoryName)?> <span><?=count($categoryProducts)?></span></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Produto</th><th>Categoria</th><th>SKU</th><th>PVP</th><th>Custo</th><th>Stock</th><th>Ajustar</th></tr></thead>
                                <tbody>
                                    <?php $lastCategory = null;
                                    foreach ($products as $p): ?>
                                        <?php if ($lastCategory !== $p['category']):
                                            $lastCategory = $p['category']; ?>
                                            <tr class="g360-category-row" data-stock-row-category="<?=h(category_class($p['category'] ?: 'Geral'))?>"><th colspan="7"><span class="g360-category-dot"></span><?=h($lastCategory ?: 'Geral')?></th></tr>
                                        <?php endif; ?>
                                        <tr data-stock-row-category="<?=h(category_class($p['category'] ?: 'Geral'))?>">
                                            <td><div class="inventory-product"><img src="<?=h(product_image($p))?>" alt="" loading="lazy"><strong><?=h($p['name'])?></strong></div></td>
                                            <td><span class="tag"><?=h($p['category'] ?: 'Geral')?></span></td>
                                            <td><?=h($p['sku'])?></td>
                                            <td><?=money($p['price_cents'])?></td>
                                            <td><?=money($p['cost_cents'])?></td>
                                            <td><span class="tag <?=$p['qty'] <= $p['min_qty'] ? 'tag-warn' : 'tag-ok'?>"><?=$p['qty']?> un.</span></td>
                                            <td>
                                                <div class="v18-product-row-actions">
                                                    <button type="button" class="btn btn-xs btn-outline stock-modal-trigger" data-id="<?=$p['id']?>" data-name="<?=h($p['name'])?>" data-qty="<?=$p['qty']?>"><span aria-hidden="true">⚙</span> Alterar quantidade</button>
                                                    <button type="button" class="btn btn-xs btn-outline product-edit-trigger"
                                                        data-id="<?=$p['id']?>" data-name="<?=h($p['name'])?>" data-category="<?=h($p['category'])?>"
                                                        data-sku="<?=h($p['sku'])?>" data-price="<?=number_format($p['price_cents'] / 100, 2, '.', '')?>"
                                                        data-cost="<?=number_format($p['cost_cents'] / 100, 2, '.', '')?>" data-min-qty="<?=$p['min_qty']?>"
                                                        data-image="<?=h(product_image($p))?>">✎ Editar</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$products): ?><tr><td colspan="7">Ainda não existem produtos.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section class="panel glass">
                        <div class="g360-new-product-head"><h3>Novo produto</h3></div>
                        <?php if ($ugAtProductLimit): ?><p class="ug-product-cap-notice">Atingiste o limite do plano. Podes editar ou repor produtos existentes, mas precisas de um plano superior para criar mais. <a href="plans.php">Ver opções →</a></p><?php endif; ?>
                        <?php form_start('products'); ?>
                            <input type="hidden" name="action" value="product">
                            <label>Nome<input name="name" required placeholder="Ex. Café expresso"></label>
                            <label>Fotografia (JPG, PNG ou WebP, até 3 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="product-upload"></label>
                            <label>Categoria<input name="category" placeholder="Ex. Bebidas"></label>
                            <label>Referência / SKU<input name="sku" placeholder="CAF-001"></label>
                            <div class="two-fields">
                                <label>Preço de venda (€)<input name="price" value="1.20" required></label>
                                <label>Custo (€)<input name="cost" value="0.40" required></label>
                            </div>
                            <div class="two-fields">
                                <label>Stock inicial<input name="qty" type="number" value="0" min="0" required></label>
                                <label>Stock mínimo<input name="min_qty" type="number" value="2" min="0" required></label>
                            </div>
                            <button class="btn btn-main" <?=$ugAtProductLimit ? 'disabled title="Limite de produtos do plano atingido"' : ''?>><?=$ugAtProductLimit ? 'Limite de produtos atingido' : 'Adicionar produto'?></button>
                        </form>
                    </section>
                </div>

                <dialog id="stock-edit-modal" class="product-modal generic-action-modal quantity-modal" aria-labelledby="stock-modal-title">
                    <div class="product-modal-head">
                        <span class="modal-eyebrow">INVENTÁRIO · STOCK</span>
                        <h2 id="stock-modal-title">Alterar quantidade</h2>
                        <p id="stock-modal-product">Produto</p>
                        <button type="button" class="product-modal-close" data-stock-close aria-label="Fechar">×</button>
                    </div>
                    <form action="actions.php" method="post" id="stock-edit-form">
                        <input type="hidden" name="csrf" value="<?=h(token())?>">
                        <input type="hidden" name="return" value="products">
                        <input type="hidden" name="action" value="stock">
                        <input type="hidden" name="product_id" id="stock-modal-id">
                        <input type="hidden" name="delta" id="stock-modal-delta">
                        <div class="generic-modal-body">
                            <div class="quantity-current-card"><span>Quantidade atual</span><strong id="stock-modal-current">0</strong><span>unidades</span></div>
                            <div class="quantity-form-grid">
                                <label>Operação
                                    <select id="stock-modal-mode">
                                        <option value="add">+ Adicionar unidades</option>
                                        <option value="remove">− Retirar unidades</option>
                                    </select>
                                </label>
                                <label>Quantidade<input name="amount_ui" id="stock-modal-amount" type="number" min="1" max="100000" step="1" value="1" required></label>
                            </div>
                            <label>Motivo da alteração<input name="reason" id="stock-modal-reason" maxlength="200" required placeholder="Ex.: Reposição de fornecedor"></label>
                            <div class="quantity-preview" id="stock-modal-preview" aria-live="polite">O novo stock será calculado aqui.</div>
                        </div>
                        <div class="product-modal-footer">
                            <button type="button" class="btn btn-outline" data-stock-close>Cancelar</button>
                            <button type="submit" class="btn btn-main">Confirmar alteração</button>
                        </div>
                    </form>
                </dialog>

                <dialog id="product-edit-modal" class="product-modal" aria-labelledby="product-modal-title">
                    <form method="post" action="actions.php" enctype="multipart/form-data" id="product-edit-form" data-autosave="edit">
                        <input type="hidden" name="csrf" value="<?=h(token())?>">
                        <input type="hidden" name="return" value="products">
                        <input type="hidden" name="action" value="product_edit">
                        <input type="hidden" name="product_id" id="product-modal-id">
                        <div class="product-modal-head">
                            <div class="modal-eyebrow">UNIGEST · INVENTÁRIO</div>
                            <h2 id="product-modal-title">Editar produto</h2>
                            <p>Altera os detalhes sem sair da página. O inventário mantém-se em segundo plano.</p>
                            <button type="button" class="product-modal-close" data-product-modal-close aria-label="Fechar janela">×</button>
                        </div>
                        <div class="product-modal-body">
                            <div class="product-modal-image">
                                <img id="product-modal-photo" alt="Fotografia atual do produto" src="assets/img/product-placeholder.svg">
                                <span>Imagem do produto</span>
                                <label>Alterar fotografia <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="product-upload"></label>
                            </div>
                            <div class="product-modal-fields">
                                <label>Nome do produto<input name="name" id="product-modal-name" maxlength="120" required></label>
                                <div class="two-fields">
                                    <label>Categoria<input name="category" id="product-modal-category" maxlength="80"></label>
                                    <label>Referência / SKU<input name="sku" id="product-modal-sku" required></label>
                                </div>
                                <div class="two-fields">
                                    <label>Preço de venda (€)<input name="price" id="product-modal-price" inputmode="decimal" required></label>
                                    <label>Custo (€)<input name="cost" id="product-modal-cost" inputmode="decimal" required></label>
                                </div>
                                <label>Stock mínimo<input type="number" name="min_qty" id="product-modal-min" min="0" required></label>
                                <p class="modal-hint">Para alterar a quantidade disponível, utiliza o menu «Alterar quantidade» no inventário.</p>
                            </div>
                        </div>
                        <div class="product-modal-footer">
                            <button type="button" class="btn btn-outline" data-product-modal-close>Cancelar</button>
                            <button type="submit" class="btn btn-main">✓ Guardar alterações</button>
                        </div>
                    </form>
                </dialog>

            <?php elseif ($page === 'customers'): ?>
                <div class="section-grid two-thirds">
                    <section class="panel glass">
                        <h3>Clientes</h3>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Nome</th><th>NIF</th><th>Telefone</th><th>Email</th><th>Editar</th></tr></thead>
                                <tbody>
                                <?php foreach (all($db, 'SELECT * FROM customers WHERE company_id=? ORDER BY id DESC', [$cid]) as $cu): ?>
                                    <tr>
                                        <td><?=h($cu['name'])?></td>
                                        <td><?=h($cu['nif'])?></td>
                                        <td><?=h($cu['phone'])?></td>
                                        <td><?=h($cu['email'])?></td>
                                        <td><button type="button" class="btn btn-xs btn-outline customer-edit-trigger" data-id="<?=$cu['id']?>" data-name="<?=h($cu['name'])?>" data-nif="<?=h($cu['nif'])?>" data-phone="<?=h($cu['phone'])?>" data-email="<?=h($cu['email'])?>">✎ Editar</button></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <section class="panel glass">
                        <h3>Novo cliente</h3>
                        <?php form_start('customers'); ?>
                            <input type="hidden" name="action" value="customer">
                            <label>Nome<input name="name" required></label>
                            <label>NIF<input name="nif" maxlength="9"></label>
                            <label>Telefone<input name="phone"></label>
                            <label>Email<input name="email" type="email"></label>
                            <button class="btn btn-main">Adicionar cliente</button>
                        </form>
                    </section>
                </div>

                <dialog id="customer-edit-modal" class="product-modal generic-action-modal" aria-label="Editar cliente">
                    <div class="product-modal-head"><h2>Editar cliente</h2><button type="button" class="product-modal-close" data-edit-close="customer-edit-modal">×</button></div>
                    <div class="generic-modal-body">
                        <form data-autosave="edit" method="post" action="actions.php">
                            <input type="hidden" name="csrf" value="<?=h(token())?>">
                            <input type="hidden" name="return" value="customers">
                            <input type="hidden" name="action" value="customer_edit">
                            <input type="hidden" name="customer_id" id="customer-edit-id">
                            <label>Nome<input name="name" id="customer-edit-name" required maxlength="120"></label>
                            <label>NIF<input name="nif" id="customer-edit-nif" maxlength="9"></label>
                            <label>Telefone<input name="phone" id="customer-edit-phone"></label>
                            <label>Email<input name="email" id="customer-edit-email" type="email"></label>
                            <button class="btn btn-main btn-xl" type="submit">Guardar alterações</button>
                        </form>
                    </div>
                </dialog>

            <?php elseif ($page === 'cash'): ?>
                <div class="metric-grid four">
                    <article class="metric glass-lite"><span>Estado da caixa</span><strong><?=$till ? 'Aberta' : 'Fechada'?></strong><small><?=$till ? h($till['opened_at']) : 'Sem sessão'?></small></article>
                    <article class="metric glass-lite"><span>Fundo inicial</span><strong><?=money($till['opening_cents'] ?? 0)?></strong></article>
                    <article class="metric glass-lite"><span>Dinheiro esperado</span><strong><?=money($cashExpected)?></strong></article>
                    <article class="metric glass-lite"><span>Pagamentos hoje</span><strong><?=money($daySales)?></strong></article>
                </div>
                <div class="section-grid two-thirds">
                    <section class="panel glass">
                        <h3><?=$till ? ($g360On('cash_movements_enabled') ? 'Movimentos e fecho' : 'Fechar caixa') : 'Abrir caixa'?></h3>
                        <?php if (!$till): ?>
                            <?php form_start('cash'); ?>
                                <input type="hidden" name="action" value="open_till">
                                <label>Fundo de caixa (€)<input name="opening" value="0.00" required></label>
                                <button class="btn btn-main btn-xl">Abrir caixa</button>
                            </form>
                        <?php else: ?>
                            <?php if ($g360On('cash_movements_enabled')): ?>
                                <?php form_start('cash'); ?>
                                    <input type="hidden" name="action" value="cash_move">
                                    <label>Operação
                                        <select name="type"><option value="entrada">Entrada</option><option value="saida">Saída</option></select>
                                    </label>
                                    <label>Montante (€)<input name="amount" required placeholder="10,00"></label>
                                    <label>Justificação<input name="reason" required></label>
                                    <button class="btn btn-main">Registar movimento</button>
                                </form>
                                <hr>
                            <?php endif; ?>
                            <?php form_start('cash'); ?>
                                <input type="hidden" name="action" value="close_till">
                                <label>Dinheiro contado no fecho (€)<input name="closing" required placeholder="0.00"></label>
                                <label>Notas<textarea name="notes"></textarea></label>
                                <button class="btn btn-danger btn-xl">Fechar caixa</button>
                            </form>
                        <?php endif; ?>
                    </section>
                    <?php if ($g360On('cash_movements_enabled')): ?>
                        <section class="panel glass">
                            <h3>Movimentos recentes</h3>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Data</th><th>Tipo</th><th>Montante</th><th>Motivo</th></tr></thead>
                                    <tbody>
                                    <?php foreach (all($db, 'SELECT * FROM cash_movements WHERE company_id=? ORDER BY id DESC LIMIT 50', [$cid]) as $m): ?>
                                        <tr><td><?=h($m['created_at'])?></td><td><?=h($m['type'])?></td><td><?=money($m['amount_cents'])?></td><td><?=h($m['reason'])?></td></tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>

            <?php elseif ($page === 'reports'): ?>
                <?php
                $daily = all($db, "SELECT substr(created_at,1,10) AS period, COUNT(*) AS transactions, SUM(amount_cents) AS total FROM payments WHERE company_id=? GROUP BY substr(created_at,1,10) ORDER BY period DESC LIMIT 30", [$cid]);
                $byMethod = all($db, 'SELECT method, COUNT(*) AS transactions, SUM(amount_cents) AS total FROM payments WHERE company_id=? GROUP BY method', [$cid]);
                $top = all($db, "SELECT oi.name, MAX(p.image_path) AS image_path, SUM(oi.qty) AS units, SUM(oi.qty*oi.price_cents) AS total FROM order_items oi JOIN orders o ON o.id=oi.order_id LEFT JOIN products p ON p.id=oi.product_id AND p.company_id=o.company_id WHERE o.company_id=? AND o.status='pago' GROUP BY oi.name ORDER BY total DESC LIMIT 10", [$cid]);
                ?>
                <?php if (has_permission($db, $cid, 'profits.view')):
                    $profitRanges = [
                        'Hoje'         => [$today, date('Y-m-d', strtotime('+1 day'))],
                        'Esta semana'  => [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('next monday'))],
                        'Este mês'     => [date('Y-m-01'), date('Y-m-d', strtotime('first day of next month'))],
                    ];
                ?>
                    <section class="panel glass g360-profit-panel">
                        <div class="panel-heading"><div><span class="eyebrow">RENTABILIDADE</span><h3>Margens por período</h3></div><span class="tag">Acesso restrito</span></div>
                        <div class="g360-profit-grid">
                        <?php foreach ($profitRanges as $label => $range):
                            $pr = g360_profit_period($db, $cid, $range[0], $range[1]); ?>
                            <article class="g360-profit-card">
                                <small><?=h($label)?></small>
                                <strong><?=$pr['margin'] === null ? 'Por apurar' : money($pr['margin'])?></strong>
                                <span>Vendas recebidas: <?=money($pr['revenue'])?></span>
                                <span>Custo dos produtos: <?=money($pr['cost'])?></span>
                                <?php if ($pr['unknown_units']): ?><em>Existem <?=$pr['unknown_units']?> unidades antigas sem custo registado.</em><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                        </div>
                        <p class="hint">Margem bruta estimada. Não corresponde ao lucro líquido: não inclui despesas fixas, impostos ou outros custos. Os pedidos anteriores à V22 podem não ter custos históricos.</p>
                    </section>
                <?php endif; ?>
                <?php if (has_permission($db, $cid, 'reports.view')): ?>
                    <div class="metric-grid four">
                        <article class="metric glass-lite"><span>Hoje</span><strong><?=money($daySales)?></strong></article>
                        <article class="metric glass-lite"><span>Esta semana</span><strong><?=money($weekSales)?></strong></article>
                        <article class="metric glass-lite"><span>Este mês</span><strong><?=money($monthSales)?></strong></article>
                        <?php if ($g360On('stock_alerts_enabled') && $g360On('products_enabled')): ?>
                            <article class="metric glass-lite"><span>Produtos com stock baixo</span><strong><?=$low?></strong></article>
                        <?php endif; ?>
                    </div>
                    <div class="section-grid two-thirds">
                        <section class="panel glass">
                            <h3>Vendas por dia</h3>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Dia</th><th>Transações</th><th>Total</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($daily as $d): ?><tr><td><?=h($d['period'])?></td><td><?=$d['transactions']?></td><td><?=money($d['total'])?></td></tr><?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <section class="panel glass">
                            <h3>Pagamentos por método</h3>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Método</th><th>Operações</th><th>Montante</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($byMethod as $m): ?><tr><td><?=h($m['method'])?></td><td><?=$m['transactions']?></td><td><?=money($m['total'])?></td></tr><?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <h3 class="space-top">Produtos mais vendidos</h3>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Produto</th><th>Qtd.</th><th>Valor</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($top as $p): ?><tr><td><span class="g360-inline-product"><img src="<?=h(product_image($p))?>" alt="" loading="lazy"><?=h($p['name'])?></span></td><td><?=$p['units']?></td><td><?=money($p['total'])?></td></tr><?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                    <p class="hint">Relatórios internos de gestão. Não equivalem a documentos fiscais certificados.</p>
                <?php endif; ?>

            <?php elseif ($page === 'exports'): ?>
                <section class="panel glass">
                    <h3>Centro de exportações</h3>
                    <p class="muted">Descarrega dados para análise ou partilha com o contabilista.</p>
                    <div class="export-grid">
                    <?php foreach (['products' => 'Produtos', 'customers' => 'Clientes', 'reservations' => 'Reservas', 'orders' => 'Pedidos', 'payments' => 'Pagamentos', 'stock' => 'Movimentos de stock'] as $key => $name):
                        if (!empty($g360CurrentPrefs[g360_export_feature($key) ?? ''])): ?>
                            <a class="export-tile glass-lite" href="export.php?type=<?=$key?>"><span>⇩</span><strong><?=h($name)?></strong><small>Descarregar CSV</small></a>
                    <?php endif; endforeach; ?>
                    </div>
                    <div class="alert warning"><strong>Exportação interna:</strong> os CSV destinam-se apenas à gestão. A faturação certificada e o SAF-T (PT) ficam disponíveis quando ativares o módulo fiscal e tiveres permissões. A comunicação à AT deve estar configurada e ser verificada no fornecedor.</div>
                </section>

            <?php elseif ($page === 'fiscal'): ?>
                <?php require __DIR__.'/pages/fiscal.php'; ?>

            <?php elseif ($page === 'settings'): ?>
                <?php require __DIR__.'/pages/settings.php'; ?>

            <?php elseif ($page === 'logs'): ?>
                <?php require __DIR__.'/pages/logs.php'; ?>

            <?php elseif ($page === 'ai10'): ?>
                <section class="section-grid two-thirds">
                    <div class="panel glass assistant-panel">
                        <div class="assistant-head">
                            <div class="assistant-logo">✦</div>
                            <div><h3>UniGest AI · Central de operações</h3><p>Uma única IA: prepara ações e consultas para confirmação, com validações no servidor.</p></div>
                        </div>
                        <div class="chat-log" id="ai10-log">
                            <div class="bubble bot">Olá! Sou a tua central de operações. Pede para duplicar, arquivar ou repor produtos, criar mesas, alterar reservas ou consultar relatórios. Primeiro mostro a operação e peço sempre confirmação. Se faltar informação, faço uma pergunta antes de avançar.</div>
                        </div>
                        <form class="chat-form" id="ai10-form">
                            <input type="hidden" id="ai10-csrf" value="<?=h(token())?>">
                            <input id="ai10-input" required maxlength="900" placeholder="Ex.: repõe 30 cafés, duplica uma mesa ou prepara relatório de vendas">
                            <button class="btn btn-main">Preparar operação ↗</button>
                        </form>
                    </div>
                    <div class="panel glass">
                        <h3>O que posso fazer?</h3>
                        <div class="prompt-list">
                            <button data-ai10-prompt="Faz um resumo do meu negócio hoje.">Resumo do negócio</button>
                            <?php if ($g360On('products_enabled')): ?><button data-ai10-prompt="Que produtos precisam de reposição de stock?">Analisar stock</button><?php endif; ?>
                            <?php if ($g360On('orders_enabled')): ?><button data-ai10-prompt="Quais são os pedidos em aberto?">Pedidos em aberto</button><?php endif; ?>
                            <?php if ($g360On('booking_enabled')): ?><button data-ai10-prompt="Que reservas estão marcadas?">Ver reservas</button><?php endif; ?>
                            <?php if ($g360On('tables_enabled')): ?><button data-ai10-prompt="Quantas mesas estão disponíveis hoje às 20:00 para duas pessoas?">Mesas disponíveis hoje</button><?php endif; ?>
                            <?php if ($g360On('booking_enabled')): ?><button data-ai10-prompt="Quais são os próximos sete dias com mesas disponíveis às 20:00 para quatro pessoas?">Dias com mesas livres</button><?php endif; ?>
                            <?php if (has_permission($db, $cid, 'profits.view')): ?><button data-ai10-prompt="Mostra a margem bruta das vendas deste mês.">Consultar rentabilidade</button><?php endif; ?>
                            <?php if ($g360On('products_enabled')): ?><button data-ai10-prompt="Duplica o produto Café expresso.">Duplicar produto</button><?php endif; ?>
                            <?php if ($g360On('products_enabled')): ?><button data-ai10-prompt="Repõe o stock de Café expresso para 35 e adiciona 10 unidades de Água.">Repor stock</button><?php endif; ?>
                            <?php if ($g360On('tables_enabled')): ?><button data-ai10-prompt="Cria uma mesa chamada Esplanada 1 para 4 pessoas.">Nova mesa</button><?php endif; ?>
                            <?php if ($g360On('reports_enabled')): ?><button data-ai10-prompt="Prepara um relatório de vendas de hoje.">Relatório de vendas</button><?php endif; ?>
                        </div>
                        <p class="hint">Para ativar a IA externa, configura a chave de API no servidor. Nunca coloques a chave no HTML ou JavaScript.</p>
                        <div class="alert warning">Nenhuma operação é executada automaticamente. Confirma sempre o resumo antes de avançar. Se o pedido for ambíguo, a IA pede esclarecimentos. Não emite faturação fiscal certificada.</div>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>
<?=g360_scripts($page)?>
<?php require __DIR__.'/app/view/account-modal.php'; ?>
</body>
</html>