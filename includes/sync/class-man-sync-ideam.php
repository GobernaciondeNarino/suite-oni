<?php
/**
 * Conector IDEAM vía datos.gov.co (Socrata/SoQL) — alertas y pronóstico
 * oficial por municipio (Sección 3.3). Requiere sslverify=false (cert estatal).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Ideam {

	/**
	 * Sincroniza alertas IDEAM para Nariño.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$ds = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : '';
		if ( '' === $ds ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Falta el dataset-id de IDEAM' );
		}

		$base = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) . '/' : 'https://www.datos.gov.co/resource/';
		$ssl  = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : false;
		$ttl  = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 21600;

		// Socrata exige $ literales en los parámetros de consulta.
		$url = $base . rawurlencode( $ds ) . '.json?$limit=500&$order=:id';

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$json = json_decode( $r['cuerpo'], true );
		if ( ! is_array( $json ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'JSON inválido de datos.gov.co' );
		}

		// Filtra registros de Nariño (campos de departamento variables).
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
		$datos = ! empty( $narino ) ? $narino : array_slice( $json, 0, 200 );

		MAN_Cache::set(
			'ideam_alertas',
			array(
				'registros'   => $datos,
				'dataset'     => $ds,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'IDEAM / datos.gov.co',
			),
			$ttl,
			'ideam'
		);

		return array(
			'ok'        => true,
			'registros' => count( $datos ),
			'mensaje'   => count( $narino ) . ' registros de Nariño',
		);
	}
}
