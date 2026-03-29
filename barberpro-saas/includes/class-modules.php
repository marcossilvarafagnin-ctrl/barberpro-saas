<?php
/**
 * BarberPro – Gerenciador de Módulos
 *
 * Controla quais módulos estão ativos e fornece helpers globais.
 * Módulos disponíveis: barbearia, lavacar, bar
 *
 * @package BarberProSaaS
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BarberPro_Modules {

    /** Retorna se um módulo está ativo */
    public static function is_active( string $module ): bool {
        return (bool) BarberPro_Database::get_setting( "module_{$module}_active", '0' );
    }

    /** Lista todos os módulos com seus metadados */
    public static function all(): array {
        return [
            'barbearia' => [
                'label'       => '✂️ Barbearia',
                'description' => 'Agendamentos, kanban, profissionais, serviços e financeiro da barbearia.',
                'icon'        => '✂️',
                'color'       => '#e94560',
                'company_id'  => (int) BarberPro_Database::get_setting('module_barbearia_company_id', 1),
                'shortcodes'  => ['barberpro_barbearia', 'barberpro_painel_barbearia'],
            ],
            'lavacar' => [
                'label'       => '🚗 Lava-Car',
                'description' => 'Agendamentos com veículo, kanban, coleta/entrega e financeiro do lava-car.',
                'icon'        => '🚗',
                'color'       => '#3b82f6',
                'company_id'  => (int) BarberPro_Database::get_setting('module_lavacar_company_id', 2),
                'shortcodes'  => ['barberpro_lavacar', 'barberpro_painel_lavacar'],
            ],
            'bar' => [
                'label'       => '🍺 Bar / Eventos',
                'description' => 'Comandas, cardápio, caixa e gestão de bar ou eventos.',
                'icon'        => '🍺',
                'color'       => '#f59e0b',
                'company_id'  => (int) BarberPro_Database::get_setting('module_bar_company_id', 3),
                'shortcodes'  => [],
            ],
        ];
    }

    /** Retorna company_id de um módulo */
    public static function company_id( string $module ): int {
        $all = self::all();
        return $all[$module]['company_id'] ?? 1;
    }

    /** Ativa um módulo e garante que ele tenha um company_id único no banco */
    public static function activate( string $module ): void {
        global $wpdb;
        BarberPro_Database::update_setting( "module_{$module}_active", '1' );

        // Se não tem company_id definido, cria empresa no banco
        $existing_id = (int) BarberPro_Database::get_setting( "module_{$module}_company_id", 0 );
        if ( ! $existing_id ) {
            $labels = [ 'barbearia' => 'Barbearia', 'lavacar' => 'Lava-Car', 'bar' => 'Bar/Eventos' ];
            $slugs  = [ 'barbearia' => 'barbearia', 'lavacar' => 'lavacar', 'bar' => 'bar' ];
            $label  = $labels[$module] ?? ucfirst($module);
            $slug   = $slugs[$module]  ?? $module;
            $wpdb->insert( "{$wpdb->prefix}barber_companies", [
                'name'       => $label,
                'slug'       => $slug,
                'created_at' => current_time('mysql'),
            ] );
            $new_id = $wpdb->insert_id ?: ( $module === 'barbearia' ? 1 : 2 );
            BarberPro_Database::update_setting( "module_{$module}_company_id", (string) $new_id );
        }
    }

    /** Desativa um módulo */
    public static function deactivate( string $module ): void {
        BarberPro_Database::update_setting( "module_{$module}_active", '0' );
    }

    /** Retorna módulos ativos */
    public static function active_list(): array {
        return array_filter( self::all(), function($k) { return self::is_active($k); }, ARRAY_FILTER_USE_KEY );
    }
}
