<?php
/**
 * View – Módulo Bar/Eventos
 * Tabs: Comandas | Produtos | Estoque | Financeiro
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can('barberpro_manage_bookings') ) wp_die('Sem permissao.');

$company_id = isset($company_id) ? (int)$company_id : BarberPro_Bar::CID;
$tab        = sanitize_key($_GET['bar_tab'] ?? 'comandas');
$page_url   = is_admin() ? admin_url('admin.php?page=barberpro_bar') : get_permalink();

function bar_tab_url(string $t, array $extra=[]): string {
    $base = is_admin() ? admin_url('admin.php?page=barberpro_bar') : get_permalink();
    return esc_url(add_query_arg(array_merge(['bar_tab'=>$t],$extra),$base));
}
function bar_money($v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function bar_nonce(string $a): string { return wp_create_nonce('bar_'.$a); }

$method_labels=['dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Debito','cartao_credito'=>'Credito'];

// ── POST handlers ─────────────────────────────────────────────────────────────
$action = sanitize_key($_POST['bar_action'] ?? $_GET['bar_action'] ?? '');
$nonce  = sanitize_text_field($_POST['bar_nonce'] ?? $_GET['bar_nonce'] ?? '');

if ($action && wp_verify_nonce($nonce, 'bar_'.$action)) {
    switch ($action) {

        case 'open_comanda':
            $id = BarberPro_Bar::open_comanda([
                'company_id'   => $company_id,
                'table_number' => sanitize_text_field($_POST['table_number']??''),
                'client_name'  => sanitize_text_field($_POST['client_name']??''),
            ]);
            wp_redirect(add_query_arg(['bar_tab'=>'comandas','bar_comanda_id'=>$id],remove_query_arg('bar_action')));
            exit;

        case 'add_item':
            $res = BarberPro_Bar::add_item(absint($_POST['comanda_id']),absint($_POST['product_id']),(float)str_replace(',','.',$_POST['quantity']??'1'));
            $redirect = add_query_arg(['bar_tab'=>'comandas','bar_comanda_id'=>absint($_POST['comanda_id'])],remove_query_arg('bar_action'));
            if (!$res['success']) $redirect = add_query_arg('bar_error',urlencode($res['message']),$redirect);
            wp_redirect($redirect); exit;

        case 'remove_item':
            BarberPro_Bar::remove_item(absint($_GET['item_id']));
            wp_redirect(add_query_arg(['bar_tab'=>'comandas','bar_comanda_id'=>absint($_GET['comanda_id'])],remove_query_arg(['bar_action','bar_nonce','item_id']))); exit;

        case 'pay_comanda':
            $payments=[];
            foreach(['dinheiro','pix','cartao_debito','cartao_credito'] as $m){
                $amt=(float)str_replace(',','.',$_POST['pmt_'.$m]??'0');
                if($amt>0) $payments[]=['method'=>$m,'amount'=>$amt];
            }
            $res=BarberPro_Bar::pay_comanda(
                absint($_POST['comanda_id']),
                $payments,
                (float)str_replace(',','.',$_POST['discount']??'0'),
                sanitize_key($_POST['discount_type']??'fixo')
            );
            $redirect=add_query_arg(['bar_tab'=>'comandas','bar_comanda_id'=>absint($_POST['comanda_id'])],remove_query_arg('bar_action'));
            if($res['success']) $redirect=add_query_arg('bar_paid',1,$redirect);
            else $redirect=add_query_arg('bar_error',urlencode($res['message']),$redirect);
            wp_redirect($redirect); exit;

        case 'cancel_comanda':
            BarberPro_Bar::cancel_comanda(absint($_GET['comanda_id']));
            wp_redirect(add_query_arg(['bar_tab'=>'comandas'],remove_query_arg(['bar_action','bar_nonce','comanda_id','bar_comanda_id']))); exit;

        case 'save_product':
            $pid=absint($_POST['product_id']??0);
            BarberPro_Bar::save_product(array_merge($_POST,['company_id'=>$company_id]),$pid);
            wp_redirect(add_query_arg(['bar_tab'=>'produtos','bar_saved'=>1],remove_query_arg('bar_action'))); exit;

        case 'delete_product':
            BarberPro_Bar::delete_product(absint($_GET['product_id']));
            wp_redirect(add_query_arg(['bar_tab'=>'produtos'],remove_query_arg(['bar_action','bar_nonce','product_id']))); exit;

        case 'stock_move':
            BarberPro_Bar::stock_move(absint($_POST['product_id']),$_POST);
            wp_redirect(add_query_arg(['bar_tab'=>'estoque','bar_saved'=>1,'view_product'=>absint($_POST['product_id'])],remove_query_arg('bar_action'))); exit;
    }
}

$comanda_id    = absint($_GET['bar_comanda_id']??0);
$comanda       = $comanda_id ? BarberPro_Bar::get_comanda($comanda_id) : null;
$items         = $comanda    ? BarberPro_Bar::get_items($comanda_id)   : [];
$low_stock     = BarberPro_Bar::low_stock($company_id);
$products_list = BarberPro_Bar::get_products($company_id);
$categories    = BarberPro_Bar::get_categories($company_id);
?>
<div class="wrap barberpro-admin" id="bpBarWrap">
<style>
#bpBarWrap{max-width:1200px}
.bar-nav{display:flex;gap:6px;margin-bottom:22px;border-bottom:2px solid #f3f4f6;padding-bottom:0}
.bar-nav a{padding:10px 18px;font-size:.88rem;font-weight:600;color:#6b7280;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s}
.bar-nav a.active{color:#f59e0b;border-bottom-color:#f59e0b}
.bar-nav a:hover{color:#1a1a2e}
.bar-grid{display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start}
.bar-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);margin-bottom:16px}
.bar-card h3{margin:0 0 14px;font-size:.92rem;border-bottom:1px solid #f3f4f6;padding-bottom:10px}
.bf{margin-bottom:11px}
.bf label{display:block;font-size:.76rem;font-weight:600;color:#6b7280;margin-bottom:3px}
.bf input,.bf select,.bf textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.88rem;box-sizing:border-box}
.bf-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.bf-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.btn-prim{background:#f59e0b;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.88rem;width:100%}
.btn-prim:hover{background:#d97706}
.btn-green{background:#10b981;color:#fff;border:none;padding:11px 20px;border-radius:8px;font-weight:700;cursor:pointer;font-size:.9rem;width:100%}
.btn-sm{padding:4px 10px;border-radius:6px;font-size:.76rem;cursor:pointer;border:1px solid #e5e7eb;background:#f9fafb;color:#374151;text-decoration:none}
.btn-danger{border-color:#fecaca;background:#fee2e2;color:#991b1b}
.bar-table{width:100%;border-collapse:collapse;font-size:.84rem}
.bar-table th{text-align:left;padding:7px 8px;background:#f8f9fa;font-size:.71rem;text-transform:uppercase;color:#9ca3af}
.bar-table td{padding:8px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.bar-table tr:last-child td{border:none}
.bar-code{font-family:monospace;font-weight:800;color:#1a1a2e;letter-spacing:1px}
.bar-status{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:700}
.bar-total-box{background:#f8f9fa;border-radius:8px;padding:12px 14px}
.bar-total-row{display:flex;justify-content:space-between;padding:4px 0;font-size:.86rem}
.bar-total-row.final{border-top:2px solid #1a1a2e;margin-top:5px;padding-top:7px;font-size:1.05rem;font-weight:800}
.pmt-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:12px}
.pmt-box{background:#f8f9fa;border-radius:8px;padding:11px}
.pmt-box label{display:block;font-weight:700;font-size:.8rem;margin-bottom:5px}
.pmt-box input{width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;box-sizing:border-box}
.stock-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.76rem;font-weight:700}
.alert-warn{background:#fef3c7;border-left:4px solid #f59e0b;padding:11px 14px;border-radius:8px;color:#78350f;margin-bottom:14px}
.alert-ok{background:#d1fae5;border-left:4px solid #10b981;padding:11px 14px;border-radius:8px;color:#065f46;margin-bottom:14px;font-weight:600}
.alert-err{background:#fee2e2;border-left:4px solid #ef4444;padding:11px 14px;border-radius:8px;color:#991b1b;margin-bottom:14px}
.today-cmd{display:flex;justify-content:space-between;align-items:center;padding:9px 12px;background:#fff;border-radius:9px;margin-bottom:6px;box-shadow:0 1px 4px rgba(0,0,0,.05);cursor:pointer;transition:box-shadow .15s;text-decoration:none}
.today-cmd:hover{box-shadow:0 3px 10px rgba(0,0,0,.1)}
.prod-card{background:#fff;border-radius:10px;padding:14px;box-shadow:0 1px 5px rgba(0,0,0,.05);border:1px solid #f3f4f6;cursor:pointer;transition:all .15s}
.prod-card:hover{border-color:#f59e0b;box-shadow:0 3px 10px rgba(245,158,11,.15)}
.prod-card.selected{border-color:#f59e0b;background:#fffbeb}
.prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;margin-bottom:14px}
@media(max-width:768px){.bar-grid{grid-template-columns:1fr}.pmt-grid{grid-template-columns:1fr}}
@media print{
    body *{visibility:hidden}
    #barComprovante,#barComprovante *{visibility:visible}
    #barComprovante{position:fixed;top:0;left:0;width:80mm;font-family:'Courier New',monospace;font-size:11px;line-height:1.5}
    .bar-no-print{display:none!important}
}
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
        <h1 style="margin:0">🍺 Bar / Eventos</h1>
        <?php if(!empty($low_stock)):?>
        <span style="background:#fef3c7;color:#78350f;padding:3px 10px;border-radius:20px;font-size:.78rem;font-weight:600">
            ⚠️ <?php echo count($low_stock);?> produto(s) com estoque baixo
        </span>
        <?php endif;?>
    </div>
    <?php if($comanda): ?>
    <a href="<?php echo esc_url(add_query_arg(['bar_tab'=>'comandas'],remove_query_arg('bar_comanda_id'),$page_url));?>" class="button bar-no-print">← Voltar</a>
    <?php endif;?>
</div>

<?php if(isset($_GET['bar_paid'])):?><div class="alert-ok">✅ Comanda paga! Receita lançada no financeiro.</div><?php endif;?>
<?php if(isset($_GET['bar_error'])):?><div class="alert-err">⚠️ <?php echo esc_html(urldecode($_GET['bar_error']));?></div><?php endif;?>
<?php if(isset($_GET['bar_saved'])):?><div class="alert-ok">✅ Salvo com sucesso!</div><?php endif;?>

<!-- Navegação -->
<nav class="bar-nav bar-no-print">
    <?php foreach(['comandas'=>'🧾 Comandas','produtos'=>'📦 Produtos','estoque'=>'📊 Estoque'] as $t=>$l):?>
    <a href="<?php echo bar_tab_url($t);?>" class="<?php echo $tab===$t?'active':'';?>"><?php echo esc_html($l);?></a>
    <?php endforeach;?>
</nav>

<?php /* ══════════════════════ TAB: COMANDAS ══════════════════════ */ ?>
<?php if($tab==='comandas'): ?>

