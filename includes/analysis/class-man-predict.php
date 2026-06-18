<?php
/**
 * Motor de predicción por vista. Calcula una estimación a futuro con
 * incertidumbre EXPLÍCITA, reutilizando MAN_Forecast (OLS, Holt amortiguado,
 * gaussiana). Cada método es transparente y auditable; NO sustituye los
 * pronósticos oficiales del IRI/CPC ni del IDEAM y SIEMPRE comunica el método
 * y la banda. Las salidas se etiquetan como «estimación».
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Predict {

	/**
	 * Predicción de una serie temporal numérica por regresión lineal (OLS) con
	 * banda de incertidumbre del 90% (1,645·σ_residual) que crece con el
	 * horizonte (√h). Si se aportan etiquetas mensuales, añade un ajuste
	 * estacional simple (desviación media del mes-del-año respecto a la recta).
	 *
	 * @param float[]  $valores Valores en orden cronológico.
	 * @param string[] $meses   Etiquetas AAAA-MM paralelas (opcional, estacionalidad).
	 * @param int      $h       Horizonte (pasos a futuro).
	 * @return array {metodo, horizonte, proyeccion[], pendiente, r2, sigma}
	 */
	public static function serie_temporal( array $valores, array $meses = array(), $h = 3 ) {
		$valores = array_values( array_map( 'floatval', $valores ) );
		$n       = count( $valores );
		if ( $n < 3 ) {
			return array( 'metodo' => 'Regresión lineal (OLS)', 'horizonte' => 0, 'proyeccion' => array(), 'pendiente' => 0.0, 'r2' => 0.0, 'sigma' => 0.0 );
		}

		$reg = MAN_Forecast::regresion_lineal( $valores );

		// Desviación estándar de los residuos (insesgada) para la banda.
		$res = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$est  = $reg['intercepto'] + $reg['pendiente'] * $i;
			$res += ( $valores[ $i ] - $est ) * ( $valores[ $i ] - $est );
		}
		$sigma = ( $n > 2 ) ? sqrt( $res / ( $n - 2 ) ) : 0.0;

		// Ajuste estacional opcional: desviación media por mes-del-año.
		$estacional = self::factor_estacional( $valores, $meses, $reg );

		$proy = array();
		for ( $k = 1; $k <= (int) $h; $k++ ) {
			$x   = $n - 1 + $k;
			$v   = $reg['intercepto'] + $reg['pendiente'] * $x;
			if ( $estacional && $meses ) {
				$mes_fut = MAN_Forecast::sumar_meses( end( $meses ), $k );
				$mm      = (int) substr( $mes_fut, 5, 2 );
				$v      += isset( $estacional[ $mm ] ) ? $estacional[ $mm ] : 0.0;
			}
			$b      = $sigma * sqrt( $k );
			$proy[] = array(
				'paso'      => $k,
				'valor'     => round( $v, 2 ),
				'banda_min' => round( $v - 1.645 * $b, 2 ),
				'banda_max' => round( $v + 1.645 * $b, 2 ),
			);
		}

		return array(
			'metodo'     => $estacional ? 'Regresión lineal (OLS) + estacionalidad, banda 90%' : 'Regresión lineal (OLS), banda 90%',
			'horizonte'  => (int) $h,
			'proyeccion' => $proy,
			'pendiente'  => round( $reg['pendiente'], 4 ),
			'r2'         => $reg['r2'],
			'sigma'      => round( $sigma, 3 ),
		);
	}

	/**
	 * Factor estacional (desviación media respecto a la recta por mes-del-año).
	 * Solo si hay etiquetas mensuales y al menos dos ciclos anuales.
	 *
	 * @param float[]  $valores Serie.
	 * @param string[] $meses   Etiquetas AAAA-MM.
	 * @param array    $reg     Resultado de regresion_lineal.
	 * @return array<int,float>|null Desviación por número de mes (1–12), o null.
	 */
	private static function factor_estacional( array $valores, array $meses, array $reg ) {
		$n = count( $valores );
		if ( count( $meses ) !== $n || $n < 24 ) {
			return null;
		}
		$acc = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$mm = (int) substr( (string) $meses[ $i ], 5, 2 );
			if ( $mm < 1 || $mm > 12 ) {
				return null;
			}
			$est = $reg['intercepto'] + $reg['pendiente'] * $i;
			$acc[ $mm ][] = $valores[ $i ] - $est;
		}
		$fac = array();
		foreach ( $acc as $mm => $devs ) {
			$fac[ $mm ] = array_sum( $devs ) / max( 1, count( $devs ) );
		}
		return $fac;
	}

	/**
	 * Probabilidad (0–100) de que un valor supere un umbral, dada la
	 * incertidumbre σ, asumiendo una distribución normal.
	 *
	 * @param float $valor  Valor actual o proyectado.
	 * @param float $umbral Umbral de alerta.
	 * @param float $sigma  Incertidumbre (desviación estándar).
	 * @return float Probabilidad 0–100.
	 */
	public static function prob_supera_umbral( $valor, $umbral, $sigma ) {
		$s = max( 0.01, (float) $sigma );
		$z = ( (float) $umbral - (float) $valor ) / $s;
		return round( ( 1.0 - MAN_Forecast::cdf_normal( $z ) ) * 100.0, 0 );
	}

	/**
	 * Tendencia textual a partir de una pendiente (con unidad opcional).
	 *
	 * @param float  $pendiente Pendiente por paso.
	 * @param string $unidad    Unidad.
	 * @return string
	 */
	public static function etiqueta_tendencia( $pendiente, $unidad = '' ) {
		$p = (float) $pendiente;
		if ( abs( $p ) < 1e-6 ) {
			return 'sin tendencia apreciable';
		}
		$u = $unidad ? ' ' . $unidad : '';
		return ( $p > 0 ? 'tendencia al alza' : 'tendencia a la baja' ) . ' (' . ( $p > 0 ? '+' : '' ) . number_format_i18n( $p, 3 ) . $u . ' por paso)';
	}
}
