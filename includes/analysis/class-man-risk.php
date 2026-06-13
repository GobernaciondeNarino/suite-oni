<?php
/**
 * Anomalías e índice de riesgo municipal compuesto (Secciones 5.2 y 5.3).
 *
 * Índice 0..1 heurístico y transparente:
 *   r = w1·f_enso + w2·g_anom_lluvia + w3·h_expo + w4·k_sector
 * con Σw = 1 (pesos configurables en el panel admin). El signo del empuje
 * ENSO se interpreta distinto en zona andina (sequía) y litoral (exceso).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Risk {

	/**
	 * Anomalía térmica = pronóstico − climatología.
	 *
	 * @param float $t_pron Temperatura pronosticada.
	 * @param float $t_clim Climatología del mes.
	 * @return float °C.
	 */
	public static function anomalia_t( $t_pron, $t_clim ) {
		return round( (float) $t_pron - (float) $t_clim, 2 );
	}

	/**
	 * Anomalía de lluvia como fracción respecto a la normal.
	 *
	 * @param float $p_pron Precipitación pronosticada.
	 * @param float $p_clim Climatología del mes (> 0).
	 * @return float Fracción (0.2 = +20 %).
	 */
	public static function anomalia_lluvia( $p_pron, $p_clim ) {
		$p_clim = (float) $p_clim;
		if ( $p_clim <= 0 ) {
			return 0.0;
		}
		return round( ( (float) $p_pron - $p_clim ) / $p_clim, 3 );
	}

	/**
	 * Empuje del fenómeno ENSO normalizado a 0..1 (magnitud del ONI).
	 *
	 * @param float $oni     ONI del mes.
	 * @param bool  $litoral ¿Subregión litoral Pacífica?
	 * @return float 0..1.
	 */
	public static function f_enso( $oni, $litoral = false ) {
		$mag = min( 1.0, abs( (float) $oni ) / 2.0 );
		// En ambas subregiones un ENSO intenso eleva el riesgo, pero por
		// vías opuestas (andina: sequía; litoral: exceso de lluvia/oleaje).
		// La magnitud es el driver común; el signo se explica en el texto.
		return round( $mag, 3 );
	}

	/**
	 * Contribución por desviación de lluvia (exceso o déficit).
	 *
	 * @param float $anom_lluvia Fracción de anomalía.
	 * @return float 0..1.
	 */
	public static function g_anom( $anom_lluvia ) {
		return round( min( 1.0, abs( (float) $anom_lluvia ) / 0.5 ), 3 );
	}

	/**
	 * Exposición heurística por subregión (población, ladera, costa).
	 *
	 * @param string $subregion Subregión PDD.
	 * @return float 0..1.
	 */
	public static function h_expo( $subregion ) {
		$alta  = array( 'Sanquianga', 'Pacífico Sur', 'Telembí', 'Cordillera', 'Centro' );
		$media = array( 'Pie de Monte Costero', 'Juanambú', 'Occidente', 'Río Mayo', 'Sabana' );
		if ( in_array( $subregion, $alta, true ) ) {
			return 0.85;
		}
		if ( in_array( $subregion, $media, true ) ) {
			return 0.55;
		}
		return 0.4;
	}

	/**
	 * Sensibilidad sectorial (agrícola/hídrica) por subregión.
	 *
	 * @param string $subregion Subregión PDD.
	 * @return float 0..1.
	 */
	public static function k_sector( $subregion ) {
		$alta = array( 'Sabana', 'Ex-Provincia de Obando', 'Occidente', 'Río Mayo', 'Centro-Occidente / Abades' );
		return in_array( $subregion, $alta, true ) ? 0.8 : 0.5;
	}

	/**
	 * Calcula el índice de riesgo compuesto 0..1.
	 *
	 * @param array $args  {oni, anom_lluvia, subregion, litoral}.
	 * @param array $pesos {w1_enso, w2_anomalia, w3_exposicion, w4_sector}.
	 * @return float 0..1 redondeado a 3 decimales.
	 */
	public static function indice( array $args, array $pesos ) {
		$oni       = isset( $args['oni'] ) ? (float) $args['oni'] : 0.0;
		$anom      = isset( $args['anom_lluvia'] ) ? (float) $args['anom_lluvia'] : 0.0;
		$subregion = isset( $args['subregion'] ) ? $args['subregion'] : '';
		$litoral   = ! empty( $args['litoral'] );

		$w1 = isset( $pesos['w1_enso'] ) ? (float) $pesos['w1_enso'] : 0.4;
		$w2 = isset( $pesos['w2_anomalia'] ) ? (float) $pesos['w2_anomalia'] : 0.3;
		$w3 = isset( $pesos['w3_exposicion'] ) ? (float) $pesos['w3_exposicion'] : 0.2;
		$w4 = isset( $pesos['w4_sector'] ) ? (float) $pesos['w4_sector'] : 0.1;

		$r = $w1 * self::f_enso( $oni, $litoral )
			+ $w2 * self::g_anom( $anom )
			+ $w3 * self::h_expo( $subregion )
			+ $w4 * self::k_sector( $subregion );

		return round( max( 0.0, min( 1.0, $r ) ), 3 );
	}

	/**
	 * Traduce el índice 0..1 a un nivel con etiqueta y color de semáforo.
	 *
	 * @param float $riesgo Índice 0..1.
	 * @return array {clave, etiqueta, color}.
	 */
	public static function nivel( $riesgo ) {
		$r = (float) $riesgo;
		if ( $r < 0.25 ) {
			return array( 'clave' => 'bajo', 'etiqueta' => 'Bajo', 'color' => '#2e7d32' );
		}
		if ( $r < 0.50 ) {
			return array( 'clave' => 'medio', 'etiqueta' => 'Medio', 'color' => '#f9a825' );
		}
		if ( $r < 0.75 ) {
			return array( 'clave' => 'alto', 'etiqueta' => 'Alto', 'color' => '#ef6c00' );
		}
		return array( 'clave' => 'extremo', 'etiqueta' => 'Extremo', 'color' => '#c62828' );
	}
}
