<?php
/**
 * Conector IDEAM — FEWS (visorfews). Lee las redes de estaciones por variable
 * (nivel, precipitación, caudal, temperatura, nivel y caudal pronosticados y
 * calidad del agua) y filtra las de Nariño con su último valor y nivel de
 * alerta. Sustituye al antiguo dataset de datos.gov.co, que dejó de existir.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Ideam {

	const BASE = 'https://fews.ideam.gov.co/visorfews/data/';

	/**
	 * Redes FEWS soportadas. 'alerta' indica cómo se calcula el nivel de alerta:
	 *   umbral  → alta si valor ≥ umbral (nivel observado).
	 *   graded  → media/alta según umbrales amarilla/naranja/roja (pronóstico).
	 *   ica     → categoría del Índice de Calidad del Agua (menor = peor).
	 *   none    → sin alerta (precipitación, temperatura).
	 *
	 * @return array
	 */
	public static function redes() {
		return array(
			'nivel'             => array(
				'archivo' => 'ReporteTablaEstaciones',
				'valor'   => array( 'ultimonivelobs', 'ultimonivelsen' ),
				'alerta'  => 'umbral',
				'umbral'  => array( 'umbralobs', 'umbralsen' ),
				'tipo'    => 'H',
				'unidad'  => 'm',
				'nombre'  => 'Nivel de ríos',
			),
			'precipitacion'     => array(
				'archivo' => 'ReporteTablaEstacionesPobs',
				'valor'   => array( 'ultimodatoobs', 'ultimodatosen' ),
				'alerta'  => 'none',
				'tipo'    => 'P',
				'unidad'  => 'mm',
				'nombre'  => 'Precipitación',
			),
			'caudal'            => array(
				'archivo' => 'ReporteTablaEstacionesQ',
				'valor'   => array( 'ultimoqobs', 'ultimoqsen' ),
				'alerta'  => 'none',
				'tipo'    => 'Q',
				'unidad'  => 'm³/s',
				'nombre'  => 'Caudal',
			),
			'temperatura'       => array(
				'archivo' => 'ReporteTablaEstacionesTobs',
				'valor'   => array( 'ultimodatoobs', 'ultimodatosen' ),
				'alerta'  => 'none',
				'tipo'    => 'T',
				'unidad'  => '°C',
				'nombre'  => 'Temperatura',
			),
			'nivel_pronostico'  => array(
				'archivo' => 'ReporteTablaEstacionesHsim',
				'valor'   => array( 'maxnivel' ),
				'alerta'  => 'graded',
				'grad'    => array( 'uamarilla', 'unaranja', 'uroja' ),
				'tipo'    => 'H',
				'unidad'  => 'm',
				'nombre'  => 'Nivel pronosticado',
			),
			'caudal_pronostico' => array(
				'archivo' => 'ReporteTablaEstacionesQsim',
				'valor'   => array( 'maxcaudal' ),
				'alerta'  => 'graded',
				'grad'    => array( 'uamarilla', 'unaranja', 'uroja' ),
				'tipo'    => 'Q',
				'unidad'  => 'm³/s',
				'nombre'  => 'Caudal pronosticado',
			),
			'calidad'           => array(
				'archivo' => 'ReporteTablaEstacionesCalidad',
				'valor'   => array( 'ultimodatoica6v' ),
				'alerta'  => 'ica',
				'tipo'    => '',
				'unidad'  => 'ICA',
				'nombre'  => 'Calidad del agua (ICA)',
			),
		);
	}

	/**
	 * Descarga una red FEWS y devuelve sus estaciones de Nariño normalizadas.
	 *
	 * @param string $variable Clave de red.
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
			$umbral = ( 'umbral' === $cfgv['alerta'] ) ? self::primer_valor( $p, $cfgv['umbral'] ) : null;
			$alerta = self::nivel_alerta( $valor, $umbral, $p, $cfgv );
			if ( 'alta' === $alerta ) {
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
				'nivel_alerta' => $alerta,
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
	 * Calcula el nivel de alerta (normal|media|alta) según el modo de la red.
	 *
	 * @param mixed $valor  Último valor.
	 * @param mixed $umbral Umbral (modo umbral).
	 * @param array $p      Propiedades de la estación.
	 * @param array $cfgv   Config de la red.
	 * @return string
	 */
	private static function nivel_alerta( $valor, $umbral, $p, $cfgv ) {
		if ( null === $valor ) {
			return 'normal';
		}
		$v = (float) $valor;
		switch ( $cfgv['alerta'] ) {
			case 'umbral':
				return ( null !== $umbral && $v >= (float) $umbral ) ? 'alta' : 'normal';
			case 'graded':
				$g    = isset( $cfgv['grad'] ) ? $cfgv['grad'] : array();
				$am   = isset( $g[0] ) ? self::primer_valor( $p, array( $g[0] ) ) : null;
				$nar  = isset( $g[1] ) ? self::primer_valor( $p, array( $g[1] ) ) : null;
				$roja = isset( $g[2] ) ? self::primer_valor( $p, array( $g[2] ) ) : null;
				if ( null !== $nar && $v >= (float) $nar ) {
					return 'alta';
				}
				if ( null !== $roja && $v >= (float) $roja ) {
					return 'alta';
				}
				if ( null !== $am && $v >= (float) $am ) {
					return 'media';
				}
				return 'normal';
			case 'ica':
				// Índice de Calidad del Agua de 6 variables (ICA6v), escala 0–1: menor = peor.
				if ( $v <= 0.50 ) {
					return 'alta';
				}
				if ( $v <= 0.70 ) {
					return 'media';
				}
				return 'normal';
			default:
				return 'normal';
		}
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
