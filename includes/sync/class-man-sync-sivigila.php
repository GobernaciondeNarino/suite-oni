<?php
/**
 * Conector SIVIGILA / INS vía datos.gov.co (Sección 3.7) — eventos de salud
 * pública sensibles al clima (dengue). Datos agregados, nunca individuales.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Sivigila {

	/**
	 * Sincroniza casos de dengue para Nariño.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$ds = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : '';
		if ( '' === $ds ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Falta el dataset-id de SIVIGILA' );
		}

		$base = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) . '/' : 'https://www.datos.gov.co/resource/';
		$ssl  = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : false;
		$ttl  = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 43200;

		$url = $base . rawurlencode( $ds ) . '.json?$limit=1000&$order=:id';

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$json = json_decode( $r['cuerpo'], true );
		if ( ! is_array( $json ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'JSON inválido de datos.gov.co' );
		}

		$narino = array();
		foreach ( $json as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$blob = strtoupper( remove_accents( wp_json_encode( $row ) ) );
			if ( false !== strpos( $blob, 'NARINO' ) ) {
				$narino[] = $row;
			}
		}

		MAN_Cache::set(
			'sivigila_dengue',
			array(
				'registros'   => $narino,
				'dataset'     => $ds,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'INS / SIVIGILA',
			),
			$ttl,
			'salud'
		);

		return array(
			'ok'        => true,
			'registros' => count( $narino ),
			'mensaje'   => count( $narino ) . ' registros de Nariño (dengue)',
		);
	}
}
