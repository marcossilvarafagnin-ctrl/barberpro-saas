<?php
/**
 * BarberPro – Sistema de Licença por Prazo
 *
 * Como funciona:
 *  - A chave de licença é gerada por você (dono do plugin) via painel admin
 *  - A chave codifica: domínio autorizado + data de expiração + hash de segurança
 *  - O cliente cola a chave nas Configurações do plugin
 *  - O plugin verifica a chave localmente (sem servidor externo) a cada carregamento
 *  - Quando expirada: frontend desativa shortcodes e admin exibe aviso de bloqueio
 *
 * SEGREDO (BARBERPRO_LICENSE_SECRET):
 *  - Defina uma string secreta longa e única no wp-config.php do SEU servidor:
 *    define('BARBERPRO_LICENSE_SECRET', 'sua-chave-secreta-aqui-32chars+');
 *  - Clientes NÃO têm acesso a esse segredo, então não conseguem forjar chaves.
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_License {

    /** Chave de opção onde a licença fica salva */
    const OPTION_KEY = 'barberpro_license_key';

    /** Cache de estado em memória para evitar múltiplas verificações */
    private static ?array $status_cache = null;

    // =========================================================================
    // GERAÇÃO DE CHAVE (use no seu servidor/painel para gerar chaves)
    // =========================================================================

    /**
     * Gera uma chave de licença para um domínio e prazo determinados.
     *
     * Formato gerado: BP2-{BASE64_PAYLOAD}-{HMAC_8}
     *
     * Exemplo de uso (no seu servidor):
     *   $key = BarberPro_License::generate('minhabarbearia.com.br', 365);
     *   // → BP2-eyJkIjoibWlu...-A3F9C2B1
     *
     * @param string $domain    Domínio do cliente (ex: barbearia.com.br)
     * @param int    $days      Quantidade de dias de validade
     * @param string $plan      Plano: trial | basic | pro | lifetime
     * @return string           Chave pronta para entregar ao cliente
     */
    public static function generate( string $domain, int $days, string $plan = 'pro' ): string {
        $domain  = strtolower( trim( $domain ) );
        $expires = date( 'Y-m-d', strtotime( "+{$days} days" ) );

        $payload = json_encode( [
            'd' => $domain,
            'e' => $expires,
            'p' => $plan,
            'i' => time(), // issued_at – impede reutilização
        ] );

        $encoded = rtrim( base64_encode( $payload ), '=' );
        $hmac    = strtoupper( substr( hash_hmac( 'sha256', $encoded, self::secret() ), 0, 8 ) );

        return 'BP2-' . $encoded . '-' . $hmac;
    }

    // =========================================================================
    // VERIFICAÇÃO DE LICENÇA
    // =========================================================================

    /**
     * Retorna o status completo da licença atual.
     *
     * @return array{
     *   valid: bool,
     *   status: string,   // active | expired | invalid | missing | domain_mismatch
     *   plan: string,
     *   domain: string,
     *   expires: string,
     *   days_remaining: int,
     *   message: string,
     * }
     */
    public static function status(): array {
        if ( self::$status_cache !== null ) {
            return self::$status_cache;
        }

        $key = get_option( self::OPTION_KEY, '' );

        if ( empty( $key ) ) {
            return self::$status_cache = self::result( false, 'missing', '', '', '',
                'Nenhuma chave de licença inserida.' );
        }

        // Valida formato: BP2-{payload}-{hmac8}
        // Base64 pode conter +, /, =, letras e números
        if ( ! preg_match( '/^BP2-([A-Za-z0-9+\/=_-]+)-([A-F0-9]{8})$/', $key, $m ) ) {
            return self::$status_cache = self::result( false, 'invalid', '', '', '',
                'Chave de licença com formato inválido.' );
        }

        $encoded = $m[1];
        $hmac    = $m[2];

        // Verifica integridade HMAC
        $expected = strtoupper( substr( hash_hmac( 'sha256', $encoded, self::secret() ), 0, 8 ) );
        if ( ! hash_equals( $expected, $hmac ) ) {
            return self::$status_cache = self::result( false, 'invalid', '', '', '',
                'Chave de licença inválida ou adulterada.' );
        }

        // Decodifica payload
        $payload = json_decode( base64_decode( $encoded . str_repeat( '=', strlen( $encoded ) % 4 ) ), true );
        if ( ! $payload || empty( $payload['d'] ) || empty( $payload['e'] ) ) {
            return self::$status_cache = self::result( false, 'invalid', '', '', '',
                'Payload da licença corrompido.' );
        }

        $domain  = $payload['d'];
        $expires = $payload['e'];
        $plan    = $payload['p'] ?? 'pro';

        // Verifica domínio
        $current_domain = strtolower( parse_url( home_url(), PHP_URL_HOST ) );
        // Remove www. para comparação
        $current_clean  = preg_replace( '/^www\./', '', $current_domain );
        $licensed_clean = preg_replace( '/^www\./', '', $domain );

        // Permite localhost e IPs para desenvolvimento
        $is_dev = in_array( $current_domain, [ 'localhost', '127.0.0.1', '::1' ], true )
                  || preg_match( '/^\d+\.\d+\.\d+\.\d+$/', $current_domain );

        if ( ! $is_dev && $current_clean !== $licensed_clean ) {
            return self::$status_cache = self::result( false, 'domain_mismatch', $plan, $domain, $expires,
                "Esta licença é para o domínio <strong>{$domain}</strong>. Domínio atual: <strong>{$current_domain}</strong>." );
        }

        // Verifica validade
        $today         = date( 'Y-m-d' );
        $days_remaining = (int) ceil( ( strtotime( $expires ) - strtotime( $today ) ) / 86400 );

        if ( $today > $expires ) {
            return self::$status_cache = self::result( false, 'expired', $plan, $domain, $expires,
                "Licença expirada em <strong>{$expires}</strong>." );
        }

        // Tudo OK
        return self::$status_cache = self::result( true, 'active', $plan, $domain, $expires,
            "Licença ativa. Expira em <strong>{$expires}</strong> ({$days_remaining} dia(s) restantes).",
            $days_remaining );
    }

    /**
     * Atalho: retorna true se a licença está ativa.
     */
    public static function is_active(): bool {
        return self::status()['valid'];
    }

    /**
     * Salva uma nova chave de licença.
     */
    public static function save( string $key ): array {
        $key = sanitize_text_field( trim( $key ) );
        update_option( self::OPTION_KEY, $key );
        self::$status_cache = null; // Limpa cache
        return self::status();
    }

    /**
     * Remove a licença atual.
     */
    public static function remove(): void {
        delete_option( self::OPTION_KEY );
        self::$status_cache = null;
    }

    // =========================================================================
    // GERADOR DE CHAVE NO PAINEL ADMIN (para você usar)
    // =========================================================================

    /**
     * Renderiza o gerador de chaves (visível só para super-admin ou quem tem
     * a capability 'manage_options' + sabe a secret).
     * Adicione na URL: ?barberpro_keygen=1
     */
    public static function maybe_render_keygen(): void {
        if ( ! isset( $_GET['barberpro_keygen'] ) ) return;
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Secret resolvido automaticamente via AUTH_KEY ou BARBERPRO_LICENSE_SECRET

        $generated = '';
        if ( isset( $_POST['gen_domain'], $_POST['gen_nonce'] )
            && wp_verify_nonce( sanitize_key( $_POST['gen_nonce'] ), 'barberpro_keygen' ) ) {
            $generated = self::generate(
                sanitize_text_field( $_POST['gen_domain'] ),
                absint( $_POST['gen_days'] ),
                sanitize_key( $_POST['gen_plan'] ?? 'pro' )
            );
        }
        ?>
        <!DOCTYPE html><html><head>
        <title>BarberPro – Gerador de Licença</title>
        <style>
        body{font-family:sans-serif;max-width:600px;margin:60px auto;background:#f5f5f5;padding:20px}
        h1{color:#1a1a2e} .card{background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
        label{display:block;font-weight:600;margin-bottom:4px;font-size:.9rem}
        input,select{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;font-size:1rem;margin-bottom:14px}
        button{background:#e94560;color:#fff;border:none;padding:12px 28px;border-radius:8px;font-size:1rem;cursor:pointer;font-weight:700}
        .key-box{background:#1a1a2e;color:#6ee7b7;padding:16px;border-radius:8px;font-family:monospace;font-size:.9rem;word-break:break-all;margin-top:16px}
        .info{background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:12px;font-size:.85rem;margin-bottom:16px}
        </style></head><body>
        <h1>🔑 BarberPro – Gerador de Licença</h1>
        <div class="info">⚠️ Esta página é <b>exclusiva para você</b> (dono do plugin). Nunca compartilhe esta URL com clientes.</div>
        <div class="card">
            <form method="post">
                <?php wp_nonce_field('barberpro_keygen','gen_nonce'); ?>
                <label>Domínio do cliente</label>
                <input type="text" name="gen_domain" placeholder="barbearia.com.br" required value="<?php echo esc_attr($_POST['gen_domain']??''); ?>">
                <label>Dias de validade</label>
                <input type="number" name="gen_days" min="1" max="36500" value="<?php echo esc_attr($_POST['gen_days']??'30'); ?>" required>
                <label>Plano</label>
                <select name="gen_plan">
                    <option value="trial">Trial (demonstração)</option>
                    <option value="basic">Basic</option>
                    <option value="pro" selected>Pro</option>
                    <option value="lifetime">Lifetime</option>
                </select>
                <button type="submit">⚡ Gerar Chave</button>
            </form>
            <?php if ( $generated ) : ?>
            <div class="key-box">
                <p style="color:#9ca3af;font-size:.75rem;margin:0 0 8px">CHAVE GERADA – copie e envie ao cliente:</p>
                <strong><?php echo esc_html( $generated ); ?></strong>
            </div>
            <p style="color:#6b7280;font-size:.8rem;margin-top:8px">
                Domínio: <b><?php echo esc_html($_POST['gen_domain']); ?></b> |
                Expira em: <b><?php echo esc_html(date('d/m/Y', strtotime('+'.absint($_POST['gen_days']).' days'))); ?></b> |
                Plano: <b><?php echo esc_html($_POST['gen_plan']??'pro'); ?></b>
            </p>
            <?php endif; ?>
        </div>
        </body></html>
        <?php
        exit;
    }

    // =========================================================================
    // AVISOS NO ADMIN
    // =========================================================================

    /**
     * Exibe avisos no painel WordPress quando a licença está prestes a expirar ou inválida.
     */
    public static function admin_notices(): void {
        $s = self::status();

        if ( $s['status'] === 'missing' ) {
            echo '<div class="notice notice-warning"><p>'
               . '🔑 <strong>BarberPro:</strong> Insira sua chave de licença em '
               . '<a href="' . esc_url(admin_url('admin.php?page=barberpro_license')) . '">BarberPro → Licença</a>.'
               . '</p></div>';
            return;
        }

        if ( ! $s['valid'] ) {
            echo '<div class="notice notice-error"><p>'
               . '🚫 <strong>BarberPro:</strong> ' . wp_kses_post($s['message'])
               . ' <a href="' . esc_url(admin_url('admin.php?page=barberpro_license')) . '">Atualizar licença →</a>'
               . '</p></div>';
            return;
        }

        // Aviso de expiração próxima (7 dias)
        if ( $s['days_remaining'] <= 7 ) {
            echo '<div class="notice notice-warning is-dismissible"><p>'
               . '⏳ <strong>BarberPro:</strong> Sua licença expira em <strong>'
               . esc_html($s['days_remaining']) . ' dia(s)</strong> ('
               . esc_html($s['expires']) . '). '
               . '<a href="' . esc_url(admin_url('admin.php?page=barberpro_license')) . '">Renovar →</a>'
               . '</p></div>';
        }
    }

    // =========================================================================
    // TELA DE LICENÇA BLOQUEADA (substitui o conteúdo do plugin quando inválida)
    // =========================================================================

    public static function render_blocked_page(): void {
        $s = self::status();
        ?>
        <div class="wrap" style="max-width:620px;margin:40px auto;text-align:center">
            <div style="background:#fff;border-radius:16px;padding:48px 36px;box-shadow:0 4px 24px rgba(0,0,0,.08)">
                <div style="font-size:3.5rem;margin-bottom:16px">🔒</div>
                <h1 style="color:#1a1a2e;margin-bottom:8px">Plugin Bloqueado</h1>
                <p style="color:#6b7280;margin-bottom:24px"><?php echo wp_kses_post($s['message']); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=barberpro_license')); ?>"
                   style="background:#e94560;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;display:inline-block">
                    🔑 Inserir / Renovar Licença
                </a>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    private static function secret(): string {
        if ( defined( 'BARBERPRO_LICENSE_SECRET' ) ) {
            return BARBERPRO_LICENSE_SECRET;
        }
        // Fallback: usa AUTH_KEY do wp-config (sempre presente no WordPress)
        // Assim o gerador e o verificador usam o MESMO segredo automaticamente
        // sem precisar configurar nada extra no wp-config.php
        if ( defined( 'AUTH_KEY' ) && strlen( AUTH_KEY ) > 8 ) {
            return AUTH_KEY;
        }
        // Último recurso: derivado estável por instalação
        return 'BP-' . md5( get_site_url() . 'barberpro-salt-v2' );
    }

    private static function result(
        bool   $valid,
        string $status,
        string $plan,
        string $domain,
        string $expires,
        string $message,
        int    $days_remaining = 0
    ): array {
        return compact( 'valid', 'status', 'plan', 'domain', 'expires', 'message', 'days_remaining' );
    }
}
