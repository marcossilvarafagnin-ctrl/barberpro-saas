<?php
/**
 * BarberPro – Motor de Automações
 *
 * Responsável por:
 * - Processar a fila de mensagens via WP-Cron
 * - Lembretes automáticos (24h e 2h antes)
 * - Reativação de clientes inativos
 * - Aquecimento/engajamento de clientes
 * - Campanhas manuais
 *
 * @package BarberProSaaS
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Automation {

    // =========================================================
    // REGISTRO DE HOOKS / CRON
    // =========================================================

    public static function init(): void {
        // Processa fila a cada minuto
        if ( ! wp_next_scheduled('barberpro_process_queue') ) {
            wp_schedule_event( time(), 'every_minute', 'barberpro_process_queue' );
        }
        add_action( 'barberpro_process_queue', [ __CLASS__, 'process_queue' ] );

        // Lembretes a cada 5 minutos (mais preciso)
        if ( ! wp_next_scheduled('barberpro_reminders') ) {
            wp_schedule_event( time(), 'every_5_minutes', 'barberpro_reminders' );
        }
        add_action( 'barberpro_reminders', [ __CLASS__, 'queue_reminders' ] );

        // Reativação diária às 9h
        if ( ! wp_next_scheduled('barberpro_reactivation') ) {
            wp_schedule_event( strtotime('09:00:00'), 'daily', 'barberpro_reactivation' );
        }
        add_action( 'barberpro_reactivation', [ __CLASS__, 'queue_reactivation' ] );

        // Aquecimento (engajamento) diário às 10h
        if ( ! wp_next_scheduled('barberpro_warming') ) {
            wp_schedule_event( strtotime('10:00:00'), 'daily', 'barberpro_warming' );
        }
        add_action( 'barberpro_warming', [ __CLASS__, 'queue_warming' ] );

        // Limpeza semanal da fila (mensagens antigas)
        if ( ! wp_next_scheduled('barberpro_queue_cleanup') ) {
            wp_schedule_event( time(), 'weekly', 'barberpro_queue_cleanup' );
        }
        add_action( 'barberpro_queue_cleanup', [ __CLASS__, 'cleanup' ] );
    }

    public static function add_cron_intervals( array $schedules ): array {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => 'A cada 1 minuto',
        ];
        $schedules['every_5_minutes'] = [
            'interval' => 300,
            'display'  => 'A cada 5 minutos',
        ];
        return $schedules;
    }

    public static function deactivate(): void {
        foreach ( ['barberpro_process_queue','barberpro_reminders','barberpro_reactivation','barberpro_warming','barberpro_queue_cleanup'] as $hook ) {
            $ts = wp_next_scheduled($hook);
            if ($ts) wp_unschedule_event($ts, $hook);
        }
    }

    // =========================================================
    // PROCESSADOR DA FILA (roda a cada minuto)
    // =========================================================

    public static function process_queue(): void {
        // Lock anti-duplicação
        if ( ! BarberPro_Message_Queue::acquire_lock() ) return;

        try {
            $batch_size = (int) BarberPro_Database::get_setting('queue_batch_size', 5);
            $delay_ms   = (int) BarberPro_Database::get_setting('queue_delay_seconds', 3) * 1000000; // microsegundos
            $messages   = BarberPro_Message_Queue::get_pending( $batch_size );

            foreach ( $messages as $msg ) {
                BarberPro_Message_Queue::mark_processing( $msg->id );

                $ok = BarberPro_WhatsApp_Sender::send(
                    (int) $msg->company_id,
                    $msg->phone,
                    $msg->message,
                    $msg->media_url ?: null,
                    $msg->type
                );

                if ( $ok ) {
                    BarberPro_Message_Queue::mark_sent( $msg->id );
                } else {
                    BarberPro_Message_Queue::mark_failed( $msg->id, 'Falha no envio via provider' );
                }

                // Delay entre mensagens (anti-spam)
                if ( $delay_ms > 0 ) {
                    usleep( $delay_ms );
                }
            }
        } finally {
            BarberPro_Message_Queue::release_lock();
        }
    }

    // =========================================================
    // LEMBRETES AUTOMÁTICOS
    // =========================================================

    /**
     * Busca agendamentos próximos e adiciona lembretes na fila.
     * Roda a cada 5 minutos.
     */
    public static function queue_reminders(): void {
        global $wpdb;

        $lembrete_24h = BarberPro_Database::get_setting('msg_reminder_active','0') === '1';
        $lembrete_2h  = BarberPro_Database::get_setting('msg_reminder2_active','0') === '1';

        if ( ! $lembrete_24h && ! $lembrete_2h ) return;

        $now = current_time('mysql');

        // Janela: agendamentos entre agora e 25h (para capturar o de 24h)
        $bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*, s.name AS service_name, p.name AS pro_name
             FROM {$wpdb->prefix}barber_bookings b
             LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id = s.id
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id = p.id
             WHERE b.status IN ('agendado','confirmado')
               AND b.client_phone != ''
               AND CONCAT(b.booking_date, ' ', b.booking_time) BETWEEN %s AND DATE_ADD(%s, INTERVAL 25 HOUR)
             ORDER BY b.booking_date ASC, b.booking_time ASC",
            $now, $now
        ) ) ?: [];

        foreach ( $bookings as $b ) {
            $booking_ts  = strtotime( $b->booking_date . ' ' . $b->booking_time );
            $diff_hours  = ( $booking_ts - time() ) / 3600;
            $cid         = (int) $b->company_id;

            // Lembrete 24h
            if ( $lembrete_24h && $diff_hours >= 23.5 && $diff_hours <= 24.5 ) {
                $ctx = 'reminder_24h_' . $b->id;
                if ( ! BarberPro_Message_Queue::exists($cid, $b->client_phone, $ctx, 25) ) {
                    $msg = self::parse_booking_template(
                        BarberPro_Database::get_setting('msg_reminder', 'Olá {nome}! Lembrete: você tem {servico} amanhã às {hora}. Até logo! 😊'),
                        $b
                    );
                    BarberPro_Message_Queue::push( $cid, $b->client_phone, $msg, [
                        'context'  => $ctx,
                        'priority' => 3,
                    ] );
                }
            }

            // Lembrete 2h
            if ( $lembrete_2h && $diff_hours >= 1.8 && $diff_hours <= 2.5 ) {
                $ctx = 'reminder_2h_' . $b->id;
                if ( ! BarberPro_Message_Queue::exists($cid, $b->client_phone, $ctx, 3) ) {
                    $hours = (int) BarberPro_Database::get_setting('reminder2_hours', 2);
                    $msg   = self::parse_booking_template(
                        BarberPro_Database::get_setting('msg_reminder2', "Olá {nome}! Daqui a pouco é hora do seu {servico}. Te esperamos às {hora}! ✂️"),
                        $b
                    );
                    BarberPro_Message_Queue::push( $cid, $b->client_phone, $msg, [
                        'context'  => $ctx,
                        'priority' => 2,
                    ] );
                }
            }
        }
    }

    // =========================================================
    // REATIVAÇÃO DE CLIENTES INATIVOS
    // =========================================================

    /**
     * Identifica clientes sem retorno e enfileira mensagem de reativação.
     * Roda 1x por dia.
     */
    public static function queue_reactivation(): void {
        global $wpdb;

        if ( BarberPro_Database::get_setting('msg_return_active','0') !== '1' ) return;

        $inactive_days = (int) BarberPro_Database::get_setting('return_default_days', 30);
        $template      = BarberPro_Database::get_setting('msg_return', '');
        if ( ! $template ) return;

        $cutoff = date('Y-m-d', strtotime("-{$inactive_days} days"));

        // Último atendimento finalizado de cada cliente, por empresa
        $clients = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.company_id, b.client_phone, b.client_name,
                    MAX(b.booking_date) as last_visit,
                    COUNT(*) as total_visits
             FROM {$wpdb->prefix}barber_bookings b
             WHERE b.status IN ('finalizado','concluido')
               AND b.client_phone != ''
             GROUP BY b.company_id, b.client_phone
             HAVING last_visit < %s",
            $cutoff
        ) ) ?: [];

        $agenda_url = BarberPro_Database::get_setting('booking_page_url', home_url('/agendamento/'));

        foreach ( $clients as $c ) {
            $cid = (int) $c->company_id;
            $ctx = 'reactivation_' . md5($c->client_phone . '_' . $cid);

            // Não reativar mais de 1x por mês
            if ( BarberPro_Message_Queue::exists($cid, $c->client_phone, $ctx, 720) ) continue;

            $days_since = (int) floor((time() - strtotime($c->last_visit)) / 86400);

            $msg = str_replace(
                ['{nome}', '{dias}', '{link_agendamento}'],
                [$c->client_name, $days_since, $agenda_url],
                $template
            );

            BarberPro_Message_Queue::push( $cid, $c->client_phone, $msg, [
                'context'  => $ctx,
                'priority' => 8, // baixa prioridade
            ] );
        }
    }

    // =========================================================
    // AQUECIMENTO / ENGAJAMENTO
    // =========================================================

    /**
     * Envia mensagem de aquecimento (engajamento) para clientes ativos.
     * Respeita configuração de dias da semana, frequência e delay mínimo de 5s.
     */
    public static function queue_warming(): void {
        global $wpdb;

        if ( BarberPro_Database::get_setting('warming_ativo','0') !== '1' ) return;

        // Verifica dias da semana configurados (0=dom, 1=seg, ..., 6=sáb)
        $dias_config = BarberPro_Database::get_setting('warming_dias', '2,5'); // ter e sex
        $dias        = array_map('intval', explode(',', $dias_config));
        $hoje_dow    = (int) date('w'); // dia da semana atual
        if ( ! in_array($hoje_dow, $dias, true) ) return;

        // Frequência: 1x ou 2x por semana
        $frequencia = (int) BarberPro_Database::get_setting('warming_frequencia', 1);
        $horario    = BarberPro_Database::get_setting('warming_horario', '10:00');
        $delay_s    = max(5, (int) BarberPro_Database::get_setting('warming_delay_seconds', 5));

        // Busca conteúdo configurado
        $msg       = BarberPro_Database::get_setting('warming_msg', '');
        $media_url = BarberPro_Database::get_setting('warming_media_url', '');
        $media_type= BarberPro_Database::get_setting('warming_media_type', 'image');

        if ( ! $msg && ! $media_url ) return;

        // Busca clientes únicos com agendamento nos últimos 90 dias
        $clients = $wpdb->get_results(
            "SELECT DISTINCT b.company_id, b.client_phone, b.client_name
             FROM {$wpdb->prefix}barber_bookings b
             WHERE b.client_phone != ''
               AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
             ORDER BY b.company_id, b.client_phone"
        ) ?: [];

        // Calcula janela de "não repetição" — 3 dias para 2x/semana, 5 para 1x/semana
        $no_repeat_hours = $frequencia >= 2 ? 72 : 120;

        foreach ( $clients as $c ) {
            $cid = (int) $c->company_id;
            $ctx = 'warming_' . md5($c->client_phone . '_' . $cid);

            if ( BarberPro_Message_Queue::exists($cid, $c->client_phone, $ctx, $no_repeat_hours) ) continue;

            $texto = str_replace('{nome}', $c->client_name, $msg);

            if ( $media_url ) {
                BarberPro_Message_Queue::push_media( $cid, $c->client_phone, $texto, $media_url, $media_type, [
                    'context'  => $ctx,
                    'priority' => 10,
                    'scheduled_at' => date('Y-m-d') . ' ' . $horario . ':00',
                ] );
            } else {
                BarberPro_Message_Queue::push( $cid, $c->client_phone, $texto, [
                    'context'  => $ctx,
                    'priority' => 10,
                    'scheduled_at' => date('Y-m-d') . ' ' . $horario . ':00',
                ] );
            }
        }
    }

    // =========================================================
    // CAMPANHA MANUAL
    // =========================================================

    /**
     * Envia campanha manual para lista de clientes.
     *
     * @param int    $company_id
     * @param array  $phones     Lista de números
     * @param string $message    Texto (suporta {nome})
     * @param string $media_url  URL de mídia (opcional)
     * @param array  $opts       priority, context, scheduled_at, delay_seconds
     * @return int   Número de mensagens enfileiradas
     */
    public static function send_campaign( int $company_id, array $phones, string $message, string $media_url = '', array $opts = [] ): int {
        $delay_s  = max(5, (int)($opts['delay_seconds'] ?? 5));
        $context  = sanitize_key($opts['context'] ?? 'campaign_' . time());
        $priority = (int)($opts['priority'] ?? 9);
        $base_at  = strtotime($opts['scheduled_at'] ?? 'now');
        $count    = 0;

        foreach ( array_unique($phones) as $i => $phone ) {
            if ( empty($phone) ) continue;

            // Distribui os envios com delay progressivo (evita burst)
            $send_at = date('Y-m-d H:i:s', $base_at + ($i * $delay_s));

            $args = [
                'company_id'   => $company_id,
                'phone'        => $phone,
                'message'      => $message,
                'context'      => $context,
                'priority'     => $priority,
                'scheduled_at' => $send_at,
            ];

            if ( $media_url ) {
                $args['media_url'] = $media_url;
                $args['type']      = $opts['media_type'] ?? 'image';
            }

            $r = BarberPro_Message_Queue::add($args);
            if ($r) $count++;
        }

        return $count;
    }

    // =========================================================
    // LIMPEZA
    // =========================================================

    public static function cleanup(): void {
        $days = (int) BarberPro_Database::get_setting('queue_cleanup_days', 30);
        BarberPro_Message_Queue::cleanup($days);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private static function parse_booking_template( string $template, object $b ): string {
        $hora = substr($b->booking_time ?? '', 0, 5);
        $data = $b->booking_date ? date_i18n('d/m/Y', strtotime($b->booking_date)) : '';
        return str_replace(
            ['{nome}', '{data}', '{hora}', '{servico}', '{profissional}', '{codigo}'],
            [$b->client_name, $data, $hora, $b->service_name ?? '', $b->pro_name ?? '', $b->booking_code ?? ''],
            $template
        );
    }
}
