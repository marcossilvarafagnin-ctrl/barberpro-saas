<?php
/**
 * Template – Painel Admin Frontend
 * Shortcode: [barberpro_painel_admin]
 *
 * Acesso controlado por capability. Padrão: barberpro_manage_bookings
 * Atributo: role="barber_admin|barber_professional" para restringir mais.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// $required_cap vem do shortcode

/** Gera link wa.me com mensagem pré-preenchida */
function bpa_wa_link( string $phone, string $msg = '' ): string {
    $digits = preg_replace( '/\D/', '', $phone );
    if ( strlen($digits) <= 11 ) $digits = '55' . $digits;
    $url = 'https://wa.me/' . $digits;
    if ( $msg ) $url .= '?text=' . rawurlencode($msg);
    return $url;
}
$cap = $required_cap ?? 'barberpro_manage_bookings';

// Detecta módulo via variável injetada pelo shortcode ou GET fallback
$bp_module    = sanitize_key( $bp_module_attr ?? $_GET['bp_module'] ?? 'barbearia' );
$company_id   = BarberPro_Modules::company_id( $bp_module );
$module_meta  = BarberPro_Modules::all()[$bp_module] ?? ['label'=>'✂️ Barbearia','icon'=>'✂️','color'=>'#e94560'];
$module_label = BarberPro_Database::get_setting("module_{$bp_module}_name", $module_meta['label']);
$module_icon  = $module_meta['icon'];
$module_color = $module_meta['color'];
$is_lavacar   = ( $bp_module === 'lavacar' );

if ( ! is_user_logged_in() ) {
    echo '<div class="bp-panel-login">'
       . '<div class="bp-login-box">'
       . '<div style="font-size:2.5rem;margin-bottom:12px">🔒</div>'
       . '<h2>Acesso Restrito</h2>'
       . '<p>Faça login para acessar o painel.</p>'
       . '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="bp-btn-primary">Entrar</a>'
       . '</div></div>';
    return;
}

if ( ! current_user_can( $cap ) ) {
    echo '<div class="bp-panel-login"><div class="bp-login-box">'
       . '<div style="font-size:2.5rem">🚫</div>'
       . '<h2>Sem Permissão</h2>'
       . '<p>Você não tem acesso a esta área.</p>'
       . '</div></div>';
    return;
}

// Aba ativa
$tab = isset( $_GET['bp_tab'] ) ? sanitize_key( $_GET['bp_tab'] ) : 'dashboard';

// URL base desta página
$page_url = get_permalink();

// Helpers
function bpa_money( $v ): string {
    return 'R$ ' . number_format( (float) $v, 2, ',', '.' );
}
function bpa_tab_url( string $t, array $extra = [] ): string {
    return esc_url( add_query_arg( array_merge( ['bp_tab' => $t], $extra ), get_permalink() ) );
}
function bpa_badge( string $status ): string {
    $map = [
        'agendado'       => ['#dbeafe','#1d4ed8','Agendado'],
        'confirmado'     => ['#ede9fe','#6d28d9','Confirmado'],
        'em_atendimento' => ['#fef3c7','#b45309','Em Atendimento'],
        'finalizado'     => ['#d1fae5','#065f46','Finalizado'],
        'cancelado'      => ['#fee2e2','#991b1b','Cancelado'],
        'recusado'       => ['#fee2e2','#991b1b','Recusado'],
    ];
    [$bg,$fg,$label] = $map[$status] ?? ['#f3f4f6','#374151',ucfirst($status)];
    return "<span style='background:{$bg};color:{$fg};padding:3px 10px;border-radius:20px;font-size:.75rem;font-weight:700;white-space:nowrap'>{$label}</span>";
}

$is_admin    = current_user_can( 'barberpro_manage_settings' );
$can_finance = current_user_can( 'barberpro_view_finance' );
$can_staff   = current_user_can( 'barberpro_manage_staff' );

// ── Processar ações POST ──────────────────────────────────────────────────────

// Atualizar status de agendamento
if ( isset($_POST['bp_status_nonce']) && wp_verify_nonce(sanitize_key($_POST['bp_status_nonce']), 'bpa_status') ) {
    BarberPro_Bookings::update_status( absint($_POST['booking_id']), sanitize_key($_POST['new_status']) );
    wp_redirect( add_query_arg(['bp_tab' => $tab], $page_url) );
    exit;
}

// Salvar serviço
if ( isset($_POST['bp_service_nonce']) && wp_verify_nonce(sanitize_key($_POST['bp_service_nonce']), 'bpa_service') && current_user_can('barberpro_manage_services') ) {
    $sid = absint($_POST['service_id'] ?? 0);
    $sdata = [
        'name'        => sanitize_text_field($_POST['svc_name']  ?? ''),
        'price'       => (float) str_replace(['.', ','], ['', '.'], $_POST['svc_price'] ?? '0'),
        'duration'    => absint($_POST['svc_duration'] ?? 30),
        'category'    => sanitize_text_field($_POST['svc_category'] ?? ''),
        'description' => sanitize_textarea_field($_POST['svc_description'] ?? ''),
    ];
    if ($sid) BarberPro_Database::update_service($sid, $sdata);
    else      BarberPro_Database::insert_service($sdata);
    wp_redirect( add_query_arg(['bp_tab' => 'servicos'], $page_url) );
    exit;
}

