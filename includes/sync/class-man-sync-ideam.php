<?php
/**
 * Conector IDEAM — FEWS (visorfews). Lee las redes de estaciones por variable
 * (nivel, precipitación, caudal, temperatura) y filtra las de Nariño con su
 * último valor y, donde existe, su umbral de alerta. Sustituye al antiguo
 * dataset de datos.gov.co, que dejó de existir.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Ideam {

	const BASE = 'https://fews.ideam.gov.co/visorfews/data/';

	/**
	 * Redes FEWS soportadas. Cada una indica su archivo, los campos del último
	 * valor (observado/sensor), los del umbral (si hay), el tipo de serie para
	 * el detalle y la unidad.
	 *
	 * @return array
	 */
	public static function redes() {
		return array(
			'nivel'         => array(
				'archivo' => 'ReporteTablaEstaciones',
				'valor'   => array( 'ultimonivelobs', 'ultimonivelsen' ),
				'umbral'  => array( 'umbralobs', 'umbralsen' ),
				'tipo'    => 'H',
				'unidad'  => 'm',
				'nombre'  => 'Nivel de ríos',
			),
			'precipitacion' => array(
				'archivo' => 'ReporteTablaEstacionesPobs',
				'valor'   => array( 'ultimodatoobs', 'ultimodatosen' ),
				'umbral'  => array(),
				'tipo'    => 'P',
				'unidad'  => 'mm',
				'nombre'  => 'Precipitación',
			),
			'caudal'        => array(
				'archivo' => 'ReporteTablaEstacionesQ',
				'valor'   => array( 'ultimoqobs', 'ultimoqsen' ),
				'umbral'  => array(),
				'tipo'    => 'Q',
				'unidad'  => 'm³/s',
				'nombre'  => 'Caudal',
			),
			'temperatura'   => array(
				'archivo' => 'ReporteTablaEstacionesTobs',
				'valor'   => array( 'ultimodatoobs', 'ultimodatosen' ),
				'umbral'  => array(),
				'tipo'    => 'T',
				'unidad'  => '°C',
				'nombre'  => 'Temperatura',
			),
		);
	}

	/**
	 * Descarga una red FEWS y devuelve sus estaciones de Nariño normalizadas.
	 *
	 * @param string $variable nivel|precipitacion|caudal|temperatura.
	 * @param bool   $ssl      Verificar certificado.
	 * @return array {ok, fuente, estaciones[], alertas, total_red}
	 */
	public static function estaciones_narino( $variable, $ssl = false ) {
		$redes = self::redes();
		$cfgv  = isset( $redes[ $variable ] ) ? $redes[ $variable ] : $redes['nivel'];

		$r = MAN_Sync::http_get( self::BASE . $cfgv['archivo'] . '.json', $ssl, array( 'timeout' => 25 ) );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'estaciones' => array(), 'alertas' => 0, 'total_red' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}
		$json  = json_decode( $r['cuerpo'], true );
		$feats = ( is_array( $json ) && ! empty( $json['features'] ) ) ? $json['features'] : array();
		if ( empty( $feats ) ) {
			return array( 'ok' => false, 'estaciones' => array(), 'alertas' => 0, 'total_red' => 0, 'mensaje' => 'Red FEWS vacía' );
		}

		$out     = array();
		$alertas = 0;
		foreach ( $feats as $f ) {
			$p   = isset( $f['properties'] ) && is_array( $f['properties'] ) ? $f['properties'] : array();
			$dep = strtoupper( remove_accents( (string) ( isset( $p['depart'] ) ? $p['depart'] : '' ) ) );
			if ( false === strpos( $dep, 'NARI' ) ) {
				continue;
			}
			$valor  = self::primer_valor( $p, $cfgv['valor'] );
			$umbral = self::primer_valor( $p, $cfgv['umbral'] );
			$alerta = ( null !== $valor && null !== $umbral && (float) $valor >= (float) $umbral );
			if ( $alerta ) {
				$alertas++;
			}
			$out[] = array(
				'id'           => isset( $p['id'] ) ? $p['id'] : '',
				'estacion'     => isset( $p['nombre'] ) ? $p['nombre'] : '',
				'municipio'    => isset( $p['municipio'] ) ? $p['municipio'] : '',
				'corriente'    => isset( $p['corriente'] ) ? $p['corriente'] : '',
				'lat'          => isset( $p['lat'] ) ? (float) $p['lat'] : null,
				'lng'          => isset( $p['lng'] ) ? (float) $p['lng'] : null,
				'valor'        => ( null !== $valor ) ? round( (float) $valor, 2 ) : null,
				'umbral'       => ( null !== $umbral ) ? round( (float) $umbral, 2 ) : null,
				'unidad'       => $cfgv['unidad'],
				'tipo_serie'   => $cfgv['tipo'],
				'nivel_alerta' => $alerta ? 'alta' : 'normal',
			);
		}

		return array(
			'ok'         => count( $out ) > 0,
			'fuente'     => 'IDEAM — FEWS · ' . $cfgv['nombre'],
			'estaciones' => $out,
			'alertas'    => $alertas,
			'total_red'  => count( $feats ),
		);
	}

	/**
	 * Sincroniza por cron la red de NIVEL (alertas de ríos) → caché ideam_alertas.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : false;
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 21600;

		$res = self::estaciones_narino( 'nivel', $ssl );
		if ( ! $res['ok'] && empty( $res['estaciones'] ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => isset( $res['mensaje'] ) ? $res['mensaje'] : 'Sin estaciones' );
		}

		MAN_Cache::set(
			'ideam_alertas',
			array(
				'registros'   => $res['estaciones'],
				'total_red'   => $res['total_red'],
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'IDEAM — FEWS (visorfews)',
			),
			$ttl,
			'ideam'
		);

		return array(
			'ok'        => true,
			'registros' => count( $res['estaciones'] ),
			'mensaje'   => count( $res['estaciones'] ) . ' estaciones de nivel en Nariño (' . $res['alertas'] . ' en alerta)',
		);
	}

	/**
	 * Primer valor numérico no nulo de una lista de claves dentro de $p.
	 *
	 * @param array $p     Propiedades.
	 * @param array $claves Claves candidatas.
	 * @return mixed|null
	 */
	private static function primer_valor( $p, $claves ) {
		foreach ( $claves as $k ) {
			if ( isset( $p[ $k ] ) && '' !== $p[ $k ] && is_numeric( $p[ $k ] ) ) {
				return $p[ $k ];
			}
		}
		return null;
	}
}
