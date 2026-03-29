<?php
/**
 * BarberPro – Carteira de Clientes
 *
 * Gerencia a carteira de clientes com suporte a VIP, recorrência
 * e notificações automáticas de retorno.
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Clients {

    // =========================================================
    // CRUD
    // =========================================================

    /**
     * Busca ou cria cliente pelo telefone.
     */
    public static function get_or_create( string $phone, string $name, int $company_id, string $email = '' ): object {
        global $wpdb;
        $phone = self::normalize_phone( $phone );
        $row   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_clients WHERE phone = %s AND company_id = %d",
            $phone, $company_id
        ) );
        if ( $row ) return $row;
        $wpdb->insert( "{$wpdb->prefix}barber_clients", [
            'company_id' => $company_id,
            'name'       => sanitize_text_field( $name ),
            'phone'      => $phone,
            'email'      => sanitize_email( $email ),
            'tipo'       => 'normal',
            'created_at' => current_time('mysql'),
        ] );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_clients WHERE id = %d",
            $wpdb->insert_id
        ) );
    }

    public static function get( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_clients WHERE id = %d", $id
        ) );
    }

    public static function get_by_phone( string $phone, int $company_id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_clients WHERE phone = %s AND company_id = %d",
            self::normalize_phone($phone), $company_id
        ) );
    }

    public static function list( int $company_id, string $search = '', string $tipo = '' ): array {
        global $wpdb;
        $where  = 'WHERE company_id = %d';
        $params = [ $company_id ];
        if ( $search ) {
            $like    = '%' . $wpdb->esc_like($search) . '%';
            $where  .= ' AND (name LIKE %s OR phone LIKE %s OR email LIKE %s)';
            $params  = array_merge($params, [$like, $like, $like]);
        }
        if ( $tipo ) {
            $where  .= ' AND tipo = %s';
            $params[] = $tipo;
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, p.name AS pro_name
             FROM {$wpdb->prefix}barber_clients c
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON c.professional_id = p.id
             {$where}
             ORDER BY tipo DESC, name ASC
             LIMIT 300",
            ...$params
        ) ) ?: [];
    }

    public static function save( array $data ): int|false {
        global $wpdb;
        $id = absint( $data['id'] ?? 0 );

        $recorrencia_dias = ! empty($data['recorrencia_dias']) ? absint($data['recorrencia_dias']) : null;
        $pro_id           = ! empty($data['professional_id'])  ? absint($data['professional_id'])  : null;

        $clean = [
            'name'             => sanitize_text_field( $data['name'] ?? '' ),
            'phone'            => self::normalize_phone( $data['phone'] ?? '' ),
            'email'            => sanitize_email( $data['email'] ?? '' ),
            'tipo'             => in_array($data['tipo']??'normal', ['normal','vip','recorrente']) ? $data['tipo'] : 'normal',
            'recorrencia_dias' => $recorrencia_dias,
            'professional_id'  => $pro_id,
            'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
            'updated_at'       => current_time('mysql'),
        ];

        // Calcula próximo lembrete se recorrente
        if ( $clean['tipo'] === 'recorrente' && $recorrencia_dias ) {
            $base = $data['last_visit'] ?? current_time('Y-m-d');
            $clean['next_reminder'] = date('Y-m-d', strtotime($base . " +{$recorrencia_dias} days"));
        } else {
            $clean['next_reminder'] = null;
        }

        if ( $id ) {
            $r = $wpdb->update("{$wpdb->prefix}barber_clients", $clean, ['id' => $id]);
            return $r !== false ? $id : false;
        }

        $clean['company_id'] = absint( $data['company_id'] ?? 1 );
        $clean['created_at'] = current_time('mysql');
        $r = $wpdb->insert("{$wpdb->prefix}barber_clients", $clean);
        return $r ? $wpdb->insert_id : false;
    }

    /**
     * Registra visita do cliente (chamado após agendamento finalizado).
     */
    public static function register_visit( string $phone, int $company_id, string $date ): void {
        global $wpdb;
        $client = self::get_by_phone($phone, $company_id);
        if ( ! $client ) return;

        $recorrencia = (int)($client->recorrencia_dias ?? 0);
        $next        = $recorrencia > 0
            ? date('Y-m-d', strtotime($date . " +{$recorrencia} days"))
            : null;

        $wpdb->update(
            "{$wpdb->prefix}barber_clients",
            [
                'total_visits'  => (int)$client->total_visits + 1,
                'last_visit'    => $date,
                'next_reminder' => $next,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $client->id]
        );
    }

    // =========================================================
    // LEMBRETES DE RETORNO
    // =========================================================

    /**
     * Envia lembretes para clientes recorrentes com next_reminder = hoje.
     * Chamado via wp_cron ou manualmente.
     */
    public static function send_recurrence_reminders(): void {
        global $wpdb;
        $hoje = current_time('Y-m-d');

        $clientes = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, p.name AS pro_name,
                    comp.name AS company_name,
                    s.setting_value AS booking_url
             FROM {$wpdb->prefix}barber_clients c
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON c.professional_id = p.id
             LEFT JOIN {$wpdb->prefix}barber_companies comp ON c.company_id = comp.id
             LEFT JOIN {$wpdb->prefix}barber_settings s
                    ON s.company_id = c.company_id AND s.setting_key = 'booking_page_url'
             WHERE c.tipo = 'recorrente'
               AND c.next_reminder = %s
               AND c.phone != ''",
            $hoje
        ) ) ?: [];

        foreach ( $clientes as $c ) {
            self::send_reminder_whatsapp($c);

            // Atualiza next_reminder para o próximo ciclo
            global $wpdb;
            $dias = (int)$c->recorrencia_dias;
            if ( $dias > 0 ) {
                $wpdb->update(
                    "{$wpdb->prefix}barber_clients",
                    ['next_reminder' => date('Y-m-d', strtotime($hoje . " +{$dias} days"))],
                    ['id' => $c->id]
                );
            }
        }
    }

    private static function send_reminder_whatsapp( object $c ): void {
        $link = ! empty($c->booking_url) ? "\n\n📅 Agende: {$c->booking_url}" : '';
        $pro  = ! empty($c->pro_name) ? "\n👤 Seu profissional: {$c->pro_name}" : '';
        $msg  = "Olá, *{$c->name}*! 👋\n\n"
              . "Tá na hora de uma visita! ✂️\n"
              . "Faz {$c->recorrencia_dias} dias desde seu último atendimento."
              . $pro
              . $link
              . "\n\nTe esperamos! 😊";

        BarberPro_WhatsApp::send( $c->phone, $msg );
    }

    // =========================================================
    // INTEGRAÇÃO COM AGENDAMENTOS
    // =========================================================

    /**
     * Cria/atualiza cliente na carteira quando um agendamento é criado.
     * Também cria (ou associa) um usuário WordPress para o cliente acessar o painel.
     */
    public static function on_booking_created( array $data ): void {
        if ( empty($data['client_phone']) || empty($data['client_name']) ) return;

        $client = self::get_or_create(
            $data['client_phone'],
            $data['client_name'],
            (int)($data['company_id'] ?? 1),
            $data['client_email'] ?? ''
        );

        // Cria usuário WordPress se o cliente tem e-mail e ainda não tem conta
        if ( ! empty($data['client_email']) && is_email($data['client_email']) ) {
            self::maybe_create_wp_user( $client, $data );
        }
    }

    /**
     * Cria conta WordPress para o cliente (se ainda não existir).
     * Envia e-mail com credenciais de acesso.
     */
    public static function maybe_create_wp_user( object $client, array $data ): void {
        $email = sanitize_email( $data['client_email'] );
        if ( ! $email ) return;

        // Já existe usuário com esse e-mail?
        $user = get_user_by('email', $email);

        if ( ! $user ) {
            // Gera username único a partir do e-mail
            $username_base = sanitize_user( strstr($email, '@', true), true );
            $username      = $username_base;
            $suffix        = 1;
            while ( username_exists($username) ) {
                $username = $username_base . $suffix;
                $suffix++;
            }

            $senha = wp_generate_password( 10, false );

            $user_id = wp_create_user( $username, $senha, $email );

            if ( is_wp_error($user_id) ) return;

            // Define role de cliente
            $u = new WP_User($user_id);
            $u->set_role('subscriber');

            // Salva nome e telefone nos metadados
            update_user_meta( $user_id, 'first_name',    sanitize_text_field($data['client_name']) );
            update_user_meta( $user_id, 'billing_phone', sanitize_text_field($data['client_phone']) );

            // Vincula o usuário WP ao cliente na tabela barber_clients
            global $wpdb;
            $wpdb->update(
                "{$wpdb->prefix}barber_clients",
                ['wp_user_id' => $user_id],
                ['id'         => $client->id]
            );

            // Envia e-mail de boas-vindas com as credenciais
            self::send_welcome_email( $email, $data['client_name'], $username, $senha );

        } else {
            // Usuário já existe — garante vínculo na tabela de clientes
            global $wpdb;
            if ( empty($client->wp_user_id) ) {
                $wpdb->update(
                    "{$wpdb->prefix}barber_clients",
                    ['wp_user_id' => $user->ID],
                    ['id'         => $client->id]
                );
            }
        }
    }

    /**
     * Envia e-mail de boas-vindas com login e senha temporária.
     */
    private static function send_welcome_email( string $email, string $nome, string $username, string $senha ): void {
        $nome_neg    = BarberPro_Database::get_setting('email_nome_remetente', get_bloginfo('name'));
        $from        = BarberPro_Database::get_setting('email_remetente', get_bloginfo('admin_email'));
        $painel_url  = BarberPro_Database::get_setting('booking_page_url', home_url('/minha-conta/'));
        $login_url   = wp_login_url( $painel_url );

        $assunto = "🎉 Sua conta em {$nome_neg} foi criada!";
        $corpo   = "Olá, {$nome}!

"
                 . "Seu agendamento foi confirmado e criamos uma conta para você acompanhar seus horários.

"
                 . "🔐 SEUS DADOS DE ACESSO:
"
                 . "• Usuário: {$username}
"
                 . "• Senha:   {$senha}

"
                 . "👉 Acesse seu painel: {$login_url}

"
                 . "Você pode alterar sua senha após o primeiro acesso.

"
                 . "Até breve! 😊
"
                 . "— Equipe {$nome_neg}";

        wp_mail( $email, $assunto, $corpo, [
            "Content-Type: text/plain; charset=UTF-8",
            "From: {$nome_neg} <{$from}>",
        ] );
    }

    /**
     * Registra visita quando agendamento é finalizado.
     */
    public static function on_booking_finished( object $booking ): void {
        self::register_visit(
            $booking->client_phone,
            (int)$booking->company_id,
            $booking->booking_date
        );
    }

    // =========================================================
    // HELPERS
    // =========================================================

    public static function normalize_phone( string $phone ): string {
        return preg_replace('/\D/', '', $phone);
    }

    public static function tipo_label( string $tipo ): string {
        return match($tipo) {
            'vip'        => '⭐ VIP',
            'recorrente' => '🔁 Recorrente',
            default      => '👤 Normal',
        };
    }

    public static function tipo_color( string $tipo ): string {
        return match($tipo) {
            'vip'        => '#f59e0b',
            'recorrente' => '#3b82f6',
            default      => '#6b7280',
        };
    }
}
