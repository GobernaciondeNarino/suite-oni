<?php
/**
 * Registro y render de los shortcodes del núcleo (componentes independientes).
 *
 * Contrato común (Sección 4.1): esqueleto inmediato, carga asíncrona, texto
 * de análisis, error elegante con reintento y atribución de fuentes. El
 * contenedor `.man` se renderiza SIN chrome propio (transparente) salvo que
 * el módulo de Apariencia o un atributo lo indiquen.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Shortcodes {

	/** @var int Contador para ids únicos. */
	private static $contador = 0;

	/** @var bool El importmap de Three.js solo puede imprimirse una vez. */
	private static $importmap_impreso = false;

	public function __construct() {
		add_action( 'init', array( $this, 'registrar_shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar_assets' ), 10 );
		add_filter( 'script_loader_tag', array( $this, 'marcar_modulo' ), 10, 3 );
	}

	/* ----------------------------------------------------------------- */
	/* Registro                                                          */
	/* ----------------------------------------------------------------- */

	public function registrar_shortcodes() {
		add_shortcode( 'man_estado', array( $this, 'sc_estado' ) );
		add_shortcode( 'man_pronostico', array( $this, 'sc_pronostico' ) );
		add_shortcode( 'man_mapa', array( $this, 'sc_mapa' ) );
		add_shortcode( 'man_globo', array( $this, 'sc_globo' ) );
		add_shortcode( 'man_timeline', array( $this, 'sc_timeline' ) );
		add_shortcode( 'man_datos', array( $this, 'sc_datos' ) );
		add_shortcode( 'man_historico', array( $this, 'sc_historico' ) );
		add_shortcode( 'man_prediccion', array( $this, 'sc_prediccion' ) );
		add_shortcode( 'man_estadisticas', array( $this, 'sc_estadisticas' ) );
		add_shortcode( 'man_animacion', array( $this, 'sc_animacion' ) );
		add_shortcode( 'man_mar', array( $this, 'sc_mar' ) );
		add_shortcode( 'man_salud', array( $this, 'sc_salud' ) );
		add_shortcode( 'man_hidrico', array( $this, 'sc_hidrico' ) );
		add_shortcode( 'man_estado_api', array( $this, 'sc_estado_api' ) );
	}

	/**
	 * Registra (sin encolar) librerías CDN y scripts del plugin.
	 */
	public function registrar_assets() {
		// Librerías por CDN (sin npm/build).
		wp_register_script( 'd3', 'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js', array(), '7', true );
		wp_register_script( 'd3plus', 'https://cdn.jsdelivr.net/npm/d3plus@3/dist/d3plus.full.min.js', array(), '3', true );
		wp_register_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );

		// Núcleo JS del plugin.
		wp_register_script( 'man-core', MAN_URL . 'assets/js/man-core.js', array(), MAN_VERSION, true );
		// Sin nonce a propósito: los endpoints del front son públicos de solo
		// lectura y un nonce caducado en caché de página rompería la REST (403).
		wp_localize_script( 'man-core', 'MAN', array(
			'rest'      => esc_url_raw( rest_url( 'man/v1' ) ),
			'pluginUrl' => MAN_URL,
			'mesActual' => gmdate( 'Y-m' ),
		) );

		wp_register_script( 'man-municipios', MAN_URL . 'assets/js/municipios.js', array(), MAN_VERSION, true );
		wp_register_script( 'man-estado', MAN_URL . 'assets/js/estado.js', array( 'd3', 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-pronostico', MAN_URL . 'assets/js/pronostico.js', array( 'd3', 'man-core', 'man-municipios' ), MAN_VERSION, true );
		wp_register_script( 'man-mapa', MAN_URL . 'assets/js/mapa.js', array( 'leaflet', 'man-core', 'man-municipios' ), MAN_VERSION, true );
		wp_register_script( 'man-datos', MAN_URL . 'assets/js/datos.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-timeline', MAN_URL . 'assets/js/timeline.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-historico', MAN_URL . 'assets/js/historico.js', array( 'd3', 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-prediccion', MAN_URL . 'assets/js/prediccion.js', array( 'd3', 'man-core' ), MAN_VERSION, true );
		// Estadísticas con D3plus (gráficos prediseñados con tooltip/leyenda en español).
		wp_register_script( 'man-estadisticas', MAN_URL . 'assets/js/estadisticas.js', array( 'd3', 'd3plus', 'man-core', 'man-municipios' ), MAN_VERSION, true );
		// Animaciones explicativas con Anime.js (el globo usa Three.js, registrado aparte).
		wp_register_script( 'animejs', 'https://cdn.jsdelivr.net/npm/animejs@3.2.1/lib/anime.min.js', array(), '3.2.1', true );
		wp_register_script( 'man-animacion', MAN_URL . 'assets/js/animacion.js', array( 'animejs', 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-mar', MAN_URL . 'assets/js/mar.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-salud', MAN_URL . 'assets/js/salud.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-hidrico', MAN_URL . 'assets/js/hidrico.js', array( 'man-core', 'man-municipios' ), MAN_VERSION, true );
		wp_register_script( 'man-estado-api', MAN_URL . 'assets/js/estado-api.js', array( 'man-core' ), MAN_VERSION, true );
		// Globo: módulo ES (importmap de Three.js impreso aparte).
		wp_register_script( 'man-globo', MAN_URL . 'assets/js/globo.js', array(), MAN_VERSION, true );
	}

	/**
	 * Convierte el tag del globo en <script type="module">.
	 *
	 * @param string $tag    Etiqueta HTML.
	 * @param string $handle Handle del script.
	 * @param string $src    URL.
	 * @return string
	 */
	public function marcar_modulo( $tag, $handle, $src ) {
		if ( 'man-globo' === $handle ) {
			return '<script type="module" src="' . esc_url( $src ) . '" id="man-globo-js"></script>' . "\n";
		}
		return $tag;
	}

	/* ----------------------------------------------------------------- */
	/* Shortcodes                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * [man_estado] — Estado actual ENSO + condiciones del día.
	 */
	public function sc_estado( $atts ) {
		$atts = $this->fusionar( array(
			'municipio' => 'departamento',
			'compacto'  => 'no',
		), $atts, 'man_estado' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-estado' );

		$id  = $this->id();
		$div = MAN_Security::sanitizar_divipola( $atts['municipio'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-estado<?php echo 'si' === $atts['compacto'] ? ' man--compacto' : ''; ?>"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-estado
			data-municipio="<?php echo esc_attr( $div ); ?>">
			<?php echo $this->skeleton( 'Cargando estado del fenómeno…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'NOAA/CPC · IDEAM · Open-Meteo (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_pronostico] — Pronóstico 7–16 días (Open-Meteo en vivo).
	 */
	public function sc_pronostico( $atts ) {
		$atts = $this->fusionar( array(
			'municipio' => '52001',
			'dias'      => '7',
		), $atts, 'man_pronostico' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-pronostico' );

		$id   = $this->id();
		$div  = MAN_Security::sanitizar_divipola( $atts['municipio'] );
		$dias = max( 1, min( 16, (int) $atts['dias'] ) );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-pronostico"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-pronostico
			data-municipio="<?php echo esc_attr( $div ); ?>"
			data-dias="<?php echo esc_attr( $dias ); ?>">
			<?php echo $this->skeleton( 'Cargando pronóstico…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'Open-Meteo (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_mapa] — Coroplético Leaflet de los 64 municipios.
	 */
	public function sc_mapa( $atts ) {
		$atts = $this->fusionar( array(
			'variable' => 'riesgo',
			'mes'      => gmdate( 'Y-m' ),
		), $atts, 'man_mapa' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_style( 'leaflet' );
		wp_enqueue_script( 'man-mapa' );

		$id  = $this->id();
		$mes = MAN_Security::sanitizar_mes( $atts['mes'] );
		$var = sanitize_key( $atts['variable'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-mapa"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-mapa
			data-variable="<?php echo esc_attr( $var ); ?>"
			data-mes="<?php echo esc_attr( $mes ); ?>"
			data-geojson="<?php echo esc_url( MAN_URL . 'data/narino_municipios.geojson' ); ?>">
			<div class="man-mapa__lienzo" role="application" aria-label="Mapa coroplético de los municipios de Nariño"></div>
			<div class="man-mapa__panel" hidden></div>
			<?php echo $this->skeleton( 'Cargando mapa…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'DANE (cartografía) · IDEAM · Open-Meteo (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_globo] — Globo 3D cinematográfico (Three.js).
	 */
	public function sc_globo( $atts ) {
		$atts = $this->fusionar( array(
			'calidad'   => 'auto',
			'autorotar' => 'si',
		), $atts, 'man_globo' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-globo' );
		wp_localize_script( 'man-globo', 'MANGLOBO', array(
			'rest'      => esc_url_raw( rest_url( 'man/v1' ) ),
			'calidad'   => sanitize_key( $atts['calidad'] ),
			'autorotar' => 'si' === $atts['autorotar'],
			'textura'   => 'https://unpkg.com/three-globe@2.31.0/example/img/earth-blue-marble.jpg',
			'mesActual' => gmdate( 'Y-m' ),
		) );

		$id = $this->id();

		ob_start();
		echo $this->importmap(); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-globo"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-globo
			data-calidad="<?php echo esc_attr( sanitize_key( $atts['calidad'] ) ); ?>">
			<div class="man-globo__lienzo" role="img"
				aria-label="Globo terráqueo 3D con la anomalía del Pacífico ecuatorial y el foco de Nariño"></div>
			<div class="man-globo__cinta" aria-live="polite"></div>
			<?php echo $this->skeleton( 'Cargando globo 3D…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'NOAA/CPC · NASA Blue Marble · Open-Meteo (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_timeline] — Línea de tiempo (slider ONI que controla el globo).
	 */
	public function sc_timeline( $atts ) {
		$atts = $this->fusionar( array(
			'inicio' => '',
			'fin'    => '',
		), $atts, 'man_timeline' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-timeline' );

		$id = $this->id();

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-timeline"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-timeline>
			<div class="man-timeline__cabecera">
				<strong class="man-timeline__mes" aria-live="polite">—</strong>
				<span class="man-timeline__oni"></span>
			</div>
			<div class="man-timeline__controles">
				<button type="button" class="man-btn" data-accion="anterior" aria-label="Mes anterior">◀</button>
				<button type="button" class="man-btn" data-accion="play" aria-label="Reproducir o pausar">▶</button>
				<button type="button" class="man-btn" data-accion="siguiente" aria-label="Mes siguiente">▶▶</button>
				<input type="range" class="man-timeline__slider" min="0" max="0" step="1" value="0" aria-label="Mes activo" />
			</div>
			<?php echo $this->pie_fuentes( 'NOAA/CPC ONI' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_datos] — Botón de datos abiertos (Descargar JSON / CSV / Ver API).
	 */
	public function sc_datos( $atts ) {
		$atts = $this->fusionar( array(
			'recurso'   => 'municipios',
			'municipio' => '',
			'mes'       => gmdate( 'Y-m' ),
			'texto'     => '',
		), $atts, 'man_datos' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-datos' );

		$recurso = sanitize_key( $atts['recurso'] );
		$mes     = MAN_Security::sanitizar_mes( $atts['mes'] );

		// Construye la URL base del recurso abierto.
		if ( 'oni' === $recurso ) {
			$ruta   = 'man/v1/abierto/oni';
			$titulo = 'Serie del índice ONI';
		} elseif ( 'prediccion' === $recurso ) {
			$ruta   = 'man/v1/abierto/prediccion';
			$titulo = 'Predicción del ONI (observado + ensamble + modelo)';
		} elseif ( 'municipio' === $recurso ) {
			$div    = MAN_Security::sanitizar_divipola( $atts['municipio'] );
			$div    = ( 'departamento' === $div ) ? '52001' : $div;
			$ruta   = 'man/v1/abierto/' . $div;
			$mun    = MAN_Municipios::por_divipola( $div );
			$titulo = 'Serie de ' . ( $mun ? $mun['nombre'] : $div );
		} else {
			$ruta   = 'man/v1/abierto/municipios';
			$titulo = 'Riesgo y fase ENSO por municipio';
		}

		$url_json = add_query_arg( array( 'mes' => $mes, 'formato' => 'json' ), rest_url( $ruta ) );
		$url_csv  = add_query_arg( array( 'mes' => $mes, 'formato' => 'csv' ), rest_url( $ruta ) );

		$texto = '' !== $atts['texto'] ? sanitize_text_field( $atts['texto'] ) : $titulo;

		ob_start();
		?>
		<div class="man man-datos"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-datos>
			<p class="man-datos__titulo"><?php echo esc_html( $texto ); ?></p>
			<div class="man-datos__acciones">
				<a class="man-btn man-btn--primario" href="<?php echo esc_url( $url_json ); ?>" download>Descargar JSON</a>
				<a class="man-btn" href="<?php echo esc_url( $url_csv ); ?>" download>Descargar CSV</a>
				<a class="man-btn" href="<?php echo esc_url( $url_json ); ?>" target="_blank" rel="noopener">Ver API</a>
				<button type="button" class="man-btn" data-copiar="<?php echo esc_url( $url_json ); ?>">Copiar URL</button>
			</div>
			<p class="man-datos__licencia">Datos abiertos · Licencia CC BY 4.0 · Gobernación de Nariño</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_historico] — Episodios ENSO 2015–2024 + ONI pico.
	 */
	public function sc_historico( $atts ) {
		$atts = $this->fusionar( array( 'desde' => '', 'hasta' => '' ), $atts, 'man_historico' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-historico' );
		$id = $this->id();
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-historico"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>" data-man-historico>
			<?php echo $this->skeleton( 'Cargando históricos ENSO…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'NOAA/CPC · IDEAM (episodios ENSO)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_prediccion] — Predicción de la trayectoria del ONI hasta el mes
	 * objetivo (por defecto febrero de 2027): gráfica animada con banda de
	 * incertidumbre, umbrales de fase, probabilidades por trimestre y texto
	 * predictivo. Componente independiente y maquetable por separado.
	 */
	public function sc_prediccion( $atts ) {
		$atts = $this->fusionar( array(
			'hasta'        => '2027-02',
			'modelo'       => 'si',   // muestra la línea del modelo propio del plugin.
			'probabilidad' => 'si',   // muestra las barras de probabilidad por trimestre.
		), $atts, 'man_prediccion' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-prediccion' );

		$id    = $this->id();
		$hasta = MAN_Security::sanitizar_mes( $atts['hasta'] );
		// sanitizar_mes cae al mes actual si el valor es inválido; forzamos un
		// objetivo futuro razonable por defecto.
		if ( $hasta <= gmdate( 'Y-m' ) ) {
			$hasta = '2027-02';
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-prediccion"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-prediccion
			data-hasta="<?php echo esc_attr( $hasta ); ?>"
			data-modelo="<?php echo esc_attr( 'no' === $atts['modelo'] ? 'no' : 'si' ); ?>"
			data-probabilidad="<?php echo esc_attr( 'no' === $atts['probabilidad'] ? 'no' : 'si' ); ?>">
			<?php echo $this->skeleton( 'Calculando la predicción del fenómeno…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'NOAA/CPC ONI · IRI/CPC ENSO plume · Modelo estadístico del plugin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_estadisticas] — Gráficos estadísticos prediseñados con D3plus
	 * (tooltip y leyenda en español). Componente independiente y maquetable.
	 *
	 * tipo: oni (línea ONI observado+proyectado) | probabilidad (barras
	 * apiladas de fase por trimestre) | riesgo (riesgo medio por subregión).
	 */
	public function sc_estadisticas( $atts ) {
		$atts = $this->fusionar( array(
			'tipo'  => 'oni',
			'hasta' => '2027-02',
			'mes'   => gmdate( 'Y-m' ),
			'alto'  => '360px',
		), $atts, 'man_estadisticas' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-estadisticas' );

		$id    = $this->id();
		$tipo  = sanitize_key( $atts['tipo'] );
		$hasta = MAN_Security::sanitizar_mes( $atts['hasta'] );
		if ( $hasta <= gmdate( 'Y-m' ) ) {
			$hasta = '2027-02';
		}
		$mes  = MAN_Security::sanitizar_mes( $atts['mes'] );
		$alto = preg_match( '/^\d{1,4}(px|vh|rem|em|%)$/', $atts['alto'] ) ? $atts['alto'] : '360px';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-estadisticas"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-estadisticas
			data-tipo="<?php echo esc_attr( $tipo ); ?>"
			data-hasta="<?php echo esc_attr( $hasta ); ?>"
			data-mes="<?php echo esc_attr( $mes ); ?>">
			<div class="man-estadisticas__lienzo" style="min-height:<?php echo esc_attr( $alto ); ?>"
				role="img" aria-label="Gráfico estadístico del fenómeno ENSO"></div>
			<?php echo $this->skeleton( 'Cargando estadísticas…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'NOAA/CPC · IDEAM · D3plus' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_animacion] — Animación explicativa del mecanismo ENSO con Anime.js
	 * (vientos alisios, piscina cálida, termoclina, convección). Permite
	 * comparar Neutral / El Niño / La Niña. Componente independiente.
	 */
	public function sc_animacion( $atts ) {
		$atts = $this->fusionar( array(
			'estado'   => 'el_nino',
			'autoplay' => 'si',
		), $atts, 'man_animacion' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-animacion' );

		$id     = $this->id();
		$estado = sanitize_key( $atts['estado'] );
		if ( ! in_array( $estado, array( 'neutral', 'el_nino', 'la_nina' ), true ) ) {
			$estado = 'el_nino';
		}

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-animacion"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-animacion
			data-estado="<?php echo esc_attr( $estado ); ?>"
			data-autoplay="<?php echo esc_attr( 'no' === $atts['autoplay'] ? 'no' : 'si' ); ?>">
			<div class="man-animacion__escena" role="img"
				aria-label="Esquema animado del Pacífico ecuatorial durante el fenómeno ENSO"></div>
			<div class="man-animacion__controles" role="group" aria-label="Fases ENSO">
				<button type="button" class="man-btn" data-fase="neutral">Neutral</button>
				<button type="button" class="man-btn" data-fase="el_nino">El Niño</button>
				<button type="button" class="man-btn" data-fase="la_nina">La Niña</button>
			</div>
			<p class="man-animacion__narracion man-analisis" aria-live="polite"></p>
			<?php echo $this->pie_fuentes( 'Esquema didáctico · NOAA/CPC · Three.js (globo) · Anime.js' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_mar] — Nivel del mar (IOC) + oleaje Pacífico (Open-Meteo Marine).
	 */
	public function sc_mar( $atts ) {
		$atts = $this->fusionar( array( 'estacion' => '' ), $atts, 'man_mar' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-mar' );
		$id = $this->id();
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-mar"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-mar data-estacion="<?php echo esc_attr( sanitize_text_field( $atts['estacion'] ) ); ?>">
			<?php echo $this->skeleton( 'Cargando mar y oleaje…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'IOC/VLIZ Sea Level · Open-Meteo Marine (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_salud] — Casos de dengue (SIVIGILA) sensibles al clima.
	 */
	public function sc_salud( $atts ) {
		$atts = $this->fusionar( array( 'evento' => 'dengue', 'anio' => gmdate( 'Y' ) ), $atts, 'man_salud' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-salud' );
		$id = $this->id();
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-salud"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-salud data-evento="<?php echo esc_attr( sanitize_key( $atts['evento'] ) ); ?>"
			data-anio="<?php echo esc_attr( (int) $atts['anio'] ); ?>">
			<?php echo $this->skeleton( 'Cargando datos de salud…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'INS / SIVIGILA' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_hidrico] — Caudal de ríos (GloFAS) y humedad de suelo (Open-Meteo).
	 */
	public function sc_hidrico( $atts ) {
		$atts = $this->fusionar( array( 'municipio' => '52001' ), $atts, 'man_hidrico' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-hidrico' );
		$id  = $this->id();
		$div = MAN_Security::sanitizar_divipola( $atts['municipio'] );
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-hidrico"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-hidrico data-municipio="<?php echo esc_attr( $div ); ?>">
			<?php echo $this->skeleton( 'Cargando recursos hídricos…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'Open-Meteo Flood/Forecast (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_estado_api] — Panel público de salud de las APIs.
	 */
	public function sc_estado_api( $atts ) {
		$atts = $this->fusionar( array(), $atts, 'man_estado_api' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-estado-api' );
		$id = $this->id();
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-estado-api"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>" data-man-estado-api>
			<?php echo $this->skeleton( 'Consultando estado de las fuentes…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'Monitoreo interno del plugin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades de render                                              */
	/* ----------------------------------------------------------------- */

	/**
	 * Fusiona atributos base + atributos de apariencia.
	 *
	 * @param array  $base Atributos propios del shortcode.
	 * @param mixed  $atts Atributos recibidos.
	 * @param string $tag  Nombre del shortcode.
	 * @return array
	 */
	private function fusionar( array $base, $atts, $tag ) {
		$apariencia = array(
			'fondo'     => '',
			'acento'    => '',
			'acento2'   => '',
			'tecnico'   => '',
			'texto'     => '',
			'borde'     => '',
			'sombra'    => '',
			'ancho'     => '',
			'espaciado' => '',
			'radio'     => '',
		);
		return shortcode_atts( array_merge( $base, $apariencia ), $atts, $tag );
	}

	/**
	 * Genera un id único de contenedor.
	 *
	 * @return string
	 */
	private function id() {
		self::$contador++;
		return 'man-' . self::$contador;
	}

	/**
	 * Esqueleto de carga accesible.
	 *
	 * @param string $texto Texto del estado de carga.
	 * @return string
	 */
	private function skeleton( $texto ) {
		return '<div class="man-skeleton" role="status">' . esc_html( $texto ) . '</div>';
	}

	/**
	 * Pie de atribución de fuentes.
	 *
	 * @param string $fuentes Texto de fuentes.
	 * @return string
	 */
	private function pie_fuentes( $fuentes ) {
		return '<p class="man-fuentes">Fuente: ' . esc_html( $fuentes ) . '</p>';
	}

	/**
	 * Imprime el importmap de Three.js una sola vez por documento.
	 *
	 * @return string
	 */
	private function importmap() {
		if ( self::$importmap_impreso ) {
			return '';
		}
		self::$importmap_impreso = true;
		return '<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.module.js","three/addons/":"https://cdn.jsdelivr.net/npm/three@0.160.0/examples/jsm/"}}</script>' . "\n";
	}
}
