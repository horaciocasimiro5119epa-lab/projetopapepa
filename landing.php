<?php
/** A homepage é exatamente a mesma para convidados e utilizadores autenticados. */
require_once __DIR__.'/app/view/icons.php';
$isLoggedIn = user_id() > 0;
$landingAccount = $isLoggedIn ? one($db, 'SELECT name,email FROM users WHERE id=?', [user_id()]) : null;
$hasActiveCompany = $isLoggedIn && company_id() > 0 && member($db, company_id());
$panelHref = $hasActiveCompany ? 'index.php?page=dashboard' : 'companies.php';
?>
<!doctype html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#0b0715">
    <meta name="description" content="UniGest: O sistema de gestão definitivo para restaurantes, cafés e negócios. POS, inventário, agenda e IA num só lugar.">
    <title>UniGest · O teu negócio, no lugar certo</title>
    <?=g360_styles('landing')?>
    <link rel="icon" href="favicon.png?v=51" type="image/png">
    <link rel="apple-touch-icon" href="apple-touch-icon.png?v=51">
    
    <style>
        /* =========================================================
           UNIGEST LANDING - SEM AZUIS (PALETA ORIGINAL PÚRPURA)
           ========================================================= */
        body.ug-home {
            background: radial-gradient(circle at 12% 8%, rgba(196,44,247,.15), transparent 25%),
                        radial-gradient(circle at 86% 14%, rgba(160,36,224,.1), transparent 20%),
                        linear-gradient(180deg, var(--bg) 0, var(--bg-2) 100%);
            color: var(--text);
            font-family: 'Inter', system-ui, sans-serif;
            overflow-x: hidden;
            margin: 0;
        }

        /* Header Original Melhorado */
        .ug-home-header {
            position: sticky; top: 0; z-index: 1000;
            padding: 1.5rem 5%; display: flex; justify-content: space-between; align-items: center;
            background: rgba(11, 7, 21, 0.7); backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--line);
        }
        .ug-home-header nav { display: flex; gap: 2rem; align-items: center; }
        .ug-home-header nav a { color: var(--muted); text-decoration: none; font-weight: 500; font-size: 14px; transition: color 0.2s; }
        .ug-home-header nav a:hover { color: #fff; }
        .ug-home-account { display: flex; align-items: center; gap: 12px; }

        /* Botões (Usando o gradiente original) */
        .ug-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px 24px; font-weight: 700; font-size: 14px; border-radius: 12px;
            text-decoration: none; transition: all 0.3s ease; border: none; cursor: pointer;
        }
        .ug-btn-main {
            background: linear-gradient(135deg, var(--purple), var(--purple-2));
            color: #fff; box-shadow: 0 8px 24px rgba(161,29,231,.24);
        }
        .ug-btn-main:hover { transform: translateY(-2px); box-shadow: 0 12px 30px rgba(161,29,231,.4); }
        .ug-btn-outline {
            background: rgba(255,255,255,.05); color: #fff; border: 1px solid var(--line);
        }
        .ug-btn-outline:hover { background: rgba(255,255,255,.1); border-color: rgba(255,255,255,0.2); }

        /* Hero Section */
        .ug-hero {
            padding: 100px 5% 80px; max-width: 1300px; margin: 0 auto;
            display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 60px; align-items: center;
        }
        @media (max-width: 1024px) { .ug-hero { grid-template-columns: 1fr; text-align: center; gap: 40px; } }
        
        .ug-kicker {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 12px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase;
            color: #efb6ff; margin-bottom: 20px;
        }
        .ug-kicker span { width: 8px; height: 8px; background: #efb6ff; border-radius: 50%; box-shadow: 0 0 12px #efb6ff; }
        
        .ug-hero h1 {
            font: 800 clamp(40px, 5vw, 68px)/1.1 "Plus Jakarta Sans", sans-serif;
            letter-spacing: -2px; margin: 0 0 24px 0; color: #fff;
        }
        .ug-hero h1 em {
            font-style: normal;
            background: linear-gradient(100deg, #f6c2ff, #d447fb 52%, #9e57ff);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .ug-hero p { font-size: 18px; line-height: 1.7; color: var(--muted); max-width: 550px; margin-bottom: 35px; }
        @media (max-width: 1024px) { .ug-hero p { margin: 0 auto 35px; } }

        /* Interface Mockup 3D */
        .ug-mockup-wrapper { perspective: 1000px; }
        .ug-mockup {
            background: var(--panel); border: 1px solid var(--line); border-radius: 24px;
            padding: 24px; box-shadow: var(--shadow);
            transform: rotateY(-5deg) rotateX(5deg); transition: transform 0.5s ease;
        }
        .ug-mockup:hover { transform: rotateY(0deg) rotateX(0deg); }
        .ug-mockup-header { display: flex; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--line); padding-bottom: 16px; }
        .ug-mockup-dots { display: flex; gap: 6px; }
        .ug-mockup-dots span { width: 10px; height: 10px; border-radius: 50%; background: #333; }
        .ug-mockup-dots span:nth-child(1) { background: var(--red); }
        .ug-mockup-dots span:nth-child(2) { background: var(--orange); }
        .ug-mockup-dots span:nth-child(3) { background: var(--green); }
        
        .ug-mockup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .ug-mockup-card { background: var(--bg-2); border: 1px solid var(--line); border-radius: 16px; padding: 16px; }
        .ug-mockup-card small { color: var(--muted); font-size: 12px; font-weight: 600; }
        .ug-mockup-card strong { display: block; font-size: 24px; color: #fff; margin-top: 8px; font-family: "Plus Jakarta Sans"; }

        /* Stats Banner */
        .ug-stats {
            display: flex; justify-content: center; gap: 60px; flex-wrap: wrap;
            padding: 40px 5%; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line);
            background: rgba(255,255,255,0.01);
        }
        .ug-stat-item { text-align: center; }
        .ug-stat-item strong { display: block; font-size: 32px; font-weight: 800; color: #fff; font-family: "Plus Jakarta Sans"; }
        .ug-stat-item span { font-size: 13px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }

        /* Features */
        .ug-section { padding: 100px 5%; max-width: 1300px; margin: 0 auto; }
        .ug-section-head { text-align: center; max-width: 700px; margin: 0 auto 60px; }
        .ug-section-head h2 { font: 800 clamp(32px, 4vw, 44px)/1.2 "Plus Jakarta Sans"; letter-spacing: -1.5px; margin: 0 0 16px; }
        .ug-section-head h2 em { font-style: normal; color: var(--purple); }
        
        .ug-features-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; }
        .ug-feature-card {
            background: var(--panel); border: 1px solid var(--line); border-radius: 24px;
            padding: 32px; transition: all 0.3s ease; position: relative; overflow: hidden;
        }
        .ug-feature-card:hover { transform: translateY(-5px); border-color: rgba(196,44,247,.4); box-shadow: 0 20px 40px rgba(0,0,0,0.4); }
        .ug-feature-icon {
            width: 56px; height: 56px; border-radius: 16px; display: grid; place-items: center;
            background: rgba(196,44,247,.1); color: #eab4ff; font-size: 24px; margin-bottom: 24px;
        }
        .ug-feature-card h3 { font-size: 20px; margin: 0 0 12px; color: #fff; }
        .ug-feature-card p { font-size: 14px; line-height: 1.7; color: var(--muted); margin: 0; }

        /* CTA Final */
        .ug-cta {
            text-align: center; padding: 100px 5%;
            background: radial-gradient(circle at center, rgba(196,44,247,.15), transparent 60%);
        }
        .ug-cta h2 { font: 800 clamp(32px, 4vw, 48px)/1.2 "Plus Jakarta Sans"; margin-bottom: 20px; }
        .ug-cta p { font-size: 18px; color: var(--muted); margin-bottom: 40px; }

        /* Footer Original */
        .ug-home-footer {
            padding: 30px 5%; border-top: 1px solid var(--line);
            display: flex; justify-content: space-between; align-items: center;
            color: var(--muted); font-size: 13px;
        }

        /* Animações */
        .fade-up { opacity: 0; transform: translateY(20px); transition: opacity 0.8s ease, transform 0.8s ease; }
        .fade-up.visible { opacity: 1; transform: translateY(0); }
        .delay-1 { transition-delay: 0.1s; }
        .delay-2 { transition-delay: 0.2s; }
    </style>
</head>
<body class="ug-home" data-page="landing">

    <!-- HEADER ORIGINAL -->
    <header class="ug-home-header">
        <a class="ug-home-brand" href="index.php">
            <img src="logo.png?v=51" class="g360-brand-full" width="204" height="68" alt="UniGest" loading="eager">
        </a>
        <nav aria-label="Navegação pública">
            <a href="#funcionalidades">Funcionalidades</a>
            <a href="#como-funciona">Como funciona</a>
            <a href="plans.php">Planos</a>
        </nav>
        <div class="ug-home-account">
            <?php if ($isLoggedIn): ?>
                <span class="ug-home-welcome" style="font-size:13px; font-weight:600;">Olá, <?=h(explode(' ', trim($landingAccount['name'] ?? 'Utilizador'))[0])?></span>
                <a class="ug-btn ug-btn-outline" href="account_switch.php">Trocar conta</a>
                <a class="ug-btn ug-btn-main" href="<?=h($panelHref)?>">Abrir painel →</a>
            <?php else: ?>
                <a class="ug-btn ug-btn-outline" href="auth.php?mode=login">Iniciar sessão</a>
                <a class="ug-btn ug-btn-main" href="auth.php?mode=register">Criar conta →</a>
            <?php endif; ?>
        </div>
    </header>

    <main>
        <!-- HERO -->
        <section class="ug-hero">
            <div class="fade-up">
                <div class="ug-kicker"><span></span> Software de Gestão de Nova Geração</div>
                <h1>O teu negócio,<br><em>no lugar certo.</em></h1>
                <p>Organiza vendas, mesas, reservas, stock e colaboradores num único painel. Decide com clareza, elimina o trabalho repetitivo e foca-te no que realmente importa: crescer.</p>
                <div style="display:flex; gap:16px; justify-content:inherit;">
                    <a class="ug-btn ug-btn-main" style="padding:16px 32px; font-size:16px;" href="<?=h($isLoggedIn ? $panelHref : 'auth.php?mode=register')?>">
                        <?=$isLoggedIn ? 'Entrar no painel' : 'Começar agora'?> →
                    </a>
                    <a class="ug-btn ug-btn-outline" style="padding:16px 32px; font-size:16px;" href="#funcionalidades">Ver funcionalidades</a>
                </div>
            </div>
            
            <div class="ug-mockup-wrapper fade-up delay-1">
                <div class="ug-mockup">
                    <div class="ug-mockup-header">
                        <div class="ug-mockup-dots"><span></span><span></span><span></span></div>
                        <div style="font-size:12px; font-weight:700; color:var(--muted);">UNIGEST · DASHBOARD</div>
                    </div>
                    <div class="ug-mockup-grid">
                        <div class="ug-mockup-card">
                            <small>Vendas Hoje</small>
                            <strong>€ 1.240,50</strong>
                        </div>
                        <div class="ug-mockup-card">
                            <small>Mesas Ocupadas</small>
                            <strong>08 / 12</strong>
                        </div>
                        <div class="ug-mockup-card" style="grid-column: span 2;">
                            <small>Próximas Reservas</small>
                            <div style="margin-top:12px; display:flex; justify-content:space-between; align-items:center; font-size:14px;">
                                <span style="color:#fff; font-weight:600;">19:00 · Mesa 04</span>
                                <span style="background:rgba(106,230,179,.15); color:var(--green); padding:4px 8px; border-radius:8px; font-size:11px; font-weight:700;">Confirmada</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- STATS -->
        <section class="ug-stats fade-up">
            <div class="ug-stat-item">
                <strong>99.9%</strong>
                <span>Uptime Garantido</span>
            </div>
            <div class="ug-stat-item">
                <strong>500+</strong>
                <span>Empresas Ativas</span>
            </div>
            <div class="ug-stat-item">
                <strong>1.2M</strong>
                <span>Pedidos Processados</span>
            </div>
        </section>

        <!-- FEATURES -->
        <section class="ug-section" id="funcionalidades">
            <div class="ug-section-head fade-up">
                <h2>Tudo o que precisas. <em>Sem complicações.</em></h2>
                <p style="color:var(--muted); font-size:16px;">Um ecossistema completo e interligado. Deixa as folhas de cálculo no passado e controla o teu negócio a partir de um único lugar.</p>
            </div>
            
            <div class="ug-features-grid">
                <div class="ug-feature-card fade-up">
                    <div class="ug-feature-icon"><?=ug_icon('sales')?></div>
                    <h3>POS Inteligente</h3>
                    <p>Atendimento ultrarrápido. Gere pedidos de balcão e mesas, emite faturas e controla a caixa com um clique.</p>
                </div>
                <div class="ug-feature-card fade-up delay-1">
                    <div class="ug-feature-icon"><?=ug_icon('table')?></div>
                    <h3>Gestão de Mesas</h3>
                    <p>Desenha a planta do teu espaço ao detalhe. Move mesas, adiciona divisórias e acompanha a ocupação em tempo real.</p>
                </div>
                <div class="ug-feature-card fade-up delay-2">
                    <div class="ug-feature-icon"><?=ug_icon('calendar')?></div>
                    <h3>Agenda & Reservas</h3>
                    <p>Uma agenda unificada que evita overbookings. Controla a disponibilidade e recebe os clientes com a mesa pronta.</p>
                </div>
                <div class="ug-feature-card fade-up">
                    <div class="ug-feature-icon"><?=ug_icon('box')?></div>
                    <h3>Stock & Inventário</h3>
                    <p>Controlo absoluto sobre cada ingrediente. O sistema avisa-te quando o stock está baixo e calcula margens.</p>
                </div>
                <div class="ug-feature-card fade-up delay-1">
                    <div class="ug-feature-icon"><?=ug_icon('team')?></div>
                    <h3>Equipas & Permissões</h3>
                    <p>Nem todos precisam de ver tudo. Cria cargos personalizados e define exatamente a que dados cada um tem acesso.</p>
                </div>
                <div class="ug-feature-card fade-up delay-2">
                    <div class="ug-feature-icon"><?=ug_icon('spark')?></div>
                    <h3>IA de Gestão</h3>
                    <p>Um assistente que analisa o teu negócio. Pede-lhe relatórios ou descobre padrões de venda através de linguagem natural.</p>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="ug-cta fade-up" id="como-funciona">
            <h2>Pronto para elevar o teu negócio?</h2>
            <p>Não perdes nada em experimentar. Descobre porque é que centenas de gestores confiam no UniGest todos os dias.</p>
            <a class="ug-btn ug-btn-main" style="padding:18px 40px; font-size:18px;" href="<?=h($isLoggedIn ? $panelHref : 'auth.php?mode=register')?>">
                <?=$isLoggedIn ? 'Entrar no meu painel' : 'Criar conta gratuita'?> →
            </a>
        </section>
    </main>

    <!-- FOOTER ORIGINAL -->
    <footer class="ug-home-footer">
        <span style="display:flex; align-items:center; gap:8px;">
            <img src="logo-icon.png?v=51" class="g360-brand-small" width="24" height="24" alt=""> 
            <strong>UniGest</strong>
        </span>
        <span>Organização inteligente para estabelecimentos</span>
    </footer>

    <?=g360_scripts('landing')?>
    
    <script>
        // Scroll Reveal Animação
        document.addEventListener("DOMContentLoaded", () => {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.1 });

            document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));
        });
    </script>
</body>
</html>