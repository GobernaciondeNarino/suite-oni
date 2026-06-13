<?php
/**
 * Pronóstico probabilístico de fase ENSO, regresión y proyección del ONI
 * (Sección 5.5 de la especificación).
 *
 * Métodos predictivos transparentes y auditables. NO pretenden sustituir las
 * plumas oficiales del IRI/CPC; se contrastan con ellas y SIEMPRE comunican la
 * incertidumbre (banda creciente con el horizonte + barrera de predictibilidad
 * de la primavera boreal).
 *
 * Modelo de proyección: tendencia lineal amortiguada (estilo Holt «damped
 * trend») estimada por mínimos cuadrados sobre la cola observada del ONI, con
 * reversión a la media climatológica (ENSO-neutral). La clasificación de fase
 * proyectada usa una gaussiana centrada en el valor previsto con desviación
 * igual a la semibanda de incertidumbre.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Forecast {

	/* ================================================================= */
	/* Suavizado                                                         */
	/* ================================================================= */

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

	/* ================================================================= */
	/* Regresión lineal (mínimos cuadrados)                              */
	/* ================================================================= */

	/**
	 * Ajusta una recta y = a + b·x por mínimos cuadrados ordinarios sobre la
	 * serie (x = 0,1,2,… índice temporal). Devuelve pendiente, intercepto y R².
	 *
	 * @param float[] $y Valores en orden cronológico.
	 * @return array {pendiente, intercepto, r2, n}
	 */
	public static function regresion_lineal( array $y ) {
		$y = array_values( array_map( 'floatval', $y ) );
		$n = count( $y );
		if ( $n < 2 ) {
			return array(
				'pendiente'  => 0.0,
				'intercepto' => $n ? $y[0] : 0.0,
				'r2'         => 0.0,
				'n'          => $n,
			);
		}

		$sx = $sy = $sxx = $sxy = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$sx  += $i;
			$sy  += $y[ $i ];
			$sxx += $i * $i;
			$sxy += $i * $y[ $i ];
		}
		$den = ( $n * $sxx ) - ( $sx * $sx );
		if ( 0.0 === $den ) {
			return array(
				'pendiente'  => 0.0,
				'intercepto' => $sy / $n,
				'r2'         => 0.0,
				'n'          => $n,
			);
		}
		$b = ( ( $n * $sxy ) - ( $sx * $sy ) ) / $den;
		$a = ( $sy - ( $b * $sx ) ) / $n;

		// Coeficiente de determinación R².
		$media = $sy / $n;
		$ss_tot = $ss_res = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$est     = $a + ( $b * $i );
			$ss_res += ( $y[ $i ] - $est ) * ( $y[ $i ] - $est );
			$ss_tot += ( $y[ $i ] - $media ) * ( $y[ $i ] - $media );
		}
		$r2 = ( $ss_tot > 0 ) ? max( 0.0, 1.0 - ( $ss_res / $ss_tot ) ) : 0.0;

		return array(
			'pendiente'  => $b,
			'intercepto' => $a,
			'r2'         => round( $r2, 3 ),
			'n'          => $n,
		);
	}

	/* ================================================================= */
	/* Proyección del ONI (tendencia amortiguada + reversión a la media) */
	/* ================================================================= */

	/**
	 * Proyecta el ONI desde la última observación hasta el mes objetivo.
	 *
	 * Modelo: nivel_{h} = nivel_{h-1} + b·φ^{h} − λ·(nivel_{h-1} − μ)
	 *   · b   pendiente estimada por regresión sobre la cola observada,
	 *   · φ   amortiguamiento del término de tendencia (0<φ<1),
	 *   · λ   tasa de reversión hacia la climatología μ (≈0, ENSO-neutral),
	 *   · banda σ_h = σ0 + α·√h, ampliada en la barrera de primavera boreal (MAM).
	 *
	 * @param array  $observado Serie observada [{mes, oni}, …] cronológica.
	 * @param string $hasta     Mes objetivo AAAA-MM (incluido).
	 * @param array  $opts      {cola, phi, lambda, mu, sigma0, alfa}.
	 * @return array[] Filas proyectadas [{mes, oni, banda_min, banda_max, sigma, horizonte, barrera}].
	 */
	public static function proyectar_oni( array $observado, $hasta, array $opts = array() ) {
		$o = array_merge(
			array(
				'cola'   => 6,     // meses observados usados para la pendiente.
				'phi'    => 0.82,  // amortiguamiento de la tendencia.
				'lambda' => 0.10,  // reversión mensual a la media.
				'mu'     => 0.0,   // climatología (ENSO-neutral).
				'sigma0' => 0.18,  // incertidumbre del primer mes.
				'alfa'   => 0.16,  // crecimiento de la incertidumbre por √horizonte.
			),
			$opts
		);

		// Normaliza la serie observada (orden cronológico, numérica).
		$serie = array();
		foreach ( $observado as $p ) {
			if ( isset( $p['mes'], $p['oni'] ) ) {
				$serie[ $p['mes'] ] = (float) $p['oni'];
			}
		}
		if ( empty( $serie ) ) {
			return array();
		}
		ksort( $serie );
		$meses_obs = array_keys( $serie );
		$valores   = array_values( $serie );
		$ult_mes   = end( $meses_obs );

		if ( null === $hasta || $hasta <= $ult_mes ) {
			return array();
		}

		// Pendiente sobre la cola observada.
		$cola  = array_slice( $valores, -max( 2, (int) $o['cola'] ) );
		$reg   = self::regresion_lineal( $cola );
		$b     = (float) $reg['pendiente'];
		$nivel = (float) end( $valores );

		$salida = array();
		$mes    = $ult_mes;
		$h      = 0;
		// Tope de seguridad: nunca más de 60 meses hacia adelante.
		while ( $mes < $hasta && $h < 60 ) {
			$mes = self::sumar_meses( $mes, 1 );
			$h++;

			// Tendencia amortiguada + reversión a la media.
			$paso_tendencia = $b * pow( (float) $o['phi'], $h );
			$reversion      = (float) $o['lambda'] * ( $nivel - (float) $o['mu'] );
			$nivel          = $nivel + $paso_tendencia - $reversion;

			// Banda de incertidumbre creciente; barrera de primavera boreal (MAM).
			$mm      = (int) substr( $mes, 5, 2 );
			$barrera = in_array( $mm, array( 3, 4, 5 ), true );
			$sigma   = (float) $o['sigma0'] + (float) $o['alfa'] * sqrt( $h );
			if ( $barrera ) {
				$sigma += 0.12; // menor predictibilidad en primavera boreal.
			}

			$salida[] = array(
				'mes'       => $mes,
				'oni'       => round( $nivel, 2 ),
				'banda_min' => round( $nivel - $sigma, 2 ),
				'banda_max' => round( $nivel + $sigma, 2 ),
				'sigma'     => round( $sigma, 3 ),
				'horizonte' => $h,
				'barrera'   => $barrera,
			);
		}
		return $salida;
	}

	/* ================================================================= */
	/* Probabilidad de fase                                              */
	/* ================================================================= */

	/**
	 * Probabilidad de cada fase a partir del ONI (logística suave, normalizada).
	 * Se conserva por compatibilidad; para proyecciones use probabilidad_gaussiana().
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
	 * Probabilidad de fase integrando una gaussiana N(oni, σ) sobre los
	 * umbrales NOAA ±0,5 °C. Es el método honesto para una proyección: la
	 * incertidumbre (σ) reparte la probabilidad entre fases.
	 *
	 * @param float $oni   Valor central proyectado.
	 * @param float $sigma Semibanda de incertidumbre (desviación estándar).
	 * @return array {el_nino, neutral, la_nina} en porcentaje 0..100.
	 */
	public static function probabilidad_gaussiana( $oni, $sigma ) {
		$m = (float) $oni;
		$s = max( 0.05, (float) $sigma );

		$p_nino = 1.0 - self::cdf_normal( ( 0.5 - $m ) / $s );   // P(X >= +0.5)
		$p_nina = self::cdf_normal( ( -0.5 - $m ) / $s );        // P(X <= -0.5)
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
	 * Función de distribución acumulada de la normal estándar N(0,1).
	 * Aproximación de Abramowitz & Stegun 7.1.26 (error < 7,5·10⁻⁸).
	 *
	 * @param float $z Valor tipificado.
	 * @return float Probabilidad 0..1.
	 */
	public static function cdf_normal( $z ) {
		$z = (float) $z;
		// erf(x) por A&S 7.1.26.
		$x    = abs( $z ) / sqrt( 2.0 );
		$t    = 1.0 / ( 1.0 + 0.3275911 * $x );
		$y    = 1.0 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( - $x * $x );
		$erf  = ( $z >= 0 ) ? $y : -$y;
		return 0.5 * ( 1.0 + $erf );
	}

	/* ================================================================= */
	/* Banda de incertidumbre                                            */
	/* ================================================================= */

	/**
	 * Banda de incertidumbre simétrica para un valor pronosticado.
	 * Crece con el horizonte (días hacia el futuro) — honestidad estadística.
	 *
	 * @param float $valor          Valor central.
	 * @param int   $dias_horizonte Días hacia adelante.
	 * @param float $base           Amplitud base de la banda.
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

	/* ================================================================= */
	/* Utilidades de calendario                                          */
	/* ================================================================= */

	/**
	 * Suma (o resta) meses a un AAAA-MM y devuelve AAAA-MM.
	 *
	 * @param string $mes  AAAA-MM.
	 * @param int    $delta Meses a sumar (puede ser negativo).
	 * @return string AAAA-MM.
	 */
	public static function sumar_meses( $mes, $delta ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $mes, $m ) ) {
			return (string) $mes;
		}
		$total = ( (int) $m[1] ) * 12 + ( (int) $m[2] - 1 ) + (int) $delta;
		$anio  = intdiv( $total, 12 );
		$mm    = ( $total % 12 ) + 1;
		return sprintf( '%04d-%02d', $anio, $mm );
	}

	/**
	 * Número de meses calendario entre dos AAAA-MM (b − a).
	 *
	 * @param string $a AAAA-MM inicial.
	 * @param string $b AAAA-MM final.
	 * @return int
	 */
	public static function meses_entre( $a, $b ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})$/', (string) $a, $ma ) ||
			! preg_match( '/^(\d{4})-(\d{2})$/', (string) $b, $mb ) ) {
			return 0;
		}
		return ( (int) $mb[1] * 12 + (int) $mb[2] ) - ( (int) $ma[1] * 12 + (int) $ma[2] );
	}
}
