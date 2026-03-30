<?php
/**
 * BarberPro – Integração com IA (OpenAI Chat Completions)
 *
 * Usa a API OpenAI (GPT-4o mini por padrão) para:
 * - Respostas inteligentes no bot WhatsApp (fora do fluxo de agendamento)
 * - Respostas inteligentes no widget chat do site
 * - Geração de mensagens personalizadas de reativação
 * - Sugestões de horários e serviços
 *
 * Configurar em: BarberPro → Configurações → aba 🤖 Bot → seção OpenAI
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_OpenAI {

    const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    /** Modelo padrão recomendado (custo/benefício). */
    const DEFAULT_MODEL = 'gpt-4o-mini';

    /** @deprecated use OPENAI_API_URL */
    const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * Normaliza o modelo salvo nas configurações: apenas IDs OpenAI suportados;
     * qualquer outro valor gravado no passado passa a usar GPT-4o mini.
     */
    private static function resolve_model( string $stored ): string {
        $m = strtolower( trim( $stored ) );
        $allowed = [ 'gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo' ];
        if ( in_array( $m, $allowed, true ) ) {
            return $m;
        }
        return self::DEFAULT_MODEL;
    }

    // =========================================================
    // MÉTODO PRINCIPAL
    // =========================================================

    /**
     * Envia uma mensagem para a OpenAI e retorna a resposta.
     *
     * @param string $user_message   Mensagem do usuário
     * @param array  $context        Contexto extra (nome, serviços, etc.)
     * @param string $system_prompt  Prompt do sistema (usa o configurado se vazio)
     * @return string|null           Resposta da IA ou null em caso de erro
     */
    public static function chat( string $user_message, array $context = [], string $system_prompt = '' ): ?string {
        $api_key = BarberPro_Database::get_setting('openai_api_key', '');
        if ( empty($api_key) ) return null;

        $model   = self::resolve_model( BarberPro_Database::get_setting('openai_model', self::DEFAULT_MODEL) );
        $max_tok = (int) BarberPro_Database::get_setting('openai_max_tokens', 300);

        if ( ! $system_prompt ) {
            $system_prompt = self::build_system_prompt($context);
        }

        $body = wp_json_encode([
            'model'       => $model,
            'messages'    => [
                [ 'role' => 'system', 'content' => $system_prompt ],
                [ 'role' => 'user',   'content' => $user_message  ],
            ],
            'max_tokens'  => $max_tok,
            'temperature' => 0.82,
        ]);

        $response = wp_remote_post( self::OPENAI_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 45,
        ]);

        if ( is_wp_error($response) ) {
            error_log('[BarberPro AI] OpenAI Erro: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ( $code !== 200 || empty($data['choices'][0]['message']['content']) ) {
            error_log('[BarberPro AI] OpenAI resposta inválida: ' . wp_remote_retrieve_body($response));
            return null;
        }

        return trim($data['choices'][0]['message']['content']);
    }

    /**
     * Versão com histórico de conversa (multi-turn).
     *
     * @param array  $history  Array de ['role'=>'user'|'assistant', 'content'=>'...']
     * @param string $new_msg  Nova mensagem do usuário
     * @param array  $context  Contexto do negócio
     */
    public static function chat_with_history( array $history, string $new_msg, array $context = [] ): ?string {
        $api_key = BarberPro_Database::get_setting('openai_api_key', '');
        if ( empty($api_key) ) return null;

        $model   = self::resolve_model( BarberPro_Database::get_setting('openai_model', self::DEFAULT_MODEL) );
        $max_tok = (int) BarberPro_Database::get_setting('openai_max_tokens', 300);
        $system  = self::build_system_prompt($context);

        $messages = [
            [ 'role' => 'system', 'content' => $system ],
        ];

        foreach ( array_slice($history, -10) as $msg ) {
            $role = in_array($msg['role'], ['user','assistant'], true) ? $msg['role'] : 'user';
            $content = isset($msg['content']) ? (string) $msg['content'] : '';
            if ( $content === '' ) {
                continue;
            }
            $messages[] = [ 'role' => $role, 'content' => $content ];
        }

        $messages[] = [ 'role' => 'user', 'content' => $new_msg ];

        $body = wp_json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'max_tokens'  => $max_tok,
            'temperature' => 0.82,
        ]);

        $response = wp_remote_post( self::OPENAI_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => $body,
            'timeout' => 45,
        ]);

        if ( is_wp_error($response) ) {
            error_log('[BarberPro AI] OpenAI Erro: ' . $response->get_error_message());
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $out  = trim($data['choices'][0]['message']['content'] ?? '');
        if ( $out === '' ) {
            error_log('[BarberPro AI] OpenAI resposta vazia: ' . wp_remote_retrieve_body($response));
        }
        return $out !== '' ? $out : null;
    }

    // =========================================================
    // SYSTEM PROMPT
    // =========================================================

    /**
     * Resolve company_id e nome do negócio a partir do contexto (multi-empresa).
     */
    private static function resolve_company_from_context( array $context ): array {
        $mk = isset( $context['module_key'] ) ? sanitize_key( (string) $context['module_key'] ) : '';
        if ( $mk === 'lavacar' && class_exists( 'BarberPro_Modules' ) ) {
            $cid = BarberPro_Modules::company_id( 'lavacar' );
        } elseif ( $mk === 'barbearia' && class_exists( 'BarberPro_Modules' ) ) {
            $cid = BarberPro_Modules::company_id( 'barbearia' );
        } else {
            $cid = (int) ( $context['company_id'] ?? 0 );
            if ( $cid <= 0 && class_exists( 'BarberPro_Modules' ) ) {
                $cid = BarberPro_Modules::company_id( 'barbearia' );
            }
        }
        $cid_lav = class_exists( 'BarberPro_Modules' ) ? BarberPro_Modules::company_id( 'lavacar' ) : 2;
        $nome_negocio = ( $cid === $cid_lav )
            ? BarberPro_Database::get_setting( 'module_lavacar_name', 'Lava-Car' )
            : BarberPro_Database::get_setting( 'module_barbearia_name', get_bloginfo( 'name' ) );
        return [ $cid, $nome_negocio ];
    }

    /** System prompt do chat (widget/bot); use booking_in_chat_only para não sugerir link externo de agendamento. */
    private static function build_system_prompt( array $context = [] ): string {
        // Prompt customizado pelo usuário nas configurações
        $custom = BarberPro_Database::get_setting('openai_system_prompt', '');
        if ( $custom ) return self::replace_prompt_vars($custom, $context);

        $in_chat_only = ! empty( $context['booking_in_chat_only'] );
        [ $cid, $nome_negocio ] = self::resolve_company_from_context( $context );
        $agenda_url   = BarberPro_Database::get_setting('booking_page_url', home_url('/agendamento/'));

        // Busca serviços disponíveis para contextualizar a IA
        $servicos = BarberPro_Database::get_services( $cid );
        $lista_svc = '';
        foreach ( array_slice($servicos, 0, 8) as $s ) {
            $dur = (int) ( $s->duration_minutes ?? $s->duration ?? 30 );
            $lista_svc .= "- {$s->name}: R$ " . number_format( (float) $s->price, 2, ',', '.' ) . " ({$dur}min)\n";
        }

        $profissionais = BarberPro_Database::get_professionals( $cid );
        $lista_pro = implode(', ', array_column( (array) $profissionais, 'name' ) );

        $horario_info = BarberPro_Database::get_setting('openai_horario_info', 'Segunda a Sábado, das 9h às 18h');
        $localizacao  = BarberPro_Database::get_setting('bot_msg_localizacao', '');

        // Inclui disponibilidade real de horários se fornecida pelo widget
        $slots_info = '';
        if ( ! empty($context['slots_disponiveis']) ) {
            $slots_info = "\nHORÁRIOS DISPONÍVEIS (próximos 7 dias):\n" . $context['slots_disponiveis'] . "\n";
        }

        $hint = ! empty( $context['widget_ia_hint'] ) ? "\nCONTEXTO DO WIDGET: " . $context['widget_ia_hint'] . "\n" : '';

        $link_block = $in_chat_only
            ? "
AGENDAMENTO:
- O cliente agenda **somente neste chat**, no fluxo guiado (serviço → data → horário → confirmação). Não existe página externa para marcar horário.
"
            : "
LINK DE AGENDAMENTO (site): {$agenda_url}
";

        $regras_link = $in_chat_only
            ? "- **Proibido** enviar links (http/https), URLs, \"acesse o site\", \"clique aqui\" ou qualquer endereço para agendar fora deste chat.\n"
            . "- Para marcar horário, diga que pode seguir as opções e perguntas do assistente **aqui mesmo** (nome, serviço, data e horário).\n"
            . "- Se não souber algo, seja honesto e ofereça continuar no chat ou o telefone/WhatsApp do salão — **sem** link de agendamento.\n"
            : "- Para agendar, guie com clareza e, se faltar dado, pergunte com gentileza\n"
            . "- Se não souber, diga com honestidade e ofereça o contato ou o link {$agenda_url}\n";

        return "Você é o assistente humano de {$nome_negocio} no chat do site. Fale como uma pessoa atenciosa da recepção — natural, empática, calorosa, nunca robótica. Varie um pouco o jeito de cumprimentar e de responder; evite frases prontas repetidas.

INFORMAÇÕES DO NEGÓCIO:
- Nome: {$nome_negocio}
- Horário de funcionamento: {$horario_info}
" . ($localizacao ? "- Localização: {$localizacao}\n" : '') . "
SERVIÇOS DISPONÍVEIS:
{$lista_svc}
" . ($lista_pro ? "PROFISSIONAIS / EQUIPE: {$lista_pro}\n" : '') . $slots_info . $link_block . $hint . "
TOM:
- Português do Brasil, informal mas respeitoso (\"você\"), com leve calor humano
- Emojis com moderação (no máximo 1–2 por mensagem quando fizer sentido)
- Se o cliente estiver ansioso ou com pressa, seja objetiva e acolhedora
- Se estiver descontraído, pode espelhar levemente o tom (sem exageros)

REGRAS:
- Máximo 3 parágrafos curtos por resposta
- Não invente horários — só os listados em HORÁRIOS DISPONÍVEIS
- Não invente preços ou serviços que não estão na lista
" . $regras_link;
    }

    private static function replace_prompt_vars( string $prompt, array $context ): string {
        $nome_negocio = BarberPro_Database::get_setting('module_barbearia_name', get_bloginfo('name'));
        $agenda_url   = BarberPro_Database::get_setting('booking_page_url', home_url('/agendamento/'));
        if ( ! empty( $context['booking_in_chat_only'] ) ) {
            $agenda_url = '';
        }

        return str_replace(
            ['{negocio}', '{link_agendamento}', '{nome_cliente}'],
            [$nome_negocio, $agenda_url, $context['nome'] ?? ''],
            $prompt
        );
    }

    /**
     * Remove URLs da resposta no widget quando o agendamento deve ficar só no chat.
     */
    public static function strip_urls_for_in_chat_booking( string $text ): string {
        $text = preg_replace( '#https?://[^\s\)\]\>\"\']+#iu', '', $text );
        $text = preg_replace( '#\[[^\]]*\]\(\s*#u', '', $text );
        return trim( preg_replace( "/\n{3,}/u", "\n\n", $text ) );
    }

    // =========================================================
    // USOS ESPECÍFICOS
    // =========================================================

    /**
     * Gera mensagem de reativação personalizada para um cliente.
     * Usada pela automação de reativação.
     */
    public static function generate_reactivation_message( string $client_name, int $days_away, string $last_service = '' ): ?string {
        if ( ! self::is_enabled() ) return null;

        $nome_negocio = BarberPro_Database::get_setting('module_barbearia_name', get_bloginfo('name'));
        $agenda_url   = BarberPro_Database::get_setting('booking_page_url', home_url('/agendamento/'));

        $prompt = "Crie uma mensagem de WhatsApp curta e amigável para reconquistar o cliente '{$client_name}' que não vem há {$days_away} dias."
                . ( $last_service ? " O último serviço foi: {$last_service}." : '' )
                . " Mencione {$nome_negocio}. Inclua o link de agendamento: {$agenda_url}."
                . " Seja natural, não pareça automático. Máximo 5 linhas. Use emojis com moderação.";

        return self::chat($prompt, [], "Você é um assistente de marketing para {$nome_negocio}. Escreva mensagens naturais em português.");
    }

    /**
     * Resposta livre para mensagens fora do fluxo de agendamento.
     * Usado no bot WhatsApp e widget chat quando o usuário faz perguntas gerais.
     */
    public static function free_response( string $message, array $context = [] ): ?string {
        if ( ! self::is_enabled() ) return null;
        if ( BarberPro_Database::get_setting('openai_free_response', '0') !== '1' ) {
            return null;
        }

        return self::chat($message, $context);
    }

    // =========================================================
    // HELPERS
    // =========================================================

    public static function is_enabled(): bool {
        return ! empty( BarberPro_Database::get_setting('openai_api_key', '') )
            && BarberPro_Database::get_setting('openai_ativo', '0') === '1';
    }

    /**
     * Testa a conexão com a API.
     */
    public static function test_connection(): array {
        $api_key = BarberPro_Database::get_setting('openai_api_key', '');
        if ( empty($api_key) ) return ['success' => false, 'message' => 'API Key não configurada.'];

        $resp = self::chat('Responda apenas: OK', [], 'Responda apenas com a palavra OK.');
        if ( $resp && str_contains(strtolower($resp), 'ok') ) {
            $model = self::resolve_model( BarberPro_Database::get_setting('openai_model', self::DEFAULT_MODEL) );
            return ['success' => true, 'message' => '✅ Conexão OpenAI OK! Modelo: ' . $model . ' — Resposta: ' . $resp];
        }
        return ['success' => false, 'message' => 'Falha na conexão. Verifique a API Key OpenAI (sk-… ou sk-proj-…) e o modelo (GPT-4o mini recomendado).'];
    }
}
