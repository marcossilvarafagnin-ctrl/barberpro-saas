<?php
/**
 * Template – Painel do Cliente
 * Chamado via shortcode [barberpro_painel_cliente]
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user        = wp_get_current_user();
$cancel_hours = (int) BarberPro_Database::get_setting( 'cancellation_hours', 2 );
$now         = current_time( 'timestamp' );
?>
<div class="barberpro-painel-cliente">
    <div class="bp-client-header">
        <div class="bp-client-avatar"><?php echo esc_html( strtoupper( substr( $user->display_name, 0, 1 ) ) ); ?></div>
        <div>
            <h2><?php echo esc_html( sprintf( __( 'Olá, %s!', 'barberpro-saas' ), $user->display_name ) ); ?></h2>
            <p><?php echo esc_html( $user->user_email ); ?></p>
        </div>
    </div>

    <div class="bp-client-tabs">
        <button class="bp-tab active" data-target="proximos"><?php esc_html_e( 'Próximos', 'barberpro-saas' ); ?></button>
        <button class="bp-tab" data-target="historico"><?php esc_html_e( 'Histórico', 'barberpro-saas' ); ?></button>
    </div>

    <!-- Próximos Agendamentos -->
    <div class="bp-tab-content active" id="proximos">
        <?php
        $upcoming = array_filter( $bookings, function ( $b ) use ( $now ) {
            return strtotime( $b->booking_date . ' ' . $b->booking_time ) >= $now
                && ! in_array( $b->status, [ 'cancelado', 'finalizado' ], true );
        } );
        ?>
        <?php if ( empty( $upcoming ) ) : ?>
            <div class="bp-empty">
                <p><?php esc_html_e( 'Nenhum agendamento futuro.', 'barberpro-saas' ); ?></p>
                <a href="#" class="bp-btn bp-btn-primary"><?php esc_html_e( 'Agendar Agora', 'barberpro-saas' ); ?></a>
            </div>
        <?php else : ?>
            <?php foreach ( $upcoming as $b ) :
                $booking_ts   = strtotime( $b->booking_date . ' ' . $b->booking_time );
                $can_cancel   = $booking_ts - $now > $cancel_hours * 3600;
            ?>
            <div class="bp-booking-card status-<?php echo esc_attr( $b->status ); ?>">
                <div class="bp-booking-header">
                    <span class="bp-booking-date">
                        📅 <?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $b->booking_date ) ) ); ?>
                        🕐 <?php echo esc_html( substr( $b->booking_time, 0, 5 ) ); ?>
                    </span>
                    <span class="bp-badge status-<?php echo esc_attr( $b->status ); ?>">
                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $b->status ) ) ); ?>
                    </span>
                </div>
                <div class="bp-booking-body">
                    <p><strong><?php echo esc_html( $b->service_name ); ?></strong></p>
                    <p>✂️ <?php echo esc_html( $b->professional_name ); ?></p>
                    <p><small><?php esc_html_e( 'Código:', 'barberpro-saas' ); ?> <code><?php echo esc_html( $b->booking_code ); ?></code></small></p>
                </div>
                <?php if ( $can_cancel ) : ?>
                <div class="bp-booking-actions">
                    <button class="bp-btn bp-btn-danger bp-cancel-booking"
                            data-id="<?php echo esc_attr( $b->id ); ?>"
                            data-nonce="<?php echo esc_attr( wp_create_nonce( 'barberpro_booking' ) ); ?>">
                        <?php esc_html_e( 'Cancelar', 'barberpro-saas' ); ?>
                    </button>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Histórico -->
    <div class="bp-tab-content" id="historico" style="display:none">
        <?php
        $past = array_filter( $bookings, function ( $b ) use ( $now ) {
            return strtotime( $b->booking_date . ' ' . $b->booking_time ) < $now
                || in_array( $b->status, [ 'cancelado', 'finalizado' ], true );
        } );
        ?>
        <?php if ( empty( $past ) ) : ?>
            <p><?php esc_html_e( 'Nenhum histórico encontrado.', 'barberpro-saas' ); ?></p>
        <?php else : ?>
            <table class="bp-history-table">
                <thead><tr>
                    <th><?php esc_html_e( 'Data', 'barberpro-saas' ); ?></th>
                    <th><?php esc_html_e( 'Serviço', 'barberpro-saas' ); ?></th>
                    <th><?php esc_html_e( 'Profissional', 'barberpro-saas' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'barberpro-saas' ); ?></th>
                    <th><?php esc_html_e( 'Avaliação', 'barberpro-saas' ); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $past as $b ) : ?>
                <tr>
                    <td><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $b->booking_date ) ) ); ?> <?php echo esc_html( substr( $b->booking_time, 0, 5 ) ); ?></td>
                    <td><?php echo esc_html( $b->service_name ); ?></td>
                    <td><?php echo esc_html( $b->professional_name ); ?></td>
                    <td><span class="bp-badge status-<?php echo esc_attr( $b->status ); ?>"><?php echo esc_html( $b->status ); ?></span></td>
                    <td>
                        <?php if ( $b->status === 'finalizado' ) : ?>
                        <div class="bp-stars" data-booking="<?php echo esc_attr( $b->id ); ?>">
                            <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <span class="bp-star" data-rating="<?php echo esc_attr( $i ); ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
