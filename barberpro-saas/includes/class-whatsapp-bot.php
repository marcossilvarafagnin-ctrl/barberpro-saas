<?php
/**
 * BarberPro – Bot de Agendamento via WhatsApp
 *
 * Recebe mensagens via webhook (Z-API, Cloud API ou W-API),
 * conduz o cliente por um fluxo de agendamento guiado com IA
 * e cria o agendamento automaticamente no sistema.
 *
 * Endpoint: POST /wp-json/barberpro/v1/whatsapp
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_WhatsApp_Bot {

    private static function cid_for_modulo( string $mod_key ): int {
        if ( $mod_key === 'lavacar' ) {
            return BarberPro_Modules::company_id( 'lavacar' );
        }
        return BarberPro_Modules::company_id( 'barbearia' );
    }

    // =========================================================
    // REGISTRO DO ENDPOINT REST
    // =========================================================

    public static function register_routes(): void {
        register_rest_route( 'barberpro/v1', '/whatsapp', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_webhook' ],
            'permission_callback' => [ __CLASS__, 'verify_token' ],
        ]);
    }

    public static function verify_token( WP_REST_Request $request ): bool {
        $token_salvo = BarberPro_Database::get_setting('bot_webhook_token', '');
        if ( empty($token_salvo) ) return true; // sem token = aceita tudo (setup inicial)
        $token = $request->get_header('X-Webhook-Token') ?: $request->get_param('token');
        return hash_equals( $token_salvo, (string) $token );
    }

    // =========================================================
    // PROCESSAMENTO DO WEBHOOK
    // =========================================================

    public static function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();

        // Debug log
        if ( BarberPro_Database::get_setting('bot_debug', '0') === '1' ) {
            BarberPro_Database::set_setting('bot_ultimo_payload',
                json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        $parsed = self::parse_payload( $body );
        if ( ! $parsed ) {
            return new WP_REST_Response(['ok' => true, 'skip' => 'payload ignorado'], 200);
        }

        if ( $parsed['from_me'] || $parsed['is_group'] ) {
            return new WP_REST_Response(['ok' => true, 'skip' => 'ignorado'], 200);
        }

        $telefone = $parsed['telefone'];
        $mensagem = $parsed['mensagem'];
        $nome     = $parsed['nome'] ?? '';

        // Verifica se bot está ativo — respeita ambos os toggles (aba Bot E aba Notificações)
        $bot_ativo    = BarberPro_Database::get_setting('bot_ativo',        '0') === '1';
        $notify_ativo = BarberPro_Database::get_setting('notify_bot_ativo', '0') === '1';
        if ( ! $bot_ativo || ! $notify_ativo ) {
            return new WP_REST_Response(['ok' => true, 'skip' => 'bot inativo'], 200);
        }

        // Processa fluxo do bot
        $resposta = self::processar( $telefone, $mensagem, $nome );

        if ( $resposta ) {
            BarberPro_WhatsApp::send( $telefone, $resposta );
            // Log da conversa
            self::log_mensagem( $telefone, $nome, $mensagem, $resposta );
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    // =========================================================
    // MOTOR DO FLUXO
    // =========================================================

    public static function processar( string $telefone, string $mensagem, string $nome = '' ): ?string {
        $estado = self::get_estado( $telefone );

        // Comando global: cancelar fluxo a qualquer momento
        $msg_lower = mb_strtolower( trim( $mensagem ) );
        if ( in_array( $msg_lower, ['cancelar', 'sair', 'parar', 'stop', 'menu'] ) ) {
            self::limpar_estado( $telefone );
            return "Ok! Se precisar de algo é só mandar mensagem 😊";
        }

        // Se já está num fluxo ativo, continua
        if ( $estado && isset($estado['etapa']) && $estado['etapa'] !== 'concluido' ) {
            return self::continuar_fluxo( $telefone, $mensagem, $estado, $nome );
        }

        // Verifica intenção de agendamento
        if ( self::tem_intencao_agendamento( $mensagem ) ) {
            $bot_mode = BarberPro_Database::get_setting('bot_mode','passo_a_passo');
            if ( $bot_mode === 'ia' && class_exists('BarberPro_OpenAI') && BarberPro_OpenAI::is_enabled() ) {
                // Modo IA: deixa a OpenAI conduzir a conversa usando o prompt configurado
                $ai_resp = BarberPro_OpenAI::chat($mensagem, ['nome' => $nome, 'telefone' => $telefone]);
                if ( $ai_resp ) return $ai_resp;
            }
            return self::iniciar_fluxo( $telefone, $mensagem, $nome );
        }

        // Verifica intenção de cancelar agendamento
        if ( self::tem_intencao_cancelamento( $mensagem ) ) {
            return self::iniciar_cancelamento( $telefone, $mensagem );
        }

        // Tenta resposta com IA (se configurado)
        if ( class_exists('BarberPro_OpenAI') && BarberPro_OpenAI::is_enabled()
             && BarberPro_Database::get_setting('openai_free_response','0') === '1' ) {
            $ai_resp = BarberPro_OpenAI::free_response($mensagem, ['nome' => $nome]);
            if ( $ai_resp ) return $ai_resp;
        }

        // Resposta padrão com menu
        return self::mensagem_menu( $nome );
    }

    // =========================================================
    // INICIAR FLUXO DE AGENDAMENTO
    // =========================================================

    private static function iniciar_fluxo( string $telefone, string $mensagem, string $nome ): string {
        $modulos = self::get_modulos_ativos();

        if ( count($modulos) === 1 ) {
            $modulo = array_key_first($modulos);
            $estado = self::estado_inicial( $modulo );
            self::set_estado( $telefone, $estado );
            return self::mensagem_escolher_servico( $modulo, $nome );
        }

        $estado = [ 'etapa' => 'escolher_modulo', 'modulo' => null,
                    'service_id' => null, 'pro_id' => null, 'data' => null, 'horario' => null, 'nome' => $nome ];
        self::set_estado( $telefone, $estado );
        return self::mensagem_escolher_modulo( $modulos, $nome );
    }

    // =========================================================
    // ETAPAS DO FLUXO
    // =========================================================

    private static function continuar_fluxo( string $telefone, string $mensagem, array $estado, string $nome ): string {
        switch ( $estado['etapa'] ) {
            case 'escolher_modulo':      return self::etapa_modulo( $telefone, $mensagem, $estado );
            case 'escolher_servico':     return self::etapa_servico( $telefone, $mensagem, $estado );
            case 'escolher_profissional':return self::etapa_profissional( $telefone, $mensagem, $estado );
            case 'escolher_data':        return self::etapa_data( $telefone, $mensagem, $estado );
            case 'escolher_horario':     return self::etapa_horario( $telefone, $mensagem, $estado );
            case 'escolher_momento_pagamento':
                return self::etapa_momento_pagamento( $telefone, $mensagem, $estado );
            case 'escolher_pagamento':   return self::etapa_escolher_pagamento( $telefone, $mensagem, $estado );
            case 'confirmar':            return self::etapa_confirmar( $telefone, $mensagem, $estado );
            case 'cancelar_codigo':      return self::etapa_cancelar_codigo( $telefone, $mensagem, $estado );
            default:
                self::limpar_estado( $telefone );
                return self::mensagem_menu( $nome );
        }
    }

    private static function etapa_modulo( string $tel, string $msg, array $e ): string {
        $modulos = self::get_modulos_ativos();
        $escolha = self::detectar_modulo( $msg, $modulos );
        if ( ! $escolha ) return self::mensagem_escolher_modulo( $modulos, '', true );

        $e['modulo'] = $escolha;
        $e['etapa']  = 'escolher_servico';
        self::set_estado( $tel, $e );
        return self::mensagem_escolher_servico( $escolha );
    }

    private static function etapa_servico( string $tel, string $msg, array $e ): string {
        $cid      = self::cid_for_modulo( (string) ( $e['modulo'] ?? 'barbearia' ) );
        $servicos = BarberPro_Database::get_services( $cid );
        $idx      = self::detectar_item( $msg, $servicos, 'name' );

        if ( $idx === null ) return self::mensagem_escolher_servico( $e['modulo'], '', true );

        $e['service_id'] = (int) $servicos[$idx]->id;
        $pros = BarberPro_Database::get_professionals( $cid );

        if ( count($pros) <= 1 ) {
            $e['pro_id'] = count($pros) === 1 ? (int)$pros[0]->id : 0;
            $e['etapa']  = 'escolher_data';
            self::set_estado( $tel, $e );
            return self::mensagem_escolher_data();
        }

        $e['etapa'] = 'escolher_profissional';
        self::set_estado( $tel, $e );
        return self::mensagem_escolher_profissional( $pros );
    }

    private static function etapa_profissional( string $tel, string $msg, array $e ): string {
        $cid  = self::cid_for_modulo( (string) ( $e['modulo'] ?? 'barbearia' ) );
        $pros = BarberPro_Database::get_professionals( $cid );
        $ml   = mb_strtolower( trim($msg) );

        if ( in_array( $ml, ['0', 'qualquer', 'tanto faz', 'indiferente', 'qualquer um'] ) ) {
            $e['pro_id'] = 0;
        } else {
            $idx = self::detectar_item( $msg, $pros, 'name' );
            if ( $idx === null ) return self::mensagem_escolher_profissional( $pros, true );
            $e['pro_id'] = (int) $pros[$idx]->id;
        }

        $e['etapa'] = 'escolher_data';
        self::set_estado( $tel, $e );
        return self::mensagem_escolher_data();
    }

    private static function etapa_data( string $tel, string $msg, array $e ): string {
        $data = self::parsear_data( $msg );

        if ( ! $data ) {
            return "Não entendi a data 😅\n\nPode dizer *hoje*, *amanhã*, um dia da semana (ex: *sexta*) ou uma data (ex: *28/03*)?";
        }
        if ( $data < current_time('Y-m-d') ) {
            return "Essa data já passou 📅 Pode escolher uma data a partir de hoje?";
        }

        $slots = self::buscar_slots( $e, $data );
        if ( empty($slots) ) {
            $dia = date_i18n('l, d/m', strtotime($data));
            return "Não temos horários disponíveis em *{$dia}* 😔\n\nQuer tentar outra data?";
        }

        $e['data']  = $data;
        $e['etapa'] = 'escolher_horario';
        self::set_estado( $tel, $e );
        return self::mensagem_slots( $data, $slots );
    }

    private static function etapa_horario( string $tel, string $msg, array $e ): string {
        $slots   = self::buscar_slots( $e, $e['data'] );
        $horario = self::parsear_horario( $msg, $slots );

        if ( ! $horario ) return self::mensagem_slots( $e['data'], $slots, true );

        $e['horario'] = $horario;
        $e['etapa']   = 'confirmar';
        self::set_estado( $tel, $e );
        return self::mensagem_confirmar( $e );
    }

    private static function etapa_confirmar( string $tel, string $msg, array $e ): string {
        $ml = mb_strtolower( trim($msg) );

        if ( self::eh_negativo($ml) ) {
            self::limpar_estado($tel);
            return BarberPro_Database::get_setting('bot_msg_cancelado', 'Agendamento cancelado 😊 Se quiser remarcar é só chamar!');
        }
        if ( str_contains($ml, 'mudar') || str_contains($ml, 'outra data') || str_contains($ml, 'outro horário') ) {
            $e['etapa'] = 'escolher_data'; $e['data'] = null; $e['horario'] = null;
            self::set_estado($tel, $e);
            return self::mensagem_escolher_data();
        }
        if ( ! self::eh_positivo($ml) ) {
            return "Confirma o agendamento? Responda *sim* para confirmar ou *não* para cancelar 😊";
        }

        // Igual ao widget: sem formas configuradas → confirma direto + notificação (sem etapa de pagamento)
        if ( function_exists( 'bp_has_any_payment_method_configured' ) && ! bp_has_any_payment_method_configured() ) {
            $metodos = function_exists( 'bp_get_payment_methods' ) ? bp_get_payment_methods() : [];
            $e['payment_method'] = array_key_first( $metodos ) ?: 'dinheiro';
            return self::finalizar_agendamento( $tel, $e );
        }

        $gateways   = class_exists( 'BarberPro_Payment' ) ? BarberPro_Payment::get_active_gateways() : [];
        $when       = BarberPro_Database::get_setting( 'online_payment_when', 'optional' );
        $has_online = ! empty( $gateways ) && $when !== 'disabled';

        $e['etapa']           = 'escolher_momento_pagamento';
        $e['pay_only_online'] = false;
        $e['pay_timing']      = '';
        self::set_estado( $tel, $e );

        if ( $has_online ) {
            return "Show! ✨ Só uma coisa sobre o *pagamento*: você prefere *pagar no salão no dia* do atendimento ou *garantir agora* pelo celular (PIX/cartão)?\n\nResponda *1* no local ou *2* agora pelo celular.";
        }

        return "Perfeito! O pagamento fica *no local*, no dia do atendimento, tudo bem? 😊\n\nResponda *sim* para confirmar.";
    }

    /**
     * Cliente escolhe pagar no dia ou agora (online) — espelha o widget de chat do site.
     */
    private static function etapa_momento_pagamento( string $tel, string $msg, array $e ): string {
        $gateways   = class_exists( 'BarberPro_Payment' ) ? BarberPro_Payment::get_active_gateways() : [];
        $when       = BarberPro_Database::get_setting( 'online_payment_when', 'optional' );
        $has_online = ! empty( $gateways ) && $when !== 'disabled';
        $ml         = mb_strtolower( trim( $msg ) );
        $metodos    = function_exists( 'bp_get_payment_methods' ) ? bp_get_payment_methods() : [ 'dinheiro' => '💵 Dinheiro' ];

        if ( ! $has_online ) {
            if ( self::eh_positivo( $ml ) || str_contains( $ml, 'local' ) || str_contains( $ml, 'dia' ) || str_contains( $ml, 'salão' ) || str_contains( $ml, 'salao' ) ) {
                $e['payment_method'] = array_key_first( $metodos ) ?: 'dinheiro';
                return self::finalizar_agendamento( $tel, $e );
            }
            return "Não entendi 😅 Confirma *pagamento no local* no dia? Responda *sim*.";
        }

        $quer_online = str_contains( $ml, 'celular' ) || str_contains( $ml, 'agora' ) || str_contains( $ml, 'online' )
            || str_contains( $ml, 'pix' ) || str_contains( $ml, 'cartão' ) || str_contains( $ml, 'cartao' )
            || preg_match( '/\b2\b/', $ml ) || str_contains( $ml, 'garantir' );
        $quer_local  = str_contains( $ml, 'local' ) || str_contains( $ml, 'salão' ) || str_contains( $ml, 'salao' )
            || str_contains( $ml, 'dia' ) || preg_match( '/\b1\b/', $ml ) || str_contains( $ml, 'atendimento' );

        if ( $quer_online && ! $quer_local ) {
            $e['pay_only_online'] = true;
            $e['etapa']           = 'escolher_pagamento';
            self::set_estado( $tel, $e );
            return self::mensagem_escolher_pagamento( $gateways, $when, $metodos );
        }

        if ( $quer_local || ! $quer_online ) {
            $e['payment_method'] = array_key_first( $metodos ) ?: 'dinheiro';
            return self::finalizar_agendamento( $tel, $e );
        }

        return "Não entendi 😅 Você prefere *pagar no salão no dia* (responda *1*) ou *agora pelo celular* (*2*)?";
    }

    private static function etapa_escolher_pagamento( string $tel, string $msg, array $e ): string {
        $gateways = class_exists('BarberPro_Payment') ? BarberPro_Payment::get_active_gateways() : [];
        $when     = BarberPro_Database::get_setting('online_payment_when','optional');
        $metodos  = function_exists('bp_get_payment_methods') ? bp_get_payment_methods() : ['presencial' => '💵 No atendimento'];
        $ml       = mb_strtolower(trim($msg));

        // Monta lista: só gateways se cliente já escolheu "pagar agora" (igual widget)
        $opcoes = [];
        if ( ! empty( $e['pay_only_online'] ) ) {
            if ( ! empty($gateways) && $when !== 'disabled' ) {
                foreach ( $gateways as $key => $label ) {
                    $opcoes[ $key ] = $label;
                }
            }
            if ( empty( $opcoes ) ) {
                $e['payment_method']  = array_key_first( $metodos ) ?: 'dinheiro';
                $e['pay_only_online']   = false;
                self::set_estado( $tel, $e );
                return self::finalizar_agendamento( $tel, $e );
            }
        } else {
            if ( ! empty($gateways) && $when !== 'disabled' ) {
                foreach ( $gateways as $key => $label ) $opcoes[$key] = $label;
            }
            if ( empty($gateways) || $when !== 'required' ) {
                foreach ( $metodos as $key => $label ) $opcoes[$key] = $label;
            }
        }
        $keys = array_keys($opcoes);

        // Detecta por número
        if ( preg_match('/^\s*(\d+)\s*$/', $msg, $m) ) {
            $idx = (int)$m[1] - 1;
            if ( isset($keys[$idx]) ) {
                $e['payment_method'] = $keys[$idx];
                self::set_estado($tel, $e);
                return self::finalizar_agendamento($tel, $e);
            }
        }
        // Detecta por nome/texto
        foreach ( $opcoes as $key => $label ) {
            if ( str_contains($ml, mb_strtolower($key)) || str_contains($ml, mb_strtolower($label)) ) {
                $e['payment_method'] = $key;
                self::set_estado($tel, $e);
                return self::finalizar_agendamento($tel, $e);
            }
        }

        return self::mensagem_escolher_pagamento($gateways, $when, $metodos, true);
    }

    private static function finalizar_agendamento( string $tel, array $e ): string {
        $nome   = $e['nome'] ?? 'Cliente WhatsApp';
        $result = self::criar_agendamento( $e, $nome, $tel );
        self::limpar_estado($tel);

        if ( ! $result['success'] ) {
            return "Ops! Esse horário acabou de ser reservado 😅\n\n" . ($result['message'] ?? '') . "\n\nQuer tentar outra data?";
        }

        $msg = self::mensagem_sucesso( $result, $e );

        // Gera link de pagamento se escolheu pagar agora
        if ( ! empty($e['payment_method']) && $e['payment_method'] !== 'presencial' && class_exists('BarberPro_Payment') ) {
            $booking = BarberPro_Database::get_booking((int)($result['booking_id'] ?? 0));
            if ( $booking ) {
                $charge = BarberPro_Payment::create_charge($booking, $e['payment_method']);
                if ( $charge['success'] ) {
                    if ( ! empty($charge['checkout_url']) ) {
                        $msg .= "\n\n💳 *Link de pagamento:*\n" . $charge['checkout_url'];
                    } elseif ( ! empty($charge['pix_payload']) ) {
                        $msg .= "\n\n⚡ *Chave PIX (copia e cola):*\n" . $charge['pix_payload'];
                    }
                }
            }
        }

        return $msg;
    }

    // =========================================================
    // CANCELAMENTO DE AGENDAMENTO
    // =========================================================

    private static function iniciar_cancelamento( string $tel, string $msg ): string {
        // Tenta extrair código direto da mensagem
        if ( preg_match('/BP-[\w\-]+/i', $msg, $m) ) {
            return self::cancelar_por_codigo( $tel, strtoupper($m[0]) );
        }
        $e = [ 'etapa' => 'cancelar_codigo' ];
        self::set_estado( $tel, $e );
        return "Para cancelar, me informe o código do seu agendamento 🔍\n\n_(começa com BP-, ex: BP-2024-001)_";
    }

    private static function etapa_cancelar_codigo( string $tel, string $msg, array $e ): string {
        if ( preg_match('/BP-[\w\-]+/i', $msg, $m) ) {
            self::limpar_estado($tel);
            return self::cancelar_por_codigo( $tel, strtoupper($m[0]) );
        }
        self::limpar_estado($tel);
        return "Código não encontrado 😕 O código começa com *BP-*. Se precisar de ajuda entre em contato pelo balcão.";
    }

    private static function cancelar_por_codigo( string $tel, string $codigo ): string {
        global $wpdb;
        $booking = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}barber_bookings WHERE booking_code = %s LIMIT 1",
            $codigo
        ));

        if ( ! $booking ) return "Agendamento *{$codigo}* não encontrado 😕";

        // Verifica se pertence ao telefone (segurança)
        $tel_limpo    = preg_replace('/\D/', '', $tel);
        $booking_tel  = preg_replace('/\D/', '', $booking->client_phone);
        if ( substr($tel_limpo, -8) !== substr($booking_tel, -8) ) {
            return "Não encontrei um agendamento com esse código associado ao seu número 😕";
        }

        if ( ! in_array($booking->status, ['agendado', 'confirmado']) ) {
            $label = $booking->status === 'cancelado' ? 'já estava cancelado' : "tem status *{$booking->status}*";
            return "O agendamento *{$codigo}* {$label} e não pode ser cancelado por aqui.";
        }

        // Verifica prazo de cancelamento
        $horas_limite = (int) BarberPro_Database::get_setting('cancellation_hours', 2);
        $booking_ts   = strtotime($booking->booking_date . ' ' . $booking->booking_time);
        $horas_faltam = ($booking_ts - time()) / 3600;

        if ( $horas_faltam < $horas_limite ) {
            return "Esse agendamento é em menos de {$horas_limite}h e não pode mais ser cancelado por aqui 😕\n\nEntre em contato diretamente com a gente.";
        }

        $wpdb->update("{$wpdb->prefix}barber_bookings",
            ['status' => 'cancelado', 'updated_at' => current_time('mysql')],
            ['id' => $booking->id]
        );

        $dia = date_i18n('d/m/Y', strtotime($booking->booking_date));
        $h   = substr($booking->booking_time, 0, 5);
        return "✅ Agendamento *{$codigo}* cancelado com sucesso!\n\n_{$dia} às {$h}_\n\nSe quiser remarcar é só chamar 😊";
    }

    // =========================================================
    // CRIAR AGENDAMENTO
    // =========================================================

    private static function criar_agendamento( array $e, string $nome, string $tel ): array {
        $cid    = self::cid_for_modulo( (string) ( $e['modulo'] ?? 'barbearia' ) );
        $pro_id = (int) $e['pro_id'];

        // Se "qualquer profissional", acha um disponível
        if ( $pro_id === 0 ) {
            $svc  = BarberPro_Database::get_service( (int) $e['service_id'] );
            $dur  = (int) ( $svc->duration_minutes ?? $svc->duration ?? 30 );
            $want = BarberPro_Database::normalize_booking_time_key( (string) ( $e['horario'] ?? '' ) );
            foreach ( BarberPro_Database::get_professionals( $cid ) as $p ) {
                $slots = BarberPro_Bookings::get_available_slots( (int) $p->id, $e['data'], $dur, false );
                foreach ( $slots as $s ) {
                    if ( BarberPro_Database::normalize_booking_time_key( $s ) === $want ) {
                        $pro_id = (int) $p->id;
                        break 2;
                    }
                }
            }
            if ( ! $pro_id ) {
                return [ 'success' => false, 'message' => 'Nenhum profissional disponível neste horário.' ];
            }
        }

        return BarberPro_Bookings::create_booking([
            'company_id'      => $cid,
            'service_id'      => (int) $e['service_id'],
            'professional_id' => $pro_id,
            'client_name'     => sanitize_text_field($nome),
            'client_phone'    => sanitize_text_field($tel),
            'client_email'    => sanitize_email( $e['email'] ?? '' ),
            'booking_date'    => $e['data'],
            'booking_time'    => $e['horario'],
            'notes'           => 'Agendado via WhatsApp (Bot)',
            'status'          => 'agendado',
            'payment_method'  => 'presencial',
            'admin_mode'      => false,
        ]);
    }

    // =========================================================
    // BUSCAR SLOTS
    // =========================================================

    private static function buscar_slots( array $e, string $data ): array {
        $cid    = self::cid_for_modulo( (string) ( $e['modulo'] ?? 'barbearia' ) );
        $pro_id = (int) $e['pro_id'];
        $svc    = BarberPro_Database::get_service( (int)$e['service_id'] );
        $dur    = (int)($svc->duration_minutes ?? $svc->duration ?? 30);

        if ( $pro_id === 0 ) {
            $merged = [];
            foreach ( BarberPro_Database::get_professionals($cid) as $p ) {
                foreach ( BarberPro_Bookings::get_available_slots((int)$p->id, $data, $dur, false) as $s )
                    $merged[$s] = true;
            }
            ksort($merged);
            return array_keys($merged);
        }

        return BarberPro_Bookings::get_available_slots($pro_id, $data, $dur, false);
    }

    // =========================================================
    // MENSAGENS
    // =========================================================

    private static function mensagem_menu( string $nome = '' ): string {
        $template = BarberPro_Database::get_setting('bot_msg_menu',
            "Olá{nome}! 👋 Como posso te ajudar?\n\n1️⃣ Fazer um agendamento\n2️⃣ Cancelar agendamento\n\nResponda o número ou descreva o que precisa 😊"
        );
        $saudacao = $nome ? ", *{$nome}*" : '';
        return str_replace('{nome}', $saudacao, $template);
    }

    private static function mensagem_escolher_modulo( array $modulos, string $nome = '', bool $repete = false ): string {
        $msg = $repete ? "Não entendi 😅 Escolha:\n\n" : ( $nome ? "Oi *{$nome}*! Vou te ajudar a agendar 😊\n\nQual seria?\n\n" : "Vou te ajudar a agendar 😊\n\nQual seria?\n\n" );
        $i   = 1;
        foreach ( $modulos as $key => $label ) { $msg .= "{$i}️⃣ {$label}\n"; $i++; }
        return $msg . "\nResponda com o número.";
    }

    private static function mensagem_escolher_servico( string $modulo, string $nome = '', bool $repete = false ): string {
        $cid      = self::cid_for_modulo( (string) ( $modulo ?? 'barbearia' ) );
        $servicos = BarberPro_Database::get_services($cid);
        $saudacao = $nome && ! $repete ? "Oi *{$nome}*! " : '';
        $msg      = $repete ? "Não reconheci. Escolha pelo número:\n\n" : "{$saudacao}Qual serviço você quer?\n\n";
        foreach ( $servicos as $i => $s ) {
            $preco = 'R$ ' . number_format((float)$s->price, 2, ',', '.');
            $dur   = (int)($s->duration_minutes ?? $s->duration ?? 30);
            $msg  .= ($i+1) . "️⃣ *{$s->name}* — {$preco} ({$dur} min)\n";
        }
        return $msg . "\nResponda com o número ou nome do serviço.";
    }

    private static function mensagem_escolher_profissional( array $pros, bool $repete = false ): string {
        $msg = $repete ? "Não entendi. Com qual profissional prefere?\n\n" : "Com qual profissional prefere?\n\n";
        foreach ( $pros as $i => $p ) $msg .= ($i+1) . "️⃣ {$p->name}\n";
        return $msg . "0️⃣ Qualquer disponível\n\nResponda com o número ou nome.";
    }

    private static function mensagem_escolher_data(): string {
        $hoje   = date_i18n('d/m', strtotime(current_time('Y-m-d')));
        $amanha = date_i18n('d/m', strtotime('+1 day', strtotime(current_time('Y-m-d'))));
        $template = BarberPro_Database::get_setting('bot_msg_data',
            "Qual data prefere? 📅\n\n• *hoje* ({hoje})\n• *amanhã* ({amanha})\n• Dia da semana (ex: *sexta*)\n• Ou uma data (ex: *28/03*)"
        );
        return str_replace(['{hoje}','{amanha}'], [$hoje,$amanha], $template);
    }

    private static function mensagem_slots( string $data, array $slots, bool $repete = false ): string {
        $dia = date_i18n('l, d \d\e F', strtotime($data));
        $msg = $repete ? "Horário não encontrado. Disponíveis em *{$dia}*:\n\n" : "✅ Horários disponíveis em *{$dia}*:\n\n";
        foreach ( $slots as $i => $s ) {
            [$hh, $mm] = explode(':', substr($s, 0, 5));
            $label = $mm === '00' ? "{$hh}h" : "{$hh}h{$mm}";
            $msg  .= ($i+1) . ". *{$label}*\n";
        }
        return $msg . "\nResponda com o *número* do horário desejado 😊";
    }

    private static function mensagem_confirmar( array $e ): string {
        $svc  = BarberPro_Database::get_service((int)$e['service_id']);
        $pro  = $e['pro_id'] ? BarberPro_Database::get_professional((int)$e['pro_id']) : null;
        $dia  = date_i18n('l, d/m/Y', strtotime($e['data']));
        [$hh, $mm] = explode(':', substr($e['horario'], 0, 5));
        $h    = $mm === '00' ? "{$hh}h" : "{$hh}h{$mm}";
        $mod  = $e['modulo'] === 'barbearia' ? '✂️ Barbearia' : '🚗 Lava-Car';

        return "📋 *Confirme seu agendamento:*\n\n"
             . "📍 {$mod}\n"
             . "✂️ *{$svc->name}*\n"
             . ( $pro ? "👤 {$pro->name}\n" : "" )
             . "📅 {$dia}\n"
             . "⏰ {$h}\n"
             . "💰 R$ " . number_format((float)$svc->price, 2, ',', '.') . "\n\n"
             . "Confirma? Responda *sim* ou *não* 😊";
    }

    private static function mensagem_sucesso( array $result, array $e ): string {
        $code = $result['booking_code'] ?? '#' . ($result['booking_id'] ?? '');
        $dia  = date_i18n('l, d/m/Y', strtotime($e['data']));
        [$hh, $mm] = explode(':', substr($e['horario'], 0, 5));
        $h    = $mm === '00' ? "{$hh}h" : "{$hh}h{$mm}";

        $template = BarberPro_Database::get_setting('bot_msg_sucesso',
            "✅ *Agendamento confirmado!*\n\n📋 Código: *{codigo}*\n📅 {data} às *{hora}*\n\nTe esperamos! 😊\n_Para cancelar: cancelar {codigo}_"
        );
        $msg = str_replace(['{codigo}','{data}','{hora}'], [$code,$dia,$h], $template);

        // Localização / estacionamento (configurável)
        $local = BarberPro_Database::get_setting('bot_msg_localizacao','');
        if ( $local ) {
            $msg .= "\n\n📍 *Como chegar / Estacionamento:*\n" . $local;
        }

        return $msg;
    }

    private static function mensagem_escolher_pagamento( array $gateways, string $when, array $metodos = [], bool $repete = false ): string {
        $msg = $repete ? "Não entendi 😅 Como prefere pagar?\n\n" : "💳 *Como prefere pagar?*\n\n";
        $i   = 1;
        // Opções de pagamento online (gateways)
        if ( ! empty($gateways) && $when !== 'disabled' ) {
            foreach ( $gateways as $key => $label ) {
                $msg .= "{$i}. {$label}\n";
                $i++;
            }
        }
        // Opções de pagamento presencial configuradas
        if ( empty($gateways) || $when !== 'required' ) {
            if ( empty($metodos) ) $metodos = function_exists('bp_get_payment_methods') ? bp_get_payment_methods() : ['presencial' => '💵 No atendimento'];
            foreach ( $metodos as $key => $label ) {
                $msg .= "{$i}. {$label}\n";
                $i++;
            }
        }
        return $msg . "\nResponda com o número da opção 😊";
    }

    // =========================================================
    // PARSERS E HELPERS
    // =========================================================

    private static function parsear_data( string $msg ): ?string {
        $msg   = mb_strtolower(trim($msg));
        $today = current_time('Y-m-d');

        if ( in_array($msg, ['hoje','hj']) ) return $today;
        if ( in_array($msg, ['amanhã','amanha','amh']) )
            return date('Y-m-d', strtotime('+1 day', strtotime($today)));

        $dias = ['domingo'=>0,'segunda'=>1,'segunda-feira'=>1,'terça'=>2,'terca'=>2,
                 'terça-feira'=>2,'quarta'=>3,'quarta-feira'=>3,'quinta'=>4,'quinta-feira'=>4,
                 'sexta'=>5,'sexta-feira'=>5,'sábado'=>6,'sabado'=>6];
        foreach ( $dias as $nome => $dow ) {
            if ( str_contains($msg, $nome) ) {
                $diff = ($dow - (int)date('w', strtotime($today)) + 7) % 7 ?: 7;
                return date('Y-m-d', strtotime("+{$diff} days", strtotime($today)));
            }
        }

        if ( preg_match('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/', $msg, $m) ) {
            $d = str_pad($m[1],2,'0',STR_PAD_LEFT);
            $mo = str_pad($m[2],2,'0',STR_PAD_LEFT);
            $y  = isset($m[3]) ? (strlen($m[3])===2 ? '20'.$m[3] : $m[3]) : date('Y', strtotime($today));
            if ( checkdate((int)$mo,(int)$d,(int)$y) ) return "{$y}-{$mo}-{$d}";
        }

        return null;
    }

    private static function parsear_horario( string $msg, array $slots ): ?string {
        if ( preg_match('/(\d{1,2})[h:](\d{0,2})/', mb_strtolower($msg), $m) ) {
            $hh = str_pad($m[1],2,'0',STR_PAD_LEFT);
            $mm = str_pad($m[2]?:'00',2,'0',STR_PAD_LEFT);
            foreach ( $slots as $s ) {
                if ( strpos($s, "{$hh}:{$mm}") === 0 ) return $s;
            }
        }
        // Por número (ex: "1" = primeiro slot)
        if ( preg_match('/^\s*(\d+)\s*$/', $msg, $m) ) {
            $n = (int)$m[1];
            if ( $n >= 1 && $n <= count($slots) ) return $slots[$n-1];
        }
        return null;
    }

    private static function detectar_item( string $msg, array $items, string $campo ): ?int {
        $ml = mb_strtolower(trim($msg));
        // Por número
        if ( preg_match('/^\s*(\d+)\s*$/', $msg, $m) ) {
            $n = (int)$m[1]-1;
            return isset($items[$n]) ? $n : null;
        }
        // Por nome
        foreach ( $items as $i => $item ) {
            if ( str_contains($ml, mb_strtolower($item->$campo)) ) return $i;
        }
        // Número dentro de texto
        if ( preg_match('/\b(\d+)\b/', $msg, $m) ) {
            $n = (int)$m[1]-1;
            return isset($items[$n]) ? $n : null;
        }
        return null;
    }

    private static function detectar_modulo( string $msg, array $modulos ): ?string {
        $ml   = mb_strtolower($msg);
        $keys = array_keys($modulos);
        foreach ( $keys as $k ) {
            if ( str_contains($ml, $k) || str_contains($ml, mb_strtolower($modulos[$k])) ) return $k;
        }
        if ( preg_match('/\b(\d+)\b/', $msg, $m) ) {
            $n = (int)$m[1]-1;
            return isset($keys[$n]) ? $keys[$n] : null;
        }
        return null;
    }

    private static function tem_intencao_agendamento( string $msg ): bool {
        $msg = mb_strtolower($msg);
        $palavras = ['agendar','agendamento','marcar','horário','horario','quero cortar',
                     'quero lavar','corte','barba','lavagem','carro','disponível','disponivel',
                     'vaga','quando','que horas','tem vaga','tem horário','tem horario',
                     'marcar horário','fazer um corte','lavar o carro','fazer a barba','1'];
        foreach ($palavras as $p) { if ( str_contains($msg, $p) ) return true; }
        return false;
    }

    private static function tem_intencao_cancelamento( string $msg ): bool {
        $msg = mb_strtolower($msg);
        return str_contains($msg,'cancelar') || str_contains($msg,'desmarcar')
            || str_contains($msg,'cancela') || (str_contains($msg,'2') && strlen(trim($msg)) === 1);
    }

    private static function eh_positivo( string $msg ): bool {
        foreach (['sim','s','ok','pode','isso','confirmo','certo','tá','ta','bom','ótimo','otimo','1','yes','👍'] as $p)
            if ( str_contains($msg,$p) ) return true;
        return false;
    }

    private static function eh_negativo( string $msg ): bool {
        // Palavras longas — str_contains seguro
        foreach (['não','nao','cancelar','voltar','desistir'] as $p) {
            if ( str_contains($msg, $p) ) return true;
        }
        // Tokens curtos: \b evita casar dentro de outras palavras (ex: 'n' em "confirmar")
        if ( preg_match('/\bn\b/u',  $msg) ) return true;
        if ( preg_match('/\b0\b/',   $msg) ) return true;
        if ( preg_match('/\bno\b/u', $msg) ) return true;
        return false;
    }

    private static function get_modulos_ativos(): array {
        $m = [];
        if ( BarberPro_Database::get_setting('module_barbearia_active','1') === '1' )
            $m['barbearia'] = '✂️ ' . BarberPro_Database::get_setting('module_barbearia_name','Barbearia');
        if ( BarberPro_Database::get_setting('module_lavacar_active','0') === '1' )
            $m['lavacar']   = '🚗 ' . BarberPro_Database::get_setting('module_lavacar_name','Lava-Car');
        return $m ?: ['barbearia' => '✂️ Barbearia'];
    }

    // =========================================================
    // ESTADO (transient por telefone, expira em 30 min)
    // =========================================================

    private static function get_estado( string $tel ): ?array {
        $v = get_transient('bp_bot_' . md5($tel));
        return is_array($v) ? $v : null;
    }

    private static function set_estado( string $tel, array $e ): void {
        set_transient('bp_bot_' . md5($tel), $e, 30 * MINUTE_IN_SECONDS);
    }

    private static function limpar_estado( string $tel ): void {
        delete_transient('bp_bot_' . md5($tel));
    }

    // =========================================================
    // PARSE DO PAYLOAD (Z-API, W-API, Cloud API)
    // =========================================================

    private static function parse_payload( $body ): ?array {
        if ( empty($body) ) return null;
        $telefone = null; $mensagem = null; $from_me = false; $is_group = false; $nome = '';

        // ── Z-API ──────────────────────────────────────────────
        if ( isset($body['phone']) && isset($body['text']['message']) ) {
            $telefone = preg_replace('/\D/', '', $body['phone']);
            $mensagem = $body['text']['message'];
            $from_me  = $body['isFromMe'] ?? false;
            $is_group = str_contains($body['phone'] ?? '', '@g.us');
            $nome     = $body['senderName'] ?? '';
        }
        // ── W-API (webhookReceived) ─────────────────────────────
        elseif ( isset($body['event']) && $body['event'] === 'webhookReceived' ) {
            $from_me  = $body['fromMe'] ?? false;
            $is_group = $body['isGroup'] ?? false;
            $mc       = $body['msgContent'] ?? [];
            if ( isset($mc['protocolMessage']) || isset($mc['reactionMessage']) ) return null;
            $telefone = preg_replace('/\D/', '', $body['sender']['id'] ?? $body['chat']['id'] ?? '');
            $nome     = $body['sender']['pushName'] ?? '';
            $mensagem = $mc['conversation'] ?? $mc['extendedTextMessage']['text'] ?? '';
            if ( str_contains($body['sender']['id'] ?? '', '@g.us') ) $is_group = true;
        }
        // ── Cloud API (Meta) ────────────────────────────────────
        elseif ( isset($body['entry'][0]['changes'][0]['value']['messages'][0]) ) {
            $msg      = $body['entry'][0]['changes'][0]['value']['messages'][0];
            $from_me  = false;
            $is_group = false;
            $telefone = $msg['from'] ?? '';
            $mensagem = $msg['text']['body'] ?? '';
            $contatos = $body['entry'][0]['changes'][0]['value']['contacts'][0] ?? [];
            $nome     = $contatos['profile']['name'] ?? '';
        }

        if ( ! $telefone || ! $mensagem ) return null;
        $telefone = explode('@', $telefone)[0];

        return compact('telefone','mensagem','from_me','is_group','nome');
    }

    // =========================================================
    // LOG SIMPLES DE CONVERSA
    // =========================================================

    private static function log_mensagem( string $tel, string $nome, string $recebida, string $enviada ): void {
        $log_key = 'bp_bot_log';
        $log     = get_transient($log_key) ?: [];
        $log[]   = [
            'tel'      => $tel,
            'nome'     => $nome,
            'recebida' => $recebida,
            'enviada'  => $enviada,
            'hora'     => current_time('mysql'),
        ];
        if ( count($log) > 500 ) $log = array_slice($log, -500);
        set_transient($log_key, $log, 7 * DAY_IN_SECONDS);
    }

    public static function get_log(): array {
        return array_reverse(get_transient('bp_bot_log') ?: []);
    }

    private static function estado_inicial( string $modulo ): array {
        return ['etapa'=>'escolher_servico','modulo'=>$modulo,
                'service_id'=>null,'pro_id'=>null,'data'=>null,'horario'=>null,'nome'=>'',
                'payment_method'=>'presencial'];
    }
}
