<?php
/**
 * BarberPro – Seções da Loja Virtual no Painel Admin (SPA)
 * Cada módulo tem produtos, estoque e pedidos completamente separados por company_id.
 */
if ( ! defined('ABSPATH') ) exit;

trait BP_Sections_Loja {

    private function loja_mod_label( int $cid ): string {
        return $cid === 1 ? '✂️ Barbearia' : '🚗 Lava-Car';
    }
    private function loja_nav_prefix( int $cid ): string {
        return $cid === 1 ? 'barbearia' : 'lavacar';
    }

    // ── Produtos ────────────────────────────────────────────────
    private function section_loja_produtos( int $cid ): void {
        $mod      = $this->loja_mod_label($cid);
        $prefix   = $this->loja_nav_prefix($cid);
        $produtos = BarberPro_Shop::get_products(['company_id' => $cid, 'in_stock' => false]);

        $ativos    = array_filter($produtos, function($p) { return (float)$p->stock_qty > 0 && $p->shop_active; });
        $esgotados = array_filter($produtos, function($p) { return (float)$p->stock_qty <= 0 && $p->shop_active; });
        $inativos  = array_filter($produtos, function($p) { return !$p->shop_active; });
        $total_val = array_sum(array_map(function($p) { return (float)$p->sale_price * (float)$p->stock_qty; }, $produtos));
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">🛍️ Loja — Produtos <small style="font-size:.6em;opacity:.7"><?php echo $mod; ?></small></div>
                <div class="bp-page-subtitle">Shortcode: <code>[barberpro_loja company="<?php echo $prefix; ?>"]</code></div>
            </div>
            <button class="bp-btn bp-btn-primary" onclick="bpLojaOpenProduto(0,<?php echo $cid; ?>)">+ Novo Produto</button>
        </div>

        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px" class="bp-stagger">
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--green)"><?php echo count($ativos); ?></div><div class="bp-kpi-mini-lbl">Disponíveis</div></div>
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--red)"><?php echo count($esgotados); ?></div><div class="bp-kpi-mini-lbl">Esgotados</div></div>
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--text3)"><?php echo count($inativos); ?></div><div class="bp-kpi-mini-lbl">Ocultos na Loja</div></div>
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--accent);font-size:.85rem">R$ <?php echo number_format($total_val,2,',','.'); ?></div><div class="bp-kpi-mini-lbl">Valor em Estoque</div></div>
        </div>

        <div class="bp-card bp-animate-in">
            <div class="bp-card-header">
                <div class="bp-card-title">Catálogo — <?php echo $mod; ?></div>
                <input type="text" id="bpLojaSearch_<?php echo $cid; ?>" placeholder="🔍 Buscar..."
                       oninput="bpLojaFiltrarAdmin(<?php echo $cid; ?>)"
                       style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:7px 12px;color:var(--text);font-size:.84rem;width:200px">
            </div>
            <?php if (empty($produtos)): ?>
            <div class="bp-empty">
                <div class="bp-empty-icon">🛍️</div>
                <div class="bp-empty-title">Nenhum produto cadastrado</div>
                <div class="bp-empty-text">Clique em "+ Novo Produto" para começar a montar o catálogo da <?php echo $mod; ?></div>
            </div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table" id="bpLojaProdTable_<?php echo $cid; ?>">
                <thead><tr><th>Foto</th><th>Produto</th><th>Categoria</th><th>Visível</th><th>Preço</th><th>Estoque</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($produtos as $p):
                    $foto = $p->photo ?? '';
                    $sem  = (float)$p->stock_qty <= 0;
                    $low  = !$sem && $p->stock_min > 0 && (float)$p->stock_qty <= (float)$p->stock_min;
                ?>
                <tr data-name="<?php echo esc_attr(mb_strtolower($p->name)); ?>">
                    <td style="width:50px">
                        <?php if ($foto): ?>
                        <img src="<?php echo esc_url($foto); ?>" style="width:40px;height:40px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
                        <?php else: ?>
                        <div style="width:40px;height:40px;background:var(--bg3);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1.2rem">🛍️</div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($p->name); ?></strong>
                        <?php if ($p->sku): ?><br><small style="color:var(--text3)">SKU: <?php echo esc_html($p->sku); ?></small><?php endif; ?>
                    </td>
                    <td style="color:var(--text2);font-size:.85rem"><?php echo esc_html($p->category ?: '—'); ?></td>
                    <td>
                        <span class="bp-badge bp-badge-<?php echo $p->shop_active ? 'green' : 'gray'; ?>">
                            <?php echo $p->shop_active ? '● Visível' : '○ Oculto'; ?>
                        </span>
                    </td>
                    <td style="font-weight:700;color:var(--accent)">R$ <?php echo number_format((float)$p->sale_price,2,',','.'); ?></td>
                    <td>
                        <?php if ($sem): ?>
                            <span class="bp-badge bp-badge-red">⚠️ Esgotado</span>
                        <?php elseif ($low): ?>
                            <span class="bp-badge bp-badge-amber"><?php echo number_format((float)$p->stock_qty,0); ?> ⚠️ baixo</span>
                        <?php else: ?>
                            <span class="bp-badge bp-badge-green"><?php echo number_format((float)$p->stock_qty,0); ?> <?php echo esc_html($p->unit??'un'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="bp-badge bp-badge-<?php echo $p->status==='active'?'green':'gray'; ?>">
                            <?php echo $p->status==='active'?'Ativo':'Inativo'; ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap">
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpLojaOpenProduto(<?php echo $p->id; ?>,<?php echo $cid; ?>)" title="Editar">✏️</button>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpLojaEstoque(<?php echo $p->id; ?>,'<?php echo esc_js($p->name); ?>')" title="Movimentar estoque">📦</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function bpLojaFiltrarAdmin(cid) {
            var s = document.getElementById('bpLojaSearch_'+cid).value.toLowerCase();
            document.querySelectorAll('#bpLojaProdTable_'+cid+' tbody tr').forEach(function(r){
                r.style.display = !s || r.dataset.name.includes(s) ? '' : 'none';
            });
        }
        function bpLojaOpenProduto(id, cid) {
            BP.ajax('bp_app_action', {sub:'get_loja_produto_form', product_id:id, company_id:cid||1})
              .then(function(r){ if(r.success) BP.openModal(r.data.html); });
        }
        function bpSaveLojaProduto() {
            var cid = parseInt(document.getElementById('lpCid').value)||1;
            BP.ajax('bp_app_action', {
                sub:         'save_loja_produto',
                product_id:  document.getElementById('lpId').value,
                company_id:  cid,
                name:        document.getElementById('lpName').value,
                description: document.getElementById('lpDesc').value,
                category:    document.getElementById('lpCat').value,
                sku:         document.getElementById('lpSku').value,
                sale_price:  document.getElementById('lpPrice').value,
                cost_price:  document.getElementById('lpCost').value,
                stock_qty:   document.getElementById('lpStock').value,
                stock_min:   document.getElementById('lpMin').value,
                weight_g:    document.getElementById('lpWeight').value,
                photo:       document.getElementById('lpPhoto').value,
                shop_active: document.getElementById('lpShopActive').checked ? '1' : '0',
                status:      document.getElementById('lpStatus').value,
            }).then(function(r) {
                if (r.success) {
                    BP.closeModal();
                    BP.toast('Produto salvo!');
                    var prefix = cid === 1 ? 'barbearia' : 'lavacar';
                    BP.navigate(prefix+'_loja_produtos');
                } else {
                    BP.toast(r.data?.message||'Erro','error');
                }
            });
        }
        function bpLojaEstoque(id, name) {
            BP.ajax('bp_app_action', {sub:'get_stock_move_form', product_id:id, product_name:name})
              .then(function(r){ if(r.success) BP.openModal(r.data.html); });
        }
        </script>
        <?php
    }

    // ── Pedidos ─────────────────────────────────────────────────
    private function section_loja_pedidos( int $cid ): void {
        $mod     = $this->loja_mod_label($cid);
        $prefix  = $this->loja_nav_prefix($cid);
        $sf      = sanitize_key($_POST['f_status'] ?? '');
        $pedidos = BarberPro_Shop::list_orders(['company_id'=>$cid,'status'=>$sf,'limit'=>100]);

        $status_opts = [
            ''           => 'Todos',
            'novo'       => '🆕 Novo',
            'confirmado' => '✅ Confirmado',
            'em_preparo' => '⚙️ Em preparo',
            'enviado'    => '🚚 Enviado',
            'entregue'   => '📦 Entregue',
            'cancelado'  => '❌ Cancelado',
        ];

        // Contadores por status
        $todos   = BarberPro_Shop::list_orders(['company_id'=>$cid,'limit'=>500]);
        $counts  = [];
        foreach ($todos as $o) { $counts[$o->status] = ($counts[$o->status]??0)+1; }

        // KPIs financeiros
        $hoje   = current_time('Y-m-d');
        $mes_ini= date('Y-m-01');
        $total_hoje = array_sum(array_map(function($o) { return (float)$o->total; },
            array_filter($todos, function($o) { return substr($o->created_at,0,10)===$hoje && $o->status!=='cancelado'; })));
        $total_mes  = array_sum(array_map(function($o) { return (float)$o->total; },
            array_filter($todos, function($o) { return substr($o->created_at,0,10)>=$mes_ini && $o->status!=='cancelado'; })));
        $pendentes  = count(array_filter($todos, function($o) { return in_array($o->status,['novo','confirmado','em_preparo']); }));
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">📦 Loja — Pedidos <small style="font-size:.6em;opacity:.7"><?php echo $mod; ?></small></div>
                <div class="bp-page-subtitle">Gerencie os pedidos recebidos pela loja virtual</div>
                <p style="font-size:.72rem;color:var(--text3);margin:8px 0 0;max-width:52rem">WhatsApp em massa e ausência automática usam a <strong>Carteira de Clientes</strong> (<?php echo $cid === 1 ? 'Barbearia' : 'Lava-Car'; ?>): cadastre o telefone na carteira para incluir nos disparos.</p>
            </div>
        </div>

        <!-- KPIs -->
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px" class="bp-stagger">
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--accent);font-size:.85rem">R$ <?php echo number_format($total_hoje,2,',','.'); ?></div><div class="bp-kpi-mini-lbl">Vendas Hoje</div></div>
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--green);font-size:.85rem">R$ <?php echo number_format($total_mes,2,',','.'); ?></div><div class="bp-kpi-mini-lbl">Vendas no Mês</div></div>
            <div class="bp-kpi-mini"><div class="bp-kpi-mini-val" style="color:var(--amber)"><?php echo $pendentes; ?></div><div class="bp-kpi-mini-lbl">Pedidos Pendentes</div></div>
        </div>

        <!-- Filtros -->
        <div class="bp-card bp-animate-in" style="margin-bottom:16px;padding:14px 18px">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <?php foreach ($status_opts as $sv => $sl):
                    $cnt = $sv ? ($counts[$sv]??0) : count($todos); ?>
                <button onclick="bpLojaPedidosFiltrar('<?php echo esc_js($sv); ?>',<?php echo $cid; ?>)"
                        class="bp-btn bp-btn-sm <?php echo $sf===$sv?'bp-btn-primary':'bp-btn-ghost'; ?>">
                    <?php echo esc_html($sl); ?>
                    <?php if ($cnt > 0): ?><span style="background:rgba(255,255,255,.2);border-radius:10px;padding:0 6px;font-size:.72rem;margin-left:3px"><?php echo $cnt; ?></span><?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="bp-card bp-animate-in">
            <?php if (empty($pedidos)): ?>
            <div class="bp-empty">
                <div class="bp-empty-icon">📦</div>
                <div class="bp-empty-title">Nenhum pedido <?php echo $sf ? 'com este status' : 'ainda'; ?></div>
            </div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Código</th><th>Cliente</th><th>Entrega</th><th>Total</th><th>Pgto</th><th>Status</th><th>Data</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($pedidos as $p):
                    $entrega = $p->delivery_type === 'entrega'
                        ? '🚚 '.esc_html($p->address_city.'/'.$p->address_state)
                        : '🏪 Retirada';
                    $color_map = ['novo'=>'blue','confirmado'=>'green','em_preparo'=>'amber',
                                  'enviado'=>'blue','entregue'=>'green','cancelado'=>'red'];
                    $cor = $color_map[$p->status] ?? 'gray';
                ?>
                <tr>
                    <td><strong style="font-family:var(--font-mono);font-size:.82rem"><?php echo esc_html($p->order_code); ?></strong></td>
                    <td>
                        <strong><?php echo esc_html($p->client_name); ?></strong>
                        <?php if($p->client_phone): ?><br><small style="color:var(--text3)"><?php echo esc_html($p->client_phone); ?></small><?php endif; ?>
                    </td>
                    <td style="font-size:.82rem;color:var(--text2)"><?php echo $entrega; ?></td>
                    <td style="font-weight:700;color:var(--green)">R$ <?php echo number_format((float)$p->total,2,',','.'); ?></td>
                    <td style="font-size:.8rem;color:var(--text2)"><?php echo esc_html($p->payment_method??'—'); ?></td>
                    <td>
                        <select data-prev="<?php echo esc_attr($p->status); ?>"
                                onchange="bpLojaPedidoStatus(<?php echo $p->id; ?>,this.value,this)"
                                style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:5px 8px;font-size:.78rem;color:var(--text);cursor:pointer">
                            <?php foreach ($status_opts as $sv => $sl): if(!$sv) continue; ?>
                            <option value="<?php echo $sv; ?>" <?php selected($p->status,$sv); ?>><?php echo esc_html($sl); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="font-size:.78rem;color:var(--text3);white-space:nowrap">
                        <?php echo date_i18n('d/m/Y H:i', strtotime($p->created_at)); ?>
                    </td>
                    <td>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpLojaPedidoDetalhe(<?php echo $p->id; ?>)" title="Ver detalhes">👁</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function bpLojaPedidosFiltrar(status, cid) {
            var prefix  = cid===1?'barbearia':'lavacar';
            var section = prefix+'_loja_pedidos';
            BP.ajax('bp_app_section', {section:section, f_status:status})
              .then(function(r){
                if (r.success) {
                    document.getElementById('bpSectionContent').innerHTML = r.data.html;
                    BP.execScripts(document.getElementById('bpSectionContent'));
                }
              });
        }
        function bpLojaPedidoStatus(id, status, sel) {
            if (!confirm('Atualizar para "'+sel.options[sel.selectedIndex].text+'"?')) {
                sel.value = sel.dataset.prev; return;
            }
            BP.ajax('bp_loja_status', {order_id:id, status:status})
              .then(function(r){
                if (r.success) { sel.dataset.prev=status; BP.toast('Status atualizado!'); }
                else { sel.value=sel.dataset.prev; BP.toast(r.data?.message||'Erro','error'); }
              });
        }
        function bpLojaPedidoDetalhe(id) {
            BP.ajax('bp_app_action', {sub:'get_loja_pedido_detalhe', order_id:id})
              .then(function(r){ if(r.success) BP.openModal(r.data.html); });
        }
        </script>
        <?php
    }
}
