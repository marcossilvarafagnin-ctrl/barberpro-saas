<?php
/**
 * Template – Agendamento Barbearia
 * Shortcode: [barberpro_barbearia]
 */
if ( ! defined( 'ABSPATH' ) ) exit;
$company_id = BarberPro_Modules::company_id( 'barbearia' );
$mode       = 'default';
$max_days   = (int) BarberPro_Database::get_setting('booking_max_advance_days', 30);
$services      = BarberPro_Database::get_services( $company_id );
$professionals = BarberPro_Database::get_professionals( $company_id );
// Sem variantes nem campos de veículo — fluxo padrão barbearia
$has_variants    = false;
$delivery_options = [];
$delivery_json    = '{}';
?>
<div id="barberproBooking" class="barberpro-booking barberpro-barbearia"
     data-max-days="<?php echo esc_attr($max_days); ?>"
     data-mode="default"
     data-company="<?php echo esc_attr($company_id); ?>"
     data-ajaxurl="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
     data-nonce="<?php echo esc_attr(wp_create_nonce('barberpro_booking')); ?>">

    <div class="bp-module-header bp-barber-header">
        <span class="bp-module-icon">✂️</span>
        <h2><?php echo esc_html(BarberPro_Database::get_setting('module_barbearia_name','Barbearia')); ?></h2>
        <p>Agende seu horário de forma rápida e fácil</p>
    </div>

    <!-- Progress -->
    <div class="bp-progress">
        <?php foreach ([1=>'Serviço',2=>'Profissional',3=>'Data',4=>'Horário',5=>'Seus Dados',6=>'Pagamento',7=>'Confirmação'] as $n=>$l): ?>
        <div class="bp-step" data-step="<?php echo $n; ?>"><div class="bp-step-dot"><?php echo $n; ?></div><span><?php echo esc_html($l); ?></span></div>
        <?php endforeach; ?>
    </div>

    <!-- Etapa 1: Serviço -->
    <div class="bp-panel" data-panel="1">
        <h2>1. Escolha o Serviço</h2>
        <div class="bp-service-grid">
            <?php foreach ($services as $service): ?>
            <div class="bp-service-card"
                 data-id="<?php echo esc_attr($service->id); ?>"
                 data-duration="<?php echo esc_attr($service->duration); ?>"
                 data-price="<?php echo esc_attr($service->price); ?>"
                 data-name="<?php echo esc_attr($service->name); ?>"
                 data-variants="[]">
                <div class="bp-service-icon">✂️</div>
                <h3><?php echo esc_html($service->name); ?></h3>
                <?php if ($service->description): ?><p class="bp-service-desc"><?php echo esc_html($service->description); ?></p><?php endif; ?>
                <div class="bp-service-meta">
                    <span class="bp-price">R$ <?php echo esc_html(number_format($service->price,2,',','.')); ?></span>
                    <span class="bp-duration"><?php echo esc_html($service->duration); ?> min</span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($services)): ?><p>Nenhum serviço disponível.</p><?php endif; ?>
        </div>
    </div>

    <!-- Etapa 2: Profissional -->
    <div class="bp-panel" data-panel="2" style="display:none">
        <h2>2. Escolha o Profissional</h2>
        <div class="bp-pro-grid">
            <div class="bp-pro-card" data-id="0" data-name="Primeiro Disponível">
                <div class="bp-pro-avatar bp-pro-any">⚡</div>
                <h3>Primeiro Disponível</h3>
                <p>Qualquer profissional livre</p>
            </div>
            <?php foreach ($professionals as $pro): ?>
            <div class="bp-pro-card" data-id="<?php echo esc_attr($pro->id); ?>" data-name="<?php echo esc_attr($pro->name); ?>">
                <div class="bp-pro-avatar"><?php echo esc_html(strtoupper(substr($pro->name,0,1))); ?></div>
                <h3><?php echo esc_html($pro->name); ?></h3>
                <p class="bp-pro-specialty"><?php echo esc_html($pro->specialty??''); ?></p>
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
                   min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>"
                   max="<?php echo esc_attr(date('Y-m-d', strtotime("+{$max_days} days"))); ?>"
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

    <!-- Etapa 5: Dados -->
    <div class="bp-panel" data-panel="5" style="display:none">
        <h2>5. Seus Dados</h2>
        <form id="bpClientForm" class="bp-form">
            <div class="bp-field"><label>Nome completo *</label>
                <input type="text" id="bpClientName" name="client_name" required value="<?php echo esc_attr(is_user_logged_in()?wp_get_current_user()->display_name:''); ?>"></div>
            <div class="bp-field"><label>WhatsApp *</label>
                <input type="tel" id="bpClientPhone" name="client_phone" required placeholder="(44) 99999-9999" value="<?php echo esc_attr(is_user_logged_in()?get_user_meta(get_current_user_id(),'billing_phone',true):''); ?>"></div>
            <div class="bp-field"><label>E-mail</label>
                <input type="email" id="bpClientEmail" name="client_email" value="<?php echo esc_attr(is_user_logged_in()?wp_get_current_user()->user_email:''); ?>"></div>
            <div class="bp-field"><label>Observações</label>
                <textarea id="bpNotes" name="notes" rows="2"></textarea></div>
        </form>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-next" id="btnGoToPayment">Pagamento →</button>
    </div>

    <!-- Etapa 6: Pagamento -->
    <div class="bp-panel" data-panel="6" style="display:none">
        <h2>6. Forma de Pagamento</h2>
        <div class="bp-payment-options">
            <label class="bp-payment-option"><input type="radio" name="payment_method" value="presencial" checked><span class="bp-payment-icon">🏦</span><span>No local</span></label>
            <label class="bp-payment-option"><input type="radio" name="payment_method" value="pix"><span class="bp-payment-icon">📱</span><span>PIX</span></label>
            <label class="bp-payment-option"><input type="radio" name="payment_method" value="cartao"><span class="bp-payment-icon">💳</span><span>Cartão</span></label>
        </div>
        <div class="bp-summary-box"><h3>📋 Resumo</h3><div id="bpSummary"></div></div>
        <button class="bp-btn-back">← Voltar</button>
        <button class="bp-btn-confirm" id="btnConfirmBooking">✅ Confirmar</button>
    </div>

    <!-- Etapa 7: Sucesso -->
    <div class="bp-panel" data-panel="7" style="display:none">
        <div class="bp-success">
            <div class="bp-success-icon">✅</div>
            <h2>Agendamento Confirmado!</h2>
            <p>Você receberá a confirmação pelo WhatsApp em instantes.</p>
            <div class="bp-booking-code-box"><span>Código:</span><strong id="bpBookingCode">—</strong></div>
            <div class="bp-success-details" id="bpSuccessDetails"></div>
            <a id="bpGoogleCalBtn" href="#" target="_blank" class="bp-btn bp-btn-google">📅 Google Agenda</a>
            <button id="btnNewBooking" class="bp-btn bp-btn-secondary">Novo Agendamento</button>
        </div>
    </div>

    <div id="bpGlobalLoader" class="bp-global-loader" style="display:none"><div class="bp-spinner"></div></div>
</div>

<style>
.barberpro-barbearia .bp-module-header { text-align:center;padding:24px 16px 16px;border-bottom:2px solid #e94560;margin-bottom:20px }
.barberpro-barbearia .bp-module-icon   { font-size:2.5rem;display:block;margin-bottom:8px }
.barberpro-barbearia .bp-module-header h2 { margin:0;color:#e94560;font-size:1.4rem }
.barberpro-barbearia .bp-module-header p  { color:#6b7280;margin:4px 0 0;font-size:.9rem }
.barberpro-barbearia .bp-step-dot { background:#e94560 !important }
.barberpro-barbearia .bp-step.active .bp-step-dot { box-shadow:0 0 0 4px #e9456020 }
</style>
