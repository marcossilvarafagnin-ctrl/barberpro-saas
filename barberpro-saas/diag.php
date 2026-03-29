<?php
/**
 * BarberPro Diagnóstico - acesse: seusite.com/wp-content/plugins/barberpro-saas/diag.php
 */
define('SHORTINIT', false);
$wp_load = dirname(__FILE__) . '/../../../wp-load.php';
require_once $wp_load;

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head><title>BarberPro Diag</title>
<style>
body{font-family:monospace;padding:20px;background:#111;color:#eee}
h2{color:#f90}
.ok{color:#0f0} .fail{color:#f44} .warn{color:#fa0}
table{border-collapse:collapse;width:100%}
td,th{padding:6px 10px;border:1px solid #333;text-align:left}
th{background:#222}
.section{background:#1a1a1a;padding:14px;margin:14px 0;border-left:3px solid #f90}
pre{background:#000;padding:10px;overflow:auto;font-size:.85rem}
button{background:#f90;border:none;padding:8px 16px;cursor:pointer;font-weight:bold;margin:4px}
input,select{background:#222;color:#eee;border:1px solid #555;padding:6px;margin:4px;width:200px}
#result{background:#000;padding:14px;margin-top:10px;white-space:pre-wrap;max-height:400px;overflow:auto}
</style>
</head>
<body>
<h1>🔧 BarberPro Diagnóstico</h1>

<?php
global $wpdb;
$p = $wpdb->prefix;
$ok = '<span class="ok">✓ OK</span>';
$fail = '<span class="fail">✗ FALHOU</span>';
$warn = '<span class="warn">⚠ AVISO</span>';

// 1. AUTH
echo '<div class="section"><h2>1. Autenticação</h2>';
$logged = is_user_logged_in();
echo 'Logado: ' . ($logged ? $ok . ' — ' . wp_get_current_user()->display_name : $fail . ' — Não logado') . '<br>';
if ($logged) {
    $u = wp_get_current_user();
    echo 'Roles: <strong>' . implode(', ', $u->roles) . '</strong><br>';
    echo 'manage_options: ' . (current_user_can('manage_options') ? $ok : $fail) . '<br>';
    echo 'barberpro_manage_services: ' . (current_user_can('barberpro_manage_services') ? $ok : $fail . ' ← <strong>PROBLEMA!</strong>') . '<br>';
    echo 'barberpro_manage_staff: ' . (current_user_can('barberpro_manage_staff') ? $ok : $fail . ' ← <strong>PROBLEMA!</strong>') . '<br>';
}
echo '</div>';

// 2. TABELAS
echo '<div class="section"><h2>2. Tabelas no Banco</h2>';
echo '<table><tr><th>Tabela</th><th>Existe</th><th>Colunas principais</th></tr>';
$tables = ['barber_companies','barber_services','barber_professionals','barber_bookings','barber_products','barber_settings','barber_bar_comandas'];
foreach ($tables as $t) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$p}{$t}'");
    $cols = '';
    if ($exists) {
        $c = $wpdb->get_results("SHOW COLUMNS FROM `{$p}{$t}`", ARRAY_A);
        $cols = implode(', ', array_column($c, 'Field'));
    }
    echo '<tr><td>' . $p.$t . '</td><td>' . ($exists ? $ok : $fail) . '</td><td style="font-size:.75rem">' . esc_html($cols) . '</td></tr>';
}
echo '</table></div>';

// 3. INSERT DIRETO
echo '<div class="section"><h2>3. Teste INSERT Direto</h2>';
$wpdb->show_errors();

// Serviço
$r = $wpdb->insert("{$p}barber_services", [
    'company_id'=>1,'name'=>'__DIAG__','price'=>10,'duration'=>30,
    'status'=>'inactive','created_at'=>current_time('mysql')
]);
if ($r) {
    echo 'INSERT barber_services: ' . $ok . ' (id=' . $wpdb->insert_id . ')<br>';
    $wpdb->delete("{$p}barber_services", ['name'=>'__DIAG__']);
} else {
    echo 'INSERT barber_services: ' . $fail . '<br>';
    echo 'Erro SQL: <pre>' . esc_html($wpdb->last_error) . '</pre>';
    echo 'Query: <pre>' . esc_html($wpdb->last_query) . '</pre>';
}

// Profissional
$r2 = $wpdb->insert("{$p}barber_professionals", [
    'company_id'=>1,'name'=>'__DIAG__','status'=>'inactive',
    'work_days'=>'1,2,3,4,5','work_start'=>'09:00:00','work_end'=>'18:00:00',
    'lunch_start'=>'12:00:00','lunch_end'=>'13:00:00','slot_interval'=>30,
    'created_at'=>current_time('mysql')
]);
if ($r2) {
    echo 'INSERT barber_professionals: ' . $ok . ' (id=' . $wpdb->insert_id . ')<br>';
    $wpdb->delete("{$p}barber_professionals", ['name'=>'__DIAG__']);
} else {
    echo 'INSERT barber_professionals: ' . $fail . '<br>';
    echo 'Erro SQL: <pre>' . esc_html($wpdb->last_error) . '</pre>';
    echo 'Query: <pre>' . esc_html($wpdb->last_query) . '</pre>';
}
echo '</div>';

// 4. HOOKS AJAX
echo '<div class="section"><h2>4. Hooks AJAX Registrados</h2>';
$hooks = ['wp_ajax_bp_app_action','wp_ajax_bp_app_section','wp_ajax_barberpro_save_service','wp_ajax_barberpro_save_professional'];
foreach ($hooks as $h) {
    echo $h . ': ' . (has_action($h) ? $ok : $fail) . '<br>';
}
echo '</div>';

// 5. NONCE
echo '<div class="section"><h2>5. Nonce</h2>';
$n = wp_create_nonce('bp_app');
$n2 = wp_create_nonce('barberpro_ajax');
echo 'bp_app nonce: <code>' . $n . '</code> — Verificação: ' . (wp_verify_nonce($n,'bp_app') ? $ok : $fail) . '<br>';
echo 'barberpro_ajax nonce: <code>' . $n2 . '</code> — Verificação: ' . (wp_verify_nonce($n2,'barberpro_ajax') ? $ok : $fail) . '<br>';
echo '</div>';

// 6. TESTE AJAX REAL
echo '<div class="section"><h2>6. Teste AJAX Real (simula clique em Salvar)</h2>';
$ajax_url = admin_url('admin-ajax.php');
echo 'AJAX URL: <code>' . $ajax_url . '</code><br><br>';
echo '<strong>Teste save_pro (SPA):</strong><br>';
echo '<input type="text" id="pro_name" value="Teste Rafael" placeholder="Nome"><br>';
echo '<button onclick="testSavePro()">▶ Testar save_pro</button>';
echo '<button onclick="testSaveService()">▶ Testar save_service</button>';
echo '<button onclick="testAdminSave()">▶ Testar admin save</button>';
echo '<button onclick="testDiag()">▶ Diagnóstico completo</button>';
echo '<div id="result">Aguardando...</div>';
?>

<script>
var ajaxUrl = '<?php echo esc_js($ajax_url); ?>';
var nonceBp = '<?php echo esc_js(wp_create_nonce('bp_app')); ?>';
var nonceAdmin = '<?php echo esc_js(wp_create_nonce('barberpro_ajax')); ?>';

function show(txt) {
    document.getElementById('result').textContent = typeof txt === 'object' ? JSON.stringify(txt, null, 2) : txt;
}

function post(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k,v));
    return fetch(ajaxUrl, {method:'POST', body:fd, credentials:'include'})
        .then(r => r.text())
        .then(text => {
            try { return JSON.parse(text); } catch(e) { return {raw: text}; }
        });
}

function testSavePro() {
    show('Enviando...');
    post({
        action: 'bp_app_action',
        sub: 'save_pro',
        nonce: nonceBp,
        pro_id: '0',
        company_id: '1',
        name: document.getElementById('pro_name').value || 'Teste',
        specialty: 'Diagnóstico',
        commission_pct: '0',
        phone: '',
    }).then(show);
}

function testSaveService() {
    show('Enviando...');
    post({
        action: 'bp_app_action',
        sub: 'save_service',
        nonce: nonceBp,
        service_id: '0',
        company_id: '1',
        name: 'Serviço Teste Diag',
        price: '50',
        duration_minutes: '30',
        description: '',
    }).then(show);
}

function testAdminSave() {
    show('Enviando...');
    post({
        action: 'barberpro_save_professional',
        nonce: nonceAdmin,
        id: '0',
        company_id: '1',
        name: document.getElementById('pro_name').value || 'Teste Admin',
        specialty: 'Diagnóstico',
        commission_pct: '40',
        work_days: '1,2,3,4,5',
        work_start: '09:00',
        work_end: '18:00',
        lunch_start: '12:00',
        lunch_end: '13:00',
        slot_interval: '30',
    }).then(show);
}

function testDiag() {
    show('Enviando...');
    post({action:'bp_diag'}).then(show);
}
</script>

<?php
// 7. VERSÕES
echo '<div class="section"><h2>7. Informações do Sistema</h2>';
echo 'PHP: ' . PHP_VERSION . '<br>';
echo 'WordPress: ' . get_bloginfo('version') . '<br>';
echo 'Plugin version: ' . (defined('BARBERPRO_VERSION') ? BARBERPRO_VERSION : '?') . '<br>';
echo 'DB version salva: ' . get_option('barberpro_db_version','não definida') . '<br>';
echo 'DB version esperada: ' . (defined('BARBERPRO_DB_VERSION') ? BARBERPRO_DB_VERSION : '?') . '<br>';
echo 'Licença ativa: ' . (class_exists('BarberPro_License') && BarberPro_License::is_active() ? $ok : $warn . ' inativa') . '<br>';
echo 'Prefixo BD: <code>' . $p . '</code><br>';
echo '</div>';
echo '<p style="color:#555;font-size:.8rem">Remova este arquivo após o diagnóstico: <code>diag.php</code></p>';
?>
</body></html>
