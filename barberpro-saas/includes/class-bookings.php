<?php
/**
 * Lógica de negócio para agendamentos
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Bookings {

    /**
     * Gera slots de horário disponíveis para um profissional numa data.
     *
     * @param int    $professional_id
     * @param string $date            Y-m-d
     * @param int    $service_duration Duração do serviço em minutos
     * @param bool   $admin_mode       true = intervalo do barbeiro (15min), false = intervalo do cliente (60min)
     */
    public static function get_available_slots( int $professional_id, string $date, int $service_duration = 30, bool $admin_mode = false ): array {
        $pro = BarberPro_Database::get_professional( $professional_id );
        if ( ! $pro ) return [];

        // Verifica dia da semana
        $day_of_week = (int) date( 'w', strtotime( $date ) );
        $work_days   = array_map( 'intval', explode( ',', $pro->work_days ) );
        if ( ! in_array( $day_of_week, $work_days, true ) ) return [];

        // Intervalo: barbeiro usa slot_interval (15min padrão), cliente usa client_slot_interval (60min padrão)
        if ( $admin_mode ) {
            $interval = max( 5, (int) ( $pro->slot_interval ?? 15 ) );
        } else {
            $interval = max( 15, (int) ( $pro->client_slot_interval ?? 60 ) );
        }

        $slots    = self::generate_time_slots(
            $pro->work_start,
            $pro->work_end,
            $pro->lunch_start,
            $pro->lunch_end,
            $interval,
            $service_duration
        );

        $booked      = BarberPro_Database::get_booked_slots( $professional_id, $date );
        $min_advance = max( 0, (int) BarberPro_Database::get_setting( 'booking_min_advance_minutes', 60 ) );
        $max_days    = (int) BarberPro_Database::get_setting( 'booking_max_advance_days', 30 );

        try {
            $tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'America/Sao_Paulo' );
        } catch ( \Exception $e ) {
            $tz = new DateTimeZone( 'America/Sao_Paulo' );
        }
        $now_dt = new DateTimeImmutable( 'now', $tz );
        $max_dt = $now_dt->modify( "+{$max_days} days" );

        $available = [];
        foreach ( $slots as $slot ) {
            $slot_key = BarberPro_Database::normalize_booking_time_key( $slot );
            if ( in_array( $slot_key, $booked, true ) ) {
                continue;
            }
            $slot_dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . $slot_key, $tz );
            if ( ! $slot_dt ) {
                continue;
            }
            if ( ! $admin_mode ) {
                if ( $slot_dt <= $now_dt ) {
                    continue;
                }
                $min_ok = $now_dt->modify( '+' . $min_advance . ' minutes' );
                if ( $slot_dt < $min_ok ) {
                    continue;
                }
            }
            if ( $slot_dt > $max_dt ) {
                continue;
            }
            $available[] = $slot;
        }
        return $available;
    }

    /**
     * Gera todos os slots de tempo, descontando horário de almoço.
     */
    private static function generate_time_slots(
        string $work_start, string $work_end,
        string $lunch_start, string $lunch_end,
        int    $interval, int $duration
    ): array {
        $slots   = [];
        $current = strtotime( $work_start );
        $end     = strtotime( $work_end );
        $ls      = strtotime( $lunch_start );
        $le      = strtotime( $lunch_end );

        while ( $current + $duration * 60 <= $end ) {
            $slot_end = $current + $duration * 60;
            // Pula se slot colidir com almoço
            $overlaps_lunch = ! ( $slot_end <= $ls || $current >= $le );
            if ( ! $overlaps_lunch ) {
                $slots[] = date( 'H:i', $current );
            }
            $current += $interval * 60;
        }
        return $slots;
    }

    /**
     * Cria um agendamento com todas as validações de negócio.
     *
     * @return array{success: bool, message: string, booking_id?: int, booking_code?: string}
     */
    public static function create_booking( array $data ): array {
        // ── Validações básicas ────────────────────────────────────────────────
        $required = [ 'service_id', 'professional_id', 'client_name', 'booking_date', 'booking_time' ];
        foreach ( $required as $field ) {
            if ( empty( $data[ $field ] ) ) {
                return [ 'success' => false, 'message' => 'Campo obrigatório: ' . $field ];
            }
        }

        $service = BarberPro_Database::get_service( (int) $data['service_id'] );
        if ( ! $service ) {
            return [ 'success' => false, 'message' => 'Serviço não encontrado.' ];
        }

        $svc_duration = (int) ( $service->duration_minutes ?? $service->duration ?? 30 );

        // Horário sempre no mesmo formato da coluna TIME (evita falha de validação 09:00 vs 09:00:00)
        $data['booking_time'] = BarberPro_Database::normalize_booking_time_db( (string) ( $data['booking_time'] ?? '' ) );
        $time_want_key        = BarberPro_Database::normalize_booking_time_key( $data['booking_time'] );

        // ── Resolve "Primeiro Disponível" ─────────────────────────────────────
        $pro_id     = (int) $data['professional_id'];
        $admin_mode = ! empty( $data['admin_mode'] );
        $company_id = (int) ( $data['company_id'] ?? BarberPro_Database::get_company_id() );

        if ( $pro_id === 0 ) {
            // FIX: passa company_id para buscar só profissionais da empresa correta
            $professionals = BarberPro_Database::get_professionals( $company_id );
            foreach ( $professionals as $p ) {
                $slots = self::get_available_slots( (int) $p->id, $data['booking_date'], $svc_duration, $admin_mode );
                foreach ( $slots as $s ) {
                    if ( BarberPro_Database::normalize_booking_time_key( $s ) === $time_want_key ) {
                        $pro_id = (int) $p->id;
                        $data['booking_time'] = BarberPro_Database::normalize_booking_time_db( $s );
                        break 2;
                    }
                }
            }
            if ( $pro_id === 0 ) {
                return [ 'success' => false, 'message' => 'Nenhum profissional disponível neste horário.' ];
            }
            $data['professional_id'] = $pro_id;
        }

        // ── Lock atômico: impede duplo agendamento no mesmo horário ─────────────
        // Usa transação + verificação pós-lock para garantir atomicidade
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );

        $conflito = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}barber_bookings
              WHERE professional_id = %d
                AND booking_date    = %s
                AND TIME_FORMAT(booking_time, '%H:%i') = %s
                AND status NOT IN ('cancelado','recusado')
              FOR UPDATE",
            $pro_id,
            $data['booking_date'],
            $time_want_key
        ) );

        if ( $conflito > 0 ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => 'Este horário acabou de ser reservado. Por favor escolha outro.' ];
        }

        // ── Valida disponibilidade do horário ─────────────────────────────────
        $available = self::get_available_slots( $pro_id, $data['booking_date'], $svc_duration, $admin_mode );
        $avail_keys = array_map( static function ( $s ) {
            return BarberPro_Database::normalize_booking_time_key( $s );
        }, $available );
        if ( ! in_array( $time_want_key, $avail_keys, true ) ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => 'Horário não disponível. Por favor escolha outro.' ];
        }

        // ── Insere agendamento ────────────────────────────────────────────────
        $booking_id = BarberPro_Database::insert_booking( $data );
        if ( ! $booking_id ) {
            $wpdb->query( 'ROLLBACK' );
            return [ 'success' => false, 'message' => 'Erro ao criar agendamento. Tente novamente.' ];
        }

        $wpdb->query( 'COMMIT' );

        $booking = BarberPro_Database::get_booking( $booking_id );

        // ── Registra receita no financeiro ────────────────────────────────────
        // Busca categoria "Serviços" do plano de contas
        $service_cat_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}barber_finance_categories WHERE company_id=%d AND type='receita' AND code='REC-001' LIMIT 1",
                $company_id
            )
        );

        BarberPro_Finance::insert( [
            'company_id'       => $company_id,
            'booking_id'       => $booking_id,
            'type'             => 'receita',
            'category_id'      => $service_cat_id ?: null,
            'description'      => sprintf( 'Serviço: %s – %s', $service->name, $booking->booking_code ),
            'amount'           => (float) $service->price,
            'payment_method'   => self::map_payment_method( $data['payment_method'] ?? 'dinheiro' ),
            'status'           => 'pendente', // Pendente até finalizar atendimento
            'competencia_date' => $data['booking_date'],
            'due_date'         => $data['booking_date'],
            'professional_id'  => $pro_id,
            'supplier'         => $booking->client_name,
        ] );

        // ── Registra comissão pendente ────────────────────────────────────────
        $professional = BarberPro_Database::get_professional( $pro_id );
        if ( $professional ) {
            $pct        = (float) $professional->commission_pct;
            $commission = round( (float) $service->price * $pct / 100, 2 );

            // Categoria comissões
            $comm_cat_id = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}barber_finance_categories WHERE company_id=%d AND type='despesa' AND code='DESP-001' LIMIT 1",
                    $company_id
                )
            );

            $wpdb->insert( "{$wpdb->prefix}barber_commissions", [
                'company_id'      => $company_id,
                'professional_id' => $pro_id,
                'booking_id'      => $booking_id,
                'gross_amount'    => (float) $service->price,
                'pct'             => $pct,
                'amount'          => $commission,
                'status'          => 'pendente',
                'created_at'      => current_time( 'mysql' ),
            ] );
        }

        // ── WhatsApp de confirmação ───────────────────────────────────────────
        BarberPro_Notifications::dispatch( 'confirmation', $booking );

        do_action( 'barberpro_booking_created', $booking_id, $booking );

        return [
            'success'      => true,
            'message'      => 'Agendamento realizado com sucesso!',
            'booking_id'   => $booking_id,
            'booking_code' => $booking->booking_code,
            'service_name' => $service->name,
            'date'         => $data['booking_date'],
            'time'         => $data['booking_time'],
        ];
    }

    /**
     * Mapeia método de pagamento do booking para o enum do financeiro.
     */
    private static function map_payment_method( string $method ): string {
        $map = [
            'presencial'  => 'dinheiro',
            'online'      => 'cartao_credito',
            'pix'         => 'pix',
            'dinheiro'    => 'dinheiro',
            'cartao'      => 'cartao_debito',
            'transferencia' => 'transferencia',
        ];
        return $map[ $method ] ?? 'dinheiro';
    }

    /**
     * Atualiza status de agendamento com lógica de negócio.
     */
    public static function update_status( int $booking_id, string $new_status ): array {
        $allowed = [ 'agendado','confirmado','em_atendimento','finalizado','cancelado','recusado' ];
        if ( ! in_array( $new_status, $allowed, true ) ) {
            return [ 'success' => false, 'message' => 'Status inválido.' ];
        }

        $updated = BarberPro_Database::update_booking_status( $booking_id, $new_status );
        if ( ! $updated ) {
            return [ 'success' => false, 'message' => 'Erro ao atualizar status.' ];
        }

        $booking = BarberPro_Database::get_booking( $booking_id );

        // Quando finalizado: quita receita e comissão
        if ( $new_status === 'finalizado' && $booking ) {
            global $wpdb;
            // Marca receita como paga
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}barber_finance SET status='pago', paid_at=%s, updated_at=%s WHERE booking_id=%d AND type='receita'",
                current_time('mysql'), current_time('mysql'), $booking_id
            ) );
            // Dispara pedido de avaliação
            BarberPro_Notifications::dispatch( 'review', $booking );
        }

        if ( $new_status === 'cancelado' && $booking ) {
            global $wpdb;
            // Cancela receita e comissão relacionadas
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}barber_finance SET status='cancelado', updated_at=%s WHERE booking_id=%d",
                current_time('mysql'), $booking_id
            ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->prefix}barber_commissions SET status='cancelado' WHERE booking_id=%d",
                $booking_id
            ) );
            BarberPro_Notifications::dispatch( 'cancellation', $booking );
        }

        do_action( 'barberpro_booking_status_changed', $booking_id, $new_status, $booking );

        return [ 'success' => true, 'message' => 'Status atualizado.' ];
    }
}
