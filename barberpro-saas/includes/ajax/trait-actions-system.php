<?php
if ( ! defined( 'ABSPATH' ) ) exit;

trait BP_Actions_System {

    private function action_activate_license(): void {
        $key = sanitize_text_field($_POST['license_key']??'');
        if ( empty($key) ) wp_send_json_error(['message'=>'Digite a chave de licenca.']);

        $res = BarberPro_License::save($key);
        if ( ! empty($res['status']) && $res['status'] === 'active' ) {
            wp_send_json_success(['status'=>$res['status'],'plan'=>$res['plan']??'']);
        } else {
            wp_send_json_error(['message' => $res['message'] ?? 'Chave invalida ou expirada.']);
        }
    }

    private function action_save_settings(): void {
        $fields = ['business_name','module_barbearia_name','module_lavacar_name','printer_name','printer_width','printer_copies','printer_footer','printer_enabled'];
        foreach($fields as $f) {
            if(isset($_POST[$f])) BarberPro_Database::update_setting($f, sanitize_text_field(wp_unslash($_POST[$f])));
        }
        if(isset($_POST['printer_header'])){
            BarberPro_Database::update_setting('printer_header', sanitize_textarea_field(wp_unslash($_POST['printer_header'])));
        }
        wp_send_json_success();
    }


    // ── Caixa: abrir comanda rápida com itens já adicionados ──────
    private function action_caixa_nova_comanda(): void {
        $table  = sanitize_text_field($_POST['table_number'] ?? '');
        $client = sanitize_text_field($_POST['client_name']  ?? '');
        $itens  = json_decode(stripslashes($_POST['itens'] ?? '[]'), true) ?: [];

        $id = BarberPro_Bar::open_comanda([
            'table_number' => $table,
            'client_name'  => $client,
        ]);
        if ( ! $id ) {
            wp_send_json_error(['message' => 'Erro ao criar comanda.']);
        }

        // Adiciona itens se vieram na requisição
        foreach ( $itens as $item ) {
            $pid = absint($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 1);
            if ( $pid > 0 && $qty > 0 ) {
                BarberPro_Bar::add_item($id, $pid, $qty);
            }
        }

        $comanda = BarberPro_Bar::get_comanda($id);
        wp_send_json_success([
            'id'   => $id,
            'code' => $comanda->comanda_code ?? '',
        ]);
    }

    // ── Bar: excluir produto (soft delete) ────────────────────────
    private function action_bar_delete_product(): void {
        $id = absint($_POST['product_id'] ?? 0);
        if ( ! $id ) wp_send_json_error(['message' => 'ID inválido.']);

        global $wpdb;
        $r = $wpdb->update(
            "{$wpdb->prefix}barber_products",
            ['status' => 'inactive', 'updated_at' => current_time('mysql')],
            ['id' => $id]
        );
        $r !== false
            ? wp_send_json_success()
            : wp_send_json_error(['message' => 'Erro ao excluir produto.']);
    }


    // ── Toggle ativo/inativo (serviço, profissional, produto) ─────
    private function action_toggle_status( string $type ): void {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        if ( ! $id ) wp_send_json_error(['message' => 'ID inválido.']);

        $tables = [
            'service' => $wpdb->prefix . 'barber_services',
            'pro'     => $wpdb->prefix . 'barber_professionals',
            'product' => $wpdb->prefix . 'barber_products',
        ];
        $table = $tables[$type] ?? null;
        if ( ! $table ) wp_send_json_error(['message' => 'Tipo inválido.']);

        // Lê status atual
        $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d", $id));
        if ( $current === null ) wp_send_json_error(['message' => 'Registro não encontrado.']);

        $new_status = $current === 'active' ? 'inactive' : 'active';
        $r = $wpdb->update($table, [
            'status'     => $new_status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $id]);

        $r !== false
            ? wp_send_json_success(['new_status' => $new_status])
            : wp_send_json_error(['message' => 'Erro ao atualizar.']);
    }

    // ── Utility ───────────────────────────────────────────────────

