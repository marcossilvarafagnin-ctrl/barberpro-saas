<?php
/**
 * View – Gestão de Comandas
 * Abertura, itens, fechamento, pagamento split, comprovante de impressão
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can('barberpro_manage_bookings') ) wp_die('Sem permissao.');

$company_id   = isset($company_id) ? (int)$company_id : BarberPro_Database::get_company_id();
$professionals = BarberPro_Database::get_professionals( $company_id );
$services      = BarberPro_Database::get_services( $company_id );

// ── Processar AJAX-like POST actions ─────────────────────────────────────────
$action = sanitize_key( $_POST['bp_comanda_action'] ?? $_GET['bp_comanda_action'] ?? '' );
$nonce  = sanitize_text_field( $_POST['bp_comanda_nonce'] ?? $_GET['bp_comanda_nonce'] ?? '' );

if ( $action && wp_verify_nonce($nonce, 'bp_comanda_'.$action) ) {
    switch ($action) {

        case 'open':
            $id = BarberPro_Comanda::open([
                'company_id'   => $company_id,
                'client_name'  => sanitize_text_field($_POST['client_name'] ?? 'Cliente'),
                'client_phone' => sanitize_text_field($_POST['client_phone'] ?? ''),
            ]);
            wp_redirect(add_query_arg(['bp_comanda_id'=>$id], remove_query_arg('bp_comanda_action')));
            exit;

        case 'add_item':
            BarberPro_Comanda::add_item( absint($_POST['comanda_id']), [
                'description'     => sanitize_text_field($_POST['description'] ?? ''),
                'quantity'        => (float)($_POST['quantity'] ?? 1),
                'unit_price'      => (float)str_replace(['.', ','], ['', '.'], $_POST['unit_price'] ?? '0'),
                'professional_id' => absint($_POST['professional_id'] ?? 0) ?: null,
                'service_id'      => absint($_POST['service_id'] ?? 0) ?: null,
                'item_type'       => sanitize_key($_POST['item_type'] ?? 'servico'),
            ]);
            wp_redirect(add_query_arg(['bp_comanda_id'=>absint($_POST['comanda_id'])], remove_query_arg('bp_comanda_action')));
            exit;

        case 'remove_item':
            BarberPro_Comanda::remove_item( absint($_GET['item_id']) );
            wp_redirect(add_query_arg(['bp_comanda_id'=>absint($_GET['comanda_id'])], remove_query_arg(['bp_comanda_action','bp_comanda_nonce','item_id'])));
            exit;

        case 'pay':
            $cid_pay   = absint($_POST['comanda_id']);
            $discount  = (float)str_replace(',','.',($_POST['discount'] ?? '0'));
            $disc_type = sanitize_key($_POST['discount_type'] ?? 'fixo');
            BarberPro_Comanda::close($cid_pay, $discount, $disc_type);

            $payments = [];
            $methods = ['dinheiro','pix','cartao_debito','cartao_credito'];
            foreach ($methods as $m) {
                $amt = (float)str_replace(',','.',($_POST['pmt_'.$m] ?? '0'));
                if ($amt > 0) $payments[] = ['method'=>$m,'amount'=>$amt];
            }
            $result = BarberPro_Comanda::pay($cid_pay, $payments);
            if ($result['success']) {
                wp_redirect(add_query_arg(['bp_comanda_id'=>$cid_pay,'bp_paid'=>1], remove_query_arg('bp_comanda_action')));
            } else {
                wp_redirect(add_query_arg(['bp_comanda_id'=>$cid_pay,'bp_pay_error'=>urlencode($result['message'])], remove_query_arg('bp_comanda_action')));
            }
            exit;

        case 'cancel':
            BarberPro_Comanda::cancel( absint($_GET['comanda_id']) );
            wp_redirect(remove_query_arg(['bp_comanda_id','bp_comanda_action','bp_comanda_nonce']));
            exit;
    }
}

// ── Estado atual ──────────────────────────────────────────────────────────────
$comanda_id = absint( $_GET['bp_comanda_id'] ?? 0 );
$comanda    = $comanda_id ? BarberPro_Comanda::get($comanda_id) : null;
$items      = $comanda    ? BarberPro_Comanda::get_items($comanda_id) : [];
$payments   = ($comanda && $comanda->status === 'paga') ? BarberPro_Comanda::get_payments($comanda_id) : [];
$today_list = BarberPro_Comanda::list(['company_id'=>$company_id,'date'=>current_time('Y-m-d'),'limit'=>30]);

$page_url   = is_admin() ? admin_url('admin.php?page=barberpro_comandas') : get_permalink();
$paid_ok    = isset($_GET['bp_paid']);
$pay_error  = sanitize_text_field($_GET['bp_pay_error'] ?? '');

function cmd_money($v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function cmd_nonce(string $a): string { return wp_create_nonce('bp_comanda_'.$a); }
$method_labels = ['dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Cartao Debito','cartao_credito'=>'Cartao Credito'];
$status_meta = [
    'aberta'    => ['Aberta',    '#fbbf24','#78350f'],
    'fechada'   => ['Fechada',   '#3b82f6','#1e40af'],
    'paga'      => ['Paga',      '#10b981','#065f46'],
    'cancelada' => ['Cancelada', '#ef4444','#991b1b'],
];
?>

<div class="wrap barberpro-admin" id="bpComandasWrap">
<style>
#bpComandasWrap{max-width:1200px}
.bp-cmd-grid{display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start}
.bp-cmd-card{background:#fff;border-radius:14px;padding:22px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.bp-cmd-card h3{margin:0 0 16px;font-size:.95rem;color:#374151;border-bottom:1px solid #f3f4f6;padding-bottom:10px}
.bp-cmd-status{display:inline-block;padding:3px 12px;border-radius:20px;font-size:.78rem;font-weight:700}
.bp-cmd-code{font-family:monospace;font-size:1.1rem;font-weight:800;color:#1a1a2e;letter-spacing:1px}
.bp-field{margin-bottom:12px}
.bp-field label{display:block;font-size:.78rem;font-weight:600;color:#6b7280;margin-bottom:4px}
.bp-field input,.bp-field select{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem;box-sizing:border-box}
.bp-field-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.bp-btn-prim{background:#1a1a2e;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.9rem;width:100%}
.bp-btn-prim:hover{background:#e94560}
.bp-btn-sm{padding:4px 10px;border-radius:6px;font-size:.78rem;cursor:pointer;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;text-decoration:none}
.bp-btn-danger{border-color:#fecaca;background:#fee2e2;color:#991b1b}
.bp-btn-green{background:#10b981;color:#fff;border:none;padding:12px 24px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.95rem;width:100%}
.bp-items-table{width:100%;border-collapse:collapse;font-size:.85rem;margin-bottom:14px}
.bp-items-table th{text-align:left;padding:7px 8px;background:#f8f9fa;font-size:.72rem;text-transform:uppercase;color:#9ca3af}
.bp-items-table td{padding:8px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.bp-items-table tr:last-child td{border:none}
.bp-total-box{background:#f8f9fa;border-radius:8px;padding:14px 16px}
.bp-total-row{display:flex;justify-content:space-between;padding:5px 0;font-size:.88rem}
.bp-total-row.final{border-top:2px solid #1a1a2e;margin-top:6px;padding-top:8px;font-size:1.1rem;font-weight:800}
.bp-pmt-split{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.bp-pmt-field{background:#f8f9fa;border-radius:8px;padding:12px}
.bp-pmt-field label{display:block;font-weight:700;font-size:.82rem;margin-bottom:6px}
.bp-pmt-field input{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;box-sizing:border-box;font-weight:600}
.bp-today-list{margin-top:20px}
.bp-cmd-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#fff;border-radius:10px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.05);cursor:pointer;transition:box-shadow .15s}
.bp-cmd-row:hover{box-shadow:0 3px 10px rgba(0,0,0,.1)}
.bp-alert-success{background:#d1fae5;border-left:4px solid #10b981;padding:12px 16px;border-radius:8px;color:#065f46;margin-bottom:16px;font-weight:600}
.bp-alert-error{background:#fee2e2;border-left:4px solid #ef4444;padding:12px 16px;border-radius:8px;color:#991b1b;margin-bottom:16px}
@media(max-width:768px){.bp-cmd-grid{grid-template-columns:1fr}.bp-pmt-split{grid-template-columns:1fr}}

/* Impressao 80mm */
@media print {
    body *{visibility:hidden}
    #bpComprovanteImpressao,#bpComprovanteImpressao *{visibility:visible}
    #bpComprovanteImpressao{position:fixed;top:0;left:0;width:80mm;font-family:'Courier New',monospace;font-size:11px;line-height:1.5}
    .bp-no-print{display:none!important}
}
</style>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
        <h1 style="margin:0">🧾 Comandas</h1>
        <?php if($comanda): ?>
        <a href="<?php echo esc_url($page_url); ?>" class="button">← Voltar para lista</a>
        <?php endif; ?>
    </div>

    <?php if($paid_ok && $comanda): ?>
    <div class="bp-alert-success">✅ Comanda #<?php echo esc_html($comanda->comanda_code); ?> paga com sucesso! Receita lançada no financeiro.</div>
    <?php endif; ?>
    <?php if($pay_error): ?>
    <div class="bp-alert-error">⚠️ <?php echo esc_html(urldecode($pay_error)); ?></div>
    <?php endif; ?>

