<?php
/**
 * BarberPro – Módulo Bar/Eventos
 * Produtos, estoque, comandas por mesa, pagamento split, financeiro integrado.
 * @package BarberProSaaS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Bar {

    const CID = 3; // company_id do módulo bar

    // ══════════════════════════════════════════════════════════════
    // PRODUTOS
    // ══════════════════════════════════════════════════════════════

    public static function get_products( int $company_id = self::CID, string $category = '', bool $all = false ): array {
        global $wpdb;
        $where  = $category ? $wpdb->prepare( "AND category=%s", $category ) : '';
        $active = $all ? '' : "AND status='active'";
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_products
             WHERE company_id=%d {$active} {$where}
             ORDER BY status DESC, category, name ASC",
            $company_id
        ) ) ?: [];
    }

    public static function get_product( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_products WHERE id=%d", $id
        ) ) ?: null;
    }

    public static function get_categories( int $company_id = self::CID ): array {
        global $wpdb;
        return $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT category FROM {$wpdb->prefix}barber_products
             WHERE company_id=%d AND status='active' AND category IS NOT NULL AND category!=''
             ORDER BY category ASC",
            $company_id
        ) ) ?: [];
    }

    public static function save_product( array $data, int $id = 0 ): int|false {
        global $wpdb;
        $clean = [
            'company_id'  => (int)($data['company_id'] ?? self::CID),
            'name'        => sanitize_text_field($data['name'] ?? ''),
            'description' => sanitize_textarea_field($data['description'] ?? ''),
            'category'    => sanitize_text_field($data['category'] ?? ''),
            'sku'         => sanitize_text_field($data['sku'] ?? ''),
            'unit'        => sanitize_text_field($data['unit'] ?? 'un'),
            'cost_price'  => (float)str_replace(',','.',$data['cost_price'] ?? 0),
            'sale_price'  => (float)str_replace(',','.',$data['sale_price'] ?? 0),
            'stock_min'   => (float)str_replace(',','.',$data['stock_min'] ?? 0),
            'stock_max'   => (float)str_replace(',','.',$data['stock_max'] ?? 0),
            'status'      => 'active',
        ];
        if ( ! empty($data['photo']) ) {
            $clean['photo'] = esc_url_raw( $data['photo'] );
        }
        if ( $id ) {
            $clean['updated_at'] = current_time('mysql');
            $wpdb->update("{$wpdb->prefix}barber_products", $clean, ['id'=>$id]);
            return $id;
        }
        $clean['stock_qty'] = 0;
        $clean['created_at'] = current_time('mysql');
        $wpdb->insert("{$wpdb->prefix}barber_products", $clean);
        return $wpdb->insert_id ?: false;
    }

    public static function delete_product( int $id ): bool {
        global $wpdb;
        return (bool)$wpdb->update(
            "{$wpdb->prefix}barber_products",
            ['status'=>'inactive','updated_at'=>current_time('mysql')],
            ['id'=>$id]
        );
    }

    public static function low_stock( int $company_id = self::CID ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_products
             WHERE company_id=%d AND status='active' AND stock_qty <= stock_min AND stock_min > 0
             ORDER BY name ASC",
            $company_id
        ) ) ?: [];
    }

    // ══════════════════════════════════════════════════════════════
    // ESTOQUE – MOVIMENTAÇÕES
    // ══════════════════════════════════════════════════════════════

    /**
     * Registra uma movimentação e atualiza stock_qty.
     * move_type: entrada | saida | ajuste | transferencia
     */
    public static function stock_move( int $product_id, array $data ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        $product = self::get_product($product_id);
        if (!$product) return false;

        $type      = sanitize_key($data['move_type']);
        $qty_raw   = abs((float)str_replace(',','.',$data['qty'] ?? 0));
        $qty_before = (float)$product->stock_qty;

        // Calcula nova quantidade
        switch ($type) {
            case 'entrada':
                $qty_after = $qty_before + $qty_raw;
                $qty_delta = $qty_raw;
                break;
            case 'saida':
                $qty_after = max(0, $qty_before - $qty_raw);
                $qty_delta = -$qty_raw;
                break;
            case 'ajuste':
                $qty_after = (float)str_replace(',','.',$data['qty_final'] ?? $qty_raw);
                $qty_delta = $qty_after - $qty_before;
                break;
            case 'transferencia':
                // Saída deste produto, entrada em outro módulo (registrado separadamente)
                $qty_after = max(0, $qty_before - $qty_raw);
                $qty_delta = -$qty_raw;
                break;
            default:
                return false;
        }

        // Insere log
        $wpdb->insert("{$p}barber_stock_moves", [
            'product_id'     => $product_id,
            'company_id'     => (int)$product->company_id,
            'move_type'      => $type,
            'qty'            => $qty_delta,
            'qty_before'     => $qty_before,
            'qty_after'      => $qty_after,
            'unit_cost'      => !empty($data['unit_cost']) ? (float)str_replace(',','.',$data['unit_cost']) : null,
            'supplier'       => sanitize_text_field($data['supplier'] ?? ''),
            'invoice_number' => sanitize_text_field($data['invoice_number'] ?? ''),
            'reason'         => sanitize_text_field($data['reason'] ?? ''),
            'origin_company' => !empty($data['origin_company']) ? (int)$data['origin_company'] : null,
            'dest_company'   => !empty($data['dest_company'])   ? (int)$data['dest_company']   : null,
            'comanda_id'     => !empty($data['comanda_id'])     ? (int)$data['comanda_id']     : null,
            'created_by'     => get_current_user_id() ?: null,
            'created_at'     => current_time('mysql'),
        ]);

        // Atualiza saldo do produto
        $wpdb->update("{$p}barber_products",
            ['stock_qty'=>$qty_after,'updated_at'=>current_time('mysql')],
            ['id'=>$product_id]
        );

        // Se for transferência, registra entrada no destino
        if ($type === 'transferencia' && !empty($data['dest_product_id'])) {
            self::stock_move((int)$data['dest_product_id'], array_merge($data, [
                'move_type'      => 'entrada',
                'qty'            => $qty_raw,
                'origin_company' => (int)$product->company_id,
                'reason'         => 'Transferência recebida de ' . ($data['origin_name'] ?? 'outro módulo'),
            ]));
        }

        return true;
    }

    public static function get_moves( int $product_id, int $limit = 30 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.*, u.display_name AS user_name
             FROM {$wpdb->prefix}barber_stock_moves m
             LEFT JOIN {$wpdb->prefix}users u ON m.created_by=u.ID
             WHERE m.product_id=%d ORDER BY m.id DESC LIMIT %d",
            $product_id, $limit
        ) ) ?: [];
    }

    // ══════════════════════════════════════════════════════════════
    // COMANDAS DO BAR
    // ══════════════════════════════════════════════════════════════

    public static function open_comanda( array $data ): int|false {
        global $wpdb;
        $cid  = (int)($data['company_id'] ?? self::CID);
        $seq  = (int)BarberPro_Database::get_setting('bar_comanda_seq_'.$cid, 0) + 1;
        BarberPro_Database::update_setting('bar_comanda_seq_'.$cid, (string)$seq);
        $code = 'B' . date('ymd') . sprintf('-%04d', $seq);

        $r = $wpdb->insert("{$wpdb->prefix}barber_bar_comandas", [
            'company_id'   => $cid,
            'comanda_code' => $code,
            'table_number' => sanitize_text_field($data['table_number'] ?? ''),
            'client_name'  => sanitize_text_field($data['client_name'] ?? ''),
            'status'       => 'aberta',
            'notes'        => sanitize_textarea_field($data['notes'] ?? ''),
            'opened_by'    => get_current_user_id() ?: null,
            'created_at'   => current_time('mysql'),
        ]);
        return $r ? $wpdb->insert_id : false;
    }

    public static function get_comanda( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bar_comandas WHERE id=%d", $id
        ) ) ?: null;
    }

    public static function list_comandas( array $args = [] ): array {
        global $wpdb;
        $cid    = (int)($args['company_id'] ?? self::CID);
        $status = sanitize_key($args['status'] ?? '');
        $date   = sanitize_text_field($args['date'] ?? '');
        $limit  = (int)($args['limit'] ?? 50);

        $where = $wpdb->prepare("AND company_id=%d", $cid);
        if ($status) $where .= $wpdb->prepare(" AND status=%s", $status);
        if ($date)   $where .= $wpdb->prepare(" AND DATE(created_at)=%s", $date);

        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}barber_bar_comandas WHERE 1=1 {$where} ORDER BY id DESC LIMIT {$limit}"
        ) ?: [];
    }

    public static function add_item( int $comanda_id, int $product_id, float $qty ): array {
        global $wpdb;
        $comanda = self::get_comanda($comanda_id);
        if (!$comanda || !in_array($comanda->status, ['aberta','aguardando_pagamento'])) {
            return ['success'=>false,'message'=>'Comanda não está aberta.'];
        }
        $product = self::get_product($product_id);
        if (!$product) return ['success'=>false,'message'=>'Produto não encontrado.'];
        if ($product->stock_qty < $qty) {
            return ['success'=>false,'message'=>sprintf(
                'Estoque insuficiente. Disponível: %.3f %s.', $product->stock_qty, $product->unit
            )];
        }

        $total = round($qty * (float)$product->sale_price, 2);
        $wpdb->insert("{$wpdb->prefix}barber_bar_comanda_items", [
            'comanda_id'   => $comanda_id,
            'company_id'   => $comanda->company_id,
            'product_id'   => $product_id,
            'product_name' => $product->name,
            'quantity'     => $qty,
            'unit_price'   => (float)$product->sale_price,
            'total_price'  => $total,
            'created_at'   => current_time('mysql'),
        ]);

        // Baixa automática no estoque
        self::stock_move($product_id, [
            'move_type'  => 'saida',
            'qty'        => $qty,
            'comanda_id' => $comanda_id,
            'reason'     => "Venda – Comanda {$comanda->comanda_code}",
        ]);

        self::recalculate_comanda($comanda_id);
        return ['success'=>true];
    }

    public static function remove_item( int $item_id ): bool {
        global $wpdb;
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bar_comanda_items WHERE id=%d", $item_id
        ));
        if (!$item) return false;

        $wpdb->delete("{$wpdb->prefix}barber_bar_comanda_items", ['id'=>$item_id]);

        // Estorna estoque
        self::stock_move((int)$item->product_id, [
            'move_type' => 'entrada',
            'qty'       => (float)$item->quantity,
            'reason'    => 'Estorno – item removido da comanda',
            'comanda_id'=> (int)$item->comanda_id,
        ]);

        self::recalculate_comanda((int)$item->comanda_id);
        return true;
    }

    public static function get_items( int $comanda_id ): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, p.unit, p.stock_qty
             FROM {$wpdb->prefix}barber_bar_comanda_items i
             LEFT JOIN {$wpdb->prefix}barber_products p ON i.product_id=p.id
             WHERE i.comanda_id=%d ORDER BY i.id ASC",
            $comanda_id
        )) ?: [];
    }

    public static function pay_comanda( int $comanda_id, array $payments, float $discount = 0, string $discount_type = 'fixo' ): array {
        global $wpdb;
        $comanda = self::get_comanda($comanda_id);
        if (!$comanda) return ['success'=>false,'message'=>'Comanda não encontrada.'];
        if (!in_array($comanda->status, ['aberta','fechada','aguardando_pagamento'])) {
            return ['success'=>false,'message'=>'Comanda já processada.'];
        }

        // Aplica desconto e recalcula
        $disc_value = $discount_type === 'percentual'
            ? round((float)$comanda->total_items * $discount / 100, 2)
            : min($discount, (float)$comanda->total_items);
        $total_final = max(0, (float)$comanda->total_items - $disc_value);

        $total_paid = array_sum(array_column($payments,'amount'));
        if (round($total_paid,2) < round($total_final,2)) {
            return ['success'=>false,'message'=>sprintf(
                'Valor pago (R$ %.2f) menor que o total (R$ %.2f).', $total_paid, $total_final
            )];
        }

        // Salva pagamentos
        $method_map = [];
        foreach ($payments as $pmt) {
            $method = sanitize_key($pmt['method']);
            $amount = round((float)$pmt['amount'], 2);
            if ($amount <= 0) continue;
            $wpdb->insert("{$wpdb->prefix}barber_bar_payments", [
                'comanda_id'     => $comanda_id,
                'company_id'     => $comanda->company_id,
                'payment_method' => $method,
                'amount'         => $amount,
                'created_at'     => current_time('mysql'),
            ]);
            $method_map[$method] = ($method_map[$method] ?? 0) + $amount;
        }

        // Fecha comanda
        $wpdb->update("{$wpdb->prefix}barber_bar_comandas", [
            'status'        => 'paga',
            'discount'      => $discount,
            'discount_type' => $discount_type,
            'total_final'   => $total_final,
            'paid_at'       => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ], ['id'=>$comanda_id]);

        // Lança no financeiro consolidado
        $id_label = $comanda->table_number
            ? "Mesa {$comanda->table_number}" . ($comanda->client_name ? " – {$comanda->client_name}" : '')
            : ($comanda->client_name ?: 'Cliente');
        $desc = "Bar #{$comanda->comanda_code} – {$id_label}";

        foreach ($method_map as $method => $amount) {
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

        return ['success'=>true,'comanda_code'=>$comanda->comanda_code,'total'=>$total_final];
    }

    public static function cancel_comanda( int $comanda_id ): bool {
        global $wpdb;
        // Estorna todos os itens ao cancelar
        $items = self::get_items($comanda_id);
        foreach ($items as $item) {
            $comanda = self::get_comanda($comanda_id);
            self::stock_move((int)$item->product_id, [
                'move_type'  => 'entrada',
                'qty'        => (float)$item->quantity,
                'comanda_id' => $comanda_id,
                'reason'     => 'Estorno – comanda cancelada ' . ($comanda->comanda_code ?? ''),
            ]);
        }
        return (bool)$wpdb->update("{$wpdb->prefix}barber_bar_comandas",
            ['status'=>'cancelada','updated_at'=>current_time('mysql')],
            ['id'=>$comanda_id]
        );
    }

    /**
     * Marca comanda como "aguardando pagamento na saída".
     * A comanda continua visível no caixa com badge especial.
     */
    public static function set_aguardando_pagamento( int $comanda_id ): bool {
        global $wpdb;
        $comanda = self::get_comanda($comanda_id);
        if (!$comanda || !in_array($comanda->status, ['aberta','aguardando_pagamento'])) return false;
        return (bool)$wpdb->update(
            "{$wpdb->prefix}barber_bar_comandas",
            ['status' => 'aguardando_pagamento', 'updated_at' => current_time('mysql')],
            ['id'     => $comanda_id]
        );
    }

    /**
     * Reabre uma comanda aguardando_pagamento (volta para aberta para adicionar mais itens).
     */
    public static function reabrir_comanda( int $comanda_id ): bool {
        global $wpdb;
        $comanda = self::get_comanda($comanda_id);
        if (!$comanda || $comanda->status !== 'aguardando_pagamento') return false;
        return (bool)$wpdb->update(
            "{$wpdb->prefix}barber_bar_comandas",
            ['status' => 'aberta', 'updated_at' => current_time('mysql')],
            ['id'     => $comanda_id]
        );
    }

    private static function recalculate_comanda( int $comanda_id ): void {
        global $wpdb;
        $total = (float)$wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(total_price),0) FROM {$wpdb->prefix}barber_bar_comanda_items WHERE comanda_id=%d",
            $comanda_id
        ));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT discount,discount_type FROM {$wpdb->prefix}barber_bar_comandas WHERE id=%d",$comanda_id
        ));
        $disc = $row ? (
            $row->discount_type==='percentual'
            ? round($total*(float)$row->discount/100,2)
            : min((float)$row->discount,$total)
        ) : 0;
        $wpdb->update("{$wpdb->prefix}barber_bar_comandas",[
            'total_items'=>$total,'total_final'=>max(0,$total-$disc),'updated_at'=>current_time('mysql')
        ],['id'=>$comanda_id]);
    }
}