<?php if(!$comanda): ?>
<div class="bar-grid">

    <!-- Abrir nova comanda -->
    <div>
        <div class="bar-card">
            <h3>➕ Abrir Comanda</h3>
            <form method="post">
                <?php wp_nonce_field('bar_open_comanda','bar_nonce');?>
                <input type="hidden" name="bar_action" value="open_comanda">
                <div class="bf">
                    <label>Numero da Mesa</label>
                    <input type="text" name="table_number" placeholder="Ex: 1, 2, VIP...">
                </div>
                <div class="bf">
                    <label>Nome do Cliente</label>
                    <input type="text" name="client_name" placeholder="Opcional">
                </div>
                <button type="submit" class="btn-prim">🧾 Abrir Comanda</button>
            </form>
        </div>
    </div>

    <!-- Comandas do dia -->
    <div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <h3 style="margin:0">📋 Comandas de Hoje</h3>
            <span style="font-size:.8rem;color:#9ca3af"><?php $today_cmds=BarberPro_Bar::list_comandas(['company_id'=>$company_id,'date'=>current_time('Y-m-d'),'limit'=>50]); echo count($today_cmds);?> comanda(s)</span>
        </div>
        <?php $status_meta=['aberta'=>['Aberta','#fbbf24','#78350f'],'fechada'=>['Fechada','#3b82f6','#1e40af'],'paga'=>['Paga','#10b981','#065f46'],'cancelada'=>['Cancelada','#ef4444','#991b1b']]; ?>
        <?php foreach($today_cmds as $c):
            [$sl,$sbg,$sfg]=$status_meta[$c->status]??['?','#e5e7eb','#374151'];
            $id_label=trim(($c->table_number?'Mesa '.$c->table_number:'').($c->client_name?' – '.$c->client_name:''));
        ?>
        <a href="<?php echo esc_url(add_query_arg(['bar_tab'=>'comandas','bar_comanda_id'=>$c->id],$page_url));?>" class="today-cmd">
            <div>
                <span class="bar-code">#<?php echo esc_html($c->comanda_code);?></span>
                <span style="color:#6b7280;font-size:.82rem;margin-left:8px"><?php echo esc_html($id_label?:'-');?></span>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <strong><?php echo esc_html(bar_money($c->total_final));?></strong>
                <span class="bar-status" style="background:<?php echo esc_attr($sbg);?>20;color:<?php echo esc_attr($sfg);?>"><?php echo esc_html($sl);?></span>
            </div>
        </a>
        <?php endforeach;?>
        <?php if(empty($today_cmds)):?><p style="color:#9ca3af;text-align:center;padding:20px">Nenhuma comanda hoje.</p><?php endif;?>
    </div>
