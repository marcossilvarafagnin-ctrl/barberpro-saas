<?php
/**
 * BarberPro App – Handler AJAX principal
 *
 * Esta classe é o ponto central de entrada para todas as requisições AJAX
 * do painel SPA. A lógica de negócio está dividida em traits temáticos:
 *
 *  ajax/trait-sections-dashboard.php  → Dashboard e Ganhos
 *  ajax/trait-sections-bookings.php   → Agenda, Kanban, Serviços, Profissionais
 *  ajax/trait-sections-finance.php    → Financeiro (seção)
 *  ajax/trait-sections-bar.php        → Bar (seções: caixa, comandas, produtos, estoque, admin)
 *  ajax/trait-sections-system.php     → Backup, Licença, Configurações (seções)
 *  ajax/trait-actions-operations.php  → Actions: agendamentos, profissionais, serviços, bar, financeiro
 *  ajax/trait-actions-system.php      → Actions: backup, licença, configurações + utilitários
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Carrega os traits ─────────────────────────────────────────────
require_once __DIR__ . '/ajax/trait-sections-dashboard.php';
require_once __DIR__ . '/ajax/trait-sections-bookings.php';
require_once __DIR__ . '/ajax/trait-sections-finance.php';
require_once __DIR__ . '/ajax/trait-sections-bar.php';
require_once __DIR__ . '/ajax/trait-sections-system.php';
require_once __DIR__ . '/ajax/trait-actions-operations.php';
require_once __DIR__ . '/ajax/trait-actions-system.php';
require_once __DIR__ . '/ajax/trait-sections-loja.php';
require_once __DIR__ . '/ajax/trait-sections-clients.php';
require_once __DIR__ . '/class-clients.php';

class BarberPro_App_Ajax {

    use BP_Sections_Dashboard;
    use BP_Sections_Bookings;
    use BP_Sections_Finance;
    use BP_Sections_Bar;
    use BP_Sections_System;
    use BP_Sections_Loja;
    use BP_Sections_Clients;
    use BP_Actions_Operations;
    use BP_Actions_System;

    // ── Registro de hooks AJAX ────────────────────────────────────
    public function __construct() {
        // Login / Logout (público)
        add_action( 'wp_ajax_bp_app_login',         [ $this, 'handle_login' ] );
        add_action( 'wp_ajax_nopriv_bp_app_login',  [ $this, 'handle_login' ] );
        add_action( 'wp_ajax_bp_app_logout',        [ $this, 'handle_logout' ] );
        add_action( 'wp_ajax_nopriv_bp_app_logout', [ $this, 'handle_logout' ] );

        // Diagnóstico (debug)
        add_action( 'wp_ajax_bp_diag',        [ $this, 'handle_diag' ] );
        add_action( 'wp_ajax_nopriv_bp_diag', [ $this, 'handle_diag' ] );

        // Nonce refresh
        add_action( 'wp_ajax_bp_refresh_nonce',        [ $this, 'handle_refresh_nonce' ] );
        add_action( 'wp_ajax_nopriv_bp_refresh_nonce', [ $this, 'handle_refresh_nonce' ] );

        // Sections (requer login)
        add_action( 'wp_ajax_bp_app_section', [ $this, 'handle_section' ] );

        // Actions (POST dentro das sections)
        add_action( 'wp_ajax_bp_app_action',  [ $this, 'handle_action' ] );
        add_action( 'wp_ajax_bp_loja_status', 'barberpro_ajax_loja_status' );
    }

    // ── Auth helpers ──────────────────────────────────────────────
    private function verify_nonce(): void {
        $nonce = sanitize_text_field( $_POST['nonce'] ?? $_GET['nonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'bp_app' ) ) {
            wp_send_json_error([
                'message' => 'Nonce inválido. Recarregue a página.',
                'nonce'   => wp_create_nonce('bp_app'),
                'code'    => 'invalid_nonce',
            ], 403);
        }
    }

    private function require_login(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error(['message' => 'Não autenticado.'], 401);
        }
    }

    private function user_can_access( WP_User $user ): bool {
        return user_can($user, 'administrator')
            || user_can($user, 'barberpro_manager')
            || user_can($user, 'barberpro_manage_bookings')
            || user_can($user, 'barberpro_view_finance');
    }

    private function role_label( WP_User $user ): string {
        if ( user_can($user, 'administrator') )          return 'Administrador';
        if ( user_can($user, 'barberpro_manager') )      return 'Gerente';
        if ( user_can($user, 'barberpro_view_finance') ) return 'Financeiro';
        return 'Operador';
    }

    // ── Login ─────────────────────────────────────────────────────
    public function handle_login(): void {
        $ip      = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
        $key     = 'bp_login_attempts_' . md5( $ip );
        $attempts = (int) get_transient( $key );
        if ( $attempts >= 10 ) {
            wp_send_json_error(['message' => 'Muitas tentativas. Tente novamente em 1 hora.']);
        }
        set_transient( $key, $attempts + 1, HOUR_IN_SECONDS );

        $username = sanitize_user( wp_unslash( $_POST['username'] ?? '' ) );
        $password = wp_unslash( $_POST['password'] ?? '' );

        if ( empty($username) || empty($password) ) {
            wp_send_json_error(['message' => 'Preencha usuário e senha.']);
        }

        $user = wp_authenticate( $username, $password );
        if ( is_wp_error($user) ) {
            wp_send_json_error(['message' => 'Usuário ou senha incorretos.']);
        }

        if ( ! $this->user_can_access($user) ) {
            wp_send_json_error(['message' => 'Seu usuário não tem acesso ao painel.']);
        }

        wp_set_auth_cookie( $user->ID, true );
        wp_set_current_user( $user->ID );

        $fresh_nonce = wp_create_nonce( 'bp_app' );

        wp_send_json_success([
            'nonce' => $fresh_nonce,
            'user'  => [
                'logged_in'  => true,
                'id'         => $user->ID,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                'role_label' => $this->role_label($user),
            ],
        ]);
    }

    // ── Logout ────────────────────────────────────────────────────
    public function handle_logout(): void {
        wp_logout();
        wp_send_json_success();
    }

    // ── Diagnóstico ───────────────────────────────────────────────
    public function handle_diag(): void {
        global $wpdb;
        $tables = [];
        $needed = [
            'barber_services',
            'barber_professionals',
            'barber_bookings',
            'barber_finance',
            'barber_products',
            'barber_bar_comandas',
            'barber_clients',
            'barber_message_queue',
        ];
        foreach ( $needed as $t ) {
            $tables[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
        }
        $test_insert = null;
        $test_error  = null;
        if ( $tables['barber_services'] ) {
            $r = $wpdb->insert( "{$wpdb->prefix}barber_services", [
                'company_id' => 1,
                'name'       => '__TESTE_DIAG__',
                'price'      => 0,
                'duration'   => 30,
                'status'     => 'inactive',
                'created_at' => current_time( 'mysql' ),
            ] );
            $test_insert = $r;
            $test_error  = $wpdb->last_error;
            if ( $r ) {
                $wpdb->delete( "{$wpdb->prefix}barber_services", [ 'name' => '__TESTE_DIAG__' ] );
            }
        }

        // Diagnóstico de movimento DB v3.0 — escrita na carteira de clientes (Carteira + recorrência)
        $movement_clients_count = null;
        $movement_clients_write = null;
        $movement_clients_err   = null;
        if ( ! empty( $tables['barber_clients'] ) ) {
            $movement_clients_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}barber_clients" );
            $diag_phone = '00000009999';
            $wpdb->delete( "{$wpdb->prefix}barber_clients", [ 'phone' => $diag_phone, 'company_id' => 1 ] );
            $rw = $wpdb->insert( "{$wpdb->prefix}barber_clients", [
                'company_id' => 1,
                'name'       => '__BP_MOVEMENT_DIAG__',
                'phone'      => $diag_phone,
                'tipo'       => 'normal',
                'created_at' => current_time( 'mysql' ),
            ] );
            $movement_clients_write = (bool) $rw;
            $movement_clients_err   = $wpdb->last_error ?: '';
            if ( $rw ) {
                $wpdb->delete( "{$wpdb->prefix}barber_clients", [ 'phone' => $diag_phone, 'company_id' => 1 ] );
            }
        }

        $db_ver_installed = get_option( 'barberpro_db_version', '' );
        $db_ver_expected  = defined( 'BARBERPRO_DB_VERSION' ) ? BARBERPRO_DB_VERSION : '';

        wp_send_json_success( [
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'plugin_version' => defined( 'BARBERPRO_VERSION' ) ? BARBERPRO_VERSION : '?',
            'logged_in'      => is_user_logged_in(),
            'user_id'        => get_current_user_id(),
            'license_active' => BarberPro_License::is_active(),
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'tables'         => $tables,
            'test_insert'    => $test_insert,
            'test_error'     => $test_error ?: 'nenhum',
            'wpdb_prefix'    => $wpdb->prefix,
            'nonce_test'     => wp_create_nonce( 'bp_app' ),
            'time'           => current_time( 'mysql' ),
            'db_version'     => [
                'installed' => is_string( $db_ver_installed ) ? $db_ver_installed : '',
                'expected'  => is_string( $db_ver_expected ) ? $db_ver_expected : '',
                'aligned'   => version_compare( (string) $db_ver_installed, (string) $db_ver_expected, '>=' ),
            ],
            'movement'       => [
                'barber_clients_rows' => $movement_clients_count,
                'clients_write_ok'    => $movement_clients_write,
                'clients_write_error' => $movement_clients_err ?: 'nenhum',
            ],
            'cron_recorrencia_scheduled' => (bool) wp_next_scheduled( 'barberpro_daily_reminders' ),
        ] );
    }

    public function handle_refresh_nonce(): void {
        wp_send_json_success(['nonce' => wp_create_nonce('bp_app')]);
    }

    // ── Section loader ────────────────────────────────────────────
    public function handle_section(): void {
        $this->verify_nonce();
        $this->require_login();

        $section = sanitize_key( $_POST['section'] ?? '' );
        $tab     = sanitize_key( $_POST['tab']     ?? '' );

        ob_start();
        $this->render_section( $section, $tab );
        $html = ob_get_clean();

        wp_send_json_success( [ 'html' => $html ] );
    }

    private function render_section( string $section, string $tab ): void {
        switch ( $section ) {
            case 'dashboard':          $this->section_dashboard(); break;
            case 'ganhos':             $this->section_ganhos($tab); break;
            case 'barbearia_agenda':   $this->section_agenda(1); break;
            case 'barbearia_kanban':   $this->section_kanban(1); break;
            case 'barbearia_servicos': $this->section_servicos(1); break;
            case 'barbearia_profis':   $this->section_profissionais(1); break;
            case 'barbearia_finance':  $this->section_finance(1); break;
            case 'lavacar_agenda':     $this->section_agenda(2); break;
            case 'lavacar_kanban':     $this->section_kanban(2); break;
            case 'lavacar_servicos':   $this->section_servicos(2); break;
            case 'lavacar_profis':     $this->section_profissionais(2); break;
            case 'lavacar_finance':    $this->section_finance(2); break;
            case 'bar_comandas':       $this->section_bar_comandas(); break;
            case 'bar_produtos':       $this->section_bar_produtos(); break;
            case 'bar_estoque':        $this->section_bar_estoque(); break;
            case 'bar_caixa':          $this->section_bar_caixa(); break;
            case 'bar_admin':          $this->section_bar_admin($tab); break;
            case 'barbearia_clientes':      $this->section_clientes(1); break;
            case 'lavacar_clientes':         $this->section_clientes(2); break;
            case 'barbearia_loja_produtos': $this->section_loja_produtos(1); break;
            case 'barbearia_loja_pedidos':  $this->section_loja_pedidos(1);  break;
            case 'lavacar_loja_produtos':   $this->section_loja_produtos(2); break;
            case 'lavacar_loja_pedidos':    $this->section_loja_pedidos(2);  break;
            case 'licenca':            $this->section_licenca(); break;
            case 'backup':             $this->section_backup(); break;
            case 'settings':           $this->section_settings(); break;
            default:                   $this->section_dashboard();
        }
    }

    // ── Action dispatcher ─────────────────────────────────────────
    public function handle_action(): void {
        $this->verify_nonce();
        $this->require_login();

        global $wpdb;
        $wpdb->show_errors();

        $sub = sanitize_key($_POST['sub'] ?? '');
        switch ($sub) {
            // ── Agendamentos ──────────────────────────────────
            case 'update_booking_status': $this->action_update_booking_status(); break;
            case 'get_new_booking_form':  $this->action_get_booking_form();      break;
            case 'get_admin_slots':       $this->action_get_admin_slots();       break;
            case 'save_booking':          $this->action_save_booking();          break;

            // ── Serviços ──────────────────────────────────────
            case 'get_service_form':      $this->action_get_service_form();      break;
            case 'save_service':          $this->action_save_service();          break;

            // ── Profissionais ─────────────────────────────────
            case 'get_pro_form':          $this->action_get_pro_form();          break;
            case 'save_pro':              $this->action_save_pro();              break;

            // ── Bar ───────────────────────────────────────────
            case 'open_bar_comanda':      $this->action_open_bar_comanda();      break;
            case 'get_bar_comanda_view':  $this->action_get_bar_comanda_view();  break;
            case 'caixa_nova_comanda':    $this->action_caixa_nova_comanda();    break;
            case 'bar_delete_product':    $this->action_bar_delete_product();    break;
            case 'bar_get_finance':       $this->action_bar_get_finance();       break;
            case 'bar_add_item':          $this->action_bar_add_item();          break;
            case 'bar_remove_item':       $this->action_bar_remove_item();       break;
            case 'bar_pay_comanda':       $this->action_bar_pay_comanda();       break;
            case 'bar_cancel_comanda':    $this->action_bar_cancel_comanda();    break;
            case 'bar_aguardando_pag':    $this->action_bar_aguardando_pag();    break;
            case 'bar_reabrir_comanda':   $this->action_bar_reabrir_comanda();   break;
            case 'get_bar_comanda_receipt': $this->action_bar_receipt();         break;
            case 'get_escpos_receipt':      $this->action_get_escpos_receipt();  break;
            case 'get_printer_settings':    $this->action_get_printer_settings(); break;

            // ── Produtos / Estoque ────────────────────────────
            case 'get_product_form':      $this->action_get_product_form();      break;
            case 'save_product':          $this->action_save_product();          break;
            case 'stock_move':            $this->action_stock_move();            break;

            // ── Financeiro ────────────────────────────────────
            case 'get_finance_form':      $this->action_get_finance_form();      break;
            case 'save_finance':          $this->action_save_finance();          break;
            case 'delete_finance':        $this->action_delete_finance();        break;
            case 'pagar_finance':         $this->action_pagar_finance();         break;
            case 'save_finance_cat':      $this->action_save_finance_cat();      break;
            case 'delete_finance_cat':    $this->action_delete_finance_cat();    break;

            // ── Toggles (status) ──────────────────────────────
            case 'toggle_service':        $this->action_toggle_status('service');  break;
            case 'toggle_pro':            $this->action_toggle_status('pro');      break;
            case 'toggle_product':        $this->action_toggle_status('product');  break;

            // ── Sistema ───────────────────────────────────────
            case 'activate_license':      $this->action_activate_license();      break;
            case 'save_settings':         $this->action_save_settings();         break;
            case 'backup_export':         $this->action_backup_export();         break;
            case 'backup_restore':        $this->action_backup_restore();        break;
            case 'backup_auto_save':      $this->action_backup_auto_save();      break;
            case 'backup_delete':         $this->action_backup_delete();         break;
            case 'save_client':           $this->action_save_client();           break;
            case 'get_loja_produto_form': $this->action_get_loja_produto_form(); break;
            case 'save_loja_produto':     $this->action_save_loja_produto();     break;
            case 'get_loja_pedido_detalhe': $this->action_get_loja_pedido_detalhe(); break;

            default: wp_send_json_error(['message' => 'Ação desconhecida: ' . $sub]);
        }
    }
}
