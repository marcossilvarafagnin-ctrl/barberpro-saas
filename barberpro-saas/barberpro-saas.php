<?php
/**
 * Plugin Name: BarberPro SaaS Manager
 * Plugin URI:  https://barberpro.com.br
 * Description: Sistema completo de gestão para barbearias, clínicas, salões e studios. SaaS-ready.
 * Version:     3.8.8
 * Author:      BarberPro
 * License:     GPL-2.0+
 * Text Domain: barberpro-saas
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'BARBERPRO_VERSION', '3.8.8' );
define( 'BARBERPRO_PLUGIN_FILE', __FILE__ );
define( 'BARBERPRO_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BARBERPRO_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'BARBERPRO_DB_VERSION',  '3.0' );

// Carrega classes críticas antes do activation hook
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-database.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-roles.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-installer.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-license.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-backup.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-modules.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-comanda.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-bar.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-kanban-auto.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-notifications.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-payment.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-shop.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-clients.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-openai.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-message-queue.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-whatsapp-sender.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-automation.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-pwa.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-whatsapp-bot.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-widget-chat.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-app-ajax.php';
require_once BARBERPRO_PLUGIN_DIR . 'includes/class-bulk-whatsapp.php';

register_activation_hook(   __FILE__, [ 'BarberPro_Installer', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BarberPro_Installer', 'deactivate' ] );

// Registra intervalos de cron customizados cedo (antes do plugins_loaded),
// garantindo que o WP-Cron os reconheça mesmo quando disparado via wp-cron.php.
add_filter( 'cron_schedules', [ 'BarberPro_Automation', 'add_cron_intervals' ] );

final class BarberPro_SaaS {

    private static $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->boot();
    }

    private function load_dependencies(): void {
        $files = [
            'includes/class-api.php',
            'includes/class-whatsapp.php',
            'includes/class-finance.php',
            'includes/class-services.php',
            'includes/class-professionals.php',
            'includes/class-bookings.php',
            'admin/class-admin-menu.php',
            'public/class-frontend.php',
        ];
        foreach ( $files as $f ) {
            require_once BARBERPRO_PLUGIN_DIR . $f;
        }
    }

    private function boot(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'init', [ 'BarberPro_Installer', 'maybe_upgrade' ] );

        // Gerador de chaves (só para admins com secret configurado)
        add_action( 'admin_init', [ 'BarberPro_License', 'maybe_render_keygen' ] );

        // Avisos de licença no admin
        add_action( 'admin_notices', [ 'BarberPro_License', 'admin_notices' ] );

        // Frontend: shortcodes só funcionam com licença ativa
        $frontend = new BarberPro_Frontend();
new BarberPro_App_Ajax();
        if ( BarberPro_License::is_active() ) {
            $frontend->register_shortcodes();
            add_action( 'rest_api_init', [ 'BarberPro_API', 'register_routes' ] );
            add_action( 'rest_api_init', [ 'BarberPro_WhatsApp_Bot', 'register_routes' ] );
            add_action( 'rest_api_init', [ 'BarberPro_Payment', 'register_routes' ] );
            BarberPro_WhatsApp::schedule_reminders();
            BarberPro_Kanban_Auto::schedule();
            BarberPro_Automation::init(); // Fila de mensagens + automações
            add_action( 'barberpro_bulk_wa_tick', [ 'BarberPro_Bulk_WhatsApp', 'tick' ] );
            BarberPro_Shop::install_tables();
            BarberPro_Installer::maybe_migrate(); // garante colunas em instâncias existentes
            // maybe_flush DEVE rodar dentro do hook 'init' para que $wp_rewrite esteja pronto
            add_action( 'init', [ 'BarberPro_PWA', 'maybe_flush' ], 1 );
            add_action( 'init', [ 'BarberPro_PWA', 'init' ] );
            add_action( 'init', [ 'BarberPro_Widget_Chat', 'init' ] );
        } else {
            // Registra shortcodes "bloqueados" para não quebrar páginas
            $frontend->register_shortcodes_blocked();
        }

        // Hooks AJAX precisam ser registrados sempre (admin-ajax.php não é sempre is_admin na inicialização)
        add_action( 'wp_ajax_barberpro_save_service',          [ 'BarberPro_Admin_Menu', 'ajax_save_service_static' ] );
        add_action( 'wp_ajax_bp_testar_wapi',                  'barberpro_ajax_testar_wapi' );
        add_action( 'wp_ajax_bp_preview_pix',                  'barberpro_ajax_preview_pix' );
        add_action( 'wp_ajax_bp_testar_mp',                    'barberpro_ajax_testar_mp' );
        add_action( 'wp_ajax_nopriv_barberpro_create_payment', [ 'BarberPro_Payment', 'ajax_create_payment' ] );
        add_action( 'wp_ajax_barberpro_create_payment',        [ 'BarberPro_Payment', 'ajax_create_payment' ] );
        add_action( 'wp_ajax_nopriv_barberpro_payment_webhook', [ 'BarberPro_Payment', 'ajax_payment_webhook' ] );
        add_action( 'wp_ajax_barberpro_payment_webhook',        [ 'BarberPro_Payment', 'ajax_payment_webhook' ] );
        // Loja
        add_action( 'wp_ajax_nopriv_bp_loja_pedido', 'barberpro_ajax_loja_pedido' );
        add_action( 'wp_ajax_bp_loja_pedido',        'barberpro_ajax_loja_pedido' );
        add_action( 'wp_ajax_nopriv_bp_loja_frete',  'barberpro_ajax_loja_frete' );
        add_action( 'wp_ajax_bp_loja_frete',         'barberpro_ajax_loja_frete' );
        add_action( 'wp_ajax_bp_loja_status',        'barberpro_ajax_loja_status' );
        add_action( 'wp_ajax_barberpro_save_professional',     [ 'BarberPro_Admin_Menu', 'ajax_save_professional_static' ] );
        add_action( 'wp_ajax_barberpro_update_booking_status', [ 'BarberPro_Admin_Menu', 'ajax_update_status_static' ] );
        add_action( 'wp_ajax_bp_testar_openai', 'barberpro_ajax_testar_openai' );
        add_action( 'wp_ajax_bp_upload_product_photo',         'barberpro_ajax_upload_product_photo' );
        add_action( 'wp_ajax_nopriv_bp_upload_product_photo',  'barberpro_ajax_upload_product_photo' );

        // ── Carteira de clientes ──────────────────────────────────────────
        add_action( 'barberpro_booking_created', 'barberpro_on_booking_created', 10, 2 );
        add_action( 'barberpro_booking_finished', 'barberpro_on_booking_finished', 10, 1 );
        add_action( 'barberpro_daily_reminders', 'barberpro_send_client_reminders' );
        add_action( 'barberpro_daily_reminders', 'barberpro_send_client_absence_reminders', 20 );
        if ( ! wp_next_scheduled('barberpro_daily_reminders') ) {
            wp_schedule_event( strtotime('08:00:00'), 'daily', 'barberpro_daily_reminders' );
        }

        // Garante migrations em instâncias existentes (sem precisar reativar)
        add_action( 'init', function() {
            static $done = false;
            if ( $done ) return; $done = true;
            BarberPro_Installer::maybe_migrate();
        }, 5 );

        if ( is_admin() ) {
            new BarberPro_Admin_Menu();
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'barberpro-saas', false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }
}

add_action( 'plugins_loaded', function () {
    BarberPro_SaaS::instance();
} );

/**
 * Retorna array de formas de pagamento ativas conforme configuração.
 * Formato: ['dinheiro' => '💵 Dinheiro', 'pix' => '⚡ PIX', ...]
 */
