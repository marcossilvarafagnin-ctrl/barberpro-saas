<?php
/**
 * View – Financeiro Consolidado (Barbearia + Lava-Car)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_view_finance' ) ) wp_die( 'Sem permissão.' );

$date_from = sanitize_text_field( $_GET['date_from'] ?? date('Y-m-01') );
$date_to   = sanitize_text_field( $_GET['date_to']   ?? date('Y-m-d') );

// Carrega dados de cada módulo ativo
$modules_data = [];
$totals = [ 'receita' => 0, 'despesa' => 0, 'resultado' => 0, 'caixa' => 0 ];

foreach ( BarberPro_Modules::active_list() as $key => $meta ) {
    $cid  = BarberPro_Modules::company_id( $key );
    $dash = BarberPro_Finance::get_dashboard( $cid );
    $modules_data[$key] = [ 'meta' => $meta, 'dash' => $dash, 'cid' => $cid ];
    $totals['receita']   += $dash['monthly_receita'];
    $totals['despesa']   += $dash['monthly_despesa'];
    $totals['resultado'] += $dash['resultado_mensal'];
    $totals['caixa']     += $dash['caixa_saldo'];
}

function con_money( $v ): string {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}
?>
<div class="wrap barberpro-admin">
    <h1>📊 Financeiro Consolidado</h1>
    <p style="color:#6b7280;margin-top:-6px">Visão unificada de todos os módulos ativos • <?php echo esc_html(date('d/m/Y', strtotime($date_from))); ?> até <?php echo esc_html(date('d/m/Y', strtotime($date_to))); ?></p>

    <!-- Filtro de período -->
    <form method="get" style="display:flex;gap:10px;align-items:center;margin-bottom:20px;flex-wrap:wrap">
        <input type="hidden" name="page" value="barberpro_consolidado">
        <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px">
        <input type="date" name="date_to"   value="<?php echo esc_attr($date_to); ?>"   style="padding:7px 10px;border:1px solid #d1d5db;border-radius:6px">
        <button type="submit" class="button button-primary">🔍 Filtrar</button>
    </form>

    <!-- KPIs CONSOLIDADOS -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:24px">
        <?php
        $kpis = [
            ['📈','Receita Total',    $totals['receita'],   '#10b981'],
            ['📉','Despesas Total',   $totals['despesa'],   '#ef4444'],
            ['🎯','Resultado Total',  $totals['resultado'], $totals['resultado']>=0?'#10b981':'#ef4444'],
            ['🏦','Caixa Combinado',  $totals['caixa'],     '#3b82f6'],
        ];
        foreach ($kpis as [$icon,$label,$val,$color]):
        ?>
        <div style="background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.05);border-left:4px solid <?php echo esc_attr($color); ?>">
            <div style="font-size:1.4rem;margin-bottom:6px"><?php echo $icon; ?></div>
            <div style="font-size:.78rem;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px"><?php echo esc_html($label); ?></div>
            <div style="font-size:1.35rem;font-weight:700;color:#1a1a2e"><?php echo esc_html(con_money($val)); ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- COMPARATIVO POR MÓDULO -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:20px;margin-bottom:24px">
        <?php foreach ($modules_data as $key => $mdata):
            $d    = $mdata['dash'];
            $meta = $mdata['meta'];
            $pct  = $totals['receita'] > 0 ? round($d['monthly_receita'] / $totals['receita'] * 100) : 0;
        ?>
        <div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.06);border-top:4px solid <?php echo esc_attr($meta['color']); ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                <h2 style="margin:0;font-size:1rem"><?php echo esc_html($meta['label']); ?></h2>
                <span style="background:<?php echo esc_attr($meta['color']); ?>20;color:<?php echo esc_attr($meta['color']); ?>;border-radius:20px;padding:3px 10px;font-size:.78rem;font-weight:700">
                    <?php echo $pct; ?>% da receita total
                </span>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:.88rem">
                <?php
                $rows = [
                    ['Receita do mês',  $d['monthly_receita'],  '#10b981'],
                    ['Despesas do mês', $d['monthly_despesa'],  '#ef4444'],
                    ['Resultado',       $d['resultado_mensal'], $d['resultado_mensal']>=0?'#10b981':'#ef4444'],
                    ['Saldo em caixa',  $d['caixa_saldo'],      '#3b82f6'],
                ];
                foreach ($rows as [$rl,$rv,$rc]):
                ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:8px 0;color:#6b7280"><?php echo esc_html($rl); ?></td>
                    <td style="padding:8px 0;text-align:right;font-weight:700;color:<?php echo esc_attr($rc); ?>"><?php echo esc_html(con_money($rv)); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php if (!empty($d['vencidos_a_pagar']) && $d['vencidos_a_pagar'] > 0): ?>
            <div style="background:#fee2e2;border-radius:6px;padding:8px 12px;margin-top:10px;font-size:.82rem;color:#991b1b">
                ⚠️ Vencidos a pagar: <strong><?php echo esc_html(con_money($d['vencidos_a_pagar'])); ?></strong>
            </div>
            <?php endif; ?>
            <div style="margin-top:14px">
                <a href="<?php echo esc_url(admin_url("admin.php?page=barberpro_{$key}_finance")); ?>"
                   style="background:<?php echo esc_attr($meta['color']); ?>;color:#fff;padding:7px 14px;border-radius:7px;text-decoration:none;font-size:.82rem;font-weight:600">
                    → Financeiro completo
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- GRÁFICO COMPARATIVO -->
    <?php if (count($modules_data) >= 2): ?>
    <div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.06);margin-bottom:24px">
        <h2 style="margin:0 0 16px;font-size:1rem">📈 Receita vs Despesa – Comparativo 12 Meses</h2>
        <canvas id="bpConsolidadoChart" height="100"></canvas>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            var ctx = document.getElementById('bpConsolidadoChart');
            if (!ctx || typeof Chart === 'undefined') return;
            var datasets = [];
            var colors   = { barbearia: '#e94560', lavacar: '#3b82f6' };
            <?php foreach ($modules_data as $key => $mdata): ?>
            var data_<?php echo $key; ?> = <?php echo wp_json_encode($mdata['dash']['chart_12m'] ?? []); ?>;
            if (data_<?php echo $key; ?>.length) {
                datasets.push({
                    label: '<?php echo esc_js($mdata['meta']['label']); ?> – Receita',
                    data: data_<?php echo $key; ?>.map(function(r){ return parseFloat(r.receita||0); }),
                    borderColor: colors['<?php echo $key; ?>'],
                    backgroundColor: colors['<?php echo $key; ?>'] + '20',
                    fill: false, tension: .4
                });
            }
            <?php endforeach; ?>
            var labels = <?php
                $first = reset($modules_data);
                echo wp_json_encode(array_map(function($r) { return $r->mes ?? ''; }, $first['dash']['chart_12m'] ?? []));
            ?>;
            new Chart(ctx, {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    plugins: { legend: { position: 'top' } },
                    scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return 'R$ ' + v.toLocaleString('pt-BR'); } } } }
                }
            });
        });
        </script>
    </div>
    <?php endif; ?>

    <!-- TOP DESPESAS CONSOLIDADAS -->
    <div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.06)">
        <h2 style="margin:0 0 16px;font-size:1rem">🔴 Top Despesas Consolidadas do Mês</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:20px">
            <?php foreach ($modules_data as $key => $mdata): ?>
            <div>
                <h3 style="font-size:.88rem;margin:0 0 10px;color:<?php echo esc_attr($mdata['meta']['color']); ?>">
                    <?php echo esc_html($mdata['meta']['label']); ?>
                </h3>
                <table style="width:100%;border-collapse:collapse;font-size:.85rem">
                    <?php foreach (array_slice($mdata['dash']['top_expenses'] ?? [], 0, 6) as $e): ?>
                    <tr style="border-bottom:1px solid #f3f4f6">
                        <td style="padding:6px 0">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($e->color); ?>;margin-right:6px"></span>
                            <?php echo esc_html($e->name); ?>
                        </td>
                        <td style="padding:6px 0;text-align:right;font-weight:600;color:#ef4444"><?php echo esc_html(con_money($e->total)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($mdata['dash']['top_expenses'])): ?>
                    <tr><td colspan="2" style="color:#9ca3af;padding:8px 0">Nenhuma despesa.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