</div>

<?php else: // COMANDA ABERTA
    $status_meta=['aberta'=>['Aberta','#fbbf24','#78350f'],'fechada'=>['Fechada','#3b82f6','#1e40af'],'paga'=>['Paga','#10b981','#065f46'],'cancelada'=>['Cancelada','#ef4444','#991b1b']];
    [$sl,$sbg,$sfg]=$status_meta[$comanda->status]??['?','#e5e7eb','#374151'];
    $disc_val=$comanda->discount_type==='percentual'?round((float)$comanda->total_items*(float)$comanda->discount/100,2):(float)$comanda->discount;
    $id_label=trim(($comanda->table_number?'Mesa '.$comanda->table_number:'').($comanda->client_name?' – '.$comanda->client_name:''));
?>
<div class="bar-grid">

    <!-- Coluna esquerda -->
    <div>
        <!-- Info -->
        <div class="bar-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <div>
                    <div class="bar-code">#<?php echo esc_html($comanda->comanda_code);?></div>
                    <div style="font-size:.86rem;color:#374151;margin-top:3px"><?php echo esc_html($id_label?:'-');?></div>
                    <div style="font-size:.76rem;color:#9ca3af;margin-top:2px"><?php echo date_i18n('d/m/Y H:i',strtotime($comanda->created_at));?></div>
                </div>
                <span class="bar-status" style="background:<?php echo esc_attr($sbg);?>20;color:<?php echo esc_attr($sfg);?>"><?php echo esc_html($sl);?></span>
            </div>
        </div>

        <?php if($comanda->status==='aberta'): ?>
        <!-- Adicionar produto -->
        <div class="bar-card">
            <h3>➕ Adicionar Produto</h3>
            <?php if(empty($products_list)):?>
            <p style="color:#9ca3af">Nenhum produto cadastrado. <a href="<?php echo bar_tab_url('produtos');?>">Cadastrar →</a></p>
            <?php else: ?>
            <form method="post" id="barAddItem">
                <?php wp_nonce_field('bar_add_item','bar_nonce');?>
                <input type="hidden" name="bar_action" value="add_item">
                <input type="hidden" name="comanda_id" value="<?php echo esc_attr($comanda_id);?>">
                <input type="hidden" name="product_id" id="barProdId" value="">
                <div class="prod-grid" id="barProdGrid">
                    <?php foreach($products_list as $pr):
                        $low=$pr->stock_min>0&&$pr->stock_qty<=$pr->stock_min;?>
                    <div class="prod-card" onclick="barSelectProd(<?php echo $pr->id;?>,'<?php echo esc_js($pr->name);?>',<?php echo $pr->sale_price;?>)"
                         id="prod_<?php echo $pr->id;?>" <?php if($pr->stock_qty<=0) echo 'style="opacity:.4;pointer-events:none"';?>>
                        <div style="font-size:.8rem;color:#9ca3af;margin-bottom:3px"><?php echo esc_html($pr->category??'');?></div>
                        <div style="font-weight:700;font-size:.9rem;margin-bottom:4px"><?php echo esc_html($pr->name);?></div>
                        <div style="color:#10b981;font-weight:800;font-size:.95rem"><?php echo esc_html(bar_money($pr->sale_price));?></div>
                        <div style="font-size:.74rem;margin-top:3px;color:<?php echo $low?'#ef4444':'#6b7280';?>">
                            Estoque: <?php echo number_format($pr->stock_qty,1,',','.');?> <?php echo esc_html($pr->unit);?>
                            <?php if($low):?> ⚠️<?php endif;?>
                        </div>
                    </div>
                    <?php endforeach;?>
                </div>
                <div id="barSelInfo" style="display:none;background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:10px 12px;margin-bottom:10px">
                    <strong id="barSelName" style="font-size:.9rem"></strong>
                    <span id="barSelPrice" style="color:#10b981;font-weight:700;margin-left:8px"></span>
                </div>
                <div class="bf-row">
                    <div class="bf"><label>Quantidade</label><input type="number" name="quantity" id="barQty" value="1" min="0.5" step="0.5" required></div>
                    <div class="bf" style="display:flex;align-items:flex-end"><button type="submit" class="btn-prim" style="margin-bottom:0" id="barAddBtn" disabled>➕ Add</button></div>
                </div>
            </form>
            <?php endif;?>
        </div>

        <!-- Desconto -->
        <div class="bar-card">
            <h3>🏷️ Desconto</h3>
            <div class="bf-row">
                <div class="bf"><label>Tipo</label><select id="barDiscType"><option value="fixo">R$ Fixo</option><option value="percentual">% Pct</option></select></div>
                <div class="bf"><label>Valor</label><input type="text" id="barDiscVal" value="0" placeholder="0"></div>
            </div>
        </div>
        <?php endif; // aberta ?>
    </div>

    <!-- Coluna direita: itens + pagamento -->
    <div>
        <div class="bar-card">
            <h3>🧾 Itens <span style="color:#9ca3af;font-size:.8rem">(<?php echo count($items);?> itens)</span></h3>
            <?php if(empty($items)):?>
            <p style="color:#9ca3af;text-align:center;padding:14px">Nenhum item ainda.</p>
            <?php else:?>
            <table class="bar-table" style="margin-bottom:12px">
                <thead><tr><th>Produto</th><th>Qtd</th><th>Unit.</th><th>Total</th><?php if($comanda->status==='aberta'):?><th></th><?php endif;?></tr></thead>
                <tbody>
                <?php foreach($items as $it):?>
                <tr>
                    <td><strong><?php echo esc_html($it->product_name);?></strong></td>
                    <td><?php echo number_format($it->quantity,1,',','.');?></td>
                    <td><?php echo esc_html(bar_money($it->unit_price));?></td>
                    <td style="font-weight:700"><?php echo esc_html(bar_money($it->total_price));?></td>
                    <?php if($comanda->status==='aberta'):?>
                    <td><a href="<?php echo esc_url(add_query_arg(['bar_action'=>'remove_item','bar_nonce'=>bar_nonce('remove_item'),'comanda_id'=>$comanda_id,'item_id'=>$it->id],$page_url));?>"
                           class="btn-sm btn-danger" onclick="return confirm('Remover?')">🗑</a></td>
                    <?php endif;?>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
            <?php endif;?>

            <div class="bar-total-box">
                <div class="bar-total-row"><span>Subtotal</span><span><?php echo esc_html(bar_money($comanda->total_items));?></span></div>
                <?php if($disc_val>0):?><div class="bar-total-row" style="color:#ef4444"><span>Desconto</span><span>- <?php echo esc_html(bar_money($disc_val));?></span></div><?php endif;?>
                <div class="bar-total-row final"><span>TOTAL</span><span style="color:#10b981"><?php echo esc_html(bar_money($comanda->total_final));?></span></div>
            </div>
        </div>

        <?php if($comanda->status==='aberta'&&!empty($items)):?>
        <div class="bar-card">
            <h3>💳 Pagamento</h3>
            <form method="post" id="barPayForm">
                <?php wp_nonce_field('bar_pay_comanda','bar_nonce');?>
                <input type="hidden" name="bar_action" value="pay_comanda">
                <input type="hidden" name="comanda_id" value="<?php echo esc_attr($comanda_id);?>">
                <input type="hidden" name="discount" id="barDiscHidden" value="0">
                <input type="hidden" name="discount_type" id="barDiscTypeHidden" value="fixo">
                <p style="font-size:.82rem;color:#6b7280;margin:0 0 10px">Distribua o total entre as formas:</p>
                <div class="pmt-grid">
                    <?php foreach(['dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Debito','cartao_credito'=>'Credito'] as $m=>$ml):?>
                    <div class="pmt-box">
                        <label><?php echo esc_html($ml);?></label>
                        <input type="text" name="pmt_<?php echo $m;?>" id="pmt_<?php echo $m;?>" placeholder="0,00" oninput="barCalcPaid()">
                    </div>
                    <?php endforeach;?>
                </div>
                <div style="background:#f0fdf4;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:.86rem">
                    <div style="display:flex;justify-content:space-between;margin-bottom:3px"><span>Total:</span><strong><?php echo esc_html(bar_money($comanda->total_final));?></strong></div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:3px"><span>Informado:</span><strong id="barPaidTotal">R$ 0,00</strong></div>
                    <div style="display:flex;justify-content:space-between;font-weight:700"><span>Troco:</span><span id="barTroco" style="color:#10b981">R$ 0,00</span></div>
                </div>
                <button type="submit" class="btn-green" onclick="return barValidatePay()">✅ Confirmar Pagamento</button>
            </form>
            <div style="margin-top:10px;text-align:center">
                <a href="<?php echo esc_url(add_query_arg(['bar_action'=>'cancel_comanda','bar_nonce'=>bar_nonce('cancel_comanda'),'comanda_id'=>$comanda_id],$page_url));?>"
                   style="color:#ef4444;font-size:.8rem" onclick="return confirm('Cancelar comanda?')">❌ Cancelar</a>
            </div>
        </div>

        <?php elseif($comanda->status==='paga'):?>
        <div class="bar-card">
            <h3>✅ Paga</h3>
            <?php $pmts=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}barber_bar_payments WHERE comanda_id=%d",$comanda_id))??[];
            foreach($pmts as $pmt):?>
            <div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f3f4f6;font-size:.86rem">
                <span><?php echo esc_html($method_labels[$pmt->payment_method]??$pmt->payment_method);?></span>
                <strong><?php echo esc_html(bar_money($pmt->amount));?></strong>
            </div>
            <?php endforeach;?>
            <button onclick="window.print()" class="btn-green bar-no-print" style="margin-top:14px">🖨️ Imprimir</button>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Comprovante 80mm -->
