<?php
/**
 * Registro de "vistas" para el motor de gráficos D3plus ([man_grafico]).
 *
 * Implementa el contrato vista→payload del skill (TIC Suite Gráficos): cada
 * vista declara dimensiones (campos categóricos), medidas (numéricos) y filas
 * de datos. Las filas se construyen reutilizando los constructores REST del
 * plugin, de modo que el motor de gráficos es 100% genérico y agnóstico al
 * dominio. La compatibilidad vista→tipo sigue la tabla del skill.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Views {

	/**
	 * Catálogo de tipos de gráfico → clase d3plus + etiqueta.
	 * (d3plus v3: no existe StackedBarChart; se usa BarChart + .stacked(true).)
	 *
	 * @return array<string,array{class:string,label:string}>
	 */
	public static function tipos() {
		return array(
			'bar'          => array( 'class' => 'BarChart', 'label' => 'Barras' ),
			'stacked_bar'  => array( 'class' => 'BarChart', 'label' => 'Barras apiladas' ),
			'line'         => array( 'class' => 'LinePlot', 'label' => 'Líneas' ),
			'area'         => array( 'class' => 'AreaPlot', 'label' => 'Área' ),
			'stacked_area' => array( 'class' => 'StackedArea', 'label' => 'Área apilada' ),
			'pie'          => array( 'class' => 'Pie', 'label' => 'Pastel' ),
			'donut'        => array( 'class' => 'Donut', 'label' => 'Dona' ),
			'treemap'      => array( 'class' => 'Treemap', 'label' => 'Treemap' ),
			'box_whisker'  => array( 'class' => 'BoxWhisker', 'label' => 'Caja y bigotes' ),
		);
	}

	/**
	 * Tipos compatibles según la categoría de la vista (subconjunto soportado
	 * por el renderer; sin geomap/red/sankey, que tienen su propio componente).
	 *
	 * @param string $category Categoría.
	 * @return string[]
	 */
	public static function compatibles( $category ) {
		switch ( $category ) {
			case 'temporal':
				return array( 'line', 'area', 'stacked_area' );
			case 'statistical':
				return array( 'bar', 'line', 'box_whisker' );
			case 'categorical':
			default:
				return array( 'bar', 'stacked_bar', 'pie', 'donut', 'treemap' );
		}
	}

	/**
	 * Metadatos de las vistas disponibles.
	 *
	 * @return array<string,array>
	 */
	private static function registro() {
		return array(
			'oni_serie'         => array(
				'name'        => 'Evolución del índice ONI',
				'description' => 'Índice ONI observado y proyectado, mes a mes.',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes', 'tipo' ),
				'measures'    => array( 'oni' ),
				'default'     => 'line',
			),
			'oni_observado'     => array(
				'name'        => 'ONI observado (histórico)',
				'description' => 'Índice ONI medido (NOAA/CPC), solo meses observados.',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'oni' ),
				'default'     => 'line',
			),
			'oni_pronostico'    => array(
				'name'        => 'ONI pronosticado',
				'description' => 'Índice ONI proyectado hacia el futuro (ensamble + modelo).',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'oni' ),
				'default'     => 'line',
			),
			'prob_fase'         => array(
				'name'        => 'Probabilidad de fase por trimestre',
				'description' => 'Probabilidad de El Niño / Neutral / La Niña por trimestre móvil.',
				'category'    => 'categorical',
				'dimensions'  => array( 'trimestre' ),
				'measures'    => array( 'el_nino', 'neutral', 'la_nina' ),
				'default'     => 'stacked_bar',
			),
			'riesgo_subregion'  => array(
				'name'        => 'Riesgo medio por subregión',
				'description' => 'Índice de riesgo ambiental promedio por subregión de Nariño.',
				'category'    => 'categorical',
				'dimensions'  => array( 'subregion' ),
				'measures'    => array( 'riesgo' ),
				'default'     => 'treemap',
			),
			'riesgo_municipios' => array(
				'name'        => 'Municipios con mayor riesgo',
				'description' => 'Los 15 municipios con mayor índice de riesgo en el mes.',
				'category'    => 'categorical',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'riesgo' ),
				'default'     => 'bar',
			),
			'episodios'         => array(
				'name'        => 'Episodios históricos de El Niño',
				'description' => 'ONI pico de cada episodio de El Niño (2015–2024).',
				'category'    => 'categorical',
				'dimensions'  => array( 'periodo' ),
				'measures'    => array( 'oni_pico' ),
				'default'     => 'bar',
			),
		);
	}

	/**
	 * Lista de vistas (para selector/catálogo).
	 *
	 * @return array[]
	 */
	public static function lista() {
		$out = array();
		foreach ( self::registro() as $id => $m ) {
			$out[] = array(
				'id'          => $id,
				'name'        => $m['name'],
				'description' => $m['description'],
				'category'    => $m['category'],
				'default'     => $m['default'],
				'compatibles' => self::compatibles( $m['category'] ),
			);
		}
		return $out;
	}

	/**
	 * ¿Existe la vista?
	 *
	 * @param string $id Id de la vista.
	 * @return bool
	 */
	public static function existe( $id ) {
		$r = self::registro();
		return isset( $r[ $id ] );
	}

	/**
	 * Tipo de gráfico por defecto de una vista.
	 *
	 * @param string $id Id de la vista.
	 * @return string
	 */
	public static function default_tipo( $id ) {
		$r = self::registro();
		return isset( $r[ $id ] ) ? $r[ $id ]['default'] : 'bar';
	}

	/**
	 * Devuelve la vista completa (metadatos + filas de datos).
	 *
	 * @param string $id   Id de la vista.
	 * @param array  $args {hasta, mes}.
	 * @return array|null
	 */
	public static function obtener( $id, $args = array() ) {
		$r = self::registro();
		if ( ! isset( $r[ $id ] ) ) {
			return null;
		}
		$m     = $r[ $id ];
		$datos = self::datos( $id, $args );
		return array(
			'id'          => $id,
			'name'        => $m['name'],
			'description' => $m['description'],
			'category'    => $m['category'],
			'dimensions'  => $m['dimensions'],
			'measures'    => $m['measures'],
			'data'        => $datos,
			'analisis'    => self::analisis( $id, $datos ),
		);
	}

	/**
	 * Análisis por vista: párrafo DESCRIPTIVO (qué muestra) + CUANTITATIVO
	 * (cifras clave calculadas del propio dato). Pensado para ciudadanía,
	 * investigadores y periodistas.
	 *
	 * @param string $id    Id de la vista.
	 * @param array  $datos Filas de la vista.
	 * @return array {descriptivo, cuantitativo}
	 */
	private static function analisis( $id, $datos ) {
		$n     = count( $datos );
		$desc  = '';
		$cuant = '';

		switch ( $id ) {
			case 'oni_serie':
			case 'oni_observado':
			case 'oni_pronostico':
				$desc = 'Índice oceánico de El Niño (ONI). El umbral de ±0,5 °C define las fases El Niño (cálida) y La Niña (fría); el resto es neutral. Fuente: NOAA/CPC (observado) y ensamble (proyectado).';
				if ( $n ) {
					$pico = $datos[0];
					$ult  = $datos[ $n - 1 ];
					foreach ( $datos as $f ) {
						if ( abs( (float) $f['oni'] ) > abs( (float) $pico['oni'] ) ) {
							$pico = $f;
						}
					}
					$cuant = sprintf(
						'Pico de %s °C en %s. Valor más reciente: %s °C (%s). Serie de %d meses.',
						self::signo( $pico['oni'] ),
						MAN_Texto::mes_largo( $pico['mes'] ),
						self::signo( $ult['oni'] ),
						MAN_Enso::clasificar_fase( $ult['oni'] ),
						$n
					);
				}
				break;

			case 'prob_fase':
				$desc = 'Probabilidad de cada fase ENSO por trimestre móvil, obtenida integrando una distribución normal sobre los umbrales NOAA de ±0,5 °C.';
				if ( $n ) {
					$mx = $datos[0];
					foreach ( $datos as $f ) {
						if ( (float) $f['el_nino'] > (float) $mx['el_nino'] ) {
							$mx = $f;
						}
					}
					$cuant = sprintf( 'Probabilidad máxima de El Niño: %s%% en %s.', number_format_i18n( (float) $mx['el_nino'], 0 ), $mx['trimestre'] );
				}
				break;

			case 'riesgo_subregion':
				$desc = 'Índice de riesgo ambiental medio por subregión de Nariño (0 a 1): combina afectación histórica, déficit hídrico, exposición (DANE) y régimen climático.';
				if ( $n ) {
					$top = $datos[0];
					$sum = 0.0;
					foreach ( $datos as $f ) {
						$sum += (float) $f['riesgo'];
						if ( (float) $f['riesgo'] > (float) $top['riesgo'] ) {
							$top = $f;
						}
					}
					$cuant = sprintf( 'Subregión más expuesta: %s (%s). Promedio departamental: %s.', $top['subregion'], number_format_i18n( (float) $top['riesgo'], 2 ), number_format_i18n( $sum / $n, 2 ) );
				}
				break;

			case 'riesgo_municipios':
				$desc = 'Municipios con mayor índice de riesgo en el mes seleccionado (escenario de planeación).';
				if ( $n ) {
					$top = $datos[0];
					foreach ( $datos as $f ) {
						if ( (float) $f['riesgo'] > (float) $top['riesgo'] ) {
							$top = $f;
						}
					}
					$cuant = sprintf( 'Mayor riesgo: %s (%s de 1,00).', $top['municipio'], number_format_i18n( (float) $top['riesgo'], 2 ) );
				}
				break;

			case 'episodios':
				$desc = 'Episodios de El Niño que afectaron a Nariño (2015–2024), comparados por su ONI pico.';
				if ( $n ) {
					$top = $datos[0];
					foreach ( $datos as $f ) {
						if ( (float) $f['oni_pico'] > (float) $top['oni_pico'] ) {
							$top = $f;
						}
					}
					$cuant = sprintf( 'Episodio más intenso: %s (ONI pico +%s °C).', $top['periodo'], number_format_i18n( (float) $top['oni_pico'], 1 ) );
				}
				break;
		}

		return array( 'descriptivo' => $desc, 'cuantitativo' => $cuant );
	}

	/** Formatea un número con signo (+/−) y una decimal. */
	private static function signo( $v ) {
		$v = (float) $v;
		return ( $v >= 0 ? '+' : '' ) . number_format_i18n( $v, 1 );
	}

	/**
	 * Construye las filas de datos de una vista (reutiliza los constructores REST).
	 *
	 * @param string $id   Id de la vista.
	 * @param array  $args {hasta, mes}.
	 * @return array[]
	 */
	private static function datos( $id, $args ) {
		$hasta = isset( $args['hasta'] ) ? $args['hasta'] : '2027-02';
		$mes   = isset( $args['mes'] ) ? $args['mes'] : gmdate( 'Y-m' );

		switch ( $id ) {
			case 'oni_serie':
				$oni        = MAN_Rest::construir_oni();
				$serie      = isset( $oni['serie'] ) ? $oni['serie'] : array();
				$rows       = array();
				$ultimo_obs = null;
				$puente     = false;
				foreach ( $serie as $s ) {
					$proy = ! empty( $s['proyectado'] );
					// Punto puente: conecta la línea observada con la proyectada (sin corte).
					if ( $proy && $ultimo_obs && ! $puente ) {
						$rows[] = array(
							'mes'  => $ultimo_obs['mes'],
							'tipo' => 'Proyectado',
							'oni'  => round( (float) $ultimo_obs['oni'], 2 ),
						);
						$puente = true;
					}
					$rows[] = array(
						'mes'  => $s['mes'],
						'tipo' => $proy ? 'Proyectado' : 'Observado',
						'oni'  => round( (float) $s['oni'], 2 ),
					);
					if ( ! $proy ) {
						$ultimo_obs = $s;
					}
				}
				return $rows;

			case 'oni_observado':
			case 'oni_pronostico':
				$dom  = ( 'oni_observado' === $id ) ? 'historico' : 'pronostico';
				$d    = MAN_Rest::construir_oni_dominio( $dom );
				$rows = array();
				foreach ( ( isset( $d['serie'] ) ? $d['serie'] : array() ) as $s ) {
					$rows[] = array( 'mes' => $s['mes'], 'oni' => round( (float) $s['oni'], 2 ) );
				}
				return $rows;

			case 'prob_fase':
				$pred = MAN_Rest::construir_prediccion( $hasta );
				$rows = array();
				foreach ( ( isset( $pred['prob_trimestres'] ) ? $pred['prob_trimestres'] : array() ) as $t ) {
					$rows[] = array(
						'trimestre' => $t['etiqueta'],
						'el_nino'   => (float) $t['el_nino'],
						'neutral'   => (float) $t['neutral'],
						'la_nina'   => (float) $t['la_nina'],
					);
				}
				return $rows;

			case 'riesgo_subregion':
				$dep = MAN_Rest::construir_departamento( $mes );
				$acc = array();
				foreach ( $dep as $m ) {
					$s = ( isset( $m['subregion'] ) && '' !== $m['subregion'] ) ? $m['subregion'] : 'Sin clasificar';
					if ( ! isset( $acc[ $s ] ) ) {
						$acc[ $s ] = array( 'suma' => 0.0, 'n' => 0 );
					}
					$acc[ $s ]['suma'] += (float) $m['riesgo'];
					$acc[ $s ]['n']++;
				}
				$rows = array();
				foreach ( $acc as $s => $a ) {
					$rows[] = array( 'subregion' => $s, 'riesgo' => round( $a['suma'] / max( 1, $a['n'] ), 3 ) );
				}
				usort( $rows, function ( $a, $b ) {
					return $b['riesgo'] <=> $a['riesgo'];
				} );
				return $rows;

			case 'riesgo_municipios':
				$dep = MAN_Rest::construir_departamento( $mes );
				usort( $dep, function ( $a, $b ) {
					return (float) $b['riesgo'] <=> (float) $a['riesgo'];
				} );
				$rows = array();
				foreach ( array_slice( $dep, 0, 15 ) as $m ) {
					$rows[] = array( 'municipio' => $m['nombre'], 'riesgo' => round( (float) $m['riesgo'], 3 ) );
				}
				return $rows;

			case 'episodios':
				$h    = MAN_Rest::construir_historico();
				$rows = array();
				foreach ( ( isset( $h['episodios'] ) ? $h['episodios'] : array() ) as $e ) {
					if ( ! isset( $e['periodo'] ) ) {
						continue;
					}
					$rows[] = array(
						'periodo'  => $e['periodo'],
						'oni_pico' => (float) ( isset( $e['oni_pico'] ) ? $e['oni_pico'] : 0 ),
					);
				}
				return $rows;
		}
		return array();
	}
}
