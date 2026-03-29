<?php
/**
 * View – Configurações
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'barberpro_manage_settings' ) ) wp_die( 'Sem permissão.' );

function bp_get( string $key, $default = '' ) {
    return BarberPro_Database::get_setting( $key, $default );
}
?>
<div class="wrap barberpro-admin">
    <h1>⚙️ Configurações</h1>
    <form method="post">
        <?php wp_nonce_field( 'barberpro_save_settings', 'barberpro_settings_nonce' ); ?>
        <input type="hidden" name="bp_active_tab" id="bp_active_tab_input" value="<?php echo esc_attr($_GET['tab'] ?? 'tab-whatsapp'); ?>">

        <nav class="nav-tab-wrapper">
            <a href="#tab-whatsapp"   class="nav-tab nav-tab-active">📱 WhatsApp</a>
            <a href="#tab-notif"      class="nav-tab">🔔 Notificações</a>
            <a href="#tab-bot"        class="nav-tab">🤖 Bot</a>
            <a href="#tab-booking"    class="nav-tab">📅 Agendamento</a>
            <a href="#tab-messages"   class="nav-tab">⏱️ Mensagens</a>
            <a href="#tab-payment"       class="nav-tab">💳 Pagamentos</a>
            <a href="#tab-payment-online" class="nav-tab">🏦 Online</a>
            <a href="#tab-finance"        class="nav-tab">💰 Financeiro</a>
            <a href="#tab-pwa"          class="nav-tab">📱 PWA</a>
            <a href="#tab-widget"       class="nav-tab">💬 Widget</a>
            <a href="#tab-loja"         class="nav-tab">🛍️ Loja</a>
            <a href="#tab-lavacar"      class="nav-tab">🚗 Lava-Car</a>
            <a href="#tab-openai"       class="nav-tab">🧠 IA (OpenAI)</a>
        </nav>

        <!-- ═══ TAB: WHATSAPP ═══ -->
        <div id="tab-whatsapp" class="barberpro-tab-content">
            <h2 style="margin-top:0">Provedor WhatsApp</h2>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:.87rem">
                ⚠️ O campo <strong>Número da Empresa</strong> deve conter apenas dígitos no formato internacional.
                Ex: <code>5544999999999</code> (55 = Brasil, 44 = DDD, + número com 9 dígitos)
            </div>
            <table class="form-table">
                <tr>
                    <th>Número da Empresa</th>
                    <td>
                        <input type="text" name="whatsapp_number"
                               value="<?php echo esc_attr( preg_replace('/\D/', '', bp_get('whatsapp_number')) ); ?>"
                               class="regular-text" placeholder="5544999999999"
                               oninput="this.value=this.value.replace(/\D/g,'')">
                        <p class="description">Apenas dígitos. Ex: 5544999999999</p>
                    </td>
                </tr>
                <tr>
                    <th>Provedor</th>
                    <td>
                        <select name="whatsapp_provider" id="waProvider" onchange="showWaFields(this.value)">
                            <?php foreach ( [
                                'wapi'      => 'W-API (recomendado — mesmo do NBD Prospector)',
                                'zapi'      => 'Z-API',
                                'cloud_api' => 'WhatsApp Cloud API (Meta)',
                                'twilio'    => 'Twilio',
                            ] as $v=>$l ) : ?>
                            <option value="<?php echo esc_attr($v); ?>" <?php selected( bp_get('whatsapp_provider','wapi'), $v ); ?>><?php echo esc_html($l); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <div id="wa-wapi" class="wa-fields" <?php echo bp_get('whatsapp_provider','wapi') !== 'wapi' ? 'style="display:none"' : ''; ?>>
                <h3>🔑 W-API</h3>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.87rem">
                    Use as mesmas credenciais do NBD Prospector. Encontre no painel da W-API em "Instâncias".
                </div>
                <table class="form-table">
                    <tr><th>Instance ID</th><td><input type="text" name="wapi_instance" value="<?php echo esc_attr( bp_get('wapi_instance') ); ?>" class="regular-text" placeholder="Ex: 3A1B2C3D4E5F6G"></td></tr>
                    <tr><th>Token</th><td><input type="password" name="wapi_token" value="<?php echo esc_attr( bp_get('wapi_token') ); ?>" class="regular-text"></td></tr>
                    <tr>
                        <th>Testar conexão</th>
                        <td>
                            <button type="button" onclick="bpTestarWapi()" class="button">📡 Testar W-API</button>
                            <span id="wapi_test_result" style="margin-left:10px;font-size:.88rem"></span>
                        </td>
                    </tr>
                </table>
            </div>
            <div id="wa-zapi" class="wa-fields" <?php echo bp_get('whatsapp_provider','wapi') !== 'zapi' ? 'style="display:none"' : ''; ?>>
                <h3>🔑 Z-API</h3>
                <table class="form-table">
                    <tr><th>Instance ID</th><td><input type="text" name="zapi_instance" value="<?php echo esc_attr( bp_get('zapi_instance') ); ?>" class="regular-text"></td></tr>
                    <tr><th>Token</th><td><input type="password" name="zapi_token" value="<?php echo esc_attr( bp_get('zapi_token') ); ?>" class="regular-text"></td></tr>
                </table>
            </div>
            <div id="wa-cloud_api" class="wa-fields" <?php echo bp_get('whatsapp_provider','wapi') !== 'cloud_api' ? 'style="display:none"' : ''; ?>>
                <h3>🔑 WhatsApp Cloud API</h3>
                <table class="form-table">
                    <tr><th>Access Token</th><td><input type="password" name="whatsapp_cloud_token" value="<?php echo esc_attr( bp_get('whatsapp_cloud_token') ); ?>" class="large-text"></td></tr>
                    <tr><th>Phone Number ID</th><td><input type="text" name="whatsapp_phone_id" value="<?php echo esc_attr( bp_get('whatsapp_phone_id') ); ?>" class="regular-text"></td></tr>
                </table>
            </div>
            <div id="wa-twilio" class="wa-fields" <?php echo bp_get('whatsapp_provider','wapi') !== 'twilio' ? 'style="display:none"' : ''; ?>>
                <h3>🔑 Twilio</h3>
                <table class="form-table">
                    <tr><th>Account SID</th><td><input type="text" name="twilio_account_sid" value="<?php echo esc_attr( bp_get('twilio_account_sid') ); ?>" class="regular-text"></td></tr>
                    <tr><th>Auth Token</th><td><input type="password" name="twilio_auth_token" value="<?php echo esc_attr( bp_get('twilio_auth_token') ); ?>" class="regular-text"></td></tr>
                    <tr><th>Número From</th><td><input type="text" name="twilio_from" value="<?php echo esc_attr( bp_get('twilio_from') ); ?>" class="regular-text" placeholder="whatsapp:+14155238886"></td></tr>
                </table>
            </div>
        </div>

        <!-- ═══ TAB: MÉTODOS DE NOTIFICAÇÃO ═══ -->
        <div id="tab-notif" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🔔 Métodos de Notificação</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">Ative um ou mais métodos. Eles funcionam de forma independente e podem ser combinados.</p>

            <!-- CARDS DOS 3 MÉTODOS -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-bottom:36px">

                <?php
                $metodos = [
                    'email'    => ['ico'=>'📧','titulo'=>'E-mail','sub'=>'Agendamento pelo site + avisos por e-mail','cor'=>'#3b82f6','bg'=>'rgba(59,130,246,.08)'],
                    'whatsapp' => ['ico'=>'💬','titulo'=>'WhatsApp Automático','sub'=>'Confirmações e lembretes via WhatsApp (sem bot)','cor'=>'#25d366','bg'=>'rgba(37,211,102,.08)'],
                    'bot'      => ['ico'=>'🤖','titulo'=>'Bot IA (WhatsApp)','sub'=>'Cliente agenda conversando + recebe lembretes','cor'=>'#8b5cf6','bg'=>'rgba(139,92,246,.08)'],
                ];
                foreach ($metodos as $key => $m):
                    $ativo = bp_get("notify_{$key}_ativo",'0') === '1';
                ?>
                <div style="border:2px solid <?php echo $ativo ? $m['cor'] : '#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo $ativo ? $m['bg'] : '#fafafa'; ?>;transition:all .2s" id="notif-card-<?php echo $key; ?>">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
                        <div>
                            <div style="font-size:1.8rem;margin-bottom:6px"><?php echo $m['ico']; ?></div>
                            <div style="font-weight:800;font-size:1rem;color:#111"><?php echo $m['titulo']; ?></div>
                            <div style="font-size:.8rem;color:#6b7280;margin-top:3px"><?php echo $m['sub']; ?></div>
                        </div>
                        <label class="bp-toggle" style="flex-shrink:0;margin-left:12px">
                            <input type="checkbox" name="notify_<?php echo $key; ?>_ativo" value="1"
                                   <?php checked($ativo, true); ?>
                                   onchange="bpNotifToggle('<?php echo $key; ?>','<?php echo $m['cor']; ?>','<?php echo addslashes($m['bg']); ?>')">
                            <span class="bp-toggle-slider" style="<?php echo $ativo ? "background:{$m['cor']}" : ''; ?>"></span>
                        </label>
                    </div>
                    <div style="font-size:.75rem;padding:6px 10px;border-radius:6px;display:inline-block;font-weight:700;
                                background:<?php echo $ativo ? $m['cor'] : '#e5e7eb'; ?>;color:<?php echo $ativo ? '#fff' : '#6b7280'; ?>">
                        <?php echo $ativo ? '● ATIVO' : '○ INATIVO'; ?>
                    </div>
                    <?php if ($key === 'bot' && $ativo): ?>
                    <div style="margin-top:10px;font-size:.78rem;color:#8b5cf6">
                        Webhook: <code style="background:#f3f0ff;padding:2px 6px;border-radius:4px"><?php echo esc_url(rest_url('barberpro/v1/whatsapp')); ?></code>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ══ CONFIGURAÇÕES DO MÉTODO 1: E-MAIL ══ -->
            <div class="bp-notif-section" id="notif-cfg-email" style="<?php echo bp_get('notify_email_ativo','0')==='1'?'':'display:none'; ?>">
                <h3 style="border-top:2px solid #3b82f6;padding-top:18px;color:#3b82f6">📧 Configurações de E-mail</h3>
                <table class="form-table">
                    <tr>
                        <th>Nome do Remetente</th>
                        <td><input type="text" name="email_nome_remetente" value="<?php echo esc_attr(bp_get('email_nome_remetente', get_bloginfo('name'))); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>E-mail Remetente</th>
                        <td>
                            <input type="email" name="email_remetente" value="<?php echo esc_attr(bp_get('email_remetente', get_bloginfo('admin_email'))); ?>" class="regular-text">
                            <p class="description">Para melhor entregabilidade configure um plugin SMTP (ex: WP Mail SMTP).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>BCC (cópia oculta)</th>
                        <td>
                            <input type="email" name="email_bcc" value="<?php echo esc_attr(bp_get('email_bcc','')); ?>" class="regular-text" placeholder="sua@empresa.com">
                            <p class="description">Receba uma cópia de cada confirmação/lembrete enviado.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Logo no e-mail</th>
                        <td>
                            <input type="url" name="email_logo_url" value="<?php echo esc_attr(bp_get('email_logo_url','')); ?>" class="large-text" placeholder="https://seusite.com/logo.png">
                        </td>
                    </tr>
                    <tr>
                        <th>Cor principal</th>
                        <td>
                            <input type="color" name="email_cor_primaria" value="<?php echo esc_attr(bp_get('email_cor_primaria','#1a1a2e')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>E-mails em HTML</th>
                        <td>
                            <label>
                                <input type="checkbox" name="email_html" value="1" <?php checked(bp_get('email_html','1'),'1'); ?>>
                                Enviar e-mails com layout visual (HTML)
                            </label>
                        </td>
                    </tr>
                </table>

                <h4 style="margin-top:20px">✉️ Templates de E-mail</h4>
                <p class="description" style="margin-bottom:16px">Variáveis: <code>{nome}</code> <code>{data}</code> <code>{hora}</code> <code>{servico}</code> <code>{profissional}</code> <code>{codigo}</code> <code>{link}</code></p>

                <?php
                $email_tpls = [
                    'email_confirmation' => ['✅ Confirmação',  true],
                    'email_reminder'     => ['⏰ Lembrete 1',   true],
                    'email_reminder2'    => ['📅 Lembrete 2',   false],
                    'email_cancellation' => ['❌ Cancelamento', true],
                    'email_review'       => ['⭐ Avaliação',    false],
                ];
                foreach ($email_tpls as $key => [$label, $default_ativo]):
                    $ativo_key = $key . '_active';
                ?>
                <div class="bp-msg-card" style="border-left:3px solid #3b82f6;margin-bottom:16px">
                    <div class="bp-msg-header">
                        <div class="bp-msg-icon">📧</div>
                        <div>
                            <h3><?php echo $label; ?></h3>
                            <label style="font-size:.82rem">
                                <input type="checkbox" name="<?php echo $ativo_key; ?>" value="1"
                                       <?php checked(bp_get($ativo_key, $default_ativo?'1':'0'),'1'); ?>>
                                Ativar este e-mail
                            </label>
                        </div>
                    </div>
                    <div style="margin-bottom:6px">
                        <label style="font-size:.82rem;font-weight:600">Assunto</label>
                        <?php
                        $assuntos_default = [
                            'email_confirmation' => '✅ Agendamento confirmado — ' . get_bloginfo('name'),
                            'email_reminder'     => '⏰ Lembrete do seu agendamento — ' . get_bloginfo('name'),
                            'email_reminder2'    => '📅 Seu agendamento é amanhã — ' . get_bloginfo('name'),
                            'email_cancellation' => '❌ Agendamento cancelado — ' . get_bloginfo('name'),
                            'email_review'       => '⭐ Como foi seu atendimento? — ' . get_bloginfo('name'),
                        ];
                        $placeholder_assunto = $assuntos_default[$key] ?? '';
                        ?>
                        <input type="text" name="<?php echo $key; ?>_assunto"
                               value="<?php echo esc_attr(bp_get("{$key}_assunto", '')); ?>"
                               class="large-text" style="margin-top:4px"
                               placeholder="<?php echo esc_attr($placeholder_assunto); ?>">
                    </div>
                    <label style="font-size:.82rem;font-weight:600">Corpo da mensagem</label>
                    <textarea name="<?php echo $key; ?>_corpo" rows="5" class="large-text bp-msg-text"
                              style="margin-top:4px"><?php echo esc_textarea(bp_get("{$key}_corpo",'')); ?></textarea>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ══ CONFIGURAÇÕES DO MÉTODO 2: WHATSAPP AUTOMÁTICO ══ -->
            <div class="bp-notif-section" id="notif-cfg-whatsapp" style="<?php echo bp_get('notify_whatsapp_ativo','0')==='1'?'':'display:none'; ?>">
                <h3 style="border-top:2px solid #25d366;padding-top:18px;color:#25d366">💬 WhatsApp Automático</h3>
                <p class="description">As mensagens abaixo são enviadas automaticamente pelo WhatsApp configurado na aba <strong>📱 WhatsApp</strong>.</p>
                <p class="description" style="margin-bottom:16px">Variáveis: <code>{nome}</code> <code>{data}</code> <code>{hora}</code> <code>{servico}</code> <code>{profissional}</code> <code>{codigo}</code> <code>{link}</code></p>

                <?php
                $wa_tpls = [
                    'msg_confirmation' => ['✅ Confirmação de agendamento', 'Enviada logo após o cliente agendar'],
                    'msg_reminder'     => ['⏰ Lembrete (antes do horário)',  'Enviada ~1h antes do horário'],
                    'msg_reminder2'    => ['📅 Lembrete do dia anterior',    'Enviada no dia anterior ao agendamento'],
                    'msg_cancellation' => ['❌ Cancelamento',               'Enviada ao cancelar um agendamento'],
                    'msg_review'       => ['⭐ Pedido de avaliação',        'Enviada após finalizar o atendimento'],
                    'msg_return'       => ['🔄 Mensagem de retorno',        'Enviada quando o cliente não volta depois do prazo médio'],
                ];
                foreach ($wa_tpls as $key => [$label, $desc]):
                    $ativo_key = $key . '_active';
                ?>
                <div class="bp-msg-card" style="border-left:3px solid #25d366;margin-bottom:16px">
                    <div class="bp-msg-header">
                        <div class="bp-msg-icon">💬</div>
                        <div>
                            <h3><?php echo $label; ?></h3>
                            <p><?php echo $desc; ?></p>
                            <label style="font-size:.82rem">
                                <input type="checkbox" name="<?php echo $ativo_key; ?>" value="1"
                                       <?php checked(bp_get($ativo_key,'1'),'1'); ?>>
                                Ativar esta mensagem
                            </label>
                        </div>
                    </div>
                    <textarea name="<?php echo $key; ?>" rows="4" class="large-text bp-msg-text"><?php
                        echo esc_textarea(bp_get($key,''));
                    ?></textarea>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- ══ CONFIGURAÇÕES DO MÉTODO 3: BOT IA ══ -->
            <div class="bp-notif-section" id="notif-cfg-bot" style="<?php echo bp_get('notify_bot_ativo','0')==='1'?'':'display:none'; ?>">
                <h3 style="border-top:2px solid #8b5cf6;padding-top:18px;color:#8b5cf6">🤖 Bot IA — Configurações</h3>
                <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.88rem">
                    O bot usa o mesmo WhatsApp configurado na aba <strong>📱 WhatsApp</strong>.<br>
                    Configure o webhook no painel da Z-API/W-API apontando para:<br>
                    <code style="background:#ede9fe;padding:3px 8px;border-radius:4px;font-size:.85rem"><?php echo esc_url(rest_url('barberpro/v1/whatsapp')); ?></code>
                    <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js(rest_url('barberpro/v1/whatsapp')); ?>');this.textContent='✓ Copiado!';setTimeout(()=>this.textContent='Copiar',2000)"
                            style="margin-left:8px;padding:3px 10px;border:1px solid #8b5cf6;border-radius:4px;background:#f5f3ff;color:#8b5cf6;cursor:pointer;font-size:.82rem">Copiar</button>
                </div>
                <table class="form-table">
                    <tr>
                        <th>Token de Segurança</th>
                        <td>
                            <input type="text" name="bot_webhook_token" value="<?php echo esc_attr(bp_get('bot_webhook_token','')); ?>" class="regular-text" placeholder="Deixe vazio para não usar">
                            <button type="button" onclick="document.querySelector('[name=bot_webhook_token]').value=Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)"
                                    style="margin-left:8px;padding:5px 10px;border:1px solid #d1d5db;border-radius:4px;cursor:pointer;font-size:.82rem">Gerar</button>
                        </td>
                    </tr>
                    <tr>
                        <th>Bot também envia lembretes</th>
                        <td>
                            <label>
                                <input type="checkbox" name="bot_envia_lembretes" value="1" <?php checked(bp_get('bot_envia_lembretes','1'),'1'); ?>>
                                Enviar confirmação e lembretes via WhatsApp após agendamento pelo bot
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th>Debug (log de payloads)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="bot_debug" value="1" <?php checked(bp_get('bot_debug','0'),'1'); ?>>
                                Salvar último payload recebido (para diagnóstico)
                            </label>
                        </td>
                    </tr>
                </table>

                <!-- Log de conversas do bot -->
                <h4 style="margin-top:20px">📊 Últimas conversas</h4>
                <?php
                $log = class_exists('BarberPro_WhatsApp_Bot') ? BarberPro_WhatsApp_Bot::get_log() : [];
                if (empty($log)):
                ?>
                <p style="color:#6b7280;font-size:.88rem">Nenhuma conversa registrada ainda.</p>
                <?php else: ?>
                <div style="max-height:280px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px">
                    <table class="wp-list-table widefat fixed striped" style="font-size:.78rem">
                        <thead><tr><th width="90">Hora</th><th width="110">Telefone</th><th width="100">Nome</th><th>Recebida</th><th>Enviada</th></tr></thead>
                        <tbody>
                        <?php foreach(array_slice($log,0,30) as $row): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n('d/m H:i', strtotime($row['hora']))); ?></td>
                            <td><?php echo esc_html($row['tel']); ?></td>
                            <td><?php echo esc_html($row['nome']); ?></td>
                            <td><?php echo esc_html(mb_substr($row['recebida'],0,50)); ?></td>
                            <td><?php echo esc_html(mb_substr($row['enviada'],0,70)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <script>
        function bpNotifToggle(key, cor, bg) {
            var cb    = document.querySelector('[name="notify_'+key+'_ativo"]');
            var card  = document.getElementById('notif-card-'+key);
            var cfg   = document.getElementById('notif-cfg-'+key);
            var badge = card.querySelector('[style*="inline-block"]');
            if (cb.checked) {
                card.style.borderColor = cor;
                card.style.background  = bg;
                badge.style.background = cor;
                badge.style.color      = '#fff';
                badge.textContent      = '● ATIVO';
                if (cfg) cfg.style.display = '';
                // Sincroniza: ativar whatsapp ativa também o bot_ativo legacy
                if (key === 'bot') document.querySelector('[name="bot_ativo"]') && (document.querySelector('[name="bot_ativo"]').checked = true);
            } else {
                card.style.borderColor = '#e5e7eb';
                card.style.background  = '#fafafa';
                badge.style.background = '#e5e7eb';
                badge.style.color      = '#6b7280';
                badge.textContent      = '○ INATIVO';
                if (cfg) cfg.style.display = 'none';
            }
        }
        </script>

        <!-- ═══ TAB: BOT AGENDAMENTO ═══ -->
        <div id="tab-bot" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🤖 Bot de Agendamento via WhatsApp</h2>

            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin-bottom:24px;font-size:.9rem">
                <strong>ℹ️ Como funciona:</strong> O cliente manda qualquer mensagem sobre agendamento no WhatsApp → o bot responde automaticamente, pergunta serviço, profissional, data e horário → e cria o agendamento no sistema, tudo sem intervenção humana.<br><br>
                <strong>Webhook URL:</strong> <code style="background:#e8f5e9;padding:3px 8px;border-radius:4px;font-size:.88rem"><?php echo esc_url(rest_url('barberpro/v1/whatsapp')); ?></code>
                <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js(rest_url('barberpro/v1/whatsapp')); ?>');this.textContent='✓ Copiado!';setTimeout(()=>this.textContent='Copiar',2000)"
                        style="margin-left:8px;padding:3px 10px;border:1px solid #16a34a;border-radius:4px;background:#f0fdf4;color:#16a34a;cursor:pointer;font-size:.82rem">Copiar</button>
            </div>

            <table class="form-table">
                <tr>
                    <th>Ativar Bot</th>
                    <td>
                        <label>
                            <input type="checkbox" name="bot_ativo" value="1" <?php checked(bp_get('bot_ativo','0'),'1'); ?>>
                            Ativar bot de agendamento automático via WhatsApp
                        </label>
                        <p class="description">Quando ativo, toda mensagem recebida no webhook será processada pelo bot.</p>
                    </td>
                </tr>
                <tr>
                    <th>Modo do Bot</th>
                    <td>
                        <?php $bot_mode = bp_get('bot_mode','passo_a_passo'); ?>
                        <label style="display:flex;align-items:center;gap:10px;margin-bottom:10px;cursor:pointer">
                            <input type="radio" name="bot_mode" value="passo_a_passo" <?php checked($bot_mode,'passo_a_passo'); ?>>
                            <span>
                                <strong>🔢 Passo a passo</strong><br>
                                <span style="font-size:.85rem;color:#6b7280">Fluxo guiado: o bot pergunta serviço → profissional → data → horário → pagamento, um passo de cada vez.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                            <input type="radio" name="bot_mode" value="ia" <?php checked($bot_mode,'ia'); ?>>
                            <span>
                                <strong>🧠 Inteligência Artificial (OpenAI)</strong><br>
                                <span style="font-size:.85rem;color:#6b7280">A IA conduz a conversa de forma natural usando o prompt configurado na aba <strong>🧠 IA (OpenAI)</strong>. Se a IA não responder, o passo a passo entra como fallback.</span>
                            </span>
                        </label>
                        <p class="description" style="margin-top:8px">⚠️ O modo IA requer a OpenAI configurada e ativa na aba 🧠 IA (OpenAI).</p>
                    </td>
                </tr>
                <tr>
                    <th>Token de Segurança</th>
                    <td>
                        <input type="text" name="bot_webhook_token" value="<?php echo esc_attr(bp_get('bot_webhook_token','')); ?>"
                               class="regular-text" placeholder="Deixe vazio para não usar token">
                        <p class="description">Se preenchido, a Z-API/W-API deve enviar este token no header <code>X-Webhook-Token</code>.</p>
                        <button type="button" onclick="document.querySelector('[name=bot_webhook_token]').value=Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)"
                                style="margin-top:6px;padding:4px 10px;border:1px solid #d1d5db;border-radius:4px;background:#f9f9f9;cursor:pointer;font-size:.82rem">Gerar token aleatório</button>
                    </td>
                </tr>
                <tr>
                    <th>Debug (log de payloads)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="bot_debug" value="1" <?php checked(bp_get('bot_debug','0'),'1'); ?>>
                            Salvar último payload recebido (para diagnóstico)
                        </label>
                        <?php $payload = bp_get('bot_ultimo_payload',''); if($payload): ?>
                        <details style="margin-top:8px">
                            <summary style="cursor:pointer;font-size:.82rem;color:#6b7280">Ver último payload recebido</summary>
                            <pre style="background:#f3f4f6;border-radius:6px;padding:10px;font-size:.75rem;overflow:auto;max-height:200px"><?php echo esc_html($payload); ?></pre>
                        </details>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">📋 Configuração da Z-API / W-API</h3>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:16px;font-size:.88rem">
                No painel da Z-API ou W-API, configure o webhook apontando para a URL acima.<br>
                O bot responde automaticamente — não precisa de nenhuma outra configuração.
            </div>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">✏️ Textos do Bot (Editar Falas)</h3>
            <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.87rem">
                <strong>ℹ️</strong> Edite as mensagens que o bot envia para o cliente durante o agendamento via WhatsApp. Use <code>{nome}</code> onde quiser o nome do cliente.
            </div>
            <table class="form-table">
                <tr>
                    <th>Menu inicial</th>
                    <td>
                        <textarea name="bot_msg_menu" rows="4" class="large-text"><?php echo esc_textarea(bp_get('bot_msg_menu', "Olá{nome}! 👋 Como posso te ajudar?

1️⃣ Fazer um agendamento
2️⃣ Cancelar agendamento

Responda o número ou descreva o que precisa 😊")); ?></textarea>
                        <p class="description">Mensagem enviada quando o cliente fala algo não reconhecido. Use <code>{nome}</code> para o nome.</p>
                    </td>
                </tr>
                <tr>
                    <th>Pedir data</th>
                    <td>
                        <textarea name="bot_msg_data" rows="3" class="large-text"><?php echo esc_textarea(bp_get('bot_msg_data', "Qual data prefere? 📅

• *hoje*
• *amanhã*
• Dia da semana (ex: *sexta*)
• Ou uma data (ex: *28/03*)")); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Confirmar agendamento</th>
                    <td>
                        <textarea name="bot_msg_confirmar" rows="3" class="large-text"><?php echo esc_textarea(bp_get('bot_msg_confirmar', "Confirma? Responda *sim* para confirmar ou *não* para cancelar 😊")); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Agendamento confirmado</th>
                    <td>
                        <textarea name="bot_msg_sucesso" rows="4" class="large-text"><?php echo esc_textarea(bp_get('bot_msg_sucesso', "✅ *Agendamento confirmado!*

📋 Código: *{codigo}*
📅 {data} às *{hora}*

Te esperamos! 😊
_Para cancelar: cancelar {codigo}_")); ?></textarea>
                        <p class="description">Variáveis: <code>{codigo}</code> <code>{data}</code> <code>{hora}</code></p>
                    </td>
                </tr>
                <tr>
                    <th>Cancelamento confirmado</th>
                    <td>
                        <textarea name="bot_msg_cancelado" rows="2" class="large-text"><?php echo esc_textarea(bp_get('bot_msg_cancelado', "Ok! Agendamento cancelado 😊 Se precisar remarcar é só chamar!")); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Sem horários disponíveis</th>
                    <td>
                        <textarea name="bot_msg_sem_horarios" rows="2" class="large-text"><?php echo esc_textarea(bp_get('bot_msg_sem_horarios', "Infelizmente não temos horários nessa data 😔 Quer tentar outra data?")); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>📍 Localização / Estacionamento</th>
                    <td>
                        <textarea name="bot_msg_localizacao" rows="4" class="large-text"
                                  placeholder="Ex: Rua das Flores, 123 — Estacionamento gratuito na rua&#10;📌 Google Maps: https://maps.app.goo.gl/..."><?php echo esc_textarea(bp_get('bot_msg_localizacao', '')); ?></textarea>
                        <p class="description">Enviado junto com a confirmação (WhatsApp e Chat do Site). Deixe vazio para não enviar. Pode incluir link do Google Maps.</p>
                    </td>
                </tr>
            </table>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">📊 Últimas conversas do bot</h3>
            <?php
            $log = class_exists('BarberPro_WhatsApp_Bot') ? BarberPro_WhatsApp_Bot::get_log() : [];
            if ( empty($log) ):
            ?>
            <p style="color:#6b7280;font-size:.88rem">Nenhuma conversa registrada ainda.</p>
            <?php else: ?>
            <div style="max-height:320px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px">
                <table class="wp-list-table widefat fixed striped" style="font-size:.82rem">
                    <thead><tr><th>Hora</th><th>Telefone</th><th>Nome</th><th>Recebida</th><th>Enviada</th></tr></thead>
                    <tbody>
                    <?php foreach( array_slice($log,0,50) as $row ): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('d/m H:i', strtotime($row['hora']))); ?></td>
                        <td><?php echo esc_html($row['tel']); ?></td>
                        <td><?php echo esc_html($row['nome']); ?></td>
                        <td><?php echo esc_html(mb_substr($row['recebida'],0,60)); ?></td>
                        <td><?php echo esc_html(mb_substr($row['enviada'],0,80)); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══ TAB: AGENDAMENTO ═══ -->
        <div id="tab-booking" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">Regras de Agendamento</h2>
            <table class="form-table">
                <tr>
                    <th>Antecedência mínima</th>
                    <td>
                        <input type="number" name="booking_min_advance_minutes" value="<?php echo esc_attr( bp_get('booking_min_advance_minutes', 60) ); ?>" class="small-text" min="0">
                        <span class="description">minutos antes do horário</span>
                    </td>
                </tr>
                <tr>
                    <th>Máximo de dias para agendar</th>
                    <td>
                        <input type="number" name="booking_max_advance_days" value="<?php echo esc_attr( bp_get('booking_max_advance_days', 30) ); ?>" class="small-text" min="1">
                        <span class="description">dias à frente</span>
                    </td>
                </tr>
                <tr>
                    <th>Prazo para cancelamento</th>
                    <td>
                        <input type="number" name="cancellation_hours" value="<?php echo esc_attr( bp_get('cancellation_hours', 2) ); ?>" class="small-text" min="0">
                        <span class="description">horas de antecedência mínima para cancelar</span>
                    </td>
                </tr>
                <tr>
                    <th>Sinal obrigatório</th>
                    <td>
                        <label>
                            <input type="checkbox" name="require_deposit" value="1" <?php checked( bp_get('require_deposit'), '1' ); ?>>
                            Exigir depósito antecipado
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Percentual do sinal (%)</th>
                    <td>
                        <input type="number" name="deposit_pct" value="<?php echo esc_attr( bp_get('deposit_pct', 50) ); ?>" class="small-text" min="1" max="100">
                        <span class="description">% do valor total</span>
                    </td>
                </tr>
            </table>

            <h2 style="border-top:1px solid #e5e7eb;padding-top:20px">🤖 Automação do Kanban</h2>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.9rem">
                <strong>ℹ️</strong> O sistema avança os agendamentos automaticamente entre as colunas do Kanban com base no horário marcado e na duração do serviço.
                Roda a cada minuto via WP-Cron.
            </div>
            <table class="form-table">
                <tr>
                    <th>Ativar automação</th>
                    <td>
                        <label>
                            <input type="checkbox" name="kanban_auto_enabled" value="1" <?php checked( bp_get('kanban_auto_enabled','1'), '1' ); ?>>
                            Ativar avanço automático de status no Kanban
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>✅ Agendado → Confirmado</th>
                    <td>
                        <label>
                            <input type="checkbox" name="kanban_auto_confirm" value="1" <?php checked( bp_get('kanban_auto_confirm','1'), '1' ); ?>>
                            Confirmar automaticamente
                        </label>
                        <div style="margin-top:8px">
                            <input type="number" name="kanban_auto_confirm_minutes" value="<?php echo esc_attr( bp_get('kanban_auto_confirm_minutes','30') ); ?>" class="small-text" min="0" max="1440">
                            <span class="description">minutos antes do horário (0 = apenas quando chegar a hora)</span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>▶️ Confirmado → Em atendimento</th>
                    <td>
                        <label>
                            <input type="checkbox" name="kanban_auto_start" value="1" <?php checked( bp_get('kanban_auto_start','1'), '1' ); ?>>
                            Iniciar automaticamente quando chegar o horário
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>🏁 Em atendimento → Finalizado</th>
                    <td>
                        <label>
                            <input type="checkbox" name="kanban_auto_finish" value="1" <?php checked( bp_get('kanban_auto_finish','1'), '1' ); ?>>
                            Finalizar automaticamente após a duração do serviço
                        </label>
                        <p class="description">Usa a duração cadastrada em cada serviço. Ex: serviço de 30min marcado às 14h → finaliza às 14h30.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ═══ TAB: MENSAGENS & TEMPOS ═══ -->
        <div id="tab-messages" class="barberpro-tab-content" style="display:none">

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:24px">
                <strong>📌 Variáveis disponíveis nas mensagens:</strong><br>
                <code>{nome}</code> · <code>{data}</code> · <code>{hora}</code> · <code>{profissional}</code> ·
                <code>{servico}</code> · <code>{codigo}</code> · <code>{valor}</code> · <code>{placa}</code> · <code>{link}</code>
            </div>

            <!-- ─── 1. CONFIRMAÇÃO ────────────────────────────────────── -->
            <div class="bp-msg-card">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">✅</span>
                    <div>
                        <h3>Confirmação de Agendamento</h3>
                        <p>Enviada <strong>imediatamente</strong> após o agendamento ser criado.</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_confirmation_active" value="1" <?php checked( bp_get('msg_confirmation_active','1'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <textarea name="msg_confirmation" rows="4" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_confirmation',
                    "Olá {nome}! ✅\n\nSeu agendamento foi confirmado!\n\n✂️ Serviço: {servico}\n📅 Data: {data} às {hora}\n👤 Profissional: {profissional}\n🔖 Código: {codigo}\n\nAté logo!"
                ) ); ?></textarea>
            </div>

            <!-- ─── 2. LEMBRETE ───────────────────────────────────────── -->
            <div class="bp-msg-card">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">⏰</span>
                    <div>
                        <h3>Lembrete de Agendamento</h3>
                        <p>Enviado automaticamente <strong>X horas/minutos antes</strong> do horário.</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_reminder_active" value="1" <?php checked( bp_get('msg_reminder_active','1'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <div class="bp-msg-timing">
                    <label>⏱ Enviar com</label>
                    <input type="number" name="reminder_hours" value="<?php echo esc_attr( bp_get('reminder_hours', 24) ); ?>" min="0" max="168">
                    <label>horas</label>
                    <input type="number" name="reminder_minutes" value="<?php echo esc_attr( bp_get('reminder_minutes', 0) ); ?>" min="0" max="59" style="width:64px">
                    <label>minutos de antecedência</label>
                    <span class="bp-timing-tip">
                        <?php
                        $rh = (int) bp_get('reminder_hours', 24);
                        $rm = (int) bp_get('reminder_minutes', 0);
                        if ($rh === 24 && $rm === 0) echo '→ 1 dia antes';
                        elseif ($rh > 0 && $rm === 0) echo "→ {$rh}h antes";
                        else echo "→ {$rh}h {$rm}min antes";
                        ?>
                    </span>
                </div>
                <textarea name="msg_reminder" rows="4" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_reminder',
                    "Olá {nome}! ⏰\n\nLembrete: você tem um agendamento amanhã!\n\n✂️ {servico}\n📅 {data} às {hora}\n👤 {profissional}\n\nQualquer dúvida, estamos aqui! 😊"
                ) ); ?></textarea>
            </div>

            <!-- ─── 3. SEGUNDO LEMBRETE (opcional) ───────────────────── -->
            <div class="bp-msg-card">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">🔔</span>
                    <div>
                        <h3>Segundo Lembrete <em style="font-size:.8rem;color:#6b7280">(opcional)</em></h3>
                        <p>Um segundo lembrete mais próximo do horário — ex: 1 hora antes.</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_reminder2_active" value="1" <?php checked( bp_get('msg_reminder2_active','0'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <div class="bp-msg-timing">
                    <label>⏱ Enviar com</label>
                    <input type="number" name="reminder2_hours" value="<?php echo esc_attr( bp_get('reminder2_hours', 1) ); ?>" min="0" max="24">
                    <label>horas</label>
                    <input type="number" name="reminder2_minutes" value="<?php echo esc_attr( bp_get('reminder2_minutes', 0) ); ?>" min="0" max="59" style="width:64px">
                    <label>minutos de antecedência</label>
                </div>
                <textarea name="msg_reminder2" rows="4" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_reminder2',
                    "Olá {nome}! 🔔\n\nSeu agendamento é em breve!\n\n✂️ {servico}\n🕐 Hoje às {hora}\n📍 Esperamos por você!"
                ) ); ?></textarea>
            </div>

            <!-- ─── 4. CANCELAMENTO ───────────────────────────────────── -->
            <div class="bp-msg-card">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">❌</span>
                    <div>
                        <h3>Cancelamento</h3>
                        <p>Enviada <strong>imediatamente</strong> quando o agendamento é cancelado.</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_cancellation_active" value="1" <?php checked( bp_get('msg_cancellation_active','1'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <textarea name="msg_cancellation" rows="4" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_cancellation',
                    "Olá {nome},\n\nSeu agendamento do dia {data} às {hora} foi cancelado.\n\nQualquer dúvida, entre em contato conosco. 😊"
                ) ); ?></textarea>
            </div>

            <!-- ─── 5. AVALIAÇÃO (pós-atendimento) ────────────────────── -->
            <div class="bp-msg-card">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">⭐</span>
                    <div>
                        <h3>Solicitação de Avaliação</h3>
                        <p>Enviada após o atendimento ser marcado como <strong>finalizado</strong>.</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_review_active" value="1" <?php checked( bp_get('msg_review_active','1'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <div class="bp-msg-timing">
                    <label>⏱ Enviar após</label>
                    <input type="number" name="review_delay_hours" value="<?php echo esc_attr( bp_get('review_delay_hours', 1) ); ?>" min="0" max="48">
                    <label>horas do término</label>
                    <span class="bp-timing-tip">→ Ex: 1h depois dá tempo do cliente chegar em casa</span>
                </div>
                <textarea name="msg_review" rows="4" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_review',
                    "Olá {nome}! ⭐\n\nObrigado por nos visitar hoje!\n\nO que achou do atendimento de {servico}? Sua opinião é muito importante para nós.\n\nAvalie em: {link}"
                ) ); ?></textarea>
            </div>

            <!-- ─── 6. MENSAGEM DE ANIVERSÁRIO (opcional) ─────────────── -->
            <div class="bp-msg-card">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">🎂</span>
                    <div>
                        <h3>Feliz Aniversário <em style="font-size:.8rem;color:#6b7280">(opcional)</em></h3>
                        <p>Enviada no <strong>dia do aniversário</strong> do cliente (requer data de nascimento no cadastro).</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_birthday_active" value="1" <?php checked( bp_get('msg_birthday_active','0'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <div class="bp-msg-timing">
                    <label>⏱ Enviar às</label>
                    <input type="time" name="birthday_send_time" value="<?php echo esc_attr( bp_get('birthday_send_time', '09:00') ); ?>">
                    <span class="bp-timing-tip">→ Horário de envio no dia do aniversário</span>
                </div>
                <textarea name="msg_birthday" rows="4" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_birthday',
                    "Olá {nome}! 🎂🎉\n\nHoje é um dia especial — Feliz Aniversário!\n\nComo presente, temos um desconto especial para você esta semana. Venha nos visitar! 🎁"
                ) ); ?></textarea>
            </div>

            <!-- ─── 7. MENSAGEM DE RETORNO / REAGENDAMENTO ────────────── -->
            <div class="bp-msg-card" style="border-color:#8b5cf6">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">🔁</span>
                    <div>
                        <h3>Mensagem de Retorno <em style="font-size:.8rem;color:#6b7280">(automática)</em></h3>
                        <p>Enviada quando o cliente <strong>passa do prazo médio</strong> sem agendar.<br>
                        O sistema calcula automaticamente a frequência de cada cliente pelo histórico.</p>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="msg_return_active" value="1" <?php checked( bp_get('msg_return_active','0'), '1' ); ?>>
                        <span class="bp-toggle-slider"></span>
                    </label>
                </div>
                <div class="bp-msg-timing">
                    <label>📅 Prazo padrão (sem histórico)</label>
                    <input type="number" name="return_default_days" value="<?php echo esc_attr( bp_get('return_default_days', 30) ); ?>" min="7" max="365" style="width:80px">
                    <label>dias após último atendimento</label>
                    <span class="bp-timing-tip">→ Para clientes novos sem histórico de frequência</span>
                </div>
                <div class="bp-msg-timing" style="margin-top:8px">
                    <label>🔗 URL da página de agendamento</label>
                    <input type="text" name="booking_page_url" value="<?php echo esc_attr( bp_get('booking_page_url', home_url('/agendamento/')) ); ?>" class="large-text" placeholder="https://seusite.com.br/agendamento/">
                </div>
                <div style="background:#f3f0ff;border-radius:8px;padding:12px 14px;margin:12px 0;font-size:.85rem;color:#5b21b6">
                    <strong>Variáveis disponíveis:</strong><br>
                    <code>{nome}</code> <code>{profissional}</code> <code>{servico}</code>
                    <code>{dias_media}</code> → ex: <em>"30 dias"</em> (média calculada do cliente)<br>
                    <code>{link_agendamento}</code> → link direto para agendar
                </div>
                <textarea name="msg_return" rows="6" class="large-text bp-msg-text"><?php echo esc_textarea( bp_get('msg_return',
                    "Olá {nome}! 🔁\n\nFaz um tempinho que não te vemos por aqui!\n\nPelo seu histórico, você costuma cortar de {dias_media} em {dias_media}. Que tal agendar agora?\n\n📅 Agende com {profissional}:\n{link_agendamento}\n\nEstamos esperando por você! ✂️"
                ) ); ?></textarea>
            </div>

            <!-- ─── CONFIGURAÇÕES DE INTERVALO DE SLOTS ───────────────── -->
            <div class="bp-msg-card" style="border-color:#0891b2">
                <div class="bp-msg-header">
                    <span class="bp-msg-icon">⏱</span>
                    <div>
                        <h3>Intervalos de Agendamento</h3>
                        <p>Configure os intervalos de horário para <strong>barbeiro</strong> (maior controle) e <strong>cliente</strong> (menos opções, mais simples).</p>
                    </div>
                </div>
                <table class="form-table" style="margin:0">
                    <tr>
                        <th style="width:220px">✂️ Intervalo do barbeiro (admin)</th>
                        <td>
                            <select name="admin_slot_interval_default">
                                <?php foreach ([5,10,15,20,30,45,60] as $m): ?>
                                <option value="<?php echo $m; ?>" <?php selected(bp_get('admin_slot_interval_default',15), $m); ?>><?php echo $m; ?> minutos</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Padrão para novos profissionais. Permite agendamentos a cada <em>N</em> minutos no painel admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>📱 Intervalo do cliente (público)</th>
                        <td>
                            <select name="client_slot_interval_default">
                                <?php foreach ([15,30,60,90,120] as $m): ?>
                                <option value="<?php echo $m; ?>" <?php selected(bp_get('client_slot_interval_default',60), $m); ?>><?php echo $m; ?> minutos</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Padrão para novos profissionais. O cliente vê menos opções para não ficar confuso.</p>
                        </td>
                    </tr>
                </table>
                <div style="background:#e0f2fe;border-radius:8px;padding:12px 14px;margin-top:12px;font-size:.85rem;color:#0c4a6e">
                    <strong>ℹ️ Configuração individual:</strong> cada profissional pode ter seu próprio intervalo configurado no cadastro de profissionais.
                </div>
            </div>

        </div>

        <!-- ═══ TAB: PWA ═══ -->
        <div id="tab-pwa" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">📱 App (PWA) — Instalar na Tela Inicial</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Transforma o sistema em um <strong>app instalável</strong> no celular do cliente e do barbeiro — sem precisar da App Store ou Play Store.
            </p>

            <!-- Status card -->
            <?php $pwa_ativo = bp_get('pwa_ativo','0')==='1'; ?>
            <div style="border:2px solid <?php echo $pwa_ativo?'#10b981':'#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo $pwa_ativo?'rgba(16,185,129,.06)':'#fafafa'; ?>;margin-bottom:28px;display:flex;justify-content:space-between;align-items:center" id="pwa-status-card">
                <div>
                    <div style="font-size:1.8rem;margin-bottom:4px">📱</div>
                    <div style="font-weight:800;font-size:1rem">PWA — Progressive Web App</div>
                    <div style="font-size:.8rem;color:#6b7280;margin-top:4px;line-height:1.6">
                        ✓ Ícone na tela inicial do celular &nbsp;·&nbsp; ✓ Abre como app (sem barra do navegador)<br>
                        ✓ Funciona Android e iPhone &nbsp;·&nbsp; ✓ Custo zero
                    </div>
                </div>
                <label class="bp-toggle" style="flex-shrink:0">
                    <input type="checkbox" name="pwa_ativo" value="1"
                           id="pwa_ativo_cb"
                           <?php checked($pwa_ativo,true); ?>
                           onchange="bpPwaToggleCard()">
                    <span class="bp-toggle-slider" style="<?php echo $pwa_ativo?'background:#10b981':''; ?>"></span>
                </label>
            </div>

            <!-- Como instalar — preview -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px">
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
                    <div style="font-weight:700;margin-bottom:10px">🤖 Android (automático)</div>
                    <div style="font-size:.84rem;color:#374151;line-height:1.7">
                        1. Cliente abre o site no Chrome<br>
                        2. Banner aparece automaticamente na parte de baixo<br>
                        3. Clica em <strong>"Instalar"</strong><br>
                        4. Ícone aparece na tela inicial 🎉
                    </div>
                </div>
                <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:18px">
                    <div style="font-weight:700;margin-bottom:10px">🍎 iPhone (manual)</div>
                    <div style="font-size:.84rem;color:#374151;line-height:1.7">
                        1. Cliente abre o site no Safari<br>
                        2. Instrução aparece na tela<br>
                        3. Toca em <strong>Compartilhar ↑</strong><br>
                        4. Toca em <strong>"Adicionar à Tela de Início"</strong> 🎉
                    </div>
                </div>
            </div>

            <!-- Configurações -->
            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">⚙️ Configurações do App</h3>
            <table class="form-table">
                <tr>
                    <th>Nome do App *</th>
                    <td>
                        <input type="text" name="pwa_nome"
                               value="<?php echo esc_attr(bp_get('pwa_nome', bp_get('business_name', get_bloginfo('name')))); ?>"
                               class="regular-text" placeholder="Ex: Barbearia do João">
                        <p class="description">Nome exibido na tela inicial do celular</p>
                    </td>
                </tr>
                <tr>
                    <th>Nome curto (máx. 12 caracteres)</th>
                    <td>
                        <input type="text" name="pwa_nome_curto"
                               value="<?php echo esc_attr(bp_get('pwa_nome_curto','')); ?>"
                               class="regular-text" maxlength="12" placeholder="Ex: Barber João">
                        <p class="description">Nome abreviado — aparece embaixo do ícone</p>
                    </td>
                </tr>
                <tr>
                    <th>Ícone do App (URL)</th>
                    <td>
                        <input type="url" name="pwa_icone_url"
                               value="<?php echo esc_attr(bp_get('pwa_icone_url','')); ?>"
                               class="large-text" placeholder="https://seusite.com/logo-512x512.png">
                        <p class="description">Imagem <strong>quadrada</strong> de pelo menos <strong>512×512px</strong> (PNG). Use o logo da barbearia.</p>
                    </td>
                </tr>
                <tr>
                    <th>Cor do tema</th>
                    <td>
                        <input type="color" name="pwa_cor_tema" value="<?php echo esc_attr(bp_get('pwa_cor_tema','#1a1a2e')); ?>">
                        <span class="description">Cor da barra de status do celular quando o app estiver aberto</span>
                    </td>
                </tr>
                <tr>
                    <th>Página inicial do app</th>
                    <td>
                        <input type="url" name="pwa_start_url"
                               value="<?php echo esc_attr(bp_get('pwa_start_url', home_url('/'))); ?>"
                               class="large-text">
                        <p class="description">Página que abre quando o cliente toca no ícone. Ex: página de agendamento.</p>
                    </td>
                </tr>
                <tr>
                    <th>Mensagem de instalação iOS</th>
                    <td>
                        <input type="text" name="pwa_ios_msg"
                               value="<?php echo esc_attr(bp_get('pwa_ios_msg','Para instalar: toque em Compartilhar ↑ e depois Adicionar à Tela de Início')); ?>"
                               class="large-text">
                    </td>
                </tr>
            </table>

            <!-- Instrução técnica -->
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:16px 20px;margin-top:20px">
                <strong>⚠️ Ativando pela primeira vez:</strong><br>
                <span style="font-size:.87rem">Após salvar com o PWA ativo, vá em <strong>WordPress → Configurações → Links Permanentes</strong> e clique em <strong>Salvar</strong> (sem mudar nada). Isso registra as URLs do <code>manifest.json</code> e <code>sw.js</code>.</span>
            </div>

            <script>
            function bpPwaToggleCard() {
                var cb   = document.getElementById('pwa_ativo_cb');
                var card = document.getElementById('pwa-status-card');
                var sl   = card.querySelector('.bp-toggle-slider');
                if (cb.checked) {
                    card.style.borderColor='#10b981'; card.style.background='rgba(16,185,129,.06)';
                    sl.style.background='#10b981';
                } else {
                    card.style.borderColor='#e5e7eb'; card.style.background='#fafafa';
                    sl.style.background='';
                }
            }
            </script>
        </div>

        <!-- ═══ TAB: WIDGET CHAT ═══ -->
        <div id="tab-widget" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">💬 Widget de Chat para Agendamento</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Chat flutuante que aparece em todas as páginas do site. A IA guia o cliente pelo agendamento completo.
            </p>

            <!-- Card de status + preview -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px">

                <!-- Status -->
                <div style="border:2px solid <?php echo bp_get('widget_chat_ativo','0')==='1'?'#10b981':'#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo bp_get('widget_chat_ativo','0')==='1'?'rgba(16,185,129,.06)':'#fafafa'; ?>" id="wc-status-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
                        <div>
                            <div style="font-size:1.8rem;margin-bottom:4px">💬</div>
                            <div style="font-weight:800;font-size:1rem">Widget de Chat</div>
                            <div style="font-size:.8rem;color:#6b7280;margin-top:2px">Agendamento automático pelo site</div>
                        </div>
                        <label class="bp-toggle">
                            <input type="checkbox" name="widget_chat_ativo" value="1"
                                   id="widget_chat_ativo_cb"
                                   <?php checked(bp_get('widget_chat_ativo','0'),'1'); ?>
                                   onchange="bpWcToggleCard()">
                            <span class="bp-toggle-slider" style="<?php echo bp_get('widget_chat_ativo','0')==='1'?'background:#10b981':''; ?>"></span>
                        </label>
                    </div>
                    <span id="wc-status-badge" style="font-size:.75rem;padding:4px 12px;border-radius:20px;font-weight:700;display:inline-block;
                          background:<?php echo bp_get('widget_chat_ativo','0')==='1'?'#10b981':'#e5e7eb'; ?>;
                          color:<?php echo bp_get('widget_chat_ativo','0')==='1'?'#fff':'#6b7280'; ?>">
                        <?php echo bp_get('widget_chat_ativo','0')==='1'?'● ATIVO':'○ INATIVO'; ?>
                    </span>
                    <div style="margin-top:14px;font-size:.78rem;color:#6b7280;line-height:1.7">
                        ✓ Aparece em todas as páginas<br>
                        ✓ Coleta nome, celular e e-mail<br>
                        ✓ Notifica cliente e empresa<br>
                        ✓ Sem necessidade de login
                    </div>
                </div>

                <!-- Preview visual -->
                <div style="border:1px solid #e5e7eb;border-radius:14px;padding:20px;background:#1a1a2e;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px">
                    <div style="font-size:.75rem;color:rgba(255,255,255,.4);text-align:center;margin-bottom:4px">PREVIEW — aparência no site</div>
                    <div style="width:200px;background:#1a1a2e;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.5);overflow:hidden;border:1px solid rgba(255,255,255,.08)">
                        <div style="background:linear-gradient(135deg,#16213e,#0f3460);padding:10px 12px;display:flex;align-items:center;gap:8px">
                            <div id="wc-prev-avatar" style="width:30px;height:30px;border-radius:50%;background:<?php echo esc_attr(bp_get('widget_chat_cor','#f5a623')); ?>;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;color:#fff;flex-shrink:0">
                                <?php echo mb_substr(bp_get('widget_chat_nome_bot','A'),0,1); ?>
                            </div>
                            <div>
                                <div id="wc-prev-name" style="font-size:.75rem;font-weight:700;color:#fff"><?php echo esc_html(bp_get('widget_chat_nome_bot','Assistente')); ?></div>
                                <div style="font-size:.6rem;color:rgba(255,255,255,.4)">● Online</div>
                            </div>
                        </div>
                        <div style="padding:10px 12px;font-size:.7rem;color:#e2e8f0;line-height:1.5;min-height:50px">
                            <?php echo esc_html(mb_substr(bp_get('widget_chat_saudacao','Olá! Posso te ajudar a agendar 😊'),0,60)); ?>...
                        </div>
                        <div style="padding:6px 10px 10px;display:flex;gap:6px">
                            <div style="flex:1;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.1);border-radius:20px;padding:6px 10px;font-size:.65rem;color:rgba(255,255,255,.3)">
                                Digite aqui...
                            </div>
                            <div id="wc-prev-btn" style="width:28px;height:28px;border-radius:50%;background:<?php echo esc_attr(bp_get('widget_chat_cor','#f5a623')); ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            </div>
                        </div>
                    </div>
                    <div id="wc-prev-toggle" style="width:46px;height:46px;border-radius:50%;background:<?php echo esc_attr(bp_get('widget_chat_cor','#f5a623')); ?>;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(0,0,0,.3)">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                </div>
            </div>

            <!-- Configurações visuais -->
            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">🎨 Aparência</h3>
            <table class="form-table">
                <tr>
                    <th>Nome do Bot</th>
                    <td>
                        <input type="text" name="widget_chat_nome_bot"
                               value="<?php echo esc_attr(bp_get('widget_chat_nome_bot','Assistente')); ?>"
                               class="regular-text" placeholder="Ex: Ana, Carlos, Assistente..."
                               oninput="document.getElementById('wc-prev-name').textContent=this.value||'Bot';document.getElementById('wc-prev-avatar').textContent=(this.value||'A').charAt(0).toUpperCase()">
                    </td>
                </tr>
                <tr>
                    <th>Cor principal</th>
                    <td>
                        <input type="color" name="widget_chat_cor"
                               value="<?php echo esc_attr(bp_get('widget_chat_cor','#f5a623')); ?>"
                               oninput="document.getElementById('wc-prev-avatar').style.background=this.value;document.getElementById('wc-prev-btn').style.background=this.value;document.getElementById('wc-prev-toggle').style.background=this.value;">
                        <p class="description">Cor do botão flutuante e das mensagens do usuário</p>
                    </td>
                </tr>
                <tr>
                    <th>Avatar do Bot (URL)</th>
                    <td>
                        <input type="url" name="widget_chat_avatar"
                               value="<?php echo esc_attr(bp_get('widget_chat_avatar','')); ?>"
                               class="large-text" placeholder="https://seusite.com/avatar.jpg">
                        <p class="description">Deixe vazio para usar a inicial do nome.</p>
                    </td>
                </tr>
                <tr>
                    <th>Posição na tela</th>
                    <td>
                        <select name="widget_chat_posicao">
                            <option value="right" <?php selected(bp_get('widget_chat_posicao','right'),'right'); ?>>Direita (padrão)</option>
                            <option value="left"  <?php selected(bp_get('widget_chat_posicao','right'),'left'); ?>>Esquerda</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Mensagem de saudação</th>
                    <td>
                        <textarea name="widget_chat_saudacao" rows="2" class="large-text"><?php echo esc_textarea(bp_get('widget_chat_saudacao','Olá! 👋 Posso te ajudar a agendar um horário agora mesmo.')); ?></textarea>
                        <p class="description">Primeira mensagem que o bot exibe ao abrir.</p>
                    </td>
                </tr>
            </table>

            <!-- Textos do Chat do Site -->
            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">✏️ Textos do Chat do Site (Editar Falas)</h3>
            <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.87rem">
                <strong>ℹ️</strong> Edite as mensagens que o chat do site envia durante o fluxo de agendamento.
            </div>
            <table class="form-table">
                <tr>
                    <th>Pedir nome</th>
                    <td>
                        <input type="text" name="wc_msg_pedir_nome" class="large-text"
                               value="<?php echo esc_attr(bp_get('wc_msg_pedir_nome','Para começar, qual é o seu nome?')); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Pedir celular</th>
                    <td>
                        <input type="text" name="wc_msg_pedir_celular" class="large-text"
                               value="<?php echo esc_attr(bp_get('wc_msg_pedir_celular','Prazer! Qual é o seu celular com DDD?')); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Pedir e-mail</th>
                    <td>
                        <input type="text" name="wc_msg_pedir_email" class="large-text"
                               value="<?php echo esc_attr(bp_get('wc_msg_pedir_email','Ótimo! Agora me diga seu e-mail para enviar a confirmação 📧')); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Agendamento confirmado</th>
                    <td>
                        <textarea name="wc_msg_sucesso" rows="4" class="large-text"><?php echo esc_textarea(bp_get('wc_msg_sucesso','✅ *Agendamento confirmado!*

📋 Código: *{codigo}*
📅 {data} às *{hora}*

Te esperamos! 😊')); ?></textarea>
                        <p class="description">Variáveis: <code>{codigo}</code> <code>{data}</code> <code>{hora}</code></p>
                    </td>
                </tr>
                <tr>
                    <th>Sem horários disponíveis</th>
                    <td>
                        <input type="text" name="wc_msg_sem_horarios" class="large-text"
                               value="<?php echo esc_attr(bp_get('wc_msg_sem_horarios','Infelizmente não temos horários disponíveis nessa data 😔 Quer tentar outra data?')); ?>">
                    </td>
                </tr>
                <tr>
                    <th>Cancelamento</th>
                    <td>
                        <input type="text" name="wc_msg_cancelado" class="large-text"
                               value="<?php echo esc_attr(bp_get('wc_msg_cancelado','Agendamento cancelado 😊 Se quiser remarcar é só começar de novo!')); ?>">
                    </td>
                </tr>
            </table>

            <!-- Configurações de notificação -->
            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">🔔 Notificações do Dono</h3>
            <p class="description" style="margin-bottom:16px">Além de notificar o cliente, o bot avisa o dono a cada novo agendamento.</p>
            <table class="form-table">
                <tr>
                    <th>E-mail do dono</th>
                    <td>
                        <input type="email" name="widget_chat_email_dono"
                               value="<?php echo esc_attr(bp_get('widget_chat_email_dono', get_bloginfo('admin_email'))); ?>"
                               class="regular-text">
                        <p class="description">Receberá um e-mail a cada agendamento feito pelo chat.</p>
                    </td>
                </tr>
                <tr>
                    <th>WhatsApp do dono</th>
                    <td>
                        <input type="text" name="widget_chat_tel_dono"
                               value="<?php echo esc_attr(bp_get('widget_chat_tel_dono', bp_get('whatsapp_number',''))); ?>"
                               class="regular-text" placeholder="5544999990000"
                               oninput="this.value=this.value.replace(/\D/g,'')">
                        <p class="description">Receberá uma mensagem no WhatsApp a cada novo agendamento. Deixe vazio para não enviar.</p>
                    </td>
                </tr>
            </table>

            <script>
            function bpWcToggleCard() {
                var cb    = document.getElementById('widget_chat_ativo_cb');
                var card  = document.getElementById('wc-status-card');
                var badge = document.getElementById('wc-status-badge');
                if (cb.checked) {
                    card.style.borderColor = '#10b981'; card.style.background = 'rgba(16,185,129,.06)';
                    badge.style.background = '#10b981'; badge.style.color = '#fff';
                    badge.textContent = '● ATIVO';
                    document.querySelector('[name="widget_chat_ativo"] + .bp-toggle-slider').style.background='#10b981';
                } else {
                    card.style.borderColor = '#e5e7eb'; card.style.background = '#fafafa';
                    badge.style.background = '#e5e7eb'; badge.style.color = '#6b7280';
                    badge.textContent = '○ INATIVO';
                    document.querySelector('[name="widget_chat_ativo"] + .bp-toggle-slider').style.background='';
                }
            }
            </script>
        </div>

        <!-- ═══ TAB: LOJA VIRTUAL ═══ -->
        <div id="tab-loja" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🛍️ Loja Virtual</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Configure a loja virtual do site. Use o shortcode <code>[barberpro_loja]</code> em qualquer página.<br>
                Para filtrar por módulo: <code>[barberpro_loja company="barbearia"]</code> ou <code>[barberpro_loja company="lavacar"]</code><br>
                Para exibir o título: <code>[barberpro_loja show_title="1"]</code> &nbsp;|&nbsp; Para 4 colunas: <code>[barberpro_loja colunas="4"]</code>
            </p>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">⚙️ Geral</h3>
            <table class="form-table">
                <tr>
                    <th>Nome da Loja</th>
                    <td><input type="text" name="shop_nome" value="<?php echo esc_attr(bp_get('shop_nome',get_bloginfo('name').' — Loja')); ?>" class="regular-text"></td>
                </tr>
            </table>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">🚚 Frete</h3>
            <table class="form-table">
                <tr>
                    <th>Tipo de frete</th>
                    <td>
                        <select name="shop_frete_tipo" id="shop_frete_tipo" onchange="bpLojaFreteToggle()">
                            <option value="fixo"      <?php selected(bp_get('shop_frete_tipo','fixo'),'fixo'); ?>>Taxa fixa única</option>
                            <option value="por_faixa" <?php selected(bp_get('shop_frete_tipo','fixo'),'por_faixa'); ?>>Por faixa de CEP</option>
                        </select>
                    </td>
                </tr>
                <tr id="row_frete_fixo">
                    <th>Taxa fixa (R$)</th>
                    <td>
                        <input type="number" name="shop_frete_fixo" value="<?php echo esc_attr(bp_get('shop_frete_fixo','10')); ?>"
                               class="small-text" min="0" step="0.01">
                        <span class="description">Valor cobrado para qualquer entrega</span>
                    </td>
                </tr>
                <tr id="row_frete_faixas" style="<?php echo bp_get('shop_frete_tipo','fixo')==='por_faixa'?'':'display:none'; ?>">
                    <th>Faixas de CEP (JSON)</th>
                    <td>
                        <textarea name="shop_frete_faixas" rows="5" class="large-text" style="font-family:monospace;font-size:.8rem"><?php echo esc_textarea(bp_get('shop_frete_faixas','[{"cep_ini":"80000000","cep_fim":"89999999","valor":"12.00"},{"cep_ini":"90000000","cep_fim":"99999999","valor":"18.00"}]')); ?></textarea>
                        <p class="description">Array JSON: <code>[{"cep_ini":"80000000","cep_fim":"89999999","valor":"12.00"}]</code></p>
                    </td>
                </tr>
                <tr id="row_frete_fora" style="<?php echo bp_get('shop_frete_tipo','fixo')==='por_faixa'?'':'display:none'; ?>">
                    <th>Frete fora das faixas (R$)</th>
                    <td>
                        <input type="number" name="shop_frete_fora_faixa" value="<?php echo esc_attr(bp_get('shop_frete_fora_faixa','0')); ?>"
                               class="small-text" min="0" step="0.01">
                        <span class="description">0 = não entrega fora das faixas</span>
                    </td>
                </tr>
                <tr>
                    <th>Frete grátis acima de (R$)</th>
                    <td>
                        <input type="number" name="shop_frete_gratis_minimo" value="<?php echo esc_attr(bp_get('shop_frete_gratis_minimo','0')); ?>"
                               class="small-text" min="0" step="0.01">
                        <span class="description">0 = não oferece frete grátis</span>
                    </td>
                </tr>
            </table>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">🔔 Notificações</h3>
            <table class="form-table">
                <tr>
                    <th>E-mail para notificar pedidos</th>
                    <td>
                        <input type="email" name="shop_notify_email"
                               value="<?php echo esc_attr(bp_get('shop_notify_email', get_bloginfo('admin_email'))); ?>"
                               class="regular-text">
                        <p class="description">Receberá um e-mail a cada novo pedido.</p>
                    </td>
                </tr>
                <tr>
                    <th>WhatsApp para notificar pedidos</th>
                    <td>
                        <input type="text" name="shop_notify_whatsapp"
                               value="<?php echo esc_attr(bp_get('shop_notify_whatsapp', bp_get('whatsapp_number',''))); ?>"
                               class="regular-text" placeholder="5544999990000"
                               oninput="this.value=this.value.replace(/\D/g,'')">
                    </td>
                </tr>
            </table>

            <script>
            function bpLojaFreteToggle() {
                var v = document.getElementById('shop_frete_tipo').value;
                document.getElementById('row_frete_faixas').style.display = v==='por_faixa' ? '' : 'none';
                document.getElementById('row_frete_fora').style.display   = v==='por_faixa' ? '' : 'none';
            }
            </script>
        </div>

        <!-- ═══ TAB: LAVA-CAR ═══ -->
        <div id="tab-lavacar" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🚗 Configurações do Lava-Car</h2>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.9rem">
                <strong>ℹ️ Modalidades de coleta/entrega:</strong> configure quais modalidades ficam disponíveis para o cliente escolher no agendamento, e a taxa de cada uma.
            </div>

            <h3>📋 Modalidades disponíveis</h3>
            <table class="form-table">

                <!-- 1. Cliente traz e busca -->
                <tr>
                    <th style="width:260px">
                        <label>
                            <input type="checkbox" name="delivery_opt_cliente_traz" value="1"
                                <?php checked( bp_get('delivery_opt_cliente_traz','1'), '1' ); ?>>
                            🏠 Cliente traz e busca
                        </label>
                    </th>
                    <td>
                        <span style="color:#6b7280;font-size:.88rem">Sem taxa — cliente entrega e retira o veículo</span>
                    </td>
                </tr>

                <!-- 2. Empresa busca e entrega -->
                <tr>
                    <th>
                        <label>
                            <input type="checkbox" name="delivery_opt_busca_entrega" value="1"
                                <?php checked( bp_get('delivery_opt_busca_entrega','1'), '1' ); ?>>
                            🚐 Empresa busca e entrega
                        </label>
                    </th>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <label style="font-size:.88rem">Taxa: R$</label>
                            <input type="number" name="delivery_fee_busca_entrega"
                                value="<?php echo esc_attr( bp_get('delivery_fee_busca_entrega','0') ); ?>"
                                min="0" step="0.50" style="width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px">
                            <p class="description" style="margin:0">Cobrada por ida+volta</p>
                        </div>
                    </td>
                </tr>

                <!-- 3. Empresa busca, cliente retira -->
                <tr>
                    <th>
                        <label>
                            <input type="checkbox" name="delivery_opt_busca_retira" value="1"
                                <?php checked( bp_get('delivery_opt_busca_retira','1'), '1' ); ?>>
                            📦 Empresa busca, cliente retira
                        </label>
                    </th>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <label style="font-size:.88rem">Taxa: R$</label>
                            <input type="number" name="delivery_fee_busca_retira"
                                value="<?php echo esc_attr( bp_get('delivery_fee_busca_retira','0') ); ?>"
                                min="0" step="0.50" style="width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px">
                            <p class="description" style="margin:0">Cobrada só pela busca</p>
                        </div>
                    </td>
                </tr>

                <!-- 4. Cliente leva, empresa entrega -->
                <tr>
                    <th>
                        <label>
                            <input type="checkbox" name="delivery_opt_leva_entrega" value="1"
                                <?php checked( bp_get('delivery_opt_leva_entrega','1'), '1' ); ?>>
                            🏁 Cliente leva, empresa entrega
                        </label>
                    </th>
                    <td>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                            <label style="font-size:.88rem">Taxa: R$</label>
                            <input type="number" name="delivery_fee_leva_entrega"
                                value="<?php echo esc_attr( bp_get('delivery_fee_leva_entrega','0') ); ?>"
                                min="0" step="0.50" style="width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px">
                            <p class="description" style="margin:0">Cobrada só pela entrega</p>
                        </div>
                    </td>
                </tr>

            </table>

            <h3>📍 Área de atendimento</h3>
            <table class="form-table">
                <tr>
                    <th>Raio máximo de atendimento</th>
                    <td>
                        <input type="number" name="delivery_max_km" value="<?php echo esc_attr( bp_get('delivery_max_km','10') ); ?>" min="1" class="small-text">
                        <span class="description">km — exibido como informação ao cliente</span>
                    </td>
                </tr>
                <tr>
                    <th>Mensagem de entrega</th>
                    <td>
                        <textarea name="delivery_info_msg" rows="2" class="large-text"><?php echo esc_textarea( bp_get('delivery_info_msg',
                            'Buscamos e entregamos seu veículo em até {raio}km. A taxa será adicionada automaticamente ao valor do serviço.'
                        ) ); ?></textarea>
                        <p class="description">Use <code>{raio}</code> para o raio configurado acima</p>
                    </td>
                </tr>
                <tr>
                    <th>Solicitar endereço completo</th>
                    <td>
                        <label>
                            <input type="checkbox" name="delivery_require_full_address" value="1"
                                <?php checked( bp_get('delivery_require_full_address','1'), '1' ); ?>>
                            Exigir rua, número e bairro (recomendado)
                        </label>
                    </td>
                </tr>
            </table>

            <h3>🔔 WhatsApp ao confirmar coleta</h3>
            <table class="form-table">
                <tr>
                    <th>Aviso de saída para busca</th>
                    <td>
                        <textarea name="msg_delivery_pickup" rows="3" class="large-text"><?php echo esc_textarea( bp_get('msg_delivery_pickup',
                            "Olá {nome}! 🚐

Estamos a caminho para buscar seu veículo!

Endereço: {endereco}
Previsão: em breve.

Qualquer dúvida, responda esta mensagem."
                        ) ); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Aviso de entrega pronta</th>
                    <td>
                        <textarea name="msg_delivery_done" rows="3" class="large-text"><?php echo esc_textarea( bp_get('msg_delivery_done',
                            "Olá {nome}! ✅

Seu veículo está pronto e a caminho!

🚗 {placa} · {modelo}
Estimativa de chegada: em breve.

Obrigado por escolher a gente! 😊"
                        ) ); ?></textarea>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ═══ TAB: PAGAMENTOS ONLINE ═══ -->
        <div id="tab-payment-online" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🏦 Pagamentos Online</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Configure gateways de pagamento para cobrar sinal ou valor total no momento do agendamento.
                Tudo opcional — cada gateway é ativado individualmente.
            </p>

            <!-- Cards de gateway -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;margin-bottom:32px">

                <!-- PIX -->
                <?php $pix_ativo = bp_get('payment_pix_ativo','0')==='1'; ?>
                <div style="border:2px solid <?php echo $pix_ativo?'#00b4d8':'#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo $pix_ativo?'rgba(0,180,216,.06)':'#fafafa'; ?>" id="gw-card-pix">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
                        <div>
                            <div style="font-size:1.8rem;margin-bottom:4px">⚡</div>
                            <div style="font-weight:800;font-size:1rem">PIX Estático</div>
                            <div style="font-size:.8rem;color:#6b7280;margin-top:2px">QR Code gerado automaticamente — sem taxas</div>
                        </div>
                        <label class="bp-toggle">
                            <input type="checkbox" name="payment_pix_ativo" value="1"
                                   <?php checked($pix_ativo,true); ?>
                                   onchange="bpGwToggle('pix','#00b4d8','rgba(0,180,216,.06)')">
                            <span class="bp-toggle-slider" style="<?php echo $pix_ativo?'background:#00b4d8':''; ?>"></span>
                        </label>
                    </div>
                    <span style="font-size:.75rem;padding:4px 10px;border-radius:20px;font-weight:700;display:inline-block;
                          background:<?php echo $pix_ativo?'#00b4d8':'#e5e7eb'; ?>;
                          color:<?php echo $pix_ativo?'#fff':'#6b7280'; ?>">
                        <?php echo $pix_ativo?'● ATIVO':'○ INATIVO'; ?>
                    </span>
                    <div style="margin-top:12px;font-size:.78rem;color:#6b7280">
                        ✓ Sem integração de API · ✓ Gera QR Code na hora · ✓ Copia e cola
                    </div>
                </div>

                <!-- Mercado Pago -->
                <?php $mp_ativo = bp_get('payment_mp_ativo','0')==='1'; ?>
                <div style="border:2px solid <?php echo $mp_ativo?'#009ee3':'#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo $mp_ativo?'rgba(0,158,227,.06)':'#fafafa'; ?>" id="gw-card-mercadopago">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:14px">
                        <div>
                            <div style="font-size:1.8rem;margin-bottom:4px">💳</div>
                            <div style="font-weight:800;font-size:1rem">Mercado Pago</div>
                            <div style="font-size:.8rem;color:#6b7280;margin-top:2px">Checkout Pro — Pix, cartão, boleto</div>
                        </div>
                        <label class="bp-toggle">
                            <input type="checkbox" name="payment_mp_ativo" value="1"
                                   <?php checked($mp_ativo,true); ?>
                                   onchange="bpGwToggle('mercadopago','#009ee3','rgba(0,158,227,.06)')">
                            <span class="bp-toggle-slider" style="<?php echo $mp_ativo?'background:#009ee3':''; ?>"></span>
                        </label>
                    </div>
                    <span style="font-size:.75rem;padding:4px 10px;border-radius:20px;font-weight:700;display:inline-block;
                          background:<?php echo $mp_ativo?'#009ee3':'#e5e7eb'; ?>;
                          color:<?php echo $mp_ativo?'#fff':'#6b7280'; ?>">
                        <?php echo $mp_ativo?'● ATIVO':'○ INATIVO'; ?>
                    </span>
                    <div style="margin-top:12px;font-size:.78rem;color:#6b7280">
                        ✓ Pix + Cartão + Boleto · ✓ Confirmação automática · ✓ Webhook
                    </div>
                </div>
            </div>

            <!-- CONFIGURAÇÕES PIX -->
            <div id="gw-cfg-pix" style="<?php echo $pix_ativo?'':'display:none'; ?>">
                <h3 style="border-top:2px solid #00b4d8;padding-top:18px;color:#00b4d8">⚡ Configurações PIX</h3>
                <table class="form-table">
                    <tr>
                        <th>Chave PIX *</th>
                        <td>
                            <input type="text" name="pix_key" value="<?php echo esc_attr(bp_get('pix_key','')); ?>" class="regular-text"
                                   placeholder="CPF, CNPJ, e-mail, telefone ou chave aleatória">
                            <p class="description">A chave que o cliente usará para pagar.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Nome do titular *</th>
                        <td>
                            <input type="text" name="pix_holder" value="<?php echo esc_attr(bp_get('pix_holder','')); ?>" class="regular-text"
                                   placeholder="Nome que aparece na transferência (máx. 25 caracteres)">
                        </td>
                    </tr>
                    <tr>
                        <th>Cidade</th>
                        <td>
                            <input type="text" name="pix_city" value="<?php echo esc_attr(bp_get('pix_city','Brasil')); ?>" class="regular-text"
                                   placeholder="Ex: Foz do Iguacu">
                            <p class="description">Aparece no QR Code (sem acentos, máx. 15 caracteres).</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Expiração do QR (min)</th>
                        <td>
                            <input type="number" name="pix_expiry_minutes" value="<?php echo esc_attr(bp_get('pix_expiry_minutes','30')); ?>" class="small-text" min="5" max="1440">
                            <span class="description">Minutos até o QR Code expirar (padrão: 30 min)</span>
                        </td>
                    </tr>
                </table>

                <!-- Preview do QR Code -->
                <div style="margin-top:16px;padding:16px;background:#f0fafa;border:1px solid #b2ebf2;border-radius:10px">
                    <strong style="font-size:.9rem">🔍 Preview do QR Code</strong>
                    <p style="font-size:.82rem;color:#6b7280;margin:4px 0 12px">Clique para gerar um QR de teste com valor R$ 1,00</p>
                    <button type="button" onclick="bpPreviewPix()" class="button">Gerar QR de Teste</button>
                    <div id="bp_pix_preview" style="margin-top:14px;display:none;text-align:center">
                        <img id="bp_pix_qr_img" src="" alt="QR PIX" style="border-radius:8px;border:1px solid #e5e7eb">
                        <div style="margin-top:8px">
                            <label style="font-size:.78rem;color:#6b7280">Copia e Cola:</label>
                            <div style="display:flex;gap:6px;margin-top:4px">
                                <input type="text" id="bp_pix_payload" readonly style="flex:1;font-size:.72rem;padding:6px;border:1px solid #e5e7eb;border-radius:6px">
                                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('bp_pix_payload').value);this.textContent='✓';setTimeout(()=>this.textContent='Copiar',2000)" class="button button-small">Copiar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- CONFIGURAÇÕES MERCADO PAGO -->
            <div id="gw-cfg-mercadopago" style="<?php echo $mp_ativo?'':'display:none'; ?>">
                <h3 style="border-top:2px solid #009ee3;padding-top:18px;color:#009ee3">💳 Configurações Mercado Pago</h3>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:20px;font-size:.87rem">
                    <strong>Como obter as credenciais:</strong><br>
                    1. Acesse <a href="https://www.mercadopago.com.br/developers/pt/docs" target="_blank">mercadopago.com.br/developers</a><br>
                    2. Crie um aplicativo → copie o <strong>Access Token</strong> de Produção (ou Teste)<br>
                    3. Configure o webhook apontando para: <code><?php echo esc_url(rest_url('barberpro/v1/mp-webhook')); ?></code>
                    <button type="button" onclick="navigator.clipboard.writeText('<?php echo esc_js(rest_url('barberpro/v1/mp-webhook')); ?>');this.textContent='✓';setTimeout(()=>this.textContent='Copiar',2000)"
                            style="margin-left:8px;padding:3px 8px;border:1px solid #3b82f6;border-radius:4px;background:#eff6ff;color:#3b82f6;cursor:pointer;font-size:.8rem">Copiar</button>
                </div>
                <table class="form-table">
                    <tr>
                        <th>Access Token *</th>
                        <td>
                            <input type="password" name="mp_access_token" value="<?php echo esc_attr(bp_get('mp_access_token','')); ?>" class="large-text"
                                   placeholder="APP_USR-...">
                            <p class="description">Token de Produção começa com <code>APP_USR-</code>. Token de Teste começa com <code>TEST-</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Public Key</th>
                        <td>
                            <input type="text" name="mp_public_key" value="<?php echo esc_attr(bp_get('mp_public_key','')); ?>" class="large-text"
                                   placeholder="APP_USR-... (necessário para o SDK JS)">
                        </td>
                    </tr>
                    <tr>
                        <th>Modo Sandbox (testes)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="mp_sandbox" value="1" <?php checked(bp_get('mp_sandbox','1'),'1'); ?>>
                                Ativar modo de testes (use token <code>TEST-</code>)
                            </label>
                            <p class="description">⚠️ Desative antes de ir para produção.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Testar credenciais</th>
                        <td>
                            <button type="button" onclick="bpTestarMP()" class="button">🧪 Testar Mercado Pago</button>
                            <span id="mp_test_result" style="margin-left:10px;font-size:.88rem"></span>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- CONFIGURAÇÕES GERAIS DE COBRANÇA -->
            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px;margin-top:32px">⚙️ Regras de Cobrança Online</h3>
            <table class="form-table">
                <tr>
                    <th>Quando cobrar</th>
                    <td>
                        <select name="online_payment_when">
                            <?php
                            $when = bp_get('online_payment_when','optional');
                            $opts = ['optional'=>'Opcional — cliente escolhe pagar online ou no local',
                                     'required_deposit'=>'Sinal obrigatório (% do valor)',
                                     'required_full'=>'Valor total obrigatório'];
                            foreach($opts as $v=>$l):
                            ?>
                            <option value="<?php echo $v; ?>" <?php selected($when,$v); ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="row_deposit_pct_online" style="<?php echo $when==='required_deposit'?'':'display:none'; ?>">
                    <th>Percentual do sinal (%)</th>
                    <td>
                        <input type="number" name="deposit_pct" value="<?php echo esc_attr(bp_get('deposit_pct','50')); ?>"
                               class="small-text" min="1" max="100">
                        <span class="description">% do valor total cobrado como sinal</span>
                    </td>
                </tr>
                <tr>
                    <th>Status após pagamento online</th>
                    <td>
                        <select name="mp_status_after_payment">
                            <option value="confirmado" <?php selected(bp_get('mp_status_after_payment','confirmado'),'confirmado'); ?>>Confirmado</option>
                            <option value="agendado"   <?php selected(bp_get('mp_status_after_payment','confirmado'),'agendado'); ?>>Aguardando confirmação manual</option>
                        </select>
                        <p class="description">Status do agendamento após pagamento aprovado pelo Mercado Pago.</p>
                    </td>
                </tr>
            </table>

            <script>
            function bpGwToggle(key, cor, bg) {
                var cb   = document.querySelector('[name="payment_' + key + '_ativo"]');
                var card = document.getElementById('gw-card-' + key);
                var cfg  = document.getElementById('gw-cfg-' + key);
                var badge = card.querySelector('span[style*="inline-block"]');
                if (cb.checked) {
                    card.style.borderColor = cor; card.style.background = bg;
                    badge.style.background = cor; badge.style.color = '#fff';
                    badge.textContent = '● ATIVO';
                    if (cfg) cfg.style.display = '';
                } else {
                    card.style.borderColor = '#e5e7eb'; card.style.background = '#fafafa';
                    badge.style.background = '#e5e7eb'; badge.style.color = '#6b7280';
                    badge.textContent = '○ INATIVO';
                    if (cfg) cfg.style.display = 'none';
                }
            }
            document.querySelector('[name="online_payment_when"]')?.addEventListener('change', function() {
                document.getElementById('row_deposit_pct_online').style.display = this.value === 'required_deposit' ? '' : 'none';
            });
            function bpPreviewPix() {
                var key    = document.querySelector('[name="pix_key"]')?.value.trim();
                var holder = document.querySelector('[name="pix_holder"]')?.value.trim();
                var city   = document.querySelector('[name="pix_city"]')?.value.trim();
                if (!key || !holder) { alert('Preencha Chave PIX e Nome do Titular antes.'); return; }
                document.getElementById('bp_pix_preview').style.display = '';
                fetch(ajaxurl, {
                    method:'POST', credentials:'same-origin',
                    body: new URLSearchParams({ action:'bp_preview_pix', nonce:'<?php echo wp_create_nonce("bp_preview_pix"); ?>', pix_key:key, pix_holder:holder, pix_city:city, amount:'1.00' })
                }).then(r=>r.json()).then(data=>{
                    if(data.success){
                        document.getElementById('bp_pix_qr_img').src = data.data.qr_url;
                        document.getElementById('bp_pix_payload').value = data.data.payload;
                    }
                });
            }
            function bpTestarMP() {
                var token  = document.querySelector('[name="mp_access_token"]')?.value.trim();
                var result = document.getElementById('mp_test_result');
                if (!token) { result.textContent='⚠️ Preencha o Access Token antes.'; result.style.color='#d97706'; return; }
                result.textContent='⏳ Testando...'; result.style.color='#6b7280';
                fetch(ajaxurl, {
                    method:'POST', credentials:'same-origin',
                    body: new URLSearchParams({ action:'bp_testar_mp', nonce:'<?php echo wp_create_nonce("bp_testar_mp"); ?>', token:token })
                }).then(r=>r.json()).then(data=>{
                    if(data.success){ result.textContent='✅ '+data.data.message; result.style.color='#16a34a'; }
                    else { result.textContent='❌ '+(data.data?.message||'Falha'); result.style.color='#dc2626'; }
                });
            }
            </script>
        </div>

        <!-- ═══ TAB: PAGAMENTOS ═══ -->
        <div id="tab-payment" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">💳 Formas de Pagamento</h2>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:24px;font-size:.9rem">
                <strong>ℹ️</strong> Ative ou desative os métodos de pagamento que seu estabelecimento aceita.
                Os métodos ativos aparecem automaticamente no caixa, no painel de agendamentos e nos relatórios financeiros.
            </div>

            <?php
            $payment_methods = [
                'dinheiro'       => ['icon'=>'💵', 'label'=>'Dinheiro',          'desc'=>'Pagamento em espécie'],
                'pix'            => ['icon'=>'⚡', 'label'=>'PIX',               'desc'=>'Transferência instantânea'],
                'cartao_debito'  => ['icon'=>'💳', 'label'=>'Cartão de Débito',  'desc'=>'Débito em conta'],
                'cartao_credito' => ['icon'=>'💳', 'label'=>'Cartão de Crédito', 'desc'=>'Crédito à vista ou parcelado'],
                'transferencia'  => ['icon'=>'🏦', 'label'=>'TED / Transferência','desc'=>'Transferência bancária'],
                'voucher'        => ['icon'=>'🎟️', 'label'=>'Voucher / Vale',    'desc'=>'Vale-refeição, gift card, etc.'],
                'boleto'         => ['icon'=>'📄', 'label'=>'Boleto',            'desc'=>'Boleto bancário'],
                'outro'          => ['icon'=>'💰', 'label'=>'Outro',             'desc'=>'Outros métodos personalizados'],
            ];
            ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;margin-bottom:28px">
                <?php foreach($payment_methods as $key => $pm):
                    $enabled = bp_get('pay_method_'.$key, in_array($key,['dinheiro','pix','cartao_debito','cartao_credito'])?'1':'0');
                ?>
                <div style="background:#f9f9f9;border:1px solid <?php echo $enabled==='1'?'#6366f1':'#e5e7eb'; ?>;border-radius:10px;padding:16px 18px;transition:border .2s">
                    <div style="display:flex;justify-content:space-between;align-items:center">
                        <div style="display:flex;align-items:center;gap:10px">
                            <span style="font-size:1.5rem"><?php echo $pm['icon']; ?></span>
                            <div>
                                <div style="font-weight:700;font-size:.95rem"><?php echo esc_html($pm['label']); ?></div>
                                <div style="font-size:.78rem;color:#6b7280"><?php echo esc_html($pm['desc']); ?></div>
                            </div>
                        </div>
                        <label class="bp-toggle">
                            <input type="checkbox" name="pay_method_<?php echo $key; ?>" value="1"
                                   <?php checked($enabled, '1'); ?>
                                   onchange="this.closest('div[style]').style.borderColor=this.checked?'#6366f1':'#e5e7eb'">
                            <span class="bp-toggle-slider"></span>
                        </label>
                    </div>
                    <?php if($key==='pix'): ?>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                        <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Chave PIX</label>
                        <input type="text" name="pix_key" value="<?php echo esc_attr(bp_get('pix_key','')); ?>"
                               placeholder="CPF, CNPJ, e-mail, telefone ou chave aleatória"
                               style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem">
                        <label style="font-size:.8rem;font-weight:600;display:block;margin:8px 0 4px">Nome do titular</label>
                        <input type="text" name="pix_holder" value="<?php echo esc_attr(bp_get('pix_holder','')); ?>"
                               placeholder="Nome que aparece na transferência"
                               style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem">
                    </div>
                    <?php endif; ?>
                    <?php if($key==='voucher'): ?>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                        <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Tipos de voucher aceitos</label>
                        <input type="text" name="voucher_types" value="<?php echo esc_attr(bp_get('voucher_types','')); ?>"
                               placeholder="Ex: Alelo, Ticket, VR, Sodexo..."
                               style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem">
                    </div>
                    <?php endif; ?>
                    <?php if($key==='outro'): ?>
                    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb">
                        <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Nome personalizado</label>
                        <input type="text" name="outro_payment_label" value="<?php echo esc_attr(bp_get('outro_payment_label','Outro')); ?>"
                               placeholder="Ex: Fiado, Crédito interno..."
                               style="width:100%;padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:.85rem">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">🧾 Configurações de Taxa</h3>
            <table class="form-table">
                <tr>
                    <th>Taxa cartão de crédito (%)</th>
                    <td>
                        <input type="number" name="fee_cartao_credito" value="<?php echo esc_attr(bp_get('fee_cartao_credito','0')); ?>"
                               class="small-text" min="0" max="10" step="0.01">
                        <p class="description">Percentual cobrado pela maquininha (ex: 2.99). Usado nos relatórios.</p>
                    </td>
                </tr>
                <tr>
                    <th>Taxa cartão de débito (%)</th>
                    <td>
                        <input type="number" name="fee_cartao_debito" value="<?php echo esc_attr(bp_get('fee_cartao_debito','0')); ?>"
                               class="small-text" min="0" max="10" step="0.01">
                    </td>
                </tr>
                <tr>
                    <th>Cobrar taxa do cliente</th>
                    <td>
                        <label>
                            <input type="checkbox" name="pass_card_fee_to_client" value="1"
                                   <?php checked(bp_get('pass_card_fee_to_client','0'),'1'); ?>>
                            Repassar taxa da maquininha no valor final (acréscimo automático)
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ═══ TAB: FINANCEIRO ═══ -->
        <div id="tab-finance" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">Fidelidade & Aparência</h2>
            <table class="form-table">
                <tr>
                    <th>Pontos por atendimento</th>
                    <td>
                        <input type="number" name="loyalty_points_per_booking" value="<?php echo esc_attr( bp_get('loyalty_points_per_booking', 10) ); ?>" class="small-text" min="0">
                        <p class="description">Pontos creditados ao cliente após cada atendimento finalizado</p>
                    </td>
                </tr>
                <tr>
                    <th>Dark Mode padrão</th>
                    <td>
                        <label>
                            <input type="checkbox" name="dark_mode" value="1" <?php checked( bp_get('dark_mode'), '1' ); ?>>
                            Ativar dark mode no painel admin
                        </label>
                    </td>
                </tr>
            </table>
        </div>

        <!-- ═══ TAB: AQUECIMENTO ═══ -->
        <div id="tab-aquecimento" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🔥 Aquecimento & Engajamento</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Mantém seus clientes engajados enviando mensagens automáticas periódicas.
                Ideal para promoções, dicas e lembretes de retorno sem parecer spam.
            </p>

            <div style="border:2px solid <?php echo bp_get('warming_ativo','0')==='1'?'#f59e0b':'#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo bp_get('warming_ativo','0')==='1'?'rgba(245,158,11,.06)':'#fafafa'; ?>;margin-bottom:28px">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <div style="font-size:1.8rem;margin-bottom:4px">🔥</div>
                        <div style="font-weight:800;font-size:1rem">Aquecimento Automático</div>
                        <div style="font-size:.8rem;color:#6b7280;margin-top:2px">Enviado automaticamente nos dias e horário configurados</div>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="warming_ativo" value="1" <?php checked(bp_get('warming_ativo','0'),'1'); ?>>
                        <span class="bp-toggle-slider" style="<?php echo bp_get('warming_ativo','0')==='1'?'background:#f59e0b':''; ?>"></span>
                    </label>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th>Dias da semana</th>
                    <td>
                        <?php
                        $warming_dias = explode(',', bp_get('warming_dias','2,5'));
                        $dias_semana = ['0'=>'Dom','1'=>'Seg','2'=>'Ter','3'=>'Qua','4'=>'Qui','5'=>'Sex','6'=>'Sáb'];
                        foreach ($dias_semana as $v => $l):
                        ?>
                        <label style="margin-right:12px">
                            <input type="checkbox" name="warming_dias[]" value="<?php echo $v; ?>" <?php checked(in_array($v, $warming_dias)); ?>>
                            <?php echo $l; ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th>Horário de envio</th>
                    <td>
                        <input type="time" name="warming_horario" value="<?php echo esc_attr(bp_get('warming_horario','10:00')); ?>">
                        <p class="description">Hora que as mensagens serão enfileiradas para envio</p>
                    </td>
                </tr>
                <tr>
                    <th>Frequência</th>
                    <td>
                        <select name="warming_frequencia">
                            <option value="1" <?php selected(bp_get('warming_frequencia','1'),'1'); ?>>1x por semana</option>
                            <option value="2" <?php selected(bp_get('warming_frequencia','1'),'2'); ?>>2x por semana</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Delay entre mensagens (s)</th>
                    <td>
                        <input type="number" name="warming_delay_seconds" value="<?php echo esc_attr(bp_get('warming_delay_seconds','5')); ?>" class="small-text" min="5" max="60">
                        <span class="description">Mínimo 5 segundos (evita ser bloqueado)</span>
                    </td>
                </tr>
                <tr>
                    <th>Mensagem de texto</th>
                    <td>
                        <textarea name="warming_msg" rows="4" class="large-text"><?php echo esc_textarea(bp_get('warming_msg','')); ?></textarea>
                        <p class="description">Use <code>{nome}</code> para personalizar. Deixe vazio se quiser só enviar mídia.</p>
                    </td>
                </tr>
                <tr>
                    <th>URL de imagem/vídeo (opcional)</th>
                    <td>
                        <input type="url" name="warming_media_url" value="<?php echo esc_attr(bp_get('warming_media_url','')); ?>" class="large-text" placeholder="https://seusite.com/promo.jpg">
                        <select name="warming_media_type" style="margin-top:6px">
                            <option value="image"    <?php selected(bp_get('warming_media_type','image'),'image'); ?>>🖼️ Imagem</option>
                            <option value="video"    <?php selected(bp_get('warming_media_type','image'),'video'); ?>>🎬 Vídeo</option>
                            <option value="document" <?php selected(bp_get('warming_media_type','image'),'document'); ?>>📄 Documento</option>
                        </select>
                    </td>
                </tr>
            </table>

            <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:14px 18px;margin-top:16px;font-size:.87rem">
                <strong>💡 Boas práticas anti-spam:</strong><br>
                • Limite a 1-2x por semana para não incomodar<br>
                • Use mensagens de valor: dicas, promoções, novidades<br>
                • O delay mínimo de 5s entre envios ajuda a não ser bloqueado pelo WhatsApp<br>
                • Clientes dos últimos 90 dias são selecionados automaticamente
            </div>
        </div>

        <!-- ═══ TAB: FILA DE MENSAGENS ═══ -->
        <div id="tab-fila" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">📤 Fila de Mensagens</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Monitor da fila de envio automático de WhatsApp.
                Mensagens são processadas a cada minuto via WP-Cron.
            </p>

            <?php
            $stats = class_exists('BarberPro_Message_Queue') ? BarberPro_Message_Queue::stats() : [];
            ?>
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:28px">
                <div style="background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:2rem;font-weight:800;color:#d97706"><?php echo (int)($stats['pending']??0); ?></div>
                    <div style="font-size:.82rem;color:#6b7280;margin-top:4px">⏳ Pendentes</div>
                </div>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:2rem;font-weight:800;color:#16a34a"><?php echo (int)($stats['sent']??0); ?></div>
                    <div style="font-size:.82rem;color:#6b7280;margin-top:4px">✅ Enviadas</div>
                </div>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:2rem;font-weight:800;color:#dc2626"><?php echo (int)($stats['failed']??0); ?></div>
                    <div style="font-size:.82rem;color:#6b7280;margin-top:4px">❌ Falhas</div>
                </div>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:16px;text-align:center">
                    <div style="font-size:2rem;font-weight:800;color:#3b82f6"><?php echo (int)($stats['processing']??0); ?></div>
                    <div style="font-size:.82rem;color:#6b7280;margin-top:4px">⚙️ Processando</div>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th>Mensagens por ciclo</th>
                    <td>
                        <input type="number" name="queue_batch_size" value="<?php echo esc_attr(bp_get('queue_batch_size','5')); ?>" class="small-text" min="1" max="20">
                        <span class="description">Máximo de mensagens enviadas a cada minuto (recomendado: 3-5)</span>
                    </td>
                </tr>
                <tr>
                    <th>Delay entre envios (s)</th>
                    <td>
                        <input type="number" name="queue_delay_seconds" value="<?php echo esc_attr(bp_get('queue_delay_seconds','3')); ?>" class="small-text" min="1" max="30">
                        <span class="description">Segundos de pausa entre cada mensagem</span>
                    </td>
                </tr>
                <tr>
                    <th>Limpar histórico após (dias)</th>
                    <td>
                        <input type="number" name="queue_cleanup_days" value="<?php echo esc_attr(bp_get('queue_cleanup_days','30')); ?>" class="small-text" min="7" max="365">
                        <span class="description">Mensagens enviadas/falhadas são removidas após este prazo</span>
                    </td>
                </tr>
            </table>

            <div style="background:#f8f9fa;border-radius:8px;padding:14px 18px;margin-top:16px;font-size:.87rem">
                <strong>ℹ️ Como funciona:</strong><br>
                • Fila processada a cada <strong>1 minuto</strong> via WP-Cron<br>
                • Lock anti-duplicação impede dois processos simultâneos<br>
                • Máximo de <strong>3 tentativas</strong> por mensagem antes de marcar como falha<br>
                • Prioridade: lembretes (alta) → confirmações → reativação → aquecimento (baixa)
            </div>
        </div>

        <!-- ═══ TAB: OPENAI ═══ -->
        <div id="tab-openai" class="barberpro-tab-content" style="display:none">
            <h2 style="margin-top:0">🧠 Integração OpenAI (ChatGPT)</h2>
            <p style="color:#6b7280;margin-top:-8px;margin-bottom:24px">
                Conecte o ChatGPT ao seu bot e chat do site para respostas inteligentes,
                mensagens personalizadas e reativação de clientes com IA.
            </p>

            <!-- Status card -->
            <?php $ai_ativo = bp_get('openai_ativo','0')==='1'; ?>
            <div style="border:2px solid <?php echo $ai_ativo?'#8b5cf6':'#e5e7eb'; ?>;border-radius:14px;padding:22px;background:<?php echo $ai_ativo?'rgba(139,92,246,.06)':'#fafafa'; ?>;margin-bottom:28px">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <div style="font-size:1.8rem;margin-bottom:4px">🧠</div>
                        <div style="font-weight:800;font-size:1rem">OpenAI / ChatGPT</div>
                        <div style="font-size:.8rem;color:#6b7280;margin-top:2px">Respostas inteligentes e mensagens personalizadas com IA</div>
                    </div>
                    <label class="bp-toggle">
                        <input type="checkbox" name="openai_ativo" value="1" <?php checked($ai_ativo,true); ?>>
                        <span class="bp-toggle-slider" style="<?php echo $ai_ativo?'background:#8b5cf6':''; ?>"></span>
                    </label>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th>🔑 API Key *</th>
                    <td>
                        <input type="password" name="openai_api_key"
                               value="<?php echo esc_attr(bp_get('openai_api_key','')); ?>"
                               class="large-text" placeholder="sk-proj-...">
                        <p class="description">
                            <strong>OpenAI:</strong> <a href="https://platform.openai.com/api-keys" target="_blank">platform.openai.com/api-keys</a> — chave <code>sk-</code> ou <code>sk-proj-</code>.
                        </p>
                        <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
                            <button type="button" onclick="bpTestarOpenAI()" class="button">🧪 Testar conexão</button>
                            <span id="openai_test_result" style="font-size:.88rem"></span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>Modelo</th>
                    <td>
                        <select name="openai_model">
                            <option value="gpt-4o-mini"  <?php selected(bp_get('openai_model','gpt-4o-mini'),'gpt-4o-mini'); ?>>GPT-4o Mini — recomendado ⭐</option>
                            <option value="gpt-4o"       <?php selected(bp_get('openai_model','gpt-4o-mini'),'gpt-4o'); ?>>GPT-4o — OpenAI</option>
                            <option value="gpt-3.5-turbo"<?php selected(bp_get('openai_model','gpt-4o-mini'),'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo — OpenAI</option>
                        </select>
                        <p class="description">Apenas modelos <code>gpt-*</code> da OpenAI. O padrão <strong>GPT-4o mini</strong> oferece bom custo e qualidade para atendimento.</p>
                    </td>
                </tr>
                <tr>
                    <th>Máx. tokens por resposta</th>
                    <td>
                        <input type="number" name="openai_max_tokens" value="<?php echo esc_attr(bp_get('openai_max_tokens','300')); ?>" class="small-text" min="50" max="2000">
                        <span class="description">300 = ~200 palavras. Suficiente para respostas de atendimento.</span>
                    </td>
                </tr>
            </table>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">⚙️ Comportamento</h3>
            <table class="form-table">
                <tr>
                    <th>Responder perguntas livres</th>
                    <td>
                        <label>
                            <input type="checkbox" name="openai_free_response" value="1" <?php checked(bp_get('openai_free_response','0'),'1'); ?>>
                            Usar IA para responder perguntas fora do fluxo de agendamento (ex: "qual o horário?", "vocês fazem barba?")
                        </label>
                        <p class="description">Quando ativado, o bot usa ChatGPT para responder perguntas gerais em vez de mostrar o menu padrão.</p>
                    </td>
                </tr>
                <tr>
                    <th>IA nas mensagens de reativação</th>
                    <td>
                        <label>
                            <input type="checkbox" name="openai_reactivation" value="1" <?php checked(bp_get('openai_reactivation','0'),'1'); ?>>
                            Gerar mensagens de reativação personalizadas com IA (mais naturais que mensagens fixas)
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Horário de funcionamento</th>
                    <td>
                        <input type="text" name="openai_horario_info" value="<?php echo esc_attr(bp_get('openai_horario_info','Segunda a Sábado, das 9h às 18h')); ?>" class="large-text" placeholder="Ex: Seg a Sex das 9h às 19h, Sáb das 9h às 17h">
                        <p class="description">Informação que a IA usará para responder perguntas sobre horários.</p>
                    </td>
                </tr>
            </table>

            <h3 style="border-top:1px solid #e5e7eb;padding-top:20px">✏️ System Prompt (Prompt do Sistema)</h3>
            <div style="background:#fefce8;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.87rem">
                <strong>ℹ️</strong> Define como a IA deve se comportar. Deixe vazio para usar o prompt padrão gerado automaticamente com os dados do seu negócio.<br>
                Variáveis: <code>{negocio}</code> <code>{link_agendamento}</code> <code>{nome_cliente}</code>
            </div>
            <textarea name="openai_system_prompt" rows="8" class="large-text"
                      placeholder="Deixe vazio para usar o prompt padrão automático.&#10;&#10;Exemplo:&#10;Você é o assistente da {negocio}. Seja simpático e profissional. Para agendamentos, direcione para {link_agendamento}."><?php echo esc_textarea(bp_get('openai_system_prompt','')); ?></textarea>

            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;margin-top:20px;font-size:.87rem">
                <strong>💡 Como funciona na prática:</strong><br><br>
                <strong>Bot WhatsApp:</strong> quando o cliente manda mensagem não reconhecida (ex: "vocês trabalham com barba?"),
                em vez de mostrar o menu genérico, a IA responde de forma natural.<br><br>
                <strong>Chat do Site:</strong> respostas mais humanas para perguntas gerais antes de entrar no fluxo de agendamento.<br><br>
                <strong>Reativação:</strong> a IA gera mensagens únicas e personalizadas para cada cliente inativo, com o nome e último serviço.<br><br>
                <strong>Custo estimado:</strong> GPT-4o Mini custa ~$0.00015 por mensagem — praticamente gratuito para volumes normais de barbearia.
            </div>

            <script>
            function bpTestarOpenAI() {
                var key    = document.querySelector('[name="openai_api_key"]').value.trim();
                var result = document.getElementById('openai_test_result');
                if (!key) { result.textContent='⚠️ Insira a API Key antes.'; result.style.color='#d97706'; return; }
                result.textContent='⏳ Testando...'; result.style.color='#6b7280';
                var fd = new FormData();
                fd.append('action','bp_testar_openai');
                fd.append('nonce','<?php echo wp_create_nonce("barberpro_ajax"); ?>');
                fd.append('api_key', key);
                fetch(ajaxurl, {method:'POST',credentials:'same-origin',body:fd})
                    .then(r=>r.json())
                    .then(data=>{
                        if(data.success){ result.textContent='✅ '+data.data.message; result.style.color='#16a34a'; }
                        else { result.textContent='❌ '+(data.data?.message||'Falha'); result.style.color='#dc2626'; }
                    }).catch(()=>{ result.textContent='❌ Erro na requisição'; result.style.color='#dc2626'; });
            }
            </script>
        </div>

        <!-- Sticky Save Bar -->
        <div class="bp-sticky-save">
            <span class="bp-save-hint">💡 As alterações só são aplicadas após salvar</span>
            <input type="submit" name="submit" class="button button-primary" value="💾 Salvar Configurações">
        </div>
    </form>
</div>

<style>
/* ── Cards de mensagem ──────────────────────────────────── */
.bp-msg-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 20px 24px;
    margin-bottom: 20px;
    box-shadow: 0 1px 6px rgba(0,0,0,.04);
}
.bp-msg-header {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    margin-bottom: 14px;
}
.bp-msg-icon { font-size: 1.8rem; flex-shrink: 0; }
.bp-msg-header > div { flex: 1; }
.bp-msg-header h3 { margin: 0 0 3px; font-size: 1rem; }
.bp-msg-header p  { margin: 0; color: #6b7280; font-size: .85rem; }

/* Timing row */
.bp-msg-timing {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f8f9fa;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 12px;
    flex-wrap: wrap;
    font-size: .9rem;
}
.bp-msg-timing input[type="number"] {
    width: 72px;
    padding: 5px 8px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    text-align: center;
    font-size: .9rem;
}
.bp-msg-timing input[type="time"] {
    padding: 5px 8px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    font-size: .9rem;
}
.bp-timing-tip { color: #3b82f6; font-size: .8rem; font-style: italic; }

.bp-msg-text { font-size: .88rem !important; border-radius: 8px !important; }

/* Toggle switch */
.bp-toggle { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
.bp-toggle input { opacity: 0; width: 0; height: 0; }
.bp-toggle-slider {
    position: absolute; inset: 0;
    background: #d1d5db; border-radius: 24px; cursor: pointer;
    transition: background .2s;
}
.bp-toggle-slider::before {
    content: ''; position: absolute;
    width: 18px; height: 18px;
    left: 3px; top: 3px;
    background: #fff; border-radius: 50%;
    transition: transform .2s;
    box-shadow: 0 1px 4px rgba(0,0,0,.2);
}
.bp-toggle input:checked + .bp-toggle-slider { background: #10b981; }
.bp-toggle input:checked + .bp-toggle-slider::before { transform: translateX(20px); }

/* Tabs */
.barberpro-tab-content { background: #fff; border: 1px solid #e0e0e0; border-top: none; padding: 24px; border-radius: 0 0 8px 8px; }
</style>

<script>
function showWaFields(val) {
    document.querySelectorAll('.wa-fields').forEach(function(el){ el.style.display = 'none'; });
    var el = document.getElementById('wa-' + val);
    if (el) el.style.display = '';
}
// Init on load
showWaFields(document.getElementById('waProvider').value);

function bpTestarWapi() {
    var instance = document.querySelector('[name="wapi_instance"]').value.trim();
    var token    = document.querySelector('[name="wapi_token"]').value.trim();
    var result   = document.getElementById('wapi_test_result');
    if (!instance || !token) { result.textContent = '⚠️ Preencha Instance ID e Token antes de testar.'; result.style.color='#d97706'; return; }
    result.textContent = '⏳ Testando...'; result.style.color='#6b7280';
    fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({ action: 'bp_testar_wapi', nonce: '<?php echo wp_create_nonce("bp_testar_wapi"); ?>', instance: instance, token: token })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) { result.textContent = '✅ Conectado!'; result.style.color='#16a34a'; }
        else { result.textContent = '❌ ' + (data.data?.message || 'Falha na conexão'); result.style.color='#dc2626'; }
    })
    .catch(() => { result.textContent = '❌ Erro na requisição'; result.style.color='#dc2626'; });
}

// Tab switching
// Restore active tab after save
(function() {
    var saved = new URLSearchParams(window.location.search).get('tab')
                || document.getElementById('bp_active_tab_input').value;
    if (saved) {
        var el = document.querySelector('.nav-tab[href="#' + saved + '"]');
        var content = document.getElementById(saved);
        if (el && content) {
            document.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
            document.querySelectorAll('.barberpro-tab-content').forEach(function(c){ c.style.display = 'none'; });
            el.classList.add('nav-tab-active');
            content.style.display = '';
        }
    }
})();

document.querySelectorAll('.nav-tab-wrapper .nav-tab').forEach(function(tab) {
    tab.addEventListener('click', function(e) {
        e.preventDefault();
        document.querySelectorAll('.nav-tab').forEach(function(t){ t.classList.remove('nav-tab-active'); });
        document.querySelectorAll('.barberpro-tab-content').forEach(function(c){ c.style.display = 'none'; });
        this.classList.add('nav-tab-active');
        // Save active tab to hidden field and URL
        var tgt = this.getAttribute('href').replace('#','');
        document.getElementById('bp_active_tab_input').value = tgt;
        history.replaceState(null, '', '?page=barberpro_settings&tab=' + tgt);
        var target = this.getAttribute('href').replace('#', '');
        var el = document.getElementById(target);
        if (el) el.style.display = '';
    });
});
</script>
