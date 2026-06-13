<?php
/**
 * Activación / desactivación del plugin.
 *
 * Crea las tablas (caché durable y auditoría), agenda el cron de
 * sincronización y siembra las opciones por defecto (config de APIs,
 * apariencia minimalista y pesos del índice de riesgo).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Activator {

	/** Nombre del evento de cron. */
	const HOOK_CRON = 'man_cron_sync';

	/**
	 * Se ejecuta al activar el plugin.
	 */
	public static function activar() {
		self::crear_tablas();
		self::sembrar_opciones();
		self::agendar_cron();

		// Marca de versión para futuras migraciones.
		update_option( 'man_version', MAN_VERSION );

		// Refresca permalinks para exponer la REST sin 404.
		flush_rewrite_rules();
	}

	/**
	 * Se ejecuta al desactivar el plugin (no borra datos).
	 */
	public static function desactivar() {
		$timestamp = wp_next_scheduled( self::HOOK_CRON );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK_CRON );
		}
		wp_clear_scheduled_hook( self::HOOK_CRON );
		flush_rewrite_rules();
	}

	/**
	 * Crea las tablas del plugin con dbDelta.
	 */
	private static function crear_tablas() {
		global $wpdb;
		require_once \ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset      = $wpdb->get_charset_collate();
		$tabla_cache  = $wpdb->prefix . 'man_cache';
		$tabla_audit  = $wpdb->prefix . 'man_audit';

		$sql_cache = "CREATE TABLE {$tabla_cache} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			clave varchar(191) NOT NULL,
			grupo varchar(64) NOT NULL DEFAULT 'general',
			valor longtext NULL,
			expira int(11) unsigned NOT NULL DEFAULT 0,
			actualizado datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY clave (clave),
			KEY grupo (grupo)
		) {$charset};";

		$sql_audit = "CREATE TABLE {$tabla_audit} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			evento varchar(64) NOT NULL DEFAULT '',
			fuente varchar(64) NOT NULL DEFAULT '',
			resultado varchar(32) NOT NULL DEFAULT '',
			detalle text NULL,
			registros int(11) NOT NULL DEFAULT 0,
			ts datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY evento (evento),
			KEY ts (ts)
		) {$charset};";

		dbDelta( $sql_cache );
		dbDelta( $sql_audit );
	}

	/**
	 * Siembra las opciones por defecto sin sobrescribir si ya existen.
	 */
	private static function sembrar_opciones() {
		// 1) Configuración por API (Sección 8 de la especificación).
		add_option( 'man_api_config', self::config_apis_por_defecto() );

		// 2) Apariencia: por defecto MINIMALISTA PURO / transparente.
		add_option( 'man_estilo', self::estilo_por_defecto() );

		// 3) Pesos del índice de riesgo (Sección 5.3), Σ = 1.
		add_option(
			'man_pesos_riesgo',
			array(
				'w1_enso'      => 0.40, // empuje del fenómeno ENSO
				'w2_anomalia'  => 0.30, // exceso/déficit de lluvia
				'w3_exposicion'=> 0.20, // población / ladera / costa
				'w4_sector'    => 0.10, // sensibilidad agrícola / hídrica
			)
		);
	}

	/**
	 * Valores por defecto de la apariencia (minimalismo transparente).
	 *
	 * @return array
	 */
	public static function estilo_por_defecto() {
		return array(
			'fondo'          => 'transparent', // sin fondo → se funde con la página
			'texto'          => 'inherit',     // hereda el color del tema
			'tipografia'     => 'inherit',     // hereda la tipografía del tema
			'acento'         => '#10A13B',     // verde institucional
			'acento_2'       => '#FFD500',     // amarillo institucional
			'acento_tecnico' => '#003087',     // azul profundo (acento marino/técnico)
			'mute'           => '#6b7280',     // texto secundario
			'borde'          => 'none',        // sin bordes
			'borde_color'    => '#e5e7eb',
			'borde_radio'    => '0',           // sin esquinas redondeadas
			'sombra'         => 'none',        // sin sombras
			'ancho_max'      => '100%',
			'espaciado'      => '0',           // sin padding propio
		);
	}

	/**
	 * Configuración por defecto de cada fuente de datos.
	 *
	 * @return array
	 */
	public static function config_apis_por_defecto() {
		return array(
			'open_meteo' => array(
				'nombre'           => 'Open-Meteo (pronóstico, marino, aire, caudal)',
				'activa'           => true,
				'capa'             => 'navegador',
				'url'              => 'https://api.open-meteo.com/v1/forecast',
				'dataset_id'       => '',
				'clave'            => '',
				'frecuencia'       => 1,
				'ttl'              => 30,
				'sslverify'        => true,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
			'noaa_oni' => array(
				'nombre'           => 'NOAA CPC — ONI / Niño 3.4',
				'activa'           => true,
				'capa'             => 'cron',
				'url'              => 'https://www.cpc.ncep.noaa.gov/data/indices/oni.ascii.txt',
				'url_aux'          => 'https://www.cpc.ncep.noaa.gov/data/indices/wksst8110.for',
				'dataset_id'       => '',
				'clave'            => '',
				'frecuencia'       => 12,
				'ttl'              => 720,
				'sslverify'        => true,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
			'ideam' => array(
				'nombre'           => 'IDEAM vía datos.gov.co (alertas/pronóstico)',
				'activa'           => true,
				'capa'             => 'cron',
				'url'              => 'https://www.datos.gov.co/resource/',
				'dataset_id'       => 'st8p-pai8',
				'clave'            => '',
				'frecuencia'       => 12,
				'ttl'              => 360,
				'sslverify'        => false, // certificado estatal CO
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
			'sivigila' => array(
				'nombre'           => 'SIVIGILA / INS vía datos.gov.co (dengue)',
				'activa'           => false, // requiere fijar dataset-id vigente
				'capa'             => 'cron',
				'url'              => 'https://www.datos.gov.co/resource/',
				'dataset_id'       => '',
				'clave'            => '',
				'frecuencia'       => 24,
				'ttl'              => 720,
				'sslverify'        => false,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
			'ioc' => array(
				'nombre'           => 'IOC Sea Level Monitoring (nivel del mar)',
				'activa'           => false, // activar al fijar el código de estación
				'capa'             => 'cron',
				'url'              => 'https://api.ioc-sealevelmonitoring.org/',
				'dataset_id'       => '', // código de estación (ej. Tumaco)
				'clave'            => '',
				'frecuencia'       => 6,
				'ttl'              => 60,
				'sslverify'        => true,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
		);
	}

	/**
	 * Agenda el cron de sincronización cada 12 h (dentro del rango 6-12 h).
	 */
	private static function agendar_cron() {
		if ( ! wp_next_scheduled( self::HOOK_CRON ) ) {
			// Registra el intervalo propio para que exista al agendar.
			add_filter( 'cron_schedules', array( MAN_Sync::class, 'intervalos_personalizados' ) );
			wp_schedule_event( time() + 60, 'man_12h', self::HOOK_CRON );
		}
	}
}
