<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Sections_Bar {

    private function section_bar_comandas(): void {
        $today    = current_time('Y-m-d');
        $comandas = BarberPro_Bar::list_comandas(['date'=>$today,'limit'=>30]);
        $products = BarberPro_Bar::get_products();
        $status_meta=['aberta'=>['Aberta','amber'],'aguardando_pagamento'=>['Ag. Pagamento','red'],'paga'=>['Paga','green'],'cancelada'=>['Cancelada','red'],'fechada'=>['Fechada','blue']];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">🍺 Comandas – Bar/Eventos</div>
            <div class="bp-page-subtitle">Hoje, <?php echo date_i18n('d/m/Y'); ?></div>
            <button class="bp-btn bp-btn-primary" onclick="bpOpenComanda()">🧾 Nova Comanda</button>
        </div>

        <?php $abertas=array_filter($comandas,function($c) { return $c->status==='aberta'; }); if(!empty($abertas)): ?>
        <div class="bp-alert bp-alert-warn bp-animate-in">
            ⚠️ <?php echo count($abertas); ?> comanda(s) em aberto
        </div>
        <?php endif; ?>

        <div class="bp-card bp-animate-in">
            <?php if(empty($comandas)): ?>
            <div class="bp-empty"><div class="bp-empty-icon">🧾</div><div class="bp-empty-title">Nenhuma comanda hoje</div></div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Código</th><th>Mesa / Cliente</th><th>Itens</th><th>Total</th><th>Status</th><th>Ações</th></tr></thead>
                <tbody>
                <?php foreach($comandas as $c):
                    [$slabel,$scls]=$status_meta[$c->status]??[$c->status,'gray'];
                    $id_label=trim(($c->table_number?'Mesa '.$c->table_number:'').($c->client_name?' – '.$c->client_name:''))?:'-';
                    global $wpdb;
                    $item_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}barber_bar_comanda_items WHERE comanda_id=%d",$c->id));
                ?>
                <tr>
                    <td style="font-family:var(--font-mono);font-weight:700;color:var(--accent)">#<?php echo esc_html($c->comanda_code); ?></td>
                    <td><strong><?php echo esc_html($id_label); ?></strong></td>
                    <td style="color:var(--text2)"><?php echo $item_count; ?> item(s)</td>
                    <td style="font-weight:700;color:var(--green)"><?php echo $this->money($c->total_final); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $scls; ?>"><?php echo esc_html($slabel); ?></span></td>
                    <td>
                        <?php if($c->status==='aberta'): ?>
                        <button class="bp-btn bp-btn-sm bp-btn-secondary" onclick="bpViewComanda(<?php echo $c->id; ?>)">Abrir →</button>
                        <?php elseif($c->status==='paga'): ?>
                        <button class="bp-btn bp-btn-sm bp-btn-ghost" onclick="bpPrintComanda(<?php echo $c->id; ?>)">🖨️</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php
    }

    // ── Bar Produtos ──────────────────────────────────────────────
    private function section_bar_produtos(): void {
        $products   = BarberPro_Bar::get_products(BarberPro_Bar::CID, '', true); // mostra ativos + inativos
        $categories = BarberPro_Bar::get_categories();
        $low_stock  = BarberPro_Bar::low_stock();
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">📦 Produtos – Bar</div>
            <?php if(!empty($low_stock)): ?><span class="bp-badge bp-badge-red">⚠️ <?php echo count($low_stock); ?> estoque baixo</span><?php endif; ?>
            <button class="bp-btn bp-btn-primary" onclick="bpOpenProductForm(0)">+ Novo Produto</button>
        </div>
        <div class="bp-card bp-animate-in">
            <?php if(empty($products)): ?>
            <div class="bp-empty"><div class="bp-empty-icon">📦</div><div class="bp-empty-title">Nenhum produto cadastrado</div></div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Produto</th><th>Categoria</th><th>Custo</th><th>Venda</th><th>Estoque</th><th></th></tr></thead>
                <tbody>
                <?php foreach($products as $p):
                    $low=$p->stock_min>0&&(float)$p->stock_qty<=(float)$p->stock_min; ?>
                <tr>
                    <td><strong><?php echo esc_html($p->name); ?></strong></td>
                    <td style="color:var(--text2)"><?php echo esc_html($p->category??'—'); ?></td>
                    <td style="color:var(--text3)"><?php echo $this->money($p->cost_price); ?></td>
                    <td style="font-weight:700;color:var(--green)"><?php echo $this->money($p->sale_price); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $low?'red':'green'; ?>"><?php echo number_format($p->stock_qty,1,',','.'); ?> <?php echo esc_html($p->unit); ?></span></td>
                    <td>
                        <button class="bp-btn bp-btn-sm" id="bpProdToggle_<?php echo $p->id; ?>"
                            style="background:<?php echo $p->status==='active'?'rgba(34,211,160,.15)':'rgba(144,144,170,.12)'; ?>;color:<?php echo $p->status==='active'?'var(--green)':'var(--text3)'; ?>;border:1px solid <?php echo $p->status==='active'?'rgba(34,211,160,.3)':'var(--border)'; ?>"
                            onclick="bpToggleStatus('product',<?php echo $p->id; ?>,'bar_produtos')">
                            <?php echo $p->status==='active'?'✅ Ativo':'⏸ Inativo'; ?>
                        </button>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpOpenProductForm(<?php echo $p->id; ?>)">✏️</button>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpOpenStockMove(<?php echo $p->id; ?>,'<?php echo esc_js($p->name); ?>')">📦</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Bar Estoque ───────────────────────────────────────────────
    private function section_bar_estoque(): void {
        global $wpdb;
        $products  = BarberPro_Bar::get_products();
        $low_stock = BarberPro_Bar::low_stock();
        $recent_moves = $wpdb->get_results(
            "SELECT m.*, p.name AS product_name
             FROM {$wpdb->prefix}barber_stock_moves m
             JOIN {$wpdb->prefix}barber_products p ON m.product_id=p.id
             ORDER BY m.id DESC LIMIT 20"
        ) ?: [];
        $icons=['entrada'=>'📥','saida'=>'📤','ajuste'=>'🔧','transferencia'=>'🔄'];
        $colors=['entrada'=>'var(--green)','saida'=>'var(--red)','ajuste'=>'var(--accent)','transferencia'=>'var(--blue)'];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">📊 Controle de Estoque</div>
        </div>
        <?php if(!empty($low_stock)): ?>
        <div class="bp-alert bp-alert-warn bp-animate-in">
            ⚠️ Produtos com estoque abaixo do mínimo:
            <?php foreach($low_stock as $l): ?>
            <strong><?php echo esc_html($l->name); ?></strong> (<?php echo number_format($l->stock_qty,1,',','.'); ?> <?php echo esc_html($l->unit); ?>)
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" class="bp-animate-in">
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">Saldo Atual</div></div>
                <div class="bp-table-wrap">
                <table class="bp-table">
                    <thead><tr><th>Produto</th><th>Saldo</th><th>Mín.</th></tr></thead>
                    <tbody>
                    <?php foreach($products as $p): $low=$p->stock_min>0&&(float)$p->stock_qty<=(float)$p->stock_min; ?>
                    <tr>
                        <td><strong><?php echo esc_html($p->name); ?></strong></td>
                        <td><span class="bp-badge bp-badge-<?php echo $low?'red':'green'; ?>"><?php echo number_format($p->stock_qty,1,',','.'); ?> <?php echo esc_html($p->unit); ?></span></td>
                        <td style="color:var(--text3)"><?php echo number_format($p->stock_min,1,',','.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">Últimas Movimentações</div></div>
                <div class="bp-table-wrap">
                <table class="bp-table">
                    <thead><tr><th>Produto</th><th>Tipo</th><th>Qtd</th><th>Data</th></tr></thead>
                    <tbody>
                    <?php foreach($recent_moves as $m): $icon=$icons[$m->move_type]??'•'; $color=$colors[$m->move_type]??'var(--text2)'; ?>
                    <tr>
                        <td style="font-size:.8rem"><?php echo esc_html($m->product_name); ?></td>
                        <td style="color:<?php echo $color; ?>;font-size:.78rem;font-weight:700"><?php echo $icon.' '.ucfirst($m->move_type); ?></td>
                        <td style="color:<?php echo (float)$m->qty>=0?'var(--green)':'var(--red)'; ?>;font-weight:700;font-family:var(--font-mono)"><?php echo (float)$m->qty>=0?'+':''; echo number_format($m->qty,1,',','.'); ?></td>
                        <td style="color:var(--text3);font-size:.76rem"><?php echo date_i18n('d/m H:i',strtotime($m->created_at)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
        <?php
    }

    // ── Licença ───────────────────────────────────────────────────

    // ── Caixa do Bar ──────────────────────────────────────────────
    private function section_bar_caixa(): void {
        global $wpdb;
        $today    = current_time('Y-m-d');
        $now      = current_time('mysql');

        // Comandas abertas
        $abertas = BarberPro_Bar::list_comandas([
            'company_id' => BarberPro_Bar::CID,
            'status'     => 'aberta',
            'limit'      => 50,
        ]);
        // Comandas aguardando pagamento na saída
        $aguardando = BarberPro_Bar::list_comandas([
            'company_id' => BarberPro_Bar::CID,
            'status'     => 'aguardando_pagamento',
            'limit'      => 50,
        ]);
        // Comandas pagas hoje
        $pagas_hoje = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=%d AND status='paga' AND DATE(paid_at)=%s
             ORDER BY paid_at DESC LIMIT 30",
            BarberPro_Bar::CID, $today
        )) ?: [];

        // Totais do dia
        $total_dia   = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_final),0) FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=%d AND status='paga' AND DATE(paid_at)=%s",
            BarberPro_Bar::CID, $today
        ));
        $qtd_pagas   = count($pagas_hoje);
        $ticket_med  = $qtd_pagas > 0 ? $total_dia / $qtd_pagas : 0;

        // Itens de cada comanda aberta (para mostrar nos cards)
        $itens_por_comanda = [];
        foreach ($abertas as $c) {
            $itens = $wpdb->get_results($wpdb->prepare(
                "SELECT product_name, quantity, total_price FROM {$wpdb->prefix}barber_bar_comanda_items
                 WHERE comanda_id=%d ORDER BY id ASC", $c->id
            )) ?: [];
            $itens_por_comanda[$c->id] = $itens;
        }

        // Produtos disponíveis (para nova comanda rápida)
        $produtos = BarberPro_Bar::get_products(BarberPro_Bar::CID);
        $low_stock = BarberPro_Bar::low_stock(BarberPro_Bar::CID);
        ?>
        <!-- CSS extra para o caixa -->
        <style>
        .bp-caixa-grid{display:grid;grid-template-columns:1fr 380px;gap:16px;align-items:start}
        .bp-mesas-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px}
        .bp-mesa-card{background:var(--bg2);border:2px solid var(--border);border-radius:14px;padding:16px;cursor:pointer;transition:all .2s;position:relative;overflow:hidden}
        .bp-mesa-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.3)}
        .bp-mesa-card.aberta{border-color:rgba(245,166,35,.4);background:rgba(245,166,35,.04)}
        .bp-mesa-card.aberta:hover{border-color:var(--accent)}
        .bp-mesa-card.aguardando{border-color:rgba(239,68,68,.5);background:rgba(239,68,68,.06)}
        .bp-mesa-card.aguardando:hover{border-color:#ef4444}
        .bp-mesa-card.vazia{border-color:var(--border);border-style:dashed;opacity:.6}
        .bp-mesa-card.vazia:hover{opacity:1;border-color:var(--accent);border-style:solid}
        .bp-mesa-num{font-size:1.6rem;font-weight:900;letter-spacing:-1px;margin-bottom:4px}
        .bp-mesa-client{font-size:.78rem;color:var(--text2);margin-bottom:8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .bp-mesa-total{font-size:1.15rem;font-weight:800;color:var(--green)}
        .bp-mesa-items{font-size:.72rem;color:var(--text3);margin-top:4px;line-height:1.4}
        .bp-mesa-time{position:absolute;top:10px;right:10px;font-size:.68rem;color:var(--text3);font-family:var(--font-mono)}
        .bp-fila-item{display:flex;align-items:center;justify-content:space-between;padding:11px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;margin-bottom:8px;gap:10px;cursor:pointer;transition:all .15s}
        .bp-fila-item:hover{border-color:var(--accent)}
        .bp-fila-item.urgente{border-color:rgba(255,77,109,.3);background:rgba(255,77,109,.04)}
        .bp-hist-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:.83rem}
        .bp-hist-row:last-child{border:none}
        .bp-kpi-mini{background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 14px;text-align:center}
        .bp-kpi-mini-val{font-size:1.2rem;font-weight:800}
        .bp-kpi-mini-lbl{font-size:.7rem;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
        @media(max-width:900px){.bp-caixa-grid{grid-template-columns:1fr}}
        @media(max-width:600px){.bp-mesas-grid{grid-template-columns:repeat(2,1fr)}}
        </style>

        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">🍺 Caixa – Bar/Eventos</div>
                <div class="bp-page-subtitle"><?php echo date_i18n('l, d/m/Y – H:i'); ?>
                    <span style="display:block;font-size:.72rem;color:var(--text3);margin-top:6px;font-weight:400">Fidelização por WhatsApp (ausência e envio em massa) fica em <strong>Clientes</strong> no menu do Bar — a carteira é separada das comandas.</span>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <?php if(!empty($low_stock)): ?>
                <span class="bp-badge bp-badge-red">⚠️ <?php echo count($low_stock); ?> estoque baixo</span>
                <?php endif; ?>
                <button class="bp-btn bp-btn-primary" onclick="bpCaixaNovaComanda()">
                    ➕ Nova Comanda
                </button>
                <button class="bp-btn bp-btn-secondary bp-btn-sm" onclick="BP.navigate('bar_caixa')" title="Atualizar">🔄</button>
            </div>
        </div>

        <!-- KPIs do dia -->
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px" class="bp-stagger">
            <div class="bp-kpi-mini">
                <div class="bp-kpi-mini-val" style="color:var(--accent)"><?php echo count($abertas); ?></div>
                <div class="bp-kpi-mini-lbl">Mesas Abertas</div>
            </div>
            <div class="bp-kpi-mini">
                <div class="bp-kpi-mini-val" style="color:#ef4444"><?php echo count($aguardando); ?></div>
                <div class="bp-kpi-mini-lbl">Ag. Pagamento</div>
            </div>
            <div class="bp-kpi-mini">
                <div class="bp-kpi-mini-val" style="color:var(--green)"><?php echo $qtd_pagas; ?></div>
                <div class="bp-kpi-mini-lbl">Pagas Hoje</div>
            </div>
            <div class="bp-kpi-mini">
                <div class="bp-kpi-mini-val" style="color:var(--green)"><?php echo $this->money($total_dia); ?></div>
                <div class="bp-kpi-mini-lbl">Total do Dia</div>
            </div>
            <div class="bp-kpi-mini">
                <div class="bp-kpi-mini-val" style="color:var(--blue)"><?php echo $this->money($ticket_med); ?></div>
                <div class="bp-kpi-mini-lbl">Ticket Médio</div>
            </div>
        </div>

        <div class="bp-caixa-grid">
            <!-- Coluna esquerda: mesas + histórico -->
            <div>
                <!-- Cards de mesas abertas -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3)">
                        🟡 Mesas Abertas (<?php echo count($abertas); ?>)
                    </div>
                </div>

                <?php if(empty($abertas)): ?>
                <div class="bp-card bp-animate-in" style="text-align:center;padding:32px">
                    <div style="font-size:2rem;margin-bottom:8px">✅</div>
                    <div style="font-weight:600;color:var(--text2)">Nenhuma mesa aberta</div>
                    <div style="color:var(--text3);font-size:.84rem;margin:6px 0 14px">Clique em Nova Comanda para começar</div>
                    <button class="bp-btn bp-btn-primary" onclick="bpCaixaNovaComanda()">➕ Nova Comanda</button>
                </div>
                <?php else: ?>
                <div class="bp-mesas-grid bp-stagger">
                    <?php foreach($abertas as $c):
                        $itens   = $itens_por_comanda[$c->id] ?? [];
                        $n_itens = count($itens);
                        $mins    = round((strtotime($now) - strtotime($c->created_at)) / 60);
                        $urgente = $mins > 60;
                        $id_label = trim(($c->table_number ? 'Mesa '.$c->table_number : '').($c->client_name ? ' – '.$c->client_name : '')) ?: 'Comanda';
                    ?>
                    <div class="bp-mesa-card aberta <?php echo $urgente?'urgente':''; ?>"
                         onclick="bpCaixaAbrirComanda(<?php echo $c->id; ?>)">
                        <?php if($urgente): ?>
                        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--red);border-radius:14px 14px 0 0"></div>
                        <?php else: ?>
                        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:var(--accent);border-radius:14px 14px 0 0"></div>
                        <?php endif; ?>
                        <div class="bp-mesa-time"><?php echo $mins; ?>min</div>
                        <?php if($c->table_number): ?>
                        <div class="bp-mesa-num" style="color:<?php echo $urgente?'var(--red)':'var(--accent)'; ?>">
                            Mesa <?php echo esc_html($c->table_number); ?>
                        </div>
                        <?php else: ?>
                        <div class="bp-mesa-num" style="color:var(--accent);font-size:1.1rem">
                            <?php echo esc_html($c->comanda_code); ?>
                        </div>
                        <?php endif; ?>
                        <?php if($c->client_name): ?>
                        <div class="bp-mesa-client">👤 <?php echo esc_html($c->client_name); ?></div>
                        <?php endif; ?>
                        <div class="bp-mesa-total"><?php echo $this->money($c->total_final); ?></div>
                        <div class="bp-mesa-items">
                            <?php echo $n_itens; ?> item(s)
                            <?php if(!empty($itens)): ?>
                            · <?php echo esc_html(implode(', ', array_map(function($i) { return $i->product_name; }, array_slice($itens,0,2)))); ?>
                            <?php if($n_itens>2): ?>... +<?php echo $n_itens-2; ?><?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:10px;display:flex;gap:6px">
                            <button class="bp-btn bp-btn-sm" style="flex:1;background:var(--accent);color:#0f0f13;font-weight:700;justify-content:center"
                                    onclick="event.stopPropagation();bpCaixaCobrar(<?php echo $c->id; ?>)">
                                💳 Cobrar
                            </button>
                            <button class="bp-btn bp-btn-ghost bp-btn-sm"
                                    onclick="event.stopPropagation();bpCaixaAbrirComanda(<?php echo $c->id; ?>)">
                                ✏️
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <!-- Card para nova comanda -->
                    <div class="bp-mesa-card vazia" onclick="bpCaixaNovaComanda()" style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:160px">
                        <div style="font-size:2rem;opacity:.4;margin-bottom:8px">＋</div>
                        <div style="font-size:.8rem;color:var(--text3)">Nova Comanda</div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Comandas aguardando pagamento na saída -->
                <?php if(!empty($aguardando)): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;margin:18px 0 10px">
                    <div style="font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#ef4444">
                        💳 Aguardando Pagamento (<?php echo count($aguardando); ?>)
                    </div>
                </div>
                <div class="bp-mesas-grid bp-stagger">
                    <?php foreach($aguardando as $c):
                        $itens_ag = BarberPro_Bar::get_items($c->id);
                        $n_ag     = count($itens_ag);
                        $mins_ag  = round((strtotime($now) - strtotime($c->created_at)) / 60);
                        $id_label = trim(($c->table_number ? 'Mesa '.$c->table_number : '').($c->client_name ? ' – '.$c->client_name : '')) ?: 'Comanda';
                    ?>
                    <div class="bp-mesa-card aguardando" onclick="bpCaixaAbrirComanda(<?php echo $c->id; ?>)">
                        <div style="position:absolute;top:0;left:0;right:0;height:3px;background:#ef4444;border-radius:14px 14px 0 0"></div>
                        <div class="bp-mesa-time" style="color:#ef4444"><?php echo $mins_ag; ?>min</div>
                        <?php if($c->table_number): ?>
                        <div class="bp-mesa-num" style="color:#ef4444">Mesa <?php echo esc_html($c->table_number); ?></div>
                        <?php else: ?>
                        <div class="bp-mesa-num" style="color:#ef4444;font-size:1.1rem"><?php echo esc_html($c->comanda_code); ?></div>
                        <?php endif; ?>
                        <?php if($c->client_name): ?>
                        <div class="bp-mesa-client">👤 <?php echo esc_html($c->client_name); ?></div>
                        <?php endif; ?>
                        <div class="bp-mesa-total" style="color:#ef4444"><?php echo $this->money($c->total_final); ?></div>
                        <div class="bp-mesa-items"><?php echo $n_ag; ?> item(s)</div>
                        <div style="margin-top:10px;display:flex;gap:6px">
                            <button class="bp-btn bp-btn-sm" style="flex:1;background:#ef4444;color:#fff;font-weight:700;justify-content:center"
                                    onclick="event.stopPropagation();bpCaixaCobrar(<?php echo $c->id; ?>)">
                                💳 Cobrar Agora
                            </button>
                            <button class="bp-btn bp-btn-ghost bp-btn-sm"
                                    onclick="event.stopPropagation();bpCaixaAbrirComanda(<?php echo $c->id; ?>)">
                                ✏️
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Histórico do dia -->
                <?php if(!empty($pagas_hoje)): ?>
                <div class="bp-card bp-animate-in" style="margin-top:16px">
                    <div class="bp-card-header">
                        <div class="bp-card-title">📋 Histórico do Dia</div>
                        <span class="bp-badge bp-badge-green"><?php echo $qtd_pagas; ?> pagas</span>
                    </div>
                    <?php foreach($pagas_hoje as $c):
                        $id_label = trim(($c->table_number?'Mesa '.$c->table_number:'').($c->client_name?' – '.$c->client_name:''))?:$c->comanda_code;
                    ?>
                    <div class="bp-hist-row">
                        <div>
                            <div style="font-weight:600;font-size:.84rem"><?php echo esc_html($id_label); ?></div>
                            <div style="color:var(--text3);font-size:.74rem"><?php echo date_i18n('H:i',strtotime($c->paid_at)); ?></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px">
                            <strong style="color:var(--green)"><?php echo $this->money($c->total_final); ?></strong>
                            <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpCaixaImprimir(<?php echo $c->id; ?>)">🖨️</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Coluna direita: painel de cobranças + nova comanda rápida -->
            <div>
                <!-- Fila de cobranças -->
                <?php if(!empty($abertas)): ?>
                <div class="bp-card bp-animate-in" style="margin-bottom:14px">
                    <div class="bp-card-header">
                        <div class="bp-card-title">⏳ Fila de Cobranças</div>
                    </div>
                    <?php
                    $abertas_ord = $abertas;
                    usort($abertas_ord, function($a,$b) { return strtotime($a->created_at) - strtotime($b->created_at); });
                    foreach($abertas_ord as $c):
                        $mins  = round((strtotime($now)-strtotime($c->created_at))/60);
                        $id_label = trim(($c->table_number?'Mesa '.$c->table_number:'').($c->client_name?' – '.$c->client_name:''))?:$c->comanda_code;
                        $urgente = $mins > 60;
                    ?>
                    <div class="bp-fila-item <?php echo $urgente?'urgente':''; ?>" onclick="bpCaixaCobrar(<?php echo $c->id; ?>)">
                        <div>
                            <div style="font-weight:700;font-size:.88rem">
                                <?php if($urgente): ?><span style="color:var(--red)">🔴</span><?php else: ?><span style="color:var(--accent)">🟡</span><?php endif; ?>
                                <?php echo esc_html($id_label); ?>
                            </div>
                            <div style="font-size:.74rem;color:var(--text3);margin-top:2px"><?php echo $mins; ?> min aberta</div>
                        </div>
                        <div style="text-align:right">
                            <div style="font-weight:800;color:var(--green)"><?php echo $this->money($c->total_final); ?></div>
                            <div style="font-size:.7rem;color:var(--accent);margin-top:2px">Cobrar →</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Nova comanda rápida -->
                <div class="bp-card bp-animate-in">
                    <div class="bp-card-header"><div class="bp-card-title">⚡ Comanda Rápida</div></div>
                    <div class="bp-field">
                        <label>Mesa</label>
                        <input type="text" id="bpCxTable" placeholder="Ex: 1, 2, VIP..." style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px 12px;width:100%;font-size:.9rem">
                    </div>
                    <div class="bp-field">
                        <label>Cliente</label>
                        <input type="text" id="bpCxClient" placeholder="Nome do cliente (opcional)" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px 12px;width:100%;font-size:.9rem">
                    </div>

                    <?php if(!empty($produtos)): ?>
                    <div style="font-size:.76rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">Adicionar itens</div>
                    <div id="bpCxProdutos" style="display:flex;flex-direction:column;gap:6px;max-height:260px;overflow-y:auto;margin-bottom:12px">
                        <?php foreach($produtos as $p): $sem = (float)$p->stock_qty <= 0; ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 10px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;<?php echo $sem?'opacity:.4':''; ?>">
                            <div>
                                <div style="font-size:.84rem;font-weight:600"><?php echo esc_html($p->name); ?></div>
                                <div style="font-size:.74rem;color:var(--text3)"><?php echo esc_html($p->category??''); ?> · <?php echo number_format($p->stock_qty,0); ?> em estoque</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:8px">
                                <span style="font-weight:700;color:var(--green);font-size:.88rem"><?php echo $this->money($p->sale_price); ?></span>
                                <?php if(!$sem): ?>
                                <div style="display:flex;align-items:center;gap:4px">
                                    <button class="bp-btn-icon" style="width:26px;height:26px;font-size:14px;display:flex;align-items:center;justify-content:center"
                                            onclick="bpCxQty(<?php echo $p->id; ?>,-1)">−</button>
                                    <span id="bpCxQ_<?php echo $p->id; ?>" style="min-width:20px;text-align:center;font-weight:700;font-size:.9rem">0</span>
                                    <button class="bp-btn-icon" style="width:26px;height:26px;font-size:14px;display:flex;align-items:center;justify-content:center"
                                            onclick="bpCxQty(<?php echo $p->id; ?>,1)">＋</button>
                                </div>
                                <?php else: ?>
                                <span class="bp-badge bp-badge-red" style="font-size:.65rem">Sem estoque</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div id="bpCxResumo" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px 12px;margin-bottom:10px;font-size:.84rem">
                        <div style="font-weight:700;color:var(--text2);margin-bottom:4px">Resumo:</div>
                        <div id="bpCxResumoItens" style="color:var(--text3)"></div>
                        <div style="font-weight:800;color:var(--green);margin-top:6px;font-size:.95rem">Total: <span id="bpCxTotal">R$ 0,00</span></div>
                    </div>
                    <?php endif; ?>

                    <button class="bp-btn bp-btn-primary" style="width:100%" onclick="bpCaixaConfirmarRapida()">
                        🧾 Abrir Comanda
                    </button>
                </div>
            </div>
        </div>

        <!-- Área de impressão -->
        <div id="bpCaixaPrintArea" class="bp-print-receipt"></div>

        <?php
    }

    // ── Admin Bar ─────────────────────────────────────────────────
    private function section_bar_admin( string $tab ): void {
        global $wpdb;
        $tab = $tab ?: 'visao';
        $today = current_time('Y-m-d');
        $month = current_time('Y-m');

        // Dados para visão geral
        $total_mes = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_final),0) FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=%d AND status='paga' AND DATE_FORMAT(paid_at,'%%Y-%%m')=%s",
            BarberPro_Bar::CID, $month
        ));
        $total_dia = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_final),0) FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=%d AND status='paga' AND DATE(paid_at)=%s",
            BarberPro_Bar::CID, $today
        ));
        $qtd_mes = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=%d AND status='paga' AND DATE_FORMAT(paid_at,'%%Y-%%m')=%s",
            BarberPro_Bar::CID, $month
        ));
        $ticket_med = $qtd_mes > 0 ? $total_mes / $qtd_mes : 0;

        // Vendas por produto (top 5 do mês)
        $top_produtos = $wpdb->get_results($wpdb->prepare(
            "SELECT i.product_name, SUM(i.quantity) as qtd, SUM(i.total_price) as total
             FROM {$wpdb->prefix}barber_bar_comanda_items i
             JOIN {$wpdb->prefix}barber_bar_comandas c ON i.comanda_id=c.id
             WHERE c.status='paga' AND DATE_FORMAT(c.paid_at,'%%Y-%%m')=%s
             GROUP BY i.product_name ORDER BY total DESC LIMIT 5",
            $month
        )) ?: [];

        // Receita por dia (últimos 14 dias)
        $chart_data = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(paid_at) as dia, COALESCE(SUM(total_final),0) as total, COUNT(*) as qtd
             FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=%d AND status='paga' AND paid_at >= DATE_SUB(%s, INTERVAL 13 DAY)
             GROUP BY DATE(paid_at) ORDER BY dia ASC",
            BarberPro_Bar::CID, $today
        )) ?: [];

        // Por forma de pagamento do mês
        $por_metodo = $wpdb->get_results($wpdb->prepare(
            "SELECT bp.payment_method, COALESCE(SUM(bp.amount),0) as total
             FROM {$wpdb->prefix}barber_bar_payments bp
             JOIN {$wpdb->prefix}barber_bar_comandas bc ON bp.comanda_id=bc.id
             WHERE bc.company_id=%d AND DATE_FORMAT(bc.paid_at,'%%Y-%%m')=%s
             GROUP BY bp.payment_method ORDER BY total DESC",
            BarberPro_Bar::CID, $month
        )) ?: [];

        // Histórico de comandas (todos os status, filtro de data)
        $filter_date = sanitize_text_field($_GET['date'] ?? '');
        $filter_status = sanitize_key($_GET['fstatus'] ?? '');
        $hist_where = '';
        if($filter_date)   $hist_where .= $wpdb->prepare(" AND DATE(created_at)=%s", $filter_date);
        if($filter_status) $hist_where .= $wpdb->prepare(" AND status=%s", $filter_status);
        $historico = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}barber_bar_comandas
             WHERE company_id=".BarberPro_Bar::CID." {$hist_where}
             ORDER BY id DESC LIMIT 50"
        ) ?: [];

        // Produtos e estoque
        $produtos = BarberPro_Bar::get_products(BarberPro_Bar::CID, '', true);
        $low_stock = BarberPro_Bar::low_stock(BarberPro_Bar::CID);

        $mlabels = bp_get_payment_methods();
        $status_map = ['aberta'=>['Aberta','amber'],'paga'=>['Paga','green'],'cancelada'=>['Cancelada','red'],'fechada'=>['Fechada','blue']];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">📊 Admin – Bar/Eventos</div>
                <div class="bp-page-subtitle">Gestão completa · <?php echo date_i18n('F Y'); ?></div>
            </div>
            <button class="bp-btn bp-btn-primary" onclick="BP.navigate('bar_caixa')">🍺 Ir ao Caixa</button>
        </div>

        <!-- Tabs -->
        <div class="bp-tabs bp-animate-in">
            <?php foreach(['visao'=>'📊 Visão Geral','comandas'=>'🧾 Comandas','estoque'=>'📦 Estoque','financeiro'=>'💰 Financeiro'] as $t=>$l): ?>
            <button class="bp-tab <?php echo $tab===$t?'active':''; ?>" onclick="BP.navigate('bar_admin','<?php echo $t; ?>')"><?php echo esc_html($l); ?></button>
            <?php endforeach; ?>
        </div>

        <?php if($tab==='visao'): ?>
        <!-- ── Visão Geral ── -->
        <div class="bp-kpi-grid bp-stagger">
            <div class="bp-kpi green"><div class="bp-kpi-label">Receita do Mês</div><div class="bp-kpi-value green"><?php echo $this->money($total_mes); ?></div><div class="bp-kpi-sub"><?php echo date_i18n('F Y'); ?></div></div>
            <div class="bp-kpi amber"><div class="bp-kpi-label">Receita Hoje</div><div class="bp-kpi-value amber"><?php echo $this->money($total_dia); ?></div><div class="bp-kpi-sub"><?php echo date_i18n('d/m/Y'); ?></div></div>
            <div class="bp-kpi blue"><div class="bp-kpi-label">Comandas no Mês</div><div class="bp-kpi-value blue"><?php echo $qtd_mes; ?></div></div>
            <div class="bp-kpi green"><div class="bp-kpi-label">Ticket Médio</div><div class="bp-kpi-value green"><?php echo $this->money($ticket_med); ?></div></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px" class="bp-animate-in">
            <!-- Gráfico receita 14 dias -->
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">Receita – Últimos 14 dias</div></div>
                <canvas id="bpBarAdminChart" style="max-height:180px"></canvas>
            </div>
            <!-- Por método -->
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">💳 Por Pagamento (mês)</div></div>
                <?php if(empty($por_metodo)): ?>
                <div class="bp-empty" style="padding:20px"><div class="bp-empty-text">Sem dados ainda</div></div>
                <?php else:
                    $max_m = max(array_map(function($m) { return (float)$m->total; }, $por_metodo));
                    foreach($por_metodo as $m):
                        $pct = $max_m>0 ? round((float)$m->total/$max_m*100) : 0;
                ?>
                <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);font-size:.83rem">
                    <span style="color:var(--text2);width:80px;flex-shrink:0"><?php echo esc_html($mlabels[$m->payment_method]??$m->payment_method); ?></span>
                    <div style="flex:1;height:6px;background:var(--border);border-radius:3px">
                        <div style="height:6px;background:var(--accent);border-radius:3px;width:<?php echo $pct; ?>%"></div>
                    </div>
                    <strong style="width:70px;text-align:right"><?php echo $this->money($m->total); ?></strong>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Top produtos -->
        <?php if(!empty($top_produtos)): ?>
        <div class="bp-card bp-animate-in">
            <div class="bp-card-header"><div class="bp-card-title">🏆 Produtos Mais Vendidos (mês)</div></div>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>#</th><th>Produto</th><th>Quantidade</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach($top_produtos as $i=>$p): ?>
                <tr>
                    <td style="color:var(--accent);font-weight:800"><?php echo $i+1; ?>º</td>
                    <td><strong><?php echo esc_html($p->product_name); ?></strong></td>
                    <td><?php echo number_format($p->qtd,1,',','.'); ?></td>
                    <td style="font-weight:700;color:var(--green)"><?php echo $this->money($p->total); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>


        <?php elseif($tab==='comandas'): ?>
        <!-- ── Comandas ── -->
        <div class="bp-card bp-animate-in">
            <div class="bp-card-header" style="flex-wrap:wrap;gap:10px">
                <div class="bp-card-title">🧾 Histórico de Comandas</div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="date" value="<?php echo esc_attr($filter_date); ?>"
                           onchange="BP.navigate('bar_admin','comandas')"
                           style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:7px 10px;font-size:.82rem">
                    <select onchange="BP.navigate('bar_admin','comandas')"
                            style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:7px 10px;font-size:.82rem">
                        <option value="">Todos status</option>
                        <option value="aberta" <?php selected($filter_status,'aberta'); ?>>Abertas</option>
                        <option value="aguardando_pagamento" <?php selected($filter_status,'aguardando_pagamento'); ?>>Ag. Pagamento</option>
                        <option value="paga" <?php selected($filter_status,'paga'); ?>>Pagas</option>
                        <option value="cancelada" <?php selected($filter_status,'cancelada'); ?>>Canceladas</option>
                    </select>
                </div>
            </div>
            <?php if(empty($historico)): ?>
            <div class="bp-empty"><div class="bp-empty-icon">🧾</div><div class="bp-empty-title">Nenhuma comanda encontrada</div></div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Código</th><th>Mesa / Cliente</th><th>Data</th><th>Itens</th><th>Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach($historico as $c):
                    [$slabel,$scls] = $status_map[$c->status] ?? [$c->status,'gray'];
                    $id_label = trim(($c->table_number?'Mesa '.$c->table_number:'').($c->client_name?' – '.$c->client_name:'')) ?: '—';
                    $n_itens  = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}barber_bar_comanda_items WHERE comanda_id=%d",$c->id));
                ?>
                <tr>
                    <td style="font-family:var(--font-mono);color:var(--accent);font-weight:700">#<?php echo esc_html($c->comanda_code); ?></td>
                    <td><strong><?php echo esc_html($id_label); ?></strong></td>
                    <td style="color:var(--text3);font-size:.8rem"><?php echo date_i18n('d/m H:i',strtotime($c->created_at)); ?></td>
                    <td style="color:var(--text2)"><?php echo $n_itens; ?></td>
                    <td style="font-weight:700;color:var(--green)"><?php echo $this->money($c->total_final); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $scls; ?>"><?php echo esc_html($slabel); ?></span></td>
                    <td style="white-space:nowrap">
                        <?php if($c->status==='aberta'): ?>
                        <button class="bp-btn bp-btn-sm" style="background:var(--accent);color:#000;font-weight:700"
                                onclick="bpCaixaCobrar(<?php echo $c->id; ?>)">💳 Cobrar</button>
                        <?php endif; ?>
                        <?php if($c->status==='paga'): ?>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpAdminImprimir(<?php echo $c->id; ?>)">🖨️</button>
                        <?php endif; ?>
                        <?php if($c->status==='aberta'): ?>
                        <button class="bp-btn bp-btn-danger bp-btn-sm"
                                onclick="if(confirm('Cancelar?'))BP.ajax('bp_app_action',{sub:'bar_cancel_comanda',comanda_id:<?php echo $c->id; ?>}).then(r=>{if(r.success){BP.toast('Cancelada');BP.navigate('bar_admin','comandas');}})">✕</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <div id="bpAdminPrint" class="bp-print-receipt"></div>

        <?php elseif($tab==='estoque'): ?>
        <!-- ── Estoque ── -->
        <?php if(!empty($low_stock)): ?>
        <div class="bp-alert bp-alert-warn bp-animate-in">
            ⚠️ Estoque baixo: <?php echo implode(', ', array_map(function($p) { return $p->name.' ('.number_format($p->stock_qty,1,',','.').' '.$p->unit.')'; }, $low_stock)); ?>
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:340px 1fr;gap:14px;align-items:start" class="bp-animate-in">
            <!-- Formulário produto -->
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">➕ Produto / Editar</div></div>
                <div id="bpAdminProdForm">
                    <div class="bp-field"><label>Nome *</label><input type="text" id="apf_name" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div class="bp-field"><label>Categoria</label><input type="text" id="apf_cat" placeholder="Bebida..." style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                        <div class="bp-field"><label>Unidade</label>
                            <select id="apf_unit" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem">
                                <option value="un">Unidade</option><option value="ml">ml</option><option value="l">Litro</option><option value="g">Grama</option><option value="kg">KG</option><option value="cx">Caixa</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div class="bp-field"><label>Custo (R$)</label><input type="text" id="apf_cost" placeholder="0,00" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                        <div class="bp-field"><label>Venda (R$) *</label><input type="text" id="apf_price" placeholder="0,00" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div class="bp-field"><label>Estoque Mín.</label><input type="text" id="apf_min" placeholder="0" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                        <div class="bp-field"><label>Estoque Máx.</label><input type="text" id="apf_max" placeholder="0" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                    </div>
                    <input type="hidden" id="apf_id" value="0">
                    <div style="display:flex;gap:8px">
                        <button class="bp-btn bp-btn-primary" style="flex:1" onclick="bpAdminSalvarProduto()">✅ Salvar</button>
                        <button class="bp-btn bp-btn-ghost" onclick="bpAdminLimparProduto()" id="apf_cancel" style="display:none">✕</button>
                    </div>
                </div>

                <!-- Movimentação -->
                <div style="border-top:1px solid var(--border);margin-top:16px;padding-top:14px">
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text3);margin-bottom:10px">📦 Movimentar Estoque</div>
                    <div class="bp-field"><label>Produto</label>
                        <select id="apf_move_prod" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem">
                            <option value="">-- Selecione --</option>
                            <?php foreach($produtos as $p): ?>
                            <option value="<?php echo $p->id; ?>"><?php echo esc_html($p->name); ?> (<?php echo number_format($p->stock_qty,1,',','.'); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div class="bp-field"><label>Tipo</label>
                            <select id="apf_move_type" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem">
                                <option value="entrada">📥 Entrada</option>
                                <option value="ajuste">🔧 Ajuste</option>
                            </select>
                        </div>
                        <div class="bp-field"><label>Quantidade</label><input type="number" id="apf_move_qty" min="0.1" step="0.1" placeholder="0" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                    </div>
                    <div class="bp-field"><label>Observação</label><input type="text" id="apf_move_reason" placeholder="Motivo..." style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:10px;width:100%;font-size:.88rem"></div>
                    <button class="bp-btn bp-btn-secondary" style="width:100%" onclick="bpAdminMovEstoque()">📦 Registrar Movimentação</button>
                </div>
            </div>

            <!-- Lista de produtos -->
            <div class="bp-card">
                <div class="bp-card-header">
                    <div class="bp-card-title">Produtos (<?php echo count($produtos); ?>)</div>
                </div>
                <?php if(empty($produtos)): ?>
                <div class="bp-empty"><div class="bp-empty-icon">📦</div><div class="bp-empty-title">Nenhum produto ainda</div></div>
                <?php else: ?>
                <div class="bp-table-wrap">
                <table class="bp-table">
                    <thead><tr><th>Nome</th><th>Cat.</th><th>Custo</th><th>Venda</th><th>Estoque</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach($produtos as $p):
                        $low = (float)$p->stock_min > 0 && (float)$p->stock_qty <= (float)$p->stock_min;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($p->name); ?></strong></td>
                        <td style="color:var(--text3);font-size:.78rem"><?php echo esc_html($p->category??'—'); ?></td>
                        <td style="color:var(--text3)"><?php echo $this->money($p->cost_price); ?></td>
                        <td style="font-weight:700;color:var(--green)"><?php echo $this->money($p->sale_price); ?></td>
                        <td>
                            <span class="bp-badge bp-badge-<?php echo $low?'red':'green'; ?>">
                                <?php echo number_format($p->stock_qty,1,',','.'); ?> <?php echo esc_html($p->unit); ?>
                            </span>
                        </td>
                        <td style="white-space:nowrap">
                            <button class="bp-btn bp-btn-sm" id="bpProdToggle_<?php echo $p->id; ?>"
                                style="background:<?php echo $p->status==='active'?'rgba(34,211,160,.15)':'rgba(144,144,170,.12)'; ?>;color:<?php echo $p->status==='active'?'var(--green)':'var(--text3)'; ?>;border:1px solid <?php echo $p->status==='active'?'rgba(34,211,160,.3)':'var(--border)'; ?>"
                                onclick="bpToggleStatus('product',<?php echo $p->id; ?>,'bar_admin')">
                                <?php echo $p->status==='active'?'✅ Ativo':'⏸ Inativo'; ?>
                            </button>
                            <button class="bp-btn bp-btn-ghost bp-btn-sm"
                                onclick="bpAdminEditarProduto(<?php echo $p->id; ?>,<?php echo json_encode($p->name); ?>,<?php echo json_encode($p->category??''); ?>,'<?php echo $p->unit; ?>',<?php echo $p->cost_price; ?>,<?php echo $p->sale_price; ?>,<?php echo $p->stock_min; ?>,<?php echo $p->stock_max; ?>)">✏️</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif($tab==='financeiro'): ?>
        <!-- ── Financeiro ── -->
        <div class="bp-kpi-grid bp-stagger">
            <div class="bp-kpi green"><div class="bp-kpi-label">Receita do Mês</div><div class="bp-kpi-value green"><?php echo $this->money($total_mes); ?></div></div>
            <div class="bp-kpi amber"><div class="bp-kpi-label">Receita Hoje</div><div class="bp-kpi-value amber"><?php echo $this->money($total_dia); ?></div></div>
            <div class="bp-kpi blue"><div class="bp-kpi-label">Ticket Médio</div><div class="bp-kpi-value blue"><?php echo $this->money($ticket_med); ?></div></div>
            <div class="bp-kpi blue"><div class="bp-kpi-label">Comandas Pagas</div><div class="bp-kpi-value blue"><?php echo $qtd_mes; ?></div></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px" class="bp-animate-in">
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">Receita Diária – 14 dias</div></div>
                <canvas id="bpAdminFinChart" style="max-height:200px"></canvas>
            </div>
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">💳 Por Método (mês)</div></div>
                <?php if(empty($por_metodo)): ?>
                <div class="bp-empty" style="padding:20px"><div class="bp-empty-text">Sem dados</div></div>
                <?php else:
                    $total_met = array_sum(array_map(function($m) { return (float)$m->total; }, $por_metodo));
                    foreach($por_metodo as $m):
                        $pct = $total_met>0 ? round((float)$m->total/$total_met*100) : 0;
                ?>
                <div style="padding:9px 0;border-bottom:1px solid var(--border)">
                    <div style="display:flex;justify-content:space-between;font-size:.83rem;margin-bottom:4px">
                        <span style="color:var(--text2)"><?php echo esc_html($mlabels[$m->payment_method]??$m->payment_method); ?></span>
                        <strong><?php echo $this->money($m->total); ?></strong>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div style="flex:1;height:4px;background:var(--border);border-radius:2px">
                            <div style="height:4px;background:var(--accent);border-radius:2px;width:<?php echo $pct; ?>%"></div>
                        </div>
                        <span style="font-size:.72rem;color:var(--text3);width:30px"><?php echo $pct; ?>%</span>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <?php if(!empty($top_produtos)): ?>
        <div class="bp-card bp-animate-in" style="margin-top:14px">
            <div class="bp-card-header"><div class="bp-card-title">🏆 Top Produtos – Receita do Mês</div></div>
            <div class="bp-table-wrap">
            <table class="bp-table">
                <thead><tr><th>Produto</th><th>Qtd Vendida</th><th>Receita</th><th>% do Total</th></tr></thead>
                <tbody>
                <?php foreach($top_produtos as $p):
                    $pct_t = $total_mes > 0 ? round((float)$p->total/$total_mes*100,1) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html($p->product_name); ?></strong></td>
                    <td><?php echo number_format($p->qtd,1,',','.'); ?></td>
                    <td style="font-weight:700;color:var(--green)"><?php echo $this->money($p->total); ?></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="width:60px;height:4px;background:var(--border);border-radius:2px">
                                <div style="height:4px;background:var(--green);border-radius:2px;width:<?php echo $pct_t; ?>%"></div>
                            </div>
                            <span style="font-size:.78rem;color:var(--text3)"><?php echo $pct_t; ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; // tabs ?>
        <?php
    }



}
