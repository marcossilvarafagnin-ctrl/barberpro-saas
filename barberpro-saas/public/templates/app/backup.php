<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="bp-page-header bp-animate-in">
    <div>
        <div class="bp-page-title">💾 Backup do Sistema</div>
        <div class="bp-page-subtitle">Banco de dados · Configurações · Arquivos do plugin</div>
    </div>
</div>

<?php if ( ! $dir_writable ): ?>
<div class="bp-alert bp-alert-error bp-animate-in">
    ⚠️ A pasta <code>wp-content</code> não tem permissão de escrita. Verifique as permissões do servidor (chmod 755).
</div>
<?php endif; ?>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px" class="bp-stagger">
    <div class="bp-kpi-mini">
        <div class="bp-kpi-mini-val" style="color:var(--blue)"><?php echo count($backups); ?></div>
        <div class="bp-kpi-mini-lbl">Backups Salvos</div>
    </div>
    <div class="bp-kpi-mini">
        <div class="bp-kpi-mini-val" style="color:var(--green);font-size:.9rem">
            <?php echo $last_auto ? date_i18n('d/m/Y H:i', strtotime($last_auto)) : '—'; ?>
        </div>
        <div class="bp-kpi-mini-lbl">Último Auto</div>
    </div>
    <div class="bp-kpi-mini">
        <div class="bp-kpi-mini-val" style="color:var(--accent);font-size:.9rem">
            <?php echo $next_cron ? date_i18n('d/m/Y H:i', $next_cron) : '—'; ?>
        </div>
        <div class="bp-kpi-mini-lbl">Próximo Auto</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:340px 1fr;gap:16px;align-items:start" class="bp-animate-in">

    <!-- Coluna esquerda -->
    <div>

        <!-- Backup Manual -->
        <div class="bp-card" style="margin-bottom:14px">
            <div class="bp-card-header">
                <div class="bp-card-title">⚡ Backup Manual</div>
            </div>
            <p style="font-size:.82rem;color:var(--text3);margin-bottom:14px;line-height:1.5">
                Gera um arquivo JSON com todos os dados e salva ou baixa no seu computador.
            </p>
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
                <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer">
                    <input type="checkbox" id="bpBkDb" checked style="accent-color:var(--accent)">
                    🗄️ Banco de dados (agendamentos, comandas, financeiro)
                </label>
                <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer">
                    <input type="checkbox" id="bpBkSettings" checked style="accent-color:var(--accent)">
                    ⚙️ Configurações do plugin
                </label>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px">
                <button class="bp-btn bp-btn-primary" style="width:100%;justify-content:center" onclick="bpBackupExportar()">
                    📥 Exportar e Baixar JSON
                </button>
                <button class="bp-btn bp-btn-secondary" style="width:100%;justify-content:center" onclick="bpBackupSalvarServidor()">
                    💾 Salvar no Servidor
                </button>
                <?php if ( $zip_ok ): ?>
                <button class="bp-btn bp-btn-ghost" style="width:100%;justify-content:center" onclick="bpBackupPlugin()">
                    📦 Backup dos Arquivos (ZIP)
                </button>
                <?php else: ?>
                <div style="font-size:.74rem;color:var(--text3);text-align:center;padding:6px 0">
                    ℹ️ ZipArchive não disponível no servidor — backup de arquivos desativado
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Restaurar -->
        <div class="bp-card" style="margin-bottom:14px">
            <div class="bp-card-header">
                <div class="bp-card-title">🔄 Restaurar Backup</div>
            </div>
            <p style="font-size:.82rem;color:var(--text3);margin-bottom:12px;line-height:1.5">
                Selecione um arquivo <strong>.json</strong> exportado anteriormente. Os dados serão <strong style="color:var(--red)">substituídos</strong>.
            </p>
            <input type="file" id="bpBkFile" accept=".json" style="display:none" onchange="bpBackupArquivoSelecionado(this)">
            <div id="bpBkFileLabel"
                 style="border:2px dashed var(--border);border-radius:10px;padding:16px;text-align:center;cursor:pointer;font-size:.84rem;color:var(--text3);margin-bottom:10px;transition:border-color .2s"
                 onmouseover="this.style.borderColor='var(--accent)'"
                 onmouseout="this.style.borderColor='var(--border)'"
                 onclick="document.getElementById('bpBkFile').click()">
                📂 Clique para selecionar o arquivo JSON
            </div>
            <div id="bpBkFileInfo" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:.81rem;margin-bottom:10px;line-height:1.6"></div>
            <button class="bp-btn bp-btn-danger" id="bpBkRestoreBtn" style="width:100%;justify-content:center;display:none" onclick="bpBackupRestaurar()">
                ⚠️ Restaurar (substituirá os dados atuais)
            </button>
        </div>

        <!-- Backup Automático -->
        <div class="bp-card">
            <div class="bp-card-header">
                <div class="bp-card-title">🕐 Backup Automático</div>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem">
                    <input type="checkbox" id="bpBkAutoEnabled"
                           <?php checked( $schedule['enabled'] ?? false ); ?>
                           style="accent-color:var(--accent)"
                           onchange="bpBackupSalvarAuto()">
                    Ativado
                </label>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px">
                <div class="bp-field">
                    <label style="font-size:.78rem">Frequência</label>
                    <select id="bpBkFreq" onchange="bpBackupSalvarAuto()"
                            style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%;font-size:.85rem">
                        <option value="daily"   <?php selected( $schedule['frequency'] ?? 'daily', 'daily' ); ?>>Diário</option>
                        <option value="weekly"  <?php selected( $schedule['frequency'] ?? '', 'weekly' ); ?>>Semanal</option>
                        <option value="monthly" <?php selected( $schedule['frequency'] ?? '', 'monthly' ); ?>>Mensal</option>
                    </select>
                </div>
                <div class="bp-field">
                    <label style="font-size:.78rem">Manter últimos</label>
                    <select id="bpBkKeep" onchange="bpBackupSalvarAuto()"
                            style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%;font-size:.85rem">
                        <?php foreach ( [3,5,7,10,14,30] as $n ): ?>
                        <option value="<?php echo $n; ?>" <?php selected( (int)($schedule['keep'] ?? 7), $n ); ?>><?php echo $n; ?> backups</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;flex-direction:column;gap:7px">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.83rem;cursor:pointer">
                        <input type="checkbox" id="bpBkAutoDb" <?php checked( $schedule['include_db'] ?? true ); ?> onchange="bpBackupSalvarAuto()" style="accent-color:var(--accent)">
                        Incluir banco de dados
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:.83rem;cursor:pointer">
                        <input type="checkbox" id="bpBkAutoSettings" <?php checked( $schedule['include_settings'] ?? true ); ?> onchange="bpBackupSalvarAuto()" style="accent-color:var(--accent)">
                        Incluir configurações
                    </label>
                </div>
                <div id="bpBkAutoStatus" style="font-size:.75rem;color:var(--text3);padding-top:4px;line-height:1.7;border-top:1px solid var(--border);margin-top:4px">
                    <?php if ( ! empty( $schedule['enabled'] ) ): ?>
                        ✅ Ativo
                        · <?php echo $schedule['frequency'] === 'daily' ? 'Diário' : ($schedule['frequency'] === 'weekly' ? 'Semanal' : 'Mensal'); ?>
                        · Guarda <?php echo (int)($schedule['keep'] ?? 7); ?> backups
                        <?php if ( $next_cron ): ?> · Próximo: <?php echo date_i18n('d/m H:i', $next_cron); ?><?php endif; ?>
                    <?php else: ?>
                        ⏸ Desativado — configure a frequência e ative
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /coluna esquerda -->

    <!-- Coluna direita: lista de backups -->
    <div class="bp-card">
        <div class="bp-card-header">
            <div class="bp-card-title">📋 Backups Salvos no Servidor</div>
            <button class="bp-btn bp-btn-ghost bp-btn-sm" onclick="BP.navigate('backup')" title="Atualizar">🔄</button>
        </div>

        <?php if ( empty($backups) ): ?>
        <div class="bp-empty" style="padding:40px">
            <div class="bp-empty-icon">💾</div>
            <div class="bp-empty-title">Nenhum backup salvo ainda</div>
            <div class="bp-empty-text">Clique em "Salvar no Servidor" ou ative o backup automático</div>
        </div>
        <?php else: ?>
        <div class="bp-table-wrap">
        <table class="bp-table">
            <thead>
                <tr>
                    <th>Arquivo</th>
                    <th>Tipo</th>
                    <th>Data</th>
                    <th>Tamanho</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $backups as $b ): ?>
            <tr>
                <td style="font-family:var(--font-mono);font-size:.74rem;color:var(--text2);word-break:break-all">
                    <?php echo esc_html( $b['filename'] ); ?>
                </td>
                <td>
                    <span class="bp-badge bp-badge-<?php echo $type_colors[ $b['type'] ] ?? 'gray'; ?>">
                        <?php echo $type_labels[ $b['type'] ] ?? $b['type']; ?>
                    </span>
                </td>
                <td style="color:var(--text3);font-size:.8rem;white-space:nowrap"><?php echo esc_html( $b['date'] ); ?></td>
                <td style="color:var(--text3);font-size:.8rem;white-space:nowrap"><?php echo esc_html( $b['size_fmt'] ); ?></td>
                <td style="white-space:nowrap">
                    <?php if ( $b['ext'] === 'json' ): ?>
                    <button class="bp-btn bp-btn-ghost bp-btn-sm"
                            onclick="bpBackupDownloadServidor('<?php echo esc_js( $b['filename'] ); ?>')"
                            title="Baixar JSON">📥</button>
                    <?php endif; ?>
                    <?php if ( $b['ext'] === 'zip' ): ?>
                    <button class="bp-btn bp-btn-ghost bp-btn-sm"
                            onclick="bpBackupDownloadZip('<?php echo esc_js( $b['filename'] ); ?>')"
                            title="Baixar ZIP">📦</button>
                    <?php endif; ?>
                    <button class="bp-btn bp-btn-danger bp-btn-sm"
                            onclick="bpBackupExcluir('<?php echo esc_js( $b['filename'] ); ?>')"
                            title="Excluir">🗑</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="padding:10px 0 0;font-size:.74rem;color:var(--text3)">
            📁 Pasta no servidor: <code style="background:var(--bg3);padding:2px 6px;border-radius:4px;font-size:.7rem"><?php echo esc_html( $backup_dir ); ?></code>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /grid -->

