<?php
/**
 * View – Módulo Financeiro Completo
 * Inclui: Dashboard, Lançamentos, DRE, Fluxo de Caixa, Contas a Pagar/Receber
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_view_finance' ) ) wp_die( 'Sem permissão.' );

// Detecta aba ativa
$tab = isset( $_GET['fin_tab'] ) ? sanitize_key( $_GET['fin_tab'] ) : 'dashboard';

// Parâmetros de período
$mes_atual  = current_time( 'Y-m' );
$date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-01' );
$date_to    = isset( $_GET['date_to']   ) ? sanitize_text_field( $_GET['date_to'] )   : date( 'Y-m-d'  );

// ── Exportações CSV ───────────────────────────────────────────────────────────
if ( isset( $_GET['export'], $_GET['_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_nonce'] ), 'barberpro_fin_export' ) ) {
    $export = sanitize_key( $_GET['export'] );
    if ( $export === 'lancamentos' ) {
        BarberPro_Finance::export_csv( [ 'date_from' => $date_from, 'date_to' => $date_to ] );
    }
    if ( $export === 'dre' ) {
        BarberPro_Finance::export_dre_csv( $date_from, $date_to );
    }
}

// ── Salvar lançamento ─────────────────────────────────────────────────────────
if ( isset( $_POST['bp_finance_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['bp_finance_nonce'] ), 'barberpro_finance_save' ) ) {
    $fin_id = absint( $_POST['fin_id'] ?? 0 );
    $data   = [
        'type'             => sanitize_key( $_POST['type']             ?? 'despesa' ),
        'category_id'      => absint( $_POST['category_id']           ?? 0 ) ?: null,
        'subcategory'      => sanitize_text_field( $_POST['subcategory']      ?? '' ),
        'description'      => sanitize_text_field( $_POST['description']      ?? '' ),
        'amount'           => (float) str_replace( ['.',',' ], ['','.'], $_POST['amount'] ?? '0' ),
        'payment_method'   => sanitize_key( $_POST['payment_method']  ?? 'dinheiro' ),
        'status'           => sanitize_key( $_POST['status']          ?? 'pago' ),
        'competencia_date' => sanitize_text_field( $_POST['competencia_date'] ?? current_time('Y-m-d') ),
        'due_date'         => sanitize_text_field( $_POST['due_date']         ?? '' ) ?: null,
        'paid_at'          => sanitize_text_field( $_POST['paid_at']          ?? '' ) ?: null,
        'supplier'         => sanitize_text_field( $_POST['supplier']         ?? '' ),
        'cost_center'      => sanitize_text_field( $_POST['cost_center']      ?? '' ),
        'invoice_number'   => sanitize_text_field( $_POST['invoice_number']   ?? '' ),
        'professional_id'  => absint( $_POST['professional_id'] ?? 0 ) ?: null,
        'is_recurring'     => ! empty( $_POST['is_recurring'] ) ? 1 : 0,
        'tags'             => sanitize_text_field( $_POST['tags']             ?? '' ),
        'notes'            => sanitize_textarea_field( $_POST['notes']        ?? '' ),
    ];
    if ( $fin_id ) {
        BarberPro_Finance::update( $fin_id, $data );
    } else {
        BarberPro_Finance::insert( $data );
    }
    $saved = true;
}

// ── Excluir lançamento ────────────────────────────────────────────────────────
if ( isset( $_GET['delete_id'], $_GET['_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_nonce'] ), 'barberpro_fin_delete' ) ) {
    BarberPro_Finance::delete( absint( $_GET['delete_id'] ) );
}

// ── Quitar conta ──────────────────────────────────────────────────────────────
if ( isset( $_GET['pay_id'], $_GET['_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_nonce'] ), 'barberpro_fin_pay' ) ) {
    BarberPro_Finance::update( absint( $_GET['pay_id'] ), [ 'status' => 'pago', 'paid_at' => current_time('mysql') ] );
}

$nonce_export = wp_create_nonce( 'barberpro_fin_export' );
$nonce_delete = wp_create_nonce( 'barberpro_fin_delete' );
$nonce_pay    = wp_create_nonce( 'barberpro_fin_pay' );

// Dados de tela
$categories    = BarberPro_Finance::get_categories();
$professionals = BarberPro_Database::get_professionals();
$dashboard     = ( $tab === 'dashboard' ) ? BarberPro_Finance::get_dashboard() : null;
$dre           = ( $tab === 'dre'        ) ? BarberPro_Finance::get_dre( $date_from, $date_to ) : null;
$cash_flow     = ( $tab === 'fluxo'      ) ? BarberPro_Finance::get_cash_flow( $date_from, $date_to ) : null;
$a_pagar       = ( $tab === 'contas'     ) ? BarberPro_Finance::get_accounts( 'despesa' ) : null;
$a_receber     = ( $tab === 'contas'     ) ? BarberPro_Finance::get_accounts( 'receita' ) : null;
$lancamentos   = ( $tab === 'lancamentos') ? BarberPro_Finance::list([
    'date_from' => $date_from, 'date_to' => $date_to,
    'type'      => sanitize_key( $_GET['type']   ?? '' ),
    'status'    => sanitize_key( $_GET['status'] ?? '' ),
    'search'    => sanitize_text_field( $_GET['search'] ?? '' ),
]) : null;

$page_url = admin_url( 'admin.php?page=barberpro_finance' );

function bp_money( $v ): string {
    return 'R$ ' . number_format( (float) $v, 2, ',', '.' );
}
function bp_tab_url( string $t, array $extra = [] ): string {
    return esc_url( add_query_arg( array_merge( [ 'page' => 'barberpro_finance', 'fin_tab' => $t ], $extra ), admin_url( 'admin.php' ) ) );
}
?>
<div class="wrap barberpro-admin" id="barberproFinance">
    <?php if ( ! empty( $saved ) ) : ?>
    <div class="notice notice-success is-dismissible"><p>✅ Lançamento salvo com sucesso!</p></div>
    <?php endif; ?>

    <h1>💰 Gestão Financeira</h1>

    <!-- ABAS PRINCIPAIS -->
    <nav class="bp-fin-tabs">
        <a href="<?php echo bp_tab_url('dashboard'); ?>" class="bp-fin-tab <?php echo $tab==='dashboard'?'active':''; ?>">📊 Dashboard</a>
        <a href="<?php echo bp_tab_url('lancamentos'); ?>" class="bp-fin-tab <?php echo $tab==='lancamentos'?'active':''; ?>">📋 Lançamentos</a>
        <a href="<?php echo bp_tab_url('dre'); ?>" class="bp-fin-tab <?php echo $tab==='dre'?'active':''; ?>">📄 DRE</a>
        <a href="<?php echo bp_tab_url('fluxo'); ?>" class="bp-fin-tab <?php echo $tab==='fluxo'?'active':''; ?>">🌊 Fluxo de Caixa</a>
        <a href="<?php echo bp_tab_url('contas'); ?>" class="bp-fin-tab <?php echo $tab==='contas'?'active':''; ?>">🔔 Contas</a>
        <a href="<?php echo bp_tab_url('categorias'); ?>" class="bp-fin-tab <?php echo $tab==='categorias'?'active':''; ?>">🏷 Plano de Contas</a>
    </nav>

    <!-- Seletor de período (exceto Dashboard e Categorias) -->
    <?php if ( ! in_array( $tab, ['dashboard','categorias','contas'] ) ) : ?>
    <div class="bp-fin-period-bar">
        <form method="get" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="hidden" name="page" value="barberpro_finance">
            <input type="hidden" name="fin_tab" value="<?php echo esc_attr($tab); ?>">
            <label>De: <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>"></label>
            <label>Até: <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>"></label>
            <button type="submit" class="button button-primary">Filtrar</button>
            <a class="button" href="<?php echo esc_url(add_query_arg(['export'=>'lancamentos','_nonce'=>$nonce_export,'date_from'=>$date_from,'date_to'=>$date_to], $page_url.'&fin_tab=lancamentos')); ?>">⬇ CSV Lançamentos</a>
            <a class="button" href="<?php echo esc_url(add_query_arg(['export'=>'dre','_nonce'=>$nonce_export,'date_from'=>$date_from,'date_to'=>$date_to], $page_url.'&fin_tab=dre')); ?>">⬇ CSV DRE</a>
        </form>
    </div>
    <?php endif; ?>

    <!-- ====================================================================
         TAB: DASHBOARD
    ==================================================================== -->
    <?php if ( $tab === 'dashboard' && $dashboard ) : ?>

    <div class="bp-fin-kpis">
        <div class="kpi-card">
            <span class="kpi-icon">💵</span>
            <h3>Receita Hoje</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['today_revenue']); ?></p>
        </div>
        <div class="kpi-card">
            <span class="kpi-icon">📈</span>
            <h3>Receita Mês (Competência)</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['monthly_receita']); ?></p>
        </div>
        <div class="kpi-card kpi-red">
            <span class="kpi-icon">📉</span>
            <h3>Despesas Mês</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['monthly_despesa']); ?></p>
        </div>
        <div class="kpi-card <?php echo $dashboard['resultado_mensal'] >= 0 ? 'kpi-green' : 'kpi-red'; ?>">
            <span class="kpi-icon">🎯</span>
            <h3>Resultado Mês</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['resultado_mensal']); ?></p>
            <small>Margem: <?php echo esc_html($dashboard['margem_pct']); ?>%</small>
        </div>
        <div class="kpi-card">
            <span class="kpi-icon">🏦</span>
            <h3>Saldo em Caixa (Efetivo)</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['caixa_saldo']); ?></p>
        </div>
        <?php if ( $dashboard['vencidos_a_pagar'] > 0 ) : ?>
        <div class="kpi-card kpi-red">
            <span class="kpi-icon">⚠️</span>
            <h3>A Pagar Vencido</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['vencidos_a_pagar']); ?></p>
        </div>
        <?php endif; ?>
        <?php if ( $dashboard['vencidos_a_receber'] > 0 ) : ?>
        <div class="kpi-card kpi-yellow">
            <span class="kpi-icon">🔔</span>
            <h3>A Receber Vencido</h3>
            <p class="kpi-value"><?php echo bp_money($dashboard['vencidos_a_receber']); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <div class="bp-fin-grid-2">
        <!-- Gráfico 12 meses -->
        <div class="bp-fin-card">
            <h2>📊 Receita vs Despesa – 12 meses</h2>
            <canvas id="chartReceita" height="220"></canvas>
            <script>window.bpChart12m = <?php echo wp_json_encode($dashboard['chart_12m']); ?>;</script>
        </div>

        <!-- Receita por método de pagamento -->
        <div class="bp-fin-card">
            <h2>💳 Receita por Forma de Pagamento (Mês)</h2>
            <canvas id="chartMethod" height="220"></canvas>
            <script>window.bpByMethod = <?php echo wp_json_encode($dashboard['by_method']); ?>;</script>
            <table class="widefat striped" style="margin-top:12px">
                <thead><tr><th>Forma</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ( $dashboard['by_method'] as $m ) : ?>
                <tr>
                    <td><?php echo esc_html(ucfirst(str_replace('_',' ',$m->payment_method))); ?></td>
                    <td><?php echo bp_money($m->total); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Despesas -->
        <div class="bp-fin-card">
            <h2>🔴 Top Despesas por Categoria (Mês)</h2>
            <canvas id="chartExpenses" height="220"></canvas>
            <script>window.bpTopExpenses = <?php echo wp_json_encode($dashboard['top_expenses']); ?>;</script>
            <table class="widefat striped" style="margin-top:12px">
                <thead><tr><th>Categoria</th><th>Cód.</th><th>Total</th></tr></thead>
                <tbody>
                <?php foreach ( $dashboard['top_expenses'] as $e ) : ?>
                <tr>
                    <td>
                        <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($e->color); ?>;margin-right:6px"></span>
                        <?php echo esc_html($e->name); ?>
                    </td>
                    <td><code><?php echo esc_html($e->code); ?></code></td>
                    <td><?php echo bp_money($e->total); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Orçamento vs Real -->
        <div class="bp-fin-card">
            <h2>🎯 Orçado vs Realizado (Despesas / Mês)</h2>
            <?php if ( empty($dashboard['budget_vs_real']) ) : ?>
                <p style="color:#9ca3af">Nenhum orçamento configurado. <a href="<?php echo bp_tab_url('categorias'); ?>">Configure na aba Plano de Contas</a>.</p>
            <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Categoria</th><th>Orçado</th><th>Realizado</th><th>Variação</th></tr></thead>
                <tbody>
                <?php foreach ( $dashboard['budget_vs_real'] as $bv ) :
                    $var = (float)$bv->variance;
                    $cls = $var > 0 ? 'color:#ef4444' : 'color:#10b981';
                ?>
                <tr>
                    <td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($bv->color); ?>;margin-right:6px"></span><?php echo esc_html($bv->name); ?></td>
                    <td><?php echo bp_money($bv->budget); ?></td>
                    <td><?php echo bp_money($bv->real_total); ?></td>
                    <td style="<?php echo esc_attr($cls); ?>;font-weight:600">
                        <?php echo ( $var > 0 ? '+' : '' ) . bp_money($var); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Comissões pendentes -->
        <?php if ( ! empty($dashboard['commissions_pending']) ) : ?>
        <div class="bp-fin-card">
            <h2>👨 Comissões Pendentes por Profissional</h2>
            <table class="widefat striped">
                <thead><tr><th>Profissional</th><th>Atendimentos</th><th>A Pagar</th></tr></thead>
                <tbody>
                <?php foreach ( $dashboard['commissions_pending'] as $c ) : ?>
                <tr>
                    <td><?php echo esc_html($c->name); ?></td>
                    <td><?php echo esc_html($c->qty); ?></td>
                    <td><strong><?php echo bp_money($c->total); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ====================================================================
         TAB: LANÇAMENTOS
    ==================================================================== -->
    <?php elseif ( $tab === 'lancamentos' ) :

    // Editar lançamento existente?
    $editing = null;
    if ( isset( $_GET['edit_id'] ) ) {
        $editing = BarberPro_Finance::get( absint( $_GET['edit_id'] ) );
    }
    ?>
    <div class="bp-fin-grid-2" style="margin-top:16px">

        <!-- Formulário de lançamento -->
        <div class="bp-fin-card">
            <h2><?php echo $editing ? '✏️ Editar Lançamento' : '➕ Novo Lançamento'; ?></h2>
            <form method="post">
                <?php wp_nonce_field('barberpro_finance_save','bp_finance_nonce'); ?>
                <input type="hidden" name="fin_id" value="<?php echo $editing ? esc_attr($editing->id) : '0'; ?>">

                <div class="bp-form-row-2">
                    <div class="bp-field">
                        <label>Tipo *</label>
                        <select name="type" id="bpFinType" required>
                            <option value="receita" <?php selected($editing->type??'','receita'); ?>>💚 Receita</option>
                            <option value="despesa" <?php selected($editing->type??'despesa','despesa'); ?>>🔴 Despesa</option>
                        </select>
                    </div>
                    <div class="bp-field">
                        <label>Categoria *</label>
                        <select name="category_id" id="bpFinCat">
                            <option value="">-- Selecione --</option>
                            <?php
                            $rec_cats  = array_filter($categories, function($c) { return $c->type === 'receita'; });
                            $desp_cats = array_filter($categories, function($c) { return $c->type === 'despesa'; });
                            ?>
                            <optgroup label="── RECEITAS ──">
                            <?php foreach ( $rec_cats as $c ) : ?>
                                <option value="<?php echo esc_attr($c->id); ?>" data-type="receita"
                                    <?php selected($editing->category_id??0,$c->id); ?>>
                                    <?php echo esc_html("[{$c->code}] {$c->name}"); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="── DESPESAS ──">
                            <?php foreach ( $desp_cats as $c ) : ?>
                                <option value="<?php echo esc_attr($c->id); ?>" data-type="despesa"
                                    <?php selected($editing->category_id??0,$c->id); ?>>
                                    <?php echo esc_html("[{$c->code}] {$c->name}"); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                </div>

                <div class="bp-field">
                    <label>Descrição *</label>
                    <input type="text" name="description" value="<?php echo esc_attr($editing->description??''); ?>" required placeholder="Ex: Aluguel Dezembro/2025">
                </div>

                <div class="bp-form-row-2">
                    <div class="bp-field">
                        <label>Valor (R$) *</label>
                        <input type="text" name="amount" value="<?php echo $editing ? esc_attr(number_format($editing->amount,2,',','.')) : ''; ?>" required placeholder="0,00">
                    </div>
                    <div class="bp-field">
                        <label>Forma de Pagamento</label>
                        <select name="payment_method">
                            <?php foreach (['dinheiro'=>'Dinheiro','pix'=>'PIX','cartao_debito'=>'Cartão Débito','cartao_credito'=>'Cartão Crédito','transferencia'=>'Transferência','boleto'=>'Boleto','cheque'=>'Cheque','outro'=>'Outro'] as $v=>$l) : ?>
                            <option value="<?php echo esc_attr($v); ?>" <?php selected($editing->payment_method??'dinheiro',$v); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="bp-form-row-3">
                    <div class="bp-field">
                        <label>Competência * <small>(Accrual)</small></label>
                        <input type="date" name="competencia_date" value="<?php echo esc_attr($editing->competencia_date ?? current_time('Y-m-d')); ?>" required>
                    </div>
                    <div class="bp-field">
                        <label>Vencimento</label>
                        <input type="date" name="due_date" value="<?php echo esc_attr($editing->due_date??''); ?>">
                    </div>
                    <div class="bp-field">
                        <label>Data Pagamento</label>
                        <input type="date" name="paid_at" value="<?php echo esc_attr($editing ? substr($editing->paid_at??'',0,10) : current_time('Y-m-d')); ?>">
                    </div>
                </div>

                <div class="bp-field">
                    <label>Status</label>
                    <select name="status">
                        <option value="pago"     <?php selected($editing->status??'pago','pago'); ?>>✅ Pago / Recebido</option>
                        <option value="pendente" <?php selected($editing->status??'','pendente'); ?>>⏳ Pendente</option>
                        <option value="vencido"  <?php selected($editing->status??'','vencido'); ?>>🔴 Vencido</option>
                    </select>
                </div>

                <hr>
                <details>
                    <summary style="cursor:pointer;font-weight:600;margin-bottom:8px">🔍 Campos Contábeis Adicionais</summary>
                    <div class="bp-form-row-2">
                        <div class="bp-field">
                            <label>Fornecedor / Cliente</label>
                            <input type="text" name="supplier" value="<?php echo esc_attr($editing->supplier??''); ?>" placeholder="Nome do fornecedor ou cliente">
                        </div>
                        <div class="bp-field">
                            <label>NF / Recibo / Doc.</label>
                            <input type="text" name="invoice_number" value="<?php echo esc_attr($editing->invoice_number??''); ?>" placeholder="Ex: NF 12345">
                        </div>
                    </div>
                    <div class="bp-form-row-2">
                        <div class="bp-field">
                            <label>Subcategoria</label>
                            <input type="text" name="subcategory" value="<?php echo esc_attr($editing->subcategory??''); ?>" placeholder="Ex: Fixo / Variável">
                        </div>
                        <div class="bp-field">
                            <label>Centro de Custo</label>
                            <input type="text" name="cost_center" value="<?php echo esc_attr($editing->cost_center??''); ?>" placeholder="Ex: Sala 1, Online">
                        </div>
                    </div>
                    <div class="bp-form-row-2">
                        <div class="bp-field">
                            <label>Profissional Relacionado</label>
                            <select name="professional_id">
                                <option value="">-- Nenhum --</option>
                                <?php foreach ( $professionals as $pr ) : ?>
                                <option value="<?php echo esc_attr($pr->id); ?>" <?php selected($editing->professional_id??0,$pr->id); ?>><?php echo esc_html($pr->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="bp-field">
                            <label>Tags</label>
                            <input type="text" name="tags" value="<?php echo esc_attr($editing->tags??''); ?>" placeholder="fixo, mensal, essencial">
                        </div>
                    </div>
                    <div class="bp-field">
                        <label><input type="checkbox" name="is_recurring" value="1" <?php checked($editing->is_recurring??0,1); ?>> Lançamento Recorrente</label>
                    </div>
                    <div class="bp-field">
                        <label>Observações</label>
                        <textarea name="notes" rows="2"><?php echo esc_textarea($editing->notes??''); ?></textarea>
                    </div>
                </details>

                <div style="display:flex;gap:8px;margin-top:16px">
                    <button type="submit" class="button button-primary">
                        <?php echo $editing ? '✅ Salvar Alterações' : '➕ Registrar Lançamento'; ?>
                    </button>
                    <?php if ( $editing ) : ?>
                    <a href="<?php echo esc_url(bp_tab_url('lancamentos')); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Lista de lançamentos -->
        <div class="bp-fin-card bp-fin-card-full">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
                <h2 style="margin:0">📋 Lançamentos <?php echo esc_html($date_from); ?> → <?php echo esc_html($date_to); ?></h2>
                <a class="button" href="<?php echo esc_url(add_query_arg(['export'=>'lancamentos','_nonce'=>$nonce_export,'date_from'=>$date_from,'date_to'=>$date_to],$page_url.'&fin_tab=lancamentos')); ?>">
                    ⬇ Exportar CSV (Contador)
                </a>
            </div>
            <!-- Filtros inline -->
            <form method="get" style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap">
                <input type="hidden" name="page" value="barberpro_finance">
                <input type="hidden" name="fin_tab" value="lancamentos">
                <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                <input type="date" name="date_to"   value="<?php echo esc_attr($date_to); ?>">
                <select name="type">
                    <option value="">Tipo</option>
                    <option value="receita" <?php selected($_GET['type']??'','receita'); ?>>Receita</option>
                    <option value="despesa" <?php selected($_GET['type']??'','despesa'); ?>>Despesa</option>
                </select>
                <select name="status">
                    <option value="">Status</option>
                    <option value="pago"     <?php selected($_GET['status']??'','pago'); ?>>Pago</option>
                    <option value="pendente" <?php selected($_GET['status']??'','pendente'); ?>>Pendente</option>
                    <option value="vencido"  <?php selected($_GET['status']??'','vencido'); ?>>Vencido</option>
                </select>
                <input type="text" name="search" value="<?php echo esc_attr($_GET['search']??''); ?>" placeholder="Buscar descrição, fornecedor...">
                <button type="submit" class="button">Filtrar</button>
            </form>

            <?php
            $total_r = 0; $total_d = 0;
            foreach ( $lancamentos as $l ) {
                if ( $l->type === 'receita' ) $total_r += $l->amount;
                else                          $total_d += $l->amount;
            }
            ?>
            <div style="display:flex;gap:16px;margin-bottom:10px;font-weight:600">
                <span style="color:#10b981">↑ Receitas: <?php echo bp_money($total_r); ?></span>
                <span style="color:#ef4444">↓ Despesas: <?php echo bp_money($total_d); ?></span>
                <span style="color:<?php echo ($total_r-$total_d)>=0?'#10b981':'#ef4444'; ?>">
                    = Saldo: <?php echo bp_money($total_r-$total_d); ?>
                </span>
            </div>

            <div style="overflow-x:auto">
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th style="width:40px">ID</th>
                    <th style="width:70px">Tipo</th>
                    <th>Categoria</th>
                    <th>Descrição</th>
                    <th>Fornecedor</th>
                    <th style="width:80px">NF/Doc</th>
                    <th style="width:90px">Competência</th>
                    <th style="width:90px">Vencimento</th>
                    <th style="width:90px">Pgto</th>
                    <th style="width:100px;text-align:right">Valor</th>
                    <th style="width:80px">Status</th>
                    <th style="width:80px">Forma</th>
                    <th style="width:100px">Ações</th>
                </tr></thead>
                <tbody>
                <?php foreach ( $lancamentos as $l ) :
                    $type_color = $l->type === 'receita' ? '#10b981' : '#ef4444';
                    $status_map = ['pago'=>['✅','kpi-green'],'pendente'=>['⏳',''],'vencido'=>['🔴','kpi-red'],'cancelado'=>['❌','']];
                    [$st_icon, $st_cls] = $status_map[$l->status] ?? ['?',''];
                ?>
                <tr>
                    <td><small>#<?php echo esc_html($l->id); ?></small></td>
                    <td><strong style="color:<?php echo esc_attr($type_color); ?>"><?php echo $l->type==='receita'?'↑':'↓'; ?></strong></td>
                    <td>
                        <?php if ($l->category_color) : ?>
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($l->category_color); ?>;margin-right:4px"></span>
                        <?php endif; ?>
                        <small><?php echo esc_html($l->category_name??'-'); ?></small>
                    </td>
                    <td><?php echo esc_html($l->description); ?></td>
                    <td><small><?php echo esc_html($l->supplier??''); ?></small></td>
                    <td><small><?php echo esc_html($l->invoice_number??''); ?></small></td>
                    <td><?php echo esc_html($l->competencia_date); ?></td>
                    <td><?php echo esc_html($l->due_date??''); ?></td>
                    <td><?php echo esc_html($l->paid_at ? substr($l->paid_at,0,10) : ''); ?></td>
                    <td style="text-align:right;font-weight:700;color:<?php echo esc_attr($type_color); ?>">
                        <?php echo bp_money($l->amount); ?>
                    </td>
                    <td><?php echo esc_html($st_icon.' '.ucfirst($l->status)); ?></td>
                    <td><small><?php echo esc_html(ucfirst(str_replace('_',' ',$l->payment_method))); ?></small></td>
                    <td>
                        <?php if ( $l->status === 'pendente' || $l->status === 'vencido' ) : ?>
                        <a href="<?php echo esc_url(add_query_arg(['pay_id'=>$l->id,'_nonce'=>$nonce_pay,'fin_tab'=>'lancamentos','date_from'=>$date_from,'date_to'=>$date_to],$page_url)); ?>" class="button button-small" title="Quitar" onclick="return confirm('Marcar como pago?')">✅</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(add_query_arg(['edit_id'=>$l->id,'fin_tab'=>'lancamentos','date_from'=>$date_from,'date_to'=>$date_to],$page_url)); ?>" class="button button-small">✏️</a>
                        <a href="<?php echo esc_url(add_query_arg(['delete_id'=>$l->id,'_nonce'=>$nonce_delete,'fin_tab'=>'lancamentos','date_from'=>$date_from,'date_to'=>$date_to],$page_url)); ?>" class="button button-small" style="color:#ef4444" onclick="return confirm('Excluir este lançamento?')">🗑</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ( empty($lancamentos) ) : ?>
                <tr><td colspan="13" style="text-align:center;padding:20px">Nenhum lançamento encontrado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- ====================================================================
         TAB: DRE
    ==================================================================== -->
    <?php elseif ( $tab === 'dre' && $dre ) : ?>
    <div class="bp-fin-card" style="margin-top:16px;max-width:800px">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <h2>📄 DRE – <?php echo esc_html($date_from); ?> a <?php echo esc_html($date_to); ?></h2>
            <a class="button" href="<?php echo esc_url(add_query_arg(['export'=>'dre','_nonce'=>$nonce_export,'date_from'=>$date_from,'date_to'=>$date_to],$page_url.'&fin_tab=dre')); ?>">⬇ Exportar DRE</a>
        </div>
        <table class="widefat" style="border-collapse:separate;border-spacing:0">
            <thead>
                <tr style="background:#1a1a2e;color:#fff">
                    <th style="padding:12px">Conta</th>
                    <th style="padding:12px;width:80px">Cód.</th>
                    <th style="padding:12px;text-align:right">Valor (R$)</th>
                    <th style="padding:12px;text-align:right">%</th>
                </tr>
            </thead>
            <tbody>
                <!-- RECEITAS -->
                <tr style="background:#d1fae5"><td colspan="4" style="padding:10px 12px;font-weight:700;color:#065f46">📈 RECEITAS BRUTAS</td></tr>
                <?php foreach ( $dre['receitas'] as $r ) : ?>
                <tr>
                    <td style="padding:8px 12px 8px 24px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($r->color); ?>;margin-right:6px"></span>
                        <?php echo esc_html($r->category); ?>
                    </td>
                    <td style="padding:8px"><code><?php echo esc_html($r->code); ?></code></td>
                    <td style="text-align:right;padding:8px;color:#10b981;font-weight:600"><?php echo bp_money($r->total); ?></td>
                    <td style="text-align:right;padding:8px;color:#6b7280">
                        <?php echo $dre['total_receita'] > 0 ? round($r->total/$dre['total_receita']*100,1).'%' : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#6ee7b7;font-weight:700">
                    <td style="padding:10px 12px">TOTAL RECEITAS</td><td></td>
                    <td style="text-align:right;padding:10px;font-size:1.05rem"><?php echo bp_money($dre['total_receita']); ?></td>
                    <td style="text-align:right;padding:10px">100%</td>
                </tr>

                <!-- DESPESAS -->
                <tr><td colspan="4" style="padding:4px"></td></tr>
                <tr style="background:#fee2e2"><td colspan="4" style="padding:10px 12px;font-weight:700;color:#991b1b">📉 DESPESAS OPERACIONAIS</td></tr>
                <?php foreach ( $dre['despesas'] as $d ) : ?>
                <tr>
                    <td style="padding:8px 12px 8px 24px">
                        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($d->color); ?>;margin-right:6px"></span>
                        <?php echo esc_html($d->category); ?>
                    </td>
                    <td style="padding:8px"><code><?php echo esc_html($d->code); ?></code></td>
                    <td style="text-align:right;padding:8px;color:#ef4444;font-weight:600">(<?php echo bp_money($d->total); ?>)</td>
                    <td style="text-align:right;padding:8px;color:#6b7280">
                        <?php echo $dre['total_receita'] > 0 ? round($d->total/$dre['total_receita']*100,1).'%' : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:#fca5a5;font-weight:700">
                    <td style="padding:10px 12px">TOTAL DESPESAS</td><td></td>
                    <td style="text-align:right;padding:10px;font-size:1.05rem">(<?php echo bp_money($dre['total_despesa']); ?>)</td>
                    <td style="text-align:right;padding:10px">
                        <?php echo $dre['total_receita'] > 0 ? round($dre['total_despesa']/$dre['total_receita']*100,1).'%' : '—'; ?>
                    </td>
                </tr>

                <!-- RESULTADO -->
                <tr><td colspan="4" style="padding:4px"></td></tr>
                <?php $res = $dre['resultado_bruto']; $res_cor = $res >= 0 ? '#065f46' : '#991b1b'; $res_bg = $res >= 0 ? '#a7f3d0' : '#fca5a5'; ?>
                <tr style="background:<?php echo esc_attr($res_bg); ?>">
                    <td colspan="2" style="padding:14px 12px;font-size:1.1rem;font-weight:700;color:<?php echo esc_attr($res_cor); ?>">
                        🎯 RESULTADO DO PERÍODO
                    </td>
                    <td style="text-align:right;padding:14px;font-size:1.2rem;font-weight:700;color:<?php echo esc_attr($res_cor); ?>">
                        <?php echo bp_money($res); ?>
                    </td>
                    <td style="text-align:right;padding:14px;font-weight:700;color:<?php echo esc_attr($res_cor); ?>">
                        <?php echo $dre['margem_pct']; ?>%
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ====================================================================
         TAB: FLUXO DE CAIXA
    ==================================================================== -->
    <?php elseif ( $tab === 'fluxo' && $cash_flow ) : ?>
    <div class="bp-fin-card" style="margin-top:16px">
        <h2>🌊 Fluxo de Caixa (Regime de Caixa) — <?php echo esc_html($date_from); ?> a <?php echo esc_html($date_to); ?></h2>
        <div class="bp-fin-kpis" style="margin-bottom:16px">
            <div class="kpi-card"><h3>Entradas</h3><p class="kpi-value" style="color:#10b981"><?php echo bp_money($cash_flow['total_receita']); ?></p></div>
            <div class="kpi-card"><h3>Saídas</h3><p class="kpi-value" style="color:#ef4444"><?php echo bp_money($cash_flow['total_despesa']); ?></p></div>
            <div class="kpi-card <?php echo $cash_flow['saldo_periodo']>=0?'kpi-green':'kpi-red'; ?>">
                <h3>Saldo do Período</h3>
                <p class="kpi-value"><?php echo bp_money($cash_flow['saldo_periodo']); ?></p>
            </div>
        </div>
        <canvas id="chartCashFlow" height="200"></canvas>
        <script>window.bpCashFlow = <?php echo wp_json_encode($cash_flow['days']); ?>;</script>
        <div style="overflow-x:auto;margin-top:16px">
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th>Data</th>
                <th style="text-align:right;color:#10b981">Entradas</th>
                <th style="text-align:right;color:#ef4444">Saídas</th>
                <th style="text-align:right">Saldo Dia</th>
                <th style="text-align:right">Saldo Acumulado</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $cash_flow['days'] as $d ) : ?>
            <tr>
                <td><?php echo esc_html(date_i18n('d/m/Y',strtotime($d['date']))); ?></td>
                <td style="text-align:right;color:#10b981"><?php echo bp_money($d['receita']); ?></td>
                <td style="text-align:right;color:#ef4444"><?php echo bp_money($d['despesa']); ?></td>
                <td style="text-align:right;font-weight:600;color:<?php echo $d['saldo']>=0?'#10b981':'#ef4444'; ?>">
                    <?php echo bp_money($d['saldo']); ?>
                </td>
                <td style="text-align:right;font-weight:700;color:<?php echo $d['saldo_acum']>=0?'#1a1a2e':'#ef4444'; ?>">
                    <?php echo bp_money($d['saldo_acum']); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if ( empty($cash_flow['days']) ) : ?>
            <tr><td colspan="5" style="text-align:center">Nenhuma movimentação no período.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- ====================================================================
         TAB: CONTAS A PAGAR / RECEBER
    ==================================================================== -->
    <?php elseif ( $tab === 'contas' ) : ?>
    <div class="bp-fin-grid-2" style="margin-top:16px">

        <!-- A RECEBER -->
        <div class="bp-fin-card">
            <h2>🟢 Contas a Receber</h2>
            <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap">
                <div class="kpi-card" style="flex:1;min-width:120px"><h3>Pendente</h3><p class="kpi-value" style="color:#10b981"><?php echo bp_money($a_receber['total_pendente']); ?></p></div>
                <div class="kpi-card kpi-red" style="flex:1;min-width:120px"><h3>Vencido</h3><p class="kpi-value"><?php echo bp_money($a_receber['total_vencido']); ?></p></div>
            </div>
            <table class="widefat striped">
                <thead><tr><th>Descrição</th><th>Categoria</th><th>Vencimento</th><th>Dias</th><th>Valor</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($a_receber['items'] as $item) :
                    $days = (int)$item->days_to_due;
                    $dc   = $days < 0 ? 'color:#ef4444' : ($days <= 3 ? 'color:#f59e0b' : 'color:#6b7280');
                ?>
                <tr>
                    <td><?php echo esc_html($item->description); ?><br><small><?php echo esc_html($item->supplier??''); ?></small></td>
                    <td><span style="color:<?php echo esc_attr($item->category_color??'#6b7280'); ?>"><?php echo esc_html($item->category_name??'-'); ?></span></td>
                    <td><?php echo esc_html($item->due_date); ?></td>
                    <td style="<?php echo esc_attr($dc); ?>;font-weight:600"><?php echo $days < 0 ? abs($days).'d atrás' : $days.'d'; ?></td>
                    <td><strong><?php echo bp_money($item->amount); ?></strong></td>
                    <td><a href="<?php echo esc_url(add_query_arg(['pay_id'=>$item->id,'_nonce'=>$nonce_pay,'fin_tab'=>'contas'],$page_url)); ?>" class="button button-small" onclick="return confirm('Marcar como recebido?')">✅</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($a_receber['items'])): ?>
                <tr><td colspan="6" style="text-align:center">Nenhuma conta a receber.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- A PAGAR -->
        <div class="bp-fin-card">
            <h2>🔴 Contas a Pagar</h2>
            <div style="display:flex;gap:12px;margin-bottom:12px;flex-wrap:wrap">
                <div class="kpi-card" style="flex:1;min-width:120px"><h3>Pendente</h3><p class="kpi-value"><?php echo bp_money($a_pagar['total_pendente']); ?></p></div>
                <div class="kpi-card kpi-red" style="flex:1;min-width:120px"><h3>Vencido</h3><p class="kpi-value"><?php echo bp_money($a_pagar['total_vencido']); ?></p></div>
            </div>
            <table class="widefat striped">
                <thead><tr><th>Descrição</th><th>Categoria</th><th>Vencimento</th><th>Dias</th><th>Valor</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($a_pagar['items'] as $item) :
                    $days = (int)$item->days_to_due;
                    $dc   = $days < 0 ? 'color:#ef4444;font-weight:700' : ($days <= 3 ? 'color:#f59e0b' : 'color:#6b7280');
                ?>
                <tr <?php echo $item->status==='vencido' ? 'style="background:#fff5f5"' : ''; ?>>
                    <td><?php echo esc_html($item->description); ?><br><small><?php echo esc_html($item->supplier??''); ?></small></td>
                    <td><span style="color:<?php echo esc_attr($item->category_color??'#6b7280'); ?>"><?php echo esc_html($item->category_name??'-'); ?></span></td>
                    <td><?php echo esc_html($item->due_date); ?></td>
                    <td style="<?php echo esc_attr($dc); ?>"><?php echo $days < 0 ? abs($days).'d atrasado' : $days.'d'; ?></td>
                    <td><strong style="color:#ef4444"><?php echo bp_money($item->amount); ?></strong></td>
                    <td><a href="<?php echo esc_url(add_query_arg(['pay_id'=>$item->id,'_nonce'=>$nonce_pay,'fin_tab'=>'contas'],$page_url)); ?>" class="button button-small" onclick="return confirm('Marcar como pago?')">✅</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($a_pagar['items'])): ?>
                <tr><td colspan="6" style="text-align:center">Nenhuma conta a pagar.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ====================================================================
         TAB: PLANO DE CONTAS / CATEGORIAS
    ==================================================================== -->
    <?php elseif ( $tab === 'categorias' ) :

    // Salvar nova categoria
    if ( isset($_POST['bp_cat_nonce']) && wp_verify_nonce(sanitize_key($_POST['bp_cat_nonce']),'barberpro_cat_save') ) {
        BarberPro_Finance::insert_category([
            'type'  => sanitize_key($_POST['cat_type']),
            'name'  => sanitize_text_field($_POST['cat_name']),
            'code'  => sanitize_text_field($_POST['cat_code']),
            'color' => sanitize_hex_color($_POST['cat_color']??'#6b7280'),
        ]);
    }
    $all_cats = BarberPro_Finance::get_categories();
    $rec_cats  = array_filter($all_cats, function($c) { return $c->type === 'receita'; });
    $desp_cats = array_filter($all_cats, function($c) { return $c->type === 'despesa'; });
    ?>
    <div class="bp-fin-grid-2" style="margin-top:16px">
        <div class="bp-fin-card">
            <h2>🏷 Plano de Contas – Categorias</h2>
            <p class="description">Categorias marcadas com 🔒 são do sistema e não podem ser excluídas. Você pode adicionar suas próprias.</p>

            <form method="post" style="background:#f8f9fa;padding:16px;border-radius:8px;margin-bottom:20px">
                <?php wp_nonce_field('barberpro_cat_save','bp_cat_nonce'); ?>
                <div class="bp-form-row-2">
                    <div class="bp-field">
                        <label>Tipo *</label>
                        <select name="cat_type" required>
                            <option value="receita">Receita</option>
                            <option value="despesa">Despesa</option>
                        </select>
                    </div>
                    <div class="bp-field">
                        <label>Código Contábil</label>
                        <input type="text" name="cat_code" placeholder="Ex: DESP-050">
                    </div>
                </div>
                <div class="bp-form-row-2">
                    <div class="bp-field">
                        <label>Nome da Categoria *</label>
                        <input type="text" name="cat_name" required placeholder="Ex: Delivery / Frete">
                    </div>
                    <div class="bp-field">
                        <label>Cor</label>
                        <input type="color" name="cat_color" value="#6b7280">
                    </div>
                </div>
                <button type="submit" class="button button-primary">➕ Adicionar Categoria</button>
            </form>

            <h3>📈 Receitas</h3>
            <table class="widefat striped">
                <thead><tr><th>Código</th><th>Nome</th><th>Cor</th><th>Sistema</th></tr></thead>
                <tbody>
                <?php foreach ( $rec_cats as $c ) : ?>
                <tr>
                    <td><code><?php echo esc_html($c->code??''); ?></code></td>
                    <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($c->color); ?>;margin-right:6px"></span><?php echo esc_html($c->name); ?></td>
                    <td><span style="background:<?php echo esc_attr($c->color); ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75rem"><?php echo esc_html($c->color); ?></span></td>
                    <td><?php echo $c->is_system ? '🔒' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3 style="margin-top:20px">📉 Despesas</h3>
            <table class="widefat striped">
                <thead><tr><th>Código</th><th>Nome</th><th>Cor</th><th>Sistema</th></tr></thead>
                <tbody>
                <?php foreach ( $desp_cats as $c ) : ?>
                <tr>
                    <td><code><?php echo esc_html($c->code??''); ?></code></td>
                    <td><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo esc_attr($c->color); ?>;margin-right:6px"></span><?php echo esc_html($c->name); ?></td>
                    <td><span style="background:<?php echo esc_attr($c->color); ?>;color:#fff;padding:2px 8px;border-radius:4px;font-size:.75rem"><?php echo esc_html($c->color); ?></span></td>
                    <td><?php echo $c->is_system ? '🔒' : ''; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div><!-- #barberproFinance -->

<style>
.bp-fin-tabs{display:flex;gap:4px;margin:12px 0 0;border-bottom:2px solid #e0e0e0;flex-wrap:wrap}
.bp-fin-tab{padding:10px 18px;text-decoration:none;color:#4b5563;font-weight:600;border-radius:6px 6px 0 0;font-size:.9rem;transition:all .2s}
.bp-fin-tab:hover{background:#f3f4f6;color:#1a1a2e}
.bp-fin-tab.active{background:#1a1a2e;color:#fff}
.bp-fin-kpis{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0}
.bp-fin-grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(500px,1fr));gap:16px;margin-top:0}
.bp-fin-card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.bp-fin-card h2{margin:0 0 16px;font-size:1rem}
.bp-fin-card-full{grid-column:1/-1}
.bp-fin-period-bar{background:#f8f9fa;padding:12px 16px;border-radius:8px;margin:12px 0;border:1px solid #e0e0e0}
.bp-form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.bp-form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}
.bp-field{margin-bottom:12px}
.bp-field label{display:block;font-weight:600;font-size:.85rem;margin-bottom:4px;color:#374151}
.bp-field input,.bp-field select,.bp-field textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem}
.bp-field input:focus,.bp-field select:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.2)}
.kpi-yellow{border-left-color:#f59e0b}
details summary{padding:8px 0;color:#4b5563}
details[open] summary{color:#1a1a2e}
</style>
