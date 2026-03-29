<?php
require_once dirname(__FILE__) . '/../../../wp-load.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO v2 ===\n\n";

echo "1. LICENÇA: ";
$lic = BarberPro_License::status();
echo ($lic['valid'] ? 'VÁLIDA ✓' : 'INVÁLIDA ✗') . "\n\n";

echo "2. SHORTCODES REGISTRADOS\n";
global $shortcode_tags;
foreach (['barberpro_app','barberpro_bar','barberpro_bar_caixa'] as $sc) {
    echo "   [{$sc}]: " . (isset($shortcode_tags[$sc]) ? 'SIM ✓' : 'NÃO ✗') . "\n";
}

echo "\n3. PÁGINA COM SHORTCODE\n";
global $wpdb;
$pages = $wpdb->get_results(
    "SELECT ID, post_title, post_content FROM {$wpdb->posts}
     WHERE post_status='publish' AND post_type='page'
     AND (post_content LIKE '%barberpro_app%' OR post_content LIKE '%barberpro_bar%')"
);
if (empty($pages)) {
    echo "   NENHUMA PÁGINA ENCONTRADA COM OS SHORTCODES!\n";
    echo "   Crie uma página com [barberpro_app] no conteúdo.\n";
} else {
    foreach ($pages as $p) {
        echo "   ID:{$p->ID} '{$p->post_title}'\n";
        echo "   URL: " . get_permalink($p->ID) . "\n";
        preg_match_all('/\[barberpro_\w+\]/', $p->post_content, $m);
        echo "   Shortcodes: " . implode(', ', $m[0]) . "\n";
    }
}

echo "\n4. ARQUIVOS CSS/JS\n";
$url = BARBERPRO_PLUGIN_URL;
$dir = BARBERPRO_PLUGIN_DIR;
foreach (['assets/css/app.css','assets/js/app.js'] as $f) {
    $size = file_exists($dir.$f) ? round(filesize($dir.$f)/1024,1).'KB' : 'FALTA ✗';
    echo "   {$f}: {$size}\n";
    echo "   URL: {$url}{$f}\n";
}

echo "\n5. TESTE HTML DO SHORTCODE\n";
$frontend = new BarberPro_Frontend();
$html = $frontend->shortcode_app([]);
echo "   Bytes gerados: " . strlen($html) . "\n";
echo "   Tem bpAppRoot: " . (strpos($html,'bpAppRoot')!==false ? 'SIM ✓' : 'NÃO ✗') . "\n";
echo "   Tem bpApp: " . (strpos($html,'id=\"bpApp\"')!==false ? 'SIM ✓' : 'NÃO ✗') . "\n";
echo "   Tem bpLogin: " . (strpos($html,'bpLogin')!==false ? 'SIM ✓' : 'NÃO ✗') . "\n";

echo "\n6. bpAppData (gerado pelo wp_localize_script)\n";
// Simulate what wp_localize_script would inject
$is_logged = is_user_logged_in();
echo "   Usuário logado: " . ($is_logged ? 'SIM' : 'NÃO') . "\n";
$active_mods = [];
foreach (BarberPro_Modules::active_list() as $key => $mod) {
    $active_mods[] = $key;
}
echo "   Módulos ativos: " . implode(', ', $active_mods) . "\n";
echo "   ajaxUrl: " . admin_url('admin-ajax.php') . "\n";

echo "\n7. VERIFICAÇÃO DE CONFLITO DE SCRIPTS\n";
// Check if wp_enqueue_scripts action fires (simulated)
echo "   BARBERPRO_VERSION: " . BARBERPRO_VERSION . "\n";
echo "   wp_enqueue_scripts hook registrado: ";
global $wp_filter;
$hook = 'wp_enqueue_scripts';
if (isset($wp_filter[$hook])) {
    $cbs = [];
    foreach ($wp_filter[$hook]->callbacks as $prio => $calls) {
        foreach ($calls as $key => $cb) {
            $name = is_array($cb['function']) ? get_class($cb['function'][0]).'::'.($cb['function'][1]) : $cb['function'];
            if (str_contains((string)$name, 'barberpro') || str_contains((string)$name, 'BarberPro')) {
                $cbs[] = "prio={$prio}: {$name}";
            }
        }
    }
    echo count($cbs) > 0 ? 'SIM ✓ (' . implode(', ', $cbs) . ')' : 'NÃO ENCONTRADO ✗';
} else {
    echo "hook não existe ainda\n";
}

echo "\n\n=== FIM ===\n";
