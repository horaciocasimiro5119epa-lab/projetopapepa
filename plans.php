<?php
/** 
 * Custom Plan Builder - UniGest
 * Permite ao utilizador criar o seu próprio plano ativando módulos individualmente.
 * Sem necessidade de aprovação de admin.
 */
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/app/domain/subscriptions.php';
require_once __DIR__.'/app/domain/company_features.php';

$cid = company_id();
$hasCompany = user_id() > 0 && $cid > 0 && member($db, $cid);
$canManage = false;
if ($hasCompany) {
    $canManage = (current_company_role($db, $cid)['role_key'] ?? '') === 'dono';
}

$notice = '';
$error = '';

// ─────────────────────────────────────────────────────────────
// 1. PROCESSAMENTO DO PEDIDO (Ativação Imediata)
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        check_token();
        if (!$hasCompany || !$canManage) {
            throw new RuntimeException('Apenas o dono da empresa pode alterar o plano.');
        }
        if ((string)($_POST['action'] ?? '') !== 'save_custom_plan') {
            throw new RuntimeException('Operação inválida.');
        }

        $activeFeatures = $_POST['features'] ?? [];
        if (!is_array($activeFeatures)) {
            $activeFeatures = [];
        }

        $duration = (int)($_POST['duration_months'] ?? 1);
        if (!in_array($duration, [1, 3, 6, 12])) {
            $duration = 1;
        }

        // TODO: ADAPTAR À TUA BASE DE DADOS
        $prefs = g360_preferences($db, $cid);
        $allModules = ['pos_enabled','orders_enabled','tables_enabled','products_enabled','customers_enabled','cash_enabled','booking_enabled','menu_enabled','reports_enabled','exports_enabled','logs_enabled','ai_enabled','fiscal_enabled'];
        
        foreach ($allModules as $mod) {
            $prefs[$mod] = in_array($mod, $activeFeatures) ? 1 : 0;
        }
        
        $jsonPrefs = json_encode($prefs);
        $db->prepare("UPDATE companies SET preferences = ? WHERE id = ?")->execute([$jsonPrefs, $cid]);

        $totalMonthly = (int)($_POST['total_monthly'] ?? 0);
        $db->prepare("UPDATE companies SET monthly_fee_cents = ?, plan_duration_months = ? WHERE id = ?")->execute([$totalMonthly * 100, $duration, $cid]);

        $notice = 'O teu plano personalizado foi ativado com sucesso! Os novos módulos já estão disponíveis no teu painel.';
        
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error = $e->getMessage();
    }
}

// ─────────────────────────────────────────────────────────────
// 2. CARREGAR ESTADO ATUAL
// ─────────────────────────────────────────────────────────────
$company = $hasCompany ? one($db, 'SELECT name FROM companies WHERE id=?', [$cid]) : null;
$currentPrefs = $hasCompany ? g360_preferences($db, $cid) : [];

$availableModules = [
    'pos_enabled' => ['name' => 'POS & Caixa', 'desc' => 'Caixa registadora, pedidos de balcão e pagamentos.', 'price' => 8, 'icon' => 'sales'],
    'orders_enabled' => ['name' => 'Pedidos & Mesas', 'desc' => 'Gestão de pedidos, ecrãs de preparação e mapa de mesas.', 'price' => 5, 'icon' => 'table'],
    'products_enabled' => ['name' => 'Inventário & Stock', 'desc' => 'Gestão de produtos, ingredientes, stock e alertas.', 'price' => 4, 'icon' => 'box'],
    'booking_enabled' => ['name' => 'Reservas & Agenda', 'desc' => 'Agenda de reservas online, controlo de lotação.', 'price' => 3, 'icon' => 'calendar'],
    'fiscal_enabled' => ['name' => 'Faturação AT', 'desc' => 'Emissão de faturas certificadas e SAF-T.', 'price' => 3, 'icon' => 'sales'],
    'menu_enabled' => ['name' => 'Menu Digital', 'desc' => 'Menu interativo para clientes (QR Code).', 'price' => 2, 'icon' => 'menu'],
    'customers_enabled' => ['name' => 'Fichas de Clientes', 'desc' => 'Histórico de compras e preferências dos clientes.', 'price' => 1, 'icon' => 'team'],
    'reports_enabled' => ['name' => 'Relatórios & Análises', 'desc' => 'Relatórios de vendas, margens e exportações.', 'price' => 1, 'icon' => 'chart'],
    'ai_enabled' => ['name' => 'IA de Gestão', 'desc' => 'Assistente inteligente para análise de dados.', 'price' => 1, 'icon' => 'spark'],
    'logs_enabled' => ['name' => 'Logs de Atividade', 'desc' => 'Registo de segurança e auditoria.', 'price' => 1, 'icon' => 'orders'],
];

