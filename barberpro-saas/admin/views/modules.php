<?php
/**
 * View – Gerenciamento de Módulos
 * BarberPro → Módulos
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sem permissão.' );

// ── Processar POST ────────────────────────────────────────────────────────────
if ( isset( $_POST['bp_modules_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['bp_modules_nonce'] ), 'bp_save_modules' ) ) {
    foreach ( BarberPro_Modules::all() as $key => $meta ) {
        $should_be_active = ! empty( $_POST["module_{$key}"] );
        $currently_active = BarberPro_Modules::is_active( $key );

        if ( $should_be_active && ! $currently_active ) {
            BarberPro_Modules::activate( $key );
        } elseif ( ! $should_be_active && $currently_active ) {
            BarberPro_Modules::deactivate( $key );
        }

        if ( isset( $_POST["module_{$key}_name"] ) ) {
            BarberPro_Database::update_setting(
                "module_{$key}_name",
                sanitize_text_field( wp_unslash( $_POST["module_{$key}_name"] ) )
            );
        }
    }
    // Redireciona para evitar reenvio do form e recarregar estado atualizado
    wp_redirect( add_query_arg( [ 'page' => 'barberpro_modules', 'saved' => '1' ], admin_url('admin.php') ) );
    exit;
}

if ( isset( $_GET['saved'] ) ) {
    echo '<div class="notice notice-success is-dismissible"><p>✅ Módulos salvos com sucesso!</p></div>';
}

$modules = BarberPro_Modules::all();
?>

<div class="wrap barberpro-admin">
    <h1>🧩 Módulos do BarberPro</h1>
    <p style="color:#6b7280;margin-top:-6px">Ative os módulos que sua empresa utiliza. Cada módulo tem seu próprio painel, shortcodes e financeiro.</p>

    <form method="post" id="bpModulesForm" style="max-width:940px">
        <?php wp_nonce_field( 'bp_save_modules', 'bp_modules_nonce' ); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:24px 0">

            <?php foreach ( $modules as $key => $meta ) :
                $active   = BarberPro_Modules::is_active( $key );
                $mod_name = BarberPro_Database::get_setting( "module_{$key}_name", $meta['label'] );
            ?>

            <div class="bp-mod-card <?php echo $active ? 'bp-mod-on' : ''; ?>"
                 id="card-<?php echo esc_attr($key); ?>"
                 style="background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,.07);border-top:4px solid <?php echo esc_attr($meta['color']); ?>">

                <!-- Cabeçalho com toggle -->
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px">
                    <div>
                        <div style="font-size:2rem;margin-bottom:6px"><?php echo $meta['icon']; ?></div>
                        <h2 style="margin:0;font-size:1.1rem"><?php echo esc_html($meta['label']); ?></h2>
                        <p style="color:#6b7280;font-size:.85rem;margin:4px 0 0"><?php echo esc_html($meta['description']); ?></p>
                    </div>

                    <!-- Toggle funcional -->
                    <div class="bp-toggle-wrap" style="flex-shrink:0;margin-left:16px">
                        <input type="checkbox"
                               id="toggle-<?php echo esc_attr($key); ?>"
                               name="module_<?php echo esc_attr($key); ?>"
                               value="1"
                               <?php checked($active); ?>
                               class="bp-mod-toggle"
                               data-card="card-<?php echo esc_attr($key); ?>"
                               data-color="<?php echo esc_attr($meta['color']); ?>">
                        <label for="toggle-<?php echo esc_attr($key); ?>" class="bp-toggle-label"
                               style="--on-color:<?php echo esc_attr($meta['color']); ?>">
                            <span class="bp-toggle-track" style="background:<?php echo $active ? esc_attr($meta['color']) : '#d1d5db'; ?>">
                                <span class="bp-toggle-thumb" style="left:<?php echo $active ? '26px' : '3px'; ?>"></span>
                            </span>
                            <span class="bp-toggle-txt"><?php echo $active ? 'Ativo' : 'Inativo'; ?></span>
                        </label>
                    </div>
                </div>

                <!-- Conteúdo quando ATIVO -->
                <div class="bp-mod-active-content" style="<?php echo $active ? '' : 'display:none'; ?>">
                    <div style="background:#f8f9fa;border-radius:8px;padding:14px;margin-bottom:14px">
                        <label style="display:block;font-weight:600;font-size:.82rem;margin-bottom:5px">Nome exibido no sistema</label>
                        <input type="text"
                               name="module_<?php echo esc_attr($key); ?>_name"
                               value="<?php echo esc_attr($mod_name); ?>"
                               placeholder="<?php echo esc_attr($meta['label']); ?>"
                               style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;box-sizing:border-box">
                    </div>

                    <div style="background:#f0fdf4;border-radius:8px;padding:12px 14px;margin-bottom:14px">
                        <strong style="font-size:.82rem;display:block;margin-bottom:6px;color:#166534">📎 Shortcodes disponíveis:</strong>
                        <?php foreach ( $meta['shortcodes'] as $sc ) : ?>
                        <code style="background:#dcfce7;color:#166534;padding:3px 8px;border-radius:4px;margin:2px 3px 2px 0;display:inline-block;font-size:.82rem;cursor:pointer"
                              onclick="navigator.clipboard.writeText('[<?php echo esc_js($sc); ?>]').then(()=>this.textContent='✅ Copiado!').then(()=>setTimeout(()=>this.textContent='[<?php echo esc_js($sc); ?>]',1500))">
                            [<?php echo esc_html($sc); ?>]
                        </code>
                        <?php endforeach; ?>
                        <p style="font-size:.78rem;color:#6b7280;margin:8px 0 0">Clique no shortcode para copiar</p>
                    </div>

                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        <a href="<?php echo esc_url(admin_url("admin.php?page=barberpro_{$key}")); ?>"
                           style="background:<?php echo esc_attr($meta['color']); ?>;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;display:inline-flex;align-items:center;gap:4px">
                            🖥 Abrir Painel
                        </a>
                        <a href="<?php echo esc_url(admin_url("admin.php?page=barberpro_{$key}_kanban")); ?>"
                           style="background:#f3f4f6;color:#374151;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600">
                            🗂 Kanban
                        </a>
                        <a href="<?php echo esc_url(admin_url("admin.php?page=barberpro_{$key}_finance")); ?>"
                           style="background:#f3f4f6;color:#374151;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600">
                            💰 Financeiro
                        </a>
                    </div>
                </div>

                <!-- Conteúdo quando INATIVO -->
                <div class="bp-mod-inactive-content" style="<?php echo $active ? 'display:none' : ''; ?>">
                    <div style="background:#fef3c7;border-radius:8px;padding:14px;font-size:.88rem;color:#92400e;display:flex;align-items:center;gap:10px">
                        <span style="font-size:1.4rem">⚠️</span>
                        <div>
                            <strong>Módulo inativo</strong><br>
                            Ative o toggle acima e clique em <strong>Salvar</strong> para liberar os shortcodes e o painel.
                        </div>
                    </div>
                </div>

            </div>
            <?php endforeach; ?>
        </div>

        <!-- Financeiro Consolidado -->
        <div style="background:#fff;border-radius:14px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.07);border-top:4px solid #8b5cf6;margin-bottom:24px">
            <div style="display:flex;align-items:center;gap:16px">
                <div style="font-size:2rem">📊</div>
                <div style="flex:1">
                    <h2 style="margin:0 0 4px;font-size:1rem">Financeiro Consolidado</h2>
                    <p style="color:#6b7280;font-size:.85rem;margin:0">DRE e fluxo de caixa somando barbearia + lava-car lado a lado.</p>
                </div>
                <a href="<?php echo esc_url(admin_url('admin.php?page=barberpro_consolidado')); ?>"
                   style="background:#8b5cf6;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:600;white-space:nowrap">
                    → Abrir
                </a>
            </div>
        </div>

        <!-- Botão salvar -->
        <div style="display:flex;align-items:center;gap:14px">
            <button type="submit" class="button button-primary" style="padding:10px 28px;font-size:1rem;height:auto">
                💾 Salvar Módulos
            </button>
            <span style="color:#6b7280;font-size:.85rem">As alterações só têm efeito após salvar.</span>
        </div>
    </form>
</div>

<style>
/* Toggle switch */
.bp-toggle-wrap { display:flex; flex-direction:column; align-items:center; gap:4px; }
.bp-mod-toggle  { position:absolute; opacity:0; width:0; height:0; }
.bp-toggle-label { display:flex; flex-direction:column; align-items:center; gap:5px; cursor:pointer; user-select:none; }
.bp-toggle-track {
    position:relative; width:50px; height:26px; border-radius:26px;
    transition:background .25s; display:block;
}
.bp-toggle-thumb {
    position:absolute; width:20px; height:20px;
    top:3px; border-radius:50%; background:#fff;
    box-shadow:0 1px 5px rgba(0,0,0,.25);
    transition:left .25s;
}
.bp-toggle-txt { font-size:.75rem; font-weight:600; color:#6b7280; transition:color .2s; white-space:nowrap; }

/* Card ativo */
.bp-mod-card.bp-mod-on { box-shadow:0 4px 16px rgba(0,0,0,.1) !important; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.bp-mod-toggle').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var card      = document.getElementById(this.dataset.card);
            var track     = card.querySelector('.bp-toggle-track');
            var thumb     = card.querySelector('.bp-toggle-thumb');
            var txt       = card.querySelector('.bp-toggle-txt');
            var activeDiv = card.querySelector('.bp-mod-active-content');
            var inactDiv  = card.querySelector('.bp-mod-inactive-content');
            var color     = this.dataset.color;

            if (this.checked) {
                track.style.background = color;
                thumb.style.left       = '27px';
                txt.textContent        = 'Ativo';
                txt.style.color        = color;
                activeDiv.style.display = '';
                inactDiv.style.display  = 'none';
                card.classList.add('bp-mod-on');
            } else {
                track.style.background = '#d1d5db';
                thumb.style.left       = '3px';
                txt.textContent        = 'Inativo';
                txt.style.color        = '#6b7280';
                activeDiv.style.display = 'none';
                inactDiv.style.display  = '';
                card.classList.remove('bp-mod-on');
            }
        });
    });
});
</script>
