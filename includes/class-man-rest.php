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
	 * Serie ONI (caché de NOAA o semilla local).
	 *
	 * @return array
	 */
	public static function construir_oni() {
		$c = MAN_Cache::get( 'oni' );
		if ( $c && ! empty( $c['serie'] ) ) {
			return $c;
		}

		$g = self::datos_globo();
		if ( $g && ! empty( $g['global']['meses'] ) ) {
			$serie  = array();
			$actual = null;
			foreach ( $g['global']['meses'] as $m ) {
				$proy    = isset( $m['tipo_dato'] ) && 'observado' !== $m['tipo_dato'];
				$serie[] = array(
					'mes'        => $m['mes'],
					'oni'        => (float) $m['oni'],
					'fase'       => MAN_Enso::clasificar_fase( $m['oni'] ),
					'proyectado' => $proy,
				);
				$actual = $m;
			}
			return array(
				'actual'      => array(
					'mes'        => $actual['mes'],
					'oni'        => (float) $actual['oni'],
					'fase'       => MAN_Enso::clasificar_fase( $actual['oni'] ),
					'intensidad' => MAN_Enso::intensidad( $actual['oni'] ),
				),
				'serie'       => $serie,
				'fuente'      => 'Semilla local (datos_globo)',
				'actualizado' => null,
			);
		}

		return array(
			'actual' => array( 'mes' => gmdate( 'Y-m' ), 'oni' => 0, 'fase' => 'Neutral', 'intensidad' => 'sin intensidad' ),
			'serie'  => array(),
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
