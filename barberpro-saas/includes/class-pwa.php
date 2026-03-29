<?php
/**
 * BarberPro – PWA (Progressive Web App)
 *
 * Transforma o sistema em um app instalável no celular.
 * - Manifesto JSON dinâmico com dados do negócio
 * - Service Worker para cache básico e funcionamento offline
 * - Banner de instalação automático no Android
 * - Instrução de instalação para iPhone (iOS)
 *
 * Configurado em: BarberPro → Configurações → 📱 App (PWA)
 *
 * @package BarberProSaaS
 */

if ( ! defined('ABSPATH') ) exit;

class BarberPro_PWA {

    public static function init(): void {
        if ( BarberPro_Database::get_setting('pwa_ativo','0') !== '1' ) return;

        // Manifesto e service worker — registra rewrites diretamente (não dentro de add_action('init'))
        self::register_rewrites();
        add_action( 'template_redirect', [ __CLASS__, 'handle_requests' ] );

        // Tags no <head> em TODAS as páginas
        add_action( 'wp_head',            [ __CLASS__, 'add_head_tags' ] );
        add_action( 'admin_head',         [ __CLASS__, 'add_head_tags' ] ); // admin também

        // Banner de instalação em TODAS as páginas do frontend
        add_action( 'wp_footer',          [ __CLASS__, 'render_install_banner' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    // ── URL Rewrite para manifest.json e sw.js ────────────────
    public static function register_rewrites(): void {
        add_rewrite_rule( '^manifest\.json$', 'index.php?barberpro_pwa=manifest', 'top' );
        add_rewrite_rule( '^sw\.js$',         'index.php?barberpro_pwa=sw',       'top' );
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'barberpro_pwa';
            return $vars;
        });
    }

    public static function handle_requests(): void {
        $req = get_query_var('barberpro_pwa');
        if ( ! $req ) return;

        if ( $req === 'manifest' ) {
            self::serve_manifest();
        } elseif ( $req === 'sw' ) {
            self::serve_sw();
        }
        exit;
    }

    // ── Manifesto JSON dinâmico ───────────────────────────────
    private static function serve_manifest(): void {
        $nome      = BarberPro_Database::get_setting('pwa_nome',
                     BarberPro_Database::get_setting('business_name', get_bloginfo('name')));
        $nome_curto= BarberPro_Database::get_setting('pwa_nome_curto', mb_substr($nome, 0, 12));
        $cor_tema  = BarberPro_Database::get_setting('pwa_cor_tema',   '#1a1a2e');
        $cor_fundo = BarberPro_Database::get_setting('pwa_cor_fundo',  '#1a1a2e');
        $icone_url = BarberPro_Database::get_setting('pwa_icone_url',  '');
        $start_url = BarberPro_Database::get_setting('pwa_start_url',  home_url('/'));
        $display   = BarberPro_Database::get_setting('pwa_display',    'standalone');

        // Ícones — usa o ícone configurado ou gera um placeholder
        $icons = [];
        if ( $icone_url ) {
            $icons = [
                [ 'src' => $icone_url, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ],
                [ 'src' => $icone_url, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ],
            ];
        } else {
            // Fallback: ícone SVG gerado dinamicamente
            $icons = [
                [ 'src' => home_url('/barberpro-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png' ],
                [ 'src' => home_url('/barberpro-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png' ],
            ];
        }

        $manifest = [
            'name'             => $nome,
            'short_name'       => $nome_curto,
            'description'      => 'Sistema de agendamento e gestão — ' . $nome,
            'start_url'        => $start_url,
            'scope'            => home_url('/'),
            'display'          => $display,
            'background_color' => $cor_fundo,
            'theme_color'      => $cor_tema,
            'lang'             => 'pt-BR',
            'orientation'      => 'portrait-primary',
            'categories'       => ['business', 'productivity'],
            'icons'            => $icons,
            'shortcuts'        => [
                [
                    'name'      => 'Agendamentos',
                    'url'       => $start_url . '#agendamentos',
                    'icons'     => [ [ 'src' => $icone_url ?: home_url('/barberpro-icon-192.png'), 'sizes' => '96x96' ] ],
                ],
            ],
        ];

        header('Content-Type: application/manifest+json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo wp_json_encode( $manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    }

    // ── Service Worker ────────────────────────────────────────
    private static function serve_sw(): void {
        $version    = BARBERPRO_VERSION;
        $cache_name = 'barberpro-v' . str_replace('.', '-', $version);
        $start_url  = BarberPro_Database::get_setting('pwa_start_url', home_url('/'));

        // URLs para pré-cachear (shell do app)
        $plugin_css = BARBERPRO_PLUGIN_URL . 'assets/css/app.css';
        $plugin_js  = BARBERPRO_PLUGIN_URL . 'assets/js/app.js';
        $precache = wp_json_encode([
            $start_url,
            $plugin_css,
            $plugin_js,
        ]);

        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo <<<JS
/* BarberPro Service Worker v{$version} */
var CACHE = '{$cache_name}';
var PRECACHE = {$precache};

// Instalação — pré-cacheia o shell
self.addEventListener('install', function(e) {
    e.waitUntil(
        caches.open(CACHE).then(function(c) {
            return c.addAll(PRECACHE).catch(function() {});
        }).then(function() {
            return self.skipWaiting();
        })
    );
});

// Ativação — limpa caches antigos
self.addEventListener('activate', function(e) {
    e.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.filter(function(k) { return k !== CACHE; })
                    .map(function(k) { return caches.delete(k); })
            );
        }).then(function() { return self.clients.claim(); })
    );
});

// Fetch — cache-first para assets, network-first para HTML/AJAX
self.addEventListener('fetch', function(e) {
    var url = e.request.url;

    // Ignora requisições AJAX, admin e não-GET
    if (e.request.method !== 'GET') return;
    if (url.indexOf('admin-ajax.php') !== -1) return;
    if (url.indexOf('wp-admin') !== -1) return;
    if (url.indexOf('wp-json') !== -1) return;

    // Assets estáticos: cache-first
    if (url.match(/\.(css|js|woff2?|png|jpg|jpeg|svg|ico)(\?|$)/)) {
        e.respondWith(
            caches.match(e.request).then(function(cached) {
                return cached || fetch(e.request).then(function(res) {
                    var clone = res.clone();
                    caches.open(CACHE).then(function(c) { c.put(e.request, clone); });
                    return res;
                }).catch(function() { return cached; });
            })
        );
        return;
    }

    // HTML: network-first com fallback para cache
    e.respondWith(
        fetch(e.request).then(function(res) {
            var clone = res.clone();
            if (res.status === 200) {
                caches.open(CACHE).then(function(c) { c.put(e.request, clone); });
            }
            return res;
        }).catch(function() {
            return caches.match(e.request).then(function(cached) {
                return cached || caches.match('{$start_url}');
            });
        })
    );
});
JS;
    }

    // ── Tags no <head> ────────────────────────────────────────
    public static function add_head_tags(): void {
        $cor_tema = BarberPro_Database::get_setting('pwa_cor_tema', '#1a1a2e');
        $icone    = BarberPro_Database::get_setting('pwa_icone_url', '');
        $nome     = esc_attr( BarberPro_Database::get_setting('pwa_nome',
                    BarberPro_Database::get_setting('business_name', get_bloginfo('name'))) );
        ?>
        <!-- BarberPro PWA -->
        <link rel="manifest" href="<?php echo esc_url(home_url('/manifest.json')); ?>">
        <meta name="theme-color" content="<?php echo esc_attr($cor_tema); ?>">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="<?php echo $nome; ?>">
        <?php if ( $icone ): ?>
        <link rel="apple-touch-icon" href="<?php echo esc_url($icone); ?>">
        <link rel="icon" type="image/png" href="<?php echo esc_url($icone); ?>">
        <?php endif; ?>
        <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function() {
                navigator.serviceWorker.register('<?php echo esc_js(home_url('/sw.js')); ?>', { scope: '<?php echo esc_js(home_url('/')); ?>' })
                    .catch(function(e) { /* silencioso */ });
            });
        }
        </script>
        <?php
    }

    // ── Assets do banner ─────────────────────────────────────
    public static function enqueue_assets(): void {
        wp_enqueue_style(
            'bp-pwa',
            BARBERPRO_PLUGIN_URL . 'assets/css/pwa.css',
            [],
            BARBERPRO_VERSION
        );
        wp_enqueue_script(
            'bp-pwa',
            BARBERPRO_PLUGIN_URL . 'assets/js/pwa.js',
            [],
            BARBERPRO_VERSION,
            true
        );
        wp_localize_script('bp-pwa', 'bpPwaData', [
            'nome'     => BarberPro_Database::get_setting('pwa_nome',
                          BarberPro_Database::get_setting('business_name', get_bloginfo('name'))),
            'icone'    => BarberPro_Database::get_setting('pwa_icone_url', ''),
            'cor'      => BarberPro_Database::get_setting('pwa_cor_tema', '#1a1a2e'),
            'ios_msg'  => BarberPro_Database::get_setting('pwa_ios_msg',
                          'Para instalar: toque em <strong>Compartilhar</strong> e depois <strong>Adicionar à Tela de Início</strong>'),
        ]);
    }

    // ── Banner HTML ───────────────────────────────────────────
    public static function render_install_banner(): void {
        $nome = BarberPro_Database::get_setting('pwa_nome',
                BarberPro_Database::get_setting('business_name', get_bloginfo('name')));
        $icone= BarberPro_Database::get_setting('pwa_icone_url', '');
        $ios_msg = BarberPro_Database::get_setting('pwa_ios_msg',
                   'Para instalar: toque em <strong>Compartilhar ↑</strong> e depois <strong>Adicionar à Tela de Início</strong>');
        ?>
        <!-- Banner Android (mostra automaticamente via JS) -->
        <div id="bpPwaAndroid" class="bp-pwa-banner" style="display:none">
            <div class="bp-pwa-banner-inner">
                <?php if ($icone): ?>
                <img src="<?php echo esc_url($icone); ?>" class="bp-pwa-icon" alt="<?php echo esc_attr($nome); ?>">
                <?php else: ?>
                <div class="bp-pwa-icon-placeholder">✂️</div>
                <?php endif; ?>
                <div class="bp-pwa-info">
                    <div class="bp-pwa-app-name"><?php echo esc_html($nome); ?></div>
                    <div class="bp-pwa-app-sub">Instalar na tela inicial</div>
                </div>
                <button id="bpPwaInstallBtn" class="bp-pwa-btn-install">Instalar</button>
                <button class="bp-pwa-btn-close" onclick="bpPwaDismiss()" aria-label="Fechar">✕</button>
            </div>
        </div>

        <!-- Banner iOS (instrução manual) -->
        <div id="bpPwaIOS" class="bp-pwa-ios-tip" style="display:none">
            <div class="bp-pwa-ios-inner">
                <button class="bp-pwa-btn-close" onclick="bpPwaDismissIOS()" style="float:right">✕</button>
                <div class="bp-pwa-ios-text"><?php echo wp_kses($ios_msg, ['strong'=>[],'br'=>[],'em'=>[]]); ?></div>
                <div class="bp-pwa-ios-arrow">▼</div>
            </div>
        </div>
        <?php
    }

    // ── Flush rewrites ao ativar/salvar ─────────────────────
    public static function flush_rewrites(): void {
        self::register_rewrites();
        flush_rewrite_rules();
    }

    /**
     * Chama automaticamente ao salvar configurações com PWA ativo.
     * Garante que manifest.json e sw.js respondem sem precisar ir em Links Permanentes.
     */
    public static function maybe_flush(): void {
        $flushed = get_option('barberpro_pwa_flushed', '');
        $version = BARBERPRO_VERSION . '_' . BarberPro_Database::get_setting('pwa_ativo','0');
        if ( $flushed !== $version ) {
            self::register_rewrites();
            flush_rewrite_rules();
            update_option('barberpro_pwa_flushed', $version);
        }
    }
}
