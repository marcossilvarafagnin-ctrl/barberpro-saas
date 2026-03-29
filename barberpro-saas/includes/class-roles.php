<?php
/**
 * Roles e capabilities personalizadas
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Roles {

    /**
     * Cria roles customizadas no WordPress.
     */
    public static function create_roles(): void {

        // barber_admin – gestão total da barbearia
        add_role( 'barber_admin', __( 'Barber Admin', 'barberpro-saas' ), [
            'read'                        => true,
            'barberpro_manage_bookings'   => true,
            'barberpro_manage_services'   => true,
            'barberpro_manage_staff'      => true,
            'barberpro_view_finance'      => true,
            'barberpro_manage_finance'    => true,
            'barberpro_manage_settings'   => true,
            'barberpro_manage_coupons'    => true,
            'barberpro_view_reports'      => true,
            'barberpro_manage_store'      => true,
        ] );

        // barber_professional – acesso restrito à própria agenda
        add_role( 'barber_professional', __( 'Barber Professional', 'barberpro-saas' ), [
            'read'                        => true,
            'barberpro_view_own_bookings' => true,
            'barberpro_update_own_status' => true,
            'barberpro_block_own_hours'   => true,
            'barberpro_view_own_commission' => true,
        ] );

        // barber_client – acesso ao painel do cliente
        add_role( 'barber_client', __( 'Barber Client', 'barberpro-saas' ), [
            'read'                        => true,
            'barberpro_book_service'      => true,
            'barberpro_view_own_bookings' => true,
            'barberpro_cancel_own_booking' => true,
            'barberpro_rate_professional' => true,
        ] );

        // Concede capabilities de admin ao administrator do WP
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $caps = [
                'barberpro_manage_bookings', 'barberpro_manage_services',
                'barberpro_manage_staff',    'barberpro_view_finance',
                'barberpro_manage_finance',  'barberpro_manage_settings',
                'barberpro_manage_coupons',  'barberpro_view_reports',
                'barberpro_manage_store',
            ];
            foreach ( $caps as $cap ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Verifica se o usuário atual tem determinada capability.
     */
    public static function can( string $capability ): bool {
        return current_user_can( $capability );
    }

    /**
     * Retorna o company_id do profissional logado (útil para SaaS).
     */
    public static function get_current_professional_company(): int {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return 1;
        global $wpdb;
        $company_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT company_id FROM {$wpdb->prefix}barber_professionals WHERE user_id = %d LIMIT 1",
                $user_id
            )
        );
        return $company_id ? (int) $company_id : 1;
    }
}
