<?php
/**
 * BarberPro – Loja Virtual
 *
 * Gerencia produtos da loja, carrinho, pedidos, frete e pagamentos.
 * Usa a tabela barber_products existente (expandida para company_id 1/2/3)
 * e cria novas tabelas barber_shop_orders e barber_shop_order_items.
 *
 * @package BarberProSaaS
 */

if ( ! defined('ABSPATH') ) exit;

class BarberPro_Shop {

    // =========================================================
    // INSTALAÇÃO — tabelas extras da loja
    // =========================================================

    public static function install_tables(): void {
        global $wpdb;
        // Garante colunas novas mesmo em instâncias existentes
        if ( class_exists('BarberPro_Installer') ) BarberPro_Installer::maybe_migrate();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Pedidos
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}barber_shop_orders (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_code      VARCHAR(20)  NOT NULL UNIQUE,
            company_id      INT UNSIGNED NOT NULL DEFAULT 1,
            client_name     VARCHAR(150) NOT NULL,
            client_email    VARCHAR(150) DEFAULT NULL,
            client_phone    VARCHAR(20)  DEFAULT NULL,
            delivery_type   ENUM('entrega','retirada') NOT NULL DEFAULT 'retirada',
            address_street  VARCHAR(200) DEFAULT NULL,
            address_number  VARCHAR(20)  DEFAULT NULL,
            address_neighborhood VARCHAR(100) DEFAULT NULL,
            address_city    VARCHAR(100) DEFAULT NULL,
            address_state   VARCHAR(2)   DEFAULT NULL,
            address_zip     VARCHAR(10)  DEFAULT NULL,
            subtotal        DECIMAL(10,2) NOT NULL DEFAULT 0,
            shipping_cost   DECIMAL(10,2) NOT NULL DEFAULT 0,
            discount        DECIMAL(10,2) NOT NULL DEFAULT 0,
            total           DECIMAL(10,2) NOT NULL DEFAULT 0,
            payment_method  VARCHAR(30)  DEFAULT 'presencial',
            payment_status  ENUM('pendente','pago','cancelado') DEFAULT 'pendente',
            status          ENUM('novo','confirmado','em_preparo','enviado','entregue','cancelado') DEFAULT 'novo',
            notes           TEXT         DEFAULT NULL,
            mp_preference_id VARCHAR(100) DEFAULT NULL,
            pix_payload     TEXT         DEFAULT NULL,
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            KEY company_id (company_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};");

        // Itens do pedido
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}barber_shop_order_items (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id        INT UNSIGNED NOT NULL,
            product_id      INT UNSIGNED NOT NULL,
            product_name    VARCHAR(150) NOT NULL,
            unit_price      DECIMAL(10,2) NOT NULL,
            quantity        DECIMAL(10,3) NOT NULL DEFAULT 1,
            total_price     DECIMAL(10,2) NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id)
        ) {$charset};");

        // Campos extras em barber_products para a loja
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}barber_products");
        $add  = [
            'photo'       => "ALTER TABLE {$wpdb->prefix}barber_products ADD COLUMN photo VARCHAR(500) DEFAULT NULL AFTER barcode",
            'weight_g'    => "ALTER TABLE {$wpdb->prefix}barber_products ADD COLUMN weight_g INT NOT NULL DEFAULT 0 AFTER photo",
            'shop_active' => "ALTER TABLE {$wpdb->prefix}barber_products ADD COLUMN shop_active TINYINT(1) NOT NULL DEFAULT 0 AFTER weight_g",
        ];
        foreach ( $add as $col => $sql ) {
            if ( ! in_array($col, $cols, true) ) $wpdb->query($sql);
        }
    }

    // =========================================================
    // PRODUTOS DA LOJA
    // =========================================================

    public static function get_products( array $args = [] ): array {
        global $wpdb;
        $cid        = (int)($args['company_id'] ?? 0);
        $cat_filter = sanitize_text_field($args['category'] ?? '');
        $search     = sanitize_text_field($args['search'] ?? '');
        $limit      = (int)($args['limit'] ?? 200);
        $in_stock   = ! empty($args['in_stock']);

        // company filter suporta também string 'barbearia'/'lavacar' para o frontend
        if ( ! $cid && isset($args['company']) ) {
            $cid = $args['company'] === 'lavacar' ? 2 : 1;
        }

        $where  = "WHERE status = 'active' AND shop_active = 1";
        $params = [];

        if ( $cid ) {
            $where   .= ' AND company_id = %d';
            $params[] = $cid;
        }
        if ( $cat_filter ) {
            $where   .= ' AND category = %s';
            $params[] = $cat_filter;
        }
        if ( $search ) {
            $where   .= ' AND (name LIKE %s OR description LIKE %s OR category LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params   = array_merge($params, [$like, $like, $like]);
        }
        if ( $in_stock ) {
            $where .= ' AND stock_qty > 0';
        }

        $sql = "SELECT * FROM {$wpdb->prefix}barber_products {$where} ORDER BY category ASC, name ASC LIMIT {$limit}";
        return $params
            ? ($wpdb->get_results($wpdb->prepare($sql, $params)) ?: [])
            : ($wpdb->get_results($sql) ?: []);
    }

    public static function get_product( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_products WHERE id = %d", $id
        ));
    }

    public static function get_categories( int $cid = 0 ): array {
        global $wpdb;
        $where = "WHERE status='active' AND shop_active=1 AND category IS NOT NULL AND category != ''";
        $params = [];
        if ( $cid ) {
            $where   .= ' AND company_id = %d';
            $params[] = $cid;
        }
        $sql = "SELECT DISTINCT category FROM {$wpdb->prefix}barber_products {$where} ORDER BY category ASC";
        return $params
            ? ($wpdb->get_col($wpdb->prepare($sql, $params)) ?: [])
            : ($wpdb->get_col($sql) ?: []);
    }

    // =========================================================
    // PEDIDOS
    // =========================================================

    public static function create_order( array $data ): array {
        global $wpdb;

        $items = $data['items'] ?? [];
        if ( empty($items) ) return ['success'=>false,'message'=>'Carrinho vazio.'];

        // Valida estoque e calcula subtotal
        $subtotal = 0;
        $validated_items = [];
        foreach ( $items as $item ) {
            $product = self::get_product((int)$item['product_id']);
            // shop_active pode ser NULL se a coluna ainda não foi migrada – tratar NULL como ativo
            $shop_active = isset($product->shop_active) ? (int)$product->shop_active : 1;
            if ( ! $product || $product->status !== 'active' || ! $shop_active ) {
                return ['success'=>false,'message'=>'Produto ' . $item['product_name'] . ' nao disponivel.'];
            }
            $qty = (float)($item['qty'] ?? 1);
            if ( $product->stock_qty < $qty ) {
                return ['success'=>false,'message'=>"Estoque insuficiente: {$product->name} (disponível: {$product->stock_qty})."];
            }
            $price = (float)$product->sale_price;
            $subtotal += round($price * $qty, 2);
            $validated_items[] = [
                'product_id'   => $product->id,
                'product_name' => $product->name,
                'unit_price'   => $price,
                'quantity'     => $qty,
                'total_price'  => round($price * $qty, 2),
            ];
        }

        $shipping   = (float)($data['shipping_cost'] ?? 0);
        $total      = round($subtotal + $shipping, 2);
        $order_code = self::generate_code();

        // Insere pedido
        $r = $wpdb->insert("{$wpdb->prefix}barber_shop_orders", [
            'order_code'           => $order_code,
            'company_id'           => (int)($data['company_id'] ?? 1),
            'client_name'          => sanitize_text_field($data['client_name']),
            'client_email'         => sanitize_email($data['client_email'] ?? ''),
            'client_phone'         => sanitize_text_field($data['client_phone'] ?? ''),
            'delivery_type'        => $data['delivery_type'] === 'entrega' ? 'entrega' : 'retirada',
            'address_street'       => sanitize_text_field($data['address_street']        ?? ''),
            'address_number'       => sanitize_text_field($data['address_number']        ?? ''),
            'address_neighborhood' => sanitize_text_field($data['address_neighborhood']  ?? ''),
            'address_city'         => sanitize_text_field($data['address_city']          ?? ''),
            'address_state'        => strtoupper(sanitize_text_field($data['address_state'] ?? '')),
            'address_zip'          => preg_replace('/\D/','',$data['address_zip'] ?? ''),
            'subtotal'             => $subtotal,
            'shipping_cost'        => $shipping,
            'discount'             => 0,
            'total'                => $total,
            'payment_method'       => sanitize_key($data['payment_method'] ?? 'presencial'),
            'notes'                => sanitize_textarea_field($data['notes'] ?? ''),
            'status'               => 'novo',
            'payment_status'       => 'pendente',
            'created_at'           => current_time('mysql'),
        ]);

        if ( ! $r ) return ['success'=>false,'message'=>'Erro ao criar pedido: '.$wpdb->last_error];
        $order_id = $wpdb->insert_id;

        // Insere itens e baixa estoque
        foreach ( $validated_items as $item ) {
            $wpdb->insert("{$wpdb->prefix}barber_shop_order_items", array_merge($item, ['order_id'=>$order_id]));
            // Baixa estoque
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}barber_products SET stock_qty = stock_qty - %f, updated_at = %s WHERE id = %d",
                $item['quantity'], current_time('mysql'), $item['product_id']
            ));
        }

        // Lança receita no financeiro
        self::register_finance($order_id, $order_code, $total, $data);

        return [
            'success'    => true,
            'order_id'   => $order_id,
            'order_code' => $order_code,
            'total'      => $total,
        ];
    }

    private static function register_finance( int $order_id, string $code, float $total, array $data ): void {
        global $wpdb;
        $cid = (int)($data['company_id'] ?? 1);
        $cat_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}barber_finance_categories WHERE company_id=%d AND code='REC-002' LIMIT 1",
            $cid
        ));
        BarberPro_Finance::insert([
            'company_id'       => $cid,
            'type'             => 'receita',
            'category_id'      => $cat_id ?: null,
            'description'      => "Pedido #{$code}",
            'amount'           => $total,
            'payment_method'   => $data['payment_method'] ?? 'presencial',
            'status'           => 'pendente',
            'competencia_date' => current_time('Y-m-d'),
            'due_date'         => current_time('Y-m-d'),
            'supplier'         => $data['client_name'],
        ]);
    }

    public static function get_order( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_shop_orders WHERE id = %d", $id
        ));
    }

    public static function get_order_by_code( string $code ): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_shop_orders WHERE order_code = %s", $code
        ));
    }

    public static function get_order_items( int $order_id ): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_shop_order_items WHERE order_id = %d ORDER BY id ASC",
            $order_id
        )) ?: [];
    }

    public static function list_orders( array $args = [] ): array {
        global $wpdb;
        $cid    = (int)($args['company_id'] ?? 0);
        $status = sanitize_key($args['status'] ?? '');
        $limit  = (int)($args['limit'] ?? 50);
        $email  = sanitize_email($args['client_email'] ?? '');

        $where  = 'WHERE 1=1';
        $params = [];
        if ($cid)    { $where .= ' AND company_id = %d'; $params[] = $cid; }
        if ($status) { $where .= ' AND status = %s';     $params[] = $status; }
        if ($email)  { $where .= ' AND client_email = %s'; $params[] = $email; }

        $sql = "SELECT * FROM {$wpdb->prefix}barber_shop_orders {$where} ORDER BY created_at DESC LIMIT {$limit}";
        return $params
            ? ($wpdb->get_results($wpdb->prepare($sql, $params)) ?: [])
            : ($wpdb->get_results($sql) ?: []);
    }

    public static function update_order_status( int $id, string $status ): bool {
        global $wpdb;
        $allowed = ['novo','confirmado','em_preparo','enviado','entregue','cancelado'];
        if ( ! in_array($status, $allowed, true) ) return false;
        return (bool)$wpdb->update(
            "{$wpdb->prefix}barber_shop_orders",
            ['status'=>$status,'updated_at'=>current_time('mysql')],
            ['id'=>$id]
        );
    }

    // =========================================================
    // FRETE
    // =========================================================

    /**
     * Calcula frete baseado na configuração das settings.
     * Tipos: 'fixo' (taxa fixa), 'por_km' (por CEP/distância estimada),
     *        'correios' (API Correios), 'gratis' (acima de X valor)
     */
    public static function calc_shipping( string $zip, float $subtotal, int $company_id = 1 ): array {
        $tipo       = BarberPro_Database::get_setting('shop_frete_tipo','fixo');
        $gratis_min = (float)BarberPro_Database::get_setting('shop_frete_gratis_minimo','0');

        // Frete grátis acima de X
        if ( $gratis_min > 0 && $subtotal >= $gratis_min ) {
            return ['success'=>true,'cost'=>0,'label'=>'Frete Grátis 🎉','tipo'=>'gratis'];
        }

        switch ($tipo) {
            case 'fixo':
                $cost = (float)BarberPro_Database::get_setting('shop_frete_fixo','10');
                return ['success'=>true,'cost'=>$cost,'label'=>'Entrega — R$ '.number_format($cost,2,',','.'),'tipo'=>'fixo'];

            case 'por_faixa':
                // Faixas de CEP configuradas em JSON
                $faixas = json_decode(BarberPro_Database::get_setting('shop_frete_faixas','[]'),true) ?: [];
                $zip_clean = preg_replace('/\D/','',$zip);
                $zip_int   = (int)substr($zip_clean,0,5);
                foreach ($faixas as $f) {
                    if ($zip_int >= (int)$f['cep_ini'] && $zip_int <= (int)$f['cep_fim']) {
                        $cost = (float)$f['valor'];
                        return ['success'=>true,'cost'=>$cost,'label'=>'Entrega — R$ '.number_format($cost,2,',','.'),'tipo'=>'faixa'];
                    }
                }
                // CEP fora das faixas
                $cost_default = (float)BarberPro_Database::get_setting('shop_frete_fora_faixa','0');
                if ($cost_default <= 0) return ['success'=>false,'cost'=>0,'label'=>'CEP fora da área de entrega','tipo'=>'fora'];
                return ['success'=>true,'cost'=>$cost_default,'label'=>'Entrega — R$ '.number_format($cost_default,2,',','.'),'tipo'=>'default'];

            default:
                $cost = (float)BarberPro_Database::get_setting('shop_frete_fixo','10');
                return ['success'=>true,'cost'=>$cost,'label'=>'Entrega — R$ '.number_format($cost,2,',','.'),'tipo'=>'fixo'];
        }
    }

    // =========================================================
    // NOTIFICAÇÕES
    // =========================================================

    public static function notify_new_order( object $order, array $items ): void {
        // Notifica dono por e-mail
        self::notify_owner_email($order, $items);
        // Notifica dono por WhatsApp
        self::notify_owner_whatsapp($order);
        // Notifica cliente por e-mail
        self::notify_client_email($order, $items);
    }

    private static function notify_owner_email( object $o, array $items ): void {
        $email = BarberPro_Database::get_setting('shop_notify_email',
                 BarberPro_Database::get_setting('email_remetente', get_bloginfo('admin_email')));
        if ( ! $email || ! is_email($email) ) return;

        $lista = implode("\n", array_map(function($i) { return "  • {$i->product_name} x{$i->quantity} = R$ ".number_format($i->total_price,2,',','.'); }, $items));
        $frete = $o->shipping_cost > 0 ? "\nFrete: R$ ".number_format($o->shipping_cost,2,',','.') : '';
        $entrega = $o->delivery_type === 'entrega'
            ? "Entrega em: {$o->address_street}, {$o->address_number} — {$o->address_city}/{$o->address_state} — CEP {$o->address_zip}"
            : "Retirada na loja";

        $body = "🛍️ Novo pedido recebido!\n\n"
              . "Pedido: #{$o->order_code}\n"
              . "Cliente: {$o->client_name}\n"
              . "Telefone: {$o->client_phone}\n"
              . "E-mail: {$o->client_email}\n\n"
              . "Itens:\n{$lista}{$frete}\n"
              . "Total: R$ ".number_format($o->total,2,',','.')."\n\n"
              . $entrega;

        $nome = BarberPro_Database::get_setting('email_nome_remetente', get_bloginfo('name'));
        $from = BarberPro_Database::get_setting('email_remetente', get_bloginfo('admin_email'));
        wp_mail($email, "🛍️ Novo Pedido #{$o->order_code}", $body, [
            "Content-Type: text/plain; charset=UTF-8",
            "From: {$nome} <{$from}>",
        ]);
    }

    private static function notify_owner_whatsapp( object $o ): void {
        $tel = BarberPro_Database::get_setting('shop_notify_whatsapp',
               BarberPro_Database::get_setting('whatsapp_number',''));
        if ( ! $tel ) return;
        $entrega = $o->delivery_type === 'entrega' ? "🚚 Entrega — {$o->address_city}/{$o->address_state}" : "🏪 Retirada na loja";
        $msg = "🛍️ *Novo Pedido #{$o->order_code}!*\n\n"
             . "👤 {$o->client_name}\n"
             . "📱 {$o->client_phone}\n"
             . "💰 R$ ".number_format($o->total,2,',','.')."\n"
             . $entrega;
        BarberPro_WhatsApp::send($tel, $msg);
    }

    private static function notify_client_email( object $o, array $items ): void {
        if ( ! $o->client_email || ! is_email($o->client_email) ) return;
        $lista = implode("\n", array_map(function($i) { return "  • {$i->product_name} x{$i->quantity}"; }, $items));
        $entrega = $o->delivery_type === 'entrega'
            ? "📦 Entrega em: {$o->address_street}, {$o->address_number} — {$o->address_city}/{$o->address_state}"
            : "🏪 Retirada na loja";
        $body = "Olá, {$o->client_name}!\n\n"
              . "Seu pedido #{$o->order_code} foi recebido com sucesso! 🎉\n\n"
              . "Itens:\n{$lista}\n"
              . "Total: R$ ".number_format($o->total,2,',','.')."\n\n"
              . $entrega."\n\n"
              . "Aguarde a confirmação. Qualquer dúvida entre em contato.";
        $nome = BarberPro_Database::get_setting('email_nome_remetente', get_bloginfo('name'));
        $from = BarberPro_Database::get_setting('email_remetente', get_bloginfo('admin_email'));
        wp_mail($o->client_email, "✅ Pedido #{$o->order_code} confirmado!", $body, [
            "Content-Type: text/plain; charset=UTF-8",
            "From: {$nome} <{$from}>",
        ]);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private static function generate_code(): string {
        global $wpdb;
        do {
            $code = 'PD-' . strtoupper(substr(md5(uniqid()), 0, 6));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}barber_shop_orders WHERE order_code = %s", $code
            ));
        } while ($exists);
        return $code;
    }

    public static function money( float $v ): string {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    public static function status_label( string $s ): string {
        return match($s) {
            'novo'       => '🆕 Novo',
            'confirmado' => '✅ Confirmado',
            'em_preparo' => '⚙️ Em preparo',
            'enviado'    => '🚚 Enviado',
            'entregue'   => '📦 Entregue',
            'cancelado'  => '❌ Cancelado',
            default      => $s,
        };
    }
}