function bp_get_payment_methods(): array {
    $all = [
        'dinheiro'       => '💵 Dinheiro',
        'pix'            => '⚡ PIX',
        'cartao_debito'  => '💳 Débito',
        'cartao_credito' => '💳 Crédito',
        'transferencia'  => '🏦 Transferência',
        'voucher'        => '🎟️ Voucher',
        'boleto'         => '📄 Boleto',
        'outro'          => '💰 ' . ( BarberPro_Database::get_setting('outro_payment_label','Outro') ?: 'Outro' ),
    ];
    // Padrão ativo se nunca foi configurado: dinheiro, pix, débito, crédito
    $defaults = ['dinheiro','pix','cartao_debito','cartao_credito'];
    $active = [];
    foreach ( $all as $key => $label ) {
        $val = BarberPro_Database::get_setting( 'pay_method_' . $key, in_array($key,$defaults) ? '1' : '0' );
        if ( $val === '1' ) {
            $active[$key] = $label;
        }
    }
    return $active ?: $all; // fallback: todos, se nada configurado
}

/**
 * Há pelo menos uma forma de pagamento (presencial configurada ou gateway online ativo)?
 * Usado pelo widget/WhatsApp para pular a etapa de pagamento quando não há nada configurado.
 */
function bp_has_any_payment_method_configured(): bool {
    $when = BarberPro_Database::get_setting( 'online_payment_when', 'optional' );
    if ( class_exists( 'BarberPro_Payment' ) ) {
        $gw = BarberPro_Payment::get_active_gateways();
        if ( ! empty( $gw ) && $when !== 'disabled' ) {
            return true;
        }
    }
    $keys     = [ 'dinheiro', 'pix', 'cartao_debito', 'cartao_credito', 'transferencia', 'voucher', 'boleto', 'outro' ];
    $defaults = [ 'dinheiro', 'pix', 'cartao_debito', 'cartao_credito' ];
    foreach ( $keys as $k ) {
        $def = in_array( $k, $defaults, true ) ? '1' : '0';
        if ( BarberPro_Database::get_setting( 'pay_method_' . $k, $def ) === '1' ) {
            return true;
        }
    }
    return false;
}

