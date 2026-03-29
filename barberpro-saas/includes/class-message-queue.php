<?php
/**
 * BarberPro – Fila de Mensagens WhatsApp
 *
 * Gerencia uma fila persistente de mensagens a enviar via WhatsApp,
 * com controle de tentativas, status e delay entre disparos.
 *
 * Tabela: {prefix}barber_message_queue
 *
 * @package BarberProSaaS
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Message_Queue {

    const TABLE      = 'barber_message_queue';
    const MAX_TRIES  = 3;
    const LOCK_KEY   = 'barberpro_queue_lock';
    const LOCK_TTL   = 90; // segundos — evita execução duplicada

    // =========================================================
    // INSTALAÇÃO / MIGRAÇÃO
    // =========================================================

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . self::TABLE;

        dbDelta( "CREATE TABLE IF NOT EXISTS {$table} (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id  INT UNSIGNED    NOT NULL DEFAULT 1,
            phone       VARCHAR(30)     NOT NULL,
            message     TEXT            NOT NULL,
            media_url   VARCHAR(500)    DEFAULT NULL,
            type        ENUM('text','image','video','document') NOT NULL DEFAULT 'text',
            status      ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
            priority    TINYINT         NOT NULL DEFAULT 5,
            attempts    TINYINT         NOT NULL DEFAULT 0,
            context     VARCHAR(100)    DEFAULT NULL,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            scheduled_at DATETIME       DEFAULT NULL,
            sent_at     DATETIME        DEFAULT NULL,
            error_msg   VARCHAR(255)    DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status_priority (status, priority, scheduled_at),
            KEY company_id (company_id),
            KEY phone (phone)
        ) {$charset};" );
    }

    // =========================================================
    // ADICIONAR NA FILA
    // =========================================================

    /**
     * Adiciona mensagem na fila.
     *
     * @param array $args {
     *   @type int    $company_id   ID da empresa (obrigatório)
     *   @type string $phone        Número destino
     *   @type string $message      Texto da mensagem
     *   @type string $media_url    URL de mídia (opcional)
     *   @type string $type         'text'|'image'|'video'|'document'
     *   @type int    $priority     1=alta, 5=normal, 10=baixa
     *   @type string $scheduled_at Datetime para envio futuro (opcional)
     *   @type string $context      Contexto livre: 'reminder_24h', 'reactivation', etc.
     * }
     * @return int|false ID inserido ou false em erro
     */
    public static function add( array $args ): int|false {
        global $wpdb;

        if ( empty($args['company_id']) || empty($args['phone']) || empty($args['message']) ) {
            return false;
        }

        $data = [
            'company_id'   => (int) $args['company_id'],
            'phone'        => self::normalize_phone( $args['phone'] ),
            'message'      => sanitize_textarea_field( $args['message'] ),
            'media_url'    => ! empty($args['media_url']) ? esc_url_raw($args['media_url']) : null,
            'type'         => in_array($args['type'] ?? 'text', ['text','image','video','document']) ? $args['type'] : 'text',
            'priority'     => max(1, min(10, (int)($args['priority'] ?? 5))),
            'context'      => sanitize_key($args['context'] ?? ''),
            'scheduled_at' => ! empty($args['scheduled_at']) ? $args['scheduled_at'] : current_time('mysql'),
            'created_at'   => current_time('mysql'),
        ];

        $r = $wpdb->insert( $wpdb->prefix . self::TABLE, $data );
        return $r ? $wpdb->insert_id : false;
    }

    /**
     * Atalho para mensagem de texto simples.
     */
    public static function push( int $company_id, string $phone, string $message, array $opts = [] ): int|false {
        return self::add( array_merge([
            'company_id' => $company_id,
            'phone'      => $phone,
            'message'    => $message,
        ], $opts) );
    }

    /**
     * Mensagem com mídia (imagem, vídeo, documento).
     */
    public static function push_media( int $company_id, string $phone, string $message, string $media_url, string $type = 'image', array $opts = [] ): int|false {
        return self::add( array_merge([
            'company_id' => $company_id,
            'phone'      => $phone,
            'message'    => $message,
            'media_url'  => $media_url,
            'type'       => $type,
        ], $opts) );
    }

    /**
     * Agenda mensagem para envio futuro.
     */
    public static function schedule( int $company_id, string $phone, string $message, string $send_at, array $opts = [] ): int|false {
        return self::add( array_merge([
            'company_id'   => $company_id,
            'phone'        => $phone,
            'message'      => $message,
            'scheduled_at' => $send_at,
        ], $opts) );
    }

    // =========================================================
    // BUSCAR MENSAGENS
    // =========================================================

    /**
     * Busca próximas mensagens pendentes para processar.
     *
     * @param int $limit   Máximo de mensagens por ciclo
     * @param int $company_id  0 = todas as empresas
     */
    public static function get_pending( int $limit = 5, int $company_id = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $where = $wpdb->prepare(
            "status = 'pending' AND attempts < %d AND scheduled_at <= %s",
            self::MAX_TRIES,
            current_time('mysql')
        );

        if ( $company_id ) {
            $where .= $wpdb->prepare( ' AND company_id = %d', $company_id );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY priority ASC, scheduled_at ASC LIMIT %d",
            $limit
        ) ) ?: [];
    }

    /**
     * Verifica se já existe mensagem do mesmo contexto para o mesmo telefone
     * para evitar duplicatas (ex: lembrete 24h já enviado).
     */
    public static function exists( int $company_id, string $phone, string $context, int $hours_back = 24 ): bool {
        global $wpdb;
        $since = date('Y-m-d H:i:s', strtotime("-{$hours_back} hours"));
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}" . self::TABLE . "
             WHERE company_id = %d AND phone = %s AND context = %s
               AND status IN ('pending','processing','sent')
               AND created_at >= %s
             LIMIT 1",
            $company_id,
            self::normalize_phone($phone),
            $context,
            $since
        ) );
    }

    // =========================================================
    // ATUALIZAR STATUS
    // =========================================================

    public static function mark_processing( int $id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [ 'status' => 'processing' ],
            [ 'id' => $id ]
        );
    }

    public static function mark_sent( int $id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            [ 'status' => 'sent', 'sent_at' => current_time('mysql') ],
            [ 'id' => $id ]
        );
    }

    public static function mark_failed( int $id, string $error = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Incrementa tentativas
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET attempts = attempts + 1, error_msg = %s,
             status = IF(attempts + 1 >= %d, 'failed', 'pending')
             WHERE id = %d",
            mb_substr($error, 0, 255),
            self::MAX_TRIES,
            $id
        ) );
    }

    // =========================================================
    // LOCK ANTI-DUPLICAÇÃO
    // =========================================================

    /**
     * Tenta adquirir o lock de processamento.
     * Retorna false se outro processo já está rodando.
     */
    public static function acquire_lock(): bool {
        $existing = get_transient( self::LOCK_KEY );
        if ( $existing ) return false;
        set_transient( self::LOCK_KEY, microtime(true), self::LOCK_TTL );
        return true;
    }

    public static function release_lock(): void {
        delete_transient( self::LOCK_KEY );
    }

    // =========================================================
    // ESTATÍSTICAS
    // =========================================================

    public static function stats( int $company_id = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $where = $company_id ? $wpdb->prepare( 'WHERE company_id = %d', $company_id ) : '';

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) as total FROM {$table} {$where} GROUP BY status"
        );

        $out = [ 'pending'=>0, 'sent'=>0, 'failed'=>0, 'processing'=>0 ];
        foreach ( $rows as $r ) $out[$r->status] = (int) $r->total;
        return $out;
    }

    /**
     * Limpa mensagens enviadas há mais de X dias.
     */
    public static function cleanup( int $days = 30 ): int {
        global $wpdb;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE . "
             WHERE status IN ('sent','failed') AND created_at < %s",
            $cutoff
        ) );
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private static function normalize_phone( string $phone ): string {
        $clean = preg_replace('/\D/', '', $phone);
        if ( strlen($clean) <= 11 ) $clean = '55' . $clean;
        return $clean;
    }
}
