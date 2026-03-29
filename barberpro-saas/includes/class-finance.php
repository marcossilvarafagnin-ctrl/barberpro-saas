<?php
/**
 * Módulo Financeiro Completo – Contábil, DRE, Fluxo de Caixa, Contas a Pagar/Receber
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Finance {

    // =========================================================================
    // CRUD DE LANÇAMENTOS
    // =========================================================================

    /**
     * Insere um lançamento financeiro com todos os campos contábeis.
     */
    public static function insert( array $data ): int|false {
        global $wpdb;
        $cid = (int) ( $data['company_id'] ?? BarberPro_Database::get_company_id() );

        // Normaliza datas
        $competencia = sanitize_text_field( $data['competencia_date'] ?? $data['transaction_date'] ?? current_time( 'Y-m-d' ) );
        $due_date    = ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : null;

        // Status automático: se não tem vencimento e type=pagamento => 'pago'; senão 'pendente'
        $status = sanitize_key( $data['status'] ?? 'pago' );
        if ( $status === 'pendente' && $due_date && $due_date < current_time( 'Y-m-d' ) ) {
            $status = 'vencido';
        }

        $clean = [
            'company_id'       => $cid,
            'booking_id'       => ! empty( $data['booking_id'] )     ? (int) $data['booking_id']       : null,
            'type'             => sanitize_key( $data['type'] ),
            'category_id'      => ! empty( $data['category_id'] )    ? (int) $data['category_id']      : null,
            'subcategory'      => sanitize_text_field( $data['subcategory'] ?? '' ) ?: null,
            'description'      => sanitize_text_field( $data['description'] ?? 'Lançamento' ),
            'amount'           => round( (float) ( $data['amount'] ?? 0 ), 2 ),
            'payment_method'   => sanitize_key( $data['payment_method'] ?? 'dinheiro' ),
            'status'           => $status,
            'competencia_date' => $competencia,
            'due_date'         => $due_date,
            'paid_at'          => ( $status === 'pago' )
                                    ? ( $data['paid_at'] ?? current_time( 'mysql' ) )
                                    : null,
            'professional_id'  => ! empty( $data['professional_id'] ) ? (int) $data['professional_id'] : null,
            'cost_center'      => sanitize_text_field( $data['cost_center']   ?? '' ) ?: null,
            'supplier'         => sanitize_text_field( $data['supplier']       ?? '' ) ?: null,
            'invoice_number'   => sanitize_text_field( $data['invoice_number'] ?? '' ) ?: null,
            'is_recurring'     => ! empty( $data['is_recurring'] ) ? 1 : 0,
            'recurring_group'  => sanitize_text_field( $data['recurring_group'] ?? '' ) ?: null,
            'tags'             => sanitize_text_field( $data['tags']  ?? '' ) ?: null,
            'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ) ?: null,
            'created_by'       => get_current_user_id() ?: null,
            'created_at'       => current_time( 'mysql' ),
        ];

        $result = $wpdb->insert( "{$wpdb->prefix}barber_finance", $clean );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Atualiza lançamento existente.
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql' );

        // Se marcou como pago agora
        if ( isset( $data['status'] ) && $data['status'] === 'pago' && empty( $data['paid_at'] ) ) {
            $data['paid_at'] = current_time( 'mysql' );
        }

        // Re-verifica vencimento
        if ( isset( $data['status'] ) && $data['status'] === 'pendente'
            && ! empty( $data['due_date'] ) && $data['due_date'] < current_time( 'Y-m-d' ) ) {
            $data['status'] = 'vencido';
        }

        $allowed = [
            'type','category_id','subcategory','description','amount','payment_method',
            'status','competencia_date','due_date','paid_at','professional_id',
            'cost_center','supplier','invoice_number','is_recurring','tags','notes','updated_at',
        ];
        $clean = array_intersect_key( $data, array_flip( $allowed ) );
        return (bool) $wpdb->update( "{$wpdb->prefix}barber_finance", $clean, [ 'id' => $id ] );
    }

    /**
     * Soft-delete (marca como cancelado).
     */
    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}barber_finance",
            [ 'status' => 'cancelado', 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );
    }

    /**
     * Retorna um lançamento pelo ID.
     */
    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT f.*, c.name AS category_name, c.color AS category_color, c.code AS category_code
             FROM {$wpdb->prefix}barber_finance f
             LEFT JOIN {$wpdb->prefix}barber_finance_categories c ON f.category_id = c.id
             WHERE f.id = %d",
            $id
        ) );
    }

    /**
     * Lista lançamentos com filtros avançados.
     */
    public static function list( array $args = [] ): array {
        global $wpdb;
        $cid   = (int) ( $args['company_id'] ?? BarberPro_Database::get_company_id() );
        $page  = max( 1, (int) ( $args['page']  ?? 1 ) );
        $limit = min( 200, max( 10, (int) ( $args['limit'] ?? 50 ) ) );
        $offset = ( $page - 1 ) * $limit;

        $where  = [ 'f.company_id = %d' ];
        $params = [ $cid ];

        if ( ! empty( $args['type'] ) ) {
            $where[]  = 'f.type = %s';
            $params[] = $args['type'];
        }
        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'f.status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['category_id'] ) ) {
            $where[]  = 'f.category_id = %d';
            $params[] = (int) $args['category_id'];
        }
        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'f.competencia_date >= %s';
            $params[] = $args['date_from'];
        }
        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'f.competencia_date <= %s';
            $params[] = $args['date_to'];
        }
        if ( ! empty( $args['professional_id'] ) ) {
            $where[]  = 'f.professional_id = %d';
            $params[] = (int) $args['professional_id'];
        }
        if ( ! empty( $args['cost_center'] ) ) {
            $where[]  = 'f.cost_center = %s';
            $params[] = $args['cost_center'];
        }
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(f.description LIKE %s OR f.supplier LIKE %s OR f.invoice_number LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like;
        }
        // Não mostra cancelados por padrão
        if ( empty( $args['include_cancelled'] ) ) {
            $where[] = "f.status != 'cancelado'";
        }

        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT f.*, c.name AS category_name, c.color AS category_color, c.code AS category_code,
                       p.name AS professional_name
                FROM {$wpdb->prefix}barber_finance f
                LEFT JOIN {$wpdb->prefix}barber_finance_categories c ON f.category_id = c.id
                LEFT JOIN {$wpdb->prefix}barber_professionals p ON f.professional_id = p.id
                WHERE {$where_sql}
                ORDER BY f.competencia_date DESC, f.created_at DESC
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: []; // phpcs:ignore
    }

    // =========================================================================
    // DRE – DEMONSTRAÇÃO DO RESULTADO DO EXERCÍCIO
    // =========================================================================

    /**
     * Retorna a DRE para um período (mês ou ano).
     *
     * @param string $date_from  Y-m-d
     * @param string $date_to    Y-m-d
     * @param int    $company_id
     * @return array
     */
    public static function get_dre( string $date_from, string $date_to, int $company_id = 0 ): array {
        global $wpdb;
        $cid = $company_id ?: BarberPro_Database::get_company_id();

        // Receita bruta por categoria
        $receitas = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id AS category_id, c.name AS category, c.code, c.color,
                    COALESCE(SUM(f.amount), 0) AS total
             FROM {$wpdb->prefix}barber_finance_categories c
             LEFT JOIN {$wpdb->prefix}barber_finance f
                ON f.category_id = c.id
               AND f.company_id = %d
               AND f.type = 'receita'
               AND f.status IN ('pago','pendente')
               AND f.competencia_date BETWEEN %s AND %s
             WHERE c.company_id = %d AND c.type = 'receita' AND c.status = 'active'
             GROUP BY c.id
             ORDER BY c.sort_order",
            $cid, $date_from, $date_to, $cid
        ) ) ?: [];

        // Despesas por categoria
        $despesas = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.id AS category_id, c.name AS category, c.code, c.color,
                    COALESCE(SUM(f.amount), 0) AS total
             FROM {$wpdb->prefix}barber_finance_categories c
             LEFT JOIN {$wpdb->prefix}barber_finance f
                ON f.category_id = c.id
               AND f.company_id = %d
               AND f.type = 'despesa'
               AND f.status IN ('pago','pendente','vencido')
               AND f.competencia_date BETWEEN %s AND %s
             WHERE c.company_id = %d AND c.type = 'despesa' AND c.status = 'active'
             GROUP BY c.id
             ORDER BY c.sort_order",
            $cid, $date_from, $date_to, $cid
        ) ) ?: [];

        $total_receita = array_sum( array_column( $receitas, 'total' ) );
        $total_despesa = array_sum( array_column( $despesas, 'total' ) );

        // Comissões pagas no período (já estão em despesas/categoria, mas detalhamos)
        $comissoes = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0)
             FROM {$wpdb->prefix}barber_commissions
             WHERE company_id = %d AND status = 'pago'
               AND paid_at BETWEEN %s AND %s",
            $cid, $date_from . ' 00:00:00', $date_to . ' 23:59:59'
        ) );

        $resultado_bruto = $total_receita - $total_despesa;
        $margem          = $total_receita > 0 ? round( $resultado_bruto / $total_receita * 100, 2 ) : 0;

        return [
            'period'            => [ 'from' => $date_from, 'to' => $date_to ],
            'receitas'          => $receitas,
            'despesas'          => $despesas,
            'total_receita'     => $total_receita,
            'total_despesa'     => $total_despesa,
            'comissoes_pagas'   => $comissoes,
            'resultado_bruto'   => $resultado_bruto,
            'margem_pct'        => $margem,
        ];
    }

    // =========================================================================
    // FLUXO DE CAIXA (Regime de Caixa)
    // =========================================================================

    /**
     * Fluxo de caixa diário para um período.
     */
    public static function get_cash_flow( string $date_from, string $date_to, int $company_id = 0 ): array {
        global $wpdb;
        $cid = $company_id ?: BarberPro_Database::get_company_id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(COALESCE(paid_at, competencia_date)) AS cash_date,
                    type,
                    payment_method,
                    COALESCE(SUM(amount), 0) AS total
             FROM {$wpdb->prefix}barber_finance
             WHERE company_id = %d
               AND status = 'pago'
               AND DATE(COALESCE(paid_at, competencia_date)) BETWEEN %s AND %s
             GROUP BY cash_date, type, payment_method
             ORDER BY cash_date ASC",
            $cid, $date_from, $date_to
        ) ) ?: [];

        // Agrupa por data
        $by_date = [];
        foreach ( $rows as $r ) {
            $d = $r->cash_date;
            if ( ! isset( $by_date[ $d ] ) ) {
                $by_date[ $d ] = [ 'date' => $d, 'receita' => 0, 'despesa' => 0, 'saldo' => 0, 'by_method' => [] ];
            }
            $by_date[ $d ][ $r->type ] += (float) $r->total;
            $by_date[ $d ]['by_method'][ $r->payment_method ][ $r->type ] =
                ( $by_date[ $d ]['by_method'][ $r->payment_method ][ $r->type ] ?? 0 ) + (float) $r->total;
        }

        // Calcula saldo acumulado
        $saldo_acumulado = 0;
        $result = [];
        foreach ( $by_date as &$d ) {
            $d['saldo']       = $d['receita'] - $d['despesa'];
            $saldo_acumulado += $d['saldo'];
            $d['saldo_acum']  = $saldo_acumulado;
            $result[]         = $d;
        }

        return [
            'days'           => $result,
            'total_receita'  => array_sum( array_column( $result, 'receita' ) ),
            'total_despesa'  => array_sum( array_column( $result, 'despesa' ) ),
            'saldo_periodo'  => $saldo_acumulado,
        ];
    }

    // =========================================================================
    // CONTAS A PAGAR / RECEBER
    // =========================================================================

    /**
     * Lista contas a pagar ou receber com filtros.
     */
    public static function get_accounts( string $type, array $args = [] ): array {
        global $wpdb;
        $cid = (int) ( $args['company_id'] ?? BarberPro_Database::get_company_id() );

        $status_filter = "AND f.status IN ('pendente','vencido')";
        if ( ! empty( $args['include_paid'] ) ) {
            $status_filter = "AND f.status IN ('pendente','vencido','pago')";
        }

        $type_label = $type === 'receita' ? 'receber' : 'pagar';
        $order      = $type === 'receita' ? 'ASC' : 'ASC';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT f.*, c.name AS category_name, c.color AS category_color,
                    DATEDIFF(f.due_date, CURDATE()) AS days_to_due
             FROM {$wpdb->prefix}barber_finance f
             LEFT JOIN {$wpdb->prefix}barber_finance_categories c ON f.category_id = c.id
             WHERE f.company_id = %d AND f.type = %s
               AND f.due_date IS NOT NULL
               {$status_filter}
             ORDER BY f.due_date {$order}",
            $cid, $type
        ) ) ?: [];

        // Totais
        $total_pendente = 0;
        $total_vencido  = 0;
        $total_pago     = 0;
        foreach ( $rows as $r ) {
            if ( $r->status === 'pago' )     $total_pago     += (float) $r->amount;
            elseif ( $r->status === 'vencido' ) $total_vencido += (float) $r->amount;
            else                             $total_pendente += (float) $r->amount;
        }

        return [
            'type'           => $type_label,
            'items'          => $rows,
            'total_pendente' => $total_pendente,
            'total_vencido'  => $total_vencido,
            'total_pago'     => $total_pago,
        ];
    }

    // =========================================================================
    // DASHBOARD FINANCEIRO
    // =========================================================================

    public static function get_dashboard( int $company_id = 0 ): array {
        global $wpdb;
        $cid   = $company_id ?: BarberPro_Database::get_company_id();
        $today = current_time( 'Y-m-d' );
        $month = current_time( 'Y-m' );
        $year  = current_time( 'Y' );

        // ── Receita / Despesa / Lucro ────────────────────────────────────────
        $monthly = $wpdb->get_row( $wpdb->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN type='receita' AND status IN ('pago','pendente') THEN amount ELSE 0 END),0) AS receita,
               COALESCE(SUM(CASE WHEN type='despesa' AND status IN ('pago','pendente','vencido') THEN amount ELSE 0 END),0) AS despesa
             FROM {$wpdb->prefix}barber_finance
             WHERE company_id=%d AND DATE_FORMAT(competencia_date,'%%Y-%%m')=%s",
            $cid, $month
        ) );

        // ── Caixa efetivo (só pagos) ─────────────────────────────────────────
        $caixa = $wpdb->get_row( $wpdb->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN type='receita' THEN amount ELSE 0 END),0) AS entradas,
               COALESCE(SUM(CASE WHEN type='despesa' THEN amount ELSE 0 END),0) AS saidas
             FROM {$wpdb->prefix}barber_finance
             WHERE company_id=%d AND status='pago'
               AND DATE_FORMAT(COALESCE(paid_at,competencia_date),'%%Y-%%m')=%s",
            $cid, $month
        ) );

        // ── Vencidos ─────────────────────────────────────────────────────────
        $vencidos = $wpdb->get_row( $wpdb->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN type='receita' THEN amount ELSE 0 END),0) AS a_receber,
               COALESCE(SUM(CASE WHEN type='despesa' THEN amount ELSE 0 END),0) AS a_pagar
             FROM {$wpdb->prefix}barber_finance
             WHERE company_id=%d AND status='vencido'",
            $cid
        ) );

        // ── Receita por método de pagamento ─────────────────────────────────
        $by_method = $wpdb->get_results( $wpdb->prepare(
            "SELECT payment_method, COALESCE(SUM(amount),0) AS total
             FROM {$wpdb->prefix}barber_finance
             WHERE company_id=%d AND type='receita' AND status='pago'
               AND DATE_FORMAT(COALESCE(paid_at,competencia_date),'%%Y-%%m')=%s
             GROUP BY payment_method ORDER BY total DESC",
            $cid, $month
        ) ) ?: [];

        // ── Top despesas por categoria ────────────────────────────────────────
        $top_expenses = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.name, c.color, c.code, COALESCE(SUM(f.amount),0) AS total
             FROM {$wpdb->prefix}barber_finance f
             JOIN {$wpdb->prefix}barber_finance_categories c ON f.category_id = c.id
             WHERE f.company_id=%d AND f.type='despesa'
               AND f.status IN ('pago','pendente','vencido')
               AND DATE_FORMAT(f.competencia_date,'%%Y-%%m')=%s
             GROUP BY c.id ORDER BY total DESC LIMIT 8",
            $cid, $month
        ) ) ?: [];

        // ── Gráfico: últimos 12 meses ─────────────────────────────────────────
        $chart_12m = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE_FORMAT(competencia_date,'%%Y-%%m') AS mes,
                    COALESCE(SUM(CASE WHEN type='receita' AND status IN ('pago','pendente') THEN amount ELSE 0 END),0) AS receita,
                    COALESCE(SUM(CASE WHEN type='despesa' AND status IN ('pago','pendente','vencido') THEN amount ELSE 0 END),0) AS despesa
             FROM {$wpdb->prefix}barber_finance
             WHERE company_id=%d
               AND competencia_date >= DATE_SUB(%s, INTERVAL 11 MONTH)
             GROUP BY mes ORDER BY mes ASC",
            $cid, $today
        ) ) ?: [];

        // ── Comissões pendentes ───────────────────────────────────────────────
        $commissions_pending = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.name, COALESCE(SUM(c.amount),0) AS total, COUNT(*) AS qty
             FROM {$wpdb->prefix}barber_commissions c
             JOIN {$wpdb->prefix}barber_professionals p ON c.professional_id = p.id
             WHERE c.company_id=%d AND c.status='pendente'
             GROUP BY c.professional_id ORDER BY total DESC",
            $cid
        ) ) ?: [];

        // ── Receita hoje ──────────────────────────────────────────────────────
        $today_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}barber_finance
             WHERE company_id=%d AND type='receita' AND status='pago'
               AND DATE(COALESCE(paid_at,competencia_date))=%s",
            $cid, $today
        ) );

        // ── Orçamento vs Real por categoria (mês) ─────────────────────────────
        $budget_vs_real = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.name, c.color, c.code,
                    COALESCE(b.budget, 0) AS budget,
                    COALESCE(SUM(f.amount), 0) AS real_total,
                    COALESCE(SUM(f.amount), 0) - COALESCE(b.budget, 0) AS variance
             FROM {$wpdb->prefix}barber_finance_categories c
             LEFT JOIN {$wpdb->prefix}barber_finance_budget b
                ON b.category_id = c.id AND b.company_id = %d AND b.`year_month` = %s
             LEFT JOIN {$wpdb->prefix}barber_finance f
                ON f.category_id = c.id AND f.company_id = %d
               AND f.type = 'despesa'
               AND f.status IN ('pago','pendente','vencido')
               AND DATE_FORMAT(f.competencia_date,'%%Y-%%m') = %s
             WHERE c.company_id = %d AND c.type = 'despesa' AND c.status = 'active'
               AND (b.budget > 0 OR SUM(f.amount) > 0)
             GROUP BY c.id
             ORDER BY real_total DESC",
            $cid, $month, $cid, $month, $cid
        ) ) ?: [];

        $receita  = (float) ( $monthly->receita  ?? 0 );
        $despesa  = (float) ( $monthly->despesa  ?? 0 );
        $entradas = (float) ( $caixa->entradas   ?? 0 );
        $saidas   = (float) ( $caixa->saidas     ?? 0 );

        return [
            'today_revenue'       => $today_revenue,
            'monthly_receita'     => $receita,
            'monthly_despesa'     => $despesa,
            'resultado_mensal'    => $receita - $despesa,
            'margem_pct'          => $receita > 0 ? round( ( $receita - $despesa ) / $receita * 100, 1 ) : 0,
            'caixa_entradas'      => $entradas,
            'caixa_saidas'        => $saidas,
            'caixa_saldo'         => $entradas - $saidas,
            'vencidos_a_receber'  => (float) ( $vencidos->a_receber ?? 0 ),
            'vencidos_a_pagar'    => (float) ( $vencidos->a_pagar   ?? 0 ),
            'by_method'           => $by_method,
            'top_expenses'        => $top_expenses,
            'chart_12m'           => $chart_12m,
            'commissions_pending' => $commissions_pending,
            'budget_vs_real'      => $budget_vs_real,
        ];
    }

    // =========================================================================
    // RELATÓRIO POR PROFISSIONAL
    // =========================================================================

    public static function get_professional_report( int $professional_id, string $date_from, string $date_to ): array {
        global $wpdb;
        $cid = BarberPro_Database::get_company_id();

        $services_done = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}barber_bookings
             WHERE professional_id=%d AND status='finalizado'
               AND booking_date BETWEEN %s AND %s",
            $professional_id, $date_from, $date_to
        ) );

        $revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}barber_finance
             WHERE professional_id=%d AND type='receita' AND status='pago'
               AND competencia_date BETWEEN %s AND %s",
            $professional_id, $date_from, $date_to
        ) );

        $commission_paid = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}barber_commissions
             WHERE professional_id=%d AND status='pago'
               AND DATE(paid_at) BETWEEN %s AND %s",
            $professional_id, $date_from, $date_to
        ) );

        $commission_pending = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount),0) FROM {$wpdb->prefix}barber_commissions
             WHERE professional_id=%d AND status='pendente'",
            $professional_id
        ) );

        $pro = BarberPro_Database::get_professional( $professional_id );
        $goal = $pro ? (float) $pro->monthly_goal : 0;

        return [
            'professional'       => $pro,
            'period'             => [ 'from' => $date_from, 'to' => $date_to ],
            'services_done'      => $services_done,
            'ticket_medio'       => $services_done > 0 ? round( $revenue / $services_done, 2 ) : 0,
            'revenue'            => $revenue,
            'commission_paid'    => $commission_paid,
            'commission_pending' => $commission_pending,
            'goal'               => $goal,
            'goal_pct'           => $goal > 0 ? min( 100, round( $revenue / $goal * 100, 1 ) ) : 0,
        ];
    }

    // =========================================================================
    // CATEGORIAS
    // =========================================================================

    public static function get_categories( string $type = '', int $company_id = 0 ): array {
        global $wpdb;
        $cid    = $company_id ?: BarberPro_Database::get_company_id();
        $where  = 'WHERE company_id = %d AND status = %s';
        $params = [ $cid, 'active' ];
        if ( $type ) {
            $where   .= ' AND type = %s';
            $params[] = $type;
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_finance_categories {$where} ORDER BY sort_order, name",
            ...$params
        ) ) ?: [];
    }

    public static function insert_category( array $data ): int|false {
        global $wpdb;
        $result = $wpdb->insert( "{$wpdb->prefix}barber_finance_categories", [
            'company_id' => BarberPro_Database::get_company_id(),
            'type'       => sanitize_key( $data['type'] ),
            'name'       => sanitize_text_field( $data['name'] ),
            'code'       => sanitize_text_field( $data['code'] ?? '' ),
            'color'      => sanitize_hex_color( $data['color'] ?? '#6b7280' ),
            'icon'       => sanitize_key( $data['icon'] ?? '' ),
            'is_system'  => 0,
            'status'     => 'active',
            'sort_order' => (int) ( $data['sort_order'] ?? 99 ),
            'created_at' => current_time( 'mysql' ),
        ] );
        return $result ? $wpdb->insert_id : false;
    }

    // =========================================================================
    // EXPORTAÇÃO
    // =========================================================================

    /**
     * Exporta lançamentos em CSV formatado para contador.
     */
    public static function export_csv( array $args = [] ): void {
        if ( ! current_user_can( 'barberpro_view_finance' ) ) {
            wp_die( 'Sem permissão.' );
        }
        $items = self::list( array_merge( $args, [ 'limit' => 9999, 'include_cancelled' => true ] ) );

        $filename = 'lancamentos-' . ( $args['date_from'] ?? date('Y-m') ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // BOM UTF-8 para Excel
        fputs( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, [
            'ID', 'Tipo', 'Categoria (Cód)', 'Categoria', 'Subcategoria',
            'Descrição', 'Fornecedor/Cliente', 'Centro de Custo',
            'Valor (R$)', 'Forma Pgto', 'Status',
            'Competência', 'Vencimento', 'Data Pgto',
            'NF/Recibo', 'Profissional', 'Recorrente', 'Tags', 'Obs',
        ], ';' );

        foreach ( $items as $item ) {
            fputcsv( $out, [
                $item->id,
                $item->type === 'receita' ? 'Receita' : 'Despesa',
                $item->category_code ?? '',
                $item->category_name ?? '',
                $item->subcategory   ?? '',
                $item->description,
                $item->supplier      ?? '',
                $item->cost_center   ?? '',
                number_format( (float) $item->amount, 2, ',', '.' ),
                $item->payment_method,
                $item->status,
                $item->competencia_date,
                $item->due_date      ?? '',
                $item->paid_at       ? substr( $item->paid_at, 0, 10 ) : '',
                $item->invoice_number ?? '',
                $item->professional_name ?? '',
                $item->is_recurring  ? 'Sim' : 'Não',
                $item->tags          ?? '',
                $item->notes         ?? '',
            ], ';' );
        }
        fclose( $out );
        exit;
    }

    /**
     * Exporta DRE em CSV.
     */
    public static function export_dre_csv( string $date_from, string $date_to ): void {
        if ( ! current_user_can( 'barberpro_view_finance' ) ) wp_die( 'Sem permissão.' );

        $dre      = self::get_dre( $date_from, $date_to );
        $filename = 'DRE-' . $date_from . '-a-' . $date_to . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'Conta', 'Cód.', 'Valor (R$)' ], ';' );
        fputcsv( $out, [ '=== RECEITAS ===', '', '' ], ';' );
        foreach ( $dre['receitas'] as $r ) {
            fputcsv( $out, [ $r->category, $r->code, number_format( $r->total, 2, ',', '.' ) ], ';' );
        }
        fputcsv( $out, [ 'TOTAL RECEITAS', '', number_format( $dre['total_receita'], 2, ',', '.' ) ], ';' );
        fputcsv( $out, [ '', '', '' ], ';' );
        fputcsv( $out, [ '=== DESPESAS ===', '', '' ], ';' );
        foreach ( $dre['despesas'] as $d ) {
            fputcsv( $out, [ $d->category, $d->code, number_format( $d->total, 2, ',', '.' ) ], ';' );
        }
        fputcsv( $out, [ 'TOTAL DESPESAS', '', number_format( $dre['total_despesa'], 2, ',', '.' ) ], ';' );
        fputcsv( $out, [ '', '', '' ], ';' );
        fputcsv( $out, [ 'RESULTADO DO PERÍODO', '', number_format( $dre['resultado_bruto'], 2, ',', '.' ) ], ';' );
        fputcsv( $out, [ 'MARGEM (%)', '', $dre['margem_pct'] . '%' ], ';' );
        fclose( $out );
        exit;
    }
    /**
     * Retorna receita, despesa e lucro para um periodo arbitrario.
     * Aceita company_id = 0 para consolidado (todos os modulos ativos).
     */
    public static function get_period_summary( string $date_from, string $date_to, int $company_id = 0 ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $where_cid = $company_id > 0 ? $wpdb->prepare( "AND company_id = %d", $company_id ) : '';

        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT
               COALESCE(SUM(CASE WHEN type='receita' AND status IN ('pago','pendente') THEN amount ELSE 0 END),0) AS receita,
               COALESCE(SUM(CASE WHEN type='despesa' AND status IN ('pago','pendente','vencido') THEN amount ELSE 0 END),0) AS despesa
             FROM {$p}barber_finance
             WHERE competencia_date BETWEEN %s AND %s {$where_cid}",
            $date_from, $date_to
        ) );

        $by_day = $wpdb->get_results( $wpdb->prepare(
            "SELECT competencia_date AS dia,
                    COALESCE(SUM(CASE WHEN type='receita' AND status IN ('pago','pendente') THEN amount ELSE 0 END),0) AS receita,
                    COALESCE(SUM(CASE WHEN type='despesa' AND status IN ('pago','pendente','vencido') THEN amount ELSE 0 END),0) AS despesa
             FROM {$p}barber_finance
             WHERE competencia_date BETWEEN %s AND %s {$where_cid}
             GROUP BY dia ORDER BY dia ASC",
            $date_from, $date_to
        ) ) ?: [];

        $by_method = $wpdb->get_results( $wpdb->prepare(
            "SELECT payment_method, COALESCE(SUM(amount),0) AS total, COUNT(*) AS qty
             FROM {$p}barber_finance
             WHERE type='receita' AND status='pago'
               AND competencia_date BETWEEN %s AND %s {$where_cid}
             GROUP BY payment_method ORDER BY total DESC",
            $date_from, $date_to
        ) ) ?: [];

        $by_company = [];
        if ( $company_id === 0 ) {
            $by_company = $wpdb->get_results( $wpdb->prepare(
                "SELECT company_id,
                        COALESCE(SUM(CASE WHEN type='receita' AND status IN ('pago','pendente') THEN amount ELSE 0 END),0) AS receita,
                        COALESCE(SUM(CASE WHEN type='despesa' AND status IN ('pago','pendente','vencido') THEN amount ELSE 0 END),0) AS despesa
                 FROM {$p}barber_finance
                 WHERE competencia_date BETWEEN %s AND %s
                 GROUP BY company_id",
                $date_from, $date_to
            ) ) ?: [];
        }

        $top_services = $wpdb->get_results( $wpdb->prepare(
            "SELECT service_name, COUNT(*) AS qty, COALESCE(SUM(amount_total),0) AS total
             FROM {$p}barber_bookings
             WHERE status='finalizado' AND booking_date BETWEEN %s AND %s
               {$where_cid_bk}
             GROUP BY service_name ORDER BY total DESC LIMIT 8",
            $date_from, $date_to
        ) ) ?: [];

        $receita = (float)($totals->receita ?? 0);
        $despesa = (float)($totals->despesa ?? 0);

        return [
            'receita'      => $receita,
            'despesa'      => $despesa,
            'lucro'        => $receita - $despesa,
            'margem'       => $receita > 0 ? round((($receita-$despesa)/$receita)*100,1) : 0,
            'by_day'       => $by_day,
            'by_method'    => $by_method,
            'by_company'   => $by_company,
            'top_services' => $top_services,
            'date_from'    => $date_from,
            'date_to'      => $date_to,
        ];
    }

}
