<?php
/**
 * Conector IOC Sea Level Monitoring (Sección 3.5) — nivel del mar en la costa
 * Pacífica (estación de Tumaco). Servicio REST abierto para datos crudos.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Sealevel {

	/**
	 * Sincroniza el nivel del mar de una estación IOC.
	 *
	 * @param array $cfg Configuración (dataset_id = código de estación).
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$code = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : '';
		if ( '' === $code ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Falta el código de estación IOC' );
		}

		$base = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) . '/' : 'https://api.ioc-sealevelmonitoring.org/';
		$ssl  = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl  = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 3600;

		$url = $base . '?query=data&code=' . rawurlencode( $code ) . '&format=json';

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$json = json_decode( $r['cuerpo'], true );
		if ( ! is_array( $json ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'JSON inválido de IOC' );
		}

		// Conserva las últimas ~288 muestras (1 día a 5 min) para no inflar caché.
		$muestras = array_slice( $json, -288 );

		MAN_Cache::set(
			'mar_nivel',
			array(
				'estacion'    => $code,
				'muestras'    => $muestras,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'IOC / VLIZ Sea Level',
			),
			$ttl,
			'mar'
		);

		return array(
			'ok'        => true,
			'registros' => count( $muestras ),
			'mensaje'   => count( $muestras ) . ' muestras de ' . $code,
		);
	}
}
