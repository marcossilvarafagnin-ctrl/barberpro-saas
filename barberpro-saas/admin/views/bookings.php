<?php
/**
 * View – Lista de Agendamentos
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_manage_bookings' ) ) wp_die( 'Sem permissão.' );

$filter_date   = isset( $_GET['filter_date'] )   ? sanitize_text_field( $_GET['filter_date'] )   : '';
$filter_status = isset( $_GET['filter_status'] ) ? sanitize_key( $_GET['filter_status'] )        : '';
$filter_pro    = isset( $_GET['filter_pro'] )    ? absint( $_GET['filter_pro'] )                  : 0;

$bookings      = BarberPro_Database::get_bookings( [
    'date'   => $filter_date,
    'status' => $filter_status,
    'pro_id' => $filter_pro,
] );
$professionals = BarberPro_Professionals::get_all();
?>
<div class="wrap barberpro-admin">
    <h1><?php esc_html_e( 'Agendamentos', 'barberpro-saas' ); ?></h1>

    <!-- Filtros -->
    <form method="get" class="barberpro-filters">
        <input type="hidden" name="page" value="barberpro_bookings">
        <input type="date" name="filter_date" value="<?php echo esc_attr( $filter_date ); ?>">
        <select name="filter_status">
            <option value=""><?php esc_html_e( 'Todos os status', 'barberpro-saas' ); ?></option>
            <?php foreach ( [ 'agendado','confirmado','em_atendimento','finalizado','cancelado' ] as $s ) : ?>
            <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $filter_status, $s ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="filter_pro">
            <option value=""><?php esc_html_e( 'Todos profissionais', 'barberpro-saas' ); ?></option>
            <?php foreach ( $professionals as $p ) : ?>
            <option value="<?php echo esc_attr( $p->id ); ?>" <?php selected( $filter_pro, $p->id ); ?>><?php echo esc_html( $p->name ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'barberpro-saas' ); ?></button>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th><?php esc_html_e( 'Código', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Data/Hora', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Cliente', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Telefone', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Serviço', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Profissional', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Pagamento', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Status', 'barberpro-saas' ); ?></th>
            <th><?php esc_html_e( 'Ações', 'barberpro-saas' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $bookings as $b ) : ?>
        <tr>
            <td><code><?php echo esc_html( $b->booking_code ); ?></code></td>
            <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $b->booking_date ) ) . ' ' . substr( $b->booking_time, 0, 5 ) ); ?></td>
            <td><?php echo esc_html( $b->client_name ); ?></td>
            <td><?php echo esc_html( $b->client_phone ); ?></td>
            <td><?php echo esc_html( $b->service_name ); ?></td>
            <td><?php echo esc_html( $b->professional_name ); ?></td>
            <td><?php echo esc_html( ucfirst( $b->payment_method ) ); ?></td>
            <td>
                <select class="booking-status-select" data-id="<?php echo esc_attr( $b->id ); ?>">
                    <?php foreach ( [ 'agendado','confirmado','em_atendimento','finalizado','cancelado','recusado' ] as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $b->status, $s ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <span class="barberpro-badge status-<?php echo esc_attr( $b->status ); ?>">
                    <?php echo esc_html( $b->status ); ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if ( empty( $bookings ) ) : ?>
        <tr><td colspan="9"><?php esc_html_e( 'Nenhum agendamento encontrado.', 'barberpro-saas' ); ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
