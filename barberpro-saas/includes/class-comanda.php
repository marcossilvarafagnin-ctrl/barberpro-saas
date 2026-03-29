<?php
/**
 * BarberPro – Gestão de Comandas
 * Abre, adiciona itens, fecha, processa pagamento split e lança no financeiro.
 *
 * @package BarberProSaaS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Comanda {

    // ── Abrir comanda ─────────────────────────────────────────────────────────
    public static function open( array $data ): int|false {
        global $wpdb;
        $cid  = (int)( $data['company_id'] ?? BarberPro_Database::get_company_id() );
        $code = self::generate_code( $cid );

        $r = $wpdb->insert( "{$wpdb->prefix}barber_comandas", [
            'company_id'   => $cid,
            'comanda_code' => $code,
            'client_name'  => sanitize_text_field( $data['client_name'] ?? 'Cliente' ),
            'client_phone' => sanitize_text_field( $data['client_phone'] ?? '' ),
            'status'       => 'aberta',
            'notes'        => sanitize_textarea_field( $data['notes'] ?? '' ),
            'opened_by'    => get_current_user_id() ?: null,
            'created_at'   => current_time('mysql'),
        ] );
        return $r ? $wpdb->insert_id : false;
    }

    // ── Adicionar item ────────────────────────────────────────────────────────
    public static function add_item( int $comanda_id, array $data ): int|false {
        global $wpdb;

        $comanda = self::get( $comanda_id );
        if ( ! $comanda || $comanda->status !== 'aberta' ) return false;

        $qty        = max( 0.01, (float)( $data['quantity'] ?? 1 ) );
        $unit_price = (float)( $data['unit_price'] ?? $data['price'] ?? 0 );
        $total      = round( $qty * $unit_price, 2 );

        $r = $wpdb->insert( "{$wpdb->prefix}barber_comanda_items", [
            'comanda_id'      => $comanda_id,
            'company_id'      => $comanda->company_id,
            'professional_id' => ! empty($data['professional_id']) ? (int)$data['professional_id'] : null,
            'service_id'      => ! empty($data['service_id'])      ? (int)$data['service_id']      : null,
            'description'     => sanitize_text_field( $data['description'] ?? 'Item' ),
            'quantity'        => $qty,
            'unit_price'      => $unit_price,
            'total_price'     => $total,
            'item_type'       => sanitize_key( $data['item_type'] ?? 'servico' ),
            'created_at'      => current_time('mysql'),
        ] );

        if ( $r ) self::recalculate( $comanda_id );
        return $r ? $wpdb->insert_id : false;
    }

    // ── Remover item ──────────────────────────────────────────────────────────
    public static function remove_item( int $item_id ): bool {
        global $wpdb;
        $item = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_comanda_items WHERE id=%d", $item_id
        ) );
        if ( ! $item ) return false;
        $r = $wpdb->delete( "{$wpdb->prefix}barber_comanda_items", ['id' => $item_id] );
        if ( $r ) self::recalculate( (int)$item->comanda_id );
        return (bool)$r;
    }

    // ── Aplicar desconto e fechar ─────────────────────────────────────────────
    public static function close( int $comanda_id, float $discount = 0, string $discount_type = 'fixo' ): bool {
        global $wpdb;
        $comanda = self::get( $comanda_id );
        if ( ! $comanda || $comanda->status !== 'aberta' ) return false;

        self::recalculate( $comanda_id, $discount, $discount_type );

        return (bool) $wpdb->update( "{$wpdb->prefix}barber_comandas", [
            'status'        => 'fechada',
            'discount'      => $discount,
            'discount_type' => $discount_type,
            'closed_at'     => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ], ['id' => $comanda_id] );
    }

    // ── Processar pagamento (split) ───────────────────────────────────────────
    /**
     * $payments = [
     *   ['method' => 'pix',      'amount' => 50.00],
     *   ['method' => 'dinheiro', 'amount' => 20.00],
     * ]
     */
    public static function pay( int $comanda_id, array $payments ): array {
        global $wpdb;

        $comanda = self::get( $comanda_id );
        if ( ! $comanda ) return ['success'=>false,'message'=>'Comanda não encontrada.'];
        if ( ! in_array($comanda->status, ['aberta','fechada']) ) {
            return ['success'=>false,'message'=>'Esta comanda já foi paga ou cancelada.'];
        }

        // Fecha se ainda aberta
        if ( $comanda->status === 'aberta' ) self::close( $comanda_id );
        $comanda = self::get( $comanda_id );

        // Valida total dos pagamentos
        $total_paid = array_sum( array_column($payments, 'amount') );
        if ( round($total_paid, 2) < round((float)$comanda->total_final, 2) ) {
            return ['success'=>false,'message'=>sprintf(
                'Valor pago (R$ %.2f) menor que o total da comanda (R$ %.2f).',
                $total_paid, $comanda->total_final
            )];
        }

        // Salva cada parcela de pagamento
        $method_map = []; // para lançar no financeiro
        foreach ( $payments as $pmt ) {
            $method = sanitize_key( $pmt['method'] );
            $amount = round( (float)$pmt['amount'], 2 );
            if ( $amount <= 0 ) continue;

            $wpdb->insert( "{$wpdb->prefix}barber_comanda_payments", [
                'comanda_id'     => $comanda_id,
                'company_id'     => $comanda->company_id,
                'payment_method' => $method,
                'amount'         => $amount,
                'created_at'     => current_time('mysql'),
            ] );
            $method_map[$method] = ($method_map[$method] ?? 0) + $amount;
        }

        // Atualiza comanda como paga
        $wpdb->update( "{$wpdb->prefix}barber_comandas", [
            'status'     => 'paga',
            'total_final'=> $comanda->total_final,
            'paid_at'    => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $comanda_id] );

        // ── Lança receita no financeiro (uma entrada por forma de pagamento) ──
        $items = self::get_items( $comanda_id );
        $desc  = "Comanda #{$comanda->comanda_code} – {$comanda->client_name}";

        foreach ( $method_map as $method => $amount ) {
            BarberPro_Finance::insert([
                'company_id'       => $comanda->company_id,
                'type'             => 'receita',
                'description'      => $desc,
                'amount'           => $amount,
                'payment_method'   => $method,
                'status'           => 'pago',
                'competencia_date' => current_time('Y-m-d'),
                'paid_at'          => current_time('mysql'),
            ]);
        }

        // ── Lança comissões por profissional ──────────────────────────────────
        $commissions = []; // [professional_id => total]
        foreach ( $items as $item ) {
            if ( empty($item->professional_id) ) continue;
            $pid = (int)$item->professional_id;
            $commissions[$pid] = ($commissions[$pid] ?? 0) + (float)$item->total_price;
        }

        foreach ( $commissions as $pid => $gross ) {
            $pro = BarberPro_Database::get_professional( $pid );
            if ( ! $pro ) continue;
            $pct    = (float)($pro->commission_pct ?? 0);
            $amount = round( $gross * $pct / 100, 2 );
            if ( $amount <= 0 ) continue;

            $wpdb->insert( "{$wpdb->prefix}barber_commissions", [
                'company_id'      => $comanda->company_id,
                'professional_id' => $pid,
                'booking_id'      => 0,
                'gross_amount'    => $gross,
                'pct'             => $pct,
                'amount'          => $amount,
                'status'          => 'pendente',
                'created_at'      => current_time('mysql'),
            ] );
        }

        return ['success'=>true,'comanda_code'=>$comanda->comanda_code,'total'=>$comanda->total_final];
    }

    // ── Cancelar ──────────────────────────────────────────────────────────────
    public static function cancel( int $comanda_id ): bool {
        global $wpdb;
        return (bool) $wpdb->update( "{$wpdb->prefix}barber_comandas",
            ['status'=>'cancelada','updated_at'=>current_time('mysql')],
            ['id'=>$comanda_id]
        );
    }

    // ── Recalcular totais ─────────────────────────────────────────────────────
    public static function recalculate( int $comanda_id, float $discount = -1, string $discount_type = '' ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $total_items = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total_price),0) FROM {$p}barber_comanda_items WHERE comanda_id=%d", $comanda_id
        ) );

        // Mantém desconto existente se não foi passado
        if ( $discount < 0 ) {
            $row          = $wpdb->get_row( $wpdb->prepare("SELECT discount,discount_type FROM {$p}barber_comandas WHERE id=%d",$comanda_id) );
            $discount      = (float)($row->discount ?? 0);
            $discount_type = $row->discount_type ?? 'fixo';
        }

        $discount_value = $discount_type === 'percentual'
            ? round( $total_items * $discount / 100, 2 )
            : min( $discount, $total_items );

        $total_final = max( 0, $total_items - $discount_value );

        $wpdb->update( "{$p}barber_comandas", [
            'total_items' => $total_items,
            'total_final' => $total_final,
            'updated_at'  => current_time('mysql'),
        ], ['id' => $comanda_id] );
    }

    // ── Getters ───────────────────────────────────────────────────────────────
    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_comandas WHERE id=%d", $id
        ) ) ?: null;
    }

    public static function get_by_code( string $code ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_comandas WHERE comanda_code=%s", $code
        ) ) ?: null;
    }

    public static function get_items( int $comanda_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT i.*, p.name AS professional_name, p.commission_pct
             FROM {$wpdb->prefix}barber_comanda_items i
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON i.professional_id = p.id
             WHERE i.comanda_id=%d ORDER BY i.id ASC",
            $comanda_id
        ) ) ?: [];
    }

    public static function get_payments( int $comanda_id ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_comanda_payments WHERE comanda_id=%d ORDER BY id ASC",
            $comanda_id
        ) ) ?: [];
    }

    public static function list( array $args = [] ): array {
        global $wpdb;
        $cid    = (int)($args['company_id'] ?? 0);
        $status = sanitize_key( $args['status'] ?? '' );
        $date   = sanitize_text_field( $args['date'] ?? '' );
        $limit  = (int)($args['limit'] ?? 50);

        $where = $cid ? $wpdb->prepare("AND company_id=%d",$cid) : '';
        if ( $status ) $where .= $wpdb->prepare(" AND status=%s",$status);
        if ( $date   ) $where .= $wpdb->prepare(" AND DATE(created_at)=%s",$date);

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}barber_comandas WHERE 1=1 {$where} ORDER BY id DESC LIMIT {$limit}"
        ) ?: [];
    }

    // ── Helper: gera código único ─────────────────────────────────────────────
    private static function generate_code( int $company_id ): string {
        $prefix = strtoupper( substr( date('Ymd'), 2 ) ); // ex: 260303
        $seq    = (int) \BarberPro_Database::get_setting('comanda_seq_'.$company_id, 0) + 1;
        BarberPro_Database::update_setting('comanda_seq_'.$company_id, (string)$seq);
        return sprintf('C%s-%04d', $prefix, $seq);
    }
}