<div id="barComprovante" style="display:none">
    <div style="text-align:center;border-bottom:1px dashed #000;padding-bottom:5px;margin-bottom:5px">
        <strong style="font-size:13px">BAR / EVENTOS</strong><br>
        <small>#<?php echo esc_html($comanda->comanda_code);?></small><br>
        <small><?php echo date_i18n('d/m/Y H:i');?></small>
    </div>
    <?php if($id_label):?><div style="font-size:11px;margin-bottom:5px"><?php echo esc_html($id_label);?></div><?php endif;?>
    <div style="border-bottom:1px dashed #000;margin-bottom:5px">
        <?php foreach($items as $it):?>
        <div style="display:flex;justify-content:space-between;font-size:11px;padding:2px 0">
            <span><?php echo esc_html($it->product_name);?> x<?php echo number_format($it->quantity,1);?></span>
            <span><?php echo esc_html(bar_money($it->total_price));?></span>
        </div>
        <?php endforeach;?>
    </div>
    <?php if($disc_val>0):?><div style="display:flex;justify-content:space-between;font-size:11px"><span>Desconto</span><span>-<?php echo esc_html(bar_money($disc_val));?></span></div><?php endif;?>
    <div style="display:flex;justify-content:space-between;font-weight:bold;font-size:13px;border-top:1px dashed #000;margin-top:3px;padding-top:3px"><span>TOTAL</span><span><?php echo esc_html(bar_money($comanda->total_final));?></span></div>
    <div style="text-align:center;margin-top:8px;font-size:10px;border-top:1px dashed #000;padding-top:4px">Obrigado!</div>
