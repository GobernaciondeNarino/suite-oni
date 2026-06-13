<?php
/**
 * Generación automática de texto de análisis en lenguaje claro (Sección 5.4).
 *
 * Plantillas + reglas, sin IA externa: funciona offline. Tono institucional,
 * claro y honesto con la incertidumbre.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Texto {

	/**
	 * Convierte AAAA-MM en "mes de AAAA" en español.
	 *
	 * @param string $mes AAAA-MM.
	 * @return string
	 */
	public static function mes_largo( $mes ) {
		$meses = array(
			1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
			5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
			9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
		);
		if ( preg_match( '/^(\d{4})-(\d{2})$/', (string) $mes, $m ) ) {
			$num = (int) $m[2];
			$txt = isset( $meses[ $num ] ) ? $meses[ $num ] : '';
			return trim( $txt . ' de ' . $m[1] );
		}
		return (string) $mes;
	}

	/**
	 * Texto de análisis para el estado de un municipio o el departamento.
	 *
	 * @param array $d {nombre, mes, oni, fase, intensidad, anom_t,
	 *                  anom_lluvia, riesgo, nivel_etiqueta, subregion, litoral}.
	 * @return string
	 */
	public static function estado( array $d ) {
		$nombre    = isset( $d['nombre'] ) ? $d['nombre'] : 'el departamento';
		$mes       = self::mes_largo( isset( $d['mes'] ) ? $d['mes'] : '' );
		$oni       = isset( $d['oni'] ) ? (float) $d['oni'] : 0.0;
		$fase      = isset( $d['fase'] ) ? $d['fase'] : MAN_Enso::clasificar_fase( $oni );
		$intens    = isset( $d['intensidad'] ) ? $d['intensidad'] : MAN_Enso::intensidad( $oni );
		$riesgo    = isset( $d['riesgo'] ) ? (float) $d['riesgo'] : 0.0;
		$nivel     = isset( $d['nivel_etiqueta'] ) ? $d['nivel_etiqueta'] : '';
		$litoral   = ! empty( $d['litoral'] );

		// Frase 1: fase ENSO vigente.
		$signo_oni = ( $oni >= 0 ? '+' : '' ) . number_format_i18n( $oni, 1 );
		if ( 'Neutral' === $fase ) {
			$transicion = $oni > 0.2 ? ', en transición hacia El Niño' : ( $oni < -0.2 ? ', en transición hacia La Niña' : '' );
			$f1 = sprintf( 'En %s el índice ONI es %s °C (fase neutral%s).', $mes, $signo_oni, $transicion );
		} else {
			$f1 = sprintf( 'En %s el índice ONI es %s °C: fase %s de intensidad %s.', $mes, $signo_oni, $fase, $intens );
		}

		// Frase 2: efecto esperado según subregión (signo diferenciado).
		if ( 'El Niño' === $fase ) {
			$f2 = $litoral
				? sprintf( 'En %s (litoral Pacífico) se favorecen lluvias por encima de lo normal y mayor oleaje.', $nombre )
				: sprintf( 'Para %s se favorece temperatura sobre lo normal y lluvias por debajo del promedio (riesgo de sequía e incendios).', $nombre );
		} elseif ( 'La Niña' === $fase ) {
			$f2 = $litoral
				? sprintf( 'En %s (litoral Pacífico) el oleaje tiende a moderarse, con lluvias cercanas a lo normal.', $nombre )
				: sprintf( 'Para %s se favorecen lluvias por encima del promedio (riesgo de deslizamientos y crecientes).', $nombre );
		} else {
			$f2 = sprintf( 'Para %s se esperan condiciones cercanas a lo normal.', $nombre );
		}

		// Frase 3: riesgo municipal.
		$f3 = '';
		if ( $riesgo > 0 || '' !== $nivel ) {
			$f3 = sprintf( ' Riesgo municipal: %s (%s).', strtolower( $nivel ), number_format_i18n( $riesgo, 2 ) );
		}

		return $f1 . ' ' . $f2 . $f3;
	}

	/**
	 * Texto de análisis para el pronóstico a varios días.
	 *
	 * @param array $d {nombre, dias, t_max_prom, p_total, tendencia, fase}.
	 * @return string
	 */
	public static function pronostico( array $d ) {
		$nombre = isset( $d['nombre'] ) ? $d['nombre'] : 'el municipio';
		$dias   = isset( $d['dias'] ) ? (int) $d['dias'] : 7;
		$tmax   = isset( $d['t_max_prom'] ) ? number_format_i18n( (float) $d['t_max_prom'], 1 ) : null;
		$ptot   = isset( $d['p_total'] ) ? number_format_i18n( (float) $d['p_total'], 0 ) : null;
		$tend   = isset( $d['tendencia'] ) ? $d['tendencia'] : '';

		$partes = array( sprintf( 'Pronóstico a %d días para %s.', $dias, $nombre ) );
		if ( null !== $tmax ) {
			$partes[] = sprintf( 'Temperatura máxima media de %s °C.', $tmax );
		}
		if ( null !== $ptot ) {
			$partes[] = sprintf( 'Precipitación acumulada estimada de %s mm.', $ptot );
		}
		if ( '' !== $tend ) {
			$partes[] = $tend;
		}
		$partes[] = 'Las cifras a más de 7 días son probables, no certezas: consulte el boletín vigente del IDEAM.';

		return implode( ' ', $partes );
	}

	/**
	 * Texto predictivo de la trayectoria del ONI hasta el mes objetivo.
	 *
	 * Tono institucional, claro y honesto con la incertidumbre. Describe el
	 * estado vigente, el pico previsto, la fase y probabilidad en el objetivo
	 * (p. ej. febrero de 2027) y la advertencia de predictibilidad.
	 *
	 * @param array $d {actual, objetivo, pico} con sub-arreglos {mes, oni, fase,
	 *                 intensidad, prob:{el_nino,neutral,la_nina}}.
	 * @return string
	 */
	public static function prediccion( array $d ) {
		$act = isset( $d['actual'] ) ? $d['actual'] : array();
		$obj = isset( $d['objetivo'] ) ? $d['objetivo'] : array();
		$pic = isset( $d['pico'] ) ? $d['pico'] : array();

		$partes = array();

		// Frase 1: punto de partida (estado vigente).
		if ( ! empty( $act['mes'] ) ) {
			$oni_a   = isset( $act['oni'] ) ? (float) $act['oni'] : 0.0;
			$signo_a = ( $oni_a >= 0 ? '+' : '' ) . number_format_i18n( $oni_a, 1 );
			$fase_a  = isset( $act['fase'] ) ? $act['fase'] : MAN_Enso::clasificar_fase( $oni_a );
			$partes[] = sprintf(
				'Punto de partida: en %s el ONI es %s °C (fase %s).',
				self::mes_largo( $act['mes'] ),
				$signo_a,
				$fase_a
			);
		}

		// Frase 2: pico previsto en la ventana proyectada.
		if ( ! empty( $pic['mes'] ) ) {
			$oni_p   = isset( $pic['oni'] ) ? (float) $pic['oni'] : 0.0;
			$signo_p = ( $oni_p >= 0 ? '+' : '' ) . number_format_i18n( $oni_p, 1 );
			$fase_p  = isset( $pic['fase'] ) ? $pic['fase'] : MAN_Enso::clasificar_fase( $oni_p );
			$inten_p = isset( $pic['intensidad'] ) ? $pic['intensidad'] : MAN_Enso::intensidad( $oni_p );
			if ( 'Neutral' !== $fase_p ) {
				$partes[] = sprintf(
					'El modelo proyecta el máximo en torno a %s, con ONI cercano a %s °C (fase %s, intensidad %s).',
					self::mes_largo( $pic['mes'] ),
					$signo_p,
					$fase_p,
					$inten_p
				);
			}
		}

		// Frase 3: situación en el mes objetivo + probabilidad dominante.
		if ( ! empty( $obj['mes'] ) ) {
			$oni_o   = isset( $obj['oni'] ) ? (float) $obj['oni'] : 0.0;
			$signo_o = ( $oni_o >= 0 ? '+' : '' ) . number_format_i18n( $oni_o, 1 );
			$fase_o  = isset( $obj['fase'] ) ? $obj['fase'] : MAN_Enso::clasificar_fase( $oni_o );
			$prob    = isset( $obj['prob'] ) ? $obj['prob'] : array();

			$dom_txt = '';
			if ( ! empty( $prob ) ) {
				$etq  = array( 'el_nino' => 'El Niño', 'neutral' => 'condiciones neutrales', 'la_nina' => 'La Niña' );
				$dom  = 'neutral';
				$maxv = -1;
				foreach ( array( 'el_nino', 'neutral', 'la_nina' ) as $k ) {
					$v = isset( $prob[ $k ] ) ? (float) $prob[ $k ] : 0.0;
					if ( $v > $maxv ) {
						$maxv = $v;
						$dom  = $k;
					}
				}
				$dom_txt = sprintf( ' La probabilidad de %s es de %s%%.', $etq[ $dom ], number_format_i18n( $maxv, 0 ) );
			}

			$banda_txt = '';
			if ( isset( $obj['banda']['min'], $obj['banda']['max'] ) ) {
				$banda_txt = sprintf(
					' (rango plausible %s a %s °C)',
					number_format_i18n( (float) $obj['banda']['min'], 1 ),
					number_format_i18n( (float) $obj['banda']['max'], 1 )
				);
			}

			$partes[] = sprintf(
				'Hacia %s se prevé un ONI cercano a %s °C%s: fase %s.%s',
				self::mes_largo( $obj['mes'] ),
				$signo_o,
				$banda_txt,
				$fase_o,
				$dom_txt
			);
		}

		// Frase 4: honestidad estadística (obligatoria).
		$partes[] = 'Proyección estadística del plugin (tendencia amortiguada con reversión a la media), contrastada con el ensamble oficial NOAA-CPC/IRI. La incertidumbre crece con el horizonte y aumenta al cruzar la primavera boreal: son escenarios probables, no certezas. Verifique los boletines vigentes de IDEAM y NOAA-CPC.';

		return implode( ' ', $partes );
	}
}
