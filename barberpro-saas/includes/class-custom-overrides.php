<?php
/**
 * BarberPro – Override Loader (update-safe)
 *
 * Carrega customizações fora da pasta do plugin, em uploads, para não serem
 * perdidas em atualizações.
 *
 * Estrutura esperada:
 * - wp-content/uploads/barberpro-overrides/php/overrides.php
 * - wp-content/uploads/barberpro-overrides/assets/app.override.css
 * - wp-content/uploads/barberpro-overrides/assets/app.override.js
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Custom_Overrides {

    public static function init(): void {
        add_action( 'init', [ __CLASS__, 'load_php_override' ], 1 );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 999 );
    }

    public static function ensure_dirs(): void {
        $paths = self::paths();
        if ( ! empty( $paths['base_dir'] ) ) {
            wp_mkdir_p( $paths['base_dir'] );
            wp_mkdir_p( $paths['php_dir'] );
            wp_mkdir_p( $paths['assets_dir'] );
            self::ensure_stub_files( $paths );
        }
    }

    public static function load_php_override(): void {
        $paths = self::paths();
        $file  = $paths['php_file'];
        if ( ! $file || ! file_exists( $file ) ) return;

        // include_once para evitar cargas duplicadas em cenários edge.
        try {
            include_once $file;
        } catch ( \Throwable $e ) {
            error_log( '[BarberPro] Override PHP error: ' . $e->getMessage() );
        }
    }

    public static function enqueue_assets(): void {
        $paths = self::paths();

        // CSS override
        if ( ! empty( $paths['css_file'] ) && file_exists( $paths['css_file'] ) ) {
            $deps = wp_style_is( 'barberpro-app', 'registered' ) ? [ 'barberpro-app' ] : [];
            wp_enqueue_style(
                'barberpro-override-css',
                $paths['css_url'],
                $deps,
                (string) filemtime( $paths['css_file'] )
            );
        }

        // JS override
        if ( ! empty( $paths['js_file'] ) && file_exists( $paths['js_file'] ) ) {
            $deps = wp_script_is( 'barberpro-app', 'registered' ) ? [ 'barberpro-app' ] : [];
            wp_enqueue_script(
                'barberpro-override-js',
                $paths['js_url'],
                $deps,
                (string) filemtime( $paths['js_file'] ),
                true
            );
        }
    }

    /**
     * @return array<string,string>
     */
    private static function paths(): array {
        $uploads = wp_upload_dir();
        $base_dir = rtrim( (string) ( $uploads['basedir'] ?? '' ), '/\\' ) . '/barberpro-overrides';
        $base_url = rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/\\' ) . '/barberpro-overrides';

        return [
            'base_dir'   => $base_dir,
            'base_url'   => $base_url,
            'php_dir'    => $base_dir . '/php',
            'assets_dir' => $base_dir . '/assets',
            'php_file'   => $base_dir . '/php/overrides.php',
            'css_file'   => $base_dir . '/assets/app.override.css',
            'js_file'    => $base_dir . '/assets/app.override.js',
            'css_url'    => $base_url . '/assets/app.override.css',
            'js_url'     => $base_url . '/assets/app.override.js',
        ];
    }

    /**
     * Cria arquivos de exemplo apenas se ainda não existirem.
     *
     * @param array<string,string> $paths
     */
    private static function ensure_stub_files( array $paths ): void {
        $readme = $paths['base_dir'] . '/README.txt';
        if ( ! file_exists( $readme ) ) {
            @file_put_contents(
                $readme,
                "BarberPro Overrides (update-safe)\n\n" .
                "Arquivos suportados:\n" .
                "- php/overrides.php        (customizações PHP)\n" .
                "- assets/app.override.css  (customizações visuais)\n" .
                "- assets/app.override.js   (customizações JS)\n\n" .
                "Esses arquivos ficam fora do plugin e não são apagados em updates.\n"
            );
        }

        if ( ! file_exists( $paths['php_file'] ) ) {
            @file_put_contents(
                $paths['php_file'],
                "<?php\n// BarberPro custom overrides.\n// Exemplo:\n// add_filter('barberpro_whatsapp_custom_send', function(\$ok){ return \$ok; });\n"
            );
        }
        if ( ! file_exists( $paths['css_file'] ) ) {
            @file_put_contents( $paths['css_file'], "/* BarberPro app override CSS */\n" );
        }
        if ( ! file_exists( $paths['js_file'] ) ) {
            @file_put_contents( $paths['js_file'], "// BarberPro app override JS\n" );
        }
    }
}