</div>
<script>
window.addEventListener('beforeprint',function(){document.getElementById('barComprovante').style.display='block';});
window.addEventListener('afterprint',function(){document.getElementById('barComprovante').style.display='none';});
</script>
<?php endif; // comanda ?>

<?php /* ══════════════════════ TAB: PRODUTOS ══════════════════════ */ ?>
<?php elseif($tab==='produtos'): ?>
<?php $editing=isset($_GET['edit_prod'])?BarberPro_Bar::get_product(absint($_GET['edit_prod'])):null; ?>
<div class="bar-grid">
    <div class="bar-card">
        <h3><?php echo $editing?'✏️ Editar Produto':'➕ Novo Produto';?></h3>
        <form method="post">
            <?php wp_nonce_field('bar_save_product','bar_nonce');?>
            <input type="hidden" name="bar_action" value="save_product">
            <input type="hidden" name="product_id" value="<?php echo $editing?esc_attr($editing->id):0;?>">
            <div class="bf"><label>Nome *</label><input type="text" name="name" required value="<?php echo esc_attr($editing->name??'');?>"></div>
            <div class="bf-row">
                <div class="bf"><label>Categoria</label><input type="text" name="category" placeholder="Ex: Bebida, Petisco" value="<?php echo esc_attr($editing->category??'');?>"></div>
                <div class="bf"><label>Unidade</label>
                    <select name="unit">
                        <?php foreach(['un'=>'Unidade','ml'=>'ml','l'=>'Litro','g'=>'Grama','kg'=>'KG','cx'=>'Caixa','pct'=>'Pacote'] as $k=>$v):?>
                        <option value="<?php echo $k;?>" <?php selected($editing->unit??'un',$k);?>><?php echo esc_html($v);?></option>
                        <?php endforeach;?>
                    </select>
                </div>
            </div>
            <div class="bf-row">
                <div class="bf"><label>Preco de Custo</label><input type="text" name="cost_price" placeholder="0,00" value="<?php echo $editing?number_format($editing->cost_price,2,',','.'):'';?>"></div>
                <div class="bf"><label>Preco de Venda *</label><input type="text" name="sale_price" placeholder="0,00" required value="<?php echo $editing?number_format($editing->sale_price,2,',','.'):'';?>"></div>
            </div>
            <div class="bf-row">
                <div class="bf"><label>Estoque Minimo ⚠️</label><input type="text" name="stock_min" placeholder="0" value="<?php echo $editing?number_format($editing->stock_min,1,',','.'):'';?>"></div>
                <div class="bf"><label>Estoque Maximo</label><input type="text" name="stock_max" placeholder="0" value="<?php echo $editing?number_format($editing->stock_max,1,',','.'):'';?>"></div>
            </div>
            <div class="bf"><label>Descricao</label><textarea name="description" rows="2" placeholder="Opcional"><?php echo esc_textarea($editing->description??'');?></textarea></div>
            <div class="bf"><label>Imagem do Produto</label>
                <div style="display:flex;align-items:center;gap:10px;margin-top:4px">
                    <img id="bar_photo_preview" src="<?php echo esc_url($editing->photo??'')?>" alt="" style="width:60px;height:60px;object-fit:cover;border-radius:6px;<?php echo ($editing&&!empty($editing->photo))?'':'display:none;'?>border:2px solid #ddd;">
                    <div>
                        <input type="hidden" name="photo" id="bar_photo_url" value="<?php echo esc_attr($editing->photo??'')?>">
                        <button type="button" class="button" id="btn_bar_photo">🖼️ Selecionar Imagem</button>
                        <button type="button" class="button" id="btn_bar_photo_remove" style="<?php echo ($editing&&!empty($editing->photo))?'':'display:none;'?>color:#c00;">✕ Remover</button>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <button type="submit" class="btn-prim"><?php echo $editing?'✅ Salvar':'➕ Cadastrar';?></button>
                <?php if($editing):?><a href="<?php echo bar_tab_url('produtos');?>" class="button">Cancelar</a><?php endif;?>
            </div>
        </form>
    </div>

    <div class="bar-card">
        <h3>📦 Produtos Cadastrados</h3>
        <?php if(empty($products_list)):?><p style="color:#9ca3af">Nenhum produto ainda.</p>
        <?php else:?>
        <table class="bar-table">
            <thead><tr><th>Produto</th><th>Cat.</th><th>Venda</th><th>Estoque</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach($products_list as $pr):
                $low=$pr->stock_min>0&&$pr->stock_qty<=$pr->stock_min;?>
            <tr>
                <td><strong><?php echo esc_html($pr->name);?></strong></td>
                <td style="color:#6b7280;font-size:.8rem"><?php echo esc_html($pr->category??'—');?></td>
                <td style="font-weight:700"><?php echo esc_html(bar_money($pr->sale_price));?></td>
                <td>
                    <span class="stock-badge" style="background:<?php echo $low?'#fee2e2':'#d1fae5';?>;color:<?php echo $low?'#991b1b':'#065f46';?>">
                        <?php echo number_format($pr->stock_qty,1,',','.');?> <?php echo esc_html($pr->unit);?>
                    </span>
                    <?php if($low):?><br><small style="color:#ef4444;font-size:.7rem">Estoque baixo!</small><?php endif;?>
                </td>
                <td>
                    <a href="<?php echo esc_url(add_query_arg(['bar_tab'=>'produtos','edit_prod'=>$pr->id],$page_url));?>" class="btn-sm">✏️</a>
                    <a href="<?php echo esc_url(add_query_arg(['bar_tab'=>'estoque','view_product'=>$pr->id],$page_url));?>" class="btn-sm">📊</a>
                    <a href="<?php echo esc_url(add_query_arg(['bar_action'=>'delete_product','bar_nonce'=>bar_nonce('delete_product'),'product_id'=>$pr->id],$page_url));?>"
                       class="btn-sm btn-danger" onclick="return confirm('Excluir produto?')">🗑</a>
                </td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
        <?php endif;?>
    </div>
