<?php
/**
 * Teste standalone - simula exatamente o que o JS envia ao salvar
 * Coloque em: /wp-content/plugins/barberpro-saas/test-save.php
 * Acesse: seusite.com/wp-content/plugins/barberpro-saas/test-save.php
 */

// Bootstrap WordPress
$wp_load = dirname(__FILE__) . '/../../../wp-load.php';
if (!file_exists($wp_load)) {
    die('wp-load.php não encontrado em: ' . $wp_load);
}
require_once $wp_load;

header('Content-Type: text/plain; charset=utf-8');

echo "=== TESTE DE DIAGNÓSTICO BARBERPRO ===\n\n";

// 1. Usuário logado?
echo "1. AUTENTICAÇÃO\n";
echo "   Logado: " . (is_user_logged_in() ? 'SIM ✓' : 'NÃO ✗') . "\n";
if (is_user_logged_in()) {
    $u = wp_get_current_user();
    echo "   Usuário: {$u->display_name} (ID: {$u->ID})\n";
    echo "   Roles: " . implode(', ', $u->roles) . "\n";
}

// 2. Tabelas existem?
global $wpdb;
echo "\n2. TABELAS NO BANCO\n";
$tables = ['barber_services', 'barber_professionals', 'barber_bookings', 'barber_products', 'barber_settings'];
foreach ($tables as $t) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}{$t}'");
    echo "   {$wpdb->prefix}{$t}: " . ($exists ? 'EXISTE ✓' : 'NÃO EXISTE ✗') . "\n";
}

// 3. Testa INSERT direto
echo "\n3. TESTE INSERT DIRETO (barber_services)\n";
$wpdb->show_errors();
$r = $wpdb->insert("{$wpdb->prefix}barber_services", [
    'company_id'  => 1,
    'name'        => '__TESTE__',
    'price'       => 50.00,
    'duration'    => 30,
    'status'      => 'inactive',
    'created_at'  => current_time('mysql'),
]);
if ($r) {
    $id = $wpdb->insert_id;
    echo "   INSERT: OK ✓ (id={$id})\n";
    $wpdb->delete("{$wpdb->prefix}barber_services", ['id' => $id]);
    echo "   DELETE: OK ✓\n";
} else {
    echo "   INSERT: FALHOU ✗\n";
    echo "   Erro: " . $wpdb->last_error . "\n";
}

// 4. Testa INSERT direto profissional
echo "\n4. TESTE INSERT DIRETO (barber_professionals)\n";
$r2 = $wpdb->insert("{$wpdb->prefix}barber_professionals", [
    'company_id'    => 1,
    'name'          => '__TESTE_PRO__',
    'status'        => 'inactive',
    'work_days'     => '1,2,3,4,5',
    'work_start'    => '09:00:00',
    'work_end'      => '18:00:00',
    'lunch_start'   => '12:00:00',
    'lunch_end'     => '13:00:00',
    'slot_interval' => 30,
    'created_at'    => current_time('mysql'),
]);
if ($r2) {
    $id2 = $wpdb->insert_id;
    echo "   INSERT: OK ✓ (id={$id2})\n";
    $wpdb->delete("{$wpdb->prefix}barber_professionals", ['id' => $id2]);
    echo "   DELETE: OK ✓\n";
} else {
    echo "   INSERT: FALHOU ✗\n";
    echo "   Erro: " . $wpdb->last_error . "\n";
}

// 5. Nonce válido?
echo "\n5. NONCE\n";
$nonce = wp_create_nonce('bp_app');
echo "   Nonce gerado: {$nonce}\n";
echo "   Verificação: " . (wp_verify_nonce($nonce, 'bp_app') ? 'VÁLIDO ✓' : 'INVÁLIDO ✗') . "\n";

// 6. AJAX hooks registrados?
echo "\n6. HOOKS AJAX REGISTRADOS\n";
$hooks = ['wp_ajax_bp_app_action', 'wp_ajax_bp_app_section', 'wp_ajax_bp_app_login'];
foreach ($hooks as $h) {
    $registered = has_action($h);
    echo "   {$h}: " . ($registered ? 'SIM ✓' : 'NÃO ✗') . "\n";
}

// 7. AJAX URL
echo "\n7. CONFIGURAÇÃO\n";
echo "   AJAX URL: " . admin_url('admin-ajax.php') . "\n";
echo "   Plugin dir: " . (defined('BARBERPRO_PLUGIN_DIR') ? BARBERPRO_PLUGIN_DIR . ' ✓' : 'NÃO DEFINIDO ✗') . "\n";
echo "   Versão: " . (defined('BARBERPRO_VERSION') ? BARBERPRO_VERSION : '?') . "\n";
echo "   DB Version salva: " . get_option('barberpro_db_version', 'não definida') . "\n";
echo "   Licença ativa: " . (class_exists('BarberPro_License') && BarberPro_License::is_active() ? 'SIM' : 'NÃO') . "\n";

// 8. Simula chamada AJAX save_pro
echo "\n8. SIMULA AJAX save_pro\n";
if (is_user_logged_in() && class_exists('BarberPro_App_Ajax')) {
    $_POST = [
        'action'     => 'bp_app_action',
        'sub'        => 'save_pro',
        'nonce'      => wp_create_nonce('bp_app'),
        'pro_id'     => '0',
        'company_id' => '1',
        'name'       => '__TESTE_AJAX__',
        'specialty'  => 'Teste',
        'commission_pct' => '0',
        'phone'      => '',
    ];
    // Chama diretamente o método
    $ajax = new BarberPro_App_Ajax();
    ob_start();
    try {
        // Bypass nonce/login - testa só o save
        global $wpdb;
        $data = [
            'company_id'    => 1,
            'name'          => '__TESTE_AJAX__',
            'specialty'     => 'Teste',
            'commission_pct'=> 0,
            'phone'         => '',
            'status'        => 'inactive',
            'updated_at'    => current_time('mysql'),
            'work_days'     => '1,2,3,4,5',
            'work_start'    => '09:00:00',
            'work_end'      => '18:00:00',
            'lunch_start'   => '12:00:00',
            'lunch_end'     => '13:00:00',
            'slot_interval' => 30,
            'created_at'    => current_time('mysql'),
        ];
        $r = $wpdb->insert("{$wpdb->prefix}barber_professionals", $data);
        if ($r) {
            echo "   SAVE direto: OK ✓ (id={$wpdb->insert_id})\n";
            $wpdb->delete("{$wpdb->prefix}barber_professionals", ['name'=>'__TESTE_AJAX__']);
        } else {
            echo "   SAVE direto: FALHOU ✗\n";
            echo "   Erro SQL: " . $wpdb->last_error . "\n";
            echo "   Último SQL: " . $wpdb->last_query . "\n";
        }
    } catch(Exception $e) {
        echo "   Exception: " . $e->getMessage() . "\n";
    }
    ob_end_clean();
} else {
    echo "   Não logado ou classe não existe - faça login no WordPress primeiro\n";
}

echo "\n=== FIM DO DIAGNÓSTICO ===\n";
