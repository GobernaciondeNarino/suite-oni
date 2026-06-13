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
}
