<?php
/**
 * BarberPro – Widget de Chat para Agendamento no Site
 *
 * Exibe um chat flutuante em todas as páginas públicas.
 * A IA conduz o cliente pelo agendamento (nome → celular → email →
 * serviço → profissional → data → horário → confirmação).
 * Após confirmado, notifica o dono e o cliente por e-mail e WhatsApp.
 *
 * Ativado/desativado em Configurações → Widget Chat.
 *
 * @package BarberProSaaS
 */

if ( ! defined('ABSPATH') ) exit;

class BarberPro_Widget_Chat {

    // ── Registro de hooks ────────────────────────────────────────
    public static function init(): void {
        if ( BarberPro_Database::get_setting('widget_chat_ativo','0') !== '1' ) return;

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        add_action( 'wp_footer',          [ __CLASS__, 'render_html' ] );
        add_action( 'wp_ajax_nopriv_bp_widget_chat', [ __CLASS__, 'handle_ajax' ] );
        add_action( 'wp_ajax_bp_widget_chat',        [ __CLASS__, 'handle_ajax' ] );
    }

    // ── Assets ───────────────────────────────────────────────────
    public static function enqueue(): void {
        // Só carrega em páginas públicas (não admin)
        if ( is_admin() ) return;

        wp_enqueue_style(
            'bp-widget-chat',
            BARBERPRO_PLUGIN_URL . 'assets/css/widget-chat.css',
            [],
            BARBERPRO_VERSION
        );
        wp_enqueue_script(
            'bp-widget-chat',
            BARBERPRO_PLUGIN_URL . 'assets/js/widget-chat.js',
            [],
            BARBERPRO_VERSION,
            true
        );
        wp_localize_script( 'bp-widget-chat', 'bpWidgetData', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('bp_widget_chat'),
            'cor'        => BarberPro_Database::get_setting('widget_chat_cor','#f5a623'),
            'nome_bot'   => BarberPro_Database::get_setting('widget_chat_nome_bot','Assistente'),
            'avatar'     => BarberPro_Database::get_setting('widget_chat_avatar',''),
            'saudacao'   => BarberPro_Database::get_setting('widget_chat_saudacao',
                            'Olá! 👋 Posso te ajudar a agendar um horário agora mesmo.'),
            'negocio'    => BarberPro_Database::get_setting('business_name', get_bloginfo('name')),
        ]);
    }

    // ── HTML do widget ───────────────────────────────────────────
    public static function render_html(): void {
        $cor       = BarberPro_Database::get_setting('widget_chat_cor','#f5a623');
        $nome_bot  = esc_html( BarberPro_Database::get_setting('widget_chat_nome_bot','Assistente') );
        $avatar    = BarberPro_Database::get_setting('widget_chat_avatar','');
        $saudacao  = esc_html( BarberPro_Database::get_setting('widget_chat_saudacao',
                     'Olá! 👋 Posso te ajudar a agendar um horário agora mesmo.') );
        $negocio   = esc_html( BarberPro_Database::get_setting('business_name', get_bloginfo('name')) );
        $posicao   = BarberPro_Database::get_setting('widget_chat_posicao','right');

        $avatar_html = $avatar
            ? "<img src=\"{$avatar}\" alt=\"{$nome_bot}\" style=\"width:100%;height:100%;object-fit:cover;border-radius:50%\">"
            : "<span class=\"bp-wc-bot-initial\">" . mb_substr($nome_bot,0,1) . "</span>";
        ?>
        <div id="bpWidgetChat"
             class="bp-wc-wrap bp-wc-<?php echo esc_attr($posicao); ?>"
             data-cor="<?php echo esc_attr($cor); ?>"
             style="--bp-wc-cor:<?php echo esc_attr($cor); ?>">

            <!-- Botão flutuante -->
            <button id="bpWcToggle" class="bp-wc-toggle" aria-label="Abrir chat de agendamento">
                <span class="bp-wc-toggle-open">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                </span>
                <span class="bp-wc-toggle-close" style="display:none">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </span>
                <span class="bp-wc-badge" id="bpWcBadge" style="display:none">1</span>
            </button>

            <!-- Janela do chat -->
            <div id="bpWcWindow" class="bp-wc-window" style="display:none">
                <!-- Header -->
                <div class="bp-wc-header">
                    <div class="bp-wc-avatar"><?php echo $avatar_html; ?></div>
                    <div class="bp-wc-header-info">
                        <div class="bp-wc-bot-name"><?php echo $nome_bot; ?></div>
                        <div class="bp-wc-bot-status">
                            <span class="bp-wc-dot"></span> <?php echo $negocio; ?>
                        </div>
                    </div>
                    <button class="bp-wc-header-close" onclick="bpWcClose()" aria-label="Fechar">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>

                <!-- Mensagens -->
                <div id="bpWcMessages" class="bp-wc-messages">
                    <div class="bp-wc-msg bp-wc-msg-bot bp-wc-msg-in">
                        <div class="bp-wc-msg-bubble"><?php echo $saudacao; ?></div>
                        <div class="bp-wc-msg-time"><?php echo date_i18n('H:i'); ?></div>
                    </div>
                </div>

                <!-- Quick replies -->
                <div id="bpWcQuickReplies" class="bp-wc-quick-replies"></div>

                <!-- Input -->
                <div class="bp-wc-input-wrap">
                    <input type="text" id="bpWcInput" class="bp-wc-input"
                           placeholder="Digite sua mensagem..."
                           autocomplete="off" maxlength="200">
                    <button id="bpWcSend" class="bp-wc-send" onclick="bpWcSend()" aria-label="Enviar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>

                <!-- Powered by -->
                <div class="bp-wc-powered">
                    Agendamento automático · BarberPro
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX handler ─────────────────────────────────────────────
    public static function handle_ajax(): void {
        check_ajax_referer('bp_widget_chat', 'nonce');

        $action  = sanitize_key( $_POST['chat_action'] ?? '' );
        $session = sanitize_text_field( $_POST['session_id'] ?? '' );

        switch ( $action ) {
            case 'start':
                self::ajax_start( $session );
                break;
            case 'message':
                $msg = sanitize_text_field( $_POST['message'] ?? '' );
                self::ajax_message( $session, $msg );
                break;
            case 'get_slots':
                $svc_id = absint( $_POST['service_id'] ?? 0 );
                $pro_id = absint( $_POST['pro_id'] ?? 0 );
                $date   = sanitize_text_field( $_POST['date'] ?? '' );
                self::ajax_get_slots( $svc_id, $pro_id, $date );
                break;
            default:
                wp_send_json_error(['message' => 'Ação inválida.']);
        }
    }

    // ── Start da conversa ────────────────────────────────────────
    private static function ajax_start( string $session ): void {
        $estado = self::get_estado($session);

        // Já tem estado ativo — restaura
        if ( $estado && ($estado['etapa'] ?? '') !== 'concluido' ) {
            // Verifica se mudou de modo desde que a sessão foi criada
            $bot_mode = BarberPro_Database::get_setting('bot_mode','passo_a_passo');
            $use_ia   = ( $bot_mode === 'ia'
                          && class_exists('BarberPro_OpenAI')
                          && BarberPro_OpenAI::is_enabled() );
            // Se estava em modo passo-a-passo mas agora é IA (ou vice-versa), reinicia
            $etapa_atual = $estado['etapa'] ?? '';
            $era_ia = ( $etapa_atual === 'ia_livre' );
            if ( $use_ia !== $era_ia ) {
                // Modo mudou — deixa cair para reiniciar abaixo
            } else {
                wp_send_json_success([
                    'etapa'    => $etapa_atual,
                    'context'  => $estado,
                    'restored' => true,
                ]);
                return;
            }
        }

        $modulos  = self::get_modulos();
        $bot_mode = BarberPro_Database::get_setting('bot_mode','passo_a_passo');
        $use_ia   = ( $bot_mode === 'ia'
                      && class_exists('BarberPro_OpenAI')
                      && BarberPro_OpenAI::is_enabled() );

        $estado = [
            'etapa'      => $use_ia ? 'ia_livre' : 'coletar_nome',
            'nome'       => '',
            'celular'    => '',
            'email'      => '',
            'modulo'     => count($modulos) === 1 ? array_key_first($modulos) : '',
            'service_id' => 0,
            'pro_id'     => 0,
            'data'       => '',
            'horario'    => '',
            'ia_history' => [],  // histórico multi-turn para IA
        ];
        self::set_estado($session, $estado);

        if ( $use_ia ) {
            // Saudação inicial gerada pela IA
            $saudacao = BarberPro_Database::get_setting('widget_chat_saudacao',
                        'Olá! 👋 Posso te ajudar a agendar um horário agora mesmo.');
            wp_send_json_success([
                'etapa'         => 'ia_livre',
                'message'       => $saudacao,
                'quick_replies' => ['Quero agendar', 'Dúvidas', 'Preços'],
            ]);
        } else {
            wp_send_json_success([
                'etapa'         => 'coletar_nome',
                'message'       => BarberPro_Database::get_setting('wc_msg_pedir_nome','Para começar, qual é o seu nome?'),
                'quick_replies' => [],
            ]);
        }
    }

    // ── Processar mensagem ───────────────────────────────────────
    private static function ajax_message( string $session, string $msg ): void {
        if ( empty($msg) ) wp_send_json_error(['message' => 'Mensagem vazia.']);

        $estado = self::get_estado($session);
        if ( ! $estado ) {
            // Sessão expirada — reinicia
            self::ajax_start($session);
            return;
        }

        $etapa = $estado['etapa'] ?? 'coletar_nome';

        switch ($etapa) {
            case 'ia_livre':
                $resposta = self::etapa_ia_livre($session, $msg, $estado);
                break;
            case 'coletar_nome':
                $resposta = self::etapa_nome($session, $msg, $estado);
                break;
            case 'coletar_celular':
                $resposta = self::etapa_celular($session, $msg, $estado);
                break;
            case 'coletar_email':
                $resposta = self::etapa_email($session, $msg, $estado);
                break;
            case 'escolher_modulo':
                $resposta = self::etapa_modulo($session, $msg, $estado);
                break;
            case 'escolher_servico':
                $resposta = self::etapa_servico($session, $msg, $estado);
                break;
            case 'escolher_profissional':
                $resposta = self::etapa_profissional($session, $msg, $estado);
                break;
            case 'escolher_data':
                $resposta = self::etapa_data($session, $msg, $estado);
                break;
            case 'escolher_horario':
                $resposta = self::etapa_horario($session, $msg, $estado);
                break;
            case 'escolher_momento_pagamento':
                $resposta = self::etapa_momento_pagamento_widget($session, $msg, $estado);
                break;
            case 'escolher_pagamento':
                $resposta = self::etapa_escolher_pagamento_widget($session, $msg, $estado);
                break;
            case 'confirmar':
                $resposta = self::etapa_confirmar($session, $msg, $estado);
                break;
            default:
                self::limpar_estado($session);
                $resposta = ['message' => 'Para começar um novo agendamento, basta me dizer seu nome 😊', 'etapa' => 'coletar_nome'];
                $estado['etapa'] = 'coletar_nome';
                self::set_estado($session, $estado);
        }

        wp_send_json_success($resposta);
    }

    // ── Etapas ───────────────────────────────────────────────────

    // ── Modo IA livre (chat com histórico multi-turn) ────────────
    private static function etapa_ia_livre( string $session, string $msg, array $e ): array {
        $history = is_array($e['ia_history'] ?? null) ? $e['ia_history'] : [];

        // Data na mensagem → horários reais + slot_buttons (JSON para o widget)
        $slot_merge = null;
        $e_try      = $e;
        $data_parse = self::parsear_data($msg);
        if ( $data_parse && $data_parse >= current_time('Y-m-d') ) {
            $mods    = self::get_modulos();
            $mod_key = $e_try['modulo'] ?? '';
            if ( $mod_key === '' || ! isset($mods[$mod_key]) ) {
                $mod_key = array_key_first($mods) ?: 'barbearia';
            }
            $e_try['modulo'] = $mod_key;
            $cid             = $mod_key === 'barbearia' ? 1 : 2;
            $sid             = (int) ($e_try['service_id'] ?? 0);
            if ( $sid <= 0 ) {
                $servicos = BarberPro_Database::get_services($cid);
                if ( ! empty($servicos) ) {
                    $sid = (int) $servicos[0]->id;
                    $e_try['service_id'] = $sid;
                }
            }
            $e_try['pro_id'] = (int) ($e_try['pro_id'] ?? 0);
            if ( $sid > 0 ) {
                $slots_try = self::buscar_slots($e_try, $data_parse);
                if ( ! empty($slots_try) ) {
                    $slot_merge = self::resposta_slots($data_parse, $slots_try);
                }
            }
        }

        $resposta_ia = null;
        if ( class_exists('BarberPro_OpenAI') && BarberPro_OpenAI::is_enabled() ) {
            $slots_context = self::build_slots_context();
            $mod_key       = $e['modulo'] ?? '';
            if ( $mod_key === '' ) {
                $mods_m = self::get_modulos();
                $mod_key = array_key_first($mods_m) ?: 'barbearia';
            }
            $cid_ctx = $mod_key === 'lavacar' ? 2 : 1;
            $context = [
                'nome'              => $e['nome'] ?? '',
                'celular'           => $e['celular'] ?? '',
                'slots_disponiveis' => $slots_context,
                'company_id'        => $cid_ctx,
            ];
            if ( $slot_merge ) {
                $context['widget_ia_hint'] = 'O cliente escolheu uma data com horários livres no sistema. Responda em 1–2 frases, de forma acolhedora. Não liste horários no texto — eles aparecerão como botões abaixo da mensagem.';
            }
            $resposta_ia = BarberPro_OpenAI::chat_with_history($history, $msg, $context);
        }

        if ( ! $resposta_ia ) {
            $fallbacks = [
                'Oi! Estou por aqui sim 😊 Em que posso te ajudar hoje?',
                'Opa, recebi sua mensagem! Me conta: quer agendar um horário ou tirar uma dúvida?',
                'Olha só, tive um probleminha técnico agora, mas não some não — quer tentar de novo ou prefere ir direto no agendamento?',
            ];
            $resposta_ia = $fallbacks[ array_rand( $fallbacks ) ];
        }

        $assistant_visible = $resposta_ia;
        $out_etapa         = 'ia_livre';
        $out_slots         = null;
        $out_slot_buttons  = null;

        if ( $slot_merge ) {
            $assistant_visible = trim($resposta_ia) . "\n\n" . $slot_merge['message'];
            $out_etapa         = 'escolher_horario';
            $out_slots         = $slot_merge['slots'];
            $out_slot_buttons  = $slot_merge['slot_buttons'];
            $e['modulo']       = $e_try['modulo'];
            $e['service_id']   = (int) $e_try['service_id'];
            $e['pro_id']       = (int) $e_try['pro_id'];
            $e['data']         = $data_parse;
            $e['etapa']        = 'escolher_horario';
        }

        $history[] = ['role' => 'user',      'content' => $msg];
        $history[] = ['role' => 'assistant', 'content' => $assistant_visible];
        if ( count($history) > 20 ) {
            $history = array_slice($history, -20);
        }

        $e['ia_history'] = $history;
        self::set_estado($session, $e);

        return [
            'message'       => $assistant_visible,
            'etapa'         => $out_etapa,
            'quick_replies' => [],
            'slots'         => $out_slots,
            'slot_buttons'  => $out_slot_buttons,
        ];
    }

    private static function etapa_nome( string $session, string $msg, array $e ): array {
        if ( mb_strlen($msg) < 2 ) {
            return ['message' => 'Pode me dizer seu nome completo? 😊', 'etapa' => 'coletar_nome'];
        }
        $nome = ucwords(mb_strtolower(trim($msg)));
        $e['nome']  = $nome;
        $e['etapa'] = 'coletar_celular';
        self::set_estado($session, $e);

        $msg_cel = "Prazer, *{$nome}*! 😊 Qual é o seu celular com DDD?";
        if ( ! empty($e['horario']) && ! empty($e['data']) ) {
            $msg_cel = "Obrigado, *{$nome}*! 📱 Para confirmar seu horário, qual é o seu celular com DDD?";
        }

        return [
            'message'      => $msg_cel,
            'etapa'        => 'coletar_celular',
            'quick_replies'=> [],
        ];
    }

    private static function etapa_celular( string $session, string $msg, array $e ): array {
        $cel = preg_replace('/\D/','',$msg);
        if ( strlen($cel) < 10 || strlen($cel) > 11 ) {
            return ['message' => 'Não entendi o celular 😅 Pode digitar com DDD? Ex: 44999990000', 'etapa' => 'coletar_celular'];
        }
        $e['celular'] = $cel;
        $e['etapa']   = 'coletar_email';
        self::set_estado($session, $e);

        return [
            'message' => BarberPro_Database::get_setting('wc_msg_pedir_email','Ótimo! Agora me diga seu e-mail para enviar a confirmação 📧'),
            'etapa'   => 'coletar_email',
            'quick_replies' => [],
        ];
    }

    private static function etapa_email( string $session, string $msg, array $e ): array {
        // Aceita "pular" ou "não tenho"
        $ml = mb_strtolower(trim($msg));
        if ( in_array($ml, ['pular','não tenho','nao tenho','sem email','não','nao','-']) ) {
            $e['email'] = '';
        } elseif ( is_email($msg) ) {
            $e['email'] = sanitize_email($msg);
        } else {
            return [
                'message'      => 'E-mail inválido 😕 Tente novamente ou diga *pular* para continuar sem e-mail.',
                'etapa'        => 'coletar_email',
                'quick_replies'=> ['Pular'],
            ];
        }

        $modulos = self::get_modulos();
        if ( count($modulos) === 1 ) {
            $e['modulo'] = array_key_first($modulos);
            $e['etapa']  = 'escolher_servico';
            self::set_estado($session, $e);
            return self::resposta_servicos($e);
        }

        $e['etapa'] = 'escolher_modulo';
        self::set_estado($session, $e);
        return self::resposta_modulos($modulos);
    }

    private static function etapa_modulo( string $session, string $msg, array $e ): array {
        $modulos = self::get_modulos();
        $escolha = self::detectar_item_lista($msg, array_values($modulos));
        if ( ! $escolha ) {
            $escolha = self::detectar_item_lista($msg, array_keys($modulos));
        }
        if ( $escolha === null ) {
            return array_merge(self::resposta_modulos($modulos), ['error_hint' => true]);
        }
        $keys     = array_keys($modulos);
        $e['modulo'] = is_int($escolha) ? $keys[$escolha] : $escolha;
        $e['etapa']  = 'escolher_servico';
        self::set_estado($session, $e);
        return self::resposta_servicos($e);
    }

    private static function etapa_servico( string $session, string $msg, array $e ): array {
        $cid      = $e['modulo'] === 'barbearia' ? 1 : 2;
        $servicos = BarberPro_Database::get_services($cid);
        $idx      = self::detectar_numero_ou_nome($msg, array_column($servicos,'name'));
        if ( $idx === null ) {
            return array_merge(self::resposta_servicos($e), ['error_hint'=>true]);
        }
        $e['service_id'] = (int)$servicos[$idx]->id;

        $pros = BarberPro_Database::get_professionals($cid);
        if ( count($pros) <= 1 ) {
            $e['pro_id'] = count($pros) === 1 ? (int)$pros[0]->id : 0;
            $e['etapa']  = 'escolher_data';
            self::set_estado($session, $e);
            return self::resposta_data($e);
        }
        $e['etapa'] = 'escolher_profissional';
        self::set_estado($session, $e);
        return self::resposta_profissionais($e, $pros);
    }

    private static function etapa_profissional( string $session, string $msg, array $e ): array {
        $cid  = $e['modulo'] === 'barbearia' ? 1 : 2;
        $pros = BarberPro_Database::get_professionals($cid);
        $ml   = mb_strtolower(trim($msg));

        if ( in_array($ml, ['qualquer','tanto faz','0','indiferente','qualquer um']) ) {
            $e['pro_id'] = 0;
        } else {
            $idx = self::detectar_numero_ou_nome($msg, array_column($pros,'name'));
            if ( $idx === null ) return array_merge(self::resposta_profissionais($e,$pros), ['error_hint'=>true]);
            $e['pro_id'] = (int)$pros[$idx]->id;
        }

        $e['etapa'] = 'escolher_data';
        self::set_estado($session, $e);
        return self::resposta_data($e);
    }

    private static function etapa_data( string $session, string $msg, array $e ): array {
        $data = self::parsear_data($msg);
        if ( ! $data ) {
            return ['message' => "Não entendi a data 😅\n\nPode dizer *hoje*, *amanhã*, um dia da semana (ex: *sexta*) ou uma data (ex: *28/03*)?",
                    'etapa'   => 'escolher_data',
                    'quick_replies' => ['Hoje','Amanhã','Sexta','Sábado']];
        }
        if ( $data < current_time('Y-m-d') ) {
            return ['message' => 'Essa data já passou 😅 Pode escolher uma data a partir de hoje?',
                    'etapa'   => 'escolher_data',
                    'quick_replies' => ['Hoje','Amanhã']];
        }

        $slots = self::buscar_slots($e, $data);
        if ( empty($slots) ) {
            $dia = date_i18n('l, d/m', strtotime($data));
            return ['message' => BarberPro_Database::get_setting('wc_msg_sem_horarios','Infelizmente não temos horários disponíveis nessa data 😔 Quer tentar outra data?'),
                    'etapa'   => 'escolher_data',
                    'quick_replies' => ['Hoje','Amanhã','Próxima semana']];
        }

        $e['data']  = $data;
        $e['etapa'] = 'escolher_horario';
        self::set_estado($session, $e);

        return self::resposta_slots($data, $slots);
    }

    private static function etapa_horario( string $session, string $msg, array $e ): array {
        $slots   = self::buscar_slots($e, $e['data']);
        $horario = self::parsear_horario($msg, $slots);

        if ( ! $horario ) {
            $base = self::resposta_slots($e['data'], $slots);
            if ( class_exists('BarberPro_OpenAI') && BarberPro_OpenAI::is_enabled() ) {
                $ctx_nome = $e['nome'] ?? '';
                $ia = BarberPro_OpenAI::chat(
                    $msg,
                    ['nome' => $ctx_nome],
                    'Você é o assistente do widget de agendamento de uma barbearia. O cliente está na etapa de escolher um horário entre botões na tela, mas enviou um texto que não corresponde a um horário (pergunta casual, piada, "você é IA?", etc.). Responda de forma breve, natural e em português brasileiro. Não invente horários. Convide-o a tocar em um dos botões de horário. Máximo 2 frases curtas.'
                );
                if ( $ia ) {
                    $base['message'] = trim( $ia ) . "\n\n" . $base['message'];
                }
            }
            return array_merge($base, ['error_hint' => true]);
        }

        $e['horario'] = $horario;
        // Modo IA pode ter pular nome/celular — recupera fluxo completo antes da confirmação
        if ( empty( $e['nome'] ) || mb_strlen( trim( (string) $e['nome'] ) ) < 2 ) {
            $e['etapa'] = 'coletar_nome';
            self::set_estado( $session, $e );
            [$hh, $mm] = explode( ':', substr( $horario, 0, 5 ) );
            $hora_lbl  = ( $mm === '00' ) ? "{$hh}h" : "{$hh}h{$mm}";
            return [
                'message'       => "Ótima escolha: *{$hora_lbl}* ⏰\n\nPara reservar, qual é o *seu nome completo*?",
                'etapa'         => 'coletar_nome',
                'quick_replies' => [],
            ];
        }
        if ( empty( $e['celular'] ) || strlen( preg_replace( '/\D/', '', (string) $e['celular'] ) ) < 10 ) {
            $e['etapa'] = 'coletar_celular';
            self::set_estado( $session, $e );
            $nome = $e['nome'] ?? '';
            return [
                'message'       => "Perfeito, *{$nome}*! 📱 Qual é o seu celular com DDD?",
                'etapa'         => 'coletar_celular',
                'quick_replies' => [],
            ];
        }
        if ( ! isset( $e['email'] ) ) {
            $e['email'] = '';
        }

        $e['etapa'] = 'confirmar';
        self::set_estado($session, $e);
        return self::resposta_confirmar($e);
    }

    private static function etapa_confirmar( string $session, string $msg, array $e ): array {
        $ml = mb_strtolower(trim($msg));

        if ( self::eh_negativo($ml) ) {
            self::limpar_estado($session);
            return ['message' => BarberPro_Database::get_setting('wc_msg_cancelado','Agendamento cancelado 😊 Se quiser remarcar é só começar de novo!'),
                    'etapa'   => 'concluido', 'quick_replies' => ['Novo agendamento']];
        }
        if ( str_contains($ml,'mudar') || str_contains($ml,'outra') || str_contains($ml,'outro horário') ) {
            $e['etapa'] = 'escolher_data'; $e['data'] = ''; $e['horario'] = '';
            self::set_estado($session, $e);
            return self::resposta_data($e);
        }
        if ( ! self::eh_positivo($ml) ) {
            return ['message' => 'Confirma o agendamento? Responda *sim* para confirmar ou *não* para cancelar 😊',
                    'etapa'   => 'confirmar', 'quick_replies' => ['Sim, confirmar','Não, cancelar','Mudar data']];
        }

        // Sem formas de pagamento configuradas no painel: confirma direto + WhatsApp (via create_booking / notificações)
        if ( function_exists('bp_has_any_payment_method_configured') && ! bp_has_any_payment_method_configured() ) {
            $e['payment_method'] = 'dinheiro';
            return self::concluir_agendamento($session, $e);
        }

        $gateways = class_exists('BarberPro_Payment') ? BarberPro_Payment::get_active_gateways() : [];
        $when     = BarberPro_Database::get_setting('online_payment_when','optional');
        $has_online = ! empty($gateways) && $when !== 'disabled';

        // Primeiro: perguntar se paga no local ou agora (alinha ao fluxo humano do WhatsApp)
        $e['etapa']              = 'escolher_momento_pagamento';
        $e['pay_only_online']    = false;
        $e['pay_timing']         = '';
        self::set_estado($session, $e);

        if ( $has_online ) {
            return [
                'message'       => "Show! ✨ Só uma coisa sobre o *pagamento*: você prefere *pagar no salão no dia* do atendimento ou *garantir agora* pelo celular (PIX/cartão)?",
                'etapa'         => 'escolher_momento_pagamento',
                'quick_replies' => ['No salão, no dia', 'Agora pelo celular'],
            ];
        }

        return [
            'message'       => "Perfeito! O pagamento fica *no local*, no dia do atendimento, tudo bem? 😊",
            'etapa'         => 'escolher_momento_pagamento',
            'quick_replies' => ['Sim, pagamento no local'],
        ];
    }

    /**
     * Cliente escolhe pagar no dia ou agora (online).
     */
    private static function etapa_momento_pagamento_widget( string $session, string $msg, array $e ): array {
        $gateways = class_exists('BarberPro_Payment') ? BarberPro_Payment::get_active_gateways() : [];
        $when     = BarberPro_Database::get_setting('online_payment_when','optional');
        $has_online = ! empty($gateways) && $when !== 'disabled';
        $ml       = mb_strtolower(trim($msg));
        $metodos  = function_exists('bp_get_payment_methods') ? bp_get_payment_methods() : ['dinheiro' => '💵 Dinheiro'];

        if ( ! $has_online ) {
            $first = array_key_first($metodos) ?: 'dinheiro';
            $e['payment_method'] = $first;
            self::set_estado($session, $e);
            return self::concluir_agendamento($session, $e);
        }

        $quer_online = str_contains($ml, 'celular') || str_contains($ml, 'agora') || str_contains($ml, 'online')
            || str_contains($ml, 'pix') || str_contains($ml, 'cartão') || str_contains($ml, 'cartao')
            || str_contains($ml, '2') || str_contains($ml, 'garantir');
        $quer_local  = str_contains($ml, 'local') || str_contains($ml, 'salão') || str_contains($ml, 'salao')
            || str_contains($ml, 'dia') || str_contains($ml, '1') || str_contains($ml, 'atendimento');

        if ( $quer_online && ! $quer_local ) {
            $e['pay_only_online'] = true;
            $e['etapa']           = 'escolher_pagamento';
            self::set_estado($session, $e);
            $qr = [];
            $i  = 1;
            foreach ($gateways as $key => $label) {
                $qr[] = "{$i}. {$label}";
                $i++;
            }
            return [
                'message'       => self::mensagem_pagamento_ia($e) . "\n\nEscolha como quer pagar agora:",
                'etapa'         => 'escolher_pagamento',
                'quick_replies' => $qr,
            ];
        }

        if ( $quer_local || ! $quer_online ) {
            $first = array_key_first($metodos) ?: 'dinheiro';
            $e['payment_method'] = $first;
            self::set_estado($session, $e);
            return self::concluir_agendamento($session, $e);
        }

        return [
            'message'       => 'Não entendi 😅 Você prefere *pagar no salão no dia* ou *agora pelo celular*?',
            'etapa'         => 'escolher_momento_pagamento',
            'quick_replies' => ['No salão, no dia', 'Agora pelo celular'],
        ];
    }

    private static function etapa_escolher_pagamento_widget( string $session, string $msg, array $e ): array {
        $gateways = class_exists('BarberPro_Payment') ? BarberPro_Payment::get_active_gateways() : [];
        $when     = BarberPro_Database::get_setting('online_payment_when','optional');
        $metodos  = function_exists('bp_get_payment_methods') ? bp_get_payment_methods() : ['presencial' => '💵 No atendimento'];
        $ml       = mb_strtolower(trim($msg));

        // Monta lista: só gateways se cliente já escolheu "pagar agora"
        $opcoes = [];
        if ( ! empty($e['pay_only_online']) ) {
            if ( ! empty($gateways) && $when !== 'disabled' ) {
                foreach ( $gateways as $key => $label ) {
                    $opcoes[ $key ] = $label;
                }
            }
            if ( empty($opcoes) ) {
                $first = array_key_first($metodos) ?: 'dinheiro';
                $e['payment_method'] = $first;
                $e['pay_only_online'] = false;
                self::set_estado($session, $e);
                return self::concluir_agendamento($session, $e);
            }
        } else {
            if ( ! empty($gateways) && $when !== 'disabled' ) {
                foreach ( $gateways as $key => $label ) {
                    $opcoes[ $key ] = $label;
                }
            }
            if ( empty($gateways) || $when !== 'required' ) {
                foreach ( $metodos as $key => $label ) {
                    $opcoes[ $key ] = $label;
                }
            }
        }
        $keys = array_keys($opcoes);

        $escolhido = null;
        // Detecta por número
        if ( preg_match('/^\s*(\d+)/', $msg, $m) ) {
            $idx = (int)$m[1] - 1;
            if ( isset($keys[$idx]) ) $escolhido = $keys[$idx];
        }
        // Detecta por texto/nome
        if ( $escolhido === null ) {
            foreach ( $opcoes as $key => $label ) {
                if ( str_contains($ml, mb_strtolower($key)) || str_contains($ml, mb_strtolower($label)) ) {
                    $escolhido = $key;
                    break;
                }
            }
        }

        if ( $escolhido === null ) {
            $qr = []; $i = 1;
            foreach ($opcoes as $k => $l) { $qr[] = "{$i}. {$l}"; $i++; }
            return ['message' => 'Não entendi 😅 Escolha uma opção:', 'etapa' => 'escolher_pagamento', 'quick_replies' => $qr];
        }

        $e['payment_method'] = $escolhido;
        self::set_estado($session, $e);
        return self::concluir_agendamento($session, $e);
    }

    private static function concluir_agendamento( string $session, array $e ): array {
        $result = self::criar_agendamento($e);
        self::limpar_estado($session);

        if ( ! $result['success'] ) {
            return ['message' => "Ops! Esse horário foi reservado agora 😅\n\n".($result['message']??'')."\n\nQuer tentar outro horário?",
                    'etapa'   => 'escolher_data', 'quick_replies' => ['Sim, tentar outro']];
        }

        $booking = self::get_booking($result['booking_id']);
        if ($booking) self::notificar($booking, $e);

        $code  = $result['booking_code'] ?? '#'.$result['booking_id'];
        $dia   = date_i18n('l, d/m/Y', strtotime($e['data']));
        [$hh,$mm] = explode(':', substr($e['horario'],0,5));
        $hora  = $mm==='00' ? "{$hh}h" : "{$hh}h{$mm}";

        $msg = BarberPro_Database::get_setting('wc_msg_sucesso',
            "✅ *Agendamento confirmado!*\n\n📋 Código: *{codigo}*\n📅 {data} às *{hora}*\n\nTe esperamos! 😊"
        );
        $msg = str_replace(['{codigo}','{data}','{hora}'], [$code,$dia,$hora], $msg);

        // Localização / estacionamento
        $local = BarberPro_Database::get_setting('bot_msg_localizacao','');
        if ($local) $msg .= "\n\n📍 *Como chegar / Estacionamento:*\n{$local}";

        // Link de pagamento se escolheu pagar agora
        $pay_method = $e['payment_method'] ?? 'presencial';
        if ($pay_method !== 'presencial' && $booking && class_exists('BarberPro_Payment')) {
            $charge = BarberPro_Payment::create_charge($booking, $pay_method);
            if ($charge['success']) {
                $pay_url = $charge['checkout_url'] ?? '';
                $pay_pix = $charge['pix_payload'] ?? '';
                if ($pay_url) {
                    $msg .= "\n\n💳 Toque no botão *Pagar agora* abaixo para concluir no ambiente seguro.";
                    return [
                        'message'               => $msg,
                        'etapa'                 => 'concluido',
                        'success'               => true,
                        'booking_code'          => $code,
                        'payment_url'           => $pay_url,
                        'payment_button_label'  => __( 'Pagar agora', 'barberpro-saas' ),
                        'quick_replies'         => [],
                    ];
                } elseif ($pay_pix) {
                    $msg .= "\n\n⚡ *PIX copia e cola:*\n`{$pay_pix}`";
                }
            }
        }

        return ['message'=>$msg,'etapa'=>'concluido','success'=>true,'booking_code'=>$code,'quick_replies'=>[]];
    }

    // ── Criar agendamento ────────────────────────────────────────
    private static function criar_agendamento( array $e ): array {
        $cid    = $e['modulo'] === 'barbearia' ? 1 : 2;
        $pro_id = (int)$e['pro_id'];

        if ( $pro_id === 0 ) {
            $svc = BarberPro_Database::get_service((int)$e['service_id']);
            $dur = (int)($svc->duration_minutes ?? $svc->duration ?? 30);
            foreach (BarberPro_Database::get_professionals($cid) as $p) {
                $slots = BarberPro_Bookings::get_available_slots((int)$p->id, $e['data'], $dur, false);
                if (in_array($e['horario'], $slots, true)) { $pro_id = (int)$p->id; break; }
            }
            if (!$pro_id) return ['success'=>false,'message'=>'Nenhum profissional disponível neste horário.'];
        }

        return BarberPro_Bookings::create_booking([
            'company_id'      => $cid,
            'service_id'      => (int)$e['service_id'],
            'professional_id' => $pro_id,
            'client_name'     => sanitize_text_field($e['nome']),
            'client_phone'    => sanitize_text_field($e['celular']),
            'client_email'    => sanitize_email($e['email'] ?? ''),
            'booking_date'    => $e['data'],
            'booking_time'    => $e['horario'],
            'notes'           => 'Agendado via Widget Chat do Site',
            'status'          => 'agendado',
            'payment_method'  => sanitize_key($e['payment_method'] ?? 'presencial'),
            'admin_mode'      => false,
        ]);
    }

    // ── Notificações ─────────────────────────────────────────────
    private static function notificar( object $booking, array $e ): void {
        // Notifica CLIENTE
        BarberPro_Notifications::dispatch('confirmation', $booking);

        // Notifica DONO — e-mail
        self::notificar_dono_email($booking, $e);

        // Notifica DONO — WhatsApp
        self::notificar_dono_whatsapp($booking);
    }

    private static function notificar_dono_email( object $booking, array $e ): void {
        $email_dono = BarberPro_Database::get_setting('widget_chat_email_dono',
                      BarberPro_Database::get_setting('email_remetente', get_bloginfo('admin_email')));
        if ( ! $email_dono || ! is_email($email_dono) ) return;

        $svc  = BarberPro_Database::get_service((int)$booking->service_id);
        $pro  = BarberPro_Database::get_professional((int)$booking->professional_id);
        $dia  = date_i18n('d/m/Y', strtotime($booking->booking_date));
        $hora = substr($booking->booking_time, 0, 5);

        $assunto = "📅 Novo agendamento via Chat — {$booking->client_name}";
        $corpo   = "Novo agendamento recebido pelo widget de chat do site.\n\n"
                 . "👤 Cliente: {$booking->client_name}\n"
                 . "📱 Celular: {$booking->client_phone}\n"
                 . ( $booking->client_email ? "📧 E-mail: {$booking->client_email}\n" : "" )
                 . "✂️ Serviço: " . ($svc->name??'—') . "\n"
                 . "👤 Profissional: " . ($pro->name??'—') . "\n"
                 . "📅 Data: {$dia} às {$hora}\n"
                 . "📋 Código: {$booking->booking_code}";

        $nome_neg = BarberPro_Database::get_setting('email_nome_remetente', get_bloginfo('name'));
        $from     = BarberPro_Database::get_setting('email_remetente', get_bloginfo('admin_email'));
        wp_mail($email_dono, $assunto, $corpo, [
            "Content-Type: text/plain; charset=UTF-8",
            "From: {$nome_neg} <{$from}>",
        ]);
    }

    private static function notificar_dono_whatsapp( object $booking ): void {
        $tel_dono = BarberPro_Database::get_setting('widget_chat_tel_dono',
                    BarberPro_Database::get_setting('whatsapp_number',''));
        if ( ! $tel_dono ) return;

        $svc  = BarberPro_Database::get_service((int)$booking->service_id);
        $pro  = BarberPro_Database::get_professional((int)$booking->professional_id);
        $dia  = date_i18n('d/m/Y', strtotime($booking->booking_date));
        $hora = substr($booking->booking_time, 0, 5);

        $msg = "📅 *Novo agendamento via Chat!*\n\n"
             . "👤 {$booking->client_name}\n"
             . "📱 {$booking->client_phone}\n"
             . "✂️ " . ($svc->name??'—') . "\n"
             . "👤 " . ($pro->name??'—') . "\n"
             . "📅 {$dia} às {$hora}\n"
             . "📋 #{$booking->booking_code}";

        BarberPro_WhatsApp::send($tel_dono, $msg);
    }

    // ── AJAX: slots disponíveis ──────────────────────────────────
    private static function ajax_get_slots( int $svc_id, int $pro_id, string $date ): void {
        if ( ! $svc_id || ! $date ) wp_send_json_error(['message'=>'Dados inválidos.']);

        $svc = BarberPro_Database::get_service($svc_id);
        if (!$svc) wp_send_json_error(['message'=>'Serviço não encontrado.']);
        $dur = (int)($svc->duration_minutes ?? $svc->duration ?? 30);

        if ($pro_id === 0) {
            // Detecta módulo via serviço
            $cid    = (int)$svc->company_id;
            $merged = [];
            foreach (BarberPro_Database::get_professionals($cid) as $p) {
                foreach (BarberPro_Bookings::get_available_slots((int)$p->id, $date, $dur, false) as $s)
                    $merged[$s] = true;
            }
            ksort($merged);
            $slots = array_keys($merged);
        } else {
            $slots = BarberPro_Bookings::get_available_slots($pro_id, $date, $dur, false);
        }
        wp_send_json_success(['slots'=>$slots]);
    }

    // ── Respostas formatadas ─────────────────────────────────────
    private static function resposta_modulos( array $modulos ): array {
        $msg  = "Qual serviço você quer agendar?\n\n";
        $qr   = [];
        foreach ($modulos as $nome) { $qr[] = $nome; }
        return ['message'=>$msg.'Escolha uma opção:', 'etapa'=>'escolher_modulo', 'quick_replies'=>$qr];
    }

    private static function resposta_servicos( array $e ): array {
        $cid      = $e['modulo']==='barbearia' ? 1 : 2;
        $servicos = BarberPro_Database::get_services($cid);
        $msg      = "Qual serviço você quer? ✂️\n\n";
        $qr       = [];
        foreach ($servicos as $s) {
            $preco = number_format((float)$s->price,2,',','.');
            $dur   = (int)($s->duration_minutes??$s->duration??30);
            $qr[]  = $s->name;
            $msg  .= "• *{$s->name}* — R\$ {$preco} ({$dur}min)\n";
        }
        return ['message'=>$msg, 'etapa'=>'escolher_servico', 'quick_replies'=>$qr, 'items'=>array_map(function($s) { return ['id'=>$s->id,'name'=>$s->name,'price'=>$s->price,'duration'=>$s->duration_minutes??$s->duration]; }, $servicos)];
    }

    private static function resposta_profissionais( array $e, array $pros ): array {
        $msg = "Com qual profissional prefere? 👤\n\n";
        $qr  = [];
        foreach ($pros as $p) { $qr[] = $p->name; }
        $qr[] = 'Qualquer disponível';
        return ['message'=>$msg.'Escolha um profissional:', 'etapa'=>'escolher_profissional', 'quick_replies'=>$qr];
    }

    private static function resposta_data( array $e ): array {
        $hoje   = date_i18n('d/m', strtotime(current_time('Y-m-d')));
        $amanha = date_i18n('d/m', strtotime('+1 day', strtotime(current_time('Y-m-d'))));
        return [
            'message'      => "Qual data você prefere? 📅\n\n• *hoje* ({$hoje})\n• *amanhã* ({$amanha})\n• Um dia da semana (ex: *sexta*)\n• Ou uma data (ex: *28/03*)",
            'etapa'        => 'escolher_data',
            'quick_replies'=> ['Hoje', 'Amanhã', 'Sexta', 'Sábado'],
        ];
    }

    private static function resposta_slots( string $data, array $slots ): array {
        $dia  = date_i18n('l, d \d\e F', strtotime($data));
        $msg  = "Horários disponíveis em *{$dia}*:\n\n";
        $qr   = [];
        $slot_buttons = [];
        foreach ( $slots as $s ) {
            [$hh,$mm] = explode(':', substr($s,0,5));
            $label = $mm==='00' ? "{$hh}h" : "{$hh}h{$mm}";
            $slot_buttons[] = ['value' => $s, 'label' => $label];
        }
        foreach ( array_slice($slots, 0, 8) as $s ) {
            [$hh,$mm] = explode(':', substr($s,0,5));
            $label = $mm==='00' ? "{$hh}h" : "{$hh}h{$mm}";
            $qr[]  = $label;
            $msg  .= "• *{$label}*\n";
        }
        if ( count($slots) > 8 ) {
            $msg .= "_...e mais " . ( count($slots) - 8 ) . " horários (use os botões abaixo para ver todos)_\n";
        }
        $msg .= "\nToque no horário desejado:";
        return [
            'message'       => $msg,
            'etapa'         => 'escolher_horario',
            'quick_replies' => $qr,
            'slots'         => $slots,
            'slot_buttons'  => $slot_buttons,
        ];
    }

    private static function resposta_confirmar( array $e ): array {
        $svc  = BarberPro_Database::get_service((int)$e['service_id']);
        $pro  = $e['pro_id'] ? BarberPro_Database::get_professional((int)$e['pro_id']) : null;
        $dia  = date_i18n('l, d/m/Y', strtotime($e['data']));
        [$hh,$mm] = explode(':', substr($e['horario'],0,5));
        $hora = $mm==='00' ? "{$hh}h" : "{$hh}h{$mm}";
        $mod  = $e['modulo']==='barbearia' ? '✂️ Barbearia' : '🚗 Lava-Car';

        $msg  = "📋 *Confirme seu agendamento:*\n\n"
              . "📍 {$mod}\n"
              . "✂️ *".($svc->name??'—')."*\n"
              . ($pro ? "👤 {$pro->name}\n" : "")
              . "📅 {$dia}\n"
              . "⏰ *{$hora}*\n"
              . "💰 R\$ ".number_format((float)($svc->price??0),2,',','.')."\n\n"
              . "Confirma? 😊";
        return ['message'=>$msg, 'etapa'=>'confirmar', 'quick_replies'=>['Sim, confirmar!','Não, cancelar','Mudar data']];
    }

    /**
     * Texto da pergunta de pagamento: IA quando disponível, senão template fixo.
     */
    private static function mensagem_pagamento_ia( array $e ): string {
        $fallback = "💳 *Como você prefere pagar?*\n\nPode pagar *agora* (online) para garantir sua vaga ou *no local* no dia do atendimento. O que prefere?";
        if ( ! class_exists('BarberPro_OpenAI') || ! BarberPro_OpenAI::is_enabled() ) {
            return $fallback;
        }
        $svc = BarberPro_Database::get_service( (int) ( $e['service_id'] ?? 0 ) );
        $dia = date_i18n( 'l, d/m/Y', strtotime( $e['data'] ?? 'now' ) );
        $hora_raw = $e['horario'] ?? '';
        [$hh, $mm] = array_pad( explode( ':', substr( $hora_raw, 0, 5 ) ), 2, '00' );
        $hora = ( $mm === '00' ) ? "{$hh}h" : "{$hh}h{$mm}";
        $nome_svc = $svc->name ?? 'o serviço';
        $prompt   = "O cliente confirmou o agendamento: {$dia} às {$hora}, serviço: {$nome_svc}. "
            . 'Pergunte de forma breve e simpática em português brasileiro se ele prefere pagar agora online para garantir a vaga ou pagar presencialmente no local. No máximo 3 frases. Use um tom acolhedor; não precisa listar métodos técnicos (PIX, cartão).';
        $ia = BarberPro_OpenAI::chat( $prompt, $e, 'Você é o assistente de uma barbearia. Responda só com a pergunta ao cliente, sem prefixos como "Claro".' );
        $ia = $ia ? trim( $ia ) : '';
        return $ia !== '' ? $ia : $fallback;
    }

    // ── Helpers ──────────────────────────────────────────────────
    private static function buscar_slots( array $e, string $data ): array {
        $cid    = $e['modulo']==='barbearia' ? 1 : 2;
        $pro_id = (int)$e['pro_id'];
        $svc    = BarberPro_Database::get_service((int)$e['service_id']);
        $dur    = (int)($svc->duration_minutes??$svc->duration??30);
        if ($pro_id === 0) {
            $merged = [];
            foreach (BarberPro_Database::get_professionals($cid) as $p) {
                foreach (BarberPro_Bookings::get_available_slots((int)$p->id,$data,$dur,false) as $s) $merged[$s]=true;
            }
            ksort($merged); return array_keys($merged);
        }
        return BarberPro_Bookings::get_available_slots($pro_id,$data,$dur,false);
    }

    // ── Contexto de disponibilidade real para a IA ──────────────
    private static function build_slots_context(): string {
        try {
            $modulos   = self::get_modulos();
            $hoje      = current_time('Y-m-d');
            $dias_map  = ['Hoje' => $hoje];
            for ( $i = 1; $i <= 6; $i++ ) {
                $d = date('Y-m-d', strtotime("+{$i} days", strtotime($hoje)));
                $dias_map[ date_i18n('l', strtotime($d)) ] = $d;
            }

            $linhas = [];
            foreach ( $modulos as $mod_key => $mod_nome ) {
                $cid      = $mod_key === 'barbearia' ? 1 : 2;
                $servicos = BarberPro_Database::get_services($cid);
                $pros     = BarberPro_Database::get_professionals($cid);
                if ( empty($servicos) || empty($pros) ) continue;

                // Usa o primeiro serviço como referência de duração (30min fallback)
                $dur = (int)($servicos[0]->duration_minutes ?? $servicos[0]->duration ?? 30);

                foreach ( $dias_map as $dia_label => $data ) {
                    $slots_dia = [];
                    foreach ( $pros as $pro ) {
                        $slots = BarberPro_Bookings::get_available_slots((int)$pro->id, $data, $dur, false);
                        foreach ( $slots as $s ) {
                            [$hh,$mm] = explode(':', substr($s,0,5));
                            $slots_dia[$s] = $mm==='00' ? "{$hh}h" : "{$hh}h{$mm}";
                        }
                    }
                    ksort($slots_dia);
                    if ( $slots_dia ) {
                        $linhas[] = "{$dia_label} ({$data}): " . implode(', ', $slots_dia);
                    } else {
                        $linhas[] = "{$dia_label} ({$data}): sem horários";
                    }
                }
            }
            return $linhas ? implode("
", $linhas) : 'Consulte disponibilidade diretamente.';
        } catch ( \Throwable $e ) {
            return 'Disponibilidade não carregada.';
        }
    }

    private static function get_modulos(): array {
        $m = [];
        if (BarberPro_Database::get_setting('module_barbearia_active','1')==='1')
            $m['barbearia'] = BarberPro_Database::get_setting('module_barbearia_name','Barbearia');
        if (BarberPro_Database::get_setting('module_lavacar_active','0')==='1')
            $m['lavacar'] = BarberPro_Database::get_setting('module_lavacar_name','Lava-Car');
        return $m ?: ['barbearia'=>'Barbearia'];
    }

    private static function get_booking( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, s.name as svc_name, s.price as svc_price, p.name as pro_name
             FROM {$wpdb->prefix}barber_bookings b
             LEFT JOIN {$wpdb->prefix}barber_services s ON b.service_id=s.id
             LEFT JOIN {$wpdb->prefix}barber_professionals p ON b.professional_id=p.id
             WHERE b.id=%d LIMIT 1", $id
        ));
    }

    private static function parsear_data( string $msg ): ?string {
        $msg   = mb_strtolower(trim($msg));
        $today = current_time('Y-m-d');
        if (in_array($msg,['hoje','hj'])) return $today;
        if (in_array($msg,['amanhã','amanha','amh'])) return date('Y-m-d',strtotime('+1 day',strtotime($today)));
        if (str_contains($msg,'próxima semana')||str_contains($msg,'proxima semana')) return date('Y-m-d',strtotime('+7 days',strtotime($today)));
        $dias = ['domingo'=>0,'segunda'=>1,'segunda-feira'=>1,'terça'=>2,'terca'=>2,'terça-feira'=>2,
                 'quarta'=>3,'quarta-feira'=>3,'quinta'=>4,'quinta-feira'=>4,'sexta'=>5,'sexta-feira'=>5,'sábado'=>6,'sabado'=>6];
        foreach ($dias as $nome=>$dow) {
            if (str_contains($msg,$nome)) {
                $diff=($dow-(int)date('w',strtotime($today))+7)%7?:7;
                return date('Y-m-d',strtotime("+{$diff} days",strtotime($today)));
            }
        }
        if (preg_match('/(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?/',$msg,$m)) {
            $d=str_pad($m[1],2,'0',STR_PAD_LEFT); $mo=str_pad($m[2],2,'0',STR_PAD_LEFT);
            $y=isset($m[3])?(strlen($m[3])===2?'20'.$m[3]:$m[3]):date('Y',strtotime($today));
            if (checkdate((int)$mo,(int)$d,(int)$y)) return "{$y}-{$mo}-{$d}";
        }
        return null;
    }

    private static function parsear_horario( string $msg, array $slots ): ?string {
        $msg_trim = trim( $msg );
        foreach ( $slots as $s ) {
            if ( $msg_trim === trim( $s ) ) {
                return $s;
            }
        }
        if (preg_match('/(\d{1,2})[h:](\d{0,2})/',mb_strtolower($msg),$m)) {
            $hh=str_pad($m[1],2,'0',STR_PAD_LEFT); $mm=str_pad($m[2]?:'00',2,'0',STR_PAD_LEFT);
            foreach ($slots as $s) { if (strpos($s,"{$hh}:{$mm}")===0) return $s; }
        }
        if (preg_match('/^\s*(\d+)\s*$/',$msg,$m)) {
            $n=(int)$m[1]-1; if (isset($slots[$n])) return $slots[$n];
        }
        // Busca label "14h" ou "14h30" no texto
        foreach ($slots as $s) {
            [$hh,$mm] = explode(':',substr($s,0,5));
            $label = $mm==='00' ? "{$hh}h" : "{$hh}h{$mm}";
            if (str_contains(mb_strtolower($msg), mb_strtolower($label))) return $s;
        }
        return null;
    }

    private static function detectar_item_lista( string $msg, array $items ): ?int {
        $ml = mb_strtolower(trim($msg));
        if (preg_match('/^\s*(\d+)\s*$/',$msg,$m)) { $n=(int)$m[1]-1; return isset($items[$n])?$n:null; }
        foreach ($items as $i=>$item) { if (str_contains($ml,mb_strtolower($item))) return $i; }
        if (preg_match('/\b(\d+)\b/',$msg,$m)) { $n=(int)$m[1]-1; return isset($items[$n])?$n:null; }
        return null;
    }

    private static function detectar_numero_ou_nome( string $msg, array $names ): ?int {
        $ml = mb_strtolower(trim($msg));
        if (preg_match('/^\s*(\d+)\s*$/',$msg,$m)) { $n=(int)$m[1]-1; return isset($names[$n])?$n:null; }
        foreach ($names as $i=>$n) { if (str_contains($ml,mb_strtolower($n))) return $i; }
        if (preg_match('/\b(\d+)\b/',$msg,$m)) { $n=(int)$m[1]-1; return isset($names[$n])?$n:null; }
        return null;
    }

    private static function eh_positivo( string $msg ): bool {
        foreach (['sim','s','ok','pode','isso','confirmo','certo','tá','ta','bom','ótimo','otimo','1','yes','sim, confirmar','👍'] as $p)
            if (str_contains($msg,$p)) return true;
        return false;
    }

    private static function eh_negativo( string $msg ): bool {
        // Palavras longas — str_contains seguro
        foreach (['não','nao','cancelar','voltar','desistir','não, cancelar'] as $p) {
            if ( str_contains($msg, $p) ) return true;
        }
        // Tokens curtos: \b evita casar dentro de outras palavras (ex: 'n' em "confirmar")
        if ( preg_match('/\bn\b/u',  $msg) ) return true;
        if ( preg_match('/\b0\b/',   $msg) ) return true;
        if ( preg_match('/\bno\b/u', $msg) ) return true;
        return false;
    }

    // Estado (transient 30min)
    private static function get_estado( string $s ): ?array { $v=get_transient('bpwc_'.md5($s)); return is_array($v)?$v:null; }
    private static function set_estado( string $s, array $e ): void { set_transient('bpwc_'.md5($s),$e,30*MINUTE_IN_SECONDS); }
    private static function limpar_estado( string $s ): void { delete_transient('bpwc_'.md5($s)); }
}