require_once __DIR__.'/app/view/icons.php';
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#0b0715">
    <title>Plano à Medida | UniGest</title>
    <?=g360_styles('landing')?>
    <link rel="icon" type="image/png" href="favicon.png?v=53">
    <style>
        body.ug-plans-page {
            background: radial-gradient(circle at 12% 8%, rgba(196,44,247,.15), transparent 25%),
                        linear-gradient(180deg, var(--bg) 0, var(--bg-2) 100%);
            color: var(--text); font-family: 'Inter', system-ui, sans-serif; margin: 0; padding-bottom: 140px;
        }

        /* Header */
        .ug-simple-header {
            padding: 1.5rem 5%; display: flex; justify-content: space-between; align-items: center;
            border-bottom: 1px solid var(--line); background: rgba(11, 7, 21, 0.7); backdrop-filter: blur(20px);
            position: sticky; top: 0; z-index: 100;
        }
        .ug-simple-header a { color: var(--muted); text-decoration: none; font-weight: 500; font-size: 14px; transition: color 0.2s; }
        .ug-simple-header a:hover { color: #fff; }

        /* Hero */
        .ug-plan-hero { text-align: center; padding: 60px 5% 30px; }
        .ug-plan-hero h1 { font: 800 clamp(32px, 4vw, 48px)/1.2 "Plus Jakarta Sans"; margin-bottom: 16px; letter-spacing: -1.5px; }
        .ug-plan-hero h1 em { font-style: normal; background: linear-gradient(100deg, #f6c2ff, #d447fb 52%, #9e57ff); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .ug-plan-hero p { color: var(--muted); font-size: 16px; max-width: 600px; margin: 0 auto; line-height: 1.7; }

        /* Duração Selector */
        .ug-duration-selector {
            max-width: 800px; margin: 40px auto 20px; display: flex; justify-content: center;
            background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 8px; gap: 8px;
        }
        .ug-duration-btn {
            flex: 1; padding: 14px; border-radius: 12px; border: none; background: transparent;
            color: var(--muted); font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s;
            display: flex; flex-direction: column; align-items: center; gap: 4px;
        }
        .ug-duration-btn:hover { background: rgba(255,255,255,0.03); color: #fff; }
        .ug-duration-btn.active { background: rgba(196, 44, 247, 0.15); color: #fff; box-shadow: inset 0 0 0 1px rgba(196,44,247,0.3); }
        .ug-duration-btn span { font-size: 11px; color: var(--green); font-weight: 600; }
        .ug-duration-btn.active span { color: #8ce2ba; }

        /* Vertical List */
        .ug-builder-wrap { max-width: 900px; margin: 0 auto; padding: 0 5%; }
        .ug-vertical-list { display: flex; flex-direction: column; gap: 12px; margin-top: 30px; }

        /* Feature Card */
        .ug-feature-toggle-card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 18px;
            padding: 20px 24px; display: flex; align-items: center; gap: 20px;
            cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden;
        }
        .ug-feature-toggle-card:hover { background: var(--panel-2); border-color: rgba(255,255,255,0.15); transform: translateX(4px); }
        .ug-feature-toggle-card.active { border-color: var(--purple); background: rgba(196, 44, 247, 0.05); }
        .ug-feature-toggle-card.active::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
            background: linear-gradient(to bottom, var(--purple), var(--purple-2));
        }
        .ug-ft-icon { width: 52px; height: 52px; border-radius: 14px; background: rgba(255,255,255,0.03); display: flex; align-items: center; justify-content: center; color: var(--muted); flex-shrink: 0; transition: all 0.3s; font-size: 24px; }
        .ug-feature-toggle-card.active .ug-ft-icon { background: rgba(196,44,247,0.15); color: #eab4ff; }
        .ug-ft-content { flex-grow: 1; }
        .ug-ft-content h3 { font-size: 18px; margin: 0 0 4px 0; color: #fff; }
        .ug-ft-content p { font-size: 13px; color: var(--muted); margin: 0; }
        .ug-ft-price { font-size: 18px; font-weight: 800; color: var(--purple); margin-right: 20px; white-space: nowrap; }

        /* Switch UI */
        .ug-switch { position: relative; display: inline-block; width: 50px; height: 28px; flex-shrink: 0; }
        .ug-switch input { opacity: 0; width: 0; height: 0; }
        .ug-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #2a2438; transition: .3s; border-radius: 28px; border: 1px solid rgba(255,255,255,0.1); }
        .ug-slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: var(--muted); transition: .3s; border-radius: 50%; }
        input:checked + .ug-slider { background-color: var(--purple); border-color: var(--purple); }
        input:checked + .ug-slider:before { transform: translateX(22px); background-color: #fff; }

        /* Sticky Bar */
        .ug-sticky-bar {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 1000;
            background: rgba(18, 9, 30, 0.9); backdrop-filter: blur(20px);
            border-top: 1px solid rgba(196, 44, 247, 0.3); padding: 16px 5%;
            display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.5);
        }
        .ug-total-info { display: flex; flex-direction: column; gap: 2px; }
        .ug-total-monthly { font-size: 14px; color: #fff; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .ug-total-monthly small { font-size: 12px; color: var(--muted); font-weight: 500; }
        .ug-total-final-row { display: flex; align-items: baseline; gap: 12px; }
        .ug-total-final { font-size: 22px; color: var(--muted); font-weight: 600; font-family: "Plus Jakarta Sans"; }
        .ug-savings { display: none; font-size: 12px; font-weight: 700; color: var(--green); background: rgba(106, 230, 179, 0.1); padding: 4px 8px; border-radius: 8px; }
        .ug-btn-save { background: linear-gradient(135deg, var(--purple), var(--purple-2)); color: #fff; border: none; padding: 16px 36px; font-size: 16px; font-weight: 700; border-radius: 14px; cursor: pointer; transition: all 0.3s; box-shadow: 0 8px 24px rgba(161,29,231,.3); }
        .ug-btn-save:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(161,29,231,.5); }

        /* Alerts */
        .ug-alert { padding: 16px; border-radius: 14px; margin-top: 24px; text-align: center; font-weight: 600; font-size: 14px; border: 1px solid transparent; }
        .ug-alert.success { background: rgba(106, 230, 179, 0.1); color: var(--green); border-color: rgba(106, 230, 179, 0.2); }
        .ug-alert.error { background: rgba(255, 117, 141, 0.1); color: var(--red); border-color: rgba(255, 117, 141, 0.2); }

        /* ─── COMPARISON CARDS SECTION ─── */
        .ug-comparison { margin-top: 80px; padding: 0 5%; max-width: 1200px; margin-left: auto; margin-right: auto; }
        .ug-comparison h2 { font: 800 clamp(26px, 3.5vw, 38px)/1.2 "Plus Jakarta Sans"; margin-bottom: 8px; text-align: center; letter-spacing: -1px; }
        .ug-comparison h2 em { font-style: normal; color: var(--green); }
        .ug-comparison > p { color: var(--muted); text-align: center; margin-bottom: 40px; font-size: 15px; }
        
        .ug-comp-grid {
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 20px;
        }

        .ug-comp-card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 20px;
            padding: 24px; display: flex; flex-direction: column; gap: 16px;
            transition: transform 0.3s ease, border-color 0.3s ease;
        }
        .ug-comp-card:hover { transform: translateY(-4px); border-color: rgba(255,255,255,0.2); }
        
        /* Destaque UniGest */
        .ug-comp-card.ug-hero-card {
            background: linear-gradient(160deg, rgba(196,44,247,0.15), var(--panel));
            border-color: var(--purple); position: relative;
            box-shadow: 0 15px 40px rgba(196,44,247,0.15);
        }
        .ug-comp-card.ug-hero-card::before {
            content: 'RECOMENDADO'; position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
            background: linear-gradient(135deg, var(--purple), var(--purple-2)); color: #fff;
            padding: 4px 12px; border-radius: 999px; font-size: 10px; font-weight: 800; letter-spacing: 1px;
        }

        .ug-cc-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .ug-cc-title { font-size: 18px; font-weight: 800; color: #fff; margin: 0; }
        .ug-cc-origin { font-size: 11px; color: var(--muted); font-weight: 600; margin-top: 4px; display: flex; align-items: center; gap: 4px; }
        
        .ug-cc-price { font-size: 28px; font-weight: 800; color: #fff; font-family: "Plus Jakarta Sans"; letter-spacing: -1px; display: flex; align-items: baseline; gap: 4px; }
        .ug-cc-price small { font-size: 12px; color: var(--muted); font-weight: 500; letter-spacing: 0; }
        
        .ug-cc-features { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .ug-badge { 
            padding: 5px 10px; border-radius: 8px; font-size: 11px; font-weight: 700; 
            display: inline-flex; align-items: center; gap: 4px;
        }
        .ug-badge.yes { background: rgba(106,230,179,.1); color: var(--green); border: 1px solid rgba(106,230,179,.2); }
        .ug-badge.no { background: rgba(255,117,141,.1); color: var(--red); border: 1px solid rgba(255,117,141,.2); }
        .ug-badge.extra { background: rgba(255,191,115,.1); color: var(--orange); border: 1px solid rgba(255,191,115,.2); }

        .ug-cc-note { font-size: 12px; color: var(--muted); line-height: 1.5; margin-top: auto; padding-top: 16px; border-top: 1px solid var(--line); }
        
        .ug-comparison-footer { text-align: center; margin-top: 40px; font-size: 13px; color: var(--muted); line-height: 1.7; max-width: 800px; margin-left: auto; margin-right: auto; }

        @media (max-width: 650px) {
            .ug-duration-selector { flex-direction: column; }
            .ug-feature-toggle-card { flex-wrap: wrap; padding: 16px; gap: 12px; }
            .ug-ft-content { width: calc(100% - 70px); }
            .ug-ft-price { margin-left: 72px; }
            .ug-sticky-bar { padding: 16px 5%; }
            .ug-total-info { width: 100%; margin-bottom: 8px; }
            .ug-btn-save { width: 100%; }
        }
    </style>
</head>
<body class="ug-plans-page" data-page="plans">

    <!-- HEADER -->
    <header class="ug-simple-header">
        <a href="index.php"><img src="logo.png?v=53" alt="UniGest" width="140" height="45"></a>
        <nav style="display:flex; gap:24px;">
            <a href="index.php">Página inicial</a>
            <?php if ($hasCompany): ?>
                <a href="index.php?page=dashboard" style="color:#fff; font-weight:600;">Voltar ao painel →</a>
            <?php elseif (user_id()): ?>
                <a href="companies.php">As minhas empresas</a>
            <?php else: ?>
                <a href="auth.php?mode=login">Iniciar sessão</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="ug-builder-wrap">
        <section class="ug-plan-hero">
            <h1>Desenha o teu plano. <em>À tua medida.</em></h1>
            <p>Ativa apenas as funcionalidades que o teu negócio precisa e escolhe a duração que preferires. Sem contratos rígidos.</p>
            
            <?php if ($notice): ?>
                <div class="ug-alert success"><?=h($notice)?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="ug-alert error"><?=h($error)?></div>
            <?php endif; ?>
            <?php if (!$hasCompany): ?>
                <div class="ug-alert error">Precisas de ter uma empresa selecionada para configurar o teu plano.</div>
            <?php endif; ?>
        </section>

        <?php if ($hasCompany): ?>
        <form method="post" id="custom-plan-form">
            <input type="hidden" name="csrf" value="<?=h(token())?>">
            <input type="hidden" name="action" value="save_custom_plan">
            <input type="hidden" name="duration_months" id="input-duration" value="1">
            <input type="hidden" name="total_monthly" id="input-total-monthly" value="0">

            <!-- Duration Selector -->
            <div class="ug-duration-selector" id="duration-selector">
                <button type="button" class="ug-duration-btn active" data-duration="1" data-discount="0">1 Mês <span>Sem desconto</span></button>
                <button type="button" class="ug-duration-btn" data-duration="3" data-discount="20">3 Meses <span>Poupa 20%</span></button>
                <button type="button" class="ug-duration-btn" data-duration="6" data-discount="30">6 Meses <span>Poupa 30%</span></button>
                <button type="button" class="ug-duration-btn" data-duration="12" data-discount="45">1 Ano <span>Poupa 45%</span></button>
            </div>

            <!-- Vertical Modules List -->
            <div class="ug-vertical-list">
                <?php foreach ($availableModules as $key => $module): 
                    $isActive = !empty($currentPrefs[$key]);
                ?>
                <label class="ug-feature-toggle-card <?=$isActive ? 'active' : ''?>" for="mod_<?=$key?>">
                    <div class="ug-ft-icon"><?=ug_icon($module['icon'])?></div>
                    <div class="ug-ft-content">
                        <h3><?=h($module['name'])?></h3>
                        <p><?=h($module['desc'])?></p>
                    </div>
                    <div class="ug-ft-price">+<?=number_format($module['price'], 0)?>€/mês</div>
                    <div class="ug-switch">
                        <input type="checkbox" name="features[]" value="<?=$key?>" id="mod_<?=$key?>" data-price="<?=$module['price']?>" <?=$isActive ? 'checked' : ''?> <?=!$canManage ? 'disabled' : ''?>>
                        <span class="ug-slider"></span>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>

            <!-- Sticky Footer -->
            <div class="ug-sticky-bar">
                <div class="ug-total-info">
                    <div class="ug-total-monthly">
                        <span id="ui-monthly-display">0€ / mês</span>
                        <small id="ui-duration-display">cobrado 1 mês</small>
                    </div>
                    <div class="ug-total-final-row">
                        <span class="ug-total-final" id="ui-total-display">0€ total</span>
                        <span class="ug-savings" id="ui-savings-display">Poupas 0€</span>
                    </div>
                </div>
                <div>
                    <?php if ($canManage): ?>
                        <button type="submit" class="ug-btn-save">Ativar Plano →</button>
                    <?php else: ?>
                        <span style="color:var(--muted); font-size:14px; font-weight:600;">Apenas o dono da empresa pode alterar o plano.</span>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════════════
             COMPARISON CARDS - SEM SCROLL LATERAL E INTUITIVO
             ═══════════════════════════════════════════════════════ -->
        <section class="ug-comparison">
            <h2>Porque é que o UniGest é <em>imbatível</em>.</h2>
            <p>Comparação direta com as principais soluções do mercado. Valores mensais, sem IVA.</p>
            
            <div class="ug-comp-grid">
                
                <!-- UniGest Card -->
                <div class="ug-comp-card ug-hero-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">UniGest</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal · Completo</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">29€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ POS & Mesas</span>
                        <span class="ug-badge yes">✓ Faturação AT</span>
                        <span class="ug-badge yes">✓ Stock & Reservas</span>
                        <span class="ug-badge yes">✓ IA de Gestão</span>
                    </div>
                    <div class="ug-cc-note">
                        <strong style="color:#fff;">Tudo incluído.</strong> Sem custos escondidos por módulos ou utilizadores adicionais.
                    </div>
                </div>

                <!-- ZS Rest -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">ZS Rest</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">24,30€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ POS Básico</span>
                        <span class="ug-badge no">✗ Stock (só 60€+)</span>
                        <span class="ug-badge no">✗ IA</span>
                        <span class="ug-badge no">✗ Menu Digital</span>
                    </div>
                    <div class="ug-cc-note">Para teres as funcionalidades do UniGest, terias de pagar mais de <strong>60€/mês</strong>.</div>
                </div>

                <!-- Bitte -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">Bitte</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">39€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ Menu QR</span>
                        <span class="ug-badge no">✗ POS (só no de 39€)</span>
                        <span class="ug-badge no">✗ Faturação AT</span>
                    </div>
                    <div class="ug-cc-note">Focado em menus digitais. Não substitui um sistema completo de gestão e faturação.</div>
                </div>

                <!-- WinMax4 -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">WinMax4</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">18,90€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ POS</span>
                        <span class="ug-badge extra">+4€ Stock</span>
                        <span class="ug-badge extra">+4€ Mesas</span>
                    </div>
                    <div class="ug-cc-note">O preço base é atrativo, mas cada módulo extra (stock, reservas, etc.) tem custos adicionais.</div>
                </div>

                <!-- Vendus -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">Vendus</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">17€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ POS</span>
                        <span class="ug-badge no">✗ Mesas</span>
                        <span class="ug-badge extra">+9€ Faturação</span>
                    </div>
                    <div class="ug-cc-note">Excelente para retalho, mas não tem gestão de mesas para restauração.</div>
                </div>

                <!-- Moloni -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">Moloni</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">10,90€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ Faturação</span>
                        <span class="ug-badge yes">✓ POS Básico</span>
                        <span class="ug-badge extra">+15,90€ Stock</span>
                    </div>
                    <div class="ug-cc-note">Focado em faturação. Para gestão de stock completo, o custo sobe para quase 27€.</div>
                </div>

                <!-- PHC / Primavera (Legacy) -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">PHC / Primavera</h3>
                            <div class="ug-cc-origin">🇵🇹 Portugal · ERP</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">50€+ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ ERP Completo</span>
                        <span class="ug-badge no">✗ Interface Antiga</span>
                    </div>
                    <div class="ug-cc-note">Soluções robustas para grandes empresas, mas caras, complexas e com interfaces desatualizadas.</div>
                </div>

                <!-- Toast / Square / Lightspeed -->
                <div class="ug-comp-card">
                    <div class="ug-cc-header">
                        <div>
                            <h3 class="ug-cc-title">Toast / Square</h3>
                            <div class="ug-cc-origin">🇺🇸 EUA / Canadá</div>
                        </div>
                    </div>
                    <div class="ug-cc-price">~69€ <small>/mês</small></div>
                    <div class="ug-cc-features">
                        <span class="ug-badge yes">✓ POS Moderno</span>
                        <span class="ug-badge no">✗ Faturação AT</span>
                        <span class="ug-badge no">✗ Hardware PT</span>
                    </div>
                    <div class="ug-cc-note">Líderes nos EUA. No entanto, não estão certificados para a Faturação AT portuguesa.</div>
                </div>

            </div>
            
            <p class="ug-comparison-footer">
                Valores de referência recolhidos em fontes públicas. O <strong>UniGest</strong> oferece POS, Mesas, Faturação, Stock, Reservas, Menu QR e IA por apenas <strong>29€/mês</strong> – menos do que a maioria cobra apenas por uma fração destas funcionalidades.
            </p>
        </section>
    </main>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const checkboxes = document.querySelectorAll('input[name="features[]"]');
            const durationBtns = document.querySelectorAll('.ug-duration-btn');
            
            const inputDuration = document.getElementById('input-duration');
            const inputTotalMonthly = document.getElementById('input-total-monthly');
            
            const uiMonthlyDisplay = document.getElementById('ui-monthly-display');
            const uiDurationDisplay = document.getElementById('ui-duration-display');
            const uiTotalDisplay = document.getElementById('ui-total-display');
            const uiSavingsDisplay = document.getElementById('ui-savings-display');

            let currentDuration = 1;
            let currentDiscount = 0;

            function calculateTotal() {
                let monthlyTotal = 0;
                checkboxes.forEach(cb => {
                    const card = cb.closest('.ug-feature-toggle-card');
                    if (cb.checked) {
                        monthlyTotal += parseInt(cb.getAttribute('data-price') || 0, 10);
                        card.classList.add('active');
                    } else {
                        card.classList.remove('active');
                    }
                });

                const rawTotal = monthlyTotal * currentDuration;
                const savings = rawTotal * (currentDiscount / 100);
                const finalTotal = rawTotal - savings;
                const monthlyAverage = currentDuration > 0 ? finalTotal / currentDuration : 0;

                const formatMoney = (val) => {
                    return Number(val).toLocaleString('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + '€';
                };

                if (uiMonthlyDisplay) uiMonthlyDisplay.innerText = formatMoney(monthlyAverage) + ' / mês';
                if (uiTotalDisplay) uiTotalDisplay.innerText = formatMoney(finalTotal) + ' total';
                
                if (uiDurationDisplay) {
                    if (currentDuration === 1) uiDurationDisplay.innerText = 'cobrado 1 mês';
                    if (currentDuration === 3) uiDurationDisplay.innerText = 'cobrado 3 meses';
                    if (currentDuration === 6) uiDurationDisplay.innerText = 'cobrado 6 meses';
                    if (currentDuration === 12) uiDurationDisplay.innerText = 'cobrado 1 ano';
                }

                if (uiSavingsDisplay) {
                    if (savings > 0) {
                        uiSavingsDisplay.innerText = 'Poupas ' + formatMoney(savings);
                        uiSavingsDisplay.style.display = 'inline-block';
                    } else {
                        uiSavingsDisplay.style.display = 'none';
                    }
                }

                if (inputDuration) inputDuration.value = currentDuration;
                if (inputTotalMonthly) inputTotalMonthly.value = monthlyTotal;
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', calculateTotal);
            });

            durationBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    durationBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    
                    currentDuration = parseInt(btn.getAttribute('data-duration'), 10);
                    currentDiscount = parseInt(btn.getAttribute('data-discount'), 10);
                    
                    calculateTotal();
                });
            });

            calculateTotal();
        });
    </script>
</body>
</html>