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
		add_shortcode( 'man_filtro', array( $this, 'sc_filtro' ) );
		add_shortcode( 'man_panel', array( $this, 'sc_panel' ) );
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
		wp_register_script( 'd3plus', 'https://cdn.jsdelivr.net/npm/@d3plus/core@3.1.4/umd/d3plus-core.full.js', array(), '3.1.4', true );
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
		// Motor de gráficos D3plus con barra de herramientas ([man_grafico]).
		wp_register_style( 'man-grafico-css', MAN_URL . 'assets/css/grafico.css', array(), MAN_VERSION );
		wp_register_script( 'man-renderer', MAN_URL . 'assets/js/renderer.js', array( 'd3plus' ), MAN_VERSION, true );
		// Bus de estado por grupo + componentes composables ([man_filtro], [man_panel]).
		wp_register_script( 'man-grupo', MAN_URL . 'assets/js/grupo.js', array(), MAN_VERSION, true );
		wp_register_script( 'man-grafico', MAN_URL . 'assets/js/grafico.js', array( 'man-renderer', 'man-core', 'man-grupo' ), MAN_VERSION, true );
		wp_register_script( 'man-composable', MAN_URL . 'assets/js/composable.js', array( 'man-core', 'man-grupo' ), MAN_VERSION, true );
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

			<div class="man-timeline__identidad">
				<img class="man-timeline__logo" src="<?php echo esc_url( MAN_URL . 'assets/img/TIC.png' ); ?>"
					alt="Gobernación de Nariño · Secretaría TIC" onerror="this.style.display='none'" />
				<span class="man-timeline__separador" aria-hidden="true"></span>
				<strong class="man-timeline__titulo"><?php echo esc_html( sanitize_text_field( $atts['titulo'] ) ); ?></strong>
				<span class="man-timeline__estado-chip" aria-live="polite">—</span>
			</div>

			<div class="man-timeline__controles">
				<button type="button" class="man-timeline__btn" data-accion="anterior" aria-label="Mes anterior">◀</button>
				<button type="button" class="man-timeline__btn man-timeline__btn--play" data-accion="play" aria-label="Reproducir o pausar">▶</button>
				<button type="button" class="man-timeline__btn" data-accion="siguiente" aria-label="Mes siguiente">▶▶</button>
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

			<div class="man-timeline__slider">
				<span class="man-timeline__divisor" aria-hidden="true"></span>
				<input type="range" class="man-timeline__rango" min="0" max="0" step="1" value="0" aria-label="Mes activo" />
				<ul class="man-timeline__marcas" role="group" aria-label="Meses"></ul>
				<p class="man-timeline__leyenda">
					<span class="man-timeline__leyenda-item"><span class="man-timeline__pip man-timeline__pip--obs"></span>Observado</span>
					<span class="man-timeline__leyenda-item"><span class="man-timeline__pip man-timeline__pip--proy"></span>Proyectado</span>
				</p>
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
			'desde' => '',
			'fin'   => '',
			'alto'  => '360px',
			'theme' => 'claro',
		), $atts, 'man_historico' );

		return $this->figura_grafico( 'episodios', 'bar', array(
			'theme'  => $atts['theme'],
			'alto'   => $atts['alto'],
			'fuente' => 'NOAA/CPC · IDEAM (episodios ENSO)',
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
			'theme' => 'claro',
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
			'theme'  => $atts['theme'],
			'alto'   => $atts['alto'],
			'hasta'  => $atts['hasta'],
			'mes'    => $atts['mes'],
			'fuente' => 'NOAA/CPC · IDEAM · D3plus',
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
		) );
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
			data-legend-pos="<?php echo esc_attr( $lpos ); ?>">
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