</div>

<?php /* ══════════════════════ TAB: ESTOQUE ══════════════════════ */ ?>
<?php elseif($tab==='estoque'): ?>
<?php
$view_prod_id = absint($_GET['view_product']??0);
$view_prod    = $view_prod_id ? BarberPro_Bar::get_product($view_prod_id) : null;
$moves        = $view_prod ? BarberPro_Bar::get_moves($view_prod_id,50) : [];
$all_products = BarberPro_Bar::get_products($company_id);
$move_icons   = ['entrada'=>'📥','saida'=>'📤','ajuste'=>'🔧','transferencia'=>'🔄'];
$move_colors  = ['entrada'=>'#10b981','saida'=>'#ef4444','ajuste'=>'#f59e0b','transferencia'=>'#3b82f6'];
?>

<?php if(!empty($low_stock)):?>
<div class="alert-warn">⚠️ <strong>Estoque baixo:</strong>
    <?php foreach($low_stock as $ls):?>
    <span style="margin-left:8px;background:#fff;padding:2px 8px;border-radius:12px;font-size:.78rem">
        <?php echo esc_html($ls->name);?> (<?php echo number_format($ls->stock_qty,1,',','.');?> <?php echo esc_html($ls->unit);?>)
    </span>
    <?php endforeach;?>
