<?php
/**
 * Template – Wizard de agendamento público
 * Shortcode: [barberpro_agendamento]
 * Suporta: barbearia (padrão) e lava-car (com campos de veículo + variantes de preço)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Detecta se há algum serviço com variantes (indica que é lava-car ou similar)
$has_variants = false;
foreach ( $services as $svc ) {
    $variants = BarberPro_Database::get_service_variants( (int) $svc->id );
    if ( ! empty( $variants ) ) { $has_variants = true; break; }
}

// Passa para JS se este agendamento usa campos de veículo
$mode = $has_variants ? 'vehicle' : 'default';

// Carrega opções de coleta/entrega do admin
$delivery_options = [];
$delivery_map = [
    'cliente_traz'             => ['label'=>'🏠 Trago e busco o carro',       'icon'=>'🏠','fee'=>0,                                                    'needs_address'=>false],
    'empresa_busca_entrega'    => ['label'=>'🚐 Buscar e entregar (+ taxa)',   'icon'=>'🚐','fee'=>(float)BarberPro_Database::get_setting('delivery_fee_busca_entrega',0),  'needs_address'=>true],
    'empresa_busca_cliente_retira' => ['label'=>'📦 Só buscar (eu retiro)',    'icon'=>'📦','fee'=>(float)BarberPro_Database::get_setting('delivery_fee_busca_retira',0),   'needs_address'=>true],
    'cliente_leva_empresa_entrega' => ['label'=>'🏁 Eu levo, entregam pra mim','icon'=>'🏁','fee'=>(float)BarberPro_Database::get_setting('delivery_fee_leva_entrega',0),  'needs_address'=>true],
];
$delivery_enabled = [
    'cliente_traz'             => (bool) BarberPro_Database::get_setting('delivery_opt_cliente_traz','1'),
    'empresa_busca_entrega'    => (bool) BarberPro_Database::get_setting('delivery_opt_busca_entrega','1'),
    'empresa_busca_cliente_retira' => (bool) BarberPro_Database::get_setting('delivery_opt_busca_retira','1'),
    'cliente_leva_empresa_entrega' => (bool) BarberPro_Database::get_setting('delivery_opt_leva_entrega','1'),
];
foreach ($delivery_map as $k => $v) {
    if (!empty($delivery_enabled[$k])) {
        $delivery_options[$k] = $v;
    }
}
$delivery_json = wp_json_encode($delivery_options);
$delivery_max_km = BarberPro_Database::get_setting('delivery_max_km', 10);
$delivery_msg    = str_replace('{raio}', $delivery_max_km, BarberPro_Database::get_setting('delivery_info_msg',''));
?>
<div id="barberproBooking" class="barberpro-booking"
     data-max-days="<?php echo esc_attr( $max_days ); ?>"
     data-mode="<?php echo esc_attr( $mode ); ?>"
     data-company="<?php echo esc_attr( $cid ?? 1 ); ?>"
     data-delivery='<?php echo $delivery_json; ?>'
     data-require-address="<?php echo esc_attr( BarberPro_Database::get_setting('delivery_require_full_address','1') ); ?>"
     data-ajaxurl="<?php echo esc_url( admin_url('admin-ajax.php') ); ?>"
     data-nonce="<?php echo esc_attr( wp_create_nonce('barberpro_booking') ); ?>">

    <!-- Progress Bar -->
    <div class="bp-progress">
        <?php
        $steps = $has_variants ? [
            1 => 'Serviço',
            2 => 'Profissional',
            3 => 'Data',
            4 => 'Horário',
            5 => 'Coleta/Entrega',
            6 => 'Seus Dados',
            7 => 'Pagamento',
            8 => 'Confirmação',
        ] : [
            1 => 'Serviço',
            2 => 'Profissional',
            3 => 'Data',
            4 => 'Horário',
            5 => 'Seus Dados',
            6 => 'Pagamento',
            7 => 'Confirmação',
        ];
        foreach ( $steps as $num => $label ) :
        ?>
        <div class="bp-step" data-step="<?php echo esc_attr($num); ?>">
            <div class="bp-step-dot"><?php echo esc_html($num); ?></div>
            <span><?php echo esc_html($label); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ======================================================
         ETAPA 1 – Escolher Serviço
    ====================================================== -->
    <div class="bp-panel" data-panel="1">
        <h2>1. Escolha o Serviço</h2>
        <div class="bp-service-grid">
            <?php foreach ( $services as $service ) :
                $variants = BarberPro_Database::get_service_variants( (int) $service->id );
                $variants_json = wp_json_encode( array_map( function($v) { return [
                    'id'       => $v->id,
                    'name'     => $v->name,
                    'size_key' => $v->size_key,
                    'price'    => (float) $v->price,
                    'duration' => $v->duration,
                ]; }, $variants ) );
            ?>
            <div class="bp-service-card"
                 data-id="<?php echo esc_attr($service->id); ?>"
                 data-duration="<?php echo esc_attr($service->duration); ?>"
                 data-price="<?php echo esc_attr($service->price); ?>"
                 data-name="<?php echo esc_attr($service->name); ?>"
                 data-variants="<?php echo esc_attr($variants_json); ?>">
                <?php if ( $service->photo ) : ?>
                <img src="<?php echo esc_url($service->photo); ?>" alt="<?php echo esc_attr($service->name); ?>">
                <?php else : ?>
                <div class="bp-service-icon">✂️</div>
                <?php endif; ?>
                <h3><?php echo esc_html($service->name); ?></h3>
                <?php if ($service->description) : ?>
                <p class="bp-service-desc"><?php echo esc_html($service->description); ?></p>
                <?php endif; ?>
                <div class="bp-service-meta">
                    <?php if ( ! empty($variants) ) : ?>
                    <span class="bp-price">
                        A partir de R$ <?php echo esc_html( number_format( min(array_column($variants, 'price')), 2, ',', '.' ) ); ?>
                    </span>
                    <span class="bp-variants-hint"><?php echo count($variants); ?> opções de porte</span>
                    <?php else : ?>
                    <span class="bp-price">R$ <?php echo esc_html( number_format($service->price, 2, ',', '.')); ?></span>
                    <?php endif; ?>
                    <span class="bp-duration"><?php echo esc_html($service->duration); ?> min</span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if ( empty($services) ) : ?>
            <p>Nenhum serviço disponível no momento.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ======================================================
         ETAPA 1b – Variante de porte (lava-car)
         Aparece dinamicamente via JS quando serviço tem variantes
    ====================================================== -->
    <div class="bp-panel" data-panel="1b" style="display:none">
        <h2>1b. Escolha o Porte do Veículo</h2>
        <p class="bp-hint">O preço e duração variam conforme o tamanho do veículo.</p>
        <div class="bp-variant-grid" id="bpVariantGrid">
            <!-- Preenchido via JS -->
        </div>
        <button class="bp-btn-back">← Voltar</button>
    </div>

    <!-- ======================================================
         ETAPA 2 – Escolher Profissional
    ====================================================== -->
    <div class="bp-panel" data-panel="2" style="display:none">
        <h2>2. Escolha o Profissional</h2>
        <div class="bp-pro-grid">
            <div class="bp-pro-card" data-id="0" data-name="Primeiro Disponível">
                <div class="bp-pro-avatar bp-pro-any">⚡</div>
                <h3>Primeiro Disponível</h3>
                <p>Atendimento com qualquer profissional livre</p>
            </div>
            <?php foreach ( $professionals as $pro ) : ?>
            <div class="bp-pro-card"
                 data-id="<?php echo esc_attr($pro->id); ?>"
                 data-name="<?php echo esc_attr($pro->name); ?>">
                <?php if ( $pro->photo ) : ?>
                <img src="<?php echo esc_url($pro->photo); ?>" alt="<?php echo esc_attr($pro->name); ?>" class="bp-pro-img">
                <?php else : ?>
                <div class="bp-pro-avatar"><?php echo esc_html( strtoupper( substr($pro->name, 0, 1) ) ); ?></div>
                <?php endif; ?>
                <h3><?php echo esc_html($pro->name); ?></h3>
                <p class="bp-pro-specialty"><?php echo esc_html($pro->specialty ?? ''); ?></p>
                <div class="bp-pro-rating">⭐ <strong><?php echo esc_html( number_format($pro->rating, 1) ); ?></strong> <small>(<?php echo esc_html($pro->rating_count); ?>)</small></div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="bp-btn-back">← Voltar</button>
    </div>

    <!-- ======================================================
         ETAPA 3 – Escolher Data
    ====================================================== -->
    <div class="bp-panel" data-panel="3" style="display:none">
        <h2>3. Escolha a Data</h2>
        <div class="bp-calendar-wrap">
            <input type="date" id="bpDate"
                   min="<?php echo esc_attr( date('Y-m-d', strtotime('+1 day')) ); ?>"
                   max="<?php echo esc_attr( date('Y-m-d', strtotime("+{$max_days} days")) ); ?>"
                   class="bp-date-input">
            <p class="bp-calendar-hint">Selecione uma data disponível.</p>
        </div>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToSlots" disabled>Ver Horários →</button>
    </div>

    <!-- ======================================================
         ETAPA 4 – Escolher Horário
    ====================================================== -->
    <div class="bp-panel" data-panel="4" style="display:none">
        <h2>4. Escolha o Horário</h2>
        <div id="bpSlotsWrap" class="bp-slots-wrap">
            <p class="bp-loading">Carregando horários...</p>
        </div>
        <button class="bp-btn-back">← Voltar</button>
    </div>

    <!-- ======================================================
         ETAPA 5 – Coleta / Entrega (só lava-car)
    ====================================================== -->
    <?php if ($has_variants && !empty($delivery_options)): ?>
    <div class="bp-panel" data-panel="5" style="display:none">
        <h2>5. Como prefere a entrega?</h2>

        <?php if ($delivery_msg): ?>
        <div style="background:#eff6ff;border-left:3px solid #3b82f6;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:.9rem;color:#1e40af">
            ℹ️ <?php echo esc_html($delivery_msg); ?>
        </div>
        <?php endif; ?>

        <div class="bp-delivery-grid" id="bpDeliveryGrid">
            <?php foreach ($delivery_options as $key => $opt):
                $fee_label = $opt['fee'] > 0
                    ? '<span class="bp-delivery-fee">+ R$ ' . number_format($opt['fee'],2,',','.') . '</span>'
                    : '<span class="bp-delivery-free">Sem taxa</span>';
            ?>
            <div class="bp-delivery-card" 
                 data-key="<?php echo esc_attr($key); ?>"
                 data-fee="<?php echo esc_attr($opt['fee']); ?>"
                 data-needs-address="<?php echo $opt['needs_address'] ? '1' : '0'; ?>">
                <div class="bp-delivery-icon"><?php echo $opt['icon']; ?></div>
                <div class="bp-delivery-label"><?php echo esc_html($opt['label']); ?></div>
                <?php echo $fee_label; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Endereço – aparece só quando necessário -->
        <div id="bpAddressWrap" style="display:none;margin-top:20px">
            <div style="background:#fef3c7;border-left:3px solid #f59e0b;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:.88rem;color:#92400e">
                📍 Informe o endereço para coleta/entrega do veículo
            </div>
            <div class="bp-field">
                <label for="bpDeliveryStreet">Rua e número *</label>
                <input type="text" id="bpDeliveryStreet" name="delivery_street" placeholder="Ex: Rua das Flores, 123">
            </div>
            <div class="bp-field-row-2">
                <div class="bp-field">
                    <label for="bpDeliveryNeighborhood">Bairro *</label>
                    <input type="text" id="bpDeliveryNeighborhood" name="delivery_neighborhood" placeholder="Ex: Centro">
                </div>
                <div class="bp-field">
                    <label for="bpDeliveryComplement">Complemento</label>
                    <input type="text" id="bpDeliveryComplement" name="delivery_complement" placeholder="Apto, bloco...">
                </div>
            </div>
            <div class="bp-field">
                <label for="bpDeliveryNotes">Ponto de referência</label>
                <input type="text" id="bpDeliveryNotes" name="delivery_notes" placeholder="Ex: Em frente ao mercado, portão azul...">
            </div>
        </div>

        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToClientFromDelivery" disabled>Meus Dados →</button>
    </div>
    <?php endif; ?>

    <!-- ======================================================
         ETAPA 5b (ou 5 sem lava-car) – Dados do Cliente
    ====================================================== -->
    <?php $client_panel = $has_variants ? '6' : '5'; ?>
    <!-- ======================================================
         ETAPA <?php echo $has_variants ? '6' : '5'; ?> – Dados do Cliente
    ====================================================== -->
    <div class="bp-panel" data-panel="<?php echo $has_variants ? '6' : '5'; ?>" style="display:none">
        <h2><?php echo $has_variants ? '6' : '5'; ?>. Seus Dados</h2>
        <form id="bpClientForm" class="bp-form">
            <div class="bp-field">
                <label for="bpClientName">Nome completo *</label>
                <input type="text" id="bpClientName" name="client_name" required
                    value="<?php echo esc_attr( is_user_logged_in() ? wp_get_current_user()->display_name : '' ); ?>">
            </div>
            <div class="bp-field">
                <label for="bpClientPhone">Telefone / WhatsApp *</label>
                <input type="tel" id="bpClientPhone" name="client_phone" required placeholder="(11) 99999-9999"
                    value="<?php echo esc_attr( is_user_logged_in() ? get_user_meta( get_current_user_id(), 'billing_phone', true ) : '' ); ?>">
            </div>
            <div class="bp-field">
                <label for="bpClientEmail">E-mail</label>
                <input type="email" id="bpClientEmail" name="client_email"
                    value="<?php echo esc_attr( is_user_logged_in() ? wp_get_current_user()->user_email : '' ); ?>">
            </div>

            <!-- CAMPOS DE VEÍCULO – aparecem só quando o serviço tem variantes (lava-car) -->
            <div id="bpVehicleFields" style="display:none">
                <div style="background:#fef3c7;border-radius:8px;padding:12px 16px;margin:12px 0;border-left:3px solid #f59e0b">
                    <strong>🚗 Dados do Veículo</strong>
                </div>
                <div class="bp-field-row-2">
                    <div class="bp-field">
                        <label for="bpVehiclePlate">Placa *</label>
                        <input type="text" id="bpVehiclePlate" name="vehicle_plate"
                               placeholder="ABC-1234" maxlength="8"
                               style="text-transform:uppercase;letter-spacing:2px">
                    </div>
                    <div class="bp-field">
                        <label for="bpVehicleColor">Cor</label>
                        <input type="text" id="bpVehicleColor" name="vehicle_color" placeholder="Ex: Prata">
                    </div>
                </div>
                <div class="bp-field">
                    <label for="bpVehicleModel">Modelo do Veículo</label>
                    <input type="text" id="bpVehicleModel" name="vehicle_model"
                           placeholder="Ex: Honda Civic, Toyota Hilux...">
                </div>
            </div>

            <div class="bp-field">
                <label for="bpNotes">Observações</label>
                <textarea id="bpNotes" name="notes" rows="2" placeholder="Alguma informação extra?"></textarea>
            </div>
        </form>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToPayment">Pagamento →</button>
    </div>

    <!-- ======================================================
         ETAPA 6 – Pagamento + Resumo
    ====================================================== -->
    <?php
    $pay_methods = ['presencial' => '🏦 Pagar no local'];
    if ( class_exists('BarberPro_Payment') ) {
        $online_when = BarberPro_Database::get_setting('online_payment_when','optional');
        $gateways    = BarberPro_Payment::get_active_gateways();
        if ( $online_when === 'required_full' || $online_when === 'required_deposit' ) {
            // Online obrigatório — só mostra gateways online
            $pay_methods = $gateways ?: $pay_methods;
        } else {
            // Opcional — mostra presencial + gateways ativos
            $pay_methods = array_merge($pay_methods, $gateways);
        }
    }
    $require_deposit = BarberPro_Database::get_setting('require_deposit','0') === '1';
    $deposit_pct     = (int)BarberPro_Database::get_setting('deposit_pct',50);
    ?>
    <div class="bp-panel" data-panel="<?php echo $has_variants ? '7' : '6'; ?>" style="display:none">
        <h2><?php echo $has_variants ? '7' : '6'; ?>. Forma de Pagamento</h2>
        <?php if($require_deposit): ?>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;margin-bottom:16px;font-size:.88rem">
            💡 É necessário um sinal de <strong><?php echo $deposit_pct; ?>%</strong> para confirmar o agendamento.
        </div>
        <?php endif; ?>
        <div class="bp-payment-options">
            <?php foreach($pay_methods as $val => $label): ?>
            <label class="bp-payment-option">
                <input type="radio" name="payment_method" value="<?php echo esc_attr($val); ?>" <?php echo $val==='presencial'?'checked':''; ?>>
                <span class="bp-payment-icon"><?php echo mb_substr($label,0,2); ?></span>
                <span><?php echo esc_html(mb_substr($label,3)); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="bp-summary-box">
            <h3>📋 Resumo do Agendamento</h3>
            <div id="bpSummary"></div>
        </div>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-confirm" id="btnConfirmBooking">✅ Confirmar Agendamento</button>
    </div>

    <!-- ======================================================
         ETAPA 6b – QR Code / Redirect (pós-agendamento)
    ====================================================== -->
    <div class="bp-panel" data-panel="payment_qr" style="display:none" id="bpPaymentQrPanel">
        <div id="bpPaymentQrContent" style="text-align:center;padding:20px 0">
            <div class="bp-spinner-wrap"><div class="bp-spinner"></div><p>Gerando cobrança...</p></div>
        </div>
    </div>

    <!-- ======================================================
         ETAPA 7 – Confirmação / Sucesso
    ====================================================== -->
    <div class="bp-panel" data-panel="<?php echo $has_variants ? '8' : '7'; ?>" style="display:none">
        <div class="bp-success">
            <div class="bp-success-icon">✅</div>
            <h2>Agendamento Confirmado!</h2>
            <p>Você receberá a confirmação pelo WhatsApp em instantes.</p>
            <div class="bp-booking-code-box">
                <span>Código:</span>
                <strong id="bpBookingCode">—</strong>
            </div>
            <div class="bp-success-details" id="bpSuccessDetails"></div>

            <!-- Link WhatsApp da empresa (para o cliente entrar em contato) -->
            <?php
            $wa_numero = BarberPro_Database::get_setting('whatsapp_number');
            if ( $wa_numero ) :
                $wa_empresa = 'https://wa.me/' . preg_replace('/\D/', '', $wa_numero);
            ?>
            <a href="<?php echo esc_url($wa_empresa); ?>" target="_blank" rel="noopener" class="bp-btn bp-btn-whatsapp">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                Falar no WhatsApp
            </a>
            <?php endif; ?>

            <a id="bpGoogleCalBtn" href="#" target="_blank" class="bp-btn bp-btn-google">
                📅 Adicionar ao Google Agenda
            </a>
            <button id="btnNewBooking" class="bp-btn bp-btn-secondary">Novo Agendamento</button>
        </div>
    </div>

    <div id="bpGlobalLoader" class="bp-global-loader" style="display:none">
        <div class="bp-spinner"></div>
    </div>
</div><!-- #barberproBooking -->

<style>
.bp-variants-hint {
    font-size:.75rem;color:#3b82f6;display:block;margin-top:2px
}
.bp-variant-grid {
    display:flex;flex-wrap:wrap;gap:12px;margin:16px 0
}
.bp-variant-card {
    background:#fff;border:2px solid #e5e7eb;border-radius:10px;
    padding:16px 20px;cursor:pointer;transition:all .15s;text-align:center;
    flex:1;min-width:120px
}
.bp-variant-card:hover { border-color:#3b82f6;background:#eff6ff }
.bp-variant-card.selected { border-color:#3b82f6;background:#dbeafe }
.bp-variant-card .bp-vc-name { font-weight:700;font-size:1rem;margin-bottom:6px }
.bp-variant-card .bp-vc-price { color:#10b981;font-weight:700;font-size:1.1rem }
.bp-variant-card .bp-vc-dur { font-size:.8rem;color:#6b7280;margin-top:4px }
.bp-vehicle-icon { font-size:2rem;margin-bottom:8px }
.bp-field-row-2 { display:grid;grid-template-columns:1fr 1fr;gap:12px }
.bp-delivery-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin: 16px 0;
}
.bp-delivery-card {
    background: #fff;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 18px 16px;
    text-align: center;
    cursor: pointer;
    transition: all .15s;
    flex: 1;
    min-width: 140px;
}
.bp-delivery-card:hover  { border-color: #3b82f6; background: #eff6ff; }
.bp-delivery-card.selected { border-color: #3b82f6; background: #dbeafe; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.bp-delivery-icon { font-size: 2rem; margin-bottom: 8px; }
.bp-delivery-label { font-weight: 600; font-size: .9rem; margin-bottom: 6px; line-height: 1.3; }
.bp-delivery-fee  { display: block; color: #e94560; font-weight: 700; font-size: .9rem; }
.bp-delivery-free { display: block; color: #10b981; font-weight: 600; font-size: .85rem; }
@media (max-width: 480px) {
    .bp-delivery-card { min-width: 120px; padding: 14px 10px; }
}
.bp-btn-whatsapp {
    display:inline-flex;align-items:center;
    background:#25d366;color:#fff !important;text-decoration:none;
    padding:12px 24px;border-radius:8px;font-weight:700;
    margin-bottom:10px;transition:background .15s
}
.bp-btn-whatsapp:hover { background:#128c7e }
@media (max-width:480px) {
    .bp-field-row-2 { grid-template-columns:1fr }
    .bp-variant-card { min-width:100px }
}
</style>