// Excluir serviço
if ( isset($_GET['bp_del_svc'], $_GET['_n']) && wp_verify_nonce(sanitize_key($_GET['_n']), 'bpa_delsvc') && current_user_can('barberpro_manage_services') ) {
    BarberPro_Database::delete_service( absint($_GET['bp_del_svc']) );
    wp_redirect( add_query_arg(['bp_tab' => 'servicos'], $page_url) );
    exit;
}

// Salvar lançamento financeiro
if ( isset($_POST['bp_fin_nonce']) && wp_verify_nonce(sanitize_key($_POST['bp_fin_nonce']), 'bpa_fin') && $can_finance ) {
    $fid = absint($_POST['fin_id'] ?? 0);
    $fdata = [
        'type'             => sanitize_key($_POST['fin_type'] ?? 'despesa'),
        'category_id'      => absint($_POST['fin_category_id'] ?? 0) ?: null,
        'description'      => sanitize_text_field($_POST['fin_desc'] ?? ''),
        'amount'           => (float) str_replace(['.', ','], ['', '.'], $_POST['fin_amount'] ?? '0'),
        'payment_method'   => sanitize_key($_POST['fin_method'] ?? 'dinheiro'),
        'status'           => sanitize_key($_POST['fin_status'] ?? 'pago'),
        'competencia_date' => sanitize_text_field($_POST['fin_date'] ?? current_time('Y-m-d')),
        'due_date'         => sanitize_text_field($_POST['fin_due'] ?? '') ?: null,
        'supplier'         => sanitize_text_field($_POST['fin_supplier'] ?? ''),
        'invoice_number'   => sanitize_text_field($_POST['fin_invoice'] ?? ''),
        'notes'            => sanitize_textarea_field($_POST['fin_notes'] ?? ''),
    ];
    if ($fid) BarberPro_Finance::update($fid, $fdata);
    else      BarberPro_Finance::insert($fdata);
    wp_redirect( add_query_arg(['bp_tab' => 'financeiro'], $page_url) );
    exit;
}

// Salvar profissional
if ( isset($_POST['bp_pro_nonce']) && wp_verify_nonce(sanitize_key($_POST['bp_pro_nonce']), 'bpa_pro') && $can_staff ) {
    $pro_id  = absint($_POST['pro_id'] ?? 0);
    $pro_cid = absint($_POST['pro_company_id'] ?? $company_id);
    $pro_data = [
        'name'           => sanitize_text_field($_POST['pro_name'] ?? ''),
        'specialty'      => sanitize_text_field($_POST['pro_specialty'] ?? ''),
        'commission_pct' => (float)($_POST['pro_commission'] ?? 30),
        'phone'          => sanitize_text_field($_POST['pro_phone'] ?? ''),
        'email'          => sanitize_email($_POST['pro_email'] ?? ''),
        'status'         => sanitize_key($_POST['pro_status'] ?? 'active'),
        'company_id'     => $pro_cid,
    ];
    if ( $pro_id ) {
        BarberPro_Professionals::update( $pro_id, $pro_data );
    } else {
        BarberPro_Professionals::create( $pro_data );
    }
    wp_redirect( add_query_arg(['bp_tab' => 'profissionais'], $page_url) );
    exit;
}

// Excluir profissional
if ( isset($_GET['bp_del_pro'], $_GET['_np']) && $can_staff ) {
    $del_pro_id = absint($_GET['bp_del_pro']);
    if ( wp_verify_nonce(sanitize_key($_GET['_np']), 'bpa_delpro_' . $del_pro_id) ) {
        BarberPro_Professionals::delete( $del_pro_id );
    }
    wp_redirect( add_query_arg(['bp_tab' => 'profissionais'], $page_url) );
    exit;
}

// Quitar conta
if ( isset($_GET['bp_pay'], $_GET['_n']) && wp_verify_nonce(sanitize_key($_GET['_n']), 'bpa_pay') && $can_finance ) {
    BarberPro_Finance::update( absint($_GET['bp_pay']), ['status' => 'pago', 'paid_at' => current_time('mysql')] );
    wp_redirect( add_query_arg(['bp_tab' => $tab], $page_url) );
    exit;
}

// ── Carregar dados da aba ativa ───────────────────────────────────────────────
$dashboard      = null; $bookings = null; $services = null;
$professionals  = null; $fin_data = null; $fin_cats = null;

$date_from = sanitize_text_field($_GET['date_from'] ?? date('Y-m-01'));
$date_to   = sanitize_text_field($_GET['date_to']   ?? date('Y-m-d'));