</div>
<?php endif;?>

<div class="bar-grid">
    <div>
        <!-- Movimentação -->
        <div class="bar-card">
            <h3>📥 Registrar Movimentação</h3>
            <form method="post">
                <?php wp_nonce_field('bar_stock_move','bar_nonce');?>
                <input type="hidden" name="bar_action" value="stock_move">
                <div class="bf">
                    <label>Produto *</label>
                    <select name="product_id" required>
                        <option value="">-- Selecione --</option>
                        <?php foreach($all_products as $pr):?>
                        <option value="<?php echo $pr->id;?>" <?php selected($view_prod_id,$pr->id);?>>
                            <?php echo esc_html($pr->name);?> (<?php echo number_format($pr->stock_qty,1,',','.');?> <?php echo esc_html($pr->unit);?>)
                        </option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div class="bf">
                    <label>Tipo *</label>
                    <select name="move_type" id="moveType" onchange="toggleMoveFields(this.value)" required>
                        <option value="entrada">📥 Entrada (compra/recebimento)</option>
                        <option value="ajuste">🔧 Ajuste manual de quantidade</option>
                        <option value="transferencia">🔄 Transferencia para outro modulo</option>
                    </select>
                </div>
                <div class="bf" id="moveQtyField">
                    <label>Quantidade *</label>
                    <input type="text" name="qty" placeholder="0" required>
                </div>
                <div class="bf" id="moveFinalField" style="display:none">
                    <label>Quantidade Final (nova quantidade em estoque)</label>
                    <input type="text" name="qty_final" placeholder="0">
                </div>
                <div id="moveEntradaFields">
                    <div class="bf-row">
                        <div class="bf"><label>Custo Unitario</label><input type="text" name="unit_cost" placeholder="0,00"></div>
                        <div class="bf"><label>Num. Nota/NF</label><input type="text" name="invoice_number" placeholder="Opcional"></div>
                    </div>
                    <div class="bf"><label>Fornecedor</label><input type="text" name="supplier" placeholder="Opcional"></div>
                </div>
                <div class="bf"><label>Observacao</label><input type="text" name="reason" placeholder="Motivo / observacao"></div>
                <button type="submit" class="btn-prim">✅ Registrar</button>
            </form>
        </div>

        <!-- Resumo de estoque -->
        <div class="bar-card">
            <h3>📊 Resumo do Estoque</h3>
            <table class="bar-table">
                <thead><tr><th>Produto</th><th>Qtd</th><th>Min</th></tr></thead>
                <tbody>
                <?php foreach($all_products as $pr):
                    $low=$pr->stock_min>0&&$pr->stock_qty<=$pr->stock_min;?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url(add_query_arg(['bar_tab'=>'estoque','view_product'=>$pr->id],$page_url));?>" style="font-weight:600;color:#1a1a2e;text-decoration:none">
                            <?php echo esc_html($pr->name);?>
                        </a>
                    </td>
                    <td>
                        <span class="stock-badge" style="background:<?php echo $low?'#fee2e2':'#d1fae5';?>;color:<?php echo $low?'#991b1b':'#065f46';?>">
                            <?php echo number_format($pr->stock_qty,1,',','.');?> <?php echo esc_html($pr->unit);?>
                        </span>
                    </td>
                    <td style="color:#6b7280;font-size:.8rem"><?php echo number_format($pr->stock_min,1,',','.');?></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Histórico de movimentações -->
    <div class="bar-card">
        <h3>📋 Historico de Movimentacoes <?php if($view_prod):?><small style="color:#9ca3af">– <?php echo esc_html($view_prod->name);?></small><?php endif;?></h3>
        <?php if(!$view_prod):?>
        <p style="color:#9ca3af">Selecione um produto ao lado para ver o historico, ou clique em 📊 na lista de produtos.</p>
        <?php elseif(empty($moves)):?>
        <p style="color:#9ca3af">Nenhuma movimentacao registrada ainda.</p>
        <?php else:?>
        <table class="bar-table">
            <thead><tr><th>Data</th><th>Tipo</th><th>Qtd</th><th>Antes</th><th>Depois</th><th>Obs</th></tr></thead>
            <tbody>
            <?php foreach($moves as $mv):
                $icon=$move_icons[$mv->move_type]??'•';
                $color=$move_colors[$mv->move_type]??'#374151';
                $qty_fmt=($mv->qty>=0?'+':'').number_format($mv->qty,1,',','.');
            ?>
            <tr>
                <td style="font-size:.78rem;color:#9ca3af;white-space:nowrap"><?php echo date_i18n('d/m H:i',strtotime($mv->created_at));?></td>
                <td><span style="color:<?php echo esc_attr($color);?>;font-weight:700;font-size:.8rem"><?php echo $icon.' '.ucfirst($mv->move_type);?></span></td>
                <td style="color:<?php echo esc_attr($color);?>;font-weight:700"><?php echo esc_html($qty_fmt);?></td>
                <td style="color:#9ca3af;font-size:.8rem"><?php echo number_format($mv->qty_before,1,',','.');?></td>
                <td style="font-weight:600"><?php echo number_format($mv->qty_after,1,',','.');?></td>
                <td style="font-size:.78rem;color:#6b7280">
                    <?php echo esc_html(implode(' ', array_filter([$mv->reason,$mv->supplier,$mv->invoice_number])));?>
                </td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table>
        <?php endif;?>
    </div>
