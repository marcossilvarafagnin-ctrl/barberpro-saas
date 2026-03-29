<?php
/**
 * CRUD de Profissionais / Atendentes
 * @package BarberProSaaS
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Professionals {

    public static function get_all(): array {
        return BarberPro_Database::get_professionals();
    }

    public static function get( int $id ): ?object {
        return BarberPro_Database::get_professional( $id );
    }

    public static function get_professional( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}barber_professionals WHERE id = %d", $id )
        ) ?: null;
    }

    public static function create( array $data ): array {
        if ( empty( $data['name'] ) ) {
            return [ 'success' => false, 'message' => 'Nome e obrigatorio.' ];
        }
        global $wpdb;
        $r = $wpdb->insert( "{$wpdb->prefix}barber_professionals", [
            'company_id'     => (int) ( $data['company_id'] ?? 1 ),
            'name'           => sanitize_text_field( $data['name'] ),
            'specialty'      => sanitize_text_field( $data['specialty'] ?? '' ),
            'phone'          => sanitize_text_field( $data['phone'] ?? '' ),
            'bio'            => sanitize_textarea_field( $data['bio'] ?? '' ),
            'work_days'      => sanitize_text_field( $data['work_days'] ?? '1,2,3,4,5' ),
            'work_start'     => sanitize_text_field( $data['work_start'] ?? '09:00' ),
            'work_end'       => sanitize_text_field( $data['work_end'] ?? '18:00' ),
            'lunch_start'    => sanitize_text_field( $data['lunch_start'] ?? '12:00' ),
            'lunch_end'      => sanitize_text_field( $data['lunch_end'] ?? '13:00' ),
            'slot_interval'  => (int) ( $data['slot_interval'] ?? 30 ),
            'commission_pct' => (float) ( $data['commission_pct'] ?? 40 ),
            'monthly_goal'   => (float) ( $data['monthly_goal'] ?? 0 ),
            'status'         => 'active',
            'created_at'     => current_time( 'mysql' ),
        ] );
        return $r
            ? [ 'success' => true, 'id' => $wpdb->insert_id ]
            : [ 'success' => false, 'message' => 'Erro ao criar profissional.' ];
    }

    public static function update( int $id, array $data ): bool {
        global $wpdb;
        $allowed = [ 'name','specialty','commission_pct','phone','email','status','company_id','work_days','work_start','work_end','slot_interval' ];
        $clean   = [];
        foreach ( $allowed as $k ) {
            if ( array_key_exists( $k, $data ) ) $clean[$k] = $data[$k];
        }
        if ( empty( $clean ) ) return false;
        $clean['updated_at'] = current_time( 'mysql' );
        return (bool) $wpdb->update( "{$wpdb->prefix}barber_professionals", $clean, [ 'id' => $id ] );
    }

    public static function delete( int $id ): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            "{$wpdb->prefix}barber_professionals",
            [ 'status' => 'inactive' ],
            [ 'id'     => $id ]
        );
    }

    public static function get_by_user( int $user_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}barber_professionals WHERE user_id = %d LIMIT 1",
                $user_id
            )
        ) ?: null;
    }
}