/**
 * Handler AJAX para download de arquivos ZIP de backup.
 */
function barberpro_handle_zip_download() {
    $filename = sanitize_file_name( $_GET['file'] ?? '' );
    $nonce    = sanitize_text_field( $_GET['nonce'] ?? '' );
    if ( ! wp_verify_nonce( $nonce, 'bp_dl_' . $filename ) ) wp_die( 'Link expirado.' );
    if ( ! is_user_logged_in() ) wp_die( 'Acesso negado.' );
    $path = BarberPro_Backup::get_backup_path( $filename );
    if ( ! $path ) wp_die( 'Arquivo não encontrado.' );
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . filesize($path) );
    readfile( $path );
    exit;
}
add_action( 'wp_ajax_bp_backup_download', 'barberpro_handle_zip_download' );

/**
 * AJAX: Preview QR Code PIX nas configurações
 */
function barberpro_ajax_preview_pix(): void {
    check_ajax_referer('bp_preview_pix', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Sem permissão']);

    $result = BarberPro_Payment::create_pix( (float)($_POST['amount'] ?? 1.00), [
        'booking_code' => 'PREVIEW',
        'description'  => 'Teste PIX',
    ]);

    // Sobrescreve as configurações com os valores do formulário (preview em tempo real)
    if ( ! empty($_POST['pix_key']) ) {
        add_filter('barberpro_setting_pix_key',    function() { return sanitize_text_field($_POST['pix_key']); });
        add_filter('barberpro_setting_pix_holder', function() { return sanitize_text_field($_POST['pix_holder'] ?? ''); });
        add_filter('barberpro_setting_pix_city',   function() { return sanitize_text_field($_POST['pix_city'] ?? 'Brasil'); });
        $result = BarberPro_Payment::create_pix( (float)($_POST['amount'] ?? 1.00), ['booking_code'=>'PREVIEW']);
    }

    $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
}

/**
 * AJAX: Testa credenciais do Mercado Pago
 */
function barberpro_ajax_testar_mp(): void {
    check_ajax_referer('bp_testar_mp', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Sem permissão']);

    $token = sanitize_text_field($_POST['token'] ?? '');
    if ( ! $token ) wp_send_json_error(['message' => 'Token obrigatório.']);

    $response = wp_remote_get('https://api.mercadopago.com/users/me', [
        'headers' => ['Authorization' => 'Bearer ' . $token],
        'timeout' => 10,
    ]);

    if ( is_wp_error($response) ) wp_send_json_error(['message' => $response->get_error_message()]);

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode(wp_remote_retrieve_body($response), true);

    if ( $code === 200 && isset($data['id']) ) {
        $nome  = $data['first_name'] ?? '';
        $email = $data['email']      ?? '';
        wp_send_json_success(['message' => "Conta: {$nome} ({$email})"]);
    }

    $msg = $data['message'] ?? $data['error'] ?? "Erro HTTP {$code}";
    wp_send_json_error(['message' => $msg]);
}

/**
 * AJAX: Criar pedido na loja
 */
function barberpro_ajax_loja_pedido(): void {
    check_ajax_referer('bp_loja', 'nonce');
    $raw_items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
    if ( ! is_array($raw_items) || empty($raw_items) ) wp_send_json_error(['message'=>'Carrinho vazio.']);
    $items = array_map( function( $i ) {
        return [
            'product_id'   => absint( $i['product_id'] ),
            'product_name' => sanitize_text_field( $i['product_name'] ?? '' ),
            'qty'          => max( 0.001, (float)( $i['qty'] ?? 1 ) ),
        ];
    }, $raw_items );
    $data = [
        'items'                => $items,
        'company_id'           => absint($_POST['cid'] ?? 1),
        'client_name'          => sanitize_text_field($_POST['client_name']  ?? ''),
        'client_email'         => sanitize_email(     $_POST['client_email'] ?? ''),
        'client_phone'         => sanitize_text_field($_POST['client_phone'] ?? ''),
        'delivery_type'        => sanitize_key(       $_POST['delivery_type']    ?? 'retirada'),
        'shipping_cost'        => (float)(            $_POST['shipping_cost']    ?? 0),
        'address_street'       => sanitize_text_field($_POST['address_street']   ?? ''),
        'address_number'       => sanitize_text_field($_POST['address_number']   ?? ''),
        'address_neighborhood' => sanitize_text_field($_POST['address_neighborhood'] ?? ''),
        'address_city'         => sanitize_text_field($_POST['address_city']     ?? ''),
        'address_state'        => sanitize_text_field($_POST['address_state']    ?? ''),
        'address_zip'          => preg_replace('/\D/','',$_POST['address_zip']   ?? ''),
        'payment_method'       => sanitize_key(       $_POST['payment_method']   ?? 'presencial'),
        'notes'                => sanitize_textarea_field($_POST['notes']         ?? ''),
    ];
    $result = BarberPro_Shop::create_order($data);
    if ( ! $result['success'] ) wp_send_json_error(['message'=>$result['message']]);
    $order     = BarberPro_Shop::get_order($result['order_id']);
    $items_db  = BarberPro_Shop::get_order_items($result['order_id']);
    if ($order) BarberPro_Shop::notify_new_order($order, $items_db);
    $response  = ['order_id'=>$result['order_id'],'order_code'=>$result['order_code'],'total'=>$result['total']];
    // PIX
    if ( $data['payment_method'] === 'pix' && class_exists('BarberPro_Payment') ) {
        $pix = BarberPro_Payment::create_pix($result['total'], ['booking_code'=>$result['order_code'],'description'=>'Pedido '.$result['order_code']]);
        if ($pix['success']) { $response['pix_payload']=$pix['payload']; $response['pix_qr_url']=$pix['qr_url']; global $wpdb; $wpdb->update("{$wpdb->prefix}barber_shop_orders",['pix_payload'=>$pix['payload']],['id'=>$result['order_id']]); }
    }
    // Mercado Pago
    if ( $data['payment_method'] === 'mercadopago' && class_exists('BarberPro_Payment') && $order ) {
        $mp = BarberPro_Payment::create_mp($result['total'], ['booking_id'=>$result['order_id'],'booking_code'=>$result['order_code'],'client_name'=>$data['client_name'],'client_email'=>$data['client_email'],'description'=>'Pedido '.$result['order_code']]);
        if ($mp['success']) $response['mp_url'] = $mp['checkout_url'];
    }
    wp_send_json_success($response);
}

/**
 * AJAX: Calcular frete + ViaCEP
 */
function barberpro_ajax_loja_frete(): void {
    check_ajax_referer('bp_loja', 'nonce');
    $zip      = preg_replace('/\D/','', $_POST['cep'] ?? '');
    $subtotal = (float)($_POST['subtotal'] ?? 0);
    if ( strlen($zip) !== 8 ) wp_send_json_error(['message'=>'CEP inválido.']);
    $frete = BarberPro_Shop::calc_shipping($zip, $subtotal);
    $address = null;
    $resp = wp_remote_get("https://viacep.com.br/ws/{$zip}/json/", ['timeout'=>5]);
    if ( ! is_wp_error($resp) ) {
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ( isset($body['logradouro']) ) $address = ['logradouro'=>$body['logradouro'],'bairro'=>$body['bairro'],'localidade'=>$body['localidade'],'uf'=>$body['uf']];
    }
    if ( ! $frete['success'] ) wp_send_json_error(['message'=>$frete['label']]);
    wp_send_json_success(['cost'=>$frete['cost'],'label'=>$frete['label'],'address'=>$address]);
}

/**
 * AJAX: Atualizar status de pedido
 */
function barberpro_ajax_loja_status(): void {
    check_ajax_referer('bp_app', 'nonce');
    if ( ! current_user_can('manage_options') && ! current_user_can('barberpro_manage_bookings') ) wp_send_json_error(['message'=>'Sem permissão.']);
    BarberPro_Shop::update_order_status(absint($_POST['order_id']??0), sanitize_key($_POST['status']??''))
        ? wp_send_json_success() : wp_send_json_error(['message'=>'Erro ao atualizar.']);
}

function barberpro_ajax_testar_wapi(): void {
    check_ajax_referer( 'bp_testar_wapi', 'nonce' );
    if ( ! current_user_can('manage_options') ) wp_send_json_error(['message'=>'Sem permissão']);

    $instance = sanitize_text_field( $_POST['instance'] ?? '' );
    $token    = sanitize_text_field( $_POST['token']    ?? '' );

    if ( ! $instance || ! $token ) {
        wp_send_json_error(['message' => 'Instance ID e Token são obrigatórios.']);
    }

    $url      = "https://api.w-api.app/v1/instance/status?instanceId={$instance}";
    $response = wp_remote_get( $url, [
        'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        'timeout' => 10,
    ]);

    if ( is_wp_error($response) ) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }

    $code = wp_remote_retrieve_response_code($response);
    $data = json_decode( wp_remote_retrieve_body($response), true );

    if ( $code === 200 && ! empty($data) ) {
        $status = $data['status'] ?? $data['instance']['status'] ?? $data['state'] ?? 'active';
        wp_send_json_success(['message' => 'Conectado! Status: ' . $status]);
    }

    $erro = $data['message'] ?? $data['error'] ?? "Erro HTTP {$code}";
    wp_send_json_error(['message' => $erro]);
}

// ── Upload de foto de produto (Loja / painel front-end) ──────────────────────
function barberpro_ajax_upload_product_photo(): void {
    // Accept multiple nonces (front-end app uses bp_app, admin uses barberpro_ajax)
    $valid = wp_verify_nonce( sanitize_text_field($_POST['nonce']??''), 'barberpro_ajax' )
          || wp_verify_nonce( sanitize_text_field($_POST['nonce']??''), 'bp_app' );
    if ( ! $valid ) wp_send_json_error(['message' => 'Nonce inválido.']);

    if ( ! current_user_can('barberpro_manage_bookings') && ! current_user_can('manage_options') ) {
        wp_send_json_error(['message' => 'Sem permissão.']);
    }

    if ( empty($_FILES['file']) ) {
        wp_send_json_error(['message' => 'Nenhum arquivo enviado.']);
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $attachment_id = media_handle_upload( 'file', 0 );

    if ( is_wp_error($attachment_id) ) {
        wp_send_json_error(['message' => $attachment_id->get_error_message()]);
    }

    $url = wp_get_attachment_url($attachment_id);
    wp_send_json_success(['url' => $url, 'id' => $attachment_id]);
}

// ── Carteira de clientes: callbacks ─────────────────────────────────────────
function barberpro_on_booking_created( int $booking_id, object $booking ): void {
    if ( class_exists('BarberPro_Clients') ) {
        BarberPro_Clients::on_booking_created([
            'client_name'  => $booking->client_name,
            'client_phone' => $booking->client_phone,
            'client_email' => $booking->client_email ?? '',
            'company_id'   => $booking->company_id,
        ]);
    }
}

function barberpro_on_booking_finished( object $booking ): void {
    if ( class_exists('BarberPro_Clients') ) {
        BarberPro_Clients::on_booking_finished( $booking );
    }
}

function barberpro_send_client_reminders(): void {
    if ( class_exists('BarberPro_Clients') ) {
        BarberPro_Clients::send_recurrence_reminders();
    }
}

function barberpro_send_client_absence_reminders(): void {
    if ( class_exists('BarberPro_Clients') ) {
        BarberPro_Clients::send_absence_reminders();
    }
}

// ── OpenAI: testar conexão ───────────────────────────────────────────────────
function barberpro_ajax_testar_openai(): void {
    check_ajax_referer('barberpro_ajax', 'nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error(['message' => 'Sem permissão.']);

    // Salva a key temporariamente para testar
    $key = sanitize_text_field($_POST['api_key'] ?? '');
    if ( $key ) BarberPro_Database::update_setting('openai_api_key', $key);

    $result = class_exists('BarberPro_OpenAI') ? BarberPro_OpenAI::test_connection() : ['success'=>false,'message'=>'Classe não carregada.'];
    $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
}
