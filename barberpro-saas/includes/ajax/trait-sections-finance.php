<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Sections_Finance {

    private function section_finance( int $company_id ): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $cid_lav   = BarberPro_Modules::company_id( 'lavacar' );
        $mod       = $company_id === $cid_lav ? 'lavacar' : 'barbearia';
        $mod_name  = BarberPro_Database::get_setting( "module_{$mod}_name", $mod === 'lavacar' ? 'Lava-Car' : 'Barbearia' );
        $tab  = sanitize_key($_POST['tab'] ?? $_GET['tab'] ?? 'lancamentos');

        // ── Dados de resumo ──
        $month = current_time('Y-m');
        $receita = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}barber_finance
             WHERE company_id=%d AND type='receita' AND status='pago'
             AND DATE_FORMAT(competencia_date,'%%Y-%%m')=%s", $company_id, $month));
        $despesa = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}barber_finance
             WHERE company_id=%d AND type='despesa' AND status='pago'
             AND DATE_FORMAT(competencia_date,'%%Y-%%m')=%s", $company_id, $month));
        $pendente = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$p}barber_finance
             WHERE company_id=%d AND status IN('pendente','vencido')
             AND DATE_FORMAT(competencia_date,'%%Y-%%m')=%s", $company_id, $month));

        // ── Lista de lançamentos (últimos 60 dias) ──
        $lancamentos = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*, c.name as cat_name
             FROM {$p}barber_finance f
             LEFT JOIN {$p}barber_finance_categories c ON f.category_id=c.id
             WHERE f.company_id=%d
             ORDER BY f.competencia_date DESC, f.id DESC LIMIT 80", $company_id));

        // ── Categorias ──
        $cats_receita = BarberPro_Finance::get_categories('receita', $company_id);
        $cats_despesa = BarberPro_Finance::get_categories('despesa', $company_id);
        $all_cats     = array_merge($cats_receita, $cats_despesa);

        $status_map = ['pago'=>['Pago','green'],'pendente'=>['Pendente','amber'],'vencido'=>['Vencido','red'],'cancelado'=>['Cancelado','gray']];
        $type_map   = ['receita'=>['Receita','green'],'despesa'=>['Despesa','red']];
        $method_map = bp_get_payment_methods();
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">💵 Financeiro – <?php echo esc_html($mod_name); ?></div>
                <div class="bp-page-subtitle"><?php echo date_i18n('F Y'); ?></div>
            </div>
            <button class="bp-btn bp-btn-primary" onclick="bpFinOpenLanc(<?php echo $company_id; ?>)">+ Lançamento</button>
        </div>

        <!-- KPIs -->
        <div class="bp-kpi-grid bp-stagger" style="margin-bottom:16px">
            <div class="bp-kpi green">
                <div class="bp-kpi-label">Receita do Mês</div>
                <div class="bp-kpi-value green"><?php echo $this->money($receita); ?></div>
            </div>
            <div class="bp-kpi red">
                <div class="bp-kpi-label">Despesas do Mês</div>
                <div class="bp-kpi-value red"><?php echo $this->money($despesa); ?></div>
            </div>
            <div class="bp-kpi <?php echo ($receita-$despesa)>=0?'green':'red'; ?>">
                <div class="bp-kpi-label">Lucro do Mês</div>
                <div class="bp-kpi-value <?php echo ($receita-$despesa)>=0?'green':'red'; ?>"><?php echo $this->money($receita-$despesa); ?></div>
            </div>
            <div class="bp-kpi <?php echo $pendente>0?'amber':'green'; ?>">
                <div class="bp-kpi-label">Pendentes</div>
                <div class="bp-kpi-value <?php echo $pendente>0?'amber':'green'; ?>"><?php echo $this->money($pendente); ?></div>
            </div>
        </div>

        <!-- Abas -->
        <div class="bp-tabs bp-animate-in" style="margin-bottom:16px">
            <button class="bp-tab<?php echo $tab==='lancamentos'?' active':''; ?>" onclick="bpFinTab('lancamentos',<?php echo $company_id; ?>)">📋 Lançamentos</button>
            <button class="bp-tab<?php echo $tab==='categorias'?' active':''; ?>" onclick="bpFinTab('categorias',<?php echo $company_id; ?>)">🏷️ Categorias</button>
        </div>

        <?php if($tab==='lancamentos'): ?>
        <!-- ── Lançamentos ── -->
        <div class="bp-card bp-animate-in">
            <div class="bp-card-header">
                <div class="bp-card-title">Últimos Lançamentos</div>
                <div style="display:flex;gap:8px;align-items:center">
                    <select id="finFilterType" onchange="bpFinFilter()" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:6px 10px;font-size:.82rem">
                        <option value="">Todos</option>
                        <option value="receita">Receitas</option>
                        <option value="despesa">Despesas</option>
                    </select>
                    <select id="finFilterStatus" onchange="bpFinFilter()" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:6px 10px;font-size:.82rem">
                        <option value="">Todos status</option>
                        <option value="pago">Pago</option>
                        <option value="pendente">Pendente</option>
                        <option value="vencido">Vencido</option>
                    </select>
                </div>
            </div>
            <?php if(empty($lancamentos)): ?>
            <div class="bp-empty">
                <div class="bp-empty-icon">💵</div>
                <div class="bp-empty-title">Nenhum lançamento ainda</div>
                <div class="bp-empty-text">Clique em "+ Lançamento" para começar</div>
            </div>
            <?php else: ?>
            <div class="bp-table-wrap">
            <table class="bp-table" id="finTable">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Tipo</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($lancamentos as $l):
                    [$tlabel,$tcls] = $type_map[$l->type] ?? [$l->type,'gray'];
                    [$slabel,$scls] = $status_map[$l->status] ?? [$l->status,'gray'];
                ?>
                <tr data-type="<?php echo esc_attr($l->type); ?>" data-status="<?php echo esc_attr($l->status); ?>">
                    <td style="color:var(--text3);white-space:nowrap;font-size:.82rem"><?php echo date_i18n('d/m/Y', strtotime($l->competencia_date)); ?></td>
                    <td>
                        <strong style="font-size:.88rem"><?php echo esc_html($l->description); ?></strong>
                        <?php if($l->supplier): ?><div style="font-size:.74rem;color:var(--text3)"><?php echo esc_html($l->supplier); ?></div><?php endif; ?>
                    </td>
                    <td style="font-size:.81rem;color:var(--text2)"><?php echo esc_html($l->cat_name ?? '—'); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $tcls; ?>"><?php echo $tlabel; ?></span></td>
                    <td style="font-weight:700;color:var(--<?php echo $tcls; ?>)"><?php echo $this->money($l->amount); ?></td>
                    <td><span class="bp-badge bp-badge-<?php echo $scls; ?>"><?php echo $slabel; ?></span></td>
                    <td style="white-space:nowrap">
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpFinEdit(<?php echo $l->id; ?>,<?php echo $company_id; ?>)" title="Editar">✏️</button>
                        <?php if($l->status==='pendente'||$l->status==='vencido'): ?>
                        <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="bpFinPagar(<?php echo $l->id; ?>)" title="Marcar como pago" style="color:var(--green)">✅</button>
                        <?php endif; ?>
                        <button class="bp-btn bp-btn-danger bp-btn-sm" onclick="bpFinExcluir(<?php echo $l->id; ?>)" title="Excluir">🗑</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif($tab==='categorias'): ?>
        <!-- ── Categorias ── -->
        <div style="display:grid;grid-template-columns:300px 1fr;gap:16px;align-items:start" class="bp-animate-in">
            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">Nova Categoria</div></div>
                <div class="bp-field">
                    <label>Tipo</label>
                    <select id="catType" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%">
                        <option value="receita">Receita</option>
                        <option value="despesa">Despesa</option>
                    </select>
                </div>
                <div class="bp-field">
                    <label>Nome da Categoria *</label>
                    <input type="text" id="catName" placeholder="Ex: Serviços, Aluguel..." style="width:100%">
                </div>
                <div class="bp-field">
                    <label>Cor</label>
                    <div style="display:flex;gap:8px;flex-wrap:wrap" id="catColors">
                        <?php foreach(['#22c55e','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#ec4899'] as $cor): ?>
                        <div onclick="bpFinSelectCor(this,'<?php echo $cor; ?>')"
                             style="width:28px;height:28px;border-radius:50%;background:<?php echo $cor; ?>;cursor:pointer;border:2px solid transparent;transition:.15s"
                             data-cor="<?php echo $cor; ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" id="catColor" value="#22c55e">
                </div>
                <button class="bp-btn bp-btn-primary" style="width:100%;justify-content:center;margin-top:8px" onclick="bpFinSalvarCat(<?php echo $company_id; ?>)">💾 Salvar Categoria</button>
            </div>

            <div class="bp-card">
                <div class="bp-card-header"><div class="bp-card-title">Categorias Cadastradas</div></div>
                <?php if(empty($all_cats)): ?>
                <div class="bp-empty" style="padding:24px">
                    <div class="bp-empty-icon">🏷️</div>
                    <div class="bp-empty-title">Nenhuma categoria ainda</div>
                </div>
                <?php else: ?>
                <div class="bp-table-wrap">
                <table class="bp-table">
                    <thead><tr><th>Nome</th><th>Tipo</th><th>Lançamentos</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach($all_cats as $cat):
                        [$tlabel,$tcls] = $type_map[$cat->type] ?? [$cat->type,'gray'];
                        $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}barber_finance WHERE category_id=%d", $cat->id));
                    ?>
                    <tr>
                        <td>
                            <?php if(!empty($cat->color)): ?>
                            <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($cat->color); ?>;margin-right:6px"></span>
                            <?php endif; ?>
                            <?php echo esc_html($cat->name); ?>
                        </td>
                        <td><span class="bp-badge bp-badge-<?php echo $tcls; ?>"><?php echo $tlabel; ?></span></td>
                        <td style="color:var(--text3);font-size:.82rem"><?php echo $count; ?> lançamentos</td>
                        <td>
                            <button class="bp-btn bp-btn-danger bp-btn-sm" onclick="bpFinExcluirCat(<?php echo $cat->id; ?>,<?php echo $company_id; ?>)" <?php echo $count>0?'disabled title="Tem lançamentos"':''; ?>>🗑</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php
    }



}
