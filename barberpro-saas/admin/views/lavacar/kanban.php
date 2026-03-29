<?php
/**
 * View – Kanban Lava-Car
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_manage_bookings' ) ) wp_die( 'Sem permissão.' );

$company_id  = BarberPro_Modules::company_id( 'lavacar' );
$filter_date = isset( $_GET['kanban_date'] ) ? sanitize_text_field( $_GET['kanban_date'] ) : '';

$statuses = [
    'agendado'       => [ 'label' => '📋 Agendado',      'color' => '#3b82f6' ],
    'confirmado'     => [ 'label' => '✅ Confirmado',     'color' => '#8b5cf6' ],
    'em_atendimento' => [ 'label' => '🚿 Lavando',        'color' => '#f59e0b' ],
    'finalizado'     => [ 'label' => '✨ Pronto',         'color' => '#10b981' ],
    'cancelado'      => [ 'label' => '❌ Cancelado',      'color' => '#ef4444' ],
];

$delivery_labels = [
    'cliente_traz'                 => [ '🏠', 'Trouxe o carro',    '#f3f4f6', '#374151' ],
    'empresa_busca_entrega'        => [ '🚐', 'Busca e Entrega',   '#dbeafe', '#1e40af' ],
    'empresa_busca_cliente_retira' => [ '📦', 'Empresa Buscou',   '#e0f2fe', '#0369a1' ],
    'cliente_leva_empresa_entrega' => [ '🏁', 'Empresa Entrega',  '#fef9c3', '#854d0e' ],
];

function lavacar_wa_link( string $phone, string $msg = '' ): string {
    $digits = preg_replace( '/\D/', '', $phone );
    if ( strlen($digits) <= 11 ) $digits = '55' . $digits;
    $url = 'https://wa.me/' . $digits;
    if ( $msg ) $url .= '?text=' . rawurlencode($msg);
    return $url;
}
?>
<div class="wrap barberpro-admin">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <h1 style="margin:0">🚗 Kanban – Lava-Car</h1>
        <form method="get" style="display:flex;align-items:center;gap:8px">
            <input type="hidden" name="page" value="barberpro_lavacar_kanban">
            <input type="date" name="kanban_date" value="<?php echo esc_attr($filter_date); ?>"
                   style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px">
            <button type="submit" class="button">🔍</button>
            <?php if ($filter_date): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=barberpro_lavacar_kanban')); ?>" class="button">✕</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="barberpro-kanban" id="barberproKanban">
        <?php foreach ( $statuses as $sk => $meta ) :
            $args = [ 'status' => $sk, 'company_id' => $company_id ];
            if ($filter_date) $args['date'] = $filter_date;
            $bookings = BarberPro_Database::get_bookings( $args );
        ?>
        <div class="kanban-column" data-status="<?php echo esc_attr($sk); ?>">
            <div class="kanban-column-header" style="border-top:3px solid <?php echo esc_attr($meta['color']); ?>">
                <h3><?php echo esc_html($meta['label']); ?></h3>
                <span class="kanban-count"><?php echo count($bookings); ?></span>
            </div>
            <div class="kanban-cards" data-status="<?php echo esc_attr($sk); ?>">
                <?php foreach ( $bookings as $b ) :
                    $has_vehicle = ! empty($b->vehicle_plate) || ! empty($b->vehicle_model);
                    $wa_msg  = "Olá {$b->client_name}! Seu veículo " . ($b->vehicle_plate ?? '') . " está pronto! Serviço: {$b->service_name}. Cód: {$b->booking_code}";
                    $wa_link = lavacar_wa_link( $b->client_phone, $wa_msg );
                    $dl      = ! empty($b->delivery_type) ? ($delivery_labels[$b->delivery_type] ?? null) : null;
                    $total   = ($b->amount_variant ?? $b->amount_total) + ($b->delivery_fee ?? 0);
                ?>
                <div class="kanban-card lc-card" data-id="<?php echo esc_attr($b->id); ?>" draggable="true">
                    <div class="kanban-card-time">📅 <?php echo esc_html(date_i18n('d/m/Y', strtotime($b->booking_date))); ?> 🕐 <?php echo esc_html(substr($b->booking_time,0,5)); ?></div>

                    <!-- Cliente -->
                    <div class="kanban-card-client"><strong>👤 <?php echo esc_html($b->client_name); ?></strong></div>

                    <!-- Serviço + variante -->
                    <div class="kanban-card-service">
                        🚿 <?php echo esc_html($b->service_name); ?>
                        <?php if (!empty($b->service_variant)): ?>
                        <span class="lc-badge lc-badge-blue"><?php echo esc_html($b->service_variant); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Veículo -->
                    <?php if ($has_vehicle): ?>
                    <div class="lc-vehicle">
                        🚗
                        <?php if ($b->vehicle_plate): ?><strong><?php echo esc_html($b->vehicle_plate); ?></strong> <?php endif; ?>
                        <?php if ($b->vehicle_model): echo esc_html($b->vehicle_model); endif; ?>
                        <?php if ($b->vehicle_color): echo ' · ' . esc_html($b->vehicle_color); endif; ?>
                        <?php if ($b->vehicle_size): ?><span class="lc-size"><?php echo esc_html(strtoupper($b->vehicle_size)); ?></span><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Entrega -->
                    <?php if ($dl): ?>
                    <div class="lc-delivery" style="background:<?php echo esc_attr($dl[2]); ?>;color:<?php echo esc_attr($dl[3]); ?>">
                        <?php echo $dl[0] . ' ' . esc_html($dl[1]); ?>
                        <?php if (!empty($b->delivery_fee) && $b->delivery_fee > 0): ?>
                        <span style="opacity:.75">· +R$ <?php echo esc_html(number_format($b->delivery_fee,2,',','.')); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($b->delivery_address)): ?>
                    <div style="font-size:.72rem;color:#6b7280;margin-bottom:3px">📍 <?php echo esc_html(mb_substr($b->delivery_address,0,55)); ?></div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- Total -->
                    <?php if ($total > 0): ?>
                    <div style="font-size:.8rem;font-weight:700;color:#10b981;margin:4px 0">💰 R$ <?php echo esc_html(number_format($total,2,',','.')); ?></div>
                    <?php endif; ?>

                    <!-- Notas -->
                    <?php if (!empty($b->notes)): ?>
                    <div class="kanban-card-notes">💬 <?php echo esc_html(mb_substr($b->notes,0,50)); ?></div>
                    <?php endif; ?>

                    <!-- Rodapé -->
                    <div class="kanban-card-footer">
                        <code class="kanban-card-code">#<?php echo esc_html($b->booking_code); ?></code>
                        <a href="<?php echo esc_url($wa_link); ?>" target="_blank" rel="noopener" class="kanban-wa-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            <?php echo esc_html($b->client_phone); ?>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($bookings)): ?>
                <div class="kanban-empty">Nenhum veículo</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<style>
.lc-card{border-left:3px solid #3b82f6}
.lc-vehicle{font-size:.78rem;color:#92400e;background:#fef3c7;border-radius:5px;padding:4px 8px;margin:4px 0;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.lc-size{background:#1a1a2e;color:#fff;border-radius:3px;padding:1px 5px;font-size:.67rem;font-weight:700}
.lc-delivery{font-size:.75rem;font-weight:600;border-radius:5px;padding:4px 8px;margin:4px 0;display:flex;align-items:center;gap:4px}
.lc-badge{border-radius:4px;padding:1px 6px;font-size:.7rem;margin-left:4px}
.lc-badge-blue{background:#e0f2fe;color:#0369a1}
.kanban-card-footer{display:flex;align-items:center;justify-content:space-between;margin-top:8px;padding-top:8px;border-top:1px solid #f3f4f6;gap:6px;flex-wrap:wrap}
.kanban-card-code{font-size:.68rem;color:#9ca3af;font-family:monospace}
.kanban-card-notes{font-size:.75rem;color:#6b7280;background:#f8f9fa;border-radius:4px;padding:4px 8px;margin:4px 0;font-style:italic}
.kanban-wa-btn{display:inline-flex;align-items:center;gap:4px;background:#25d366;color:#fff!important;text-decoration:none!important;padding:4px 9px;border-radius:20px;font-size:.73rem;font-weight:600;transition:background .15s;white-space:nowrap}
.kanban-wa-btn:hover{background:#128c7e}
.kanban-empty{text-align:center;color:#9ca3af;padding:24px 12px;font-size:.82rem}
.kanban-column{min-width:270px;width:270px}
</style>