<?php if (!$comanda): ?>
    <!-- ═══════════════════════════════════════════════════════ LISTA + ABERTURA -->
    <div class="bp-cmd-grid">

        <!-- Abrir nova comanda -->
        <div class="bp-cmd-card">
            <h3>➕ Abrir Nova Comanda</h3>
            <form method="post">
                <?php wp_nonce_field('bp_comanda_open','bp_comanda_nonce'); ?>
                <input type="hidden" name="bp_comanda_action" value="open">
                <div class="bp-field">
                    <label>Nome do Cliente *</label>
                    <input type="text" name="client_name" placeholder="Ex: João Silva" required>
                </div>
                <div class="bp-field">
                    <label>WhatsApp</label>
                    <input type="tel" name="client_phone" placeholder="(44) 99999-9999">
                </div>
                <button type="submit" class="bp-btn-prim">🧾 Abrir Comanda</button>
            </form>
        </div>

        <!-- Comandas do dia -->
        <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <h3 style="margin:0">📋 Comandas de Hoje</h3>
                <span style="font-size:.82rem;color:#9ca3af"><?php echo count($today_list); ?> comanda(s)</span>
            </div>
            <div class="bp-today-list">
                <?php foreach($today_list as $c):
                    [$slabel,$sbg,$sfg] = $status_meta[$c->status] ?? ['?','#e5e7eb','#374151'];
                    $link = add_query_arg('bp_comanda_id',$c->id,$page_url);
                ?>
                <a href="<?php echo esc_url($link); ?>" style="text-decoration:none">
                <div class="bp-cmd-row">
                    <div>
                        <span class="bp-cmd-code">#<?php echo esc_html($c->comanda_code); ?></span>
                        <span style="color:#6b7280;font-size:.82rem;margin-left:8px"><?php echo esc_html($c->client_name); ?></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:10px">
                        <strong style="color:#1a1a2e"><?php echo esc_html(cmd_money($c->total_final)); ?></strong>
                        <span class="bp-cmd-status" style="background:<?php echo esc_attr($sbg); ?>20;color:<?php echo esc_attr($sfg); ?>"><?php echo esc_html($slabel); ?></span>
                    </div>
                </div>
                </a>
                <?php endforeach; ?>
                <?php if(empty($today_list)): ?>
                <p style="color:#9ca3af;text-align:center;padding:24px 0">Nenhuma comanda hoje ainda.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ═══════════════════════════════════════════════════════ COMANDA ABERTA -->
    <?php
    [$slabel,$sbg,$sfg] = $status_meta[$comanda->status] ?? ['?','#e5e7eb','#374151'];
    $discount_value = $comanda->discount_type === 'percentual'
        ? round($comanda->total_items * $comanda->discount / 100, 2)
        : (float)$comanda->discount;
    ?>

    <div class="bp-cmd-grid">

        <!-- COLUNA ESQUERDA: Adicionar item + totais -->
        <div>
            <!-- Info da comanda -->
            <div class="bp-cmd-card" style="margin-bottom:16px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <div class="bp-cmd-code">#<?php echo esc_html($comanda->comanda_code); ?></div>
                        <div style="font-size:.88rem;color:#374151;margin-top:3px">👤 <?php echo esc_html($comanda->client_name); ?>
                            <?php if($comanda->client_phone): ?> · 📱 <?php echo esc_html($comanda->client_phone); ?><?php endif; ?>
                        </div>
                        <div style="font-size:.78rem;color:#9ca3af;margin-top:2px">📅 <?php echo date_i18n('d/m/Y H:i',strtotime($comanda->created_at)); ?></div>
                    </div>
                    <span class="bp-cmd-status" style="background:<?php echo esc_attr($sbg); ?>20;color:<?php echo esc_attr($sfg); ?>"><?php echo esc_html($slabel); ?></span>
                </div>
            </div>

            <?php if(in_array($comanda->status, ['aberta','fechada'])): ?>
            <!-- Adicionar item -->
            <div class="bp-cmd-card" style="margin-bottom:16px">
                <h3>➕ Adicionar Item</h3>
                <form method="post" id="bpAddItemForm">
                    <?php wp_nonce_field('bp_comanda_add_item','bp_comanda_nonce'); ?>
                    <input type="hidden" name="bp_comanda_action" value="add_item">
                    <input type="hidden" name="comanda_id" value="<?php echo esc_attr($comanda_id); ?>">

                    <!-- Tipo de item -->
                    <div class="bp-field">
                        <label>Tipo</label>
                        <select name="item_type" id="bpItemType" onchange="bpToggleItemType(this.value)">
                            <option value="servico">✂️ Serviço cadastrado</option>
                            <option value="livre">📝 Item livre</option>
                        </select>
                    </div>

                    <!-- Serviço cadastrado -->
                    <div id="bpServiceSelect">
                        <div class="bp-field">
                            <label>Serviço</label>
                            <select name="service_id" id="bpServiceId" onchange="bpFillService(this)">
                                <option value="">-- Selecione --</option>
                                <?php foreach($services as $svc): ?>
                                <option value="<?php echo esc_attr($svc->id); ?>"
                                        data-price="<?php echo esc_attr($svc->price); ?>"
                                        data-name="<?php echo esc_attr($svc->name); ?>">
                                    <?php echo esc_html($svc->name); ?> – <?php echo esc_html(cmd_money($svc->price)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Descrição livre -->
                    <div class="bp-field" id="bpDescField" style="display:none">
                        <label>Descrição *</label>
                        <input type="text" name="description" id="bpItemDesc" placeholder="Ex: Escova progressiva">
                    </div>
                    <input type="hidden" name="description" id="bpItemDescHidden">

                    <div class="bp-field-row">
                        <div class="bp-field">
                            <label>Qtd</label>
                            <input type="number" name="quantity" id="bpItemQty" value="1" min="0.5" step="0.5" oninput="bpCalcTotal()">
                        </div>
                        <div class="bp-field">
                            <label>Preco Unit. (R$)</label>
                            <input type="text" name="unit_price" id="bpItemPrice" placeholder="0,00" oninput="bpCalcTotal()">
                        </div>
                    </div>

                    <div class="bp-field" style="background:#f0fdf4;padding:8px 12px;border-radius:7px;margin-bottom:12px">
                        <label style="color:#166534">Total do Item</label>
                        <div id="bpItemTotal" style="font-size:1.1rem;font-weight:800;color:#10b981">R$ 0,00</div>
                    </div>

                    <div class="bp-field">
                        <label>Profissional</label>
                        <select name="professional_id">
                            <option value="">-- Nao atribuir --</option>
                            <?php foreach($professionals as $pro): ?>
                            <option value="<?php echo esc_attr($pro->id); ?>"><?php echo esc_html($pro->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="bp-btn-prim" style="background:#3b82f6">➕ Adicionar</button>
                </form>
            </div>

            <!-- Desconto + Fechar -->
            <div class="bp-cmd-card">
                <h3>🏷️ Desconto</h3>
                <div class="bp-field-row">
                    <div class="bp-field">
                        <label>Tipo</label>
                        <select id="bpDiscType">
                            <option value="fixo">R$ Fixo</option>
                            <option value="percentual">% Percentual</option>
                        </select>
                    </div>
                    <div class="bp-field">
                        <label>Valor</label>
                        <input type="text" id="bpDiscValue" placeholder="0" value="0">
                    </div>
                </div>
                <small style="color:#9ca3af">O desconto sera aplicado ao fechar/pagar.</small>
            </div>
            <?php endif; ?>
        </div>

        <!-- COLUNA DIREITA: itens + pagamento -->
        <div>
            <!-- Itens da comanda -->
            <div class="bp-cmd-card" style="margin-bottom:16px">
                <h3>🧾 Itens da Comanda <span style="color:#9ca3af;font-size:.82rem">(<?php echo count($items); ?> itens)</span></h3>
                <?php if(empty($items)): ?>
                <p style="color:#9ca3af;text-align:center;padding:16px">Nenhum item adicionado ainda.</p>
                <?php else: ?>
                <table class="bp-items-table">
                    <thead><tr><th>Item</th><th>Profissional</th><th>Qtd</th><th>Unit.</th><th>Total</th><?php if(in_array($comanda->status,['aberta','fechada'])): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach($items as $it): ?>
                    <tr>
                        <td><strong><?php echo esc_html($it->description); ?></strong></td>
                        <td style="color:#6b7280;font-size:.82rem"><?php echo esc_html($it->professional_name ?? '—'); ?></td>
                        <td><?php echo esc_html(number_format($it->quantity,1,',','.')); ?></td>
                        <td><?php echo esc_html(cmd_money($it->unit_price)); ?></td>
                        <td style="font-weight:700"><?php echo esc_html(cmd_money($it->total_price)); ?></td>
                        <?php if(in_array($comanda->status,['aberta','fechada'])): ?>
                        <td>
                            <a href="<?php echo esc_url(add_query_arg(['bp_comanda_action'=>'remove_item','bp_comanda_nonce'=>cmd_nonce('remove_item'),'comanda_id'=>$comanda_id,'item_id'=>$it->id], $page_url)); ?>"
                               class="bp-btn-sm bp-btn-danger"
                               onclick="return confirm('Remover este item?')">🗑</a>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <!-- Totais -->
                <div class="bp-total-box">
                    <div class="bp-total-row"><span>Subtotal</span><span><?php echo esc_html(cmd_money($comanda->total_items)); ?></span></div>
                    <?php if($discount_value > 0): ?>
                    <div class="bp-total-row" style="color:#ef4444"><span>Desconto</span><span>- <?php echo esc_html(cmd_money($discount_value)); ?></span></div>
                    <?php endif; ?>
                    <div class="bp-total-row final"><span>TOTAL</span><span style="color:#10b981"><?php echo esc_html(cmd_money($comanda->total_final)); ?></span></div>
                </div>
            </div>

            <!-- Pagamento -->
            <?php if(in_array($comanda->status,['aberta','fechada']) && !empty($items)): ?>
            <div class="bp-cmd-card">
                <h3>💳 Pagamento</h3>
                <form method="post" id="bpPayForm">
                    <?php wp_nonce_field('bp_comanda_pay','bp_comanda_nonce'); ?>
                    <input type="hidden" name="bp_comanda_action" value="pay">
                    <input type="hidden" name="comanda_id" value="<?php echo esc_attr($comanda_id); ?>">
                    <input type="hidden" name="discount" id="bpDiscountHidden" value="0">
                    <input type="hidden" name="discount_type" id="bpDiscTypeHidden" value="fixo">

                    <p style="font-size:.85rem;color:#6b7280;margin:0 0 12px">Distribua o total entre as formas de pagamento. O que ficar em branco sera ignorado.</p>

                    <div class="bp-pmt-split">
                        <?php foreach(['dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Cartao Debito','cartao_credito'=>'Cartao Credito'] as $m=>$ml): ?>
                        <div class="bp-pmt-field">
                            <label><?php echo esc_html($ml); ?></label>
                            <input type="text" name="pmt_<?php echo $m; ?>" id="pmt_<?php echo $m; ?>"
                                   placeholder="0,00" value="" oninput="bpCalcPaid()">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="background:#f0fdf4;border-radius:8px;padding:12px 14px;margin-bottom:14px">
                        <div style="display:flex;justify-content:space-between;font-size:.88rem;margin-bottom:4px">
                            <span>Total da comanda:</span>
                            <strong><?php echo esc_html(cmd_money($comanda->total_final)); ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.88rem;margin-bottom:4px">
                            <span>Total informado:</span>
                            <strong id="bpPaidTotal">R$ 0,00</strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;font-size:.9rem;font-weight:700" id="bpTrocoRow">
                            <span>Troco:</span>
                            <span id="bpTroco" style="color:#10b981">R$ 0,00</span>
                        </div>
                    </div>

                    <button type="submit" class="bp-btn-green" onclick="return bpValidatePay()">✅ Confirmar Pagamento</button>
                </form>

                <!-- Cancelar -->
                <div style="margin-top:10px;text-align:center">
                    <a href="<?php echo esc_url(add_query_arg(['bp_comanda_action'=>'cancel','bp_comanda_nonce'=>cmd_nonce('cancel'),'comanda_id'=>$comanda_id], $page_url)); ?>"
                       style="color:#ef4444;font-size:.82rem"
                       onclick="return confirm('Cancelar esta comanda?')">❌ Cancelar Comanda</a>
                </div>
            </div>

            <?php elseif($comanda->status === 'paga'): ?>
            <!-- Comprovante -->
            <div class="bp-cmd-card">
                <h3>✅ Comanda Paga</h3>
                <div style="margin-bottom:14px">
                    <?php foreach($payments as $pmt): ?>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:.88rem">
                        <span><?php echo esc_html($method_labels[$pmt->payment_method] ?? $pmt->payment_method); ?></span>
                        <strong><?php echo esc_html(cmd_money($pmt->amount)); ?></strong>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button onclick="window.print()" class="bp-btn-green bp-no-print">🖨️ Imprimir Comprovante</button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- COMPROVANTE IMPRESSAO 80mm -->
    <div id="bpComprovanteImpressao" style="display:none">
        <div style="text-align:center;border-bottom:1px dashed #000;padding-bottom:6px;margin-bottom:6px">
            <strong style="font-size:14px"><?php echo esc_html(BarberPro_Database::get_setting('module_barbearia_name','Estabelecimento')); ?></strong><br>
            <small>Comanda #<?php echo esc_html($comanda->comanda_code); ?></small><br>
            <small><?php echo date_i18n('d/m/Y H:i'); ?></small>
        </div>
        <div style="margin-bottom:6px">
            <strong>Cliente:</strong> <?php echo esc_html($comanda->client_name); ?><br>
            <?php if($comanda->client_phone): ?><strong>Tel:</strong> <?php echo esc_html($comanda->client_phone); ?><br><?php endif; ?>
        </div>
        <div style="border-bottom:1px dashed #000;margin-bottom:6px">
            <?php foreach($items as $it): ?>
            <div style="display:flex;justify-content:space-between;font-size:11px;padding:2px 0">
                <span><?php echo esc_html($it->description); ?> x<?php echo number_format($it->quantity,1); ?></span>
                <span><?php echo esc_html(cmd_money($it->total_price)); ?></span>
            </div>
            <?php if($it->professional_name): ?><div style="font-size:10px;color:#555;padding-left:8px">Prof: <?php echo esc_html($it->professional_name); ?></div><?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php if($discount_value > 0): ?>
        <div style="display:flex;justify-content:space-between;font-size:11px"><span>Desconto:</span><span>- <?php echo esc_html(cmd_money($discount_value)); ?></span></div>
        <?php endif; ?>
        <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:13px;border-top:1px dashed #000;margin-top:4px;padding-top:4px">
            <span>TOTAL</span><span><?php echo esc_html(cmd_money($comanda->total_final)); ?></span>
        </div>
        <?php if(!empty($payments)): ?>
        <div style="margin-top:6px;font-size:11px;border-top:1px dashed #000;padding-top:4px">
            <strong>Pagamento:</strong><br>
            <?php foreach($payments as $pmt): ?>
            <div style="display:flex;justify-content:space-between"><?php echo esc_html($method_labels[$pmt->payment_method]??$pmt->payment_method); ?> <span><?php echo esc_html(cmd_money($pmt->amount)); ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:4px">
            Obrigado pela preferencia!<br>
            <?php echo date_i18n('d/m/Y H:i'); ?>
        </div>
    </div>

    <script>
    // Mostra comprovante ao imprimir
    window.addEventListener('beforeprint', function(){
        document.getElementById('bpComprovanteImpressao').style.display='block';
    });
    window.addEventListener('afterprint', function(){
        document.getElementById('bpComprovanteImpressao').style.display='none';
    });
    </script>
<?php endif; ?>
</div>

<script>
var cmdTotal = <?php echo (float)($comanda->total_final ?? 0); ?>;

function bpToggleItemType(val) {
    document.getElementById('bpServiceSelect').style.display = val==='servico'?'':'none';
    document.getElementById('bpDescField').style.display     = val==='livre'?'':'none';
    if(val==='servico') { document.getElementById('bpItemDescHidden').name=''; document.querySelector('[name=description]').name='description_old'; }
    else { document.getElementById('bpItemDescHidden').name=''; }
}
function bpFillService(sel) {
    var opt = sel.options[sel.selectedIndex];
    var price = opt.dataset.price || '0';
    var name  = opt.dataset.name  || '';
    document.getElementById('bpItemPrice').value = parseFloat(price).toFixed(2).replace('.',',');
    document.getElementById('bpItemDescHidden').value = name;
    bpCalcTotal();
}
function bpCalcTotal() {
    var qty   = parseFloat(document.getElementById('bpItemQty').value.replace(',','.')) || 0;
    var price = parseFloat(document.getElementById('bpItemPrice').value.replace(',','.')) || 0;
    var total = qty * price;
    document.getElementById('bpItemTotal').textContent = 'R$ ' + total.toFixed(2).replace('.',',');
}
function bpCalcPaid() {
    var methods = ['dinheiro','pix','cartao_debito','cartao_credito'];
    var total = 0;
    methods.forEach(function(m){
        var v = parseFloat((document.getElementById('pmt_'+m)||{}).value||'0'.replace(',','.')) || 0;
        total += v;
    });
    document.getElementById('bpPaidTotal').textContent = 'R$ '+total.toFixed(2).replace('.',',');
    var troco = total - cmdTotal;
    var trEl  = document.getElementById('bpTroco');
    trEl.textContent = 'R$ '+Math.max(0,troco).toFixed(2).replace('.',',');
    trEl.style.color  = troco < 0 ? '#ef4444' : '#10b981';
}
function bpValidatePay() {
    var methods = ['dinheiro','pix','cartao_debito','cartao_credito'];
    var total = 0;
    methods.forEach(function(m){
        var v = parseFloat(((document.getElementById('pmt_'+m)||{}).value||'0').replace(',','.')) || 0;
        total += v;
    });
    // sync discount
    var discEl = document.getElementById('bpDiscValue');
    if(discEl) {
        document.getElementById('bpDiscountHidden').value = discEl.value.replace(',','.');
        document.getElementById('bpDiscTypeHidden').value = document.getElementById('bpDiscType').value;
    }
    if(total < cmdTotal - 0.01) {
        alert('Valor informado (R$ '+total.toFixed(2)+') menor que o total da comanda (R$ '+cmdTotal.toFixed(2)+').');
        return false;
    }
    return true;
}
// Preenche valor total no primeiro campo dinheiro ao carregar
document.addEventListener('DOMContentLoaded', function(){
    var el = document.getElementById('pmt_dinheiro');
    if(el && cmdTotal > 0) { el.value = cmdTotal.toFixed(2).replace('.',','); bpCalcPaid(); }
});
</script>
