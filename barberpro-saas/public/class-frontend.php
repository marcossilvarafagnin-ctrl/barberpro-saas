<?php
/**
 * Frontend – Shortcodes e enqueue de assets
 * CORREÇÃO: shortcodes registrados via instância para evitar conflito static/instance.
 *
 * @package BarberProSaaS
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BarberPro_Frontend {

    /** Instância singleton usada pelos shortcodes */
    private static ?self $instance = null;

    public function __construct() {
        self::$instance = $this;

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX – disponível para logados e não-logados
        add_action( 'wp_ajax_barberpro_get_slots',             [ $this, 'ajax_get_slots' ] );
        add_action( 'wp_ajax_nopriv_barberpro_get_slots',      [ $this, 'ajax_get_slots' ] );
        add_action( 'wp_ajax_barberpro_create_booking',        [ $this, 'ajax_create_booking' ] );
        add_action( 'wp_ajax_nopriv_barberpro_create_booking', [ $this, 'ajax_create_booking' ] );
        add_action( 'wp_ajax_barberpro_cancel_booking',        [ $this, 'ajax_cancel_booking' ] );
    }

    /**
     * Registra shortcodes – chamado em init via instância.
     * NÃO é static para evitar problemas de acoplamento.
     */
    public function register_shortcodes(): void {
        add_shortcode( 'barberpro_agendamento',    [ $this, 'shortcode_agendamento' ] );
        add_shortcode( 'barberpro_painel_cliente', [ $this, 'shortcode_painel_cliente' ] );
        add_shortcode( 'barberpro_painel_admin',   [ $this, 'shortcode_painel_admin' ] );
        add_shortcode( 'barberpro_loja',           [ $this, 'shortcode_loja' ] );
        // Módulo Barbearia
        if ( BarberPro_Modules::is_active('barbearia') ) {
            add_shortcode( 'barberpro_app', [ $this, 'shortcode_app' ] );
        add_shortcode( 'barberpro_barbearia',       [ $this, 'shortcode_barbearia' ] );
        add_shortcode( 'barberpro_bar',             [ $this, 'shortcode_bar_caixa' ] );
        add_shortcode( 'barberpro_bar_caixa',       [ $this, 'shortcode_bar_caixa' ] );
            add_shortcode( 'barberpro_painel_barbearia',[ $this, 'shortcode_painel_barbearia' ] );
        }
        // Módulo Lava-Car
        if ( BarberPro_Modules::is_active('lavacar') ) {
            add_shortcode( 'barberpro_lavacar',         [ $this, 'shortcode_lavacar' ] );
            add_shortcode( 'barberpro_painel_lavacar',  [ $this, 'shortcode_painel_lavacar' ] );
        }
    }

    // =========================================================================
    // Assets
    // =========================================================================

    public function enqueue_assets(): void {
        global $post;

        $has_app = false;
        $has_pub = false;
        if ( is_a( $post, 'WP_Post' ) ) {
            $app_codes = ['barberpro_app','barberpro_bar','barberpro_bar_caixa'];
            $pub_codes = ['barberpro_agendamento','barberpro_painel_cliente','barberpro_barbearia','barberpro_lavacar'];
            foreach ( $app_codes as $sc ) { if ( has_shortcode($post->post_content,$sc) ) { $has_app=true; break; } }
            foreach ( $pub_codes as $sc ) { if ( has_shortcode($post->post_content,$sc) ) { $has_pub=true; break; } }
        }

        // ── SPA assets (barberpro_app / bar_caixa) ──
        if ( $has_app ) {
            wp_enqueue_style(  'barberpro-app', BARBERPRO_PLUGIN_URL.'assets/css/app.css', [], BARBERPRO_VERSION );
            wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true );
            wp_enqueue_script( 'barberpro-app', BARBERPRO_PLUGIN_URL.'assets/js/app.js', ['chart-js'], BARBERPRO_VERSION, true );

            $is_logged = is_user_logged_in();
            $user_data = ['logged_in' => false];
            $active_mods = [];
            if ( $is_logged ) {
                $wp_user   = wp_get_current_user();
                $user_data = [
                    'logged_in'  => true,
                    'id'         => $wp_user->ID,
                    'name'       => $wp_user->display_name,
                    'email'      => $wp_user->user_email,
                    'role_label' => current_user_can('administrator') ? 'Administrador'
                                  : ( current_user_can('barberpro_manager') ? 'Gerente' : 'Operador' ),
                ];
                $allowed  = get_user_meta( $wp_user->ID, 'barberpro_modules', true ) ?: [];
                $is_admin = current_user_can('manage_options');
                foreach ( BarberPro_Modules::active_list() as $key => $mod ) {
                    if ( $is_admin || empty($allowed) || in_array($key,$allowed,true) ) {
                        $active_mods[$key] = true;
                    }
                }
            }

            // Bar-only shortcode: restrict to bar module and start at bar_caixa
            $start = 'dashboard';
            if ( is_a($post,'WP_Post') ) {
                foreach (['barberpro_bar','barberpro_bar_caixa'] as $sc) {
                    if ( has_shortcode($post->post_content,$sc) ) {
                        $start = 'bar_caixa';
                        $active_mods = ['bar' => true];
                        break;
                    }
                }
            }

            wp_localize_script( 'barberpro-app', 'bpAppData', [
                'ajaxUrl'      => admin_url('admin-ajax.php'),
                'nonce'        => wp_create_nonce('bp_app'),
                'restUrl'      => rest_url('barberpro/v1/'),
                'user'         => $user_data,
                'modules'      => empty($active_mods) ? new stdClass() : (object)$active_mods,
                'siteName'     => BarberPro_Database::get_setting('business_name', get_bloginfo('name')),
                'startSection' => $start,
            ] );
        }

        // ── Public assets (agendamento, painel cliente) ──
        if ( $has_pub ) {
            wp_enqueue_style(  'barberpro-public', BARBERPRO_PLUGIN_URL.'assets/css/public.css', [], BARBERPRO_VERSION );
            wp_enqueue_script( 'barberpro-public', BARBERPRO_PLUGIN_URL.'assets/js/public.js', ['jquery'], BARBERPRO_VERSION, true );
            wp_localize_script( 'barberpro-public', 'barberproPublic', [
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('barberpro_booking'),
                'apiUrl'    => rest_url('barberpro/v1/'),
                'restNonce' => wp_create_nonce('wp_rest'),
                'i18n'      => [
                    'loading'         => 'Carregando...',
                    'no_slots'        => 'Nenhum horário disponível nesta data.',
                    'booking_success' => 'Agendamento realizado com sucesso!',
                    'booking_error'   => 'Erro ao agendar. Tente novamente.',
                    'required_field'  => 'Preencha todos os campos obrigatórios.',
                    'confirm_cancel'  => 'Deseja cancelar este agendamento?',
                ],
            ] );
        }
    }

    // ── Shortcode: SPA completo ──────────────────────────────────
    public function shortcode_app( $atts ): string {
        if ( ! is_user_logged_in() ) {
            return $this->render_client_auth( get_permalink() );
        }
        ob_start();
        include BARBERPRO_PLUGIN_DIR . 'public/templates/app/shell.php';
        return ob_get_clean();
    }

    // ── Shortcode: Caixa Bar/Eventos ─────────────────────────────
    public function shortcode_bar_caixa( $atts ): string {
        if ( ! BarberPro_Modules::is_active('bar') ) {
            return '<p style="padding:20px;color:#ef4444">⚠️ Módulo Bar/Eventos não está ativo.</p>';
        }
        if ( ! is_user_logged_in() ) {
            return $this->render_client_auth( get_permalink() );
        }
        ob_start();
        include BARBERPRO_PLUGIN_DIR . 'public/templates/app/shell.php';
        return ob_get_clean();
    }

    // ── Shortcode: Barbearia ─────────────────────────────────────────────────

    public function shortcode_barbearia( $atts ): string {
        if ( ! BarberPro_License::is_active() ) return '<p style="color:#ef4444">⚠️ Licença inativa.</p>';
        wp_enqueue_style(  'barberpro-public',  BARBERPRO_PLUGIN_URL . 'assets/css/public.css',  [], BARBERPRO_VERSION );
        wp_enqueue_script( 'barberpro-public',  BARBERPRO_PLUGIN_URL . 'assets/js/public.js', ['jquery'], BARBERPRO_VERSION, true );
        wp_localize_script( 'barberpro-public', 'barberproPublic', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('barberpro_booking'),
            'i18n'    => [ 'required_field' => 'Preencha os campos obrigatórios.', 'booking_error' => 'Erro ao criar agendamento.' ],
        ]);
        ob_start();
        require BARBERPRO_PLUGIN_DIR . 'public/templates/barbearia/agendamento.php';
        return ob_get_clean();
    }

    public function shortcode_painel_barbearia( $atts ): string {
        $atts = shortcode_atts(['role' => 'barberpro_manage_bookings'], $atts);
        wp_enqueue_style(  'barberpro-painel-admin', BARBERPRO_PLUGIN_URL . 'assets/css/painel-admin.css', [], BARBERPRO_VERSION );
        wp_enqueue_script( 'barberpro-painel-admin', BARBERPRO_PLUGIN_URL . 'assets/js/painel-admin.js', ['jquery'], BARBERPRO_VERSION, true );
        wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true );
        $required_cap    = sanitize_key($atts['role']);
        $bp_module_attr  = 'barbearia';
        ob_start();
        require BARBERPRO_PLUGIN_DIR . 'public/templates/painel-admin.php';
        return ob_get_clean();
    }

    // ── Shortcode: Lava-Car ──────────────────────────────────────────────────

    public function shortcode_lavacar( $atts ): string {
        if ( ! BarberPro_License::is_active() ) return '<p style="color:#ef4444">⚠️ Licença inativa.</p>';
        wp_enqueue_style(  'barberpro-public',  BARBERPRO_PLUGIN_URL . 'assets/css/public.css',  [], BARBERPRO_VERSION );
        wp_enqueue_script( 'barberpro-public',  BARBERPRO_PLUGIN_URL . 'assets/js/public.js', ['jquery'], BARBERPRO_VERSION, true );
        wp_localize_script( 'barberpro-public', 'barberproPublic', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('barberpro_booking'),
            'i18n'    => [ 'required_field' => 'Preencha os campos obrigatórios.', 'booking_error' => 'Erro ao criar agendamento.' ],
        ]);
        ob_start();
        require BARBERPRO_PLUGIN_DIR . 'public/templates/lavacar/agendamento.php';
        return ob_get_clean();
    }

    public function shortcode_painel_lavacar( $atts ): string {
        $atts = shortcode_atts(['role' => 'barberpro_manage_bookings'], $atts);
        wp_enqueue_style(  'barberpro-painel-admin', BARBERPRO_PLUGIN_URL . 'assets/css/painel-admin.css', [], BARBERPRO_VERSION );
        wp_enqueue_script( 'barberpro-painel-admin', BARBERPRO_PLUGIN_URL . 'assets/js/painel-admin.js', ['jquery'], BARBERPRO_VERSION, true );
        wp_enqueue_script( 'chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true );
        $required_cap    = sanitize_key($atts['role']);
        $bp_module_attr  = 'lavacar';
        ob_start();
        require BARBERPRO_PLUGIN_DIR . 'public/templates/painel-admin.php';
        return ob_get_clean();
    }

    // =========================================================================
    // Helpers de autenticação de cliente
    // =========================================================================

    /**
     * Retorna HTML do formulário de login/cadastro de cliente.
     * Redireciona de volta para a página atual após login.
     */
    private function render_client_auth( string $return_url = '' ): string {
        if ( empty($return_url) ) $return_url = get_permalink();
        $return_url  = esc_url( $return_url );
        $login_url   = wp_login_url( $return_url );
        $reg_url     = wp_registration_url();
        $nonce       = wp_create_nonce('bp_client_register');
        $error       = '';
        $success     = '';

        // Processar cadastro de cliente
        if ( isset($_POST['bp_register_nonce']) && wp_verify_nonce( sanitize_key($_POST['bp_register_nonce']), 'bp_client_register' ) ) {
            $name  = sanitize_text_field( $_POST['bp_name'] ?? '' );
            $email = sanitize_email( $_POST['bp_email'] ?? '' );
            $pass  = $_POST['bp_password'] ?? '';
            $phone = sanitize_text_field( $_POST['bp_phone'] ?? '' );

            if ( empty($name) || empty($email) || empty($pass) ) {
                $error = 'Preencha todos os campos obrigatórios.';
            } elseif ( ! is_email($email) ) {
                $error = 'E-mail inválido.';
            } elseif ( email_exists($email) ) {
                $error = 'Este e-mail já está cadastrado. Faça login.';
            } elseif ( strlen($pass) < 6 ) {
                $error = 'A senha deve ter pelo menos 6 caracteres.';
            } else {
                $user_id = wp_create_user( $email, $pass, $email );
                if ( is_wp_error($user_id) ) {
                    $error = $user_id->get_error_message();
                } else {
                    wp_update_user(['ID' => $user_id, 'display_name' => $name, 'first_name' => $name]);
                    if ( $phone ) update_user_meta( $user_id, 'bp_phone', $phone );
                    // Login automático após cadastro
                    wp_set_auth_cookie( $user_id, false );
                    wp_redirect( $return_url );
                    exit;
                }
            }
        }

        // Processar login de cliente
        if ( isset($_POST['bp_login_nonce']) && wp_verify_nonce( sanitize_key($_POST['bp_login_nonce']), 'bp_client_login' ) ) {
            $email = sanitize_email( $_POST['bp_login_email'] ?? '' );
            $pass  = $_POST['bp_login_pass'] ?? '';
            $user  = wp_authenticate( $email, $pass );
            if ( is_wp_error($user) ) {
                $error = 'E-mail ou senha incorretos.';
            } else {
                wp_set_auth_cookie( $user->ID, isset($_POST['bp_remember']) );
                wp_redirect( $return_url );
                exit;
            }
        }

        ob_start(); ?>
<style>
.bp-auth-wrap{display:flex;align-items:center;justify-content:center;min-height:60vh;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
.bp-auth-box{background:#fff;border-radius:16px;box-shadow:0 4px 32px rgba(0,0,0,.12);padding:36px 32px;width:100%;max-width:420px}
.bp-auth-logo{text-align:center;margin-bottom:24px;font-size:2rem}
.bp-auth-title{font-size:1.4rem;font-weight:700;color:#1a1a2e;margin:0 0 4px}
.bp-auth-sub{font-size:.9rem;color:#666;margin:0 0 24px}
.bp-auth-tabs{display:flex;gap:0;border-bottom:2px solid #eee;margin-bottom:24px}
.bp-auth-tab{flex:1;padding:10px;text-align:center;cursor:pointer;font-size:.95rem;font-weight:600;color:#888;border:none;background:none;border-bottom:2px solid transparent;margin-bottom:-2px;transition:color .2s,border-color .2s}
.bp-auth-tab.active{color:#e94560;border-bottom-color:#e94560}
.bp-auth-panel{display:none}.bp-auth-panel.active{display:block}
.bp-auth-field{margin-bottom:16px}
.bp-auth-field label{display:block;font-size:.85rem;font-weight:600;color:#333;margin-bottom:6px}
.bp-auth-field input{width:100%;box-sizing:border-box;padding:11px 14px;border:1.5px solid #ddd;border-radius:8px;font-size:.95rem;outline:none;transition:border-color .2s}
.bp-auth-field input:focus{border-color:#e94560}
.bp-auth-btn{width:100%;padding:13px;background:#e94560;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer;transition:background .2s;margin-top:4px}
.bp-auth-btn:hover{background:#c73652}
.bp-auth-error{background:#fee;border:1px solid #fcc;border-radius:8px;padding:10px 14px;font-size:.9rem;color:#c00;margin-bottom:16px}
.bp-auth-success{background:#efe;border:1px solid #cfc;border-radius:8px;padding:10px 14px;font-size:.9rem;color:#060;margin-bottom:16px}
.bp-auth-forgot{text-align:right;margin-top:8px;font-size:.85rem}
.bp-auth-forgot a{color:#e94560;text-decoration:none}
.bp-auth-remember{display:flex;align-items:center;gap:8px;font-size:.85rem;color:#555;margin:8px 0}
</style>

<div class="bp-auth-wrap">
  <div class="bp-auth-box">
    <div class="bp-auth-logo">✂️</div>
    <h2 class="bp-auth-title"><?php echo esc_html( BarberPro_Database::get_setting('business_name', get_bloginfo('name')) ); ?></h2>
    <p class="bp-auth-sub">Faça login ou cadastre-se para continuar</p>

    <?php if ($error): ?>
    <div class="bp-auth-error">⚠️ <?php echo esc_html($error); ?></div>
    <?php endif; ?>

    <div class="bp-auth-tabs">
      <button class="bp-auth-tab active" onclick="bpAuthTab('login',this)">Entrar</button>
      <button class="bp-auth-tab" onclick="bpAuthTab('register',this)">Cadastrar</button>
    </div>

    <!-- LOGIN -->
    <div class="bp-auth-panel active" id="bp-panel-login">
      <form method="post">
        <?php wp_nonce_field('bp_client_login','bp_login_nonce'); ?>
        <input type="hidden" name="bp_return" value="<?php echo $return_url; ?>">
        <div class="bp-auth-field">
          <label>E-mail *</label>
          <input type="email" name="bp_login_email" placeholder="seu@email.com" required autocomplete="email">
        </div>
        <div class="bp-auth-field">
          <label>Senha *</label>
          <input type="password" name="bp_login_pass" placeholder="Sua senha" required autocomplete="current-password">
        </div>
        <label class="bp-auth-remember">
          <input type="checkbox" name="bp_remember"> Lembrar de mim
        </label>
        <button type="submit" class="bp-auth-btn">Entrar</button>
        <div class="bp-auth-forgot"><a href="<?php echo esc_url(wp_lostpassword_url($return_url)); ?>">Esqueci minha senha</a></div>
      </form>
    </div>

    <!-- CADASTRO -->
    <div class="bp-auth-panel" id="bp-panel-register">
      <form method="post">
        <?php wp_nonce_field('bp_client_register','bp_register_nonce'); ?>
        <input type="hidden" name="bp_return" value="<?php echo $return_url; ?>">
        <div class="bp-auth-field">
          <label>Nome completo *</label>
          <input type="text" name="bp_name" placeholder="Seu nome" required autocomplete="name">
        </div>
        <div class="bp-auth-field">
          <label>E-mail *</label>
          <input type="email" name="bp_email" placeholder="seu@email.com" required autocomplete="email">
        </div>
        <div class="bp-auth-field">
          <label>WhatsApp</label>
          <input type="tel" name="bp_phone" placeholder="(00) 00000-0000" autocomplete="tel">
        </div>
        <div class="bp-auth-field">
          <label>Senha * <small style="color:#888">(mínimo 6 caracteres)</small></label>
          <input type="password" name="bp_password" placeholder="Crie uma senha" required minlength="6" autocomplete="new-password">
        </div>
        <button type="submit" class="bp-auth-btn">Criar conta</button>
      </form>
    </div>
  </div>
</div>

<script>
function bpAuthTab(tab, btn) {
  document.querySelectorAll('.bp-auth-tab').forEach(b=>b.classList.remove('active'));
  document.querySelectorAll('.bp-auth-panel').forEach(p=>p.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('bp-panel-'+tab).classList.add('active');
}
<?php if ($error && isset($_POST['bp_register_nonce'])): ?>
bpAuthTab('register', document.querySelectorAll('.bp-auth-tab')[1]);
<?php endif; ?>
</script>
<?php
        return ob_get_clean();
    }

    // =========================================================================
    // Shortcode: Agendamento público
    // =========================================================================

    public function shortcode_agendamento( $atts ): string {
        if ( ! BarberPro_License::is_active() ) {
            return '<p style="color:#ef4444;padding:20px">⚠️ Sistema indisponível.</p>';
        }

        $atts   = shortcode_atts(['module' => 'barbearia', 'company_id' => 0], $atts);
        $module = sanitize_key( $atts['module'] );

        // Agendamento é público (não exige login)
        $cid      = $atts['company_id'] ? (int)$atts['company_id'] : BarberPro_Modules::company_id($module);
        $services = BarberPro_Database::get_services( $cid );

        // Verifica se é lava-car (tem variantes)
        $has_variants = false;
        foreach ( $services as $svc ) {
            if ( ! empty( BarberPro_Database::get_service_variants((int)$svc->id) ) ) {
                $has_variants = true;
                break;
            }
        }
        $mode = $has_variants ? 'vehicle' : 'default';

        // Opções de delivery
        $delivery_options = [];
        $delivery_map = [
            'cliente_traz'                 => ['label'=>'🏠 Trago e busco o carro','icon'=>'🏠','fee'=>0,'needs_address'=>false],
            'empresa_busca_entrega'        => ['label'=>'🚐 Buscar e entregar (+ taxa)','icon'=>'🚐','fee'=>(float)BarberPro_Database::get_setting('delivery_fee_busca_entrega',0),'needs_address'=>true],
            'empresa_busca_cliente_retira' => ['label'=>'📦 Só buscar (eu retiro)','icon'=>'📦','fee'=>(float)BarberPro_Database::get_setting('delivery_fee_busca_retira',0),'needs_address'=>true],
            'cliente_leva_empresa_entrega' => ['label'=>'🏁 Eu levo, entregam pra mim','icon'=>'🏁','fee'=>(float)BarberPro_Database::get_setting('delivery_fee_leva_entrega',0),'needs_address'=>true],
        ];
        foreach ( $delivery_map as $k => $v ) {
            if ( BarberPro_Database::get_setting('delivery_opt_'.$k, '1') ) {
                $delivery_options[$k] = $v;
            }
        }
        $delivery_json   = wp_json_encode($delivery_options);
        $delivery_max_km = BarberPro_Database::get_setting('delivery_max_km', 10);
        $delivery_msg    = str_replace('{raio}', $delivery_max_km, BarberPro_Database::get_setting('delivery_info_msg',''));

        // Template
        $tpl = BARBERPRO_PLUGIN_DIR . 'public/templates/agendamento.php';
        if ( ! file_exists($tpl) ) {
            return '<p style="color:#ef4444">Template de agendamento não encontrado.</p>';
        }

        ob_start();
        require $tpl;
        return ob_get_clean();
    }

    // =========================================================================
    // Shortcode: Painel do cliente
    // =========================================================================

    public function shortcode_painel_cliente( $atts ): string {
        if ( ! BarberPro_License::is_active() ) {
            return '<p style="color:#ef4444;padding:20px">⚠️ Sistema indisponível.</p>';
        }

        // Exige login
        if ( ! is_user_logged_in() ) {
            return $this->render_client_auth( get_permalink() );
        }

        $user     = wp_get_current_user();
        $bookings = BarberPro_Database::get_bookings([
            'client_email' => $user->user_email,
        ]);

        $tpl = BARBERPRO_PLUGIN_DIR . 'public/templates/painel-cliente.php';
        if ( ! file_exists($tpl) ) {
            return '<p style="color:#ef4444">Template painel-cliente não encontrado.</p>';
        }

        ob_start();
        require $tpl;
        return ob_get_clean();
    }

    // =========================================================================
    // Shortcode: Painel administrativo frontend
    // =========================================================================
    // Shortcode: Loja Virtual
    // =========================================================================

    public function shortcode_loja( $atts ): string {
        if ( ! BarberPro_License::is_active() ) {
            return '<p style="color:#ef4444;padding:20px">⚠️ Sistema indisponível.</p>';
        }
        $atts = shortcode_atts(['company' => 'all', 'show_title' => '0', 'colunas' => '3'], $atts);
        $company = sanitize_key($atts['company']);
        $cid     = $company === 'barbearia' ? 1 : ($company === 'lavacar' ? 2 : 1);

        wp_enqueue_style(  'bp-loja', BARBERPRO_PLUGIN_URL . 'assets/css/loja.css', [], BARBERPRO_VERSION );

        $loja_company    = $company;
        $loja_company_id = $cid;
        $loja_show_title = $atts['show_title'];
        $loja_colunas    = max(1, min(4, (int)$atts['colunas']));

        ob_start();
        require BARBERPRO_PLUGIN_DIR . 'public/templates/loja.php';
        return ob_get_clean();
    }

    // =========================================================================

    public function shortcode_painel_admin( $atts ): string {
        if ( ! BarberPro_License::is_active() ) {
            return '<p style="color:#ef4444;padding:20px">⚠️ Sistema indisponível.</p>';
        }

        // Exige login
        if ( ! is_user_logged_in() ) {
            return $this->render_client_auth( get_permalink() );
        }

        $atts          = shortcode_atts(['role' => 'barberpro_manage_bookings', 'module' => ''], $atts);
        $required_cap  = sanitize_key( $atts['role'] );

        // Verifica permissão
        if ( ! current_user_can($required_cap) && ! current_user_can('manage_options') ) {
            return '<div style="padding:32px;text-align:center;font-family:sans-serif">
                <div style="font-size:3rem">🔒</div>
                <h3>Acesso não autorizado</h3>
                <p>Você não tem permissão para acessar este painel.</p>
                <a href="' . esc_url(wp_logout_url(get_permalink())) . '" style="color:#e94560">Sair</a>
            </div>';
        }

        $bp_module_attr = sanitize_key( $atts['module'] ?: ( $_GET['bp_module'] ?? 'barbearia' ) );

        $tpl = BARBERPRO_PLUGIN_DIR . 'public/templates/painel-admin.php';
        if ( ! file_exists($tpl) ) {
            return '<p style="color:#ef4444">Template painel-admin não encontrado.</p>';
        }

        ob_start();
        require $tpl;
        return ob_get_clean();
    }

    /**
     * Registra versão bloqueada dos shortcodes quando sem licença ativa.
     * Mostra mensagem discreta no lugar do conteúdo.
     */
    public function register_shortcodes_blocked(): void {
        $msg = '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;font-family:sans-serif;color:#856404">'
             . '⚠️ <strong>BarberPro:</strong> Licença inativa. Entre em contato com o administrador do site.'
             . '</div>';

        add_shortcode( 'barberpro_agendamento',    function() use ($msg) { return $msg; } );
        add_shortcode( 'barberpro_painel_cliente', function() use ($msg) { return $msg; } );
        add_shortcode( 'barberpro_painel_admin',   function() use ($msg) { return $msg; } );
    }

    // =========================================================================
    // AJAX: Slots de horário
    // =========================================================================

    public function ajax_get_slots(): void {
        check_ajax_referer( 'barberpro_booking', 'nonce' );

        $pro_id    = (int) ( $_POST['professional_id'] ?? 0 );
        $date      = sanitize_text_field( $_POST['date'] ?? '' );
        $svc_id    = (int) ( $_POST['service_id'] ?? 0 );
        $company_id= (int) ( $_POST['company_id'] ?? 1 );

        if ( ! $date ) {
            wp_send_json_error( ['message' => 'Data inválida.'] );
        }

        $service  = $svc_id ? BarberPro_Database::get_service( $svc_id ) : null;
        $duration = $service ? (int) ( $service->duration_minutes ?? $service->duration ?? 30 ) : 30;
        $admin_mode = current_user_can('manage_options') || current_user_can('barberpro_manage_bookings');

        // Se pro_id = 0 (Primeiro disponível) — busca slots de todos os profissionais da empresa
        if ( $pro_id === 0 ) {
            $pros   = BarberPro_Database::get_professionals( $company_id );
            $merged = [];
            foreach ( $pros as $p ) {
                $slots = BarberPro_Bookings::get_available_slots( (int)$p->id, $date, $duration, $admin_mode );
                foreach ( $slots as $s ) $merged[$s] = true;
            }
            ksort( $merged );
            wp_send_json_success( ['slots' => array_keys($merged)] );
            return;
        }

        $slots = BarberPro_Bookings::get_available_slots( $pro_id, $date, $duration, $admin_mode );
        wp_send_json_success( ['slots' => $slots] );
    }

    // =========================================================================
    // AJAX: Criar agendamento público
    // =========================================================================

    public function ajax_create_booking(): void {
        check_ajax_referer( 'barberpro_booking', 'nonce' );

        $data = [
            'professional_id'  => (int)   ( $_POST['professional_id']  ?? 0 ),
            'service_id'       => (int)   ( $_POST['service_id']        ?? 0 ),
            'booking_date'     => sanitize_text_field( $_POST['booking_date']  ?? '' ),
            'booking_time'     => sanitize_text_field( $_POST['booking_time']  ?? '' ),
            'client_name'      => sanitize_text_field( $_POST['client_name']   ?? '' ),
            'client_phone'     => sanitize_text_field( $_POST['client_phone']  ?? '' ),
            'client_email'     => sanitize_email(      $_POST['client_email']  ?? '' ),
            'notes'            => sanitize_textarea_field( $_POST['notes']     ?? '' ),
            'company_id'       => (int)   ( $_POST['company_id']        ?? 1 ),
            'payment_method'   => sanitize_key( $_POST['payment_method'] ?? 'presencial' ),
            'status'           => 'agendado',
            // Campos de veículo / lava-car
            'vehicle_plate'    => sanitize_text_field( $_POST['vehicle_plate']   ?? '' ) ?: null,
            'vehicle_model'    => sanitize_text_field( $_POST['vehicle_model']   ?? '' ) ?: null,
            'vehicle_color'    => sanitize_text_field( $_POST['vehicle_color']   ?? '' ) ?: null,
            'vehicle_size'     => sanitize_key(        $_POST['vehicle_size']    ?? '' ) ?: null,
            'service_variant'  => sanitize_text_field( $_POST['service_variant'] ?? '' ) ?: null,
            'amount_variant'   => ! empty($_POST['amount_variant']) ? (float)$_POST['amount_variant'] : null,
            'delivery_type'    => sanitize_key(       $_POST['delivery_type']    ?? '' ) ?: null,
            'delivery_address' => sanitize_textarea_field( $_POST['delivery_address'] ?? '' ) ?: null,
            'delivery_fee'     => (float) ( $_POST['delivery_fee'] ?? 0 ),
        ];

        if ( empty($data['client_name']) ) {
            wp_send_json_error( ['message' => 'Nome é obrigatório.'] );
        }
        if ( empty($data['client_phone']) ) {
            wp_send_json_error( ['message' => 'Telefone é obrigatório.'] );
        }
        if ( empty($data['service_id']) ) {
            wp_send_json_error( ['message' => 'Selecione um serviço.'] );
        }
        if ( empty($data['booking_date']) || empty($data['booking_time']) ) {
            wp_send_json_error( ['message' => 'Data e horário são obrigatórios.'] );
        }

        // Chama create_booking (retorna array com 'success', 'booking_id', 'message')
        $result = BarberPro_Bookings::create_booking( $data );

        if ( empty($result['success']) ) {
            wp_send_json_error( ['message' => $result['message'] ?? 'Erro ao criar agendamento.'] );
        }

        $booking_id = (int) $result['booking_id'];
        $booking    = BarberPro_Database::get_booking( $booking_id );

        // Notifica cliente
        if ( $booking ) {
            BarberPro_Notifications::dispatch( 'confirmation', $booking );
        }

        wp_send_json_success( [
            'message'      => 'Agendamento realizado com sucesso!',
            'booking_id'   => $booking_id,
            'booking_code' => $booking->booking_code ?? $result['booking_code'] ?? '',
            'date'         => $data['booking_date'],
            'time'         => substr($data['booking_time'], 0, 5),
            'service_name' => $booking->service_name ?? '',
        ] );
    }

    // =========================================================================
    // AJAX: Cancelar agendamento (cliente)
    // =========================================================================

    public function ajax_cancel_booking(): void {
        check_ajax_referer( 'barberpro_booking', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( ['message' => 'Login necessário.'] );
        }

        $booking_id = (int) ( $_POST['booking_id'] ?? 0 );
        if ( ! $booking_id ) {
            wp_send_json_error( ['message' => 'ID inválido.'] );
        }

        $booking = BarberPro_Database::get_booking( $booking_id );
        if ( ! $booking ) {
            wp_send_json_error( ['message' => 'Agendamento não encontrado.'] );
        }

        // Garante que o cliente só cancela os próprios agendamentos
        $user = wp_get_current_user();
        if ( $booking->client_email !== $user->user_email && ! current_user_can('manage_options') ) {
            wp_send_json_error( ['message' => 'Sem permissão.'] );
        }

        // Verifica antecedência mínima para cancelamento
        $cancel_hours = (int) BarberPro_Database::get_setting('cancellation_hours', 2);
        $booking_ts   = strtotime( $booking->booking_date . ' ' . $booking->booking_time );
        if ( $booking_ts - time() < $cancel_hours * 3600 ) {
            wp_send_json_error( ['message' => "Cancelamento só é permitido com {$cancel_hours}h de antecedência."] );
        }

        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}barber_bookings",
            ['status' => 'cancelado'],
            ['id' => $booking_id]
        );

        if ( BarberPro_Database::get_setting('msg_cancellation_active','1') ) {
            BarberPro_Notifications::dispatch( 'cancellation', $booking );
        }

        wp_send_json_success( ['message' => 'Agendamento cancelado.'] );
    }
}
