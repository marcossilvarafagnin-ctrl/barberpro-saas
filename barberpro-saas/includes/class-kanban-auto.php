<?php
/**
 * BarberPro – Automação do Kanban
 *
 * Roda via WP-Cron a cada minuto e avança os agendamentos
 * automaticamente entre os status do Kanban com base no horário.
 *
 * Fluxo:
 *   agendado        → confirmado      : X min antes do horário (configurável)
 *   confirmado      → em_atendimento  : quando bater o horário
 *   em_atendimento  → finalizado      : horário + duração do serviço
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Kanban_Auto {

    // ── Registro do cron ──────────────────────────────────────────

    public static function schedule(): void {
        // Registra intervalo de 1 minuto se não existir
        add_filter( 'cron_schedules', [ __CLASS__, 'add_minute_interval' ] );

        if ( ! wp_next_scheduled( 'barberpro_kanban_auto' ) ) {
            wp_schedule_event( time(), 'every_minute', 'barberpro_kanban_auto' );
        }

        add_action( 'barberpro_kanban_auto', [ __CLASS__, 'run' ] );
    }

    public static function add_minute_interval( array $schedules ): array {
        if ( ! isset( $schedules['every_minute'] ) ) {
            $schedules['every_minute'] = [
                'interval' => 60,
                'display'  => 'A cada minuto',
            ];
        }
        return $schedules;
    }

    public static function unschedule(): void {
        $ts = wp_next_scheduled( 'barberpro_kanban_auto' );
        if ( $ts ) wp_unschedule_event( $ts, 'barberpro_kanban_auto' );
    }

    // ── Execução principal ────────────────────────────────────────

    public static function run(): void {
        // Só roda se automação estiver habilitada
        if ( BarberPro_Database::get_setting( 'kanban_auto_enabled', '1' ) !== '1' ) return;

        global $wpdb;
        $now      = current_time( 'timestamp' );
        $today    = date( 'Y-m-d', $now );
        $tomorrow = date( 'Y-m-d', $now + DAY_IN_SECONDS );

        // Janela: hoje e amanhã (para cobrir virada de meia-noite)
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, s.duration AS svc_duration
             FROM {$wpdb->prefix}barber_bookings b
             LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id = s.id
             WHERE b.booking_date IN (%s, %s)
               AND b.status IN ('agendado','confirmado','em_atendimento')
             ORDER BY b.booking_date ASC, b.booking_time ASC",
            $today, $tomorrow
        ) ) ?: [];

        if ( empty( $bookings ) ) return;

        // Configurações
        $mins_before_confirm = (int) BarberPro_Database::get_setting( 'kanban_auto_confirm_minutes', '30' );
        $auto_confirm        = BarberPro_Database::get_setting( 'kanban_auto_confirm',    '1' ) === '1';
        $auto_start          = BarberPro_Database::get_setting( 'kanban_auto_start',      '1' ) === '1';
        $auto_finish         = BarberPro_Database::get_setting( 'kanban_auto_finish',     '1' ) === '1';

        foreach ( $bookings as $b ) {
            $booking_ts  = strtotime( $b->booking_date . ' ' . $b->booking_time );
            $duration    = max( 15, (int) ( $b->svc_duration ?? 30 ) );
            $finish_ts   = $booking_ts + $duration * MINUTE_IN_SECONDS;

            $new_status = null;

            switch ( $b->status ) {

                case 'agendado':
                    // → confirmado: X minutos antes do horário
                    if ( $auto_confirm && $now >= ( $booking_ts - $mins_before_confirm * MINUTE_IN_SECONDS ) ) {
                        $new_status = 'confirmado';
                    }
                    break;

                case 'confirmado':
                    // → em_atendimento: quando bater o horário
                    if ( $auto_start && $now >= $booking_ts ) {
                        $new_status = 'em_atendimento';
                    }
                    break;

                case 'em_atendimento':
                    // → finalizado: após horário + duração
                    if ( $auto_finish && $now >= $finish_ts ) {
                        $new_status = 'finalizado';
                    }
                    break;
            }

            if ( $new_status ) {
                self::advance( (int) $b->id, $b->status, $new_status );
            }
        }
    }

    // ── Avança status e registra log ──────────────────────────────

    private static function advance( int $booking_id, string $from, string $to ): void {
        global $wpdb;

        $updated = $wpdb->update(
            "{$wpdb->prefix}barber_bookings",
            [ 'status' => $to, 'updated_at' => current_time('mysql') ],
            [ 'id' => $booking_id, 'status' => $from ] // double-check status to avoid race condition
        );

        if ( $updated ) {
            // Log interno (tabela de meta se existir, senão option transitória)
            $log_key = 'bp_kanban_auto_log';
            $log     = get_transient( $log_key ) ?: [];
            $log[]   = [
                'booking_id' => $booking_id,
                'from'       => $from,
                'to'         => $to,
                'time'       => current_time('mysql'),
            ];
            // Mantém só os últimos 200 registros
            if ( count($log) > 200 ) $log = array_slice( $log, -200 );
            set_transient( $log_key, $log, DAY_IN_SECONDS );

            // Dispara hook para outros sistemas reagirem (WhatsApp, etc.)
            do_action( 'barberpro_booking_status_changed', $booking_id, $to, null );
        }
    }

    // ── Retorna log das últimas automações ────────────────────────

    public static function get_log(): array {
        return array_reverse( get_transient('bp_kanban_auto_log') ?: [] );
    }
}
