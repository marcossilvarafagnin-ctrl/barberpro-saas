<?php
/**
 * Template – Loja Virtual BarberPro
 * Shortcode: [barberpro_loja] ou [barberpro_loja company="barbearia"]
 */
if ( ! defined('ABSPATH') ) exit;

$company   = sanitize_key($loja_company ?? 'all');
$cid       = (int)($loja_company_id ?? 1);
$nome_loja = BarberPro_Database::get_setting('shop_nome', get_bloginfo('name') . ' — Loja');
$categorias= BarberPro_Shop::get_categories($cid);
$payment_gw= BarberPro_Payment::get_active_gateways();
$pay_when  = BarberPro_Database::get_setting('online_payment_when','optional');
$frete_tipo= BarberPro_Database::get_setting('shop_frete_tipo','fixo');
$frete_fixo= (float)BarberPro_Database::get_setting('shop_frete_fixo','10');
$frete_min = (float)BarberPro_Database::get_setting('shop_frete_gratis_minimo','0');
?>
<div id="bpLoja" class="bp-loja"
     data-company="<?php echo esc_attr($company); ?>"
     data-cid="<?php echo esc_attr($cid); ?>"
     data-ajaxurl="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
     data-nonce="<?php echo esc_attr(wp_create_nonce('bp_loja')); ?>"
     data-frete-tipo="<?php echo esc_attr($frete_tipo); ?>"
     data-frete-fixo="<?php echo esc_attr($frete_fixo); ?>"
     data-frete-gratis="<?php echo esc_attr($frete_min); ?>">

    <!-- ── Topo ── -->
    <?php
    // Título: oculto por padrão. Para exibir: [barberpro_loja show_title="1"]
    $show_title = ! empty($loja_show_title) && $loja_show_title !== '0';
    $show_title = apply_filters('barberpro_loja_show_title', $show_title);
    ?>
    <?php if ($show_title || $frete_min > 0): ?>
    <div class="bp-loja-header">
        <?php if ($show_title): ?>
        <h2 class="bp-loja-title"><?php echo esc_html($nome_loja); ?></h2>
        <?php endif; ?>
        <?php if ($frete_min > 0): ?>
        <div class="bp-loja-banner">
            🎉 Frete Grátis em compras acima de R$ <?php echo number_format($frete_min,2,',','.'); ?>!
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Layout principal ── -->
    <div class="bp-loja-layout">

        <!-- Sidebar filtros -->
        <aside class="bp-loja-sidebar">
            <!-- Busca -->
            <div class="bp-loja-search-wrap">
                <input type="text" id="bpLojaSearch" placeholder="🔍 Buscar produto..."
                       oninput="bpLojaFiltrar()" class="bp-loja-search"
                       autocomplete="off" spellcheck="false">
            </div>

            <!-- Categorias com contagem -->
            <?php
            // Conta produtos por categoria para mostrar o número
            $count_por_cat = [''=>0];
            foreach ($categorias as $cat) $count_por_cat[$cat] = 0;
            foreach ($produtos as $p) {
                $count_por_cat['']++;
                $cat_p = $p->category ?? '';
                if ($cat_p && isset($count_por_cat[$cat_p])) $count_por_cat[$cat_p]++;
            }
            ?>
            <div class="bp-loja-cats" id="bpLojaCats">
                <div class="bp-loja-cat-item active" data-cat="" onclick="bpLojaCat(this,'')">
                    Todos
                    <span class="bp-loja-cat-count"><?php echo $count_por_cat['']; ?></span>
                </div>
                <?php foreach ($categorias as $cat):
                    $cnt = $count_por_cat[$cat] ?? 0;
                ?>
                <div class="bp-loja-cat-item"
                     data-cat="<?php echo esc_attr($cat); ?>"
                     onclick="bpLojaCat(this,'<?php echo esc_js($cat); ?>')">
                    <?php echo esc_html($cat); ?>
                    <span class="bp-loja-cat-count"><?php echo $cnt; ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Mini carrinho (apenas desktop) -->
            <div class="bp-loja-mini-cart" id="bpLojaMiniCart" style="display:none">
                <div class="bp-loja-mini-cart-title">🛒 Carrinho</div>
                <div id="bpLojaMiniCartItems"></div>
                <div class="bp-loja-mini-cart-total">
                    Total: <strong id="bpLojaMiniCartTotal">R$ 0,00</strong>
                </div>
                <button class="bp-loja-btn-checkout" onclick="bpLojaAbrirCheckout()">
                    Finalizar Pedido →
                </button>
            </div>
        </aside>

        <!-- Grid de produtos -->
        <main class="bp-loja-main">
            <div class="bp-loja-toolbar">
                <span id="bpLojaCount" class="bp-loja-count"></span>
                <button class="bp-loja-cart-btn" id="bpLojaCartBtn" onclick="bpLojaAbrirCheckout()" style="display:none">
                    🛒 Ver Carrinho (<span id="bpLojaCartQty">0</span>)
                </button>
            </div>
            <div class="bp-loja-grid" id="bpLojaGrid">
                <?php
                $produtos = BarberPro_Shop::get_products(['company_id'=>$cid,'in_stock'=>false]);
                foreach ($produtos as $p):
                    $foto     = $p->photo ?: '';
                    $sem_estoque = (float)$p->stock_qty <= 0;
                ?>
                <div class="bp-loja-card <?php echo $sem_estoque?'out-of-stock':''; ?>"
                     data-cat="<?php echo esc_attr($p->category??''); ?>"
                     data-name="<?php echo esc_attr(mb_strtolower($p->name)); ?>"
                     data-id="<?php echo $p->id; ?>">
                    <div class="bp-loja-card-img">
                        <?php if ($foto): ?>
                        <img src="<?php echo esc_url($foto); ?>"
                             alt="<?php echo esc_attr($p->name); ?>"
                             loading="lazy"
                             style="opacity:0;transition:opacity .25s;width:100%;height:100%;object-fit:cover"
                             onload="this.style.opacity='1'">
                        <?php else: ?>
                        <div class="bp-loja-card-img-placeholder">🛍️</div>
                        <?php endif; ?>
                        <?php if ($sem_estoque): ?>
                        <div class="bp-loja-badge-out">Esgotado</div>
                        <?php endif; ?>
                        <?php if ($p->category): ?>
                        <div class="bp-loja-badge-cat"><?php echo esc_html($p->category); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="bp-loja-card-body">
                        <h3 class="bp-loja-card-name"><?php echo esc_html($p->name); ?></h3>
                        <?php if ($p->description): ?>
                        <p class="bp-loja-card-desc"><?php echo esc_html(mb_substr($p->description,0,80)); ?>...</p>
                        <?php endif; ?>
                        <div class="bp-loja-card-footer">
                            <span class="bp-loja-card-price">R$ <?php echo number_format((float)$p->sale_price,2,',','.'); ?></span>
                            <?php if (!$sem_estoque): ?>
                            <button class="bp-loja-btn-add"
                                    onclick="bpLojaAdd(<?php echo $p->id; ?>,'<?php echo esc_js($p->name); ?>',<?php echo (float)$p->sale_price; ?>,this)"
                                    data-max="<?php echo (float)$p->stock_qty; ?>">
                                + Adicionar
                            </button>
                            <?php else: ?>
                            <span class="bp-loja-badge-out-sm">Esgotado</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($produtos)): ?>
                <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:#6b7280">
                    <div style="font-size:3rem;margin-bottom:12px">🛍️</div>
                    <div style="font-weight:600">Nenhum produto disponível</div>
                    <div style="font-size:.88rem;margin-top:6px">Volte em breve!</div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- ── Modal Checkout ── -->
    <div id="bpLojaModal" class="bp-loja-modal-overlay" style="display:none" onclick="if(event.target===this)bpLojaFecharCheckout()">
        <div class="bp-loja-modal">
            <div class="bp-loja-modal-header">
                <h3 id="bpLojaModalTitle">🛒 Seu Pedido</h3>
                <button class="bp-loja-modal-close" onclick="bpLojaFecharCheckout()">✕</button>
            </div>
            <div class="bp-loja-modal-body" id="bpLojaModalBody">
                <!-- Conteúdo dinâmico por step -->
            </div>
        </div>
    </div>

    <!-- ── Toast ── -->
    <div id="bpLojaToast" class="bp-loja-toast" style="display:none"></div>

