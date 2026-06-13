<?php
/**
 * Panel de administración: menú, salud de APIs (Sección 11.1), página de
 * Fuentes (config por API) y página de Apariencia (módulo de estilos).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Admin {

	/** @var MAN_Api_Config */
	private $config;

	public function __construct() {
		$this->config = new MAN_Api_Config();
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_man_guardar_estilo', array( $this, 'guardar_estilo' ) );
	}

	/**
	 * Registra el menú y submenús.
	 */
	public function menu() {
		add_menu_page(
			'Monitor Ambiental',
			'Monitor Ambiental',
			'manage_options',
			'man-salud',
			array( $this, 'pagina_salud' ),
			'dashicons-chart-area',
			58
		);
		add_submenu_page( 'man-salud', 'Salud de APIs', 'Salud de APIs', 'manage_options', 'man-salud', array( $this, 'pagina_salud' ) );
		add_submenu_page( 'man-salud', 'Elementos y shortcodes', 'Elementos', 'manage_options', 'man-elementos', array( $this, 'pagina_elementos' ) );
		add_submenu_page( 'man-salud', 'Fuentes de datos', 'Fuentes', 'manage_options', 'man-fuentes', array( $this, 'pagina_fuentes' ) );
		add_submenu_page( 'man-salud', 'Apariencia', 'Apariencia', 'manage_options', 'man-apariencia', array( $this, 'pagina_apariencia' ) );
	}

	/**
	 * Encola assets del admin solo en las páginas del plugin.
	 *
	 * @param string $hook Hook de la página actual.
	 */
	public function assets( $hook ) {
		if ( false === strpos( $hook, 'man-' ) ) {
			return;
		}
		wp_enqueue_script( 'man-admin', MAN_URL . 'assets/js/admin.js', array(), MAN_VERSION, true );
		wp_localize_script( 'man-admin', 'MANADMIN', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'man_admin' ),
		) );
		wp_add_inline_style( 'common', '.man-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:14px 0;max-width:760px}.man-card h2 code{font-size:11px;color:#787c82;background:#f0f0f1;padding:2px 6px;border-radius:4px}.man-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}.man-resultado{margin-left:10px;font-style:italic}.man-tabla-salud td,.man-tabla-salud th{padding:8px 10px}'
			. '.man-el-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;margin:10px 0 26px}.man-el-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}.man-el-card h3{margin:0 0 2px;font-size:14px}.man-el-card h3 code{font-size:12px;background:#eef2f7;color:#1d4ed8;padding:1px 6px;border-radius:4px}.man-el-desc{color:#50575e;margin:.3em 0 .6em;font-size:13px}.man-el-attrs{margin:.2em 0 .7em;padding-left:1.1em;font-size:12px;color:#50575e}.man-el-attrs code{background:#f0f0f1;padding:0 4px;border-radius:3px}.man-el-copy{display:flex;gap:6px;align-items:center}.man-el-input{flex:1;font-family:Menlo,Consolas,monospace;font-size:12px;padding:6px 8px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7}.man-el-card .button{white-space:nowrap}.man-el-intro{max-width:820px}.man-el-grp{margin:22px 0 6px;font-size:15px;border-bottom:1px solid #e0e0e0;padding-bottom:4px}' );
	}

	/* ----------------------------------------------------------------- */
	/* Páginas                                                           */
	/* ----------------------------------------------------------------- */

	/**
	 * Página de salud de las APIs + auditoría reciente.
	 */
	public function pagina_salud() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$apis = MAN_Rest::construir_estado_apis();
		?>
		<div class="wrap">
			<h1>Monitor Ambiental — Salud de las APIs</h1>
			<?php $this->aviso(); ?>
			<table class="widefat striped man-tabla-salud">
				<thead><tr><th>Fuente</th><th>Estado</th><th>Última sincronización</th><th>Resultado</th></tr></thead>
				<tbody>
				<?php foreach ( $apis as $a ) : ?>
					<tr>
						<td><?php echo esc_html( $a['fuente'] ); ?></td>
						<td><span class="man-dot" style="background:<?php echo esc_attr( $this->color_estado( $a['estado'] ) ); ?>"></span><?php echo esc_html( ucfirst( $a['estado'] ) ); ?></td>
						<td><?php echo esc_html( $a['ultima'] ); ?></td>
						<td><?php echo esc_html( $a['resultado'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Auditoría reciente</h2>
			<table class="widefat striped">
				<thead><tr><th>Fecha (UTC)</th><th>Evento</th><th>Fuente</th><th>Resultado</th><th>Reg.</th><th>Detalle</th></tr></thead>
				<tbody>
				<?php foreach ( $this->auditoria_reciente() as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['ts'] ); ?></td>
						<td><?php echo esc_html( $row['evento'] ); ?></td>
						<td><?php echo esc_html( $row['fuente'] ); ?></td>
						<td><?php echo esc_html( $row['resultado'] ); ?></td>
						<td><?php echo esc_html( $row['registros'] ); ?></td>
						<td><?php echo esc_html( $row['detalle'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Página de Elementos: catálogo de todos los shortcodes con descripción,
	 * atributos y botón de copiar, para maquetar el sitio a la medida.
	 */
	public function pagina_elementos() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$grupos = $this->catalogo_shortcodes();
		?>
		<div class="wrap">
			<h1>Monitor Ambiental — Elementos y shortcodes</h1>
			<p class="man-el-intro">Cada elemento es un <strong>componente independiente</strong>. Copia su shortcode y pégalo
				en cualquier página, entrada o widget (incluido el bloque <em>Shortcode</em> o el widget HTML de Elementor) para
				maquetar el sitio como quieras. Por defecto son <strong>minimalistas y transparentes</strong>; ajusta el aspecto
				global en <a href="<?php echo esc_url( admin_url( 'admin.php?page=man-apariencia' ) ); ?>">Apariencia</a> o por
				atributo (<code>fondo</code>, <code>acento</code>, <code>borde</code>, <code>radio</code>, <code>sombra</code>,
				<code>ancho</code>, <code>espaciado</code>).</p>

			<?php foreach ( $grupos as $grupo => $items ) : ?>
				<h2 class="man-el-grp"><?php echo esc_html( $grupo ); ?></h2>
				<div class="man-el-grid">
					<?php foreach ( $items as $sc ) : ?>
						<div class="man-el-card">
							<h3><?php echo esc_html( $sc['titulo'] ); ?> <code><?php echo esc_html( '[' . $sc['tag'] . ']' ); ?></code></h3>
							<p class="man-el-desc"><?php echo esc_html( $sc['desc'] ); ?></p>
							<?php if ( ! empty( $sc['attrs'] ) ) : ?>
								<ul class="man-el-attrs">
									<?php foreach ( $sc['attrs'] as $attr ) : ?>
										<li><?php echo wp_kses( $attr, array( 'code' => array() ) ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<div class="man-el-copy">
								<input type="text" class="man-el-input" readonly
									value="<?php echo esc_attr( $sc['ejemplo'] ); ?>"
									onfocus="this.select()" />
								<button type="button" class="button button-primary man-copiar"
									data-copy="<?php echo esc_attr( $sc['ejemplo'] ); ?>">Copiar</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Catálogo de shortcodes agrupado para la página de Elementos.
	 *
	 * @return array<string,array<int,array>>
	 */
	private function catalogo_shortcodes() {
		return array(
			'Predicción y análisis' => array(
				array(
					'tag'     => 'man_prediccion',
					'titulo'  => 'Predicción ENSO',
					'desc'    => 'Trayectoria del ONI hasta el mes objetivo (feb-2027) con banda de incertidumbre, umbrales de fase, probabilidad por trimestre y texto predictivo. Reveal animado.',
					'attrs'   => array( '<code>hasta</code> — mes objetivo AAAA-MM', '<code>modelo</code> — si/no (línea del modelo propio)', '<code>probabilidad</code> — si/no (barras por trimestre)' ),
					'ejemplo' => '[man_prediccion hasta="2027-02"]',
				),
				array(
					'tag'     => 'man_estadisticas',
					'titulo'  => 'Estadísticas (D3plus)',
					'desc'    => 'Gráficos prediseñados con tooltip y leyenda: ONI observado+proyectado, probabilidad de fase por trimestre o riesgo medio por subregión.',
					'attrs'   => array( '<code>tipo</code> — oni | probabilidad | riesgo', '<code>hasta</code> — mes objetivo (oni/probabilidad)', '<code>mes</code> — mes del riesgo', '<code>alto</code> — ej. 360px' ),
					'ejemplo' => '[man_estadisticas tipo="oni" hasta="2027-02"]',
				),
				array(
					'tag'     => 'man_estado',
					'titulo'  => 'Estado actual',
					'desc'    => 'Semáforo ENSO (gauge D3) + ONI vigente, fase, intensidad y texto de análisis.',
					'attrs'   => array( '<code>municipio</code> — DIVIPOLA, nombre o departamento', '<code>compacto</code> — si/no' ),
					'ejemplo' => '[man_estado municipio="departamento"]',
				),
				array(
					'tag'     => 'man_historico',
					'titulo'  => 'Histórico ENSO',
					'desc'    => 'Episodios de El Niño 2015–2024: barras de ONI pico y tarjetas de impacto en Nariño.',
					'attrs'   => array( '<code>desde</code> — año inicial', '<code>hasta</code> — año final' ),
					'ejemplo' => '[man_historico]',
				),
			),
			'Animación y globo 3D'  => array(
				array(
					'tag'     => 'man_animacion',
					'titulo'  => 'Animación del fenómeno',
					'desc'    => 'Esquema animado (Anime.js) del Pacífico ecuatorial: alisios, piscina cálida, termoclina y lluvias. Compara Neutral / El Niño / La Niña.',
					'attrs'   => array( '<code>estado</code> — neutral | el_nino | la_nina', '<code>autoplay</code> — si/no' ),
					'ejemplo' => '[man_animacion estado="el_nino"]',
				),
				array(
					'tag'     => 'man_globo',
					'titulo'  => 'Globo 3D',
					'desc'    => 'Globo terráqueo cinematográfico (Three.js) con la anomalía del Pacífico y el foco de Nariño. Modo ligero automático.',
					'attrs'   => array( '<code>calidad</code> — auto | alta | baja', '<code>autorotar</code> — si/no' ),
					'ejemplo' => '[man_globo calidad="auto"]',
				),
				array(
					'tag'     => 'man_timeline',
					'titulo'  => 'Línea de tiempo',
					'desc'    => 'Slider de meses del ONI que controla el globo (emite el evento man:mes).',
					'attrs'   => array( '<code>inicio</code> — AAAA-MM', '<code>fin</code> — AAAA-MM' ),
					'ejemplo' => '[man_timeline]',
				),
			),
			'Mapa y territorio'     => array(
				array(
					'tag'     => 'man_mapa',
					'titulo'  => 'Mapa coroplético',
					'desc'    => 'Mapa Leaflet de los 64 municipios por variable; clic en un municipio abre su panel.',
					'attrs'   => array( '<code>variable</code> — riesgo | anomalia | precipitacion', '<code>mes</code> — AAAA-MM' ),
					'ejemplo' => '[man_mapa variable="riesgo" mes="2026-10"]',
				),
				array(
					'tag'     => 'man_pronostico',
					'titulo'  => 'Pronóstico 7–16 días',
					'desc'    => 'Pronóstico en vivo (Open-Meteo) por municipio con gráfico y texto de análisis.',
					'attrs'   => array( '<code>municipio</code> — DIVIPOLA o nombre', '<code>dias</code> — 1 a 16' ),
					'ejemplo' => '[man_pronostico municipio="52001" dias="14"]',
				),
				array(
					'tag'     => 'man_hidrico',
					'titulo'  => 'Recursos hídricos',
					'desc'    => 'Caudal de ríos (GloFAS) y humedad de suelo por municipio.',
					'attrs'   => array( '<code>municipio</code> — DIVIPOLA o nombre' ),
					'ejemplo' => '[man_hidrico municipio="52001"]',
				),
				array(
					'tag'     => 'man_mar',
					'titulo'  => 'Mar y oleaje',
					'desc'    => 'Nivel del mar (IOC) y oleaje del Pacífico (Open-Meteo Marine) frente a Tumaco.',
					'attrs'   => array( '<code>estacion</code> — código de estación (opcional)' ),
					'ejemplo' => '[man_mar]',
				),
				array(
					'tag'     => 'man_salud',
					'titulo'  => 'Salud y clima',
					'desc'    => 'Casos de dengue (SIVIGILA) sensibles al clima.',
					'attrs'   => array( '<code>evento</code> — dengue', '<code>anio</code> — año' ),
					'ejemplo' => '[man_salud evento="dengue"]',
				),
			),
			'Datos abiertos'        => array(
				array(
					'tag'     => 'man_datos',
					'titulo'  => 'Descarga de datos',
					'desc'    => 'Botones de Descargar JSON/CSV, Ver API y Copiar URL para datos abiertos (CC BY 4.0).',
					'attrs'   => array( '<code>recurso</code> — municipios | oni | prediccion | municipio', '<code>municipio</code>, <code>mes</code>', '<code>texto</code> — rótulo' ),
					'ejemplo' => '[man_datos recurso="prediccion" texto="Descarga la predicción del ONI"]',
				),
				array(
					'tag'     => 'man_estado_api',
					'titulo'  => 'Salud de las APIs',
					'desc'    => 'Panel público del estado de cada fuente de datos del plugin.',
					'attrs'   => array(),
					'ejemplo' => '[man_estado_api]',
				),
			),
		);
	}

	/**
	 * Página de fuentes (delegada a MAN_Api_Config).
	 */
	public function pagina_fuentes() {
		?>
		<div class="wrap">
			<h1>Monitor Ambiental — Fuentes de datos</h1>
			<?php $this->aviso(); ?>
			<p>Cada fuente se puede activar, reconfigurar (URL/dataset-id), probar y sincronizar sin tocar código.</p>
			<?php $this->config->render(); ?>
		</div>
		<?php
	}

	/**
	 * Página de apariencia (módulo de estilos configurable).
	 */
	public function pagina_apariencia() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$e      = MAN_Estilos::estilo();
		$campos = array(
			'fondo'          => array( 'Fondo', 'transparent · color · inherit' ),
			'texto'          => array( 'Color de texto', 'inherit o un color' ),
			'tipografia'     => array( 'Tipografía', 'inherit o una familia' ),
			'acento'         => array( 'Acento principal', 'verde institucional' ),
			'acento_2'       => array( 'Acento secundario', 'amarillo institucional' ),
			'acento_tecnico' => array( 'Acento técnico', 'azul profundo' ),
			'mute'           => array( 'Texto secundario', '' ),
			'borde'          => array( 'Borde', 'none o ancho ej. 1px' ),
			'borde_color'    => array( 'Color de borde', '' ),
			'borde_radio'    => array( 'Radio de esquina', 'ej. 0 u 8px' ),
			'sombra'         => array( 'Sombra', 'none o valor box-shadow' ),
			'ancho_max'      => array( 'Ancho máximo', 'ej. 100% u 720px' ),
			'espaciado'      => array( 'Espaciado interno', 'ej. 0 o 12px' ),
		);
		?>
		<div class="wrap">
			<h1>Monitor Ambiental — Apariencia</h1>
			<?php $this->aviso(); ?>
			<p>Por defecto los shortcodes son <strong>minimalistas y transparentes</strong> (sin bordes, sombras ni fondo) para fundirse con tu página. Ajusta aquí solo lo que necesites.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="man-card" style="max-width:560px">
				<input type="hidden" name="action" value="man_guardar_estilo" />
				<?php wp_nonce_field( 'man_estilo' ); ?>
				<table class="form-table">
					<?php foreach ( $campos as $clave => $meta ) : ?>
						<tr>
							<th scope="row"><label for="man-est-<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $meta[0] ); ?></label></th>
							<td>
								<input type="text" id="man-est-<?php echo esc_attr( $clave ); ?>"
									name="man_estilo[<?php echo esc_attr( $clave ); ?>]"
									value="<?php echo esc_attr( isset( $e[ $clave ] ) ? $e[ $clave ] : '' ); ?>"
									class="regular-text" />
								<?php if ( '' !== $meta[1] ) : ?><p class="description"><?php echo esc_html( $meta[1] ); ?></p><?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( 'Guardar apariencia' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Guarda la opción de apariencia.
	 */
	public function guardar_estilo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'man_estilo' );

		$def    = MAN_Activator::estilo_por_defecto();
		$in     = isset( $_POST['man_estilo'] ) && is_array( $_POST['man_estilo'] ) ? wp_unslash( $_POST['man_estilo'] ) : array();
		$limpio = array();
		foreach ( $def as $clave => $valor_def ) {
			$limpio[ $clave ] = isset( $in[ $clave ] ) ? MAN_Estilos::sanitizar_css( $in[ $clave ] ) : $valor_def;
			if ( '' === $limpio[ $clave ] ) {
				$limpio[ $clave ] = $valor_def;
			}
		}
		update_option( 'man_estilo', $limpio );

		wp_safe_redirect( add_query_arg( array( 'page' => 'man-apariencia', 'man_msg' => 'guardado' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Muestra el aviso de guardado si procede.
	 */
	private function aviso() {
		if ( isset( $_GET['man_msg'] ) && 'guardado' === $_GET['man_msg'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>Cambios guardados.</p></div>';
		}
	}

	/**
	 * Color del semáforo según estado.
	 *
	 * @param string $estado Estado.
	 * @return string Hex.
	 */
	private function color_estado( $estado ) {
		switch ( $estado ) {
			case 'ok':
				return '#2e7d32';
			case 'degradado':
				return '#f9a825';
			case 'caido':
				return '#c62828';
			case 'sin datos':
				return '#ef6c00';
			default:
				return '#9aa0a6';
		}
	}

	/**
	 * Últimos registros de auditoría.
	 *
	 * @return array[]
	 */
	private function auditoria_reciente() {
		global $wpdb;
		$tabla = $wpdb->prefix . 'man_audit';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$filas = $wpdb->get_results( "SELECT ts, evento, fuente, resultado, registros, detalle FROM {$tabla} ORDER BY id DESC LIMIT 15", \ARRAY_A );
		return is_array( $filas ) ? $filas : array();
	}
}
