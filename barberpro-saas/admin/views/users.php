<?php
/**
 * View – Gerenciamento de Usuários BarberPro
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sem permissão.' );

$all_modules = BarberPro_Modules::all();
$active_mods = BarberPro_Modules::active_list();

// ── Processar ações ───────────────────────────────────────────────────────────
$msg = '';
if ( isset($_POST['bp_users_nonce']) && wp_verify_nonce(sanitize_key($_POST['bp_users_nonce']), 'bp_users') ) {
    $action = sanitize_key($_POST['bp_action'] ?? '');

    if ( $action === 'create' ) {
        $username = sanitize_user($_POST['new_username'] ?? '');
        $email    = sanitize_email($_POST['new_email'] ?? '');
        $name     = sanitize_text_field($_POST['new_name'] ?? '');
        $password = $_POST['new_password'] ?? '';
        $role     = sanitize_key($_POST['new_role'] ?? 'barber_admin');

        if ( empty($username) || empty($email) || empty($password) ) {
            $msg = '<div class="notice notice-error"><p>❌ Usuário, e-mail e senha são obrigatórios.</p></div>';
        } elseif ( username_exists($username) ) {
            $msg = '<div class="notice notice-error"><p>❌ Nome de usuário já existe.</p></div>';
        } elseif ( email_exists($email) ) {
            $msg = '<div class="notice notice-error"><p>❌ E-mail já cadastrado.</p></div>';
        } else {
            $user_id = wp_create_user($username, $password, $email);
            if ( is_wp_error($user_id) ) {
                $msg = '<div class="notice notice-error"><p>❌ ' . esc_html($user_id->get_error_message()) . '</p></div>';
            } else {
                wp_update_user(['ID'=>$user_id,'display_name'=>$name,'first_name'=>$name]);
                $user = new WP_User($user_id);
                $user->set_role($role);

                // Salva módulos permitidos como user meta
                $allowed = array_keys(array_filter($active_mods, function($k) { return !empty($_POST["mod_{$k}"]); }, ARRAY_FILTER_USE_KEY));
                update_user_meta($user_id, 'barberpro_modules', $allowed);
                update_user_meta($user_id, 'barberpro_company_id', absint($_POST['company_id'] ?? 1));

                $msg = '<div class="notice notice-success"><p>✅ Usuário <strong>' . esc_html($username) . '</strong> criado com sucesso!</p></div>';
            }
        }
    }

    if ( $action === 'update_perms' ) {
        $uid = absint($_POST['edit_user_id'] ?? 0);
        if ( $uid && $uid !== get_current_user_id() ) {
            $user = new WP_User($uid);
            $role = sanitize_key($_POST['edit_role'] ?? 'barber_admin');
            $user->set_role($role);
            $allowed = [];
            foreach ( $active_mods as $k => $_ ) {
                if ( !empty($_POST["emod_{$k}"]) ) $allowed[] = $k;
            }
            update_user_meta($uid, 'barberpro_modules', $allowed);
            update_user_meta($uid, 'barberpro_company_id', absint($_POST['edit_company_id'] ?? 1));
            $msg = '<div class="notice notice-success"><p>✅ Permissões atualizadas!</p></div>';
        }
    }

    if ( $action === 'delete' ) {
        $uid = absint($_POST['del_user_id'] ?? 0);
        if ( $uid && $uid !== get_current_user_id() && $uid !== 1 ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($uid);
            $msg = '<div class="notice notice-success"><p>✅ Usuário excluído.</p></div>';
        }
    }
}

// ── Carregar usuários BarberPro ───────────────────────────────────────────────
$bp_roles = ['administrator','barber_admin','barber_professional','barber_client'];
$users = get_users(['role__in' => $bp_roles, 'orderby' => 'display_name', 'number' => 100]);

$role_labels = [
    'administrator'      => '👑 Administrador',
    'barber_admin'       => '🛠 Gerente',
    'barber_professional'=> '✂️ Profissional',
    'barber_client'      => '👤 Cliente',
];

$companies = [];
global $wpdb;
$rows = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}barber_companies ORDER BY id");
foreach ($rows as $r) $companies[$r->id] = $r->name;
?>
<div class="wrap barberpro-admin">
<h1>👥 Usuários BarberPro</h1>
<?php echo $msg; ?>

<div style="display:grid;grid-template-columns:380px 1fr;gap:24px;align-items:start;margin-top:16px">

<!-- ── Formulário novo usuário ── -->
<div class="postbox">
    <div class="postbox-header"><h2>➕ Novo Usuário</h2></div>
    <div class="inside">
        <form method="post">
            <?php wp_nonce_field('bp_users','bp_users_nonce'); ?>
            <input type="hidden" name="bp_action" value="create">
            <table class="form-table" style="margin:0">
                <tr>
                    <th>Nome completo</th>
                    <td><input type="text" name="new_name" class="regular-text" placeholder="João Silva"></td>
                </tr>
                <tr>
                    <th>Login *</th>
                    <td><input type="text" name="new_username" class="regular-text" required placeholder="joao.silva"></td>
                </tr>
                <tr>
                    <th>E-mail *</th>
                    <td><input type="email" name="new_email" class="regular-text" required placeholder="joao@email.com"></td>
                </tr>
                <tr>
                    <th>Senha *</th>
                    <td>
                        <input type="text" name="new_password" class="regular-text" required id="newPwd" placeholder="Mínimo 8 caracteres">
                        <button type="button" onclick="document.getElementById('newPwd').value=bpGenPwd()" class="button" style="margin-top:4px">🎲 Gerar senha</button>
                    </td>
                </tr>
                <tr>
                    <th>Perfil</th>
                    <td>
                        <select name="new_role" class="regular-text">
                            <?php foreach($role_labels as $r=>$l): if($r==='administrator') continue; ?>
                            <option value="<?php echo $r; ?>"><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php if(count($companies)>1): ?>
                <tr>
                    <th>Empresa</th>
                    <td>
                        <select name="company_id" class="regular-text">
                            <?php foreach($companies as $cid=>$cn): ?>
                            <option value="<?php echo $cid; ?>"><?php echo esc_html($cn); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Módulos</th>
                    <td>
                        <?php foreach($active_mods as $k=>$meta): ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="mod_<?php echo $k; ?>" value="1" checked>
                            <?php echo esc_html($meta['label']); ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if(empty($active_mods)): ?>
                        <span style="color:#999">Nenhum módulo ativo</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p style="margin-top:16px">
                <button type="submit" class="button button-primary button-large">✅ Criar Usuário</button>
            </p>
        </form>
    </div>
</div>

<!-- ── Lista de usuários ── -->
<div class="postbox">
    <div class="postbox-header"><h2>👥 Usuários Cadastrados (<?php echo count($users); ?>)</h2></div>
    <div class="inside" style="padding:0">
        <table class="wp-list-table widefat striped" style="border:none">
            <thead>
                <tr>
                    <th>Usuário</th>
                    <th>Perfil</th>
                    <th>Módulos</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($users as $u):
                $u_roles  = $u->roles;
                $u_role   = $u_roles[0] ?? 'subscriber';
                $u_mods   = get_user_meta($u->ID, 'barberpro_modules', true) ?: [];
                $u_cid    = (int)get_user_meta($u->ID, 'barberpro_company_id', true) ?: 1;
                $is_me    = $u->ID === get_current_user_id();
                $is_super = $u->ID === 1;
            ?>
            <tr>
                <td>
                    <strong><?php echo esc_html($u->display_name ?: $u->user_login); ?></strong>
                    <div style="font-size:.8rem;color:#888"><?php echo esc_html($u->user_email); ?></div>
                    <?php if($is_me): ?><span style="font-size:.75rem;color:#f59e0b">← você</span><?php endif; ?>
                </td>
                <td>
                    <span style="background:<?php echo $u_role==='administrator'?'#dc2626':($u_role==='barber_admin'?'#2563eb':'#6b7280'); ?>;color:#fff;padding:3px 8px;border-radius:99px;font-size:.78rem">
                        <?php echo $role_labels[$u_role] ?? $u_role; ?>
                    </span>
                </td>
                <td style="font-size:.82rem">
                    <?php if(empty($u_mods) || $u_role==='administrator'): ?>
                    <span style="color:#888"><?php echo $u_role==='administrator'?'Todos':'Nenhum'; ?></span>
                    <?php else: ?>
                    <?php foreach($u_mods as $mk): $mm=$all_modules[$mk]??null; if($mm): ?>
                    <span style="display:inline-block;background:#1e3a5f;color:#60a5fa;padding:2px 7px;border-radius:99px;font-size:.75rem;margin:1px"><?php echo esc_html($mm['icon'].' '.$mm['label']); ?></span>
                    <?php endif; endforeach; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if(!$is_super): ?>
                    <button class="button button-small" onclick="bpUserEdit(<?php echo $u->ID; ?>,<?php echo htmlspecialchars(json_encode([
                        'role'=> $u_role,
                        'mods'=> $u_mods,
                        'cid' => $u_cid,
                        'name'=> $u->display_name,
                    ]),ENT_QUOTES); ?>)">✏️ Editar</button>
                    <?php if(!$is_me): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Excluir <?php echo esc_js($u->display_name); ?>?')">
                        <?php wp_nonce_field('bp_users','bp_users_nonce'); ?>
                        <input type="hidden" name="bp_action" value="delete">
                        <input type="hidden" name="del_user_id" value="<?php echo $u->ID; ?>">
                        <button type="submit" class="button button-small" style="color:#dc2626">🗑 Excluir</button>
                    </form>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="color:#999;font-size:.8rem">Super Admin</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- grid -->

<!-- ── Modal Editar Usuário ── -->
<div id="bpUserModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:12px;padding:28px;width:480px;max-width:95vw;max-height:90vh;overflow-y:auto">
        <h2 style="margin:0 0 18px">✏️ Editar Permissões</h2>
        <form method="post" id="bpUserEditForm">
            <?php wp_nonce_field('bp_users','bp_users_nonce'); ?>
            <input type="hidden" name="bp_action" value="update_perms">
            <input type="hidden" name="edit_user_id" id="editUserId">
            <table class="form-table" style="margin:0">
                <tr>
                    <th>Usuário</th>
                    <td><strong id="editUserName"></strong></td>
                </tr>
                <tr>
                    <th>Perfil</th>
                    <td>
                        <select name="edit_role" id="editRole" class="regular-text">
                            <?php foreach($role_labels as $r=>$l): if($r==='administrator') continue; ?>
                            <option value="<?php echo $r; ?>"><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php if(count($companies)>1): ?>
                <tr>
                    <th>Empresa</th>
                    <td>
                        <select name="edit_company_id" id="editCompanyId" class="regular-text">
                            <?php foreach($companies as $cid=>$cn): ?>
                            <option value="<?php echo $cid; ?>"><?php echo esc_html($cn); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Módulos</th>
                    <td id="editModsContainer">
                        <?php foreach($active_mods as $k=>$meta): ?>
                        <label style="display:block;margin-bottom:6px">
                            <input type="checkbox" name="emod_<?php echo $k; ?>" value="1" id="emod_<?php echo $k; ?>">
                            <?php echo esc_html($meta['label']); ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>
            <div style="display:flex;gap:10px;margin-top:20px">
                <button type="submit" class="button button-primary">💾 Salvar</button>
                <button type="button" class="button" onclick="document.getElementById('bpUserModal').style.display='none'">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function bpGenPwd(){
    var c='ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    var p='';for(var i=0;i<12;i++)p+=c[Math.floor(Math.random()*c.length)];
    return p;
}
function bpUserEdit(uid, data){
    document.getElementById('editUserId').value = uid;
    document.getElementById('editUserName').textContent = data.name;
    document.getElementById('editRole').value = data.role;
    if(document.getElementById('editCompanyId'))
        document.getElementById('editCompanyId').value = data.cid;
    // Set module checkboxes
    <?php foreach($active_mods as $k=>$_): ?>
    var cb_<?php echo $k; ?> = document.getElementById('emod_<?php echo $k; ?>');
    if(cb_<?php echo $k; ?>) cb_<?php echo $k; ?>.checked = data.mods.includes('<?php echo $k; ?>');
    <?php endforeach; ?>
    document.getElementById('bpUserModal').style.display='flex';
}
document.getElementById('bpUserModal').addEventListener('click', function(e){
    if(e.target===this) this.style.display='none';
});
</script>
