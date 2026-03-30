<?php
/**
 * BarberPro – Disparo em massa WhatsApp (com progresso)
 *
 * Implementa um job assíncrono processado via WP-Cron:
 * - envia 1 mensagem por tick
 * - respeita delay_seconds entre envios
 * - atualiza progresso em transient
 * - suporta mídia (URL + tipo: image|video|document) e placeholder {nome}
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Bulk_WhatsApp {
    private const TRANSIENT_PREFIX = 'bp_bulk_wa_job_';
    private const TTL_SECONDS     = 12 * HOUR_IN_SECONDS;
    private const HOOK            = 'barberpro_bulk_wa_tick';

    public static function start_job( array $job ): array {
        $passed_job_id = isset($job['job_id']) ? (string) $job['job_id'] : '';
        $job_id = $passed_job_id !== ''
            ? sanitize_key($passed_job_id)
            : ( 'job_' . md5( wp_rand() . '|' . microtime(true) ) );

        $company_id    = absint( $job['company_id'] ?? 0 );
        $delay_seconds = max( 0, min( 120, (int) ( $job['delay_seconds'] ?? 3 ) ) );
        $message       = sanitize_textarea_field( (string) ( $job['message'] ?? '' ) );
        $targets       = is_array( $job['targets'] ?? null ) ? $job['targets'] : [];

        if ( ! $company_id ) {
            return [ 'success' => false, 'message' => 'company_id inválido.' ];
        }
        if ( $message === '' ) {
            return [ 'success' => false, 'message' => 'Mensagem vazia.' ];
        }
        if ( empty( $targets ) ) {
            return [ 'success' => false, 'message' => 'Nenhum cliente com telefone para enviar.' ];
        }

        $media_url  = ! empty( $job['media_url'] ) ? esc_url_raw( (string) $job['media_url'] ) : null;
        $media_type = sanitize_key( (string) ( $job['media_type'] ?? 'image' ) );
        if ( ! in_array( $media_type, [ 'image', 'video', 'document' ], true ) ) {
            $media_type = 'image';
        }

        // Mantém ordem original.
        $targets_clean = [];
        foreach ( $targets as $t ) {
            $phone = preg_replace( '/\D/', '', (string) ( $t['phone'] ?? '' ) );
            if ( strlen( $phone ) < 10 ) continue;
            $targets_clean[] = [
                'phone' => $phone,
                'name'  => sanitize_text_field( (string) ( $t['name'] ?? '' ) ),
            ];
        }

        if ( empty( $targets_clean ) ) {
            return [ 'success' => false, 'message' => 'Nenhum destinatário válido.' ];
        }

        $state = [
            'status'        => 'processing',
            'company_id'    => $company_id,
            'message'       => $message,
            'delay_seconds' => $delay_seconds,
            'media_url'     => $media_url,
            'media_type'    => $media_url ? $media_type : null,
            'targets'       => $targets_clean,
            'total'         => count($targets_clean),
            'sent'          => 0,
            'failed'        => 0,
            'index'         => 0,
            'created_at'    => current_time( 'mysql' ),
            'last_tick_at'  => null,
        ];

        set_transient( self::TRANSIENT_PREFIX . $job_id, $state, self::TTL_SECONDS );

        // Primeiro tick em 1 segundo.
        wp_schedule_single_event( time() + 1, self::HOOK, [ $job_id ] );

        return [
            'success' => true,
            'job_id'  => $job_id,
            'total'   => $state['total'],
        ];
    }

    public static function tick( string $job_id ): void {
        $key   = self::TRANSIENT_PREFIX . $job_id;
        $state = get_transient( $key );
        if ( ! is_array( $state ) || empty( $state['status'] ) ) return;
        if ( (string) $state['status'] !== 'processing' ) return;

        $state['last_tick_at'] = current_time( 'mysql' );

        $company_id    = absint( $state['company_id'] ?? 0 );
        $delay_seconds = max( 0, (int) ( $state['delay_seconds'] ?? 3 ) );
        $message_tpl   = (string) ( $state['message'] ?? '' );
        $media_url     = ! empty( $state['media_url'] ) ? (string) $state['media_url'] : null;
        $media_type    = ! empty( $state['media_type'] ) ? (string) $state['media_type'] : 'image';

        $targets = is_array( $state['targets'] ?? null ) ? $state['targets'] : [];
        $total    = (int) ( $state['total'] ?? count( $targets ) );
        $index    = (int) ( $state['index'] ?? 0 );

        if ( $index >= $total ) {
            $state['status'] = 'done';
            set_transient( $key, $state, self::TTL_SECONDS );
            return;
        }

        $tgt = $targets[ $index ] ?? null;
        if ( ! $tgt ) {
            $state['failed']++;
            $state['index']++;
            set_transient( $key, $state, self::TTL_SECONDS );
        } else {
            $phone = (string) ( $tgt['phone'] ?? '' );
            $name  = (string) ( $tgt['name'] ?? '' );
            $msg   = str_replace( '{nome}', $name, $message_tpl );

            $ok = false;
            if ( class_exists( 'BarberPro_WhatsApp_Sender' ) ) {
                $ok = BarberPro_WhatsApp_Sender::send(
                    $company_id,
                    $phone,
                    $msg,
                    $media_url ?: null,
                    $media_url ? $media_type : 'text'
                );
            }

            if ( $ok ) $state['sent']++;
            else       $state['failed']++;

            $state['index']++;
            set_transient( $key, $state, self::TTL_SECONDS );
        }

        // Agenda próximo tick se ainda houver pendentes.
        if ( (int) ( $state['index'] ?? 0 ) < (int) ( $state['total'] ?? 0 ) ) {
            wp_schedule_single_event( time() + $delay_seconds, self::HOOK, [ $job_id ] );
        } else {
            $state['status'] = 'done';
            set_transient( $key, $state, self::TTL_SECONDS );
        }
    }

    public static function get_status( string $job_id ): array {
        $state = get_transient( self::TRANSIENT_PREFIX . $job_id );
        if ( ! is_array( $state ) ) {
            return [ 'found' => false ];
        }
        $total  = (int) ( $state['total'] ?? 0 );
        $sent   = (int) ( $state['sent'] ?? 0 );
        $failed = (int) ( $state['failed'] ?? 0 );

        $done   = (string) ( $state['status'] ?? '' ) === 'done';
        $idx    = (int) ( $state['index'] ?? 0 );
        $pct    = $total > 0 ? min( 100, (int) floor( ( ( $sent + $failed ) * 100 ) / max( 1, $total ) ) ) : 0;

        return [
            'found'  => true,
            'job_id' => $job_id,
            'status' => $state['status'] ?? 'processing',
            'total'  => $total,
            'sent'   => $sent,
            'failed' => $failed,
            'index'  => $idx,
            'pct'     => $pct,
            'done'    => $done,
            'last_tick_at' => $state['last_tick_at'] ?? null,
        ];
    }
}

