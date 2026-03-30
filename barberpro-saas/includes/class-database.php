<?php
/**
 * Classe de abstração de banco de dados
 * Todos os métodos usam prepared statements.
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Database {

    /** Retorna o company_id atual (filtrável para SaaS multiempresa) */
    public static function get_company_id(): int {
        return (int) apply_filters( 'barberpro_company_id', 1 );
    }

    // ─── SERVICES ──────────────────────────────────────────────────────────

    public static function get_services( int $company_id = 0, bool $all = false ): array {
        global $wpdb;
        $cid   = $company_id ?: self::get_company_id();
        $where = $all ? '' : "AND status = 'active'";
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *, duration AS duration_minutes FROM {$wpdb->prefix}barber_services WHERE company_id = %d {$where} ORDER BY status DESC, name ASC",
                $cid
            )
        ) ?: [];
    }

    public static function get_service( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT *, duration AS duration_minutes FROM {$wpdb->prefix}barber_services WHERE id = %d", $id )
        );
    }

    public static function insert_service( array $data ): int|false {
        global $wpdb;
        $clean = [
            'company_id'  => (int) ( $data['company_id'] ?? self::get_company_id() ),
            'name'        => sanitize_text_field( $data['name'] ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'price'       => round( (float) $data['price'], 2 ),
            'duration'    => (int) $data['duration'],
            'photo'       => sanitize_url( $data['photo'] ?? '' ),
            'category'    => sanitize_text_field( $data['category'] ?? '' ),
            'status'      => 'active',
            'created_at'  => current_time( 'mysql' ),
        ];
        $r = $wpdb->insert( "{$wpdb->prefix}barber_services", $clean );
        return $r ? $wpdb->insert_id : false;
    }

    public static function update_service( int $id, array $data ): bool {
        global $wpdb;
        $clean = [];
        $map   = [ 'name', 'description', 'price', 'duration', 'photo', 'category', 'status' ];
        foreach ( $map as $k ) {
            if ( isset( $data[ $k ] ) ) $clean[ $k ] = $data[ $k ];
        }
        $clean['updated_at'] = current_time( 'mysql' );
        $r = $wpdb->update( "{$wpdb->prefix}barber_services", $clean, [ 'id' => $id ] );
        // wpdb->update() returns false on error, 0 on "no rows changed" (both data same) - treat 0 as success
        return $r !== false;
    }

    public static function delete_service( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}barber_services",
            [ 'status' => 'inactive', 'updated_at' => current_time('mysql') ],
            [ 'id' => $id ]
        );
    }

    // ─── PROFESSIONALS ─────────────────────────────────────────────────────

    public static function get_professionals( int $company_id = 0, bool $all = false ): array {
        global $wpdb;
        $cid = $company_id ?: self::get_company_id();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}barber_professionals WHERE company_id = %d " . ($all ? '' : "AND status = 'active' ") . "ORDER BY status DESC, name ASC",
                $cid
            )
        ) ?: [];
    }

    public static function get_professional( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}barber_professionals WHERE id = %d", $id )
        );
    }

    public static function insert_professional( array $data ): int|false {
        global $wpdb;
        $data['company_id'] = $data['company_id'] ?? self::get_company_id();
        $data['created_at'] = current_time( 'mysql' );
        $r = $wpdb->insert( "{$wpdb->prefix}barber_professionals", $data );
        return $r ? $wpdb->insert_id : false;
    }


    // ─── SERVICE VARIANTS ──────────────────────────────────────────────────

    public static function get_service_variants( int $service_id ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}barber_service_variants WHERE service_id = %d AND status = 'active' ORDER BY sort_order ASC",
                $service_id
            )
        ) ?: [];
    }

    public static function insert_service_variant( array $data ): int|false {
        global $wpdb;
        $r = $wpdb->insert( "{$wpdb->prefix}barber_service_variants", [
            'service_id' => (int) $data['service_id'],
            'company_id' => (int) ( $data['company_id'] ?? self::get_company_id() ),
            'name'       => sanitize_text_field( $data['name'] ),
            'size_key'   => sanitize_key( $data['size_key'] ?? '' ),
            'price'      => round( (float) $data['price'], 2 ),
            'duration'   => ! empty( $data['duration'] ) ? (int) $data['duration'] : null,
            'sort_order' => (int) ( $data['sort_order'] ?? 0 ),
            'status'     => 'active',
            'created_at' => current_time( 'mysql' ),
        ] );
        return $r ? $wpdb->insert_id : false;
    }

    public static function delete_service_variant( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}barber_service_variants",
            [ 'status' => 'inactive' ],
            [ 'id' => $id ]
        );
    }

    // ─── BOOKINGS ──────────────────────────────────────────────────────────

    public static function get_bookings( array $args = [] ): array {
        global $wpdb;

        // Se vier client_email, busca em todas as empresas
        if ( ! empty( $args['client_email'] ) ) {
            $sql    = "SELECT b.*, s.name AS service_name, p.name AS professional_name
                       FROM {$wpdb->prefix}barber_bookings b
                       LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id = s.id
                       LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id = p.id
                       WHERE b.client_email = %s";
            $params = [ sanitize_email( $args['client_email'] ) ];
            $sql   .= ' ORDER BY b.booking_date DESC, b.booking_time DESC';
            return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: []; // phpcs:ignore
        }

        $cid    = (int) ( $args['company_id'] ?? self::get_company_id() );
        $sql    = "SELECT b.*, s.name AS service_name, p.name AS professional_name
                   FROM {$wpdb->prefix}barber_bookings b
                   LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id = s.id
                   LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id = p.id
                   WHERE b.company_id = %d";
        $params = [ $cid ];

        if ( ! empty( $args['status'] ) ) {
            $sql    .= ' AND b.status = %s';
            $params[] = $args['status'];
        }
        if ( ! empty( $args['pro_id'] ) ) {
            $sql    .= ' AND b.professional_id = %d';
            $params[] = (int) $args['pro_id'];
        }
        if ( ! empty( $args['date'] ) ) {
            $sql    .= ' AND b.booking_date = %s';
            $params[] = $args['date'];
        }
        $sql .= ' ORDER BY b.booking_date ASC, b.booking_time ASC';
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: []; // phpcs:ignore
    }

    public static function get_booking( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT b.*, s.name AS service_name, s.price AS service_price,
                        p.name AS professional_name
                 FROM {$wpdb->prefix}barber_bookings b
                 LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id = s.id
                 LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id = p.id
                 WHERE b.id = %d",
                $id
            )
        );
    }

    public static function insert_booking( array $data ): int|false {
        global $wpdb;

        // Gera código único
        do {
            $code = strtoupper( substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 8 ) );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}barber_bookings WHERE booking_code = %s", $code
            ) );
        } while ( $exists );

        $service = self::get_service( (int) $data['service_id'] );
        // Se tem variante com preço próprio, usa ela; senão usa preço base do serviço
        $price   = ! empty( $data['amount_variant'] )
            ? round( (float) $data['amount_variant'], 2 )
            : ( $service ? (float) $service->price : 0 );
        // Adiciona taxa de entrega ao total
        $delivery_fee = round( (float) ( $data['delivery_fee'] ?? 0 ), 2 );
        $price += $delivery_fee;

        $clean = [
            'company_id'      => (int) ( $data['company_id'] ?? self::get_company_id() ),
            'service_id'      => (int) $data['service_id'],
            'professional_id' => (int) $data['professional_id'],
            'client_user_id'  => (int) ( $data['client_user_id'] ?? 0 ) ?: null,
            'client_name'     => sanitize_text_field( $data['client_name'] ),
            'client_phone'    => sanitize_text_field( $data['client_phone'] ),
            'client_email'    => sanitize_email( $data['client_email'] ?? '' ),
            'booking_date'    => sanitize_text_field( $data['booking_date'] ),
            'booking_time'    => sanitize_text_field( $data['booking_time'] ),
            'status'          => sanitize_key( $data['status'] ?? 'agendado' ),
            'payment_method'  => sanitize_key( $data['payment_method'] ?? 'presencial' ),
            'payment_status'  => 'pendente',
            'amount_total'    => $price,
            'amount_paid'     => 0,
            'discount'        => 0,
            'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
            'vehicle_plate'   => ! empty( $data['vehicle_plate'] ) ? strtoupper( sanitize_text_field( $data['vehicle_plate'] ) ) : null,
            'vehicle_model'   => sanitize_text_field( $data['vehicle_model'] ?? '' ) ?: null,
            'vehicle_color'   => sanitize_text_field( $data['vehicle_color'] ?? '' ) ?: null,
            'vehicle_size'    => sanitize_key( $data['vehicle_size'] ?? '' ) ?: null,
            'service_variant' => sanitize_text_field( $data['service_variant'] ?? '' ) ?: null,
            'amount_variant'  => ! empty( $data['amount_variant'] ) ? round( (float) $data['amount_variant'], 2 ) : null,
            'delivery_type'   => ! empty( $data['delivery_type'] ) ? sanitize_key( $data['delivery_type'] ) : null,
            'delivery_address'=> ! empty( $data['delivery_address'] ) ? sanitize_textarea_field( $data['delivery_address'] ) : null,
            'delivery_fee'    => round( (float) ( $data['delivery_fee'] ?? 0 ), 2 ),
            'delivery_notes'  => ! empty( $data['delivery_notes'] ) ? sanitize_text_field( $data['delivery_notes'] ) : null,
            'booking_code'    => $code,
            'loyalty_points'  => (int) BarberPro_Database::get_setting( 'loyalty_points_per_booking', 10 ),
            'created_at'      => current_time( 'mysql' ),
        ];

        $r = $wpdb->insert( "{$wpdb->prefix}barber_bookings", $clean );
        return $r ? $wpdb->insert_id : false;
    }

    public static function update_booking_status( int $id, string $status ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}barber_bookings",
            [ 'status' => sanitize_key( $status ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );
    }

    /**
     * Atualiza data/horário do agendamento (ex.: remarcação pelo cliente).
     */
    public static function update_booking_schedule( int $id, string $date, string $time ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}barber_bookings",
            [
                'booking_date' => sanitize_text_field( $date ),
                'booking_time' => sanitize_text_field( $time ),
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );
    }

    /**
     * Lista agendamentos do cliente por e-mail ou telefone (painel do cliente).
     */
    public static function get_bookings_for_client( string $email, string $phone = '' ): array {
        global $wpdb;
        $email = sanitize_email( $email );
        $phone = preg_replace( '/\D/', '', $phone );
        if ( ! $email && ! $phone ) {
            return [];
        }
        $sql    = "SELECT b.*, s.name AS service_name, p.name AS professional_name
                   FROM {$wpdb->prefix}barber_bookings b
                   LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id = s.id
                   LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id = p.id
                   WHERE b.status NOT IN ('recusado')";
        $params = [];
        if ( $email && $phone ) {
            $sql .= ' AND (b.client_email = %s OR REPLACE(REPLACE(REPLACE(REPLACE(b.client_phone," ",""),"-",""),"(",""),")","") LIKE %s)';
            $params[] = $email;
            $params[] = '%' . $wpdb->esc_like( $phone ) . '%';
        } elseif ( $email ) {
            $sql     .= ' AND b.client_email = %s';
            $params[] = $email;
        } else {
            $sql     .= ' AND REPLACE(REPLACE(REPLACE(REPLACE(b.client_phone," ",""),"-",""),"(",""),")","") LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $phone ) . '%';
        }
        $sql .= ' ORDER BY b.booking_date DESC, b.booking_time DESC';
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) ?: [];
    }

    public static function get_booked_slots( int $professional_id, string $date ): array {
        global $wpdb;
        return $wpdb->get_col(
            $wpdb->prepare(
                "SELECT booking_time FROM {$wpdb->prefix}barber_bookings
                 WHERE professional_id = %d AND booking_date = %s
                   AND status NOT IN ('cancelado','recusado')",
                $professional_id, $date
            )
        ) ?: [];
    }

    // ─── FINANCE (helpers básicos) ──────────────────────────────────────────

    public static function insert_transaction( array $data ): int|false {
        return BarberPro_Finance::insert( $data );
    }

    // ─── SETTINGS ──────────────────────────────────────────────────────────

    public static function get_setting( string $key, $default = '' ) {
        global $wpdb;
        $cid = self::get_company_id();
        $val = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT setting_value FROM {$wpdb->prefix}barber_settings WHERE company_id = %d AND setting_key = %s",
                $cid, $key
            )
        );
        return $val ?? $default;
    }

    public static function update_setting( string $key, $value ): bool {
        global $wpdb;
        $cid    = self::get_company_id();
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}barber_settings WHERE company_id = %d AND setting_key = %s",
                $cid, $key
            )
        );
        $serialized = maybe_serialize( $value );
        if ( $exists ) {
            return (bool) $wpdb->update(
                "{$wpdb->prefix}barber_settings",
                [ 'setting_value' => $serialized, 'updated_at' => current_time('mysql') ],
                [ 'company_id' => $cid, 'setting_key' => $key ]
            );
        }
        return (bool) $wpdb->insert( "{$wpdb->prefix}barber_settings", [
            'company_id'    => $cid,
            'setting_key'   => $key,
            'setting_value' => $serialized,
            'created_at'    => current_time('mysql'),
        ] );
    }
}
