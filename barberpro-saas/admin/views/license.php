<?php
/**
 * View – Gerenciamento de Licença
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Salvar / remover chave
if ( isset( $_POST['barberpro_license_nonce'] )
    && wp_verify_nonce( sanitize_key( $_POST['barberpro_license_nonce'] ), 'barberpro_license_action' ) ) {

    if ( isset( $_POST['action_remove'] ) ) {
        BarberPro_License::remove();
        $msg_type = 'warning';
        $msg_text = 'Licença removida.';
    } else {
        $key    = sanitize_text_field( wp_unslash( $_POST['license_key'] ?? '' ) );
        $result = BarberPro_License::save( $key );
        $msg_type = $result['valid'] ? 'success' : 'error';
        $msg_text = strip_tags( $result['message'] );
    }
}

$status = BarberPro_License::status();
$plan_labels = [ 'trial' => '🧪 Trial', 'basic' => '⭐ Basic', 'pro' => '🚀 Pro', 'lifetime' => '♾ Lifetime' ];

// Cores por status
$status_colors = [
    'active'          => [ '#d1fae5', '#065f46', '✅ Ativa'            ],
    'expired'         => [ '#fee2e2', '#991b1b', '❌ Expirada'          ],
    'invalid'         => [ '#fee2e2', '#991b1b', '🚫 Inválida'          ],
    'missing'         => [ '#fef3c7', '#92400e', '⚠️ Não inserida'      ],
    'domain_mismatch' => [ '#fee2e2', '#991b1b', '🌐 Domínio diferente' ],
];
[ $bg, $fg, $label ] = $status_colors[ $status['status'] ] ?? [ '#f3f4f6', '#374151', '?' ];
?>

<div class="wrap barberpro-admin">
    <h1>🔑 Licença do BarberPro</h1>

    <?php if ( isset( $msg_text ) ) : ?>
    <div class="notice notice-<?php echo esc_attr( $msg_type ); ?> is-dismissible">
        <p><?php echo esc_html( $msg_text ); ?></p>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:960px;margin-top:20px">

        <!-- STATUS ATUAL -->
        <div style="background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,.07)">
            <h2 style="margin-top:0;font-size:1.05rem">📊 Status da Licença</h2>

            <div style="background:<?php echo esc_attr($bg); ?>;border-radius:10px;padding:20px;margin-bottom:20px;text-align:center">
                <div style="font-size:2.2rem;margin-bottom:6px"><?php echo mb_substr($label,0,2); ?></div>
                <div style="font-weight:700;font-size:1.1rem;color:<?php echo esc_attr($fg); ?>"><?php echo esc_html(mb_substr($label,3)); ?></div>
            </div>

            <table style="width:100%;border-collapse:collapse;font-size:.9rem">
                <?php
                $rows = [
                    'Plano'             => $plan_labels[ $status['plan'] ] ?? ucfirst( $status['plan'] ),
                    'Domínio licenciado'=> $status['domain']  ?: '—',
                    'Expira em'         => $status['expires'] ?: '—',
                    'Dias restantes'    => $status['valid']   ? '<strong>' . $status['days_remaining'] . ' dia(s)</strong>' : '—',
                    'Domínio atual'     => strtolower( parse_url( home_url(), PHP_URL_HOST ) ),
                ];
                foreach ( $rows as $k => $v ) :
                ?>
                <tr style="border-bottom:1px solid #f3f4f6">
                    <td style="padding:8px 0;color:#6b7280;width:50%"><?php echo esc_html($k); ?></td>
                    <td style="padding:8px 0;font-weight:600"><?php echo wp_kses_post($v); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>

            <?php if ( $status['valid'] && $status['days_remaining'] <= 30 ) : ?>
            <div style="background:#fef3c7;border-radius:8px;padding:12px;margin-top:16px;font-size:.85rem;color:#92400e">
                ⏳ <strong>Atenção:</strong> Sua licença expira em breve. Renove para não perder o acesso.
            </div>
            <?php endif; ?>
        </div>

        <!-- FORMULÁRIO -->
        <div style="background:#fff;border-radius:14px;padding:28px;box-shadow:0 2px 10px rgba(0,0,0,.07)">
            <h2 style="margin-top:0;font-size:1.05rem">🔑 Inserir / Atualizar Chave</h2>

            <form method="post">
                <?php wp_nonce_field( 'barberpro_license_action', 'barberpro_license_nonce' ); ?>
                <div style="margin-bottom:16px">
                    <label style="display:block;font-weight:600;margin-bottom:6px;font-size:.9rem">
                        Chave de Licença
                    </label>
                    <textarea name="license_key" rows="4"
                        style="width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;font-family:monospace;font-size:.85rem;resize:vertical"
                        placeholder="BP2-eyJkIjoiYmFyYmVhcmlhLmNvbS5iciIsImUiOiIyMDI1LTA2LTMwIn0=-A1B2C3D4"
                    ><?php echo esc_textarea( get_option( BarberPro_License::OPTION_KEY, '' ) ); ?></textarea>
                    <p style="color:#6b7280;font-size:.8rem;margin-top:4px">
                        Cole a chave exatamente como recebida, incluindo o prefixo <code>BP2-</code>.
                    </p>
                </div>

                <div style="display:flex;gap:10px">
                    <button type="submit" class="button button-primary" style="flex:1;height:40px">
                        ✅ Ativar Licença
                    </button>
                    <?php if ( $status['status'] !== 'missing' ) : ?>
                    <button type="submit" name="action_remove" value="1" class="button"
                        style="height:40px;color:#ef4444;border-color:#ef4444"
                        onclick="return confirm('Remover a licença atual?')">
                        🗑 Remover
                    </button>
                    <?php endif; ?>
                </div>
            </form>

            <hr style="margin:24px 0">

            <div style="background:#f8f9fa;border-radius:8px;padding:16px;font-size:.85rem">
                <h3 style="margin:0 0 10px;font-size:.9rem">📋 Como funciona</h3>
                <ul style="margin:0;padding-left:18px;color:#4b5563;line-height:1.7">
                    <li>A chave de licença é vinculada ao domínio do seu site</li>
                    <li>Funciona 100% offline – sem servidor externo</li>
                    <li>Quando expirar, o plugin bloqueia o frontend e exibe aviso no admin</li>
                    <li>Para renovar, solicite uma nova chave ao fornecedor</li>
                    <li>Em ambiente de desenvolvimento (localhost) a verificação de domínio é ignorada</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- HISTÓRICO / INFO EXTRA -->
    <?php if ( defined('BARBERPRO_LICENSE_SECRET') && BARBERPRO_LICENSE_SECRET !== 'TROQUE-ISSO-NO-WP-CONFIG' ) : ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;margin-top:20px;max-width:960px;font-size:.85rem">
        <strong>🔧 Modo desenvolvedor ativo.</strong>
        BARBERPRO_LICENSE_SECRET está configurado.
        <a href="<?php echo esc_url( admin_url('admin.php?page=barberpro_license&barberpro_keygen=1') ); ?>">
            → Abrir Gerador de Chaves
        </a>
    </div>
    <?php endif; ?>
</div>