<script>
var _bpBkRestoreData = null;

function bpBackupExportar(){
    BP.toast('Gerando backup...');
    BP.ajax('bp_app_action',{
        sub:'backup_export',
        include_db:       document.getElementById('bpBkDb').checked       ?'1':'0',
        include_settings: document.getElementById('bpBkSettings').checked ?'1':'0',
        download:'1',
    }).then(function(r){
        if(r.success){
            var blob = new Blob([JSON.stringify(r.data.json,null,2)],{type:'application/json'});
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href=url; a.download=r.data.filename;
            document.body.appendChild(a); a.click();
            URL.revokeObjectURL(url); a.remove();
            BP.toast('✅ Download: '+r.data.filename);
        } else { BP.toast(r.data?.message||'Erro ao gerar backup','error'); }
    });
}

function bpBackupSalvarServidor(){
    BP.toast('Salvando no servidor...');
    BP.ajax('bp_app_action',{
        sub:'backup_export',
        include_db:       document.getElementById('bpBkDb').checked       ?'1':'0',
        include_settings: document.getElementById('bpBkSettings').checked ?'1':'0',
        save:'1',
    }).then(function(r){
        if(r.success){
            BP.toast('✅ Salvo: '+r.data.filename+' ('+r.data.size+')');
            setTimeout(function(){ BP.navigate('backup'); },1200);
        } else { BP.toast(r.data?.message||'Erro','error'); }
    });
}

