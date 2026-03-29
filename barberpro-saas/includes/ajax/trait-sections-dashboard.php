<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Sections_Dashboard {

    private function section_dashboard(): void {
        global $wpdb;
        $today    = current_time('Y-m-d');
        $month    = current_time('Y-m');

        // Agendamentos hoje (todos módulos)
        $bookings_today = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}barber_bookings WHERE booking_date=%s AND status NOT IN ('cancelado')", $today
        ));
        // Receita do mês (todos)
        $receita_mes = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}barber_finance
             WHERE type='receita' AND status='pago' AND DATE_FORMAT(competencia_date,'%%Y-%%m')=%s", $month
        ));
        // Comandas abertas bar
        $comandas_abertas = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}barber_bar_comandas WHERE status='aberta'"
        );
        // Estoque baixo
        $low_stock = (int)$wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}barber_products WHERE status='active' AND stock_qty<=stock_min AND stock_min>0"
        );
        // Últimos agendamentos
        $recent = $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, c.name AS company_name
             FROM {$wpdb->prefix}barber_bookings b
             LEFT JOIN {$wpdb->prefix}barber_companies c ON b.company_id=c.id
             WHERE b.booking_date >= %s ORDER BY b.booking_date ASC, b.booking_time ASC LIMIT 8", $today
        )) ?: [];
        // Receita últimos 7 dias
        $chart = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(competencia_date) as dia, COALESCE(SUM(amount),0) as total
             FROM {$wpdb->prefix}barber_finance
             WHERE type='receita' AND status='pago' AND competencia_date >= DATE_SUB(%s, INTERVAL 6 DAY)
             GROUP BY dia ORDER BY dia ASC", $today
        )) ?: [];

        $active_mods = BarberPro_Modules::active_list();
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">Dashboard</div>
                <div class="bp-page-subtitle"><?php echo date_i18n('l, d \d\e F \d\e Y'); ?></div>
            </div>
        </div>

        <div class="bp-kpi-grid bp-stagger">
            <div class="bp-kpi amber">
                <div class="bp-kpi-label">Agendamentos hoje</div>
                <div class="bp-kpi-value amber"><?php echo $bookings_today; ?></div>
                <div class="bp-kpi-sub">todos os módulos</div>
            </div>
            <div class="bp-kpi green">
                <div class="bp-kpi-label">Receita do mês</div>
                <div class="bp-kpi-value green"><?php echo $this->money($receita_mes); ?></div>
                <div class="bp-kpi-sub"><?php echo date_i18n('F Y'); ?></div>
            </div>
            <div class="bp-kpi blue">
                <div class="bp-kpi-label">Comandas abertas</div>
                <div class="bp-kpi-value blue"><?php echo $comandas_abertas; ?></div>
                <div class="bp-kpi-sub">bar / eventos</div>
            </div>
            <div class="bp-kpi <?php echo $low_stock>0?'red':'green'; ?>">
                <div class="bp-kpi-label">Estoque baixo</div>
                <div class="bp-kpi-value <?php echo $low_stock>0?'red':'green'; ?>"><?php echo $low_stock; ?></div>
                <div class="bp-kpi-sub"><?php echo $low_stock>0?'produtos abaixo do mínimo':'tudo ok'; ?></div>
            </div>
        </div>

        <?php if (!empty($chart)): ?>
        <div class="bp-card bp-animate-in" style="margin-bottom:16px">
            <div class="bp-card-header"><div class="bp-card-title">📈 Receita – Últimos 7 dias</div></div>
            <canvas id="bpDashChart" style="max-height:200px"></canvas>
        </div>
        <?php endif; ?>

        <div class="bp-card bp-animate-in">
            <div class="bp-card-header">
                <div class="bp-card-title">📅 Próximos Agendamentos</div>
                <?php foreach($active_mods as $key=>$mod): ?>
                <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="BP.navigate('<?php echo $key; ?>_agenda')"><?php echo $mod['icon']; ?> Ver todos</button>
                <?php break; endforeach; ?>
            </div>
            <?php if(empty($recent)): ?>
            <div class="bp-empty"><div class="bp-empty-icon">📅</div><div class="bp-empty-title">Nenhum agendamento hoje</div></div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Cliente</th><th>Serviço</th><th>Hora</th><th>Módulo</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach($recent as $b):
                    $status_map = ['agendado'=>['Agendado','amber'],'confirmado'=>['Confirmado','blue'],'finalizado'=>['Finalizado','green'],'cancelado'=>['Cancelado','red'],'em_atendimento'=>['Em atendimento','blue']];
                    [$slabel,$scls] = $status_map[$b->status] ?? [$b->status,'gray'];
                ?>
                <tr>
                    <td><strong><?php echo esc_html($b->client_name); ?></strong><br><small style="color:var(--text3)"><?php echo esc_html($b->client_phone??''); ?></small></td>
                    <td><?php echo esc_html($b->service_name??'—'); ?></td>
                    <td style="font-family:var(--font-mono);color:var(--accent)"><?php echo esc_html(substr($b->booking_time??'',0,5)); ?></td>
                    <td><?php echo esc_html($b->company_name??'—'); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $scls; ?>"><?php echo esc_html($slabel); ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Ganhos ────────────────────────────────────────────────────
    private function section_ganhos( string $tab ): void {
        $preset = sanitize_key($_GET['preset'] ?? $tab ?: 'mes');
        $today  = current_time('Y-m-d');
        switch($preset) {
            case 'hoje':   $df=$dt=$today; $label='Hoje'; break;
            case 'semana': $df=date('Y-m-d',strtotime('monday this week')); $dt=date('Y-m-d',strtotime('sunday this week')); $label='Esta Semana'; break;
            case 'ano':    $df=date('Y').'-01-01'; $dt=date('Y').'-12-31'; $label='Este Ano'; break;
            default: $preset='mes'; $df=date('Y-m-01'); $dt=date('Y-m-t'); $label='Este Mês';
        }
        $view = sanitize_key($_GET['view'] ?? 'todos');
        $cid  = $view==='todos' ? 0 : BarberPro_Modules::company_id($view);
        $data = BarberPro_Finance::get_period_summary($df, $dt, $cid);
        $active_mods = BarberPro_Modules::active_list();
        $company_names = [
            1=>BarberPro_Database::get_setting('module_barbearia_name','Barbearia'),
            2=>BarberPro_Database::get_setting('module_lavacar_name','Lava-Car'),
            3=>'Bar/Eventos',
        ];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">💰 Painel de Ganhos</div>
                <div class="bp-page-subtitle"><?php echo esc_html($label); ?></div>
            </div>
        </div>

        <!-- Presets -->
        <div class="bp-finance-presets bp-animate-in">
            <?php foreach(['hoje'=>'Hoje','semana'=>'Semana','mes'=>'Mês','ano'=>'Ano'] as $k=>$l): ?>
            <button class="bp-preset-btn <?php echo $preset===$k?'active':''; ?>"
                onclick="BP.navigate('ganhos','<?php echo $k; ?>')"><?php echo esc_html($l); ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Módulos -->
        <div class="bp-finance-presets bp-animate-in">
            <button class="bp-preset-btn <?php echo $view==='todos'?'active':''; ?>" onclick="BP.navigate('ganhos')">🏢 Todos</button>
            <?php foreach($active_mods as $mk=>$mm): ?>
            <button class="bp-preset-btn <?php echo $view===$mk?'active':''; ?>" onclick="BP.navigate('ganhos')"><?php echo $mm['icon'].' '.esc_html(BarberPro_Database::get_setting('module_'.$mk.'_name',$mm['label'])); ?></button>
            <?php endforeach; ?>
        </div>

        <!-- KPIs -->
        <div class="bp-kpi-grid bp-stagger">
            <div class="bp-kpi green"><div class="bp-kpi-label">Receita</div><div class="bp-kpi-value green"><?php echo $this->money($data['receita']); ?></div></div>
            <div class="bp-kpi red"><div class="bp-kpi-label">Despesas</div><div class="bp-kpi-value red"><?php echo $this->money($data['despesa']); ?></div></div>
            <div class="bp-kpi <?php echo $data['lucro']>=0?'green':'red'; ?>"><div class="bp-kpi-label">Lucro</div><div class="bp-kpi-value <?php echo $data['lucro']>=0?'green':'red'; ?>"><?php echo $this->money($data['lucro']); ?></div></div>
            <div class="bp-kpi amber"><div class="bp-kpi-label">Margem</div><div class="bp-kpi-value amber"><?php echo $data['margem']; ?>%</div></div>
        </div>

        <?php if($view==='todos' && !empty($data['by_company'])): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px" class="bp-stagger">
            <?php foreach($data['by_company'] as $bc):
                $cname=$company_names[(int)$bc->company_id]??"Empresa {$bc->company_id}";
                $colors=[1=>'var(--red)',2=>'var(--blue)',3=>'var(--accent)'];
                $cc=$colors[(int)$bc->company_id]??'var(--text2)';
                $lucro_c=(float)$bc->receita-(float)$bc->despesa;
                $pct=$data['receita']>0?round((float)$bc->receita/$data['receita']*100):0;
            ?>
            <div class="bp-card">
                <div style="font-weight:700;font-size:.9rem;margin-bottom:10px;color:<?php echo $cc; ?>"><?php echo esc_html($cname); ?></div>
                <div style="font-size:1.2rem;font-weight:800;color:var(--green);margin-bottom:4px"><?php echo $this->money($bc->receita); ?></div>
                <div style="display:flex;gap:16px;font-size:.8rem;color:var(--text3);margin-bottom:8px">
                    <span>Despesas: <?php echo $this->money($bc->despesa); ?></span>
                    <span>Lucro: <strong style="color:<?php echo $lucro_c>=0?'var(--green)':'var(--red)'; ?>"><?php echo $this->money($lucro_c); ?></strong></span>
                </div>
                <div style="height:4px;background:var(--border);border-radius:2px">
                    <div style="height:4px;background:<?php echo $cc; ?>;border-radius:2px;width:<?php echo $pct; ?>%"></div>
                </div>
                <div style="font-size:.7rem;color:var(--text3);margin-top:4px"><?php echo $pct; ?>% do total</div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if(!empty($data['by_day'])): ?>
        <div class="bp-card bp-animate-in" style="margin-bottom:16px">
            <div class="bp-card-header"><div class="bp-card-title">Receita × Despesa por Dia</div></div>
            <canvas id="bpGanhosChart" style="max-height:200px"></canvas>
        </div>
        <?php endif; ?>

        <?php if(!empty($data['by_method'])): ?>
        <div class="bp-card bp-animate-in">
            <div class="bp-card-header"><div class="bp-card-title">💳 Por Forma de Pagamento</div></div>
            <?php $max_m=max(array_map(function($m) { return (float)$m->total; }, $data['by_method']));
            $mlabels=bp_get_payment_methods();
            foreach($data['by_method'] as $m): $pct=$max_m>0?round((float)$m->total/$max_m*100):0; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:.84rem">
                <span style="color:var(--text2)"><?php echo esc_html($mlabels[$m->payment_method]??$m->payment_method); ?></span>
                <div style="flex:1;margin:0 16px;height:4px;background:var(--border);border-radius:2px">
                    <div style="height:4px;background:var(--accent);border-radius:2px;width:<?php echo $pct; ?>%"></div>
                </div>
                <strong><?php echo $this->money($m->total); ?></strong>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
    }


}
