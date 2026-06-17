<?php
/**
 * Conector IDEAM — FEWS (Sistema de Alerta Temprana, visorfews). Trae la red de
 * estaciones hidrológicas y filtra las de Nariño con su nivel actual y umbral
 * de alerta. Sustituye al antiguo dataset de datos.gov.co, que dejó de existir.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Ideam {

	const FEWS_URL = 'https://fews.ideam.gov.co/visorfews/data/ReporteTablaEstaciones.json';

	/**
	 * Sincroniza las estaciones FEWS de Nariño.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$url = ! empty( $cfg['url'] ) ? $cfg['url'] : self::FEWS_URL;
		// Autocorrige instalaciones antiguas que apuntaban al dataset muerto de datos.gov.co.
		if ( false !== strpos( $url, 'datos.gov.co' ) ) {
			$url = self::FEWS_URL;
		}
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : false; // certificado estatal CO.
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 21600;

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$json  = json_decode( $r['cuerpo'], true );
		$feats = ( is_array( $json ) && ! empty( $json['features'] ) ) ? $json['features'] : array();
		if ( empty( $feats ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'FEWS sin estaciones (¿formato cambiado?)' );
		}

		$narino  = array();
		$alertas = 0;
		foreach ( $feats as $f ) {
			$p = isset( $f['properties'] ) && is_array( $f['properties'] ) ? $f['properties'] : array();
			$dep = strtoupper( remove_accents( (string) ( isset( $p['depart'] ) ? $p['depart'] : '' ) ) );
			if ( false === strpos( $dep, 'NARI' ) ) {
				continue;
			}

			$nivel  = self::primer_valor( array( isset( $p['ultimonivelobs'] ) ? $p['ultimonivelobs'] : null, isset( $p['ultimonivelsen'] ) ? $p['ultimonivelsen'] : null ) );
			$umbral = self::primer_valor( array( isset( $p['umbralobs'] ) ? $p['umbralobs'] : null, isset( $p['umbralsen'] ) ? $p['umbralsen'] : null ) );
			$alerta = ( null !== $nivel && null !== $umbral && (float) $nivel >= (float) $umbral );
			if ( $alerta ) {
				$alertas++;
			}

			$narino[] = array(
				'id'           => isset( $p['id'] ) ? $p['id'] : '',
				'estacion'     => isset( $p['nombre'] ) ? $p['nombre'] : '',
				'municipio'    => isset( $p['municipio'] ) ? $p['municipio'] : '',
				'corriente'    => isset( $p['corriente'] ) ? $p['corriente'] : '',
				'lat'          => isset( $p['lat'] ) ? (float) $p['lat'] : null,
				'lng'          => isset( $p['lng'] ) ? (float) $p['lng'] : null,
				'nivel'        => ( null !== $nivel ) ? round( (float) $nivel, 2 ) : null,
				'umbral'       => ( null !== $umbral ) ? round( (float) $umbral, 2 ) : null,
				'nivel_alerta' => $alerta ? 'alta' : 'normal',
			);
		}

		MAN_Cache::set(
			'ideam_alertas',
			array(
				'registros'   => $narino,
				'total_red'   => count( $feats ),
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'IDEAM — FEWS (visorfews)',
			),
			$ttl,
			'ideam'
		);

		return array(
			'ok'        => count( $narino ) > 0,
			'registros' => count( $narino ),
			'mensaje'   => count( $narino ) . ' estaciones de Nariño (' . $alertas . ' en alerta)',
		);
	}

	/**
	 * Primer valor no nulo/no vacío de una lista.
	 *
	 * @param array $vals Lista de candidatos.
	 * @return mixed|null
	 */
	private static function primer_valor( $vals ) {
		foreach ( $vals as $v ) {
			if ( null !== $v && '' !== $v ) {
				return $v;
			}
		}
		return null;
	}
}