switch ( $tab ) {
    case 'dashboard':
        $dashboard      = BarberPro_Finance::get_dashboard( $company_id );
        $bookings_today = BarberPro_Database::get_bookings(['date' => current_time('Y-m-d'), 'company_id' => $company_id]);
        break;
    case 'agendamentos':
    case 'kanban':
        $bookings      = BarberPro_Database::get_bookings([
            'status'     => sanitize_key($_GET['f_status'] ?? ''),
            'date'       => sanitize_text_field($_GET['f_date'] ?? ''),
            'pro_id'     => absint($_GET['f_pro'] ?? 0),
            'company_id' => $company_id,
        ]);
        $professionals = BarberPro_Database::get_professionals( $company_id );
        break;
    case 'servicos':
        $services    = BarberPro_Database::get_services( $company_id );
        $editing_svc = isset($_GET['edit_svc']) ? BarberPro_Database::get_service(absint($_GET['edit_svc'])) : null;
        if ($is_lavacar && $editing_svc) {
            $editing_variants = BarberPro_Database::get_service_variants( (int)$editing_svc->id );
        }
        break;
    case 'profissionais':
        $professionals = BarberPro_Database::get_professionals( $company_id );
        $editing_pro   = isset($_GET['edit_pro']) ? BarberPro_Database::get_professional(absint($_GET['edit_pro'])) : null;
        break;
    case 'financeiro':
        $fin_data = BarberPro_Finance::list(['date_from' => $date_from, 'date_to' => $date_to, 'limit' => 100, 'company_id' => $company_id]);
        $fin_cats = BarberPro_Finance::get_categories();
        $fin_dash = BarberPro_Finance::get_dashboard( $company_id );
        $editing_fin = isset($_GET['edit_fin']) ? BarberPro_Finance::get(absint($_GET['edit_fin'])) : null;
        break;
}

$nonce_del_svc = wp_create_nonce('bpa_delsvc');
$nonce_pay     = wp_create_nonce('bpa_pay');
?>

