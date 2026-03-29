<?php
/**
 * REST API Endpoints
 * Base: /wp-json/barberpro/v1/
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_API {

    const NAMESPACE = 'barberpro/v1';

    public static function register_routes(): void {
        // Serviços
        register_rest_route( self::NAMESPACE, '/services', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_services' ],  'permission_callback' => '__return_true' ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create_service' ], 'permission_callback' => [ __CLASS__, 'is_admin' ] ],
        ] );
        register_rest_route( self::NAMESPACE, '/services/(?P<id>\d+)', [
            [ 'methods' => 'GET',    'callback' => [ __CLASS__, 'get_service' ],    'permission_callback' => '__return_true' ],
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'update_service' ],  'permission_callback' => [ __CLASS__, 'is_admin' ] ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_service' ],  'permission_callback' => [ __CLASS__, 'is_admin' ] ],
        ] );

        // Profissionais
        register_rest_route( self::NAMESPACE, '/professionals', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_professionals' ],
            'permission_callback' => '__return_true',
        ] );

        // Slots disponíveis
        register_rest_route( self::NAMESPACE, '/slots', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_slots' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'professional_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'date'            => [ 'required' => true, 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ],
                'service_id'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ] );

        // Agendamentos – criar (público)
        register_rest_route( self::NAMESPACE, '/bookings', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_booking' ],
            'permission_callback' => '__return_true',
        ] );

        // Kanban – listar
        register_rest_route( self::NAMESPACE, '/kanban', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_kanban' ],
            'permission_callback' => [ __CLASS__, 'is_staff' ],
        ] );

        // Atualizar status de agendamento
        register_rest_route( self::NAMESPACE, '/bookings/(?P<id>\d+)/status', [
            'methods'             => 'PATCH',
            'callback'            => [ __CLASS__, 'update_booking_status' ],
            'permission_callback' => [ __CLASS__, 'is_staff' ],
        ] );

        // Dashboard financeiro
        register_rest_route( self::NAMESPACE, '/finance/summary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'get_finance_summary' ],
            'permission_callback' => [ __CLASS__, 'can_view_finance' ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public static function get_services( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( BarberPro_Database::get_services(), 200 );
    }

    public static function get_service( WP_REST_Request $req ): WP_REST_Response {
        $service = BarberPro_Database::get_service( (int) $req['id'] );
        return $service
            ? new WP_REST_Response( $service, 200 )
            : new WP_REST_Response( [ 'error' => 'Not found' ], 404 );
    }

    public static function create_service( WP_REST_Request $req ): WP_REST_Response {
        $result = BarberPro_Services::create( $req->get_json_params() );
        return new WP_REST_Response( $result, $result['success'] ? 201 : 400 );
    }

    public static function update_service( WP_REST_Request $req ): WP_REST_Response {
        $result = BarberPro_Services::update( (int) $req['id'], $req->get_json_params() );
        return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    public static function delete_service( WP_REST_Request $req ): WP_REST_Response {
        $result = BarberPro_Services::delete( (int) $req['id'] );
        return new WP_REST_Response( $result, 200 );
    }

    public static function get_professionals( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( BarberPro_Database::get_professionals(), 200 );
    }

    public static function get_slots( WP_REST_Request $req ): WP_REST_Response {
        $pro_id     = (int) $req->get_param( 'professional_id' );
        $date       = $req->get_param( 'date' );
        $service_id = (int) $req->get_param( 'service_id' );

        $duration = 30;
        if ( $service_id ) {
            $service = BarberPro_Database::get_service( $service_id );
            if ( $service ) $duration = (int) $service->duration;
        }

        $slots = BarberPro_Bookings::get_available_slots( $pro_id, $date, $duration );
        return new WP_REST_Response( [ 'slots' => $slots ], 200 );
    }

    public static function create_booking( WP_REST_Request $req ): WP_REST_Response {
        // Verificação de nonce para chamadas do frontend
        $nonce = $req->get_header( 'X-WP-Nonce' ) ?: $req->get_param( '_wpnonce' );
        if ( $nonce && ! wp_verify_nonce( $nonce, 'barberpro_booking' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Nonce inválido.' ], 403 );
        }

        $data   = $req->get_json_params() ?: $req->get_params();
        $result = BarberPro_Bookings::create_booking( $data );
        return new WP_REST_Response( $result, $result['success'] ? 201 : 400 );
    }

    public static function get_kanban( WP_REST_Request $req ): WP_REST_Response {
        $statuses = [ 'agendado', 'confirmado', 'em_atendimento', 'finalizado', 'cancelado' ];
        $kanban   = [];
        foreach ( $statuses as $status ) {
            $kanban[ $status ] = BarberPro_Database::get_bookings( [ 'status' => $status ] );
        }
        return new WP_REST_Response( $kanban, 200 );
    }

    public static function update_booking_status( WP_REST_Request $req ): WP_REST_Response {
        $status = sanitize_key( $req->get_param( 'status' ) );
        $result = BarberPro_Bookings::update_status( (int) $req['id'], $status );
        return new WP_REST_Response( $result, $result['success'] ? 200 : 400 );
    }

    public static function get_finance_summary( WP_REST_Request $req ): WP_REST_Response {
        $summary = BarberPro_Finance::get_dashboard_summary();
        return new WP_REST_Response( $summary, 200 );
    }

    // -------------------------------------------------------------------------
    // Permission callbacks
    // -------------------------------------------------------------------------

    public static function is_admin(): bool {
        return current_user_can( 'barberpro_manage_settings' );
    }

    public static function is_staff(): bool {
        return current_user_can( 'barberpro_manage_bookings' )
            || current_user_can( 'barberpro_view_own_bookings' );
    }

    public static function can_view_finance(): bool {
        return current_user_can( 'barberpro_view_finance' );
    }
}
