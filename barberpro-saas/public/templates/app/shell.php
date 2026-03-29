<?php
/**
 * BarberPro App – Shell principal
 * Shortcode: [barberpro_app] / [barberpro_bar_caixa]
 * bpAppData is localized by the shortcode function in class-frontend.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<!-- Body class via JS to avoid FOUC -->
<script>document.body.classList.add('bp-app-page');</script>

<div id="bpAppRoot">

<!-- ═══════════════════════════════════════ LOGIN ═════════════ -->
<div id="bpLogin" style="display:none">
    <div class="bp-login-box">
        <div class="bp-login-logo">
            <div class="bp-login-logo-icon">✂️</div>
            <h1><?php echo esc_html(BarberPro_Database::get_setting('business_name', get_bloginfo('name'))); ?></h1>
            <p>Acesse o painel de gestão</p>
        </div>
        <div class="bp-login-error" id="bpLoginError"></div>
        <form id="bpLoginForm" autocomplete="on">
            <div class="bp-field">
                <label>Usuário ou E-mail</label>
                <input type="text" name="username" placeholder="seu@email.com" autocomplete="username" required>
            </div>
            <div class="bp-field">
                <label>Senha</label>
                <input type="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
            </div>
            <button type="submit" class="bp-btn bp-btn-primary">Entrar</button>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════ APP SHELL ════════ -->
<div id="bpApp" style="display:none">

    <!-- Sidebar overlay (mobile) -->
    <div id="bpSidebarOverlay"></div>

    <!-- ── Sidebar ── -->
    <aside id="bpSidebar">
        <div class="bp-sidebar-logo">
            <div class="bp-sidebar-logo-icon">✂️</div>
            <div class="bp-sidebar-logo-text">Barber<span>Pro</span></div>
        </div>
        <div class="bp-sidebar-user">
            <div class="bp-user-avatar">U</div>
            <div>
                <div class="bp-user-name">Carregando...</div>
                <div class="bp-user-role">—</div>
            </div>
        </div>
        <nav class="bp-sidebar-nav" id="bpSidebarNav">
            <!-- preenchido pelo JS -->
        </nav>
        <div class="bp-sidebar-footer">
            <button class="bp-nav-item" onclick="BP.logout()" style="color:var(--red)">
                <span class="bp-nav-icon">🚪</span>
                <span>Sair</span>
            </button>
        </div>
    </aside>

    <!-- ── Main ── -->
    <div id="bpMain">

        <!-- Top bar (tablet/mobile) -->
        <header id="bpTopbar">
            <button class="bp-topbar-menu-btn" onclick="BP.openSidebar()" aria-label="Menu">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                    <path d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <div class="bp-topbar-logo">Barber<span>Pro</span></div>
            <div class="bp-topbar-title" style="display:none"></div>
            <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
                <div class="bp-user-avatar" style="width:32px;height:32px;font-size:12px">U</div>
            </div>
        </header>

        <!-- Content area -->
        <main id="bpContent">
            <div class="bp-loading">
                <div class="bp-spinner"></div>
                <span>Iniciando...</span>
            </div>
        </main>
    </div>

    <!-- Bottom nav (mobile) -->
    <nav id="bpBottomNav">
        <div class="bp-bottom-nav-inner" id="bpBottomNavInner">
            <!-- preenchido pelo JS -->
        </div>
    </nav>
</div>

</div><!-- #bpAppRoot -->

<?php /* bpAppData is localized in shortcode_app / shortcode_bar_caixa */ ?>