</div>
<?php endif; // tabs ?>

</div>

<script>
var barCmdTotal = <?php echo (float)($comanda->total_final??0);?>;

function barSelectProd(id,name,price){
    document.querySelectorAll('.prod-card').forEach(function(c){c.classList.remove('selected');});
    var card=document.getElementById('prod_'+id);
    if(card) card.classList.add('selected');
    document.getElementById('barProdId').value=id;
    document.getElementById('barSelName').textContent=name;
    document.getElementById('barSelPrice').textContent='R$ '+price.toFixed(2).replace('.',',');
    document.getElementById('barSelInfo').style.display='block';
    document.getElementById('barAddBtn').disabled=false;
}
function barCalcPaid(){
    var methods=['dinheiro','pix','cartao_debito','cartao_credito'];
    var total=0;
    methods.forEach(function(m){
        var el=document.getElementById('pmt_'+m);
        if(el) total+=parseFloat(el.value.replace(',','.')||0);
    });
    document.getElementById('barPaidTotal').textContent='R$ '+total.toFixed(2).replace('.',',');
    var troco=total-barCmdTotal;
    var trel=document.getElementById('barTroco');
    if(trel){trel.textContent='R$ '+Math.max(0,troco).toFixed(2).replace('.',',');trel.style.color=troco<0?'#ef4444':'#10b981';}
}
function barValidatePay(){
    var methods=['dinheiro','pix','cartao_debito','cartao_credito'];
    var total=0;
    methods.forEach(function(m){var el=document.getElementById('pmt_'+m);if(el)total+=parseFloat(el.value.replace(',','.')||0);});
    var dEl=document.getElementById('barDiscVal');
    if(dEl){document.getElementById('barDiscHidden').value=dEl.value.replace(',','.');document.getElementById('barDiscTypeHidden').value=document.getElementById('barDiscType').value;}
    if(total<barCmdTotal-0.01){alert('Valor informado menor que o total da comanda.');return false;}
    return true;
}
function toggleMoveFields(type){
    document.getElementById('moveQtyField').style.display=type==='ajuste'?'none':'';
    document.getElementById('moveFinalField').style.display=type==='ajuste'?'':'none';
    document.getElementById('moveEntradaFields').style.display=type==='entrada'?'':'none';
}
// Pre-fill dinheiro
document.addEventListener('DOMContentLoaded',function(){
    var el=document.getElementById('pmt_dinheiro');
    if(el&&barCmdTotal>0){el.value=barCmdTotal.toFixed(2).replace('.',',');barCalcPaid();}
});
</script>

<script>
jQuery(function($){
    // ── Imagem do produto (Bar) via wp.media ──
    var barMediaFrame;
    $('#btn_bar_photo').on('click', function(e){
        e.preventDefault();
        if(barMediaFrame){ barMediaFrame.open(); return; }
        barMediaFrame = wp.media({ title: 'Selecionar Imagem do Produto', button:{ text:'Usar esta imagem' }, multiple:false });
        barMediaFrame.on('select', function(){
            var att = barMediaFrame.state().get('selection').first().toJSON();
            $('#bar_photo_url').val(att.url);
            $('#bar_photo_preview').attr('src', att.url).show();
            $('#btn_bar_photo_remove').show();
        });
        barMediaFrame.open();
    });
    $('#btn_bar_photo_remove').on('click', function(){
        $('#bar_photo_url').val('');
        $('#bar_photo_preview').attr('src','').hide();
        $(this).hide();
    });
});
</script>

