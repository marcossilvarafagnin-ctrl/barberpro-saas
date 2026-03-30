<?php
/**
 * BarberPro App – Seção: Carteira de Clientes
 */
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Sections_Clients {

    // ── Listagem de clientes ──────────────────────────────────
    private function section_clientes( int $company_id ): void {
        $mod      = $company_id === 1 ? 'barbearia' : ( $company_id === 2 ? 'lavacar' : 'bar' );
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $tipo_f   = sanitize_key($_POST['tipo'] ?? '');
        $clientes = BarberPro_Clients::list($company_id, $search, $tipo_f);
        $pros     = BarberPro_Database::get_professionals($company_id);
        ?>
        <div class="bp-page-header bp-animate-in">
            <div>
                <div class="bp-page-title">👥 Carteira de Clientes</div>
                <div class="bp-page-subtitle"><?php echo count($clientes); ?> clientes cadastrados</div>
            </div>
            <button class="bp-btn bp-btn-primary bp-btn-sm" onclick="bpOpenClientForm(0,<?php echo $company_id; ?>)">
                + Novo Cliente
            </button>
        </div>

        <!-- Filtros -->
        <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap" class="bp-animate-in">
            <input type="text" id="bpClientSearch" placeholder="🔍 Buscar nome, telefone..."
                   value="<?php echo esc_attr($search); ?>"
                   style="flex:1;min-width:180px;padding:7px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.85rem"
                   oninput="bpClientFilter()">
            <select id="bpClientTipo" onchange="bpClientFilter()"
                    style="padding:7px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.85rem">
                <option value="">Todos os tipos</option>
                <option value="normal"     <?php selected($tipo_f,'normal'); ?>>👤 Normal</option>
                <option value="vip"        <?php selected($tipo_f,'vip'); ?>>⭐ VIP</option>
                <option value="recorrente" <?php selected($tipo_f,'recorrente'); ?>>🔁 Recorrente</option>
            </select>
        </div>

        <?php
        $abs_on   = BarberPro_Database::get_setting( 'absence_reminder_active', '0' ) === '1';
        $abs_days = (int) BarberPro_Database::get_setting( 'absence_reminder_days', '30' );
        $abs_msg  = BarberPro_Database::get_setting( 'absence_reminder_msg', 'Olá, {nome}! Sentimos sua falta na {negocio} 💈 Que tal agendar? {link}' );
        $mod_lbl  = $company_id === 1 ? 'Barbearia' : ( $company_id === 2 ? 'Lava-Car' : 'Bar / Eventos' );
        ?>
        <div class="bp-card bp-animate-in" style="margin-bottom:14px;padding:14px;border:1px solid var(--border);border-radius:12px">
            <div style="font-weight:800;margin-bottom:6px">📳 Mensagem de ausência (WhatsApp / W-API) — <?php echo esc_html( $mod_lbl ); ?></div>
            <p style="font-size:.76rem;color:var(--text3);margin:0 0 10px">Disparo diário: clientes na carteira sem agendamento há X dias. Máx. 1 envio a cada 14 dias por cliente. Variáveis: <code>{nome}</code> <code>{negocio}</code> <code>{link}</code></p>
            <label style="font-size:.85rem"><input type="checkbox" id="bpAbsActive" value="1" <?php checked( $abs_on ); ?>> Ativar</label>
            <label style="font-size:.85rem;margin-left:12px">Dias sem agendar: <input type="number" id="bpAbsDays" value="<?php echo esc_attr( (string) $abs_days ); ?>" min="7" max="365" style="width:64px;padding:4px 8px;border-radius:6px;border:1px solid var(--border);background:var(--bg2);color:var(--text1)"></label>
            <textarea id="bpAbsMsg" rows="2" style="width:100%;margin-top:8px;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.85rem"><?php echo esc_textarea( $abs_msg ); ?></textarea>
            <button type="button" class="bp-btn bp-btn-primary bp-btn-sm" style="margin-top:8px" onclick="bpSaveAbsence()">Salvar configuração de ausência</button>
        </div>

        <div class="bp-card bp-animate-in" style="margin-bottom:14px;padding:14px;border:1px solid var(--border);border-radius:12px">
            <div style="font-weight:800;margin-bottom:6px">📢 Envio em massa (carteira — <?php echo esc_html( $mod_lbl ); ?>)</div>
            <p style="font-size:.76rem;color:var(--text3);margin:0 0 8px">Mensagem para todos os clientes listados abaixo. Delay entre envios (aquecimento de número). Limite 80 por rodada.</p>
            <textarea id="bpBulkMsg" rows="3" placeholder="Olá {nome}! ..." style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.85rem"></textarea>
            <div style="display:flex;gap:10px;align-items:center;margin-top:8px;flex-wrap:wrap">
                <label style="font-size:.8rem">Delay (s) <input type="number" id="bpBulkDelay" value="4" min="0" max="120" style="width:56px;padding:4px;border-radius:6px;border:1px solid var(--border)"></label>
                <button type="button" class="bp-btn bp-btn-primary bp-btn-sm" onclick="bpBulkWhatsapp()">Enviar para carteira</button>
            </div>
        </div>

        <?php if ( $mod === 'bar' ) : ?>
        <p style="font-size:.72rem;color:var(--text3);margin:0 0 10px"><strong>Bar / Eventos:</strong> a carteira é independente das comandas. Use ausência e envio em massa para fidelizar quem você cadastra aqui (ex.: após eventos ou reservas).</p>
        <?php else : ?>
        <p style="font-size:.72rem;color:var(--text3);margin:0 0 10px"><strong>Loja Virtual:</strong> o disparo usa só quem está nesta carteira (<?php echo esc_html( $mod_lbl ); ?>). Clientes que compraram só pela loja entram na lista se também estiverem cadastrados aqui.</p>
        <?php endif; ?>

        <!-- Lista -->
        <div id="bpClientList" class="bp-animate-in">
        <?php if (empty($clientes)): ?>
            <div style="text-align:center;padding:40px;color:var(--text3)">
                <div style="font-size:2.5rem;margin-bottom:10px">👥</div>
                <div>Nenhum cliente cadastrado ainda.</div>
                <div style="font-size:.8rem;margin-top:6px"><?php echo $mod === 'bar' ? 'Cadastre manualmente ou importe contatos para usar ausência e envio em massa.' : 'Os clientes são criados automaticamente ao agendar pelo chat ou site.'; ?></div>
            </div>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px">
        <?php foreach ($clientes as $c):
            $color  = BarberPro_Clients::tipo_color($c->tipo);
            $label  = BarberPro_Clients::tipo_label($c->tipo);
            $visits = (int)$c->total_visits;
            $last   = $c->last_visit ? date_i18n('d/m/Y', strtotime($c->last_visit)) : '—';
            $next   = $c->next_reminder ? date_i18n('d/m/Y', strtotime($c->next_reminder)) : null;
        ?>
        <div class="bp-client-card" data-name="<?php echo esc_attr(mb_strtolower($c->name)); ?>"
             data-phone="<?php echo esc_attr($c->phone); ?>"
             data-tipo="<?php echo esc_attr($c->tipo); ?>"
             style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px;position:relative">

            <!-- Badge tipo -->
            <span style="position:absolute;top:12px;right:12px;font-size:.7rem;font-weight:700;color:<?php echo $color; ?>;background:<?php echo $color; ?>22;border:1px solid <?php echo $color; ?>44;border-radius:20px;padding:2px 8px">
                <?php echo $label; ?>
            </span>

            <div style="font-weight:700;font-size:.95rem;padding-right:80px"><?php echo esc_html($c->name); ?></div>
            <div style="font-size:.78rem;color:var(--text3);margin-top:2px">📱 <?php echo esc_html($c->phone); ?></div>
            <?php if ($c->email): ?><div style="font-size:.75rem;color:var(--text3)">✉️ <?php echo esc_html($c->email); ?></div><?php endif; ?>

            <div style="display:flex;gap:12px;margin-top:10px;font-size:.78rem;color:var(--text2)">
                <span title="Visitas">🔢 <?php echo $visits; ?> <?php echo $visits === 1 ? 'visita' : 'visitas'; ?></span>
                <span title="Última visita">📅 <?php echo $last; ?></span>
            </div>

            <?php if ($c->pro_name): ?>
            <div style="font-size:.75rem;color:var(--text3);margin-top:4px">👤 Prof. fixo: <strong><?php echo esc_html($c->pro_name); ?></strong></div>
            <?php endif; ?>

            <?php if ($next): ?>
            <div style="font-size:.74rem;background:rgba(59,130,246,.1);color:#3b82f6;border:1px solid rgba(59,130,246,.2);border-radius:6px;padding:4px 8px;margin-top:8px">
                🔔 Lembrete programado: <?php echo $next; ?>
            </div>
            <?php endif; ?>

            <?php if ($c->notes): ?>
            <div style="font-size:.75rem;color:var(--text3);margin-top:6px;font-style:italic">"<?php echo esc_html(mb_substr($c->notes,0,80)); ?>"</div>
            <?php endif; ?>

            <!-- Ações -->
            <div style="display:flex;gap:6px;margin-top:12px">
                <button class="bp-btn bp-btn-ghost bp-btn-sm" style="flex:1"
                        onclick="bpOpenClientForm(<?php echo $c->id; ?>,<?php echo $company_id; ?>)">✏️ Editar</button>
                <?php if ($c->phone): ?>
                <a href="https://wa.me/55<?php echo preg_replace('/\D/','',$c->phone); ?>" target="_blank" rel="noopener"
                   class="bp-btn bp-btn-ghost bp-btn-sm" style="background:rgba(37,211,102,.12);color:#25d366;border-color:rgba(37,211,102,.3)">
                   <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>

        <!-- Modal form -->
        <div id="bpClientModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9000;align-items:center;justify-content:center;padding:16px">
            <div style="background:var(--bg1);border-radius:16px;padding:24px;width:100%;max-width:440px;max-height:90vh;overflow-y:auto">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px">
                    <div style="font-size:1.05rem;font-weight:700" id="bpClientModalTitle">Cliente</div>
                    <button onclick="bpCloseClientModal()" style="background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text3)">✕</button>
                </div>
                <input type="hidden" id="bpCId" value="">
                <input type="hidden" id="bpCCid" value="<?php echo $company_id; ?>">

                <div class="bp-field" style="margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Nome *</label>
                    <input type="text" id="bpCName" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px">
                </div>
                <div class="bp-field" style="margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Telefone *</label>
                    <input type="tel" id="bpCPhone" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px" placeholder="(44) 99999-9999">
                </div>
                <div class="bp-field" style="margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">E-mail</label>
                    <input type="email" id="bpCEmail" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px">
                </div>

                <div class="bp-field" style="margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Tipo de Cliente</label>
                    <select id="bpCTipo" onchange="bpClientTipoChange()" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px">
                        <option value="normal">👤 Normal</option>
                        <option value="vip">⭐ VIP</option>
                        <option value="recorrente">🔁 Recorrente</option>
                    </select>
                </div>

                <div id="bpCRecRow" style="display:none;margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Intervalo de retorno (dias corridos)</label>
                    <input type="number" id="bpCDias" min="1" max="365" value="30"
                           style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px">
                    <div style="font-size:.73rem;color:var(--text3);margin-top:4px">Lembrete após N dias da última visita (se não usar dias da semana abaixo).</div>
                </div>
                <div id="bpCWeekRow" style="display:none;margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Dias da semana (lembrete recorrente)</label>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px">
                        <?php
                        $wd_lbl = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                        for ( $w = 0; $w <= 6; $w++ ) :
                        ?>
                        <label style="font-size:.78rem;display:flex;align-items:center;gap:4px;cursor:pointer">
                            <input type="checkbox" class="bpCWD" value="<?php echo (int) $w; ?>"> <?php echo esc_html( $wd_lbl[ $w ] ); ?>
                        </label>
                        <?php endfor; ?>
                    </div>
                    <div style="font-size:.73rem;color:var(--text3);margin-top:4px">Opcional: o próximo lembrete cai no primeiro desses dias após a última visita.</div>
                </div>

                <div class="bp-field" style="margin-bottom:12px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Profissional fixo</label>
                    <select id="bpCPro" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px">
                        <option value="">— Nenhum —</option>
                        <?php foreach ($pros as $pr): ?>
                        <option value="<?php echo $pr->id; ?>"><?php echo esc_html($pr->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="bp-field" style="margin-bottom:18px">
                    <label style="font-size:.78rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.5px">Observações</label>
                    <textarea id="bpCNotes" rows="3" style="width:100%;padding:9px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text1);font-size:.9rem;margin-top:4px;resize:vertical"></textarea>
                </div>

                <button onclick="bpSaveClient()" class="bp-btn bp-btn-primary" style="width:100%;justify-content:center">
                    ✅ Salvar Cliente
                </button>
            </div>
        </div>

        <script>
        var _bpClients = <?php echo json_encode(array_map(function($c) {
            return [
                'id'=>(int)$c->id,'name'=>$c->name,'phone'=>$c->phone,'email'=>$c->email??'',
                'tipo'=>$c->tipo,'recorrencia_dias'=>(int)($c->recorrencia_dias??0),
                'recurrence_weekdays'=>$c->recurrence_weekdays??'',
                'professional_id'=>(int)($c->professional_id??0),'notes'=>$c->notes??''
            ];
        }, $clientes)); ?>;

        function bpClientFilter() {
            var q    = document.getElementById('bpClientSearch').value.toLowerCase();
            var tipo = document.getElementById('bpClientTipo').value;
            document.querySelectorAll('.bp-client-card').forEach(function(el) {
                var matchQ = !q || el.dataset.name.includes(q) || el.dataset.phone.includes(q);
                var matchT = !tipo || el.dataset.tipo === tipo;
                el.style.display = (matchQ && matchT) ? '' : 'none';
            });
        }

        function bpOpenClientForm(id, cid) {
            document.getElementById('bpCId').value    = id || 0;
            document.getElementById('bpCCid').value   = cid;
            document.getElementById('bpCName').value  = '';
            document.getElementById('bpCPhone').value = '';
            document.getElementById('bpCEmail').value = '';
            document.getElementById('bpCTipo').value  = 'normal';
            document.getElementById('bpCDias').value  = 30;
            document.getElementById('bpCPro').value   = '';
            document.getElementById('bpCNotes').value = '';
            document.getElementById('bpCRecRow').style.display = 'none';
            document.getElementById('bpCWeekRow').style.display = 'none';
            document.querySelectorAll('.bpCWD').forEach(function(ch){ ch.checked = false; });
            document.getElementById('bpClientModalTitle').textContent = id ? 'Editar Cliente' : 'Novo Cliente';

            if (id) {
                var c = _bpClients.find(function(x){ return x.id === id; });
                if (c) {
                    document.getElementById('bpCName').value  = c.name;
                    document.getElementById('bpCPhone').value = c.phone;
                    document.getElementById('bpCEmail').value = c.email;
                    document.getElementById('bpCTipo').value  = c.tipo;
                    document.getElementById('bpCDias').value  = c.recorrencia_dias || 30;
                    document.getElementById('bpCPro').value   = c.professional_id || '';
                    document.getElementById('bpCNotes').value = c.notes;
                    if (c.tipo === 'recorrente') {
                        document.getElementById('bpCRecRow').style.display = '';
                        document.getElementById('bpCWeekRow').style.display = '';
                    }
                    if (c.recurrence_weekdays) {
                        String(c.recurrence_weekdays).split(',').forEach(function(d) {
                            document.querySelectorAll('.bpCWD').forEach(function(ch) {
                                if (ch.value === String(parseInt(d,10))) ch.checked = true;
                            });
                        });
                    }
                }
            }
            document.getElementById('bpClientModal').style.display = 'flex';
        }

        function bpCloseClientModal() {
            document.getElementById('bpClientModal').style.display = 'none';
        }

        function bpClientTipoChange() {
            var v = document.getElementById('bpCTipo').value;
            var on = v === 'recorrente';
            document.getElementById('bpCRecRow').style.display = on ? '' : 'none';
            document.getElementById('bpCWeekRow').style.display = on ? '' : 'none';
        }

        function bpRecWeekdaysCsv() {
            var a = [];
            document.querySelectorAll('.bpCWD:checked').forEach(function(ch){ a.push(ch.value); });
            return a.join(',');
        }

        function bpSaveAbsence() {
            BP.ajax('bp_app_action', {
                sub: 'save_absence_settings',
                absence_active: document.getElementById('bpAbsActive').checked ? '1' : '',
                absence_days: document.getElementById('bpAbsDays').value,
                absence_msg: document.getElementById('bpAbsMsg').value
            }).then(function(r) {
                if (r.success) BP.toast('Configuração de ausência salva!');
                else BP.toast(r.data?.message || 'Erro', 'error');
            });
        }

        function bpBulkWhatsapp() {
            var msg = document.getElementById('bpBulkMsg').value.trim();
            if (!msg) { BP.toast('Digite a mensagem.', 'error'); return; }
            if (!confirm('Enviar para todos os clientes da carteira deste módulo?')) return;
            BP.ajax('bp_app_action', {
                sub: 'client_bulk_whatsapp',
                company_id: document.getElementById('bpCCid').value,
                message: msg,
                delay_seconds: document.getElementById('bpBulkDelay').value
            }).then(function(r) {
                if (r.success) BP.toast('Enviados: ' + (r.data?.sent ?? 0));
                else BP.toast(r.data?.message || 'Erro', 'error');
            });
        }

        function bpSaveClient() {
            var name  = document.getElementById('bpCName').value.trim();
            var phone = document.getElementById('bpCPhone').value.trim();
            if (!name || !phone) { BP.toast('Nome e telefone são obrigatórios.','error'); return; }

            BP.ajax('bp_app_action', {
                sub:              'save_client',
                id:               document.getElementById('bpCId').value,
                company_id:       document.getElementById('bpCCid').value,
                name:             name,
                phone:            phone,
                email:            document.getElementById('bpCEmail').value,
                tipo:             document.getElementById('bpCTipo').value,
                recorrencia_dias: document.getElementById('bpCDias').value,
                recurrence_weekdays: bpRecWeekdaysCsv(),
                professional_id:  document.getElementById('bpCPro').value,
                notes:            document.getElementById('bpCNotes').value,
            }).then(function(r) {
                if (r.success) {
                    bpCloseClientModal();
                    BP.toast('Cliente salvo!');
                    var cid = parseInt(document.getElementById('bpCCid').value, 10);
                    var prefix = cid === 1 ? 'barbearia' : (cid === 2 ? 'lavacar' : 'bar');
                    BP.navigate(prefix+'_clientes');
                } else {
                    BP.toast(r.data?.message || 'Erro ao salvar.', 'error');
                }
            });
        }
        </script>
        <?php
    }
}