    // ── Backup: Export / Download / Save ─────────────────────────
    private function action_backup_export(): void {
        // Backup de arquivos (ZIP)
        if ( ! empty( $_POST['plugin_zip'] ) ) {
            $result = BarberPro_Backup::backup_plugin_files();
            if ( isset($result['error']) ) {
                wp_send_json_error(['message' => $result['error']]);
            }
            wp_send_json_success([
                'filename' => $result['filename'],
                'size'     => BarberPro_Backup::fmt_size_public( $result['size'] ),
            ]);
        }

        // Baixar backup de arquivo já salvo no servidor
        if ( ! empty( $_POST['download_file'] ) ) {
            $path = BarberPro_Backup::get_backup_path( sanitize_file_name($_POST['download_file']) );
            if ( ! $path ) wp_send_json_error(['message'=>'Arquivo não encontrado.']);
            $json = json_decode( file_get_contents($path), true );
            $json === null && wp_send_json_error(['message'=>'Arquivo inválido.']);
            wp_send_json_success(['json' => $json, 'filename' => basename($path)]);
        }

        // Retornar URL para download de ZIP
        if ( ! empty( $_POST['download_zip'] ) ) {
            $filename = sanitize_file_name($_POST['download_zip']);
            $path     = BarberPro_Backup::get_backup_path($filename);
            if ( ! $path ) wp_send_json_error(['message'=>'Arquivo não encontrado.']);
            // Gera URL temporária (nonce protegida) ou usa URL direta protegida
            $url = add_query_arg([
                'action'   => 'bp_backup_download',
                'file'     => $filename,
                'nonce'    => wp_create_nonce('bp_dl_'.$filename),
            ], admin_url('admin-ajax.php'));
            wp_send_json_success(['url' => $url]);
        }

        // Coletar dados
        $opts = [
            'db'       => ! empty($_POST['include_db'])       && $_POST['include_db']       === '1',
            'settings' => ! empty($_POST['include_settings']) && $_POST['include_settings'] === '1',
        ];
        $data     = BarberPro_Backup::collect_data($opts);
        $filename = 'barberpro-manual-' . date('Y-m-d_H-i-s', current_time('timestamp')) . '.json';

        // Apenas download (retorna JSON para o browser)
        if ( ! empty($_POST['download']) ) {
            wp_send_json_success(['json' => $data, 'filename' => $filename]);
        }

        // Salvar no servidor
        if ( ! empty($_POST['save']) ) {
            $result = BarberPro_Backup::save_to_file($data, 'manual');
            wp_send_json_success([
                'filename' => $result['filename'],
                'size'     => BarberPro_Backup::fmt_size_public( $result['size'] ),
            ]);
        }

        wp_send_json_error(['message' => 'Operação não especificada.']);
    }

    // ── Backup: Restaurar ─────────────────────────────────────────
    private function action_backup_restore(): void {
        $raw = stripslashes($_POST['data'] ?? '');
        if ( empty($raw) ) wp_send_json_error(['message' => 'Nenhum dado recebido.']);

        $data = json_decode($raw, true);
        if ( ! is_array($data) ) wp_send_json_error(['message' => 'JSON inválido.']);

        // Verificação básica de integridade
        if ( empty($data['tables']) && empty($data['settings']) ) {
            wp_send_json_error(['message' => 'Arquivo de backup vazio ou corrompido.']);
        }

        $result = BarberPro_Backup::restore($data);

        if ( ! empty($result['errors']) ) {
            wp_send_json_error(['message' => implode(' | ', $result['errors'])]);
        }

        wp_send_json_success([
            'restored' => $result['restored'],
            'settings' => $result['settings'],
        ]);
    }

    // ── Backup: Salvar configuração automática ────────────────────
    private function action_backup_auto_save(): void {
        $cfg = [
            'enabled'           => ! empty($_POST['enabled']) && $_POST['enabled'] === '1',
            'frequency'         => sanitize_key($_POST['frequency'] ?? 'daily'),
            'keep'              => absint($_POST['keep'] ?? 7),
            'include_db'        => ! empty($_POST['include_db'])       && $_POST['include_db']       === '1',
            'include_settings'  => ! empty($_POST['include_settings']) && $_POST['include_settings'] === '1',
        ];
        if ( ! in_array($cfg['frequency'], ['daily','weekly','monthly']) ) {
            $cfg['frequency'] = 'daily';
        }
        BarberPro_Backup::save_schedule($cfg);
        wp_send_json_success(['config' => $cfg]);
    }

    // ── Backup: Excluir arquivo ───────────────────────────────────
    private function action_backup_delete(): void {
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        if ( empty($filename) ) wp_send_json_error(['message' => 'Nome de arquivo inválido.']);
        $ok = BarberPro_Backup::delete_backup($filename);
        $ok ? wp_send_json_success() : wp_send_json_error(['message' => 'Arquivo não encontrado ou sem permissão.']);
    }

    /** Verifica se o usuário atual tem acesso ao módulo */
    private function user_can_module( string $module ): bool {
        if ( current_user_can('administrator') || current_user_can('manage_options') ) return true;
        $allowed = get_user_meta( get_current_user_id(), 'barberpro_modules', true );
        if ( empty($allowed) || ! is_array($allowed) ) return true;
        return in_array( $module, $allowed, true );
    }

    private function money($v): string {
        return 'R$ ' . number_format((float)$v, 2, ',', '.');
    }

}
