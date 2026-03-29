<?php
/**
 * BarberPro – Sistema de Backup
 * 
 * Funcionalidades:
 *  1. Exportação manual (JSON completo ou CSV por tabela)
 *  2. Backup automático agendado (via WP-Cron, salva no servidor)
 *  3. Backup dos arquivos do plugin (ZIP)
 *  4. Restauração a partir de arquivo JSON
 *  5. Listagem e exclusão de backups salvos
 *
 * @package BarberProSaaS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Backup {

    /** Pasta de backups dentro do wp-content */
    const BACKUP_DIR_NAME = 'barberpro-backups';

    /** Opção no banco para configurações de backup automático */
    const OPTION_SCHEDULE = 'barberpro_backup_schedule';
    const OPTION_LAST     = 'barberpro_backup_last_run';
    const CRON_HOOK       = 'barberpro_auto_backup';

    // ── Init ──────────────────────────────────────────────────────
    public static function init(): void {
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_auto_backup' ] );
    }

    // ── Diretório de backups ──────────────────────────────────────
    public static function backup_dir(): string {
        $dir = WP_CONTENT_DIR . '/' . self::BACKUP_DIR_NAME;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            // Protege contra acesso direto
            file_put_contents( $dir . '/.htaccess', "Options -Indexes\ndeny from all\n" );
            file_put_contents( $dir . '/index.php', '<?php // silence' );
        }
        return $dir;
    }

    public static function backup_url(): string {
        return WP_CONTENT_URL . '/' . self::BACKUP_DIR_NAME;
    }

    // ─────────────────────────────────────────────────────────────
    // 1. EXPORTAÇÃO MANUAL
    // ─────────────────────────────────────────────────────────────

    /**
     * Gera array completo com todos os dados do plugin.
     */
    public static function collect_data( array $opts = [] ): array {
        global $wpdb;

        $include_db       = $opts['db']       ?? true;
        $include_settings = $opts['settings'] ?? true;

        $data = [
            'meta' => [
                'version'      => BARBERPRO_VERSION,
                'site_url'     => get_site_url(),
                'exported_at'  => current_time( 'mysql' ),
                'exported_by'  => wp_get_current_user()->display_name ?? 'system',
                'tables'       => [],
            ],
        ];

        if ( $include_settings ) {
            $data['settings'] = $wpdb->get_results(
                "SELECT option_key, option_value FROM {$wpdb->prefix}barber_settings",
                ARRAY_A
            ) ?: [];
        }

        if ( $include_db ) {
            $tables = [
                'companies'          => "{$wpdb->prefix}barber_companies",
                'services'           => "{$wpdb->prefix}barber_services",
                'service_variants'   => "{$wpdb->prefix}barber_service_variants",
                'professionals'      => "{$wpdb->prefix}barber_professionals",
                'bookings'           => "{$wpdb->prefix}barber_bookings",
                'finance'            => "{$wpdb->prefix}barber_finance",
                'products'           => "{$wpdb->prefix}barber_products",
                'stock_moves'        => "{$wpdb->prefix}barber_stock_moves",
                'bar_comandas'       => "{$wpdb->prefix}barber_bar_comandas",
                'bar_comanda_items'  => "{$wpdb->prefix}barber_bar_comanda_items",
                'bar_payments'       => "{$wpdb->prefix}barber_bar_payments",
            ];

            foreach ( $tables as $key => $table ) {
                // Verifica se a tabela existe
                $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                if ( ! $exists ) continue;

                $rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: [];
                $data['tables'][ $key ] = $rows;
                $data['meta']['tables'][ $key ] = count( $rows );
            }
        }

        return $data;
    }

    /**
     * Salva backup como arquivo JSON no servidor.
     * Retorna [ 'file' => path, 'filename' => name, 'size' => bytes ]
     */
    public static function save_to_file( array $data, string $prefix = 'manual' ): array {
        $dir      = self::backup_dir();
        $filename = sprintf(
            'barberpro-%s-%s.json',
            $prefix,
            date( 'Y-m-d_H-i-s', current_time('timestamp') )
        );
        $filepath = $dir . '/' . $filename;
        $json     = json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );

        file_put_contents( $filepath, $json );

        return [
            'file'     => $filepath,
            'filename' => $filename,
            'size'     => strlen( $json ),
        ];
    }

    /**
     * Gera ZIP dos arquivos do plugin.
     */
    public static function backup_plugin_files(): array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'error' => 'ZipArchive não disponível no servidor.' ];
        }
        $dir      = self::backup_dir();
        $filename = 'barberpro-plugin-' . date( 'Y-m-d_H-i-s', current_time('timestamp') ) . '.zip';
        $filepath = $dir . '/' . $filename;
        $src      = BARBERPRO_PLUGIN_DIR;

        $zip = new ZipArchive();
        if ( $zip->open( $filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            return [ 'error' => 'Não foi possível criar o arquivo ZIP.' ];
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $files as $file ) {
            if ( ! $file->isDir() ) {
                $rel = substr( $file->getRealPath(), strlen( $src ) );
                $zip->addFile( $file->getRealPath(), 'barberpro-saas/' . ltrim( $rel, '/' ) );
            }
        }
        $zip->close();

        return [
            'file'     => $filepath,
            'filename' => $filename,
            'size'     => filesize( $filepath ),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // 2. BACKUP AUTOMÁTICO (WP-CRON)
    // ─────────────────────────────────────────────────────────────

    public static function get_schedule(): array {
        return get_option( self::OPTION_SCHEDULE, [
            'enabled'    => false,
            'frequency'  => 'daily',   // daily | weekly | monthly
            'keep'       => 7,          // quantos backups manter
            'include_db' => true,
            'include_settings' => true,
        ] );
    }

    public static function save_schedule( array $cfg ): void {
        update_option( self::OPTION_SCHEDULE, $cfg );
        // Re-agenda o cron
        wp_clear_scheduled_hook( self::CRON_HOOK );
        if ( ! empty( $cfg['enabled'] ) ) {
            $recurrence = $cfg['frequency'] === 'weekly'  ? 'weekly'
                        : ( $cfg['frequency'] === 'monthly' ? 'monthly' : 'daily' );
            // Adiciona recurrences se não existirem
            add_filter( 'cron_schedules', function( $schedules ) {
                if ( ! isset( $schedules['monthly'] ) ) {
                    $schedules['monthly'] = [ 'interval' => 30 * DAY_IN_SECONDS, 'display' => 'Mensal' ];
                }
                return $schedules;
            });
            wp_schedule_event( time(), $recurrence, self::CRON_HOOK );
        }
    }

    public static function run_auto_backup(): void {
        $cfg  = self::get_schedule();
        $data = self::collect_data([
            'db'       => $cfg['include_db']       ?? true,
            'settings' => $cfg['include_settings'] ?? true,
        ]);
        self::save_to_file( $data, 'auto' );
        update_option( self::OPTION_LAST, current_time('mysql') );
        self::prune_old_backups( (int)($cfg['keep'] ?? 7) );
    }

    /** Remove backups antigos, mantém os N mais recentes de cada tipo. */
    private static function prune_old_backups( int $keep ): void {
        $dir   = self::backup_dir();
        $files = glob( $dir . '/barberpro-auto-*.json' );
        if ( ! $files ) return;
        rsort( $files ); // mais recentes primeiro
        $to_delete = array_slice( $files, $keep );
        foreach ( $to_delete as $f ) @unlink( $f );
    }

    // ─────────────────────────────────────────────────────────────
    // 3. RESTAURAÇÃO
    // ─────────────────────────────────────────────────────────────

    /**
     * Restaura dados a partir de array decodificado de JSON.
     * Retorna [ 'restored' => [ table => count ], 'errors' => [] ]
     */
    public static function restore( array $data ): array {
        global $wpdb;
        $result = [ 'restored' => [], 'errors' => [], 'settings' => 0 ];

        // Restaura configurações
        if ( ! empty( $data['settings'] ) ) {
            foreach ( $data['settings'] as $row ) {
                $wpdb->replace( "{$wpdb->prefix}barber_settings", [
                    'option_key'   => $row['option_key'],
                    'option_value' => $row['option_value'],
                ] );
            }
            $result['settings'] = count( $data['settings'] );
        }

        // Mapa de tabelas e colunas PK
        $table_map = [
            'companies'         => [ 'table' => "{$wpdb->prefix}barber_companies",         'pk' => 'id' ],
            'services'          => [ 'table' => "{$wpdb->prefix}barber_services",          'pk' => 'id' ],
            'service_variants'  => [ 'table' => "{$wpdb->prefix}barber_service_variants",  'pk' => 'id' ],
            'professionals'     => [ 'table' => "{$wpdb->prefix}barber_professionals",     'pk' => 'id' ],
            'bookings'          => [ 'table' => "{$wpdb->prefix}barber_bookings",          'pk' => 'id' ],
            'finance'           => [ 'table' => "{$wpdb->prefix}barber_finance",           'pk' => 'id' ],
            'products'          => [ 'table' => "{$wpdb->prefix}barber_products",          'pk' => 'id' ],
            'stock_moves'       => [ 'table' => "{$wpdb->prefix}barber_stock_moves",       'pk' => 'id' ],
            'bar_comandas'      => [ 'table' => "{$wpdb->prefix}barber_bar_comandas",      'pk' => 'id' ],
            'bar_comanda_items' => [ 'table' => "{$wpdb->prefix}barber_bar_comanda_items", 'pk' => 'id' ],
            'bar_payments'      => [ 'table' => "{$wpdb->prefix}barber_bar_payments",      'pk' => 'id' ],
        ];

        if ( ! empty( $data['tables'] ) ) {
            foreach ( $data['tables'] as $key => $rows ) {
                if ( ! isset( $table_map[ $key ] ) || empty( $rows ) ) continue;
                $table = $table_map[ $key ]['table'];

                // Verifica se tabela existe
                if ( ! $wpdb->get_var("SHOW TABLES LIKE '{$table}'") ) {
                    $result['errors'][] = "Tabela {$table} não existe — execute a instalação primeiro.";
                    continue;
                }

                $count = 0;
                foreach ( $rows as $row ) {
                    $r = $wpdb->replace( $table, $row );
                    if ( $r !== false ) $count++;
                }
                $result['restored'][ $key ] = $count;
            }
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    // 4. LISTAGEM DE BACKUPS SALVOS
    // ─────────────────────────────────────────────────────────────

    public static function list_backups(): array {
        $dir   = self::backup_dir();
        $files = array_merge(
            glob( $dir . '/barberpro-*.json' ) ?: [],
            glob( $dir . '/barberpro-*.zip'  ) ?: []
        );
        if ( ! $files ) return [];

        $list = [];
        foreach ( $files as $f ) {
            $name  = basename( $f );
            $size  = filesize( $f );
            $mtime = filemtime( $f );
            $type  = str_ends_with( $name, '.zip' ) ? 'plugin'
                   : ( str_contains( $name, '-auto-' ) ? 'auto' : 'manual' );
            $list[] = [
                'filename' => $name,
                'size'     => $size,
                'size_fmt' => self::fmt_size( $size ),
                'date'     => date_i18n( 'd/m/Y H:i', $mtime ),
                'mtime'    => $mtime,
                'type'     => $type,
                'ext'      => pathinfo( $name, PATHINFO_EXTENSION ),
            ];
        }
        // Mais recentes primeiro
        usort( $list, function( $a, $b ) { return $b['mtime'] - $a['mtime']; } );
        return $list;
    }

    public static function delete_backup( string $filename ): bool {
        // Sanitiza: apenas nome de arquivo, sem diretórios
        $filename = basename( $filename );
        if ( ! preg_match( '/^barberpro-[\w\-\.]+\.(json|zip)$/', $filename ) ) return false;
        $path = self::backup_dir() . '/' . $filename;
        return file_exists( $path ) && @unlink( $path );
    }

    public static function get_backup_path( string $filename ): ?string {
        $filename = basename( $filename );
        if ( ! preg_match( '/^barberpro-[\w\-\.]+\.(json|zip)$/', $filename ) ) return null;
        $path = self::backup_dir() . '/' . $filename;
        return file_exists( $path ) ? $path : null;
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public static function fmt_size_public( int $bytes ): string {
        return self::fmt_size($bytes);
    }

    private static function fmt_size( int $bytes ): string {
        if ( $bytes >= 1048576 ) return round( $bytes / 1048576, 1 ) . ' MB';
        if ( $bytes >= 1024 )    return round( $bytes / 1024, 1 ) . ' KB';
        return $bytes . ' B';
    }
}

// Init cron hook
BarberPro_Backup::init();
