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
		add_shortcode( 'man_grafico', array( $this, 'sc_grafico' ) );
		add_shortcode( 'man_analisis', array( $this, 'sc_analisis' ) );
		// Piezas separadas del análisis (para maquetar cada parte por su cuenta).
		add_shortcode( 'man_descripcion', array( $this, 'sc_descripcion' ) );
		add_shortcode( 'man_analisis_cualitativo', array( $this, 'sc_analisis_cualitativo' ) );
		add_shortcode( 'man_analisis_cuantitativo', array( $this, 'sc_analisis_cuantitativo' ) );
		add_shortcode( 'man_explicacion', array( $this, 'sc_explicacion' ) );
		// Piezas separadas de la predicción ENSO.
		add_shortcode( 'man_prediccion_grafico', array( $this, 'sc_prediccion_grafico' ) );
		add_shortcode( 'man_prediccion_descripcion', array( $this, 'sc_prediccion_descripcion' ) );
		add_shortcode( 'man_prediccion_analisis', array( $this, 'sc_prediccion_analisis' ) );
		add_shortcode( 'man_prediccion_probabilidad', array( $this, 'sc_prediccion_probabilidad' ) );
		add_shortcode( 'man_prediccion_ficha', array( $this, 'sc_prediccion_ficha' ) );
		// Variantes con selector de municipio.
		add_shortcode( 'man_pronostico_select', array( $this, 'sc_pronostico_select' ) );
		add_shortcode( 'man_hidrico_select', array( $this, 'sc_hidrico_select' ) );
		add_shortcode( 'man_estado_select', array( $this, 'sc_estado_select' ) );
		// Descripción y análisis del mapa coroplético (texto, sin el mapa).
		add_shortcode( 'man_mapa_descripcion', array( $this, 'sc_mapa_descripcion' ) );
		add_shortcode( 'man_mapa_analisis', array( $this, 'sc_mapa_analisis' ) );
		// Descripción/análisis de los componentes (estado, pronóstico, hídrico, mar, salud, globo…).
		add_shortcode( 'man_info', array( $this, 'sc_info' ) );
		add_shortcode( 'man_filtro', array( $this, 'sc_filtro' ) );
		add_shortcode( 'man_panel', array( $this, 'sc_panel' ) );
		add_shortcode( 'man_mar', array( $this, 'sc_mar' ) );
		add_shortcode( 'man_salud', array( $this, 'sc_salud' ) );
		add_shortcode( 'man_hidrico', array( $this, 'sc_hidrico' ) );
		add_shortcode( 'man_estado_api', array( $this, 'sc_estado_api' ) );
		add_shortcode( 'man_estaciones', array( $this, 'sc_estaciones' ) );
	}

	/**
	 * Registra (sin encolar) librerías CDN y scripts del plugin.
	 */
	public function registrar_assets() {
		// Librerías por CDN (sin npm/build).
		wp_register_script( 'd3', 'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js', array(), '7', true );
		// d3plus v2.x: bundle UMD apto para navegador que expone window.d3plus con
		// la API (BarChart/LinePlot/… .select().data().groupBy().x().y().render()).
		// NO usar @d3plus/core@3.x: ese bundle referencia `process`/`require` y
		// rompe en el navegador (window.d3plus queda undefined → no pintan gráficos).
		wp_register_script( 'd3plus', 'https://cdn.jsdelivr.net/npm/d3plus@2.0.0/build/d3plus.full.min.js', array(), '2.0.0', true );
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
		wp_register_script( 'man-estaciones', MAN_URL . 'assets/js/estaciones.js', array( 'leaflet', 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-datos', MAN_URL . 'assets/js/datos.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-timeline', MAN_URL . 'assets/js/timeline.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-historico', MAN_URL . 'assets/js/historico.js', array( 'd3', 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-prediccion', MAN_URL . 'assets/js/prediccion.js', array( 'd3', 'man-core' ), MAN_VERSION, true );
		// Estadísticas con D3plus (gráficos prediseñados con tooltip/leyenda en español).
		wp_register_script( 'man-estadisticas', MAN_URL . 'assets/js/estadisticas.js', array( 'd3', 'd3plus', 'man-core', 'man-municipios' ), MAN_VERSION, true );
		// Animaciones explicativas con Anime.js (el globo usa Three.js, registrado aparte).
		wp_register_script( 'animejs', 'https://cdn.jsdelivr.net/npm/animejs@3.2.1/lib/anime.min.js', array(), '3.2.1', true );
		wp_register_script( 'man-animacion', MAN_URL . 'assets/js/animacion.js', array( 'animejs', 'man-core' ), MAN_VERSION, true );
		// Motor de gráficos D3plus con barra de herramientas ([man_grafico]).
		wp_register_style( 'man-grafico-css', MAN_URL . 'assets/css/grafico.css', array(), MAN_VERSION );
		wp_register_script( 'man-renderer', MAN_URL . 'assets/js/renderer.js', array( 'd3plus' ), MAN_VERSION, true );
		// Bus de estado por grupo + componentes composables ([man_filtro], [man_panel]).
		wp_register_script( 'man-grupo', MAN_URL . 'assets/js/grupo.js', array(), MAN_VERSION, true );
		wp_register_script( 'man-grafico', MAN_URL . 'assets/js/grafico.js', array( 'man-renderer', 'man-core', 'man-grupo' ), MAN_VERSION, true );
		wp_register_script( 'man-composable', MAN_URL . 'assets/js/composable.js', array( 'man-core', 'man-grupo' ), MAN_VERSION, true );
		wp_register_script( 'man-muni-select', MAN_URL . 'assets/js/municipio-select.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-mar', MAN_URL . 'assets/js/mar.js', array( 'man-core', 'd3plus' ), MAN_VERSION, true );
		wp_register_script( 'man-salud', MAN_URL . 'assets/js/salud.js', array( 'man-core' ), MAN_VERSION, true );
		wp_register_script( 'man-hidrico', MAN_URL . 'assets/js/hidrico.js', array( 'man-core', 'man-municipios', 'd3plus' ), MAN_VERSION, true );
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
			'geojson'   => esc_url_raw( MAN_URL . 'data/narino_municipios.geojson' ),
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

			<div class="man-globo__controles" role="toolbar" aria-label="Vista y capas del globo">
				<button type="button" class="man-globo__btn" data-camara="default" title="Vista global">🌐 Global</button>
				<button type="button" class="man-globo__btn" data-camara="mecanismo" title="Pacífico ecuatorial — mecanismo">🌎 Mecanismo</button>
				<button type="button" class="man-globo__btn" data-camara="local" title="Colombia / Nariño — impacto local">📍 Nariño</button>
				<button type="button" class="man-globo__btn" data-panel="mecanismo" aria-expanded="false" title="¿Cómo se genera El Niño?">📖 ¿Cómo se genera?</button>
				<button type="button" class="man-globo__btn" data-panel="historico" aria-expanded="false" title="Episodios históricos de El Niño">📊 Históricos</button>
			</div>

			<div class="man-globo__cintillo" aria-live="polite">
				<strong class="man-globo__cintillo-mes">—</strong>
				<span class="man-globo__sep">·</span>
				<span>ONI <strong class="man-globo__cintillo-oni">—</strong></span>
				<span class="man-globo__sep">·</span>
				<span>Prob. El Niño <strong class="man-globo__cintillo-prob">—</strong></span>
				<span class="man-globo__sep">·</span>
				<span class="man-globo__cintillo-resumen"></span>
			</div>

			<div class="man-globo__drawer" data-drawer="mecanismo" hidden>
				<div class="man-globo__drawer-cab"><strong>¿Cómo se genera El Niño?</strong>
					<button type="button" class="man-globo__drawer-x" data-cerrar aria-label="Cerrar">✕</button></div>
				<div class="man-globo__drawer-cuerpo" data-cuerpo="mecanismo"></div>
			</div>
			<div class="man-globo__drawer" data-drawer="historico" hidden>
				<div class="man-globo__drawer-cab"><strong>Episodios históricos de El Niño</strong>
					<button type="button" class="man-globo__drawer-x" data-cerrar aria-label="Cerrar">✕</button></div>
				<div class="man-globo__drawer-cuerpo" data-cuerpo="historico"></div>
			</div>

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
			'inicio'   => '2026-03',
			'fin'      => '2027-03',
			'autoplay' => 'si',
			'titulo'   => 'El Niño 2026 · Nariño',
		), $atts, 'man_timeline' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-timeline' );

		$id       = $this->id();
		$inicio   = MAN_Security::sanitizar_mes( $atts['inicio'] );
		$fin      = MAN_Security::sanitizar_mes( $atts['fin'] );
		$autoplay = ( 'no' === $atts['autoplay'] || '0' === (string) $atts['autoplay'] ) ? 'no' : 'si';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-timeline man-timeline--barra"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-timeline
			data-inicio="<?php echo esc_attr( $inicio ); ?>"
			data-fin="<?php echo esc_attr( $fin ); ?>"
			data-autoplay="<?php echo esc_attr( $autoplay ); ?>">

			<div class="man-timeline__barra-top">
			<p class="man-timeline__leyenda">
				<span class="man-timeline__leyenda-item"><span class="man-timeline__pip man-timeline__pip--obs"></span>Observado</span>
				<span class="man-timeline__leyenda-item"><span class="man-timeline__pip man-timeline__pip--proy"></span>Proyectado</span>
				<span class="man-timeline__leyenda-item"><span class="man-timeline__heat-leyenda" aria-hidden="true"></span>ONI: frío → cálido</span>
			</p>

			<div class="man-timeline__identidad">
				<img class="man-timeline__logo" src="<?php echo esc_url( MAN_URL . 'assets/img/TIC.png' ); ?>"
					alt="Gobernación de Nariño · Secretaría TIC" onerror="this.style.display='none'" />
				<span class="man-timeline__separador" aria-hidden="true"></span>
				<strong class="man-timeline__titulo"><?php echo esc_html( sanitize_text_field( $atts['titulo'] ) ); ?></strong>
				<span class="man-timeline__estado-chip" aria-live="polite">—</span>
			</div>

			<div class="man-timeline__controles">
				<button type="button" class="man-timeline__btn" data-accion="anterior" aria-label="Mes anterior">‹</button>
				<button type="button" class="man-timeline__btn man-timeline__btn--play" data-accion="play" aria-label="Reproducir o pausar">▶</button>
				<button type="button" class="man-timeline__btn" data-accion="siguiente" aria-label="Mes siguiente">›</button>
				<label class="man-timeline__velocidad">Velocidad:
					<select aria-label="Velocidad de reproducción">
						<option value="2000">Lento</option>
						<option value="1200" selected>Normal</option>
						<option value="600">Rápido</option>
					</select>
				</label>
				<details class="man-timeline__capas">
					<summary class="man-timeline__capas-summary" title="Activar / desactivar capas del globo">Capas <span aria-hidden="true">▾</span></summary>
					<div class="man-timeline__capas-menu" role="group" aria-label="Capas visuales del globo">
						<label class="man-timeline__capas-opcion"><input type="checkbox" data-capa="calor" checked /> <span><strong>Mapa de calor</strong> del Pacífico</span></label>
						<label class="man-timeline__capas-opcion"><input type="checkbox" data-capa="foco" checked /> <span><strong>Foco de calor</strong> costero <em>(ago–dic)</em></span></label>
						<label class="man-timeline__capas-opcion"><input type="checkbox" data-capa="nubes" checked /> <span><strong>Nubes</strong> del Pacífico</span></label>
						<label class="man-timeline__capas-opcion"><input type="checkbox" data-capa="mapa" checked /> <span><strong>Mapa de Nariño</strong></span></label>
					</div>
				</details>
			</div>
			</div>

			<div class="man-timeline__slider">
				<div class="man-timeline__heat" aria-hidden="true"></div>
				<span class="man-timeline__divisor" aria-hidden="true"></span>
				<input type="range" class="man-timeline__rango" min="0" max="0" step="1" value="0" aria-label="Mes activo" />
				<ul class="man-timeline__marcas" role="group" aria-label="Meses"></ul>
			</div>
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
		$atts = $this->fusionar( array(
			'desde'    => '',
			'fin'      => '',
			'alto'     => '360px',
			'theme'    => 'claro',
			'analisis' => 'ambos',
		), $atts, 'man_historico' );

		return $this->figura_grafico( 'episodios', 'bar', array(
			'theme'    => $atts['theme'],
			'alto'     => $atts['alto'],
			'analisis' => $atts['analisis'],
			'fuente'   => 'NOAA/CPC · IDEAM (episodios ENSO)',
		) );
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
			'partes'       => '',     // qué secciones mostrar (vacío = todas).
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
		// Secciones a renderizar (para dividir gráfico y textos en shortcodes
		// distintos): titulo, chips, grafico, probabilidad, texto, metodologia.
		$partes = preg_replace( '/[^a-z,]/', '', strtolower( (string) $atts['partes'] ) );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-prediccion"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-prediccion
			data-hasta="<?php echo esc_attr( $hasta ); ?>"
			data-modelo="<?php echo esc_attr( 'no' === $atts['modelo'] ? 'no' : 'si' ); ?>"
			data-probabilidad="<?php echo esc_attr( 'no' === $atts['probabilidad'] ? 'no' : 'si' ); ?>"
			data-partes="<?php echo esc_attr( $partes ); ?>">
			<?php echo $this->skeleton( 'Calculando la predicción del fenómeno…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'NOAA/CPC ONI · IRI/CPC ENSO plume · Modelo estadístico del plugin' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/* --- Piezas separadas de la predicción ENSO (envuelven sc_prediccion) --- */
	/** [man_prediccion_grafico] — solo la gráfica de la trayectoria del ONI. */
	public function sc_prediccion_grafico( $atts ) { return $this->prediccion_parte( $atts, 'grafico' ); }
	/** [man_prediccion_descripcion] — título + cifras clave (estado/pico/objetivo). */
	public function sc_prediccion_descripcion( $atts ) { return $this->prediccion_parte( $atts, 'titulo,chips' ); }
	/** [man_prediccion_analisis] — narrativa predictiva (texto de análisis). */
	public function sc_prediccion_analisis( $atts ) { return $this->prediccion_parte( $atts, 'texto' ); }
	/** [man_prediccion_probabilidad] — barras de probabilidad por trimestre. */
	public function sc_prediccion_probabilidad( $atts ) { return $this->prediccion_parte( $atts, 'probabilidad' ); }
	/** [man_prediccion_ficha] — ficha técnica / metodología. */
	public function sc_prediccion_ficha( $atts ) { return $this->prediccion_parte( $atts, 'metodologia' ); }

	/**
	 * Envuelve sc_prediccion forzando las secciones a mostrar.
	 *
	 * @param mixed  $atts   Atributos del shortcode.
	 * @param string $partes Secciones (titulo,chips,grafico,probabilidad,texto,metodologia).
	 * @return string
	 */
	private function prediccion_parte( $atts, $partes ) {
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['partes'] = $partes;
		return $this->sc_prediccion( $atts );
	}

	/**
	 * [man_pronostico_select] — pronóstico con un <select> de los 64 municipios
	 * que recarga el componente al cambiar (sin recargar la página).
	 */
	public function sc_pronostico_select( $atts ) {
		$atts = $this->fusionar( array( 'municipio' => '52001', 'dias' => '14' ), $atts, 'man_pronostico_select' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-pronostico' );
		wp_enqueue_script( 'man-muni-select' );

		$sel = MAN_Security::sanitizar_divipola( $atts['municipio'] );
		if ( 'departamento' === $sel || ! MAN_Municipios::existe( $sel ) ) {
			$sel = '52001';
		}
		$dias = max( 1, min( 16, (int) $atts['dias'] ) );
		$id   = $this->id();
		$cid  = $id . '-comp';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-muni-wrap"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>">
			<?php echo $this->select_municipio( $cid, $sel ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div id="<?php echo esc_attr( $cid ); ?>" class="man-muni-target" data-man-pronostico
				data-municipio="<?php echo esc_attr( $sel ); ?>" data-dias="<?php echo esc_attr( $dias ); ?>">
				<?php echo $this->skeleton( 'Cargando pronóstico…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo $this->pie_fuentes( 'Open-Meteo (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_hidrico_select] — recursos hídricos con <select> de municipios.
	 */
	public function sc_hidrico_select( $atts ) {
		$atts = $this->fusionar( array( 'municipio' => '52001' ), $atts, 'man_hidrico_select' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-hidrico' );
		wp_enqueue_script( 'man-muni-select' );

		$sel = MAN_Security::sanitizar_divipola( $atts['municipio'] );
		if ( 'departamento' === $sel || ! MAN_Municipios::existe( $sel ) ) {
			$sel = '52001';
		}
		$id  = $this->id();
		$cid = $id . '-comp';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-muni-wrap"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>">
			<?php echo $this->select_municipio( $cid, $sel ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div id="<?php echo esc_attr( $cid ); ?>" class="man-muni-target" data-man-hidrico
				data-municipio="<?php echo esc_attr( $sel ); ?>">
				<?php echo $this->skeleton( 'Cargando información hídrica…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php echo $this->pie_fuentes( 'Open-Meteo Flood (GloFAS) · Open-Meteo (CC BY 4.0)' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_estado_select] — semáforo/estado ENSO con un <select> de los 64
	 * municipios que recarga el componente al cambiar (AJAX, sin recargar).
	 */
	public function sc_estado_select( $atts ) {
		$atts = $this->fusionar( array( 'municipio' => '52001' ), $atts, 'man_estado_select' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-estado' );
		wp_enqueue_script( 'man-muni-select' );

		$sel = MAN_Security::sanitizar_divipola( $atts['municipio'] );
		if ( 'departamento' === $sel || ! MAN_Municipios::existe( $sel ) ) {
			$sel = '52001';
		}
		$id  = $this->id();
		$cid = $id . '-comp';

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-muni-wrap"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>">
			<?php echo $this->select_municipio( $cid, $sel ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<div id="<?php echo esc_attr( $cid ); ?>" class="man-muni-target" data-man-estado
				data-municipio="<?php echo esc_attr( $sel ); ?>">
				<?php echo $this->skeleton( 'Cargando estado del fenómeno…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderiza un <select> con los 64 municipios (ordenados por nombre) que
	 * controla el componente con id $target_id (vía municipio-select.js).
	 *
	 * @param string $target_id   Id del componente a recargar.
	 * @param string $seleccionado DIVIPOLA seleccionado por defecto.
	 * @return string
	 */
	private function select_municipio( $target_id, $seleccionado ) {
		$munis = MAN_Municipios::todos();
		usort( $munis, function ( $a, $b ) { return strcmp( $a['nombre'], $b['nombre'] ); } );

		ob_start();
		?>
		<label class="man-muni-select-label">Municipio:
			<select class="man-muni-select" data-man-muni-select data-target="<?php echo esc_attr( $target_id ); ?>"
				aria-label="Seleccionar municipio">
				<?php foreach ( $munis as $m ) : ?>
					<option value="<?php echo esc_attr( $m['divipola'] ); ?>" <?php selected( $m['divipola'], $seleccionado ); ?>>
						<?php echo esc_html( $m['nombre'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</label>
		<?php
		return ob_get_clean();
	}

	/** [man_mapa_descripcion] — texto descriptivo del mapa coroplético (sin el mapa). */
	public function sc_mapa_descripcion( $atts ) {
		$atts = $this->fusionar( array( 'variable' => 'riesgo', 'mes' => '' ), $atts, 'man_mapa_descripcion' );
		wp_enqueue_style( 'man-estilos' );
		return $this->bloque_texto_mapa( $atts, 'descripcion' );
	}

	/** [man_mapa_analisis] — análisis interpretativo del mapa coroplético (sin el mapa). */
	public function sc_mapa_analisis( $atts ) {
		$atts = $this->fusionar( array( 'variable' => 'riesgo', 'mes' => '' ), $atts, 'man_mapa_analisis' );
		wp_enqueue_style( 'man-estilos' );
		return $this->bloque_texto_mapa( $atts, 'analisis' );
	}

	/**
	 * Renderiza un bloque de texto (descripción o análisis) del mapa por variable.
	 *
	 * @param array  $atts Atributos (variable, mes).
	 * @param string $tipo descripcion | analisis.
	 * @return string
	 */
	private function bloque_texto_mapa( $atts, $tipo ) {
		$var = sanitize_key( $atts['variable'] );
		$txt = $this->texto_mapa( $var, $tipo );
		$cls = 'analisis' === $tipo ? 'man-g__analisis-desc man-mapa-analisis' : 'man-g__analisis-desc man-mapa-descripcion';
		return '<div class="man man-analisis-bloque" style="' . esc_attr( MAN_Estilos::estilo_inline( $atts ) ) . '">'
			. '<p class="' . esc_attr( $cls ) . '">' . esc_html( $txt ) . '</p></div>';
	}

	/**
	 * Textos ricos (≥375 caracteres) del mapa coroplético por variable.
	 *
	 * @param string $var  riesgo | anomalia | precipitacion.
	 * @param string $tipo descripcion | analisis.
	 * @return string
	 */
	private function texto_mapa( $var, $tipo ) {
		$t = array(
			'riesgo'        => array(
				'descripcion' => 'Este mapa coroplético colorea los 64 municipios de Nariño según su índice de riesgo ambiental compuesto (escala 0 a 1) para el mes seleccionado. El índice integra el empuje del fenómeno ENSO (magnitud del ONI), la anomalía de lluvia esperada, la exposición del territorio (población, ladera y costa, con cartografía del DANE) y la sensibilidad sectorial agrícola e hídrica de cada subregión. El color va de verde (bajo) a rojo (extremo); al hacer clic en un municipio se abre su panel con el detalle. Es un escenario de planeación, no un pronóstico oficial.',
				'analisis'    => 'Lectura del mapa: en El Niño, el altiplano andino y la cordillera tienden a concentrar el mayor riesgo por déficit de lluvia, mientras el litoral Pacífico (Sanquianga, Pacífico Sur, Telembí) responde de forma inversa, con exceso de precipitación y oleaje. Conviene mirar la evolución mes a mes: el pico del escenario se ubica hacia septiembre–octubre, cuando coinciden la temporada seca andina y la fase cálida del Pacífico. Use el mapa para priorizar municipios y contraste siempre con los boletines vigentes del IDEAM y de NOAA-CPC antes de tomar decisiones operativas.',
			),
			'anomalia'      => array(
				'descripcion' => 'Este mapa coroplético muestra, municipio a municipio, la anomalía climática esperada para el mes seleccionado en los 64 territorios de Nariño: cuánto se desvía la temperatura o la lluvia respecto a su valor normal histórico. Los tonos cálidos indican condiciones más secas o cálidas de lo habitual y los fríos lo contrario. La anomalía se deriva del pronóstico de Open-Meteo y de la fase ENSO vigente, y permite ver de un vistazo qué zonas se apartan más de su clima típico. Al pulsar un municipio se despliega su ficha con los valores concretos.',
				'analisis'    => 'Interpretación: las anomalías no son uniformes en el departamento. La vertiente andina (Sabana, Ex-Provincia de Obando, Río Mayo) suele mostrar déficit de lluvia en El Niño, con anomalías secas que estresan cultivos y acueductos; la franja del Pacífico, en cambio, puede registrar anomalías húmedas y mayor oleaje. La magnitud crece con la intensidad del ONI y con la cercanía a la primavera boreal, cuando la incertidumbre es mayor. Tome la anomalía como una señal de alerta temprana y verifíquela contra los boletines oficiales del IDEAM y NOAA-CPC.',
			),
			'precipitacion' => array(
				'descripcion' => 'Este mapa coroplético representa la precipitación esperada para el mes seleccionado en cada uno de los 64 municipios de Nariño, con una escala de color que va de seco a muy lluvioso. Los datos provienen del pronóstico en vivo de Open-Meteo combinado con la señal del fenómeno ENSO, de modo que refleja tanto el régimen climático propio de cada subregión como el empuje del Pacífico. La fuerte heterogeneidad del relieve nariñense hace que costa, cordillera y altiplano se comporten de forma muy distinta. Al hacer clic en un municipio se abre su panel con el detalle de precipitación.',
				'analisis'    => 'Cómo leerlo: el litoral Pacífico es estructuralmente muy húmedo (cientos de milímetros al mes) y en El Niño puede intensificar lluvias y oleaje; el altiplano andino y la cordillera son más secos y, en la fase cálida, tienden al déficit que dispara el riesgo de incendios y el racionamiento de acueductos. La precipitación mensual no debe leerse aislada: combínela con el índice de riesgo y el déficit hídrico para entender el impacto real. Recuerde que es un escenario de planeación y debe contrastarse con los boletines vigentes del IDEAM y de NOAA-CPC.',
			),
		);
		$bloque = isset( $t[ $var ] ) ? $t[ $var ] : $t['riesgo'];
		return isset( $bloque[ $tipo ] ) ? $bloque[ $tipo ] : $bloque['descripcion'];
	}

	/**
	 * [man_info] — descripción o análisis (texto, ≥375 car.) de un COMPONENTE
	 * que no es una vista del motor de gráficos (estado, pronóstico, hídrico,
	 * mar, salud, globo, timeline, animación, estaciones, datos).
	 *
	 * Atributos: elemento="estado|pronostico|hidrico|mar|salud|globo|timeline|
	 * animacion|estaciones|datos", tipo="descripcion|analisis".
	 */
	public function sc_info( $atts ) {
		$atts = $this->fusionar( array( 'elemento' => 'estado', 'tipo' => 'descripcion' ), $atts, 'man_info' );
		wp_enqueue_style( 'man-estilos' );
		$elemento = sanitize_key( $atts['elemento'] );
		$tipo     = ( 'analisis' === $atts['tipo'] ) ? 'analisis' : 'descripcion';
		$txt      = $this->info_elemento( $elemento, $tipo );
		if ( '' === $txt ) {
			return '';
		}
		$cls = 'analisis' === $tipo ? 'man-g__analisis-desc man-info--analisis' : 'man-g__analisis-desc man-info--descripcion';
		return '<div class="man man-analisis-bloque" style="' . esc_attr( MAN_Estilos::estilo_inline( $atts ) ) . '">'
			. '<p class="' . esc_attr( $cls ) . '">' . esc_html( $txt ) . '</p></div>';
	}

	/**
	 * Texto (descripcion|analisis) de un componente, desde
	 * includes/data/textos-elementos.php (cacheado en memoria).
	 *
	 * @param string $elemento Slug del componente.
	 * @param string $tipo     descripcion | analisis.
	 * @return string
	 */
	private function info_elemento( $elemento, $tipo ) {
		static $t = null;
		if ( null === $t ) {
			$ruta = MAN_DIR . 'includes/data/textos-elementos.php';
			$t    = is_readable( $ruta ) ? include $ruta : array();
			if ( ! is_array( $t ) ) {
				$t = array();
			}
		}
		return isset( $t[ $elemento ][ $tipo ] ) ? $t[ $elemento ][ $tipo ] : '';
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
			'tipo'     => 'oni',
			'hasta'    => '2027-02',
			'mes'      => gmdate( 'Y-m' ),
			'alto'     => '360px',
			'theme'    => 'claro',
			'analisis' => 'ambos',
		), $atts, 'man_estadisticas' );

		// Mapea el "tipo" amistoso a (vista, gráfico) del motor interactivo.
		$mapa = array(
			'oni'          => array( 'oni_serie', 'line' ),
			'observado'    => array( 'oni_observado', 'line' ),
			'pronostico'   => array( 'oni_pronostico', 'line' ),
			'probabilidad' => array( 'prob_fase', 'stacked_bar' ),
			'riesgo'       => array( 'riesgo_subregion', 'treemap' ),
			'municipios'   => array( 'riesgo_municipios', 'bar' ),
		);
		$tipo = sanitize_key( $atts['tipo'] );
		$par  = isset( $mapa[ $tipo ] ) ? $mapa[ $tipo ] : $mapa['oni'];

		return $this->figura_grafico( $par[0], $par[1], array(
			'theme'    => $atts['theme'],
			'alto'     => $atts['alto'],
			'hasta'    => $atts['hasta'],
			'mes'      => $atts['mes'],
			'analisis' => $atts['analisis'],
			'fuente'   => 'NOAA/CPC · IDEAM · D3plus',
		) );
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
	 * [man_grafico] — Tarjeta de gráfico D3plus con barra de herramientas
	 * (Detalle, Compartir, Datos, Imagen PNG, Descarga JSON, Cambiar tipo en
	 * vivo) y modales. Motor genérico de 3 capas (ver archivo /skill).
	 *
	 * view: oni_serie | prob_fase | riesgo_subregion | riesgo_municipios | episodios.
	 */
	public function sc_grafico( $atts ) {
		$atts = $this->fusionar( array(
			'view'         => 'oni_serie',
			'type'         => '',
			'actions'      => '',
			'theme'        => 'claro',
			'legend'       => 'si',
			'legend_style' => 'text',
			'toolbar'      => 'si',
			'alto'         => '420px',
			'hasta'        => '2027-02',
			'mes'          => gmdate( 'Y-m' ),
			'grupo'        => '',
			'legend_pos'   => 'abajo',
			'analisis'     => 'no', // por defecto SIN texto en el gráfico; usa [man_descripcion]/[man_analisis_*].
		), $atts, 'man_grafico' );

		return $this->figura_grafico( sanitize_key( $atts['view'] ), sanitize_key( $atts['type'] ), array(
			'theme'        => $atts['theme'],
			'actions'      => $atts['actions'],
			'legend'       => $atts['legend'],
			'legend_style' => $atts['legend_style'],
			'toolbar'      => $atts['toolbar'],
			'alto'         => $atts['alto'],
			'hasta'        => $atts['hasta'],
			'mes'          => $atts['mes'],
			'grupo'        => $atts['grupo'],
			'legend_pos'   => $atts['legend_pos'],
			'analisis'     => $atts['analisis'],
		) );
	}

	/**
	 * [man_analisis] — SOLO el texto de análisis (descriptivo y/o cuantitativo)
	 * de una vista, SIN la gráfica. Permite separar el gráfico de su descripción
	 * y maquetarlos en lugares distintos. Si se indica un "grupo", se sincroniza
	 * con el [man_grafico grupo="…"] del mismo grupo (lee su misma vista/mes).
	 *
	 * view  : oni_serie | oni_observado | oni_pronostico | prob_fase |
	 *         riesgo_subregion | riesgo_municipios | episodios.
	 * modo  : ambos | descriptivo | cuantitativo.
	 * grupo : (opcional) enlaza el análisis a un grupo de gráficos.
	 */
	public function sc_analisis( $atts ) {
		$atts = $this->fusionar( array(
			'view'   => 'oni_serie',
			'type'   => '',
			'modo'   => 'ambos',
			'hasta'  => '2027-02',
			'mes'    => gmdate( 'Y-m' ),
			'titulo' => '',
			'grupo'  => '',
		), $atts, 'man_analisis' );
		$modo = in_array( $atts['modo'], array( 'ambos', 'descriptivo', 'cuantitativo', 'descripcion', 'como_funciona' ), true ) ? $atts['modo'] : 'ambos';
		return $this->bloque_analisis( $atts, $modo );
	}

	/**
	 * [man_descripcion] — SOLO la descripción breve de la vista (qué muestra).
	 */
	public function sc_descripcion( $atts ) {
		$atts = $this->fusionar( $this->defaults_bloque(), $atts, 'man_descripcion' );
		return $this->bloque_analisis( $atts, 'descripcion' );
	}

	/**
	 * [man_analisis_cualitativo] — SOLO el párrafo cualitativo (interpretación).
	 */
	public function sc_analisis_cualitativo( $atts ) {
		$atts = $this->fusionar( $this->defaults_bloque(), $atts, 'man_analisis_cualitativo' );
		return $this->bloque_analisis( $atts, 'descriptivo' );
	}

	/**
	 * [man_analisis_cuantitativo] — SOLO las cifras clave calculadas del dato.
	 */
	public function sc_analisis_cuantitativo( $atts ) {
		$atts = $this->fusionar( $this->defaults_bloque(), $atts, 'man_analisis_cuantitativo' );
		return $this->bloque_analisis( $atts, 'cuantitativo' );
	}

	/**
	 * [man_explicacion] — "¿Cómo funciona?" de la vista (qué calcula y su fuente).
	 */
	public function sc_explicacion( $atts ) {
		$atts = $this->fusionar( $this->defaults_bloque(), $atts, 'man_explicacion' );
		return $this->bloque_analisis( $atts, 'como_funciona' );
	}

	/** Atributos por defecto de los bloques de texto separados. */
	private function defaults_bloque() {
		return array(
			'view'   => 'oni_serie',
			'type'   => '',
			'hasta'  => '2027-02',
			'mes'    => gmdate( 'Y-m' ),
			'titulo' => '',
			'grupo'  => '',
		);
	}

	/**
	 * Render compartido del bloque de texto [data-man-analisis] (lo hidrata
	 * grafico.js). $modo: ambos | descriptivo | cuantitativo | descripcion | como_funciona.
	 *
	 * @param array  $atts Atributos del shortcode.
	 * @param string $modo Pieza a mostrar.
	 * @return string
	 */
	private function bloque_analisis( $atts, $modo ) {
		wp_enqueue_style( 'man-grafico-css' );
		wp_enqueue_script( 'man-grafico' );

		$id     = $this->id();
		$view   = sanitize_key( $atts['view'] );
		$type   = sanitize_key( $atts['type'] );
		$grupo  = sanitize_key( $atts['grupo'] );
		$hasta  = MAN_Security::sanitizar_mes( $atts['hasta'] );
		if ( $hasta <= gmdate( 'Y-m' ) ) {
			$hasta = '2027-02';
		}
		$mes    = MAN_Security::sanitizar_mes( $atts['mes'] );
		$titulo = sanitize_text_field( $atts['titulo'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-g__analisis man-analisis-bloque man-analisis--<?php echo esc_attr( $modo ); ?>"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-analisis
			data-view="<?php echo esc_attr( $view ); ?>"
			data-type="<?php echo esc_attr( $type ); ?>"
			data-modo="<?php echo esc_attr( $modo ); ?>"
			data-hasta="<?php echo esc_attr( $hasta ); ?>"
			data-mes="<?php echo esc_attr( $mes ); ?>"
			data-grupo="<?php echo esc_attr( $grupo ); ?>"
			data-titulo="<?php echo esc_attr( $titulo ); ?>"
			aria-live="polite">
			<?php echo $this->skeleton( 'Calculando…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_filtro] — Control composable (vista | tipo | mes) que actualiza, vía
	 * el grupo, a los [man_grafico grupo="…"] del mismo grupo.
	 */
	public function sc_filtro( $atts ) {
		$atts = $this->fusionar( array(
			'grupo'   => 'enso',
			'control' => 'vista',
			'inicio'  => '2026-03',
			'fin'     => '2027-03',
		), $atts, 'man_filtro' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-composable' );

		$id      = $this->id();
		$grupo   = sanitize_key( $atts['grupo'] );
		$control = in_array( $atts['control'], array( 'vista', 'tipo', 'mes' ), true ) ? $atts['control'] : 'vista';
		$inicio  = MAN_Security::sanitizar_mes( $atts['inicio'] );
		$fin     = MAN_Security::sanitizar_mes( $atts['fin'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-filtro"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-filtro
			data-grupo="<?php echo esc_attr( $grupo ); ?>"
			data-control="<?php echo esc_attr( $control ); ?>"
			data-inicio="<?php echo esc_attr( $inicio ); ?>"
			data-fin="<?php echo esc_attr( $fin ); ?>">
			<?php echo $this->skeleton( 'Cargando filtro…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [man_panel] — Título, descripción y detalles del gráfico vigente del grupo.
	 */
	public function sc_panel( $atts ) {
		$atts = $this->fusionar( array( 'grupo' => 'enso' ), $atts, 'man_panel' );

		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_script( 'man-composable' );

		$id    = $this->id();
		$grupo = sanitize_key( $atts['grupo'] );

		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>"
			class="man man-panel"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>"
			data-man-panel
			data-grupo="<?php echo esc_attr( $grupo ); ?>">
			<?php echo $this->skeleton( 'Esperando el gráfico del grupo…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'Detalles del gráfico vigente' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renderiza la figura del motor de gráficos D3plus (capa 1). Reutilizable
	 * por [man_grafico], [man_estadisticas] y [man_historico] — un solo motor
	 * interactivo (ejes, tooltip, leyenda, barra de herramientas) para todos.
	 *
	 * @param string $view Id de la vista.
	 * @param string $type Tipo de gráfico (vacío = por defecto de la vista).
	 * @param array  $opts {theme, actions, legend, legend_style, toolbar, alto, hasta, mes, fuente}.
	 * @return string
	 */
	private function figura_grafico( $view, $type, $opts = array() ) {
		$o = array_merge( array(
			'theme'        => 'claro',
			'actions'      => '',
			'legend'       => 'si',
			'legend_style' => 'text',
			'toolbar'      => 'si',
			'alto'         => '420px',
			'hasta'        => '2027-02',
			'mes'          => gmdate( 'Y-m' ),
			'grupo'        => '',
			'legend_pos'   => 'abajo',
			'analisis'     => 'no', // sin texto embebido por defecto (va en shortcodes aparte).
			'fuente'       => 'NOAA/CPC · IDEAM · Open-Meteo (CC BY 4.0) · D3plus',
		), $opts );

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'man-grafico-css' );
		wp_enqueue_script( 'man-grafico' );

		$id      = $this->id();
		$grupo   = sanitize_key( $o['grupo'] );
		$tema    = in_array( $o['theme'], array( 'dark', 'oscuro' ), true ) ? 'dark' : 'claro';
		$alto    = preg_match( '/^\d{1,4}(px|vh|rem|em|%)$/', $o['alto'] ) ? $o['alto'] : '420px';
		$hasta   = MAN_Security::sanitizar_mes( $o['hasta'] );
		if ( $hasta <= gmdate( 'Y-m' ) ) {
			$hasta = '2027-02';
		}
		$mes     = MAN_Security::sanitizar_mes( $o['mes'] );
		$actions = preg_replace( '/[^a-z,_]/', '', strtolower( (string) $o['actions'] ) );
		$legend  = ( 'no' === $o['legend'] || '0' === (string) $o['legend'] ) ? '0' : '1';
		$lstyle  = 'icons' === $o['legend_style'] ? 'icons' : 'text';
		$toolbar = ( 'no' === $o['toolbar'] || '0' === (string) $o['toolbar'] ) ? '0' : '1';
		$posmap  = array( 'abajo' => 'bottom', 'arriba' => 'top', 'derecha' => 'right', 'izquierda' => 'left', 'bottom' => 'bottom', 'top' => 'top', 'right' => 'right', 'left' => 'left' );
		$lpos    = isset( $posmap[ $o['legend_pos'] ] ) ? $posmap[ $o['legend_pos'] ] : 'bottom';
		$analisis = in_array( $o['analisis'], array( 'ambos', 'descriptivo', 'cuantitativo', 'no' ), true ) ? $o['analisis'] : 'ambos';

		ob_start();
		?>
		<figure id="<?php echo esc_attr( $id ); ?>"
			class="man-g man-g--<?php echo esc_attr( $tema ); ?>"
			data-man-grafico
			data-view="<?php echo esc_attr( $view ); ?>"
			data-type="<?php echo esc_attr( $type ); ?>"
			data-actions="<?php echo esc_attr( $actions ); ?>"
			data-legend="<?php echo esc_attr( $legend ); ?>"
			data-legend-style="<?php echo esc_attr( $lstyle ); ?>"
			data-toolbar="<?php echo esc_attr( $toolbar ); ?>"
			data-hasta="<?php echo esc_attr( $hasta ); ?>"
			data-mes="<?php echo esc_attr( $mes ); ?>"
			data-grupo="<?php echo esc_attr( $grupo ); ?>"
			data-legend-pos="<?php echo esc_attr( $lpos ); ?>"
			data-analisis="<?php echo esc_attr( $analisis ); ?>">
			<figcaption class="man-g__title">Gráfico</figcaption>
			<div class="man-g__chart" id="<?php echo esc_attr( $id ); ?>-chart"
				style="min-height:<?php echo esc_attr( $alto ); ?>"></div>
			<?php echo $this->skeleton( 'Cargando gráfico…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="man-fuentes">Fuente: <?php echo esc_html( $o['fuente'] ); ?></p>
		</figure>
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

	/**
	 * [man_estaciones] — Mapa de estaciones hidrológicas IDEAM/FEWS de Nariño,
	 * con marcadores por nivel de alerta; al hacer clic se ve el detalle y la
	 * serie de nivel de la estación.
	 */
	public function sc_estaciones( $atts ) {
		$atts = $this->fusionar( array( 'alto' => '460px', 'variable' => 'nivel' ), $atts, 'man_estaciones' );
		wp_enqueue_style( 'man-estilos' );
		wp_enqueue_style( 'leaflet' );
		wp_enqueue_script( 'man-estaciones' );
		$id   = $this->id();
		$alto = preg_match( '/^\d{1,4}(px|vh|rem|em|%)$/', $atts['alto'] ) ? $atts['alto'] : '460px';
		$var  = in_array( $atts['variable'], array( 'nivel', 'precipitacion', 'caudal', 'temperatura' ), true ) ? $atts['variable'] : 'nivel';
		ob_start();
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="man man-estaciones"
			style="<?php echo esc_attr( MAN_Estilos::estilo_inline( $atts ) ); ?>" data-man-estaciones
			data-variable="<?php echo esc_attr( $var ); ?>">
			<div class="man-estaciones__mapa" style="height:<?php echo esc_attr( $alto ); ?>"></div>
			<div class="man-estaciones__info"></div>
			<?php echo $this->skeleton( 'Cargando estaciones IDEAM/FEWS…' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php echo $this->pie_fuentes( 'IDEAM — FEWS (visorfews) · OpenStreetMap' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
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
