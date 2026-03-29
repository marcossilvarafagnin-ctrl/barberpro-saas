<?php
/**
 * View – Dashboard principal
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_manage_bookings' ) ) wp_die( 'Sem permissão.' );

// Método correto da v2
$dash           = BarberPro_Finance::get_dashboard();
$today          = current_time( 'd/m/Y' );
$bookings_today = BarberPro_Database::get_bookings( [ 'date' => current_time( 'Y-m-d' ) ] );

function bp_fmt( $v ): string {
    return 'R$ ' . number_format( (float) $v, 2, ',', '.' );
}
?>
<div class="wrap barberpro-admin">
    <h1>📊 Dashboard – BarberPro</h1>
    <p style="color:#6b7280;margin-top:-8px"><?php echo esc_html( $today ); ?></p>

    <!-- KPI Cards -->
    <div class="barberpro-kpis">
        <div class="kpi-card">
            <span class="kpi-icon dashicons dashicons-chart-bar"></span>
            <h3>Receita Hoje</h3>
            <p class="kpi-value"><?php echo esc_html( bp_fmt( $dash['today_revenue'] ) ); ?></p>
        </div>
        <div class="kpi-card">
            <span class="kpi-icon dashicons dashicons-money-alt"></span>
            <h3>Receita Mensal</h3>
            <p class="kpi-value"><?php echo esc_html( bp_fmt( $dash['monthly_receita'] ) ); ?></p>
        </div>
        <div class="kpi-card kpi-red">
            <span class="kpi-icon dashicons dashicons-minus"></span>
            <h3>Despesas Mês</h3>
            <p class="kpi-value"><?php echo esc_html( bp_fmt( $dash['monthly_despesa'] ) ); ?></p>
        </div>
        <div class="kpi-card <?php echo $dash['resultado_mensal'] >= 0 ? 'kpi-green' : 'kpi-red'; ?>">
            <span class="kpi-icon dashicons dashicons-yes-alt"></span>
            <h3>Resultado Mês</h3>
            <p class="kpi-value"><?php echo esc_html( bp_fmt( $dash['resultado_mensal'] ) ); ?></p>
            <small style="color:#6b7280">Margem: <?php echo esc_html( $dash['margem_pct'] ); ?>%</small>
        </div>
        <div class="kpi-card">
            <span class="kpi-icon dashicons dashicons-bank"></span>
            <h3>Saldo em Caixa</h3>
            <p class="kpi-value"><?php echo esc_html( bp_fmt( $dash['caixa_saldo'] ) ); ?></p>
        </div>
        <div class="kpi-card">
            <span class="kpi-icon dashicons dashicons-calendar"></span>
            <h3>Agendamentos Hoje</h3>
            <p class="kpi-value"><?php echo esc_html( count( $bookings_today ) ); ?></p>
        </div>
    </div>

    <?php if ( $dash['vencidos_a_pagar'] > 0 ) : ?>
    <div class="notice notice-error inline" style="border-radius:8px;padding:10px 16px">
        ⚠️ Você tem <strong><?php echo esc_html( bp_fmt( $dash['vencidos_a_pagar'] ) ); ?></strong> em contas a pagar vencidas.
        <a href="<?php echo esc_url( admin_url('admin.php?page=barberpro_finance&fin_tab=contas') ); ?>">Ver contas →</a>
    </div>
    <?php endif; ?>

    <!-- Gráfico 12 meses -->
    <div class="barberpro-chart-wrap">
        <h2>📈 Últimos 12 Meses</h2>
        <canvas id="barberproChart" height="120"></canvas>
    </div>
    <script>
    var barberproChartData = <?php echo wp_json_encode( $dash['chart_12m'] ); ?>;
    </script>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">

        <!-- Top despesas -->
        <div class="barberpro-table-wrap">
            <h2>🔴 Top Despesas do Mês</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Categoria</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ( $dash['top_expenses'] as $e ) : ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($e->color); ?>;margin-right:6px"></span>
                        <?php echo esc_html( $e->name ); ?>
                    </td>
                    <td><?php echo esc_html( bp_fmt( $e->total ) ); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( empty( $dash['top_expenses'] ) ) : ?>
                <tr><td colspan="2">Nenhuma despesa registrada.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Comissões pendentes -->
        <div class="barberpro-table-wrap">
            <h2>👨 Comissões Pendentes</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th>Profissional</th><th>Atendimentos</th><th>A Pagar</th></tr></thead>
                <tbody>
                <?php foreach ( $dash['commissions_pending'] as $c ) : ?>
                <tr>
                    <td><?php echo esc_html( $c->name ); ?></td>
                    <td><?php echo esc_html( $c->qty ); ?></td>
                    <td><strong><?php echo esc_html( bp_fmt( $c->total ) ); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <?php if ( empty( $dash['commissions_pending'] ) ) : ?>
                <tr><td colspan="3" style="color:#6b7280">Nenhuma comissão pendente. ✅</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Agenda de hoje -->
    <div class="barberpro-table-wrap" style="margin-top:20px">
        <h2>📅 Agenda de Hoje</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th style="width:80px">Horário</th>
                <th>Cliente</th>
                <th>Serviço</th>
                <th>Profissional</th>
                <th style="width:120px">Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $bookings_today as $b ) : ?>
            <tr>
                <td><?php echo esc_html( substr( $b->booking_time, 0, 5 ) ); ?></td>
                <td><?php echo esc_html( $b->client_name ); ?></td>
                <td><?php echo esc_html( $b->service_name ); ?></td>
                <td><?php echo esc_html( $b->professional_name ); ?></td>
                <td>
                    <span class="barberpro-badge status-<?php echo esc_attr( $b->status ); ?>">
                        <?php echo esc_html( $b->status ); ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty( $bookings_today ) ) : ?>
            <tr><td colspan="5" style="text-align:center;color:#6b7280;padding:20px">Nenhum agendamento para hoje.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
