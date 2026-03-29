<?php
/**
 * Template – Agendamento Lava-Car
 * Shortcode: [barberpro_lavacar]
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$company_id    = BarberPro_Modules::company_id( 'lavacar' );
$max_days      = (int) BarberPro_Database::get_setting('booking_max_advance_days', 30);
$services      = BarberPro_Database::get_services( $company_id );
$professionals = BarberPro_Database::get_professionals( $company_id );

// Variantes de porte
$has_variants = false;
foreach ($services as $svc) {
    if (!empty(BarberPro_Database::get_service_variants((int)$svc->id))) { $has_variants = true; break; }
}

// Opções de entrega
$delivery_map = [
    'cliente_traz'                 => ['🏠','Trago e busco o carro',       0,                                                                            false],
    'empresa_busca_entrega'        => ['🚐','Buscar e entregar (+ taxa)',   (float)BarberPro_Database::get_setting('delivery_fee_busca_entrega',0),        true],
    'empresa_busca_cliente_retira' => ['📦','Só buscar (eu retiro)',        (float)BarberPro_Database::get_setting('delivery_fee_busca_retira',0),         true],
    'cliente_leva_empresa_entrega' => ['🏁','Eu levo, entregam pra mim',   (float)BarberPro_Database::get_setting('delivery_fee_leva_entrega',0),         true],
];
$delivery_enabled = [
    'cliente_traz'                 => (bool)BarberPro_Database::get_setting('delivery_opt_cliente_traz','1'),
    'empresa_busca_entrega'        => (bool)BarberPro_Database::get_setting('delivery_opt_busca_entrega','1'),
    'empresa_busca_cliente_retira' => (bool)BarberPro_Database::get_setting('delivery_opt_busca_retira','1'),
    'cliente_leva_empresa_entrega' => (bool)BarberPro_Database::get_setting('delivery_opt_leva_entrega','1'),
];
$delivery_options = [];
foreach ($delivery_map as $k => [$icon,$label,$fee,$needs]) {
    if (!empty($delivery_enabled[$k])) {
        $delivery_options[$k] = ['label'=>$label,'icon'=>$icon,'fee'=>$fee,'needs_address'=>$needs];
    }
}
$delivery_json = wp_json_encode($delivery_options);
$delivery_max_km = BarberPro_Database::get_setting('delivery_max_km',10);
$delivery_msg    = str_replace('{raio}', $delivery_max_km, BarberPro_Database::get_setting('delivery_info_msg',''));

// Etapas dinâmicas
$step_labels = $has_variants
    ? [1=>'Serviço',2=>'Profissional',3=>'Data',4=>'Horário',5=>'Coleta/Entrega',6=>'Seus Dados',7=>'Pagamento',8=>'Confirmação']
    : [1=>'Serviço',2=>'Profissional',3=>'Data',4=>'Horário',5=>'Seus Dados',6=>'Pagamento',7=>'Confirmação'];
$panel_dados   = $has_variants ? 6 : 5;
$panel_payment = $has_variants ? 7 : 6;
$panel_success = $has_variants ? 8 : 7;
?>
<div id="barberproBooking" class="barberpro-booking barberpro-lavacar"
     data-max-days="<?php echo esc_attr($max_days); ?>"
     data-mode="<?php echo $has_variants ? 'vehicle' : 'default'; ?>"
     data-company="<?php echo esc_attr($company_id); ?>"
     data-delivery='<?php echo $delivery_json; ?>'
     data-require-address="<?php echo esc_attr(BarberPro_Database::get_setting('delivery_require_full_address','1')); ?>"
     data-ajaxurl="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
     data-nonce="<?php echo esc_attr(wp_create_nonce('barberpro_booking')); ?>">

    <div class="bp-module-header bp-lava-header">
        <span class="bp-module-icon">🚗</span>
        <h2><?php echo esc_html(BarberPro_Database::get_setting('module_lavacar_name','Lava-Car')); ?></h2>
        <p>Agende a lavagem do seu veículo</p>
    </div>

    <!-- Progress -->
    <div class="bp-progress">
        <?php foreach ($step_labels as $n => $l): ?>
        <div class="bp-step" data-step="<?php echo $n; ?>"><div class="bp-step-dot"><?php echo $n; ?></div><span><?php echo esc_html($l); ?></span></div>
        <?php endforeach; ?>
    </div>

    <!-- Etapa 1: Serviço -->
    <div class="bp-panel" data-panel="1">
        <h2>1. Escolha o Serviço</h2>
        <div class="bp-service-grid">
            <?php foreach ($services as $service):
                $variants = BarberPro_Database::get_service_variants((int)$service->id);
                $vj = wp_json_encode(array_map(function($v) { return ['id'=>$v->id,'name'=>$v->name,'size_key'=>$v->size_key,'price'=>(float)$v->price,'duration'=>$v->duration]; }, $variants));
            ?>
            <div class="bp-service-card"
                 data-id="<?php echo esc_attr($service->id); ?>"
                 data-duration="<?php echo esc_attr($service->duration); ?>"
                 data-price="<?php echo esc_attr($service->price); ?>"
                 data-name="<?php echo esc_attr($service->name); ?>"
                 data-variants="<?php echo esc_attr($vj); ?>">
                <div class="bp-service-icon">🚿</div>
                <h3><?php echo esc_html($service->name); ?></h3>
                <?php if ($service->description): ?><p class="bp-service-desc"><?php echo esc_html($service->description); ?></p><?php endif; ?>
                <div class="bp-service-meta">
                    <?php if (!empty($variants)): ?>
                    <span class="bp-price">A partir de R$ <?php echo esc_html(number_format(min(array_column($variants,'price')),2,',','.')); ?></span>
                    <span class="bp-variants-hint"><?php echo count($variants); ?> opções</span>
                    <?php else: ?>
                    <span class="bp-price">R$ <?php echo esc_html(number_format($service->price,2,',','.')); ?></span>
                    <?php endif; ?>
                    <span class="bp-duration"><?php echo esc_html($service->duration); ?> min</span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($services)): ?><p>Nenhum serviço disponível.</p><?php endif; ?>
        </div>
    </div>

    <!-- Etapa 1b: Variante de porte -->
    <?php if ($has_variants): ?>
    <div class="bp-panel" data-panel="1b" style="display:none">
        <h2>1b. Porte do Veículo</h2>
        <p class="bp-hint">O preço varia conforme o tamanho do veículo.</p>
        <div class="bp-variant-grid" id="bpVariantGrid"></div>
        <button class="bp-btn-back">← Voltar</button>
    </div>
    <?php endif; ?>

    <!-- Etapa 2: Profissional -->
    <div class="bp-panel" data-panel="2" style="display:none">
        <h2>2. Atendente</h2>
        <div class="bp-pro-grid">
            <div class="bp-pro-card" data-id="0" data-name="Primeiro Disponível">
                <div class="bp-pro-avatar bp-pro-any">⚡</div>
                <h3>Primeiro Disponível</h3>
                <p>Qualquer atendente livre</p>
            </div>
            <?php foreach ($professionals as $pro): ?>
            <div class="bp-pro-card" data-id="<?php echo esc_attr($pro->id); ?>" data-name="<?php echo esc_attr($pro->name); ?>">
                <div class="bp-pro-avatar"><?php echo esc_html(strtoupper(substr($pro->name,0,1))); ?></div>
                <h3><?php echo esc_html($pro->name); ?></h3>
                <div class="bp-pro-rating">⭐ <strong><?php echo esc_html(number_format($pro->rating,1)); ?></strong></div>
            </div>
            <?php endforeach; ?>
        </div>
        <button class="bp-btn-back">← Voltar</button>
    </div>

    <!-- Etapa 3: Data -->
    <div class="bp-panel" data-panel="3" style="display:none">
        <h2>3. Escolha a Data</h2>
        <div class="bp-calendar-wrap">
            <input type="date" id="bpDate"
                   min="<?php echo esc_attr(date('Y-m-d',strtotime('+1 day'))); ?>"
                   max="<?php echo esc_attr(date('Y-m-d',strtotime("+{$max_days} days"))); ?>"
                   class="bp-date-input">
        </div>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToSlots" disabled>Ver Horários →</button>
    </div>

    <!-- Etapa 4: Horário -->
    <div class="bp-panel" data-panel="4" style="display:none">
        <h2>4. Escolha o Horário</h2>
        <div id="bpSlotsWrap" class="bp-slots-wrap"><p class="bp-loading">Carregando...</p></div>
        <button class="bp-btn-back">← Voltar</button>
    </div>

    <!-- Etapa 5: Coleta/Entrega (só se tem delivery options) -->
    <?php if (!empty($delivery_options)): ?>
    <div class="bp-panel" data-panel="5" style="display:none">
        <h2>5. Coleta / Entrega</h2>
        <?php if ($delivery_msg): ?>
        <div style="background:#eff6ff;border-left:3px solid #3b82f6;border-radius:6px;padding:10px 14px;margin-bottom:16px;font-size:.9rem;color:#1e40af">
            ℹ️ <?php echo esc_html($delivery_msg); ?>
        </div>
        <?php endif; ?>
        <div class="bp-delivery-grid">
            <?php foreach ($delivery_options as $key => $opt):
                $fee_html = $opt['fee'] > 0
                    ? '<span class="bp-delivery-fee">+ R$ ' . number_format($opt['fee'],2,',','.') . '</span>'
                    : '<span class="bp-delivery-free">Sem taxa</span>';
            ?>
            <div class="bp-delivery-card"
                 data-key="<?php echo esc_attr($key); ?>"
                 data-fee="<?php echo esc_attr($opt['fee']); ?>"
                 data-needs-address="<?php echo $opt['needs_address']?'1':'0'; ?>">
                <div class="bp-delivery-icon"><?php echo $opt['icon']; ?></div>
                <div class="bp-delivery-label"><?php echo esc_html($opt['label']); ?></div>
                <?php echo $fee_html; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Endereço condicional -->
        <div id="bpAddressWrap" style="display:none;margin-top:20px">
            <div style="background:#fef3c7;border-left:3px solid #f59e0b;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:.88rem;color:#92400e">
                📍 Endereço para coleta/entrega do veículo
            </div>
            <div class="bp-field"><label>Rua e número *</label>
                <input type="text" id="bpDeliveryStreet" name="delivery_street" placeholder="Ex: Rua das Flores, 123"></div>
            <div class="bp-field-row-2">
                <div class="bp-field"><label>Bairro *</label>
                    <input type="text" id="bpDeliveryNeighborhood" name="delivery_neighborhood" placeholder="Ex: Centro"></div>
                <div class="bp-field"><label>Complemento</label>
                    <input type="text" id="bpDeliveryComplement" name="delivery_complement" placeholder="Apto, bloco..."></div>
            </div>
            <div class="bp-field"><label>Ponto de referência</label>
                <input type="text" id="bpDeliveryNotes" name="delivery_notes" placeholder="Ex: Portão azul, em frente ao mercado"></div>
        </div>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToClientFromDelivery" disabled>Meus Dados →</button>
    </div>
    <?php endif; ?>

    <!-- Etapa dados do cliente -->
    <div class="bp-panel" data-panel="<?php echo $panel_dados; ?>" style="display:none">
        <h2><?php echo $panel_dados; ?>. Seus Dados</h2>
        <form id="bpClientForm" class="bp-form">
            <div class="bp-field"><label>Nome completo *</label>
                <input type="text" id="bpClientName" name="client_name" required value="<?php echo esc_attr(is_user_logged_in()?wp_get_current_user()->display_name:''); ?>"></div>
            <div class="bp-field"><label>WhatsApp *</label>
                <input type="tel" id="bpClientPhone" name="client_phone" required placeholder="(44) 99999-9999"></div>
            <div class="bp-field"><label>E-mail</label>
                <input type="email" id="bpClientEmail" name="client_email"></div>
            <!-- Campos de veículo -->
            <div id="bpVehicleFields" style="<?php echo $has_variants?'':'display:none'; ?>">
                <div style="background:#fef3c7;border-radius:8px;padding:10px 14px;margin:12px 0;border-left:3px solid #f59e0b"><strong>🚗 Dados do Veículo</strong></div>
                <div class="bp-field-row-2">
                    <div class="bp-field"><label>Placa *</label>
                        <input type="text" id="bpVehiclePlate" name="vehicle_plate" placeholder="ABC-1234" maxlength="8" style="text-transform:uppercase;letter-spacing:2px"></div>
                    <div class="bp-field"><label>Cor</label>
                        <input type="text" id="bpVehicleColor" name="vehicle_color" placeholder="Ex: Prata"></div>
                </div>
                <div class="bp-field"><label>Modelo</label>
                    <input type="text" id="bpVehicleModel" name="vehicle_model" placeholder="Ex: Honda Civic, Toyota Hilux..."></div>
            </div>
            <div class="bp-field"><label>Observações</label>
                <textarea id="bpNotes" name="notes" rows="2"></textarea></div>
        </form>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToPayment">Pagamento →</button>
    </div>

    <!-- Etapa pagamento -->
    <div class="bp-panel" data-panel="<?php echo $panel_payment; ?>" style="display:none">
        <h2><?php echo $panel_payment; ?>. Pagamento</h2>
        <div class="bp-payment-options">
            <label class="bp-payment-option"><input type="radio" name="payment_method" value="presencial" checked><span class="bp-payment-icon">🏦</span><span>No local</span></label>
            <label class="bp-payment-option"><input type="radio" name="payment_method" value="pix"><span class="bp-payment-icon">📱</span><span>PIX</span></label>
            <label class="bp-payment-option"><input type="radio" name="payment_method" value="cartao"><span class="bp-payment-icon">💳</span><span>Cartão</span></label>
        </div>
        <div class="bp-summary-box"><h3>📋 Resumo</h3><div id="bpSummary"></div></div>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-confirm" id="btnConfirmBooking">✅ Confirmar</button>
    </div>

    <!-- Etapa sucesso -->
    <div class="bp-panel" data-panel="<?php echo $panel_success; ?>" style="display:none">
        <div class="bp-success">
            <div class="bp-success-icon">✅</div>
            <h2>Agendamento Confirmado!</h2>
            <p>Você receberá a confirmação pelo WhatsApp.</p>
            <div class="bp-booking-code-box"><span>Código:</span><strong id="bpBookingCode">—</strong></div>
            <div class="bp-success-details" id="bpSuccessDetails"></div>
            <?php $wa = BarberPro_Database::get_setting('whatsapp_number');
            if ($wa): $wa_url = 'https://wa.me/' . preg_replace('/\D/','',$wa); ?>
            <a href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener" class="bp-btn bp-btn-whatsapp">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                Falar no WhatsApp
            </a>
            <?php endif; ?>
            <button id="btnNewBooking" class="bp-btn bp-btn-secondary">Novo Agendamento</button>
        </div>
    </div>

    <div id="bpGlobalLoader" class="bp-global-loader" style="display:none"><div class="bp-spinner"></div></div>
</div>

<style>
.barberpro-lavacar .bp-module-header { text-align:center;padding:24px 16px 16px;border-bottom:2px solid #3b82f6;margin-bottom:20px }
.barberpro-lavacar .bp-module-icon   { font-size:2.5rem;display:block;margin-bottom:8px }
.barberpro-lavacar .bp-module-header h2 { margin:0;color:#3b82f6;font-size:1.4rem }
.barberpro-lavacar .bp-module-header p  { color:#6b7280;margin:4px 0 0;font-size:.9rem }
.barberpro-lavacar .bp-step-dot { background:#3b82f6 !important }
.bp-delivery-grid{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}
.bp-delivery-card{background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:18px 14px;text-align:center;cursor:pointer;transition:all .15s;flex:1;min-width:130px}
.bp-delivery-card:hover{border-color:#3b82f6;background:#eff6ff}
.bp-delivery-card.selected{border-color:#3b82f6;background:#dbeafe;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.bp-delivery-icon{font-size:1.8rem;margin-bottom:8px}
.bp-delivery-label{font-weight:600;font-size:.88rem;margin-bottom:6px;line-height:1.3}
.bp-delivery-fee{display:block;color:#e94560;font-weight:700;font-size:.9rem}
.bp-delivery-free{display:block;color:#10b981;font-weight:600;font-size:.85rem}
.bp-field-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.bp-variants-hint{font-size:.75rem;color:#3b82f6;display:block;margin-top:2px}
.bp-btn-whatsapp{display:inline-flex;align-items:center;background:#25d366;color:#fff!important;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:700;margin-bottom:10px;transition:background .15s}
.bp-btn-whatsapp:hover{background:#128c7e}
@media(max-width:480px){.bp-delivery-card{min-width:110px;padding:14px 8px}.bp-field-row-2{grid-template-columns:1fr}}
</style>
