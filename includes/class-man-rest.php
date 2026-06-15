<?php
/**
 * REST interna /wp-json/man/v1/ (Capa 3 — presentación).
 *
 * Sirve datos ya procesados (fase, riesgo, texto) al front y expone los
 * endpoints públicos de DATOS ABIERTOS (/abierto/*) en JSON y CSV con
 * atribución CC BY 4.0, para ciudadanía, estudiantes e investigadores.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Rest {

	const NS = 'man/v1';

	/** @var array|null Semillas cacheadas en memoria por petición. */
	private static $pred  = null;
	private static $globo = null;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'registrar_rutas' ) );
	}

	/* ================================================================= */
	/* Registro de rutas                                                 */
	/* ================================================================= */

	public function registrar_rutas() {
		$publico = array( $this, 'permiso_publico' );

		register_rest_route( self::NS, '/oni', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_oni' ),
			'permission_callback' => $publico,
		) );

		register_rest_route( self::NS, '/departamento', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_departamento' ),
			'permission_callback' => $publico,
			'args'                => array( 'mes' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );

		register_rest_route( self::NS, '/municipio/(?P<divipola>[0-9]{5}|departamento)', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_municipio' ),
			'permission_callback' => $publico,
			'args'                => array( 'mes' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );

		register_rest_route( self::NS, '/estado-apis', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_estado_apis' ),
			'permission_callback' => $publico,
		) );

		register_rest_route( self::NS, '/historico', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_historico' ),
			'permission_callback' => $publico,
		) );

		register_rest_route( self::NS, '/prediccion', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_prediccion' ),
			'permission_callback' => $publico,
			'args'                => array( 'hasta' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );

		// Motor de gráficos D3plus ([man_grafico]).
		register_rest_route( self::NS, '/vistas', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_vistas' ),
			'permission_callback' => $publico,
		) );
		register_rest_route( self::NS, '/render', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_render' ),
			'permission_callback' => $publico,
			'args'                => array(
				'view'  => array( 'sanitize_callback' => 'sanitize_key' ),
				'type'  => array( 'sanitize_callback' => 'sanitize_key' ),
				'hasta' => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'mes'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );

		// Mapa de Nariño sobre el globo (riesgo mensual por municipio).
		register_rest_route( self::NS, '/mapa-narino', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_mapa_narino' ),
			'permission_callback' => $publico,
		) );

		register_rest_route( self::NS, '/mar', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_mar' ),
			'permission_callback' => $publico,
			'args'                => array( 'estacion' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );

		register_rest_route( self::NS, '/salud', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'ruta_salud' ),
			'permission_callback' => $publico,
		) );

		// --- Datos abiertos (JSON / CSV) ---
		register_rest_route( self::NS, '/abierto/municipios', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'abierto_municipios' ),
			'permission_callback' => $publico,
		) );
		register_rest_route( self::NS, '/abierto/oni', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'abierto_oni' ),
			'permission_callback' => $publico,
		) );
		register_rest_route( self::NS, '/abierto/prediccion', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'abierto_prediccion' ),
			'permission_callback' => $publico,
			'args'                => array( 'hasta' => array( 'sanitize_callback' => 'sanitize_text_field' ) ),
		) );
		register_rest_route( self::NS, '/abierto/(?P<divipola>[0-9]{5})', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'abierto_municipio' ),
			'permission_callback' => $publico,
		) );
	}

	/**
	 * Permiso público con rate-limit por IP (Sección 9.2/9.3).
	 *
	 * @return true|\WP_Error
	 */
	public function permiso_publico() {
		if ( ! MAN_Security::rate_limit( 'rest', 120, 60 ) ) {
			return new \WP_Error( 'man_rate_limit', 'Demasiadas peticiones. Intente en un minuto.', array( 'status' => 429 ) );
		}
		return true;
	}

	/* ================================================================= */
	/* Callbacks internos                                                */
	/* ================================================================= */

	public function ruta_oni() {
		return rest_ensure_response( self::construir_oni() );
	}

	public function ruta_departamento( $req ) {
		$mes = MAN_Security::sanitizar_mes( $req->get_param( 'mes' ) );
		return rest_ensure_response( self::construir_departamento( $mes ) );
	}

	public function ruta_municipio( $req ) {
		$divipola = MAN_Security::sanitizar_divipola( $req->get_param( 'divipola' ) );
		$mes      = MAN_Security::sanitizar_mes( $req->get_param( 'mes' ) );
		if ( 'departamento' === $divipola ) {
			return new \WP_Error( 'man_municipio', 'Use /departamento para el agregado.', array( 'status' => 400 ) );
		}
		$datos = self::construir_municipio( $divipola, $mes );
		if ( null === $datos ) {
			return new \WP_Error( 'man_no_encontrado', 'Municipio no encontrado.', array( 'status' => 404 ) );
		}
		return rest_ensure_response( $datos );
	}

	public function ruta_estado_apis() {
		return rest_ensure_response( self::construir_estado_apis() );
	}

	public function ruta_historico() {
		return rest_ensure_response( self::construir_historico() );
	}

	public function ruta_prediccion( $req ) {
		$hasta = self::sanitizar_objetivo( $req->get_param( 'hasta' ) );
		return rest_ensure_response( self::construir_prediccion( $hasta ) );
	}

	/**
	 * Lista de vistas disponibles para el motor de gráficos.
	 */
	public function ruta_vistas() {
		return rest_ensure_response( array( 'vistas' => MAN_Views::lista() ) );
	}

	/**
	 * Serie de riesgo mensual por municipio para la capa de mapa del globo.
	 */
	public function ruta_mapa_narino() {
		return rest_ensure_response( self::construir_mapa_narino() );
	}

	/**
	 * Payload de render para [man_grafico]: {chart, view, data, compatible}.
	 * Valida la vista (lista blanca) y restringe el tipo a los compatibles.
	 */
	public function ruta_render( $req ) {
		$view_id = sanitize_key( (string) $req->get_param( 'view' ) );
		if ( '' === $view_id || ! MAN_Views::existe( $view_id ) ) {
			return new \WP_Error( 'man_vista', 'Vista no encontrada.', array( 'status' => 404 ) );
		}

		$args = array(
			'hasta' => self::sanitizar_objetivo( $req->get_param( 'hasta' ) ),
			'mes'   => MAN_Security::sanitizar_mes( $req->get_param( 'mes' ) ),
		);
		$view = MAN_Views::obtener( $view_id, $args );

		$compatibles = MAN_Views::compatibles( $view['category'] );
		$tipo        = sanitize_key( (string) $req->get_param( 'type' ) );
		if ( '' === $tipo || ! in_array( $tipo, $compatibles, true ) ) {
			$tipo = MAN_Views::default_tipo( $view_id );
		}

		$tipos = MAN_Views::tipos();
		$chart = isset( $tipos[ $tipo ] ) ? $tipos[ $tipo ] : $tipos['bar'];
		$chart['key'] = $tipo;

		return rest_ensure_response( array(
			'chart'      => $chart,
			'view'       => array(
				'id'          => $view['id'],
				'name'        => $view['name'],
				'description' => $view['description'],
				'category'    => $view['category'],
				'dimensions'  => $view['dimensions'],
				'measures'    => $view['measures'],
			),
			'data'       => $view['data'],
			'mapping'    => array( 'links' => array() ),
			'compatible' => $compatibles,
		) );
	}

	public function ruta_mar( $req ) {
		$estacion = sanitize_text_field( (string) $req->get_param( 'estacion' ) );
		return rest_ensure_response( self::construir_mar( $estacion ) );
	}

	public function ruta_salud() {
		return rest_ensure_response( self::construir_salud() );
	}

	/* ================================================================= */
	/* Callbacks de datos abiertos                                       */
	/* ================================================================= */

	public function abierto_municipios( $req ) {
		$mes  = MAN_Security::sanitizar_mes( $req->get_param( 'mes' ) );
		$rows = self::construir_departamento( $mes );
		return self::responder_abierto(
			$req,
			$rows,
			'monitor-ambiental-narino_municipios_' . $mes,
			'Riesgo ambiental y fase ENSO por municipio de Nariño'
		);
	}

	public function abierto_oni( $req ) {
		$oni   = self::construir_oni();
		$serie = isset( $oni['serie'] ) ? $oni['serie'] : array();
		return self::responder_abierto(
			$req,
			$serie,
			'monitor-ambiental-narino_oni',
			'Serie del índice ONI (observado y proyectado)'
		);
	}

	public function abierto_prediccion( $req ) {
		$hasta = self::sanitizar_objetivo( $req->get_param( 'hasta' ) );
		$pred  = self::construir_prediccion( $hasta );
		$serie = isset( $pred['serie'] ) ? $pred['serie'] : array();
		return self::responder_abierto(
			$req,
			$serie,
			'monitor-ambiental-narino_prediccion_oni_' . $hasta,
			'Predicción del índice ONI (observado, ensamble oficial y modelo del plugin) hasta ' . $hasta
		);
	}

	public function abierto_municipio( $req ) {
		$divipola = MAN_Security::sanitizar_divipola( $req->get_param( 'divipola' ) );
		if ( 'departamento' === $divipola ) {
			return new \WP_Error( 'man_municipio', 'DIVIPOLA inválido.', array( 'status' => 400 ) );
		}
		$pred = self::predicciones();
		$serie = array();
		if ( ! empty( $pred['municipios'][ $divipola ]['serie_mensual'] ) ) {
			$serie = $pred['municipios'][ $divipola ]['serie_mensual'];
		}
		$mun = MAN_Municipios::por_divipola( $divipola );
		return self::responder_abierto(
			$req,
			$serie,
			'monitor-ambiental-narino_' . $divipola,
			'Serie mensual de ' . ( $mun ? $mun['nombre'] : $divipola )
		);
	}

	/**
	 * Devuelve un dataset abierto en JSON (envuelto con metadatos) o CSV.
	 *
	 * @param \WP_REST_Request $req    Petición.
	 * @param array            $filas  Filas del dataset.
	 * @param string           $nombre Nombre base del archivo.
	 * @param string           $titulo Título legible.
	 * @return \WP_REST_Response|void
	 */
	private static function responder_abierto( $req, $filas, $nombre, $titulo ) {
		$formato = strtolower( sanitize_text_field( (string) $req->get_param( 'formato' ) ) );

		if ( 'csv' === $formato ) {
			$csv = self::a_csv( is_array( $filas ) ? $filas : array() );
			if ( ! headers_sent() ) {
				status_header( 200 );
				header( 'Content-Type: text/csv; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $nombre . '.csv"' );
				header( 'X-Content-Type-Options: nosniff' );
				header( 'X-Licencia: CC BY 4.0 — Gobernación de Nariño / Open-Meteo / NOAA / IDEAM' );
			}
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		$respuesta = new \WP_REST_Response(
			array(
				'titulo'        => $titulo,
				'licencia'      => 'CC BY 4.0',
				'atribucion'    => 'Gobernación de Nariño · Secretaría TIC. Fuentes: Open-Meteo (CC BY 4.0), NOAA/CPC, IDEAM, NASA POWER, IOC.',
				'generado'      => current_time( 'mysql', true ),
				'total'         => is_array( $filas ) ? count( $filas ) : 0,
				'datos'         => $filas,
			),
			200
		);
		$respuesta->header( 'X-Licencia', 'CC BY 4.0' );
		return $respuesta;
	}

	/* ================================================================= */
	/* Constructores de datos                                            */
	/* ================================================================= */

	/**
	 * Datos completos de un municipio para un mes.
	 *
	 * @param string $divipola DIVIPOLA.
	 * @param string $mes      AAAA-MM.
	 * @return array|null
	 */
	public static function construir_municipio( $divipola, $mes ) {
		$mun = MAN_Municipios::por_divipola( $divipola );
		if ( ! $mun ) {
			return null;
		}

		$oni     = self::oni_para_mes( $mes );
		$litoral = MAN_Municipios::es_litoral( $mun['subregion'] );
		$pred    = self::pred_municipio( $divipola, $mes );
		$pesos   = get_option( 'man_pesos_riesgo', array() );

		if ( $pred && isset( $pred['indice_riesgo'] ) ) {
			$riesgo = (float) $pred['indice_riesgo'];
		} else {
			$riesgo = MAN_Risk::indice(
				array( 'oni' => $oni['oni'], 'anom_lluvia' => 0, 'subregion' => $mun['subregion'], 'litoral' => $litoral ),
				$pesos
			);
		}
		$nivel = MAN_Risk::nivel( $riesgo );

		$texto = MAN_Texto::estado( array(
			'nombre'         => $mun['nombre'],
			'mes'            => $mes,
			'oni'            => $oni['oni'],
			'fase'           => $oni['fase'],
			'intensidad'     => $oni['intensidad'],
			'anom_lluvia'    => 0,
			'riesgo'         => $riesgo,
			'nivel_etiqueta' => $nivel['etiqueta'],
			'subregion'      => $mun['subregion'],
			'litoral'        => $litoral,
		) );

		return array(
			'divipola'       => $mun['divipola'],
			'nombre'         => $mun['nombre'],
			'lat'            => $mun['lat'],
			'lon'            => $mun['lon'],
			'subregion'      => $mun['subregion'],
			'litoral'        => $litoral,
			'mes'            => $mes,
			'oni'            => $oni['oni'],
			'fase'           => $oni['fase'],
			'intensidad'     => $oni['intensidad'],
			'riesgo'         => $riesgo,
			'nivel'          => $nivel['clave'],
			'nivel_etiqueta' => $nivel['etiqueta'],
			'color'          => $nivel['color'],
			'indicadores'    => ( $pred && isset( $pred['ind'] ) ) ? $pred['ind'] : null,
			'alerta_ideam'   => self::buscar_alerta_ideam( $mun['nombre'] ),
			'texto_analisis' => $texto,
			'fuentes'        => 'Open-Meteo (CC BY 4.0), NOAA/CPC, IDEAM',
		);
	}

	/**
	 * Agregado de los 64 municipios para el coroplético.
	 *
	 * @param string $mes AAAA-MM.
	 * @return array[]
	 */
	public static function construir_departamento( $mes ) {
		$oni   = self::oni_para_mes( $mes );
		$pesos = get_option( 'man_pesos_riesgo', array() );
		$out   = array();

		foreach ( MAN_Municipios::todos() as $mun ) {
			$litoral = MAN_Municipios::es_litoral( $mun['subregion'] );
			$pred    = self::pred_municipio( $mun['divipola'], $mes );
			if ( $pred && isset( $pred['indice_riesgo'] ) ) {
				$riesgo = (float) $pred['indice_riesgo'];
			} else {
				$riesgo = MAN_Risk::indice(
					array( 'oni' => $oni['oni'], 'anom_lluvia' => 0, 'subregion' => $mun['subregion'], 'litoral' => $litoral ),
					$pesos
				);
			}
			$nivel = MAN_Risk::nivel( $riesgo );
			$out[] = array(
				'divipola'  => $mun['divipola'],
				'nombre'    => $mun['nombre'],
				'lat'       => $mun['lat'],
				'lon'       => $mun['lon'],
				'subregion' => $mun['subregion'],
				'mes'       => $mes,
				'oni'       => $oni['oni'],
				'riesgo'    => $riesgo,
				'nivel'     => $nivel['clave'],
				'color'     => $nivel['color'],
			);
		}
		return $out;
	}

	/**
	 * Serie ONI de la VENTANA del fenómeno (2026-01 … 2027-03), fusionando el
	 * observado oficial de NOAA con el proyectado de la semilla. NOAA es
	 * autoritativo para los meses pasados; la semilla aporta la proyección a
	 * futuro que NOAA aún no tiene. Así la línea de tiempo siempre cubre la
	 * ventana completa, con valores observados reales cuando existen.
	 *
	 * @return array
	 */
	public static function construir_oni() {
		// 1) Base: ventana de la semilla (datos_globo), con observado/proyectado.
		$serie = array();
		$g     = self::datos_globo();
		if ( $g && ! empty( $g['global']['meses'] ) ) {
			foreach ( $g['global']['meses'] as $m ) {
				$proy = isset( $m['tipo_dato'] ) && 'observado' !== $m['tipo_dato'];
				$serie[ $m['mes'] ] = array(
					'mes'        => $m['mes'],
					'oni'        => (float) $m['oni'],
					'fase'       => MAN_Enso::clasificar_fase( $m['oni'] ),
					'proyectado' => $proy,
				);
			}
		}

		// 2) Overlay: valores observados de NOAA (autoritativos) sobre la ventana.
		$c           = MAN_Cache::get( 'oni' );
		$actualizado = ( $c && isset( $c['actualizado'] ) ) ? $c['actualizado'] : null;
		if ( $c && ! empty( $c['serie'] ) ) {
			foreach ( $c['serie'] as $s ) {
				if ( empty( $s['mes'] ) || ! isset( $serie[ $s['mes'] ] ) ) {
					continue; // solo dentro de la ventana de la semilla
				}
				$serie[ $s['mes'] ]['oni']        = (float) $s['oni'];
				$serie[ $s['mes'] ]['fase']       = MAN_Enso::clasificar_fase( $s['oni'] );
				$serie[ $s['mes'] ]['proyectado'] = false; // NOAA = observado
			}
		}

		// 3) Sin semilla: usa el caché de NOAA tal cual (o vacío).
		if ( empty( $serie ) ) {
			if ( $c && ! empty( $c['serie'] ) ) {
				return $c;
			}
			return array(
				'actual' => array( 'mes' => gmdate( 'Y-m' ), 'oni' => 0, 'fase' => 'Neutral', 'intensidad' => 'sin intensidad' ),
				'serie'  => array(),
			);
		}

		ksort( $serie );
		$serie = array_values( $serie );

		// 4) "actual" = último mes observado (o el último de la serie).
		$actual = null;
		foreach ( $serie as $s ) {
			if ( empty( $s['proyectado'] ) ) {
				$actual = $s;
			}
		}
		if ( null === $actual ) {
			$actual = end( $serie );
		}

		return array(
			'actual'      => array(
				'mes'        => $actual['mes'],
				'oni'        => (float) $actual['oni'],
				'fase'       => MAN_Enso::clasificar_fase( $actual['oni'] ),
				'intensidad' => MAN_Enso::intensidad( $actual['oni'] ),
			),
			'serie'       => $serie,
			'fuente'      => $c ? 'NOAA/CPC (observado) + semilla (proyectado)' : 'Semilla local (datos_globo)',
			'actualizado' => $actualizado,
		);
	}

	/**
	 * Estado de salud de cada fuente (Sección 11).
	 *
	 * @return array[]
	 */
	public static function construir_estado_apis() {
		$config = get_option( 'man_api_config', array() );
		$out    = array();

		foreach ( $config as $slug => $cfg ) {
			$activa  = ! empty( $cfg['activa'] );
			$ultima  = isset( $cfg['ultima_sync'] ) ? (int) $cfg['ultima_sync'] : 0;
			$result  = isset( $cfg['ultimo_resultado'] ) ? $cfg['ultimo_resultado'] : '';
			$freq    = isset( $cfg['frecuencia'] ) ? (int) $cfg['frecuencia'] : 12;

			if ( 'open_meteo' === $slug ) {
				$estado = $activa ? 'ok' : 'inactiva'; // consumo directo navegador
			} elseif ( ! $activa ) {
				$estado = 'inactiva';
			} elseif ( 0 === $ultima ) {
				$estado = 'sin datos';
			} else {
				$ok      = ( 0 === strpos( $result, 'OK' ) );
				$antig   = time() - $ultima;
				if ( ! $ok ) {
					$estado = 'caido';
				} elseif ( $antig > $freq * 3600 * 2 ) {
					$estado = 'degradado';
				} else {
					$estado = 'ok';
				}
			}

			$out[] = array(
				'slug'      => $slug,
				'fuente'    => isset( $cfg['nombre'] ) ? $cfg['nombre'] : $slug,
				'estado'    => $estado,
				'ultima'    => $ultima ? gmdate( 'Y-m-d H:i', $ultima ) . ' UTC' : '—',
				'resultado' => $result,
			);
		}
		return $out;
	}

	/**
	 * Episodios históricos de El Niño (semilla local).
	 *
	 * @return array
	 */
	public static function construir_historico() {
		$h = MAN_Cache::semilla( 'historico_enso_episodios.json' );
		if ( ! is_array( $h ) ) {
			$h = array();
		}
		return array(
			'episodios' => isset( $h['episodios'] ) ? $h['episodios'] : array(),
			'contexto'  => isset( $h['contexto_enso_reciente'] ) ? $h['contexto_enso_reciente'] : null,
			'fuente'    => 'NOAA/CPC · IDEAM (episodios ENSO)',
		);
	}

	/**
	 * Riesgo mensual por municipio (para colorear el mapa de Nariño en el globo).
	 * Devuelve {municipios: {divipola: {nombre, serie:[{mes, riesgo, color}]}}}.
	 *
	 * @return array
	 */
	public static function construir_mapa_narino() {
		$pred = self::predicciones();
		$out  = array();
		if ( ! empty( $pred['municipios'] ) ) {
			foreach ( $pred['municipios'] as $divipola => $m ) {
				$serie = array();
				if ( ! empty( $m['serie_mensual'] ) ) {
					foreach ( $m['serie_mensual'] as $s ) {
						$riesgo = isset( $s['indice_riesgo'] ) ? (float) $s['indice_riesgo'] : 0.0;
						$nivel  = MAN_Risk::nivel( $riesgo );
						$serie[] = array(
							'mes'    => isset( $s['mes'] ) ? $s['mes'] : '',
							'riesgo' => round( $riesgo, 3 ),
							'color'  => $nivel['color'],
							'nivel'  => $nivel['etiqueta'],
							'tipo'   => ( isset( $s['tipo_dato'] ) && 'observado' !== $s['tipo_dato'] ) ? 'proyectado' : 'observado',
							'ind'    => isset( $s['ind'] ) ? $s['ind'] : null,
						);
					}
				}
				$out[ (string) $divipola ] = array(
					'nombre'      => isset( $m['municipio'] ) ? $m['municipio'] : (string) $divipola,
					'regimen'     => isset( $m['regimen'] ) ? $m['regimen'] : '',
					'mes_pico'    => isset( $m['mes_pico'] ) ? $m['mes_pico'] : '',
					'indice_pico' => isset( $m['indice_pico'] ) ? round( (float) $m['indice_pico'], 3 ) : null,
					'nivel_anual' => isset( $m['nivel_riesgo_anual'] ) ? $m['nivel_riesgo_anual'] : '',
					'historico'   => isset( $m['evidencia_historica'] ) ? $m['evidencia_historica'] : '',
					'serie'       => $serie,
				);
			}
		}
		return array(
			'municipios' => $out,
			'fuente'     => 'Predicciones por municipio (escenario de planeación) · DANE (cartografía)',
		);
	}

	/**
	 * Predicción de la trayectoria del ONI hasta el mes objetivo (p. ej. feb-2027).
	 *
	 * Une el ONI observado, el ensamble oficial NOAA-CPC/IRI (semilla, con su
	 * banda) y la proyección propia del plugin (tendencia amortiguada con
	 * reversión a la media), y deriva probabilidades de fase por trimestre.
	 *
	 * @param string $hasta Mes objetivo AAAA-MM.
	 * @return array
	 */
	public static function construir_prediccion( $hasta ) {
		$hasta     = self::sanitizar_objetivo( $hasta );
		$oni       = self::construir_oni();
		$serie_oni = isset( $oni['serie'] ) ? $oni['serie'] : array();

		// 1) Separar observado del proyectado oficial; recoger bandas de la semilla.
		$observado = array();
		foreach ( $serie_oni as $s ) {
			if ( empty( $s['proyectado'] ) ) {
				$observado[] = array( 'mes' => $s['mes'], 'oni' => (float) $s['oni'] );
			}
		}
		// Si no hay marcas de proyección, todo el histórico es observado.
		if ( empty( $observado ) ) {
			foreach ( $serie_oni as $s ) {
				$observado[] = array( 'mes' => $s['mes'], 'oni' => (float) $s['oni'] );
			}
		}
		if ( empty( $observado ) ) {
			return array(
				'objetivo_mes'   => $hasta,
				'serie'          => array(),
				'texto_analisis' => 'Aún no hay serie ONI suficiente para proyectar. Sincronice la fuente NOAA ONI.',
				'fuente'         => 'NOAA/CPC ONI',
			);
		}

		// Banda y central del ensamble oficial desde la semilla datos_globo.
		$banda_seed  = array();
		$ens_central = array();
		$globo       = self::datos_globo();
		if ( ! empty( $globo['global']['meses'] ) ) {
			foreach ( $globo['global']['meses'] as $m ) {
				if ( isset( $m['oni_banda_min'], $m['oni_banda_max'] ) ) {
					$banda_seed[ $m['mes'] ] = array(
						'min' => (float) $m['oni_banda_min'],
						'max' => (float) $m['oni_banda_max'],
					);
				}
				$proy = isset( $m['tipo_dato'] ) && 'observado' !== $m['tipo_dato'];
				if ( $proy ) {
					$ens_central[ $m['mes'] ] = (float) $m['oni'];
				}
			}
		}

		$ult_obs     = end( $observado );
		$ult_obs_mes = $ult_obs['mes'];
		if ( $hasta <= $ult_obs_mes ) {
			$hasta = '2027-02';
		}

		// 2) Proyección propia del plugin (algoritmo de regresión/pronóstico).
		$modelo         = MAN_Forecast::proyectar_oni( $observado, $hasta );
		$modelo_por_mes = array();
		foreach ( $modelo as $row ) {
			$modelo_por_mes[ $row['mes'] ] = $row;
		}

		// 3) Serie unificada para la gráfica.
		$serie       = array();
		$central_map = array();
		foreach ( $observado as $p ) {
			$serie[]                 = array(
				'mes'        => $p['mes'],
				'oni'        => round( $p['oni'], 2 ),
				'banda_min'  => null,
				'banda_max'  => null,
				'modelo_oni' => null,
				'tipo'       => 'observado',
				'fase'       => MAN_Enso::clasificar_fase( $p['oni'] ),
			);
			$central_map[ $p['mes'] ] = (float) $p['oni'];
		}

		$mes    = $ult_obs_mes;
		$guarda = 0;
		while ( $mes < $hasta && $guarda < 60 ) {
			$mes = MAN_Forecast::sumar_meses( $mes, 1 );
			$guarda++;

			$mod     = isset( $modelo_por_mes[ $mes ] ) ? $modelo_por_mes[ $mes ] : null;
			$central = isset( $ens_central[ $mes ] ) ? $ens_central[ $mes ] : ( $mod ? $mod['oni'] : null );
			if ( null === $central ) {
				continue;
			}

			if ( isset( $banda_seed[ $mes ] ) ) {
				$bmin = $banda_seed[ $mes ]['min'];
				$bmax = $banda_seed[ $mes ]['max'];
			} elseif ( $mod ) {
				$bmin = $mod['banda_min'];
				$bmax = $mod['banda_max'];
			} else {
				$bmin = null;
				$bmax = null;
			}

			$serie[]               = array(
				'mes'        => $mes,
				'oni'        => round( $central, 2 ),
				'banda_min'  => ( null !== $bmin ) ? round( $bmin, 2 ) : null,
				'banda_max'  => ( null !== $bmax ) ? round( $bmax, 2 ) : null,
				'modelo_oni' => $mod ? round( $mod['oni'], 2 ) : null,
				'tipo'       => 'proyectado',
				'fase'       => MAN_Enso::clasificar_fase( $central ),
			);
			$central_map[ $mes ] = (float) $central;
		}

		// 4) Estado actual (última observación) y objetivo.
		$actual = array(
			'mes'        => $ult_obs_mes,
			'oni'        => round( $ult_obs['oni'], 2 ),
			'fase'       => MAN_Enso::clasificar_fase( $ult_obs['oni'] ),
			'intensidad' => MAN_Enso::intensidad( $ult_obs['oni'] ),
		);

		$objetivo = null;
		foreach ( $serie as $row ) {
			if ( $row['mes'] === $hasta ) {
				$sigma = isset( $modelo_por_mes[ $hasta ] ) ? (float) $modelo_por_mes[ $hasta ]['sigma'] : 0.3;
				if ( null !== $row['banda_min'] && null !== $row['banda_max'] ) {
					$sigma = max( 0.05, ( $row['banda_max'] - $row['banda_min'] ) / 2 );
				}
				$objetivo = array(
					'mes'        => $row['mes'],
					'oni'        => $row['oni'],
					'fase'       => MAN_Enso::clasificar_fase( $row['oni'] ),
					'intensidad' => MAN_Enso::intensidad( $row['oni'] ),
					'banda'      => array( 'min' => $row['banda_min'], 'max' => $row['banda_max'] ),
					'prob'       => MAN_Forecast::probabilidad_gaussiana( $row['oni'], $sigma ),
				);
				break;
			}
		}

		// 5) Pico (máximo |ONI|) en la parte proyectada.
		$pico   = null;
		$maxabs = -1.0;
		foreach ( $serie as $row ) {
			if ( 'proyectado' !== $row['tipo'] ) {
				continue;
			}
			if ( abs( $row['oni'] ) > $maxabs ) {
				$maxabs = abs( $row['oni'] );
				$pico   = array(
					'mes'        => $row['mes'],
					'oni'        => $row['oni'],
					'fase'       => MAN_Enso::clasificar_fase( $row['oni'] ),
					'intensidad' => MAN_Enso::intensidad( $row['oni'] ),
				);
			}
		}

		// 6) Probabilidad de fase por trimestre móvil.
		$prob_trim = self::probabilidad_trimestres( $central_map, $modelo_por_mes, $banda_seed, $ult_obs_mes, $hasta );

		// 7) Diagnóstico de la regresión sobre la cola observada reciente.
		$cola = array();
		foreach ( array_slice( $observado, -6 ) as $p ) {
			$cola[] = $p['oni'];
		}
		$reg = MAN_Forecast::regresion_lineal( $cola );

		$texto = MAN_Texto::prediccion(
			array(
				'actual'   => $actual,
				'objetivo' => $objetivo,
				'pico'     => $pico,
			)
		);

		return array(
			'objetivo_mes'    => $hasta,
			'actual'          => $actual,
			'objetivo'        => $objetivo,
			'pico'            => $pico,
			'serie'           => $serie,
			'prob_trimestres' => $prob_trim,
			'regresion'       => array(
				'pendiente_mensual' => round( (float) $reg['pendiente'], 3 ),
				'r2'                => $reg['r2'],
				'meses_ajuste'      => $reg['n'],
			),
			'metodologia'     => 'Tendencia lineal amortiguada (Holt) por mínimos cuadrados sobre la cola observada del ONI, con reversión a la media climatológica y banda de incertidumbre creciente con el horizonte (ampliada en la primavera boreal). Clasificación de fase por gaussiana sobre los umbrales NOAA ±0,5 °C. Contraste con el ensamble oficial NOAA-CPC/IRI cuando está disponible.',
			'texto_analisis'  => $texto,
			'fuente'          => 'NOAA/CPC ONI · IRI/CPC ENSO plume · Modelo estadístico del plugin',
		);
	}

	/**
	 * Probabilidad de fase por trimestre móvil (DJF…NDJ) en la ventana de
	 * pronóstico, promediando el ONI central y su incertidumbre.
	 *
	 * @param array  $central_map mes => ONI central.
	 * @param array  $modelo      mes => fila del modelo (con sigma).
	 * @param array  $banda_seed  mes => {min,max} del ensamble oficial.
	 * @param string $desde       Último mes observado (excluido).
	 * @param string $hasta       Mes objetivo (incluido como centro si cabe).
	 * @return array[]
	 */
	private static function probabilidad_trimestres( $central_map, $modelo, $banda_seed, $desde, $hasta ) {
		$abrev = array(
			1 => 'DJF', 2 => 'JFM', 3 => 'FMA', 4 => 'MAM',
			5 => 'AMJ', 6 => 'MJJ', 7 => 'JJA', 8 => 'JAS',
			9 => 'ASO', 10 => 'SON', 11 => 'OND', 12 => 'NDJ',
		);

		$out    = array();
		$centro = $desde;
		$guarda = 0;
		// Recorre como centro cada mes proyectado (centro > último observado).
		while ( $guarda < 60 ) {
			$centro = MAN_Forecast::sumar_meses( $centro, 1 );
			$guarda++;
			if ( $centro > $hasta ) {
				break;
			}

			$prev = MAN_Forecast::sumar_meses( $centro, -1 );
			$next = MAN_Forecast::sumar_meses( $centro, 1 );
			$tres = array( $prev, $centro, $next );

			$suma  = 0.0;
			$cnt   = 0;
			$sig_s = 0.0;
			$sig_c = 0;
			foreach ( $tres as $mm ) {
				if ( isset( $central_map[ $mm ] ) ) {
					$suma += $central_map[ $mm ];
					$cnt++;
				}
				if ( isset( $banda_seed[ $mm ] ) ) {
					$sig_s += max( 0.05, ( $banda_seed[ $mm ]['max'] - $banda_seed[ $mm ]['min'] ) / 2 );
					$sig_c++;
				} elseif ( isset( $modelo[ $mm ]['sigma'] ) ) {
					$sig_s += (float) $modelo[ $mm ]['sigma'];
					$sig_c++;
				}
			}
			if ( $cnt < 3 ) {
				continue; // trimestre incompleto: no se reporta.
			}
			$prom  = $suma / $cnt;
			$sigma = $sig_c > 0 ? ( $sig_s / $sig_c ) : 0.3;

			$mm_centro = (int) substr( $centro, 5, 2 );
			$out[]     = array(
				'clave'    => isset( $abrev[ $mm_centro ] ) ? $abrev[ $mm_centro ] : '',
				'etiqueta' => ( isset( $abrev[ $mm_centro ] ) ? $abrev[ $mm_centro ] : '' ) . ' ' . substr( $centro, 0, 4 ),
				'centro'   => $centro,
				'oni'      => round( $prom, 2 ),
				'fase'     => MAN_Enso::clasificar_fase( $prom ),
			) + MAN_Forecast::probabilidad_gaussiana( $prom, $sigma );
		}
		return $out;
	}

	/**
	 * Sanitiza el mes objetivo de la predicción (por defecto febrero de 2027).
	 *
	 * @param mixed $valor Valor recibido.
	 * @return string AAAA-MM.
	 */
	private static function sanitizar_objetivo( $valor ) {
		$v = trim( (string) $valor );
		if ( '' === $v ) {
			return '2027-02';
		}
		if ( preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $v ) ) {
			return $v;
		}
		return '2027-02';
	}

	/**
	 * Nivel del mar (IOC, si está sincronizado) + punto de oleaje del Pacífico.
	 * El oleaje se consume en vivo desde el navegador (Open-Meteo Marine).
	 *
	 * @param string $estacion Código de estación opcional.
	 * @return array
	 */
	public static function construir_mar( $estacion = '' ) {
		$mar = MAN_Cache::get( 'mar_nivel' );
		return array(
			'disponible'   => ! empty( $mar ),
			'nivel'        => $mar ? $mar : null,
			'punto_oleaje' => array( 'lat' => 1.81, 'lon' => -78.76, 'nombre' => 'Mar abierto frente a Tumaco' ),
			'estacion'     => $estacion,
			'fuente'       => 'IOC/VLIZ Sea Level · Open-Meteo Marine (CC BY 4.0)',
		);
	}

	/**
	 * Salud pública sensible al clima (dengue, SIVIGILA si está sincronizado).
	 *
	 * @return array
	 */
	public static function construir_salud() {
		$dengue     = MAN_Cache::get( 'sivigila_dengue' );
		$disponible = $dengue && ! empty( $dengue['registros'] );
		return array(
			'disponible' => (bool) $disponible,
			'total'      => $disponible ? count( $dengue['registros'] ) : 0,
			'dengue'     => $dengue ? $dengue : null,
			'fuente'     => 'INS / SIVIGILA',
		);
	}

	/* ================================================================= */
	/* Utilidades internas                                               */
	/* ================================================================= */

	/**
	 * Empaqueta un ONI en {oni, fase, intensidad}.
	 *
	 * @param float $oni ONI.
	 * @return array
	 */
	private static function oni_pack( $oni ) {
		$oni = (float) $oni;
		return array(
			'oni'        => $oni,
			'fase'       => MAN_Enso::clasificar_fase( $oni ),
			'intensidad' => MAN_Enso::intensidad( $oni ),
		);
	}

	/**
	 * ONI para un mes (caché NOAA → semilla → 0).
	 *
	 * @param string $mes AAAA-MM.
	 * @return array
	 */
	private static function oni_para_mes( $mes ) {
		$c = MAN_Cache::get( 'oni' );
		if ( $c && ! empty( $c['serie'] ) ) {
			foreach ( $c['serie'] as $s ) {
				if ( isset( $s['mes'] ) && $s['mes'] === $mes ) {
					return self::oni_pack( $s['oni'] );
				}
			}
			if ( ! empty( $c['actual']['oni'] ) ) {
				return self::oni_pack( $c['actual']['oni'] );
			}
		}

		$g = self::datos_globo();
		if ( $g && ! empty( $g['global']['meses'] ) ) {
			$ult = null;
			foreach ( $g['global']['meses'] as $m ) {
				if ( $m['mes'] === $mes ) {
					return self::oni_pack( $m['oni'] );
				}
				$ult = $m;
			}
			if ( $ult ) {
				return self::oni_pack( $ult['oni'] );
			}
		}
		return self::oni_pack( 0.0 );
	}

	/**
	 * Entrada de predicciones de un municipio en un mes.
	 *
	 * @param string $divipola DIVIPOLA.
	 * @param string $mes      AAAA-MM.
	 * @return array|null
	 */
	private static function pred_municipio( $divipola, $mes ) {
		$p = self::predicciones();
		if ( empty( $p['municipios'][ $divipola ]['serie_mensual'] ) ) {
			return null;
		}
		foreach ( $p['municipios'][ $divipola ]['serie_mensual'] as $s ) {
			if ( isset( $s['mes'] ) && $s['mes'] === $mes ) {
				return $s;
			}
		}
		return null;
	}

	/**
	 * Busca una alerta IDEAM cacheada que mencione al municipio.
	 *
	 * @param string $nombre Nombre del municipio.
	 * @return array|null
	 */
	private static function buscar_alerta_ideam( $nombre ) {
		$cache = MAN_Cache::get( 'ideam_alertas' );
		if ( ! $cache || empty( $cache['registros'] ) ) {
			return null;
		}
		$clave = strtoupper( remove_accents( $nombre ) );
		foreach ( $cache['registros'] as $row ) {
			$blob = strtoupper( remove_accents( wp_json_encode( $row ) ) );
			if ( false !== strpos( $blob, $clave ) ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Semilla de predicciones (cacheada en memoria por petición).
	 *
	 * @return array
	 */
	private static function predicciones() {
		if ( null === self::$pred ) {
			$p          = MAN_Cache::semilla( 'predicciones_elnino_narino_2026.json' );
			self::$pred = is_array( $p ) ? $p : array();
		}
		return self::$pred;
	}

	/**
	 * Semilla datos_globo (cacheada en memoria por petición).
	 *
	 * @return array
	 */
	private static function datos_globo() {
		if ( null === self::$globo ) {
			$g           = MAN_Cache::semilla( 'datos_globo_elnino_narino_2026.json' );
			self::$globo = is_array( $g ) ? $g : array();
		}
		return self::$globo;
	}

	/**
	 * Serializa un arreglo de filas a CSV.
	 *
	 * @param array $filas Filas (asociativas).
	 * @return string
	 */
	private static function a_csv( array $filas ) {
		if ( empty( $filas ) ) {
			return '';
		}
		$fh = fopen( 'php://temp', 'r+' );
		fputcsv( $fh, array_keys( (array) $filas[0] ) );
		foreach ( $filas as $f ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'celda_csv' ), (array) $f ) );
		}
		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );
		return $csv;
	}

	/**
	 * Normaliza un valor para CSV y neutraliza la inyección de fórmulas
	 * (CSV/formula injection en Excel/LibreOffice) sin dañar los números
	 * negativos legítimos (latitud, longitud, ONI).
	 *
	 * @param mixed $v Valor de celda.
	 * @return mixed
	 */
	private static function celda_csv( $v ) {
		if ( null === $v ) {
			return '';
		}
		if ( ! is_scalar( $v ) ) {
			return wp_json_encode( $v );
		}
		// Solo se neutralizan CADENAS no numéricas que abren con un carácter
		// peligroso; los números (p. ej. -77.2061) se conservan tal cual.
		if ( is_string( $v ) && '' !== $v && ! is_numeric( $v ) && false !== strpbrk( $v[0], "=+-@\t\r" ) ) {
			return "'" . $v;
		}
		return $v;
	}
}