<div class="bpa-wrap" id="bpaPanel">

    <!-- ── TOPBAR ──────────────────────────────────────────────────────────── -->
    <div class="bpa-topbar">
        <div class="bpa-logo" style="border-left:3px solid <?php echo esc_attr($module_color); ?>;padding-left:10px"><?php echo $module_icon; ?> <strong><?php echo esc_html($module_label); ?></strong> <span>Painel</span></div>
        <div class="bpa-user">
            👤 <?php echo esc_html( wp_get_current_user()->display_name ); ?>
            <a href="<?php echo esc_url(wp_logout_url(get_permalink())); ?>" class="bpa-logout">Sair</a>
        </div>
    </div>

    <!-- ── NAV ─────────────────────────────────────────────────────────────── -->
    <nav class="bpa-nav">
        <a href="<?php echo bpa_tab_url('dashboard'); ?>" class="bpa-nav-item <?php echo $tab==='dashboard'?'active':''; ?>">📊 Dashboard</a>
        <a href="<?php echo bpa_tab_url('kanban'); ?>"    class="bpa-nav-item <?php echo $tab==='kanban'?'active':''; ?>">🗂 Kanban</a>
        <a href="<?php echo bpa_tab_url('agendamentos'); ?>" class="bpa-nav-item <?php echo $tab==='agendamentos'?'active':''; ?>">📋 Agendamentos</a>
        <?php if ($can_staff): ?>
        <a href="<?php echo bpa_tab_url('profissionais'); ?>" class="bpa-nav-item <?php echo $tab==='profissionais'?'active':''; ?>"><?php echo $is_lavacar ? '👨 Atendentes' : '👨 Profissionais'; ?></a>
        <?php endif; ?>
        <a href="<?php echo bpa_tab_url('servicos'); ?>"  class="bpa-nav-item <?php echo $tab==='servicos'?'active':''; ?>"><?php echo $is_lavacar ? '🚿 Serviços' : '✂️ Serviços'; ?></a>
        <?php if ($can_finance): ?>
        <a href="<?php echo bpa_tab_url('financeiro'); ?>" class="bpa-nav-item <?php echo $tab==='financeiro'?'active':''; ?>">💰 Financeiro</a>
        <?php endif; ?>
    </nav>

    <div class="bpa-content">

    <?php /* ================================================================
             TAB: DASHBOARD
    ================================================================ */ ?>
    <?php if ($tab === 'dashboard' && $dashboard): ?>

    <div class="bpa-kpis">
        <?php
        $kpis = [
            ['💵','Receita Hoje',    $dashboard['today_revenue'],    ''],
            ['📈','Receita Mês',     $dashboard['monthly_receita'],  ''],
            ['📉','Despesas Mês',    $dashboard['monthly_despesa'],  'red'],
            ['🎯','Resultado Mês',   $dashboard['resultado_mensal'], $dashboard['resultado_mensal']>=0?'green':'red'],
            ['🏦','Saldo em Caixa',  $dashboard['caixa_saldo'],      ''],
        ];
        foreach ($kpis as [$icon, $label, $val, $cls]):
        ?>
        <div class="bpa-kpi-card <?php echo esc_attr($cls); ?>">
            <div class="bpa-kpi-icon"><?php echo $icon; ?></div>
            <div class="bpa-kpi-label"><?php echo esc_html($label); ?></div>
            <div class="bpa-kpi-value"><?php echo esc_html(bpa_money($val)); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($dashboard['vencidos_a_pagar'] > 0): ?>
    <div class="bpa-alert bpa-alert-red">
        ⚠️ Contas vencidas a pagar: <strong><?php echo esc_html(bpa_money($dashboard['vencidos_a_pagar'])); ?></strong>
        <a href="<?php echo bpa_tab_url('financeiro'); ?>">Ver →</a>
    </div>
    <?php endif; ?>

    <div class="bpa-grid-2">
        <div class="bpa-card">
            <h3>📈 Receita vs Despesa (12 meses)</h3>
            <canvas id="bpaChart12m" height="200"></canvas>
            <script>window.bpaChart12mData = <?php echo wp_json_encode($dashboard['chart_12m']); ?>;</script>
        </div>
        <div class="bpa-card">
            <h3>🔴 Top Despesas do Mês</h3>
            <table class="bpa-table">
                <thead><tr><th>Categoria</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ($dashboard['top_expenses'] as $e): ?>
                <tr>
                    <td><span class="bpa-dot" style="background:<?php echo esc_attr($e->color); ?>"></span><?php echo esc_html($e->name); ?></td>
                    <td><?php echo esc_html(bpa_money($e->total)); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($dashboard['top_expenses'])): ?>
                <tr><td colspan="2" class="bpa-empty">Nenhuma despesa este mês.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Agenda de hoje -->
    <div class="bpa-card" style="margin-top:16px">
        <h3>📅 Agenda de Hoje</h3>
        <?php if (empty($bookings_today)): ?>
        <p class="bpa-empty">Nenhum agendamento para hoje.</p>
        <?php else: ?>
        <div class="bpa-table-scroll">
        <table class="bpa-table">
            <thead><tr><th>Hora</th><th>Cliente</th><th>Serviço</th><th>Profissional</th><th>Status</th><th>Ação</th></tr></thead>
            <tbody>
            <?php foreach ($bookings_today as $b): ?>
            <tr>
                <td><strong><?php echo esc_html(substr($b->booking_time,0,5)); ?></strong></td>
                <td><?php echo esc_html($b->client_name); ?><br><small style="color:#6b7280"><?php echo esc_html($b->client_phone); ?></small></td>
                <td><?php echo esc_html($b->service_name); ?></td>
                <td><?php echo esc_html($b->professional_name); ?></td>
                <td><?php echo bpa_badge($b->status); ?></td>
                <td>
                    <form method="post" style="display:flex;gap:4px;align-items:center">
                        <?php wp_nonce_field('bpa_status','bp_status_nonce'); ?>
                        <input type="hidden" name="booking_id" value="<?php echo esc_attr($b->id); ?>">
                        <select name="new_status" style="font-size:.8rem;padding:4px 6px;border-radius:6px;border:1px solid #d1d5db">
                            <?php foreach (['agendado','confirmado','em_atendimento','finalizado','cancelado'] as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($b->status,$s); ?>><?php echo esc_html(ucfirst(str_replace('_',' ',$s))); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="bpa-btn-sm">✓</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <?php /* ================================================================
             TAB: KANBAN
    ================================================================ */ ?>
    <?php elseif ($tab === 'kanban'): ?>

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:16px">
        <h2 class="bpa-page-title" style="margin:0">🗂 Kanban</h2>
        <form method="get" style="display:flex;align-items:center;gap:8px">
            <?php foreach($_GET as $k=>$v): if($k==='kanban_date') continue; ?>
            <input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>">
            <?php endforeach; ?>
            <input type="date" name="kanban_date" value="<?php echo esc_attr($kanban_date); ?>"
                   style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg2);color:var(--text1)">
            <button type="submit" style="padding:5px 12px;border:1px solid var(--border);border-radius:6px;background:var(--bg2);color:var(--text1);cursor:pointer">🔍</button>
        </form>
    </div>
    <div class="bpa-kanban" id="bpaKanban" data-ajaxurl="<?php echo esc_attr(admin_url('admin-ajax.php')); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('barberpro_ajax')); ?>">
        <?php
        $statuses = [
            'agendado'       => ['Agendado',       '#3b82f6'],
            'confirmado'     => ['Confirmado',      '#8b5cf6'],
            'em_atendimento' => ['Em Atendimento',  '#f59e0b'],
            'finalizado'     => ['Finalizado',      '#10b981'],
            'cancelado'      => ['Cancelado',       '#ef4444'],
        ];
        $kanban_date = isset($_GET['kanban_date']) ? sanitize_text_field($_GET['kanban_date']) : current_time('Y-m-d');
        foreach ($statuses as $sk => $meta):
            $cols = BarberPro_Database::get_bookings(['status' => $sk, 'company_id' => $company_id, 'date' => $kanban_date]);
        ?>
        <div class="bpa-kanban-col" data-status="<?php echo esc_attr($sk); ?>">
            <div class="bpa-kanban-header" style="border-top:3px solid <?php echo esc_attr($meta[1]); ?>">
                <span><?php echo esc_html($meta[0]); ?></span>
                <span class="bpa-kanban-count"><?php echo count($cols); ?></span>
            </div>
            <div class="bpa-kanban-cards" data-status="<?php echo esc_attr($sk); ?>">
                <?php foreach ($cols as $b): ?>
                <div class="bpa-kanban-card" data-id="<?php echo esc_attr($b->id); ?>" draggable="true">
                    <div class="bpa-kc-time"><?php echo esc_html(date_i18n('d/m', strtotime($b->booking_date))); ?> <?php echo esc_html(substr($b->booking_time,0,5)); ?></div>
                    <div class="bpa-kc-client"><strong><?php echo esc_html($b->client_name); ?></strong></div>
                    <div class="bpa-kc-svc"><?php echo esc_html($b->service_name); ?></div>
                    <div class="bpa-kc-pro">👤 <?php echo esc_html($b->professional_name); ?></div>
                    <div class="bpa-kc-phone">📞 <?php echo esc_html($b->client_phone); ?></div>
                    <div class="bpa-kc-code">#<?php echo esc_html($b->booking_code); ?></div>
                    <?php
                    $wa_msg  = "Olá {$b->client_name}! Confirmando seu {$b->service_name} em " . date_i18n('d/m', strtotime($b->booking_date)) . " às " . substr($b->booking_time,0,5) . ". Cód: {$b->booking_code}";
                    $wa_link = bpa_wa_link( $b->client_phone, $wa_msg );
                    ?>
                    <a href="<?php echo esc_url($wa_link); ?>" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:4px;margin-top:6px;background:#25d366;color:#fff;text-decoration:none;padding:3px 9px;border-radius:20px;font-size:.72rem;font-weight:600">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                        <?php echo esc_html($b->client_phone); ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php /* ================================================================
             TAB: AGENDAMENTOS
    ================================================================ */ ?>
    <?php elseif ($tab === 'agendamentos'): ?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px">
        <h2 class="bpa-page-title" style="margin:0">📋 Agendamentos</h2>
    </div>

    <form method="get" class="bpa-filters">
        <input type="hidden" name="bp_tab" value="agendamentos">
        <input type="date" name="f_date" value="<?php echo esc_attr($_GET['f_date']??''); ?>">
        <select name="f_status">
            <option value="">Todos os status</option>
            <?php foreach (['agendado','confirmado','em_atendimento','finalizado','cancelado'] as $s): ?>
            <option value="<?php echo esc_attr($s); ?>" <?php selected($_GET['f_status']??'',$s); ?>><?php echo esc_html(ucfirst(str_replace('_',' ',$s))); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="f_pro">
            <option value="">Todos profissionais</option>
            <?php foreach ($professionals as $p): ?>
            <option value="<?php echo esc_attr($p->id); ?>" <?php selected(absint($_GET['f_pro']??0),$p->id); ?>><?php echo esc_html($p->name); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bpa-btn-outline">🔍 Filtrar</button>
    </form>

    <div class="bpa-table-scroll">
    <table class="bpa-table">
        <thead><tr>
            <th>Código</th><th>Data/Hora</th><th>Cliente</th><th>Telefone</th>
            <th>Serviço</th><th>Profissional</th><th>Pgto</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($bookings as $b): ?>
        <tr>
            <td><code><?php echo esc_html($b->booking_code); ?></code></td>
            <td><?php echo esc_html(date_i18n('d/m/Y', strtotime($b->booking_date)).' '.substr($b->booking_time,0,5)); ?></td>
            <td><strong><?php echo esc_html($b->client_name); ?></strong></td>
            <td><?php echo esc_html($b->client_phone); ?></td>
            <td><?php echo esc_html($b->service_name); ?></td>
            <td><?php echo esc_html($b->professional_name); ?></td>
            <td><small><?php echo esc_html(ucfirst(str_replace('_',' ',$b->payment_method))); ?></small></td>
            <td>
                <?php echo bpa_badge($b->status); ?>
                <form method="post" style="margin-top:4px;display:flex;gap:4px">
                    <?php wp_nonce_field('bpa_status','bp_status_nonce'); ?>
                    <input type="hidden" name="booking_id" value="<?php echo esc_attr($b->id); ?>">
                    <select name="new_status" style="font-size:.78rem;padding:3px 5px;border-radius:6px;border:1px solid #d1d5db">
                        <?php foreach (['agendado','confirmado','em_atendimento','finalizado','cancelado','recusado'] as $s): ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($b->status,$s); ?>><?php echo esc_html(ucfirst(str_replace('_',' ',$s))); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bpa-btn-sm">✓</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($bookings)): ?>
        <tr><td colspan="8" class="bpa-empty">Nenhum agendamento encontrado.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php /* ================================================================
             TAB: PROFISSIONAIS / ATENDENTES
    ================================================================ */ ?>
    <?php elseif ($tab === 'profissionais' && $can_staff): ?>

    <h2 class="bpa-page-title"><?php echo $is_lavacar ? '👨 Atendentes' : '👨 Profissionais'; ?></h2>
    <div class="bpa-grid-2">

        <!-- FORMULÁRIO cadastro/edição -->
        <div class="bpa-card">
            <h3><?php echo isset($editing_pro) ? '✏️ Editar' : '➕ Novo'; ?> <?php echo $is_lavacar ? 'Atendente' : 'Profissional'; ?></h3>
            <?php if (!$can_staff): ?>
            <p class="bpa-empty">Sem permissão.</p>
            <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('bpa_pro','bp_pro_nonce'); ?>
                <input type="hidden" name="pro_id" value="<?php echo isset($editing_pro) ? esc_attr($editing_pro->id) : '0'; ?>">
                <input type="hidden" name="pro_company_id" value="<?php echo esc_attr($company_id); ?>">
                <div class="bpa-field">
                    <label>Nome completo *</label>
                    <input type="text" name="pro_name" required value="<?php echo esc_attr($editing_pro->name??''); ?>">
                </div>
                <div class="bpa-field">
                    <label><?php echo $is_lavacar ? 'Função' : 'Especialidade'; ?></label>
                    <input type="text" name="pro_specialty" value="<?php echo esc_attr($editing_pro->specialty??''); ?>"
                           placeholder="<?php echo $is_lavacar ? 'Ex: Lavador, Polidor' : 'Ex: Barbeiro, Colorista'; ?>">
                </div>
                <div class="bpa-row-2">
                    <div class="bpa-field">
                        <label>Comissão (%)</label>
                        <input type="number" name="pro_commission" min="0" max="100" step="0.5"
                               value="<?php echo esc_attr($editing_pro->commission_pct??30); ?>">
                    </div>
                    <div class="bpa-field">
                        <label>Telefone / WhatsApp</label>
                        <input type="tel" name="pro_phone" value="<?php echo esc_attr($editing_pro->phone??''); ?>"
                               placeholder="(44) 99999-9999">
                    </div>
                </div>
                <div class="bpa-field">
                    <label>E-mail</label>
                    <input type="email" name="pro_email" value="<?php echo esc_attr($editing_pro->email??''); ?>">
                </div>
                <div class="bpa-field">
                    <label>Status</label>
                    <select name="pro_status">
                        <option value="active"   <?php selected(($editing_pro->status??'active'),'active'); ?>>✅ Ativo</option>
                        <option value="inactive" <?php selected(($editing_pro->status??'active'),'inactive'); ?>>❌ Inativo</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <button type="submit" class="bpa-btn-primary">
                        <?php echo isset($editing_pro) ? '✅ Salvar' : '➕ Cadastrar'; ?>
                    </button>
                    <?php if (isset($editing_pro)): ?>
                    <a href="<?php echo bpa_tab_url('profissionais'); ?>" class="bpa-btn-outline">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <!-- LISTA de profissionais -->
        <div class="bpa-card">
            <h3><?php echo $is_lavacar ? '👨 Atendentes cadastrados' : '👨 Profissionais cadastrados'; ?></h3>
            <?php if (empty($professionals)): ?>
            <p class="bpa-empty">Nenhum <?php echo $is_lavacar ? 'atendente' : 'profissional'; ?> cadastrado ainda.</p>
            <?php else: ?>
            <div class="bpa-pro-grid" style="grid-template-columns:1fr;gap:10px">
                <?php foreach ($professionals as $p):
                    $nonce_del_pro = wp_create_nonce('bpa_delpro_' . $p->id);
                ?>
                <div class="bpa-pro-card" style="display:flex;align-items:center;gap:14px;text-align:left;padding:14px">
                    <div class="bpa-pro-avatar" style="width:44px;height:44px;font-size:1rem;flex-shrink:0">
                        <?php echo esc_html(mb_strtoupper(mb_substr($p->name,0,1))); ?>
                    </div>
                    <div style="flex:1;min-width:0">
                        <h4 style="margin:0 0 2px;font-size:.92rem"><?php echo esc_html($p->name); ?></h4>
                        <?php if (!empty($p->specialty)): ?>
                        <p style="margin:0;font-size:.78rem;color:#6b7280"><?php echo esc_html($p->specialty); ?></p>
                        <?php endif; ?>
                        <div style="font-size:.78rem;color:#9ca3af;margin-top:2px">
                            Comissão: <?php echo esc_html($p->commission_pct); ?>%
                            <?php if (!empty($p->phone)): ?> · 📱 <?php echo esc_html($p->phone); ?><?php endif; ?>
                        </div>
                        <div style="margin-top:4px">
                            <?php for ($i=1;$i<=5;$i++) echo $i<=round($p->rating)?'⭐':'☆'; ?>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px">
                        <a href="<?php echo esc_url(add_query_arg(['bp_tab'=>'profissionais','edit_pro'=>$p->id], get_permalink())); ?>"
                           class="bpa-btn-sm">✏️</a>
                        <a href="<?php echo esc_url(add_query_arg(['bp_tab'=>'profissionais','bp_del_pro'=>$p->id,'_np'=>$nonce_del_pro], get_permalink())); ?>"
                           class="bpa-btn-sm bpa-btn-danger"
                           onclick="return confirm('Remover este <?php echo $is_lavacar ? 'atendente' : 'profissional'; ?>?')">🗑</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <?php /* ================================================================
             TAB: SERVIÇOS
    ================================================================ */ ?>
    <?php elseif ($tab === 'servicos'): ?>

    <div class="bpa-grid-2">
        <div class="bpa-card">
            <h3><?php echo isset($editing_svc) ? '✏️ Editar Serviço' : '➕ Novo Serviço'; ?></h3>
            <?php if (!current_user_can('barberpro_manage_services')): ?>
            <p class="bpa-empty">Sem permissão para gerenciar serviços.</p>
            <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('bpa_service','bp_service_nonce'); ?>
                <input type="hidden" name="service_id" value="<?php echo isset($editing_svc) ? esc_attr($editing_svc->id) : '0'; ?>">
                <div class="bpa-field">
                    <label>Nome *</label>
                    <input type="text" name="svc_name" required value="<?php echo esc_attr($editing_svc->name??''); ?>">
                </div>
                <div class="bpa-row-2">
                    <div class="bpa-field">
                        <label>Preço (R$) *</label>
                        <input type="text" name="svc_price" required value="<?php echo isset($editing_svc) ? esc_attr(number_format($editing_svc->price,2,',','.')) : ''; ?>" placeholder="0,00">
                    </div>
                    <div class="bpa-field">
                        <label>Duração (min) *</label>
                        <input type="number" name="svc_duration" required min="5" value="<?php echo esc_attr($editing_svc->duration??30); ?>">
                    </div>
                </div>
                <div class="bpa-field">
                    <label>Categoria</label>
                    <input type="text" name="svc_category" value="<?php echo esc_attr($editing_svc->category??''); ?>" placeholder="Ex: Cabelo, Barba...">
                </div>
                <div class="bpa-field">
                    <label>Descrição</label>
                    <textarea name="svc_description" rows="2"><?php echo esc_textarea($editing_svc->description??''); ?></textarea>
                </div>
                <div style="display:flex;gap:8px">
                    <button type="submit" class="bpa-btn-primary">
                        <?php echo isset($editing_svc) ? '✅ Salvar' : '➕ Adicionar'; ?>
                    </button>
                    <?php if (isset($editing_svc)): ?>
                    <a href="<?php echo bpa_tab_url('servicos'); ?>" class="bpa-btn-outline">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
            <?php endif; ?>
        </div>

        <div class="bpa-card">
            <h3>✂️ Lista de Serviços</h3>
            <table class="bpa-table">
                <thead><tr><th>Nome</th><th>Preço</th><th>Duração</th><th>Categoria</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach ($services as $s): ?>
                <tr>
                    <td><strong><?php echo esc_html($s->name); ?></strong></td>
                    <td><?php echo esc_html(bpa_money($s->price)); ?></td>
                    <td><?php echo esc_html($s->duration); ?>min</td>
                    <td><small><?php echo esc_html($s->category??'—'); ?></small></td>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg(['bp_tab'=>'servicos','edit_svc'=>$s->id], get_permalink())); ?>" class="bpa-btn-sm">✏️</a>
                        <a href="<?php echo esc_url(add_query_arg(['bp_tab'=>'servicos','bp_del_svc'=>$s->id,'_n'=>$nonce_del_svc], get_permalink())); ?>"
                           class="bpa-btn-sm bpa-btn-danger" onclick="return confirm('Excluir este serviço?')">🗑</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($services)): ?>
                <tr><td colspan="5" class="bpa-empty">Nenhum serviço cadastrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php /* ================================================================
             TAB: FINANCEIRO
    ================================================================ */ ?>
    <?php elseif ($tab === 'financeiro' && $can_finance): ?>

    <!-- KPIs financeiro -->
    <div class="bpa-kpis" style="margin-bottom:16px">
        <div class="bpa-kpi-card"><div class="bpa-kpi-icon">📈</div><div class="bpa-kpi-label">Receita Mês</div><div class="bpa-kpi-value"><?php echo esc_html(bpa_money($fin_dash['monthly_receita'])); ?></div></div>
        <div class="bpa-kpi-card red"><div class="bpa-kpi-icon">📉</div><div class="bpa-kpi-label">Despesas Mês</div><div class="bpa-kpi-value"><?php echo esc_html(bpa_money($fin_dash['monthly_despesa'])); ?></div></div>
        <div class="bpa-kpi-card <?php echo $fin_dash['resultado_mensal']>=0?'green':'red'; ?>"><div class="bpa-kpi-icon">🎯</div><div class="bpa-kpi-label">Resultado</div><div class="bpa-kpi-value"><?php echo esc_html(bpa_money($fin_dash['resultado_mensal'])); ?></div></div>
        <div class="bpa-kpi-card"><div class="bpa-kpi-icon">🏦</div><div class="bpa-kpi-label">Saldo Caixa</div><div class="bpa-kpi-value"><?php echo esc_html(bpa_money($fin_dash['caixa_saldo'])); ?></div></div>
    </div>

    <div class="bpa-grid-2">

        <!-- Formulário -->
        <div class="bpa-card">
            <h3><?php echo isset($editing_fin) ? '✏️ Editar Lançamento' : '➕ Novo Lançamento'; ?></h3>
            <form method="post">
                <?php wp_nonce_field('bpa_fin','bp_fin_nonce'); ?>
                <input type="hidden" name="fin_id" value="<?php echo isset($editing_fin) ? esc_attr($editing_fin->id) : '0'; ?>">
                <div class="bpa-row-2">
                    <div class="bpa-field">
                        <label>Tipo</label>
                        <select name="fin_type">
                            <option value="receita" <?php selected($editing_fin->type??'','receita'); ?>>💚 Receita</option>
                            <option value="despesa" <?php selected($editing_fin->type??'despesa','despesa'); ?>>🔴 Despesa</option>
                        </select>
                    </div>
                    <div class="bpa-field">
                        <label>Categoria</label>
                        <select name="fin_category_id">
                            <option value="">-- Selecione --</option>
                            <?php foreach ($fin_cats as $c): ?>
                            <option value="<?php echo esc_attr($c->id); ?>" <?php selected($editing_fin->category_id??0,$c->id); ?>>
                                [<?php echo esc_html($c->code); ?>] <?php echo esc_html($c->name); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="bpa-field">
                    <label>Descrição *</label>
                    <input type="text" name="fin_desc" required value="<?php echo esc_attr($editing_fin->description??''); ?>">
                </div>
                <div class="bpa-row-2">
                    <div class="bpa-field">
                        <label>Valor (R$) *</label>
                        <input type="text" name="fin_amount" required value="<?php echo isset($editing_fin) ? esc_attr(number_format($editing_fin->amount,2,',','.')) : ''; ?>" placeholder="0,00">
                    </div>
                    <div class="bpa-field">
                        <label>Status</label>
                        <select name="fin_status">
                            <option value="pago"     <?php selected($editing_fin->status??'pago','pago'); ?>>✅ Pago</option>
                            <option value="pendente" <?php selected($editing_fin->status??'','pendente'); ?>>⏳ Pendente</option>
                        </select>
                    </div>
                </div>
                <div class="bpa-row-2">
                    <div class="bpa-field">
                        <label>Competência</label>
                        <input type="date" name="fin_date" value="<?php echo esc_attr($editing_fin->competencia_date ?? current_time('Y-m-d')); ?>">
                    </div>
                    <div class="bpa-field">
                        <label>Vencimento</label>
                        <input type="date" name="fin_due" value="<?php echo esc_attr($editing_fin->due_date??''); ?>">
                    </div>
                </div>
                <div class="bpa-row-2">
                    <div class="bpa-field">
                        <label>Fornecedor/Cliente</label>
                        <input type="text" name="fin_supplier" value="<?php echo esc_attr($editing_fin->supplier??''); ?>">
                    </div>
                    <div class="bpa-field">
                        <label>NF/Recibo</label>
                        <input type="text" name="fin_invoice" value="<?php echo esc_attr($editing_fin->invoice_number??''); ?>">
                    </div>
                </div>
                <div class="bpa-field">
                    <label>Forma de Pagamento</label>
                    <select name="fin_method">
                        <?php foreach (['dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Débito','cartao_credito'=>'Crédito','transferencia'=>'Transferência','boleto'=>'Boleto'] as $v=>$l): ?>
                        <option value="<?php echo esc_attr($v); ?>" <?php selected($editing_fin->payment_method??'dinheiro',$v); ?>><?php echo esc_html($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <button type="submit" class="bpa-btn-primary"><?php echo isset($editing_fin) ? '✅ Salvar' : '➕ Registrar'; ?></button>
                    <?php if (isset($editing_fin)): ?>
                    <a href="<?php echo bpa_tab_url('financeiro'); ?>" class="bpa-btn-outline">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Lista -->
        <div class="bpa-card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
                <h3 style="margin:0">📋 Lançamentos</h3>
                <form method="get" style="display:flex;gap:6px;flex-wrap:wrap">
                    <input type="hidden" name="bp_tab" value="financeiro">
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                    <input type="date" name="date_to"   value="<?php echo esc_attr($date_to); ?>">
                    <button type="submit" class="bpa-btn-sm">🔍</button>
                </form>
            </div>
            <?php
            $total_r = array_sum(array_map(function($l) { return $l->type==='receita' ? $l->amount : 0; }, $fin_data));
            $total_d = array_sum(array_map(function($l) { return $l->type==='despesa' ? $l->amount : 0; }, $fin_data));
            ?>
            <div style="display:flex;gap:12px;margin-bottom:10px;font-size:.85rem;font-weight:600">
                <span style="color:#10b981">↑ <?php echo esc_html(bpa_money($total_r)); ?></span>
                <span style="color:#ef4444">↓ <?php echo esc_html(bpa_money($total_d)); ?></span>
                <span>= <?php echo esc_html(bpa_money($total_r-$total_d)); ?></span>
            </div>
            <div class="bpa-table-scroll">
            <table class="bpa-table">
                <thead><tr><th>Tipo</th><th>Descrição</th><th>Categoria</th><th>Data</th><th>Valor</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($fin_data as $l):
                    $tc = $l->type==='receita'?'#10b981':'#ef4444';
                    $st_map = ['pago'=>['✅','#d1fae5','#065f46'],'pendente'=>['⏳','#fef3c7','#92400e'],'vencido'=>['🔴','#fee2e2','#991b1b']];
                    [$si,$sbg,$sfg] = $st_map[$l->status] ?? ['?','#f3f4f6','#374151'];
                ?>
                <tr>
                    <td><strong style="color:<?php echo esc_attr($tc); ?>"><?php echo $l->type==='receita'?'↑':'↓'; ?></strong></td>
                    <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo esc_html($l->description); ?></td>
                    <td><small><?php echo esc_html($l->category_name??'—'); ?></small></td>
                    <td><small><?php echo esc_html($l->competencia_date); ?></small></td>
                    <td style="color:<?php echo esc_attr($tc); ?>;font-weight:700;white-space:nowrap"><?php echo esc_html(bpa_money($l->amount)); ?></td>
                    <td><span style="background:<?php echo esc_attr($sbg); ?>;color:<?php echo esc_attr($sfg); ?>;padding:2px 6px;border-radius:4px;font-size:.72rem"><?php echo $si.' '.esc_html(ucfirst($l->status)); ?></span></td>
                    <td style="white-space:nowrap">
                        <?php if ($l->status !== 'pago'): ?>
                        <a href="<?php echo esc_url(add_query_arg(['bp_tab'=>'financeiro','bp_pay'=>$l->id,'_n'=>$nonce_pay,'date_from'=>$date_from,'date_to'=>$date_to],get_permalink())); ?>" class="bpa-btn-sm" onclick="return confirm('Marcar como pago?')">✅</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(add_query_arg(['bp_tab'=>'financeiro','edit_fin'=>$l->id,'date_from'=>$date_from,'date_to'=>$date_to],get_permalink())); ?>" class="bpa-btn-sm">✏️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fin_data)): ?>
                <tr><td colspan="7" class="bpa-empty">Nenhum lançamento no período.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- .bpa-content -->
</div><!-- .bpa-wrap -->
