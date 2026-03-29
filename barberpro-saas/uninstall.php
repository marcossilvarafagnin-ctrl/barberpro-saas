<?php
/**
 * Uninstall BarberPro SaaS Manager
 * Remove tabelas e opções ao desinstalar o plugin.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;

$tables = [
    'barber_bookings',
    'barber_finance',
    'barber_commissions',
    'barber_services',
    'barber_professionals',
    'barber_companies',
    'barber_settings',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
}

delete_option( 'barberpro_db_version' );
delete_option( 'barberpro_settings' );

// Remove roles personalizadas
$roles = [ 'barber_admin', 'barber_professional', 'barber_client' ];
foreach ( $roles as $role ) {
    remove_role( $role );
}
