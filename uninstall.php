<?php
/**
 * Desinstalación: elimina tablas, opciones, transients y cron del plugin.
 *
 * Solo se ejecuta cuando el usuario ELIMINA el plugin desde WordPress.
 *
 * @package MonitorAmbientalNarino
 */

// Seguridad: este archivo solo debe correr durante la desinstalación.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1) Tablas.
$tabla_cache = $wpdb->prefix . 'man_cache';
$tabla_audit = $wpdb->prefix . 'man_audit';
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$tabla_cache}" );
$wpdb->query( "DROP TABLE IF EXISTS {$tabla_audit}" );

// 2) Opciones.
$opciones = array( 'man_api_config', 'man_estilo', 'man_pesos_riesgo', 'man_version' );
foreach ( $opciones as $opcion ) {
	delete_option( $opcion );
}

// 3) Transients del plugin (prefijo man_).
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_man\_%'
	    OR option_name LIKE '\_transient\_timeout\_man\_%'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery

// 4) Cron.
$timestamp = wp_next_scheduled( 'man_cron_sync' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'man_cron_sync' );
}
wp_clear_scheduled_hook( 'man_cron_sync' );