function bpBackupPlugin(){
    BP.toast('Compactando arquivos do plugin...');
    BP.ajax('bp_app_action',{sub:'backup_export',plugin_zip:'1'}).then(function(r){
        if(r.success){
            BP.toast('✅ ZIP criado: '+r.data.filename+' ('+r.data.size+')');
            setTimeout(function(){ BP.navigate('backup'); },1200);
        } else { BP.toast(r.data?.message||'Erro ao criar ZIP','error'); }
    });
}

function bpBackupArquivoSelecionado(input){
    var file = input.files[0];
    if(!file) return;
    var reader = new FileReader();
    reader.onload = function(e){
        try {
            var data = JSON.parse(e.target.result);
            _bpBkRestoreData = data;
            var meta = data.meta || {};
            var tables = meta.tables ? Object.entries(meta.tables).map(function([k,v]){ return k+' ('+v+' reg.)'; }).join(', ') : '—';
            document.getElementById('bpBkFileInfo').innerHTML =
                '<strong>📄 '+file.name+'</strong><br>'
                +'Versão: '+(meta.version||'?')+'  ·  Exportado: '+(meta.exported_at||'?')+'<br>'
                +'Site: '+(meta.site_url||'?')+'<br>'
                +'Tabelas: '+tables;
            document.getElementById('bpBkFileInfo').style.display='block';
            document.getElementById('bpBkRestoreBtn').style.display='';
            document.getElementById('bpBkFileLabel').innerHTML='✅ <strong>'+file.name+'</strong> selecionado';
        } catch(err){ BP.toast('Arquivo JSON inválido','error'); _bpBkRestoreData=null; }
    };
    reader.readAsText(file);
}

