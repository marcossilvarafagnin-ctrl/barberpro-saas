<?php
/**
 * Menu e páginas de administração – com suporte a módulos
 * @package BarberProSaaS
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Admin_Menu {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_barberpro_update_booking_status', [ $this, 'ajax_update_status' ] );
        add_action( 'wp_ajax_barberpro_save_service',          [ $this, 'ajax_save_service' ] );
        add_action( 'wp_ajax_barberpro_save_professional',     [ $this, 'ajax_save_professional' ] );
    }

    public function register_menu(): void {
        $ok = BarberPro_License::is_active();

        // ── Menu principal ────────────────────────────────────────────────────
        add_menu_page( 'BarberPro', 'BarberPro' . ($ok?'':' 🔒'),
            'barberpro_manage_bookings', 'barberpro',
            [ $this, 'page_dashboard' ], 'dashicons-store', 30 );

        // Licença sempre visível
        add_submenu_page( 'barberpro', 'Licença', '🔑 Licença',
            'manage_options', 'barberpro_license', [ $this, 'page_license' ] );

        if ( ! $ok ) return;

        // ── Dashboard + Módulos + Consolidado ─────────────────────────────────
        add_submenu_page( 'barberpro', 'Dashboard',          '📊 Dashboard',
            'barberpro_manage_bookings', 'barberpro',          [ $this, 'page_dashboard' ] );
        add_submenu_page( 'barberpro', 'Módulos',            '🧩 Módulos',
            'manage_options',            'barberpro_modules',   [ $this, 'page_modules' ] );
        add_submenu_page( 'barberpro', 'Financeiro Consolidado', '📈 Consolidado',
            'barberpro_view_finance',    'barberpro_consolidado', [ $this, 'page_consolidado' ] );
        add_submenu_page( 'barberpro', 'Bar/Eventos', '🍺 Bar/Eventos',
            'barberpro_manage_bookings', 'barberpro_bar',      [ $this, 'page_bar' ] );
        add_submenu_page( 'barberpro', 'Comandas', '🧾 Comandas',
            'barberpro_manage_bookings', 'barberpro_comandas', [ $this, 'page_comandas' ] );
        add_submenu_page( 'barberpro', 'Painel de Ganhos', '💰 Ganhos',
            'barberpro_view_finance',    'barberpro_ganhos',      [ $this, 'page_ganhos' ] );

        // ── Módulo Barbearia ──────────────────────────────────────────────────
        if ( BarberPro_Modules::is_active('barbearia') ) {
            add_menu_page( '✂️ Barbearia', '✂️ Barbearia',
                'barberpro_manage_bookings', 'barberpro_barbearia',
                [ $this, 'page_barbearia_dashboard' ], 'dashicons-admin-users', 31 );

            $sub_barber = [
                [ 'barberpro_barbearia',          '📊 Dashboard',    'barberpro_manage_bookings', 'page_barbearia_dashboard' ],
                [ 'barberpro_barbearia_kanban',   '🗂 Kanban',        'barberpro_manage_bookings', 'page_barbearia_kanban'    ],
                [ 'barberpro_barbearia_bookings', '📋 Agendamentos',  'barberpro_manage_bookings', 'page_barbearia_bookings'  ],
                [ 'barberpro_barbearia_pros',     '👨 Profissionais', 'barberpro_manage_staff',    'page_barbearia_pros'      ],
                [ 'barberpro_barbearia_services', '✂️ Serviços',      'barberpro_manage_services', 'page_barbearia_services'  ],
                [ 'barberpro_barbearia_finance',  '💰 Financeiro',    'barberpro_view_finance',    'page_barbearia_finance'   ],
                [ 'barberpro_barbearia_comandas', '🧾 Comandas',    'barberpro_manage_bookings', 'page_barbearia_comandas' ],
                [ 'barberpro_barbearia_ganhos',   '💰 Ganhos',        'barberpro_view_finance',    'page_barbearia_ganhos'    ],
            ];
            foreach ( $sub_barber as $s ) {
                add_submenu_page( 'barberpro_barbearia', $s[0], $s[1], $s[2], $s[0], [ $this, $s[3] ] );
            }
        }

        // ── Módulo Lava-Car ───────────────────────────────────────────────────
        if ( BarberPro_Modules::is_active('lavacar') ) {
            add_menu_page( '🚗 Lava-Car', '🚗 Lava-Car',
                'barberpro_manage_bookings', 'barberpro_lavacar',
                [ $this, 'page_lavacar_dashboard' ], 'dashicons-car', 32 );

            $sub_lava = [
                [ 'barberpro_lavacar',            '📊 Dashboard',     'barberpro_manage_bookings', 'page_lavacar_dashboard' ],
                [ 'barberpro_lavacar_kanban',     '🗂 Kanban',         'barberpro_manage_bookings', 'page_lavacar_kanban'   ],
                [ 'barberpro_lavacar_bookings',   '📋 Agendamentos',   'barberpro_manage_bookings', 'page_lavacar_bookings' ],
                [ 'barberpro_lavacar_services',   '🚿 Serviços',       'barberpro_manage_services', 'page_lavacar_services' ],
                [ 'barberpro_lavacar_finance',    '💰 Financeiro',     'barberpro_view_finance',    'page_lavacar_finance'  ],
                [ 'barberpro_lavacar_comandas',   '🧾 Comandas',   'barberpro_manage_bookings', 'page_lavacar_comandas'  ],
                [ 'barberpro_lavacar_ganhos',     '💰 Ganhos',         'barberpro_view_finance',    'page_lavacar_ganhos'   ],
                [ 'barberpro_lavacar_settings',   '⚙️ Coleta/Entrega', 'barberpro_manage_settings', 'page_lavacar_settings' ],
            ];
            foreach ( $sub_lava as $s ) {
                add_submenu_page( 'barberpro_lavacar', $s[0], $s[1], $s[2], $s[0], [ $this, $s[3] ] );
            }
        }

        // ── Configurações gerais ──────────────────────────────────────────────
        add_submenu_page( 'barberpro', 'Usuários', '👥 Usuários',
            'manage_options', 'barberpro_users', [ $this, 'page_users' ] );

        add_submenu_page( 'barberpro', 'Configurações', '⚙️ Configurações',
            'barberpro_manage_settings', 'barberpro_settings', [ $this, 'page_settings' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'barberpro' ) === false ) return;
        wp_enqueue_media(); // Necessário para o seletor de mídia do WordPress (upload de imagens de produto)
        wp_enqueue_script( 'chartjs',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js',
            [], '4.4.1', true );
        wp_enqueue_style(  'barberpro-admin',
            BARBERPRO_PLUGIN_URL . 'assets/css/admin.css', [], BARBERPRO_VERSION );
        wp_enqueue_script( 'barberpro-admin',
            BARBERPRO_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery', 'wp-api-fetch' ], BARBERPRO_VERSION, true );
        wp_localize_script( 'barberpro-admin', 'barberproAdmin', [
            'apiUrl'    => rest_url( 'barberpro/v1/' ),
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( 'barberpro_ajax' ),
        ] );
    }

    // ── Helpers de guard ─────────────────────────────────────────────────────
    private function guard( string $cap = 'barberpro_manage_bookings' ): bool {
        if ( ! BarberPro_License::is_active() ) { BarberPro_License::render_blocked_page(); return false; }
        if ( ! current_user_can( $cap ) ) { wp_die('Sem permissão.'); return false; }
        return true;
    }

    // ── Pages – Geral ────────────────────────────────────────────────────────
    public function page_license():     void { require BARBERPRO_PLUGIN_DIR . 'admin/views/license.php'; }
    public function page_users():       void { if (!$this->guard('manage_options')) return; require BARBERPRO_PLUGIN_DIR . 'admin/views/users.php'; }
    public function page_modules():     void { if (!$this->guard('manage_options')) return; require BARBERPRO_PLUGIN_DIR . 'admin/views/modules.php'; }
    public function page_consolidado(): void { if (!$this->guard('barberpro_view_finance')) return; require BARBERPRO_PLUGIN_DIR . 'admin/views/consolidado/financeiro.php'; }
    public function page_bar(): void {
        if (!$this->guard('barberpro_manage_bookings')) return;
        require BARBERPRO_PLUGIN_DIR . 'admin/views/bar.php';
    }
    public function page_comandas(): void {
        if (!$this->guard('barberpro_manage_bookings')) return;
        require BARBERPRO_PLUGIN_DIR . 'admin/views/comandas.php';
    }
    public function page_barbearia_comandas(): void {
        if (!$this->guard('barberpro_manage_bookings')) return;
        $company_id = BarberPro_Modules::company_id('barbearia');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/comandas.php';
    }
    public function page_lavacar_comandas(): void {
        if (!$this->guard('barberpro_manage_bookings')) return;
        $company_id = BarberPro_Modules::company_id('lavacar');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/comandas.php';
    }
    public function page_ganhos(): void { if (!$this->guard('barberpro_view_finance')) return; require BARBERPRO_PLUGIN_DIR . 'admin/views/ganhos/painel.php'; }
    public function page_barbearia_ganhos(): void {
        if (!$this->guard('barberpro_view_finance')) return;
        $_GET['view'] = 'barbearia';
        require BARBERPRO_PLUGIN_DIR . 'admin/views/ganhos/painel.php';
    }
    public function page_lavacar_ganhos(): void {
        if (!$this->guard('barberpro_view_finance')) return;
        $_GET['view'] = 'lavacar';
        require BARBERPRO_PLUGIN_DIR . 'admin/views/ganhos/painel.php';
    }
    public function page_dashboard():   void { if (!$this->guard()) return; require BARBERPRO_PLUGIN_DIR . 'admin/views/dashboard.php'; }
    public function page_settings():    void {
        if (!$this->guard('barberpro_manage_settings')) return;
        if ( isset($_POST['barberpro_settings_nonce']) && wp_verify_nonce(sanitize_key($_POST['barberpro_settings_nonce']), 'barberpro_save_settings') ) {
            $this->save_settings();
        }
        require BARBERPRO_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── Pages – Barbearia ────────────────────────────────────────────────────
    public function page_barbearia_dashboard(): void {
        if (!$this->guard()) return;
        $company_id = BarberPro_Modules::company_id('barbearia');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    public function page_barbearia_kanban(): void {
        if (!$this->guard()) return;
        require BARBERPRO_PLUGIN_DIR . 'admin/views/barbearia/kanban.php';
    }
    public function page_barbearia_bookings(): void {
        if (!$this->guard()) return;
        $company_id = BarberPro_Modules::company_id('barbearia');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/bookings.php';
    }
    public function page_barbearia_pros(): void {
        if (!$this->guard('barberpro_manage_staff')) return;
        $company_id = BarberPro_Modules::company_id('barbearia');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/professionals.php';
    }
    public function page_barbearia_services(): void {
        if (!$this->guard('barberpro_manage_services')) return;
        $company_id = BarberPro_Modules::company_id('barbearia');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/services.php';
    }
    public function page_barbearia_finance(): void {
        if (!$this->guard('barberpro_view_finance')) return;
        $company_id = BarberPro_Modules::company_id('barbearia');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/finance.php';
    }

    // ── Pages – Lava-Car ─────────────────────────────────────────────────────
    public function page_lavacar_dashboard(): void {
        if (!$this->guard()) return;
        $company_id = BarberPro_Modules::company_id('lavacar');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/dashboard.php';
    }
    public function page_lavacar_kanban(): void {
        if (!$this->guard()) return;
        require BARBERPRO_PLUGIN_DIR . 'admin/views/lavacar/kanban.php';
    }
    public function page_lavacar_bookings(): void {
        if (!$this->guard()) return;
        $company_id = BarberPro_Modules::company_id('lavacar');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/bookings.php';
    }
    public function page_lavacar_services(): void {
        if (!$this->guard('barberpro_manage_services')) return;
        $company_id = BarberPro_Modules::company_id('lavacar');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/services.php';
    }
    public function page_lavacar_finance(): void {
        if (!$this->guard('barberpro_view_finance')) return;
        $company_id = BarberPro_Modules::company_id('lavacar');
        require BARBERPRO_PLUGIN_DIR . 'admin/views/finance.php';
    }
    public function page_lavacar_settings(): void {
        if (!$this->guard('barberpro_manage_settings')) return;
        if ( isset($_POST['barberpro_settings_nonce']) && wp_verify_nonce(sanitize_key($_POST['barberpro_settings_nonce']), 'barberpro_save_settings') ) {
            $this->save_settings();
        }
        // Vai direto para a aba lava-car das configurações
        $_GET['tab'] = 'lavacar';
        require BARBERPRO_PLUGIN_DIR . 'admin/views/settings.php';
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────
    public function ajax_update_status(): void {
        check_ajax_referer( 'barberpro_ajax', 'nonce' );
        if ( ! current_user_can('barberpro_manage_bookings') ) wp_send_json_error(['message'=>'Sem permissão.']);
        $result = BarberPro_Bookings::update_status( absint($_POST['booking_id']??0), sanitize_key($_POST['status']??'') );
        $result['success'] ? wp_send_json_success($result) : wp_send_json_error($result);
    }
    public function ajax_save_service(): void {
        check_ajax_referer( 'barberpro_ajax', 'nonce' );
        if ( ! current_user_can('manage_options') && ! current_user_can('barberpro_manage_services') ) {
            wp_send_json_error(['message' => 'Sem permissão para gerenciar serviços.']); return;
        }
        global $wpdb;
        $id   = absint($_POST['id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        if ( empty($name) ) { wp_send_json_error(['message' => 'Nome é obrigatório.']); return; }

        $data = [
            'company_id'  => absint($_POST['company_id'] ?? 1),
            'name'        => $name,
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'price'       => round((float) str_replace(',', '.', $_POST['price'] ?? '0'), 2),
            'duration'    => absint($_POST['duration'] ?? 30),
            'status'      => 'active',
        ];
        if ( ! empty($_POST['photo']) ) {
            $data['photo'] = esc_url_raw( $_POST['photo'] );
        }
        if ( $id ) {
            $data['updated_at'] = current_time('mysql');
            $r = $wpdb->update("{$wpdb->prefix}barber_services", $data, ['id' => $id]);
            $r !== false
                ? wp_send_json_success(['id' => $id])
                : wp_send_json_error(['message' => 'Erro ao atualizar: ' . $wpdb->last_error]);
        } else {
            $data['created_at'] = current_time('mysql');
            $r = $wpdb->insert("{$wpdb->prefix}barber_services", $data);
            $r
                ? wp_send_json_success(['id' => $wpdb->insert_id])
                : wp_send_json_error(['message' => 'Erro ao criar: ' . $wpdb->last_error]);
        }
    }
    public function ajax_save_professional(): void {
        check_ajax_referer( 'barberpro_ajax', 'nonce' );
        if ( ! current_user_can('manage_options') && ! current_user_can('barberpro_manage_staff') ) {
            wp_send_json_error(['message' => 'Sem permissão para gerenciar profissionais.']); return;
        }
        global $wpdb;
        $id   = absint($_POST['id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        if ( empty($name) ) { wp_send_json_error(['message' => 'Nome é obrigatório.']); return; }

        $data = [
            'company_id'    => absint($_POST['company_id'] ?? 1),
            'name'          => $name,
            'specialty'     => sanitize_text_field($_POST['specialty'] ?? ''),
            'phone'         => sanitize_text_field($_POST['phone'] ?? ''),
            'work_days'     => sanitize_text_field($_POST['work_days'] ?? '1,2,3,4,5'),
            'work_start'    => sanitize_text_field($_POST['work_start'] ?? '09:00:00'),
            'work_end'      => sanitize_text_field($_POST['work_end'] ?? '18:00:00'),
            'lunch_start'   => sanitize_text_field($_POST['lunch_start'] ?? '12:00:00'),
            'lunch_end'     => sanitize_text_field($_POST['lunch_end'] ?? '13:00:00'),
            'slot_interval' => absint($_POST['slot_interval'] ?? 30),
            'commission_pct'=> round((float)($_POST['commission_pct'] ?? 40), 2),
            'status'        => 'active',
        ];
        if ( ! empty($_POST['photo']) ) {
            $data['photo'] = esc_url_raw( $_POST['photo'] );
        }
        if ( $id ) {
            $data['updated_at'] = current_time('mysql');
            $r = $wpdb->update("{$wpdb->prefix}barber_professionals", $data, ['id' => $id]);
            $r !== false
                ? wp_send_json_success(['id' => $id])
                : wp_send_json_error(['message' => 'Erro ao atualizar: ' . $wpdb->last_error]);
        } else {
            $data['created_at'] = current_time('mysql');
            $r = $wpdb->insert("{$wpdb->prefix}barber_professionals", $data);
            $r
                ? wp_send_json_success(['id' => $wpdb->insert_id])
                : wp_send_json_error(['message' => 'Erro ao criar: ' . $wpdb->last_error]);
        }
    }

    // ── Settings save ────────────────────────────────────────────────────────
    private function save_settings(): void {
        $fields = [
            'whatsapp_number','whatsapp_provider','whatsapp_cloud_token','whatsapp_phone_id',
            'twilio_account_sid','twilio_auth_token','twilio_from','zapi_instance','zapi_token',
            'booking_min_advance_minutes','booking_max_advance_days','cancellation_hours',
            'require_deposit','deposit_pct',
            'msg_confirmation','msg_confirmation_active',
            'msg_reminder','msg_reminder_active','reminder_hours','reminder_minutes',
            'msg_reminder2','msg_reminder2_active','reminder2_hours','reminder2_minutes',
            'msg_cancellation','msg_cancellation_active',
            'msg_review','msg_review_active','review_delay_hours',
            'msg_birthday','msg_birthday_active','birthday_send_time',
            'msg_return','msg_return_active','return_default_days','booking_page_url',
            'admin_slot_interval_default','client_slot_interval_default',
            'wapi_instance','wapi_token',
            'kanban_auto_enabled','kanban_auto_confirm','kanban_auto_confirm_minutes',
            'kanban_auto_start','kanban_auto_finish',
            'bot_ativo','bot_mode','bot_webhook_token','bot_debug','bot_envia_lembretes',
            'bot_msg_menu','bot_msg_data','bot_msg_confirmar','bot_msg_sucesso','bot_msg_cancelado','bot_msg_sem_horarios','bot_msg_localizacao',
            'wc_msg_pedir_nome','wc_msg_pedir_celular','wc_msg_pedir_email','wc_msg_sucesso','wc_msg_sem_horarios','wc_msg_cancelado',
            'warming_ativo','warming_dias','warming_horario','warming_frequencia','warming_delay_seconds','warming_msg','warming_media_url','warming_media_type',
            'queue_batch_size','queue_delay_seconds','queue_cleanup_days',
            'openai_ativo','openai_api_key','openai_model','openai_max_tokens','openai_free_response','openai_reactivation','openai_horario_info','openai_system_prompt',
            'widget_chat_ativo','widget_chat_nome_bot','widget_chat_cor','widget_chat_avatar',
            'widget_chat_posicao','widget_chat_saudacao','widget_chat_email_dono','widget_chat_tel_dono',
            'pwa_ativo','pwa_nome','pwa_nome_curto','pwa_icone_url',
            'pwa_cor_tema','pwa_cor_fundo','pwa_start_url','pwa_display','pwa_ios_msg',
            'shop_nome','shop_frete_tipo','shop_frete_fixo','shop_frete_faixas',
            'shop_frete_fora_faixa','shop_frete_gratis_minimo',
            'shop_notify_email','shop_notify_whatsapp',
            'notify_email_ativo','notify_whatsapp_ativo','notify_bot_ativo',
            'email_nome_remetente','email_remetente','email_bcc','email_logo_url',
            'email_cor_primaria','email_html',
            'email_confirmation_assunto','email_confirmation_corpo','email_confirmation_active',
            'email_reminder_assunto','email_reminder_corpo','email_reminder_active',
            'email_reminder2_assunto','email_reminder2_corpo','email_reminder2_active',
            'email_cancellation_assunto','email_cancellation_corpo','email_cancellation_active',
            'email_review_assunto','email_review_corpo','email_review_active',
            'pay_method_dinheiro','pay_method_pix','pay_method_cartao_debito','pay_method_cartao_credito',
            'pay_method_transferencia','pay_method_voucher','pay_method_boleto','pay_method_outro',
            'pix_key','pix_holder','pix_city','pix_expiry_minutes','voucher_types','outro_payment_label',
            'fee_cartao_credito','fee_cartao_debito','pass_card_fee_to_client',
            'payment_pix_ativo','payment_mp_ativo',
            'mp_access_token','mp_public_key','mp_sandbox','mp_status_after_payment',
            'online_payment_when',
            'delivery_opt_cliente_traz','delivery_opt_busca_entrega','delivery_opt_busca_retira','delivery_opt_leva_entrega',
            'delivery_fee_busca_entrega','delivery_fee_busca_retira','delivery_fee_leva_entrega',
            'delivery_max_km','delivery_info_msg','delivery_require_full_address',
            'msg_delivery_pickup','msg_delivery_done',
            'loyalty_points_per_booking','dark_mode',
        ];
        foreach ( $fields as $f ) {
            $val = isset($_POST[$f]) ? sanitize_text_field(wp_unslash($_POST[$f])) : '0';
            BarberPro_Database::update_setting($f, $val);
        }
        // Textareas
        foreach (['msg_confirmation','msg_reminder','msg_reminder2','msg_cancellation','msg_review','msg_birthday','msg_return','msg_delivery_pickup','msg_delivery_done','delivery_info_msg'] as $tf) {
            if (isset($_POST[$tf])) {
                BarberPro_Database::update_setting($tf, sanitize_textarea_field(wp_unslash($_POST[$tf])));
            }
        }
        add_action('admin_notices', function() { print '<div class="notice notice-success is-dismissible"><p>✅ Configurações salvas!</p></div>'; });
    }
    // ── Static AJAX handlers (registrados fora do is_admin()) ────────────────
    public static function ajax_save_service_static(): void {
        ( new self() )->ajax_save_service();
    }
    public static function ajax_save_professional_static(): void {
        ( new self() )->ajax_save_professional();
    }
    public static function ajax_update_status_static(): void {
        ( new self() )->ajax_update_status();
    }


}
