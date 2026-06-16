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

			// --- Fase 2: vistas sectoriales ---
			'deficit_municipios' => array(
				'name'        => 'Déficit hídrico por municipio (real)',
				'description' => 'Índice de déficit hídrico (0–100) por municipio, derivado de Open-Meteo.',
				'category'    => 'categorical',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'deficit' ),
				'default'     => 'bar',
			),
			'focos_municipios'   => array(
				'name'        => 'Focos de calor por municipio (real)',
				'description' => 'Focos de calor activos (NASA FIRMS) por municipio, últimos días.',
				'category'    => 'categorical',
				'dimensions'  => array( 'municipio' ),
				'measures'    => array( 'focos' ),
				'default'     => 'bar',
			),
			'deficit_serie'      => array(
				'name'        => 'Déficit hídrico mensual',
				'description' => 'Evolución mensual del déficit hídrico departamental (escenario).',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'deficit' ),
				'default'     => 'line',
			),
			'precip_caudal'      => array(
				'name'        => 'Precipitación y nivel de caudal',
				'description' => 'Precipitación (mm) y nivel de caudal (%) mes a mes (escenario).',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes', 'serie' ),
				'measures'    => array( 'valor' ),
				'default'     => 'line',
			),
			'focos_serie'        => array(
				'name'        => 'Focos de calor mensuales',
				'description' => 'Número de focos de calor por mes (escenario).',
				'category'    => 'statistical',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'focos' ),
				'default'     => 'bar',
			),
			'cultivos_riesgo'    => array(
				'name'        => 'Área de cultivos en riesgo',
				'description' => 'Porcentaje de área de cultivos en riesgo, mes a mes (escenario).',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'cultivos_pct' ),
				'default'     => 'line',
			),
			'acueductos'         => array(
				'name'        => 'Acueductos en racionamiento',
				'description' => 'Número de municipios con acueductos en racionamiento por mes (escenario).',
				'category'    => 'statistical',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'acueductos' ),
				'default'     => 'bar',
			),
			'hidro_reduccion'    => array(
				'name'        => 'Reducción hidroeléctrica',
				'description' => 'Reducción de generación hidroeléctrica (%) mes a mes (escenario).',
				'category'    => 'temporal',
				'dimensions'  => array( 'mes' ),
				'measures'    => array( 'reduccion_pct' ),
				'default'     => 'line',
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
		// Vistas de magnitud que se colorean como mapa de calor (barras/treemap).
		$heatmap = in_array( $id, array( 'deficit_municipios', 'focos_municipios', 'focos_serie', 'acueductos', 'riesgo_municipios', 'riesgo_subregion', 'episodios' ), true );
		return array(
			'id'              => $id,
			'name'            => $m['name'],
			'description'     => $m['description'],
			'descripcion_larga' => self::descripcion_larga( $id ),
			'category'        => $m['category'],
			'dimensions'      => $m['dimensions'],
			'measures'        => $m['measures'],
			'data'            => $datos,
			'analisis'        => self::analisis( $id, $datos ),
			'como_funciona'   => self::como_funciona( $id ),
			'heatmap'         => $heatmap,
		);
	}

	/**
	 * Textos largos (descripción y análisis, ≥375 caracteres) por vista,
	 * cargados de includes/data/textos-graficos.php (cacheados en memoria).
	 *
	 * @return array<string,array{descripcion:string,analisis:string}>
	 */
	private static function textos_largos() {
		static $t = null;
		if ( null === $t ) {
			$ruta = MAN_DIR . 'includes/data/textos-graficos.php';
			$t    = is_readable( $ruta ) ? include $ruta : array();
			if ( ! is_array( $t ) ) {
				$t = array();
			}
		}
		return $t;
	}

	/**
	 * Descripción larga (≥375 caracteres) de una vista, para [man_descripcion].
	 *
	 * @param string $id Id de la vista.
	 * @return string
	 */
	public static function descripcion_larga( $id ) {
		$t = self::textos_largos();
		return isset( $t[ $id ]['descripcion'] ) ? $t[ $id ]['descripcion'] : '';
	}

	/**
	 * Análisis cualitativo largo (≥375 caracteres) de una vista.
	 *
	 * @param string $id Id de la vista.
	 * @return string
	 */
	public static function analisis_largo( $id ) {
		$t = self::textos_largos();
		return isset( $t[ $id ]['analisis'] ) ? $t[ $id ]['analisis'] : '';
	}

	/**
	 * Explicación "¿Cómo funciona?" de una vista: qué calcula, con qué fuente y
	 * cómo leerla. Pensada para el botón/modal de explicación de cada gráfico.
	 *
	 * @param string $id Id de la vista.
	 * @return string
	 */
	public static function como_funciona( $id ) {
		$map = array(
			'oni_serie'          => 'El ONI (Oceanic Niño Index) es la anomalía de temperatura del Pacífico ecuatorial (región Niño-3.4), promediada en ventanas de 3 meses. NOAA lo mide (observado) y el plugin proyecta el tramo futuro. Umbrales: ≥ +0,5 °C El Niño, ≤ −0,5 °C La Niña. Fuente: NOAA/CPC.',
			'oni_observado'      => 'Serie del ONI ya MEDIDO por NOAA/CPC (sin proyección). Cada punto es el promedio trimestral de la anomalía del Pacífico Niño-3.4. Fuente: NOAA/CPC.',
			'oni_pronostico'     => 'Tramo PROYECTADO del ONI: combina el ensamble oficial NOAA/IRI con el modelo propio del plugin (tendencia amortiguada). La incertidumbre crece con el horizonte. No es un pronóstico oficial.',
			'prob_fase'          => 'Probabilidad de cada fase (El Niño / Neutral / La Niña) por trimestre móvil, integrando una gaussiana N(valor proyectado, incertidumbre) sobre los umbrales NOAA ±0,5 °C. Las tres suman 100%.',
			'riesgo_subregion'   => 'Riesgo ambiental medio (0–1) por subregión: promedia el índice de los municipios de cada subregión. El índice combina empuje ENSO, anomalía de lluvia, exposición y sensibilidad sectorial.',
			'riesgo_municipios'  => 'Los 15 municipios con mayor índice de riesgo (0–1) en el mes elegido. El índice pondera ENSO, anomalía de lluvia (déficit real cuando hay), exposición y sector.',
			'episodios'          => 'Compara los episodios históricos de El Niño (2015–2024) por su ONI pico. Útil para dimensionar el evento actual frente a los pasados.',
			'deficit_municipios' => 'Déficit hídrico (0–100) por municipio: se deriva EN VIVO de la precipitación reciente de Open-Meteo frente a un umbral climatológico. 100 = sin lluvia respecto a lo esperado. Dato real.',
			'focos_municipios'   => 'Focos de calor activos por municipio detectados por satélite (NASA FIRMS, VIIRS/MODIS) en los últimos días, asignados a cada municipio por su polígono. Dato real.',
			'deficit_serie'      => 'Evolución mensual del déficit hídrico departamental del escenario de planeación. Modelado (semilla), no medición directa.',
			'precip_caudal'      => 'Precipitación (mm) y nivel de caudal (%) mes a mes del escenario. Dos series superpuestas para ver cómo caen juntas en El Niño. Modelado.',
			'focos_serie'        => 'Número de focos de calor por mes del escenario de planeación. Modelado.',
			'cultivos_riesgo'    => 'Porcentaje de área de cultivos en riesgo por mes del escenario. Modelado, en función del déficit hídrico.',
			'acueductos'         => 'Número de municipios con acueductos en racionamiento por mes del escenario. Modelado.',
			'hidro_reduccion'    => 'Reducción de generación hidroeléctrica (%) por mes del escenario, asociada al déficit de lluvias. Modelado.',
		);
		return isset( $map[ $id ] ) ? $map[ $id ] : '';
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

			case 'deficit_municipios':
				$desc = 'Déficit hídrico por municipio (0–100), derivado en tiempo real de la precipitación de Open-Meteo. Dato REAL.';
				if ( $n ) {
					$cuant = sprintf( 'Mayor déficit: %s (%s/100). %d municipios con dato real.', $datos[0]['municipio'], number_format_i18n( (float) $datos[0]['deficit'], 0 ), $n );
				} else {
					$cuant = 'Aún sin datos: sincronice la fuente «Déficit hídrico (Open-Meteo)».';
				}
				break;

			case 'focos_municipios':
				$desc = 'Focos de calor activos por municipio (NASA FIRMS, últimos días). Dato REAL.';
				if ( $n ) {
					$cuant = sprintf( 'Más focos: %s (%s). %d municipios con focos.', $datos[0]['municipio'], number_format_i18n( (float) $datos[0]['focos'], 0 ), $n );
				} else {
					$cuant = 'Aún sin datos: configure la MAP_KEY y sincronice «NASA FIRMS».';
				}
				break;

			case 'deficit_serie':
			case 'precip_caudal':
			case 'focos_serie':
			case 'cultivos_riesgo':
			case 'acueductos':
			case 'hidro_reduccion':
				$desc  = 'Serie mensual de planeación (escenario MODELADO). Verificar contra boletines vigentes de IDEAM y NOAA-CPC.';
				$cuant = $n ? sprintf( 'Serie de %d puntos mensuales.', $n ) : 'Sin datos de semilla disponibles.';
				break;
		}

		// El párrafo cualitativo usa el texto largo (≥375 car.) cuando existe.
		$largo = self::analisis_largo( $id );
		if ( '' !== $largo ) {
			$desc = $largo;
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

			case 'deficit_municipios':
				return self::filas_municipio_real( 'deficit_municipios', 'deficit', 'municipio' );

			case 'focos_municipios':
				return self::filas_municipio_real( 'focos_calor', null, 'municipio' );

			case 'deficit_serie':
				return self::filas_serie_semilla( 'ambiental', 'deficit_hidrico', 'deficit' );

			case 'focos_serie':
				return self::filas_serie_semilla( 'ambiental', 'focos_calor', 'focos' );

			case 'cultivos_riesgo':
				return self::filas_serie_semilla( 'agricola', 'area_cultivos_en_riesgo_pct', 'cultivos_pct' );

			case 'acueductos':
				return self::filas_serie_semilla( 'recursos', 'acueductos_en_racionamiento', 'acueductos' );

			case 'hidro_reduccion':
				return self::filas_serie_semilla( 'recursos', 'reduccion_hidroelectrica_pct', 'reduccion_pct' );

			case 'precip_caudal':
				$rows  = array();
				foreach ( self::meses_semilla() as $m ) {
					$a = isset( $m['indicadores']['ambiental'] ) ? $m['indicadores']['ambiental'] : array();
					if ( isset( $a['precipitacion_mm'] ) ) {
						$rows[] = array( 'mes' => $m['mes'], 'serie' => 'Precipitación (mm)', 'valor' => (float) $a['precipitacion_mm'] );
					}
					if ( isset( $a['nivel_caudal_pct'] ) ) {
						$rows[] = array( 'mes' => $m['mes'], 'serie' => 'Caudal (%)', 'valor' => (float) $a['nivel_caudal_pct'] );
					}
				}
				return $rows;
		}
		return array();
	}

	/**
	 * Meses de la semilla narino (para series sectoriales modeladas).
	 *
	 * @return array[]
	 */
	private static function meses_semilla() {
		$globo = MAN_Cache::semilla( 'datos_globo_elnino_narino_2026.json' );
		return ( is_array( $globo ) && ! empty( $globo['narino']['meses'] ) ) ? $globo['narino']['meses'] : array();
	}

	/**
	 * Serie temporal {mes, $medida} de un indicador de la semilla.
	 *
	 * @param string $bloque    ambiental|agricola|salud|recursos.
	 * @param string $indicador Clave del indicador.
	 * @param string $medida    Nombre de la medida de salida.
	 * @return array[]
	 */
	private static function filas_serie_semilla( $bloque, $indicador, $medida ) {
		$rows = array();
		foreach ( self::meses_semilla() as $m ) {
			$b = isset( $m['indicadores'][ $bloque ] ) ? $m['indicadores'][ $bloque ] : array();
			if ( isset( $b[ $indicador ] ) && is_numeric( $b[ $indicador ] ) ) {
				$rows[] = array( 'mes' => $m['mes'], $medida => (float) $b[ $indicador ] );
			}
		}
		return $rows;
	}

	/**
	 * Top 15 municipios desde una caché real {por_muni: cod => valor|{$medida}}.
	 *
	 * @param string      $clave_cache Clave de caché (deficit_municipios|focos_calor).
	 * @param string|null $subcampo    Subcampo del valor, o null si el valor es escalar.
	 * @param string      $dim         Nombre de la dimensión (municipio).
	 * @return array[]
	 */
	private static function filas_municipio_real( $clave_cache, $subcampo, $dim ) {
		$cache = MAN_Cache::get( $clave_cache );
		$medida = ( 'focos_calor' === $clave_cache ) ? 'focos' : 'deficit';
		$rows   = array();
		if ( ! is_array( $cache ) || empty( $cache['por_muni'] ) ) {
			return $rows;
		}
		foreach ( $cache['por_muni'] as $cod => $v ) {
			$valor = ( null === $subcampo ) ? $v : ( isset( $v[ $subcampo ] ) ? $v[ $subcampo ] : null );
			if ( null === $valor || ! is_numeric( $valor ) ) {
				continue;
			}
			$mun    = MAN_Municipios::por_divipola( (string) $cod );
			$rows[] = array( $dim => $mun ? $mun['nombre'] : (string) $cod, $medida => (int) $valor );
		}
		usort( $rows, function ( $a, $b ) use ( $medida ) {
			return (int) $b[ $medida ] <=> (int) $a[ $medida ];
		} );
		return array_slice( $rows, 0, 15 );
	}
}
