<?php
/**
 * BarberPro Diagnóstico 3 - Verifica o HTML real da página
 * Acesse: seusite.com/wp-content/plugins/barberpro-saas/diag3.php
 */
require_once dirname(__FILE__) . '/../../../wp-load.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO 3 - HTML REAL DA PÁGINA ===\n\n";

// Busca a página com barberpro_app
global $wpdb;
$page = $wpdb->get_row(
    "SELECT ID, post_title FROM {$wpdb->posts}
     WHERE post_status='publish' AND post_type='page'
     AND post_content LIKE '%barberpro_app%' LIMIT 1"
);

if (!$page) {
    echo "ERRO: Nenhuma página com [barberpro_app] encontrada!\n";
    exit;
}

$url = get_permalink($page->ID);
echo "Página: '{$page->post_title}' (ID:{$page->ID})\n";
echo "URL: {$url}\n\n";

// Busca o HTML real usando wp_remote_get
$response = wp_remote_get($url, [
    'timeout' => 15,
    'cookies' => [],
    'headers' => ['Accept' => 'text/html'],
]);

if (is_wp_error($response)) {
    echo "ERRO ao buscar página: " . $response->get_error_message() . "\n";
    exit;
}

$html = wp_remote_retrieve_body($response);
$code = wp_remote_retrieve_response_code($response);

echo "HTTP Status: {$code}\n";
echo "HTML size: " . strlen($html) . " bytes\n\n";

echo "=== VERIFICAÇÕES NO HTML ===\n";
$checks = [
    'bpAppRoot'       => 'div#bpAppRoot presente',
    'bpApp'           => 'div#bpApp presente',
    'bpLogin'         => 'div#bpLogin presente',
    'app.css'         => 'CSS app.css carregado',
    'app.js'          => 'JS app.js carregado',
    'bpAppData'       => 'bpAppData injetado',
    'bp-app-page'     => 'classe bp-app-page no body',
    'barberpro-app'   => 'handle barberpro-app',
];
foreach ($checks as $needle => $label) {
    $found = strpos($html, $needle) !== false;
    echo ($found ? '  ✓ ' : '  ✗ ') . $label . "\n";
}

echo "\n=== SCRIPTS NO <HEAD> ===\n";
preg_match('/<head[^>]*>(.*?)<\/head>/si', $html, $head_match);
$head = $head_match[1] ?? '';
preg_match_all('/<script[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $head, $scripts);
foreach ($scripts[1] as $src) {
    if (str_contains($src, 'barberpro') || str_contains($src, 'chart')) {
        echo "  HEAD SCRIPT: $src\n";
    }
}

echo "\n=== SCRIPTS NO <BODY> / FOOTER ===\n";
preg_match_all('/<script[^>]*src=["\']([^"\']+)["\'][^>]*>/i', $html, $all_scripts);
foreach ($all_scripts[1] as $src) {
    if (str_contains($src, 'barberpro') || str_contains($src, 'chart')) {
        echo "  BODY SCRIPT: $src\n";
    }
}

echo "\n=== CSS ===\n";
preg_match_all('/<link[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $css);
foreach ($css[1] as $href) {
    if (str_contains($href, 'barberpro')) {
        echo "  CSS: $href\n";
    }
}

echo "\n=== bpAppData (primeiros 500 chars) ===\n";
$pos = strpos($html, 'bpAppData');
if ($pos !== false) {
    echo substr($html, $pos - 10, 500) . "\n";
} else {
    echo "  NÃO ENCONTRADO NO HTML!\n";
    echo "  -> O wp_localize_script NÃO está injetando o bpAppData\n";
    echo "  -> O enqueue_assets() provavelmente não está sendo chamado\n";
    echo "  -> Verifique se o tema tem wp_head() e wp_footer() no template\n";
}

echo "\n=== FIM ===\n";
