<?php
/**
 * Pronóstico probabilístico de fase ENSO y suavizado (Sección 5.5).
 *
 * Heurística transparente y auditable; NO pretende sustituir las plumas
 * oficiales del IRI/CPC. Siempre comunica incertidumbre.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Forecast {

	/**
	 * Media móvil (por defecto de 3 meses, como define el ONI).
	 *
	 * @param float[] $serie   Serie cronológica.
	 * @param int     $ventana Tamaño de la ventana.
	 * @return float[] Serie suavizada (misma longitud; bordes parciales).
	 */
	public static function suavizar_media_movil( array $serie, $ventana = 3 ) {
		$ventana = max( 1, (int) $ventana );
		$n       = count( $serie );
		$salida  = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$ini  = max( 0, $i - intdiv( $ventana, 2 ) );
			$fin  = min( $n - 1, $i + intdiv( $ventana, 2 ) );
			$suma = 0.0;
			$cnt  = 0;
			for ( $j = $ini; $j <= $fin; $j++ ) {
				$suma += (float) $serie[ $j ];
				$cnt++;
			}
			$salida[] = $cnt > 0 ? round( $suma / $cnt, 3 ) : 0.0;
		}
		return $salida;
	}

	/**
	 * Probabilidad de cada fase a partir del ONI (logística suave, normalizada).
	 *
	 * @param float $oni ONI proyectado.
	 * @return array {el_nino, neutral, la_nina} en porcentaje 0..100.
	 */
	public static function probabilidad_fase( $oni ) {
		$o      = (float) $oni;
		$p_nino = 1.0 / ( 1.0 + exp( -3.0 * ( $o - 0.5 ) ) );
		$p_nina = 1.0 / ( 1.0 + exp( 3.0 * ( $o + 0.5 ) ) );
		$p_neu  = max( 0.0, 1.0 - $p_nino - $p_nina );

		$total = $p_nino + $p_nina + $p_neu;
		if ( $total <= 0 ) {
			return array(
				'el_nino' => 0.0,
				'neutral' => 100.0,
				'la_nina' => 0.0,
			);
		}
		return array(
			'el_nino' => round( 100 * $p_nino / $total, 1 ),
			'neutral' => round( 100 * $p_neu / $total, 1 ),
			'la_nina' => round( 100 * $p_nina / $total, 1 ),
		);
	}

	/**
	 * Banda de incertidumbre simétrica para un valor pronosticado.
	 * Crece con el horizonte (días hacia el futuro) — honestidad estadística.
	 *
	 * @param float $valor         Valor central.
	 * @param int   $dias_horizonte Días hacia adelante.
	 * @param float $base          Amplitud base de la banda.
	 * @return array {min, max}
	 */
	public static function banda_incertidumbre( $valor, $dias_horizonte, $base = 0.5 ) {
		$valor = (float) $valor;
		$amp   = $base + 0.12 * max( 0, (int) $dias_horizonte );
		return array(
			'min' => round( $valor - $amp, 2 ),
			'max' => round( $valor + $amp, 2 ),
		);
	}
}