</div>

<script>
(function(){
'use strict';
var CART = JSON.parse(localStorage.getItem('bp_cart_<?php echo esc_js($company); ?>') || '[]');
var CHECKOUT_STEP = 1;
var FRETE = { cost:0, label:'' };
var DATA = {};

// ── Carrinho ─────────────────────────────────────
window.bpLojaAdd = function(id, name, price, btn) {
    var max  = parseFloat(btn.dataset.max) || 999;
    var idx  = CART.findIndex(function(i){ return i.id === id; });
    if (idx >= 0) {
        if (CART[idx].qty >= max) { bpLojaToast('Estoque máximo atingido'); return; }
        CART[idx].qty++;
    } else {
        CART.push({id:id, name:name, price:price, qty:1, max:max});
    }
    saveCart();
    bpLojaToast('✓ ' + name + ' adicionado!');
    btn.textContent = 'Adicionado ✓';
    btn.style.background = '#10b981';
    setTimeout(function(){ btn.textContent = '+ Adicionar'; btn.style.background = ''; }, 1500);
};

function saveCart() {
    localStorage.setItem('bp_cart_<?php echo esc_js($company); ?>', JSON.stringify(CART));
    updateCartUI();
}

function updateCartUI() {
    var total = CART.reduce(function(s,i){ return s + i.price * i.qty; }, 0);
    var qty   = CART.reduce(function(s,i){ return s + i.qty; }, 0);
    var mini  = document.getElementById('bpLojaMiniCart');
    var cartBtn = document.getElementById('bpLojaCartBtn');
    if (mini) mini.style.display = qty > 0 ? '' : 'none';
    if (cartBtn) { cartBtn.style.display = qty > 0 ? '' : 'none'; document.getElementById('bpLojaCartQty').textContent = qty; }
    var itemsEl = document.getElementById('bpLojaMiniCartItems');
    if (itemsEl) {
        itemsEl.innerHTML = CART.map(function(i){
            return '<div class="bp-mini-cart-item">'
                 + '<span>' + escHtml(i.name) + ' x' + i.qty + '</span>'
                 + '<div style="display:flex;align-items:center;gap:6px">'
                 + '<button class="bp-mini-qty" onclick="bpLojaQty('+i.id+',-1)">-</button>'
                 + '<button class="bp-mini-qty" onclick="bpLojaQty('+i.id+',1)">+</button>'
                 + '<button class="bp-mini-rm" onclick="bpLojaRm('+i.id+')">🗑</button>'
                 + '</div></div>';
        }).join('');
    }
    var totalEl = document.getElementById('bpLojaMiniCartTotal');
    if (totalEl) totalEl.textContent = 'R$ ' + total.toFixed(2).replace('.',',');
}

window.bpLojaQty = function(id, delta) {
    var idx = CART.findIndex(function(i){ return i.id === id; });
    if (idx < 0) return;
    CART[idx].qty = Math.max(1, Math.min(CART[idx].qty + delta, CART[idx].max || 999));
    saveCart();
};
window.bpLojaRm = function(id) {
    CART = CART.filter(function(i){ return i.id !== id; });
    saveCart();
    if (CHECKOUT_STEP > 1) renderStep1();
};

// ── Filtros ────────────────────────────────────
window.bpLojaCat = function(el, cat) {
    document.querySelectorAll('.bp-loja-cat-item').forEach(function(e){ e.classList.remove('active'); });
    el.classList.add('active');
    filtrar(cat, document.getElementById('bpLojaSearch').value);
};
window.bpLojaFiltrar = function() {
    var cat = (document.querySelector('.bp-loja-cat-item.active')||{}).dataset && document.querySelector('.bp-loja-cat-item.active').dataset.cat || '';
    filtrar(cat, document.getElementById('bpLojaSearch').value);
};
function filtrar(cat, search) {
    var cards   = document.querySelectorAll('.bp-loja-card');
    var visible = 0;
    var sl      = search.toLowerCase().trim();
    cards.forEach(function(c) {
        var showCat    = !cat || c.dataset.cat === cat;
        var showSearch = !sl  || c.dataset.name.includes(sl);
        var show       = showCat && showSearch;
        c.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var el = document.getElementById('bpLojaCount');
    if (el) el.textContent = visible + ' produto' + (visible !== 1 ? 's' : '');
}

// ── Checkout modal ──────────────────────────────
window.bpLojaAbrirCheckout = function() {
    if (CART.length === 0) { bpLojaToast('Seu carrinho está vazio'); return; }
    CHECKOUT_STEP = 1;
    document.getElementById('bpLojaModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    renderStep1();
};
window.bpLojaFecharCheckout = function() {
    document.getElementById('bpLojaModal').style.display = 'none';
    document.body.style.overflow = '';
};

function renderStep1() {
    CHECKOUT_STEP = 1;
    document.getElementById('bpLojaModalTitle').textContent = '🛒 Seu Pedido';
    var total = CART.reduce(function(s,i){ return s+i.price*i.qty; },0);
    var rows  = CART.map(function(i){
        return '<div class="bp-co-item"><span><strong>'+escHtml(i.name)+'</strong> x'+i.qty+'</span>'
             + '<div style="display:flex;align-items:center;gap:6px">'
             + '<button class="bp-mini-qty" onclick="bpLojaQty('+i.id+',-1);renderStep1()">-</button>'
             + '<button class="bp-mini-qty" onclick="bpLojaQty('+i.id+',1);renderStep1()">+</button>'
             + '<button class="bp-mini-rm" onclick="bpLojaRm('+i.id+');renderStep1()">🗑</button>'
             + '<span class="bp-co-price">R$ '+(i.price*i.qty).toFixed(2).replace('.',',')+'</span>'
             + '</div></div>';
    }).join('');
    document.getElementById('bpLojaModalBody').innerHTML =
        '<div class="bp-co-items">'+rows+'</div>'
        +'<div class="bp-co-total">Subtotal: <strong>R$ '+total.toFixed(2).replace('.',',')+'</strong></div>'
        +'<div class="bp-co-actions">'
        +'<button class="bp-loja-btn-sec" onclick="bpLojaFecharCheckout()">← Continuar</button>'
        +'<button class="bp-loja-btn-primary" onclick="renderStep2()">Prosseguir →</button>'
        +'</div>';
}

window.renderStep2 = function() {
    CHECKOUT_STEP = 2;
    document.getElementById('bpLojaModalTitle').textContent = '📦 Entrega';
    document.getElementById('bpLojaModalBody').innerHTML =
        '<div class="bp-co-section">'
        +'<div class="bp-co-delivery-opts">'
        +'<label class="bp-co-delivery-opt"><input type="radio" name="bpEntrega" value="retirada" checked onchange="bpEntregaToggle()"><span><strong>🏪 Retirar na loja</strong><small>Sem frete</small></span></label>'
        +'<label class="bp-co-delivery-opt"><input type="radio" name="bpEntrega" value="entrega" onchange="bpEntregaToggle()"><span><strong>🚚 Receber em casa</strong><small>Informe o CEP</small></span></label>'
        +'</div>'
        +'<div id="bpFreteWrap" style="display:none;margin-top:14px">'
        +'<div class="bp-co-field"><label>CEP *</label>'
        +'<div style="display:flex;gap:8px"><input type="text" id="bpLojaCep" maxlength="9" placeholder="00000-000" oninput="this.value=this.value.replace(/\D/g,\'\').replace(/(\d{5})(\d)/,\'$1-$2\')" class="bp-co-input" style="flex:1">'
        +'<button class="bp-loja-btn-sm" onclick="bpCalcFrete()">Calcular</button></div></div>'
        +'<div id="bpFreteResult" style="margin-top:10px;font-size:.85rem;padding:8px 12px;border-radius:8px;display:none"></div>'
        +'<div id="bpEnderecoWrap" style="display:none;margin-top:14px">'
        +'<div class="bp-co-field-row"><div class="bp-co-field" style="flex:3"><label>Rua *</label><input type="text" id="bpLRua" class="bp-co-input" placeholder="Nome da rua"></div>'
        +'<div class="bp-co-field" style="flex:1"><label>Número *</label><input type="text" id="bpLNum" class="bp-co-input" placeholder="123"></div></div>'
        +'<div class="bp-co-field-row"><div class="bp-co-field"><label>Bairro</label><input type="text" id="bpLBairro" class="bp-co-input"></div>'
        +'<div class="bp-co-field"><label>Cidade *</label><input type="text" id="bpLCidade" class="bp-co-input"></div>'
        +'<div class="bp-co-field" style="max-width:70px"><label>UF *</label><input type="text" id="bpLUF" maxlength="2" class="bp-co-input" style="text-transform:uppercase"></div></div>'
        +'</div>'
        +'</div>'
        +'</div>'
        +'<div class="bp-co-actions">'
        +'<button class="bp-loja-btn-sec" onclick="renderStep1()">← Voltar</button>'
        +'<button class="bp-loja-btn-primary" onclick="renderStep3()">Continuar →</button>'
        +'</div>';
}

window.bpEntregaToggle = function() {
    var tipo = document.querySelector('input[name="bpEntrega"]:checked');
    if (!tipo) return;
    // .selected para fallback Firefox (sem suporte a :has)
    document.querySelectorAll('.bp-co-delivery-opt').forEach(function(el) {
        el.classList.toggle('selected', el.querySelector('input:checked') !== null);
    });
    tipo = tipo.value;
    document.getElementById('bpFreteWrap').style.display = tipo === 'entrega' ? '' : 'none';
    FRETE = tipo === 'retirada' ? {cost:0,label:'Retirada na loja'} : {cost:0,label:''};
};

window.bpCalcFrete = function() {
    var cep = document.getElementById('bpLojaCep').value.replace(/\D/g,'');
    if (cep.length < 8) { bpLojaToast('CEP inválido'); return; }
    var result = document.getElementById('bpFreteResult');
    result.style.display=''; result.textContent='⏳ Calculando...'; result.className='bp-co-frete-calc';

    var subtotal = CART.reduce(function(s,i){ return s+i.price*i.qty; },0);
    ajaxLoja({action:'bp_loja_frete',cep:cep,subtotal:subtotal.toFixed(2)}, function(d){
        if (d.success) {
            FRETE = {cost:d.data.cost,label:d.data.label};
            result.innerHTML = '✅ '+escHtml(d.data.label);
            result.className = 'bp-co-frete-ok';
            // Preenche endereço via CEP se disponível
            if (d.data.address) {
                document.getElementById('bpEnderecoWrap').style.display='';
                if (d.data.address.logradouro) document.getElementById('bpLRua').value=d.data.address.logradouro;
                if (d.data.address.bairro)     document.getElementById('bpLBairro').value=d.data.address.bairro;
                if (d.data.address.localidade) document.getElementById('bpLCidade').value=d.data.address.localidade;
                if (d.data.address.uf)         document.getElementById('bpLUF').value=d.data.address.uf;
            } else {
                document.getElementById('bpEnderecoWrap').style.display='';
            }
        } else {
            result.innerHTML = '❌ '+(d.data&&d.data.message||'CEP não atendido');
            result.className = 'bp-co-frete-err';
            FRETE = {cost:-1,label:''};
        }
    });
};

window.renderStep3 = function() {
    var tipo = document.querySelector('input[name="bpEntrega"]') ? document.querySelector('input[name="bpEntrega"]:checked').value : 'retirada';
    if (tipo === 'entrega') {
        if (FRETE.cost < 0) { bpLojaToast('Calcule o frete antes de continuar'); return; }
        if (!FRETE.label)   { bpLojaToast('Calcule o frete antes de continuar'); return; }
        var rua = document.getElementById('bpLRua'), num = document.getElementById('bpLNum');
        if (!rua||!rua.value.trim()||!num||!num.value.trim()) { bpLojaToast('Preencha rua e número'); return; }
        DATA.delivery_type    = 'entrega';
        DATA.shipping_cost    = FRETE.cost;
        DATA.address_street   = rua.value.trim();
        DATA.address_number   = num.value.trim();
        DATA.address_neighborhood = (document.getElementById('bpLBairro')||{}).value||'';
        DATA.address_city     = (document.getElementById('bpLCidade')||{}).value||'';
        DATA.address_state    = (document.getElementById('bpLUF')||{}).value||'';
        DATA.address_zip      = (document.getElementById('bpLojaCep')||{}).value||'';
    } else {
        DATA.delivery_type = 'retirada';
        DATA.shipping_cost = 0;
    }
    CHECKOUT_STEP = 3;
    renderStep3Body();
}

window.renderStep3Body = function() {
    document.getElementById('bpLojaModalTitle').textContent = '👤 Seus Dados';
    document.getElementById('bpLojaModalBody').innerHTML =
        '<div class="bp-co-section">'
        +'<div class="bp-co-field"><label>Nome completo *</label><input type="text" id="bpLNome" class="bp-co-input" value="'+(DATA.client_name||'')+'"></div>'
        +'<div class="bp-co-field"><label>Telefone / WhatsApp *</label><input type="tel" id="bpLTel" class="bp-co-input" placeholder="(44) 99999-0000" value="'+(DATA.client_phone||'')+'"></div>'
        +'<div class="bp-co-field"><label>E-mail</label><input type="email" id="bpLEmail" class="bp-co-input" placeholder="Opcional" value="'+(DATA.client_email||'')+'"></div>'
        +'<div class="bp-co-field"><label>Observações</label><textarea id="bpLObs" class="bp-co-input" rows="2" style="resize:vertical">'+(DATA.notes||'')+'</textarea></div>'
        +'</div>'
        +'<div class="bp-co-actions">'
        +'<button class="bp-loja-btn-sec" onclick="renderStep2()">← Voltar</button>'
        +'<button class="bp-loja-btn-primary" onclick="renderStep4()">Ver Resumo →</button>'
        +'</div>';
}

window.renderStep4 = function() {
    var nome = (document.getElementById('bpLNome')||{}).value||'';
    var tel  = (document.getElementById('bpLTel') ||{}).value||'';
    if (!nome.trim()) { bpLojaToast('Informe seu nome'); return; }
    if (!tel.trim())  { bpLojaToast('Informe seu telefone'); return; }
    DATA.client_name  = nome.trim();
    DATA.client_phone = tel.trim();
    DATA.client_email = (document.getElementById('bpLEmail')||{}).value||'';
    DATA.notes        = (document.getElementById('bpLObs')  ||{}).value||'';

    CHECKOUT_STEP = 4;
    document.getElementById('bpLojaModalTitle').textContent = '✅ Resumo do Pedido';

    var sub     = CART.reduce(function(s,i){ return s+i.price*i.qty; },0);
    var total   = sub + (DATA.shipping_cost||0);
    var itens   = CART.map(function(i){ return '<div class="bp-co-item"><span>'+escHtml(i.name)+' x'+i.qty+'</span><span>R$ '+(i.price*i.qty).toFixed(2).replace('.',',')+'</span></div>'; }).join('');
    var frete_html = DATA.delivery_type==='entrega'
        ? '<div class="bp-co-item"><span>🚚 Frete</span><span>R$ '+(DATA.shipping_cost||0).toFixed(2).replace('.',',')+'</span></div>'
        : '<div class="bp-co-item"><span>🏪 Retirada na loja</span><span>Grátis</span></div>';
    var end_html = DATA.delivery_type==='entrega'
        ? '<div class="bp-co-addr">📍 '+escHtml(DATA.address_street+', '+DATA.address_number+(DATA.address_city?' — '+DATA.address_city+'/'+DATA.address_state:''))+'</div>'
        : '';

    // Opções de pagamento
    var pay_html = renderPaymentOptions();

    document.getElementById('bpLojaModalBody').innerHTML =
        '<div class="bp-co-section">'
        +'<div class="bp-co-items">'+itens+frete_html+'</div>'
        +'<div class="bp-co-total">Total: <strong>R$ '+total.toFixed(2).replace('.',',')+'</strong></div>'
        +end_html
        +'<div style="margin-top:14px"><div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px">Forma de Pagamento</div>'
        +pay_html+'</div>'
        +'</div>'
        +'<div class="bp-co-actions">'
        +'<button class="bp-loja-btn-sec" onclick="renderStep3Body()">← Voltar</button>'
        +'<button class="bp-loja-btn-confirm" id="bpLojaConfirmBtn" onclick="bpLojaConfirmar()">✅ Confirmar Pedido</button>'
        +'</div>';
}

function renderPaymentOptions() {
    var opts = <?php echo wp_json_encode(array_merge(['presencial'=>'💵 Pagar na retirada/entrega'], $payment_gw)); ?>;
    return Object.entries(opts).map(function(e){
        var k=e[0],v=e[1];
        return '<label class="bp-co-pay-opt"><input type="radio" name="bpPagamento" value="'+k+'" '+(k==='presencial'?'checked':'')+'> '+escHtml(v)+'</label>';
    }).join('');
}

window.bpLojaConfirmar = function() {
    var payMethod = document.querySelector('input[name="bpPagamento"]:checked');
    DATA.payment_method = payMethod ? payMethod.value : 'presencial';

    var btn = document.getElementById('bpLojaConfirmBtn');
    btn.disabled = true; btn.textContent = '⏳ Processando...';

    var sub = CART.reduce(function(s,i){ return s+i.price*i.qty; },0);
    ajaxLoja({
        action:   'bp_loja_pedido',
        items:    JSON.stringify(CART.map(function(i){ return {product_id:i.id,product_name:i.name,price:i.price,qty:i.qty}; })),
        client_name:  DATA.client_name,
        client_phone: DATA.client_phone,
        client_email: DATA.client_email,
        delivery_type:    DATA.delivery_type,
        shipping_cost:    DATA.shipping_cost||0,
        address_street:   DATA.address_street||'',
        address_number:   DATA.address_number||'',
        address_neighborhood: DATA.address_neighborhood||'',
        address_city:     DATA.address_city||'',
        address_state:    DATA.address_state||'',
        address_zip:      DATA.address_zip||'',
        payment_method:   DATA.payment_method,
        notes:            DATA.notes||'',
        cid:              document.getElementById('bpLoja').dataset.cid,
        subtotal:         sub.toFixed(2),
    }, function(d) {
        if (d.success) {
            CART = []; saveCart();
            var total = parseFloat(d.data.total)||0;
            document.getElementById('bpLojaModalTitle').textContent = '🎉 Pedido Realizado!';
            document.getElementById('bpLojaModalBody').innerHTML =
                '<div class="bp-co-success">'
                +'<div class="bp-co-success-icon">✅</div>'
                +'<h3>Pedido confirmado!</h3>'
                +'<p>Código: <strong>#'+escHtml(d.data.order_code)+'</strong></p>'
                +'<p>Total: <strong>R$ '+total.toFixed(2).replace('.',',')+'</strong></p>'
                +(DATA.client_email?'<p>Confirmação enviada para <strong>'+escHtml(DATA.client_email)+'</strong></p>':'')
                +'<button class="bp-loja-btn-primary" style="margin-top:16px" onclick="bpLojaFecharCheckout()">Fechar</button>'
                +'</div>';

            // Exibe QR PIX se for PIX estático
            if (d.data.pix_payload) {
                document.getElementById('bpLojaModalBody').innerHTML +=
                    '<div class="bp-co-pix">'
                    +'<p style="font-weight:700;margin-bottom:8px">⚡ Pague via PIX:</p>'
                    +'<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='+encodeURIComponent(d.data.pix_payload)+'" style="border-radius:8px">'
                    +'<div style="margin-top:10px;display:flex;gap:6px"><input type="text" value="'+escHtml(d.data.pix_payload)+'" readonly class="bp-co-input" style="flex:1;font-size:.72rem">'
                    +'<button onclick="navigator.clipboard.writeText(\''+d.data.pix_payload+'\');this.textContent=\'✓\'" class="bp-loja-btn-sm">Copiar</button></div>'
                    +'</div>';
            }
        } else {
            btn.disabled = false; btn.textContent = '✅ Confirmar Pedido';
            bpLojaToast('Erro: '+((d.data&&d.data.message)||'tente novamente'), 'error');
        }
    });
};

// ── Helpers ─────────────────────────────────────
function ajaxLoja(params, cb) {
    var wrap = document.getElementById('bpLoja');
    var fd   = new FormData();
    Object.entries(Object.assign({nonce: wrap.dataset.nonce}, params)).forEach(function(e){ fd.append(e[0],e[1]); });
    fetch(wrap.dataset.ajaxurl, {method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){return r.json();}).then(cb)
        .catch(function(){ cb({success:false,data:{message:'Erro de conexão'}}); });
}

window.bpLojaToast = function(msg, type) {
    var t = document.getElementById('bpLojaToast');
    t.textContent = msg;
    t.className   = 'bp-loja-toast ' + (type||'');
    t.style.display = 'block';
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.style.display='none'; }, 2800);
};

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Init
updateCartUI();
var count = document.querySelectorAll('.bp-loja-card').length;
var el = document.getElementById('bpLojaCount');
if (el) el.textContent = count + ' produto' + (count !== 1 ? 's' : '');
})();
</script>