function bpBackupRestaurar(){
    if(!_bpBkRestoreData){ BP.toast('Selecione um arquivo primeiro','warn'); return; }
    if(!confirm('⚠️ ATENÇÃO\n\nOs dados atuais serão SUBSTITUÍDOS pelos dados do backup selecionado.\n\nEsta ação não pode ser desfeita.\n\nDeseja continuar?')) return;
    BP.toast('Restaurando dados...');
    BP.ajax('bp_app_action',{
        sub:'backup_restore',
        data: JSON.stringify(_bpBkRestoreData),
    }).then(function(r){
        if(r.success){
            var res = r.data.restored||{};
            var resumo = Object.entries(res).map(function([k,v]){ return k+': '+v; }).join(' · ');
            BP.toast('✅ Restaurado com sucesso! '+resumo);
            _bpBkRestoreData=null;
            document.getElementById('bpBkRestoreBtn').style.display='none';
            document.getElementById('bpBkFileInfo').style.display='none';
            document.getElementById('bpBkFileLabel').textContent='📂 Clique para selecionar o arquivo JSON';
            setTimeout(function(){ BP.navigate('backup'); },1500);
        } else { BP.toast(r.data?.message||'Erro na restauração','error'); }
    });
}

var _bpBkAutoTimer=null;
function bpBackupSalvarAuto(){
    clearTimeout(_bpBkAutoTimer);
    _bpBkAutoTimer=setTimeout(function(){
        BP.ajax('bp_app_action',{
            sub:'backup_auto_save',
            enabled:          document.getElementById('bpBkAutoEnabled').checked ?'1':'0',
            frequency:        document.getElementById('bpBkFreq').value,
            keep:             document.getElementById('bpBkKeep').value,
            include_db:       document.getElementById('bpBkAutoDb').checked       ?'1':'0',
            include_settings: document.getElementById('bpBkAutoSettings').checked ?'1':'0',
        }).then(function(r){
            if(r.success){
                BP.toast('✅ Configuração de backup salva!');
                var enabled=document.getElementById('bpBkAutoEnabled').checked;
                var freq=document.getElementById('bpBkFreq');
                var freqTxt=freq.options[freq.selectedIndex].text;
                var keep=document.getElementById('bpBkKeep').value;
                var st=document.getElementById('bpBkAutoStatus');
                if(st) st.textContent = enabled ? '✅ Ativo · '+freqTxt+' · Guarda '+keep+' backups' : '⏸ Desativado — configure a frequência e ative';
            } else { BP.toast('Erro ao salvar configuração','error'); }
        });
    },600);
}

function bpBackupDownloadServidor(filename){
    BP.toast('Carregando arquivo...');
    BP.ajax('bp_app_action',{sub:'backup_export',download_file:filename}).then(function(r){
        if(r.success){
            var blob=new Blob([JSON.stringify(r.data.json,null,2)],{type:'application/json'});
            var url=URL.createObjectURL(blob);
            var a=document.createElement('a');
            a.href=url; a.download=filename;
            document.body.appendChild(a); a.click();
            URL.revokeObjectURL(url); a.remove();
            BP.toast('✅ Download iniciado!');
        } else { BP.toast('Erro ao carregar arquivo','error'); }
    });
}

function bpBackupDownloadZip(filename){
    BP.ajax('bp_app_action',{sub:'backup_export',download_zip:filename}).then(function(r){
        if(r.success && r.data.url){
            var a=document.createElement('a');
            a.href=r.data.url; a.download=filename;
            document.body.appendChild(a); a.click(); a.remove();
            BP.toast('✅ Download do ZIP iniciado!');
        } else { BP.toast('Erro ao baixar ZIP','error'); }
    });
}

function bpBackupExcluir(filename){
    if(!confirm('Excluir o backup "'+filename+'"?\n\nEsta ação não pode ser desfeita.')) return;
    BP.ajax('bp_app_action',{sub:'backup_delete',filename:filename}).then(function(r){
        if(r.success){ BP.toast('Backup excluído'); BP.navigate('backup'); }
        else BP.toast('Erro ao excluir','error');
    });
}
</script>
