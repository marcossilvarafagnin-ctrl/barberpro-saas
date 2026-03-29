<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Sections_System {

    private function section_backup(): void {
        $schedule     = BarberPro_Backup::get_schedule();
        $backups      = BarberPro_Backup::list_backups();
        $last_auto    = get_option( BarberPro_Backup::OPTION_LAST, null );
        $next_cron    = wp_next_scheduled( BarberPro_Backup::CRON_HOOK );
        $zip_ok       = class_exists('ZipArchive');
        $backup_dir   = BarberPro_Backup::backup_dir();
        $dir_writable = is_writable( dirname($backup_dir) );
        $type_labels  = ['manual'=>'Manual','auto'=>'Automático','plugin'=>'Plugin ZIP'];
        $type_colors  = ['manual'=>'blue','auto'=>'green','plugin'=>'amber'];
        // Sem ob_start interno - handle_section já faz o buffer
        include BARBERPRO_PLUGIN_DIR . 'public/templates/app/backup.php';
    }

    private function section_licenca(): void {
        $license_key    = BarberPro_Database::get_setting('license_key','');
        $license_status = BarberPro_Database::get_setting('license_status','inactive');
        $license_data   = json_decode(BarberPro_Database::get_setting('license_data','{}'),true) ?: [];
        $status_map     = ['active'=>['Ativa','green'],'inactive'=>['Inativa','gray'],'invalid'=>['Inválida','red'],'expired'=>['Expirada','red']];
        [$slabel,$scls] = $status_map[$license_status] ?? ['Desconhecido','gray'];
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">🔑 Licença</div>
        </div>
        <div class="bp-card bp-animate-in" style="max-width:560px">
            <div style="text-align:center;padding:20px 0 28px">
                <div style="font-size:3rem;margin-bottom:12px">🔑</div>
                <div style="font-size:1.1rem;font-weight:700;margin-bottom:8px">Status da Licença</div>
                <span class="bp-badge bp-badge-<?php echo $scls; ?>" style="font-size:.9rem;padding:6px 18px"><?php echo esc_html($slabel); ?></span>
                <?php if(!empty($license_data['plan'])): ?>
                <div style="margin-top:12px;color:var(--text2);font-size:.84rem">Plano: <strong><?php echo esc_html($license_data['plan']); ?></strong></div>
                <?php endif; ?>
                <?php if(!empty($license_data['expires_at'])): ?>
                <div style="color:var(--text3);font-size:.8rem">Expira em: <?php echo date_i18n('d/m/Y',strtotime($license_data['expires_at'])); ?></div>
                <?php endif; ?>
            </div>
            <div class="bp-field">
                <label>Chave de Licença</label>
                <input type="text" id="bpLicenseKey" value="<?php echo esc_attr($license_key); ?>" placeholder="BP2-XXXX..." style="font-family:var(--font-mono)">
            </div>
            <button class="bp-btn bp-btn-primary" onclick="bpActivateLicense()">✅ Ativar Licença</button>
        </div>
        <?php
    }

    // ── Settings ──────────────────────────────────────────────────
    private function section_settings(): void {
        $site_name    = BarberPro_Database::get_setting('business_name', get_bloginfo('name'));
        $barber_name  = BarberPro_Database::get_setting('module_barbearia_name','Barbearia');
        $lavacar_name = BarberPro_Database::get_setting('module_lavacar_name','Lava-Car');
        $printer_name = BarberPro_Database::get_setting('printer_name','');
        $printer_width= BarberPro_Database::get_setting('printer_width','58');
        $printer_copies=(int)BarberPro_Database::get_setting('printer_copies','1');
        $printer_header=BarberPro_Database::get_setting('printer_header','');
        $printer_footer=BarberPro_Database::get_setting('printer_footer','Obrigado pela preferência!');
        $printer_enabled=(bool)(int)BarberPro_Database::get_setting('printer_enabled','0');
        ?>
        <div class="bp-page-header bp-animate-in">
            <div class="bp-page-title">⚙️ Configurações</div>
        </div>

        <div class="bp-card bp-animate-in" style="max-width:600px;margin-bottom:16px">
            <div class="bp-card-header"><div class="bp-card-title">🏢 Informações do Negócio</div></div>
            <div class="bp-field"><label>Nome do Negócio</label><input type="text" id="cfg_business_name" value="<?php echo esc_attr($site_name); ?>"></div>
            <div class="bp-field"><label>Nome do Módulo Barbearia</label><input type="text" id="cfg_barber_name" value="<?php echo esc_attr($barber_name); ?>"></div>
            <div class="bp-field"><label>Nome do Módulo Lava-Car</label><input type="text" id="cfg_lavacar_name" value="<?php echo esc_attr($lavacar_name); ?>"></div>
            <button class="bp-btn bp-btn-primary" onclick="bpSaveSettings()">💾 Salvar</button>
        </div>

        <div class="bp-card bp-animate-in" style="max-width:600px">
            <div class="bp-card-header">
                <div class="bp-card-title">🖨️ Impressora Térmica (QZ Tray)</div>
                <div style="display:flex;align-items:center;gap:8px;cursor:pointer" onclick="bpPrinterToggle()">
                    <div id="printerToggleWrap" style="width:40px;height:22px;background:<?php echo $printer_enabled?'var(--accent)':'var(--border)'; ?>;border-radius:11px;position:relative;transition:.2s">
                        <div id="printerToggleThumb" style="width:16px;height:16px;background:#fff;border-radius:50%;position:absolute;top:3px;left:<?php echo $printer_enabled?'21px':'3px'; ?>;transition:.2s"></div>
                    </div>
                    <span id="printerToggleTxt" style="font-size:.84rem;color:var(--text2)"><?php echo $printer_enabled?'Ativo':'Inativo'; ?></span>
                    <input type="hidden" id="cfg_printer_enabled" value="<?php echo $printer_enabled?'1':'0'; ?>">
                </div>
            </div>
            <div class="bp-alert bp-alert-info" style="margin-bottom:14px;font-size:.82rem">
                ℹ️ Requer o <strong>QZ Tray</strong> instalado no computador do caixa.
                <a href="https://qz.io/download/" target="_blank" style="color:var(--accent)">Baixar QZ Tray →</a>
            </div>
            <div class="bp-field-row">
                <div class="bp-field">
                    <label>Nome da Impressora (exato do sistema)</label>
                    <input type="text" id="cfg_printer_name" value="<?php echo esc_attr($printer_name); ?>" placeholder="Ex: POS-58, EPSON TM-T20">
                </div>
                <div class="bp-field" style="max-width:110px">
                    <label>Largura</label>
                    <select id="cfg_printer_width" style="background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;width:100%">
                        <option value="58" <?php selected($printer_width,'58'); ?>>58mm</option>
                        <option value="80" <?php selected($printer_width,'80'); ?>>80mm</option>
                    </select>
                </div>
                <div class="bp-field" style="max-width:90px">
                    <label>Cópias</label>
                    <input type="number" id="cfg_printer_copies" value="<?php echo $printer_copies; ?>" min="1" max="5">
                </div>
            </div>
            <div class="bp-field">
                <label>Cabeçalho (use \n para quebra de linha)</label>
                <textarea id="cfg_printer_header" rows="3" style="width:100%;background:var(--bg3);border:1px solid var(--border);border-radius:8px;color:var(--text);padding:9px 10px;resize:vertical;font-family:monospace"><?php echo esc_textarea($printer_header); ?></textarea>
            </div>
            <div class="bp-field">
                <label>Rodapé</label>
                <input type="text" id="cfg_printer_footer" value="<?php echo esc_attr($printer_footer); ?>">
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px">
                <button class="bp-btn bp-btn-primary" onclick="bpSaveSettings()">💾 Salvar</button>
                <button class="bp-btn bp-btn-secondary" onclick="bpPrinterTest()">🖨️ Imprimir Teste</button>
                <button class="bp-btn bp-btn-ghost" id="bpQzStatus" onclick="bpQzConnect()">🔌 Conectar QZ Tray</button>
            </div>
        </div>
        <?php
    }


}
