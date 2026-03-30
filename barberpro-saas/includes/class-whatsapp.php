<?php
/**
 * Automação de mensagens WhatsApp
 * Suporta: WhatsApp Cloud API, Twilio, Z-API
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_WhatsApp {

    /**
     * Envia mensagem de confirmação de agendamento.
     */
    public static function send_confirmation( object $booking ): void {
        $template = BarberPro_Database::get_setting( 'msg_confirmation' );
        $message  = self::parse_template( $template, $booking );
        self::send( $booking->client_phone, $message );
    }

    /**
     * Envia lembrete (deve ser chamado via wp-cron ou agendamento).
     */
    public static function send_reminder( object $booking ): void {
        $template = BarberPro_Database::get_setting( 'msg_reminder' );
        $message  = self::parse_template( $template, $booking );
        self::send( $booking->client_phone, $message );
    }

    /**
     * Envia mensagem de cancelamento.
     */
    public static function send_cancellation( object $booking ): void {
        $template = BarberPro_Database::get_setting( 'msg_cancellation',
            'Olá {nome}! Seu agendamento do dia {data} às {hora} foi cancelado.' );
        $message = self::parse_template( $template, $booking );
        self::send( $booking->client_phone, $message );
    }

    /**
     * Envia pedido de avaliação após atendimento.
     */
    public static function send_review_request( object $booking ): void {
        $template = BarberPro_Database::get_setting( 'msg_review' );
        $message  = self::parse_template( $template, $booking );
        self::send( $booking->client_phone, $message );
    }

    /**
     * Envia mensagem via provider configurado.
     *
     * @param string $phone   Número no formato 5511999999999
     * @param string $message Mensagem já parseada
     */
    public static function send( string $phone, string $message ): bool {
        $message  = trim( (string) $message );
        $phone    = self::normalize_phone( $phone );
        if ( $message === '' || strlen( $phone ) < 10 ) {
            return false;
        }

        $provider = BarberPro_Database::get_setting( 'whatsapp_provider', 'cloud_api' );

        switch ( $provider ) {
            case 'wapi':
                return self::send_wapi( $phone, $message );
            case 'cloud_api':
                return self::send_cloud_api( $phone, $message );
            case 'twilio':
                return self::send_twilio( $phone, $message );
            case 'zapi':
                return self::send_zapi( $phone, $message );
            default:
                do_action( 'barberpro_whatsapp_custom_send', $phone, $message, $provider );
                return false;
        }
    }

    // -------------------------------------------------------------------------
    // Providers
    // -------------------------------------------------------------------------

    private static function send_wapi( string $phone, string $message ): bool {
        $instance = BarberPro_Database::get_setting( 'wapi_instance' );
        $token    = BarberPro_Database::get_setting( 'wapi_token' );
        if ( ! $instance || ! $token ) return false;

        // Garante formato com DDI
        $number = $phone;
        if ( strlen($number) === 10 || strlen($number) === 11 ) {
            $number = '55' . $number;
        }

        $url  = "https://api.w-api.app/v1/message/send-text?instanceId={$instance}";
        $body = wp_json_encode([
            'phone'        => $number,
            'message'      => $message,
            'delayMessage' => 3,
        ]);

        $response = wp_remote_post( $url, [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => $body,
            'timeout' => 15,
        ]);

        if ( is_wp_error($response) ) return false;
        $code = wp_remote_retrieve_response_code($response);
        return $code === 200 || $code === 201;
    }

    private static function send_cloud_api( string $phone, string $message ): bool {
        $token       = BarberPro_Database::get_setting( 'whatsapp_cloud_token' );
        $phone_id    = BarberPro_Database::get_setting( 'whatsapp_phone_id' );
        if ( ! $token || ! $phone_id ) return false;

        $url  = "https://graph.facebook.com/v19.0/{$phone_id}/messages";
        $body = wp_json_encode( [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'text',
            'text'              => [ 'body' => $message ],
        ] );

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 15,
        ] );

        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }

    private static function send_twilio( string $phone, string $message ): bool {
        $sid    = BarberPro_Database::get_setting( 'twilio_account_sid' );
        $token  = BarberPro_Database::get_setting( 'twilio_auth_token' );
        $from   = BarberPro_Database::get_setting( 'twilio_from' );
        if ( ! $sid || ! $token || ! $from ) return false;

        $url      = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "{$sid}:{$token}" ),
            ],
            'body'    => [
                'From' => 'whatsapp:' . $from,
                'To'   => 'whatsapp:+' . $phone,
                'Body' => $message,
            ],
            'timeout' => 15,
        ] );

        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 201;
    }

    private static function send_zapi( string $phone, string $message ): bool {
        $instance = BarberPro_Database::get_setting( 'zapi_instance' );
        $token    = BarberPro_Database::get_setting( 'zapi_token' );
        if ( ! $instance || ! $token ) return false;

        $url      = "https://api.z-api.io/instances/{$instance}/token/{$token}/send-text";
        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'phone' => $phone, 'message' => $message ] ),
            'timeout' => 15,
        ] );

        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Substitui variáveis no template da mensagem.
     *
     * @param array $extra Variáveis extras: ['{tag}' => 'valor']
     */
    private static function parse_template( string $template, object $booking, array $extra = [] ): string {
        $professional = BarberPro_Database::get_professional( (int) $booking->professional_id );
        $service      = BarberPro_Database::get_service( (int) $booking->service_id );

        $search  = [ '{nome}', '{data}', '{hora}', '{profissional}', '{servico}', '{codigo}', '{link}' ];
        $replace = [
            esc_html( $booking->client_name ),
            esc_html( date_i18n( 'd/m/Y', strtotime( $booking->booking_date ) ) ),
            esc_html( substr( $booking->booking_time, 0, 5 ) ),
            esc_html( $professional ? $professional->name : '' ),
            esc_html( $service      ? $service->name      : '' ),
            esc_html( $booking->booking_code ),
            home_url( '?barberpro_review=' . $booking->booking_code ),
        ];

        // Variáveis extras ({dias_media}, {link_agendamento}, etc.)
        foreach ( $extra as $tag => $val ) {
            $search[]  = $tag;
            $replace[] = $val;
        }

        return str_replace( $search, $replace, $template );
    }

    /**
     * Normaliza número de telefone para apenas dígitos.
     */
    private static function normalize_phone( string $phone ): string {
        return preg_replace( '/\D/', '', $phone );
    }

    // -------------------------------------------------------------------------
    // WP-Cron: lembretes automáticos
    // -------------------------------------------------------------------------

    /**
     * Envia mensagem de retorno/reagendamento para clientes que não voltaram.
     * Calcula automaticamente o intervalo médio de cada cliente.
     */
    public static function send_return_message( object $booking ): void {
        $template = BarberPro_Database::get_setting( 'msg_return' );
        if ( empty($template) ) return;

        $avg_days   = self::get_client_avg_return( $booking->client_phone, (int) $booking->company_id );
        $agenda_url = BarberPro_Database::get_setting( 'booking_page_url', home_url('/agendamento/') );

        $message = self::parse_template( $template, $booking, [
            '{dias_media}'   => $avg_days > 0 ? "{$avg_days} dias" : 'alguns dias',
            '{link_agendamento}' => $agenda_url,
        ] );
        self::send( $booking->client_phone, $message );
    }

    /**
     * Calcula a média de dias entre visitas de um cliente.
     * Retorna 0 se não houver histórico suficiente.
     */
    public static function get_client_avg_return( string $phone, int $company_id = 0 ): int {
        global $wpdb;
        $phone = self::normalize_phone( $phone );
        if ( empty($phone) ) return 0;

        $where = $company_id ? $wpdb->prepare( 'AND b.company_id = %d', $company_id ) : '';
        $dates = $wpdb->get_col( $wpdb->prepare(
            "SELECT b.booking_date FROM {$wpdb->prefix}barber_bookings b
             WHERE REPLACE(REPLACE(REPLACE(b.client_phone,' ',''),'-',''),'(','') LIKE %s
               AND b.status IN ('finalizado','concluido','confirmado')
               $where
             ORDER BY b.booking_date ASC",
            '%' . substr( $phone, -8 ) // últimos 8 dígitos para tolerar DDI/DDD
        ) );

        if ( count($dates) < 2 ) return 0;

        $diffs = [];
        for ( $i = 1; $i < count($dates); $i++ ) {
            $diff = ( strtotime($dates[$i]) - strtotime($dates[$i-1]) ) / 86400;
            if ( $diff > 0 && $diff <= 365 ) { // ignora gaps absurdos
                $diffs[] = (int) round($diff);
            }
        }
        if ( empty($diffs) ) return 0;

        return (int) round( array_sum($diffs) / count($diffs) );
    }

    /**
     * WP-Cron: envia mensagem de retorno para clientes que passaram do prazo médio.
     */
    public static function process_return_messages(): void {
        global $wpdb;

        if ( ! BarberPro_Database::get_setting('msg_return_active', '0') ) return;

        $default_days = (int) BarberPro_Database::get_setting('return_default_days', 30);

        // Busca o último atendimento finalizado de cada cliente (phone único)
        $last_bookings = $wpdb->get_results(
            "SELECT b.*, MAX(b.id) as last_id
             FROM {$wpdb->prefix}barber_bookings b
             WHERE b.status IN ('finalizado','concluido')
               AND b.client_phone != ''
             GROUP BY b.client_phone, b.company_id
             HAVING MAX(b.booking_date) < CURDATE()"
        );

        foreach ( $last_bookings as $booking ) {
            // Verifica se já enviou mensagem de retorno para este cliente/empresa recentemente
            $already_sent = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}barber_settings
                 WHERE setting_key = %s",
                'bp_return_sent_' . md5( $booking->client_phone . '_' . $booking->company_id )
            ) );
            if ( $already_sent ) continue;

            // Calcula dias desde último atendimento
            $days_since = (int) floor( ( time() - strtotime($booking->booking_date) ) / 86400 );

            // Calcula média do cliente
            $avg = self::get_client_avg_return( $booking->client_phone, (int) $booking->company_id );
            $threshold = $avg > 0 ? (int) round( $avg * 1.2 ) : $default_days; // 20% de tolerância

            if ( $days_since >= $threshold ) {
                self::send_return_message( $booking );
                // Marca como enviado para evitar spam (expira após 7 dias)
                $wpdb->insert( "{$wpdb->prefix}barber_settings", [
                    'setting_key'   => 'bp_return_sent_' . md5( $booking->client_phone . '_' . $booking->company_id ),
                    'setting_value' => current_time('mysql'),
                ] );
            }
        }
    }

    /**
     * Registra eventos cron para lembretes e mensagens de retorno.
     */
    public static function schedule_reminders(): void {
        if ( ! wp_next_scheduled( 'barberpro_send_reminders' ) ) {
            wp_schedule_event( time(), 'hourly', 'barberpro_send_reminders' );
        }
        if ( ! wp_next_scheduled( 'barberpro_send_return_msgs' ) ) {
            wp_schedule_event( time(), 'daily', 'barberpro_send_return_msgs' );
        }
        add_action( 'barberpro_send_reminders',   [ __CLASS__, 'process_reminders' ] );
        add_action( 'barberpro_send_return_msgs', [ __CLASS__, 'process_return_messages' ] );
    }

    /**
     * Processa lembretes para agendamentos na próxima hora.
     */
    public static function process_reminders(): void {
        global $wpdb;
        $target_time = date( 'Y-m-d H:i:s', strtotime( '+1 hour' ) );
        $range_start = date( 'Y-m-d H:i:s', strtotime( '+55 minutes' ) );

        $bookings = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}barber_bookings
                 WHERE status IN ('agendado','confirmado')
                   AND CONCAT(booking_date,' ',booking_time) BETWEEN %s AND %s
                   AND reminder_sent = 0",
                $range_start, $target_time
            )
        );

        foreach ( $bookings as $booking ) {
            BarberPro_Notifications::dispatch( 'reminder', $booking );
            $wpdb->update(
                "{$wpdb->prefix}barber_bookings",
                [ 'reminder_sent' => 1 ],
                [ 'id' => $booking->id ]
            );
        }
    }
}
