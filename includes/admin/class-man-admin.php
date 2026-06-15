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
		add_submenu_page( 'man-salud', 'APIs y datos', 'APIs y datos', 'manage_options', 'man-apis-datos', array( $this, 'pagina_apis_datos' ) );
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
			. '.man-el-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:14px;margin:10px 0 26px}.man-el-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px}.man-el-card h3{margin:0 0 2px;font-size:14px}.man-el-card h3 code{font-size:12px;background:#eef2f7;color:#1d4ed8;padding:1px 6px;border-radius:4px}.man-el-desc{color:#50575e;margin:.3em 0 .6em;font-size:13px}.man-el-attrs{margin:.2em 0 .7em;padding-left:1.1em;font-size:12px;color:#50575e}.man-el-attrs code{background:#f0f0f1;padding:0 4px;border-radius:3px}.man-el-copy{display:flex;gap:6px;align-items:center}.man-el-input{flex:1;font-family:Menlo,Consolas,monospace;font-size:12px;padding:6px 8px;border:1px solid #c3c4c7;border-radius:4px;background:#f6f7f7}.man-el-card .button{white-space:nowrap}.man-el-intro{max-width:820px}.man-el-grp{margin:22px 0 6px;font-size:15px;border-bottom:1px solid #e0e0e0;padding-bottom:4px}.man-el-callout{background:#f6faf7;border:1px solid #cfe8d6;border-left:4px solid #10A13B;border-radius:8px;padding:14px 20px;margin:14px 0 8px;max-width:920px}.man-el-callout h2{margin:0 0 6px;font-size:15px;color:#0f5132}.man-el-callout ol{margin:.4em 0;padding-left:1.3em}.man-el-callout li{margin:.5em 0;font-size:13px;color:#3c434a;line-height:1.55}.man-el-callout code{font-size:12px;background:#eef2f7;color:#1d4ed8;padding:1px 5px;border-radius:3px}.man-el-callout-nota{font-size:12.5px;color:#50575e;margin:.6em 0 0;border-top:1px dashed #cfe8d6;padding-top:.6em}'
			. '.man-api-intro{max-width:920px}.man-api-leyenda{display:flex;flex-wrap:wrap;gap:8px 18px;margin:10px 0 4px;font-size:12px;color:#50575e}.man-api-leyenda .man-badge{margin-right:4px}.man-badge{display:inline-block;font-size:11px;font-weight:600;line-height:1.6;padding:0 8px;border-radius:10px;white-space:nowrap}.man-badge-cron{background:#e7f0fb;color:#0b4a8f}.man-badge-navegador{background:#e6f4ea;color:#1e6b32}.man-badge-mixto{background:#fef3e0;color:#8a5300}.man-api-panel{display:none}.man-api-panel.is-activo{display:block;animation:man-fade .18s ease-in}@keyframes man-fade{from{opacity:0}to{opacity:1}}.man-api-panel h2{margin:18px 0 4px;font-size:17px}.man-api-panel>p{max-width:920px;color:#3c434a}.man-tabla-api{max-width:1100px;margin:12px 0 8px}.man-tabla-api td,.man-tabla-api th{padding:9px 12px;vertical-align:top}.man-tabla-api th{font-size:13px}.man-tabla-api td:first-child{font-weight:600;width:24%}.man-tabla-api code{font-size:11px;background:#f0f0f1;padding:1px 5px;border-radius:3px;white-space:nowrap}.man-tabla-api .man-fuente{display:block;color:#50575e;font-size:12px;margin-top:3px}.man-tabla-api .man-lic{color:#646970;font-size:12px}.man-sc-lista code{margin:0 3px 3px 0;display:inline-block}.man-combina{background:#fbfbfc;border:1px solid #dcdcde;border-left:4px solid #10A13B;border-radius:6px;padding:14px 18px;margin:12px 0;max-width:1100px}.man-combina h3{margin:0 0 4px;font-size:15px}.man-combina .man-formula{font-family:Menlo,Consolas,monospace;font-size:12.5px;color:#1d3b6b;background:#eef2f7;padding:6px 10px;border-radius:4px;display:block;margin:6px 0;line-height:1.5}.man-combina p{margin:.4em 0;color:#3c434a;font-size:13px}.man-combina ul{margin:.3em 0 .3em 1.2em;font-size:13px;color:#50575e}.man-api-estado{font-size:11px;font-weight:600;margin-left:6px}.man-api-estado.on{color:#1e6b32}.man-api-estado.off{color:#b32d2e}.man-api-pie{max-width:920px;color:#646970;font-size:12px;margin-top:18px;border-top:1px solid #e0e0e0;padding-top:10px}' );
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

			<div class="man-el-callout">
				<h2>Cómo separar un gráfico de su descripción y análisis</h2>
				<p>Cada gráfico estadístico trae dos párrafos de análisis: uno <strong>descriptivo</strong> (qué muestra, en
					lenguaje claro) y uno <strong>cuantitativo</strong> (cifras: pendiente, R², máximos, probabilidades). Tienes
					tres maneras de maquetarlos:</p>
				<ol>
					<li><strong>Todo junto (por defecto).</strong> El gráfico muestra su análisis debajo.
						Controla cuál con <code>analisis="ambos|descriptivo|cuantitativo|no"</code>.<br/>
						<code>[man_grafico view="oni_serie" type="line" analisis="ambos"]</code></li>
					<li><strong>Gráfico y texto por separado, en distintos lugares de la página.</strong> Oculta el análisis del
						gráfico con <code>analisis="no"</code> y coloca el texto donde quieras con el shortcode
						<code>[man_analisis]</code> apuntando a la misma <code>view</code>:<br/>
						<code>[man_grafico view="oni_serie" type="line" analisis="no"]</code><br/>
						<code>[man_analisis view="oni_serie" modo="descriptivo" titulo="Lectura"]</code><br/>
						<code>[man_analisis view="oni_serie" modo="cuantitativo" titulo="Cifras clave"]</code></li>
					<li><strong>Enlazados por grupo (se actualizan juntos).</strong> Si das el mismo <code>grupo</code> al gráfico
						y al análisis, al cambiar la vista/mes con un <code>[man_filtro]</code> el texto se recalcula solo:<br/>
						<code>[man_grafico grupo="enso" view="oni_serie" analisis="no"]</code> · <code>[man_analisis grupo="enso"]</code></li>
				</ol>
				<p class="man-el-callout-nota">En <code>[man_prediccion]</code> se divide igual con el atributo
					<code>partes</code> (p. ej. <code>partes="grafico"</code> en un sitio y <code>partes="texto,metodologia"</code>
					en otro). Valores: <code>titulo, chips, grafico, probabilidad, texto, metodologia</code>.</p>
			</div>

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
					'tag'     => 'man_grafico',
					'titulo'  => 'Gráfico avanzado (D3plus)',
					'desc'    => 'Tarjeta con barra de herramientas: Detalle, Compartir, Datos, Imagen PNG, Descarga JSON y "Cambiar tipo" en vivo. Elige una vista de datos y el tipo de gráfico.',
					'attrs'   => array( '<code>view</code> — oni_serie | prob_fase | riesgo_subregion | riesgo_municipios | episodios', '<code>type</code> — bar | line | treemap | pie | …', '<code>theme</code> — claro | oscuro', '<code>analisis</code> — ambos | descriptivo | cuantitativo | no', '<code>legend_pos</code> — abajo | arriba | derecha | izquierda', '<code>actions</code>, <code>legend</code>, <code>toolbar</code>, <code>alto</code>, <code>grupo</code>' ),
					'ejemplo' => '[man_grafico view="oni_serie" type="line"]',
				),
				array(
					'tag'     => 'man_analisis',
					'titulo'  => 'Análisis (solo texto)',
					'desc'    => 'Solo los párrafos de análisis (descriptivo y/o cuantitativo) de una vista, SIN la gráfica. Sirve para separar el gráfico de su descripción y ubicarlos por separado. Con grupo="…" se sincroniza con el gráfico del mismo grupo.',
					'attrs'   => array( '<code>view</code> — la misma vista del gráfico', '<code>modo</code> — ambos | descriptivo | cuantitativo', '<code>titulo</code> — encabezado opcional', '<code>grupo</code> — enlázalo a un [man_grafico] del mismo grupo', '<code>hasta</code>, <code>mes</code>' ),
					'ejemplo' => '[man_analisis view="oni_serie" modo="descriptivo" titulo="Lectura"]',
				),
				array(
					'tag'     => 'man_prediccion',
					'titulo'  => 'Predicción ENSO',
					'desc'    => 'Trayectoria del ONI hasta el mes objetivo (feb-2027) con banda de incertidumbre, umbrales de fase, probabilidad por trimestre y texto predictivo. Reveal animado. Divisible con "partes".',
					'attrs'   => array( '<code>hasta</code> — mes objetivo AAAA-MM', '<code>modelo</code> — si/no (línea del modelo propio)', '<code>probabilidad</code> — si/no (barras por trimestre)', '<code>partes</code> — titulo, chips, grafico, probabilidad, texto, metodologia' ),
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
			'Composables (enlazados por grupo)' => array(
				array(
					'tag'     => 'man_grafico',
					'titulo'  => 'Gráfico composable',
					'desc'    => 'El mismo gráfico avanzado, pero con grupo="…": se sincroniza con [man_filtro] y [man_panel] del mismo grupo. Úsalo con toolbar="no" para separar el chrome.',
					'attrs'   => array( '<code>grupo</code> — id del grupo (ej. enso)', '<code>view</code>, <code>type</code>, <code>toolbar</code>, <code>alto</code>' ),
					'ejemplo' => '[man_grafico grupo="enso" view="oni_serie" toolbar="no"]',
				),
				array(
					'tag'     => 'man_filtro',
					'titulo'  => 'Filtro',
					'desc'    => 'Control que actualiza en vivo los gráficos del grupo: selector de vista, de tipo de gráfico o deslizador de mes.',
					'attrs'   => array( '<code>grupo</code> — id del grupo', '<code>control</code> — vista | tipo | mes', '<code>inicio</code>, <code>fin</code> (para mes)' ),
					'ejemplo' => '[man_filtro grupo="enso" control="vista"]',
				),
				array(
					'tag'     => 'man_panel',
					'titulo'  => 'Panel de detalles',
					'desc'    => 'Muestra el título, la descripción y los detalles (tipo, categoría, dimensiones, medidas, filas) del gráfico vigente del grupo.',
					'attrs'   => array( '<code>grupo</code> — id del grupo' ),
					'ejemplo' => '[man_panel grupo="enso"]',
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
					'desc'    => 'Barra de meses del ONI (estilo mockup): leyenda Observado/Proyectado, chip de estado, botones, velocidad, menú "Capas" y slider con gradiente ENSO. Controla el globo (man:mes) y sus capas (man:capa). Autoplay al cargar.',
					'attrs'   => array( '<code>inicio</code> — AAAA-MM (def. 2026-03)', '<code>fin</code> — AAAA-MM (def. 2027-03)', '<code>autoplay</code> — si/no', '<code>titulo</code> — rótulo de la barra' ),
					'ejemplo' => '[man_timeline inicio="2026-03" fin="2027-03"]',
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
	 * Página de transparencia: qué dato del plugin proviene de qué API/fuente,
	 * organizado en pestañas por dominio, indicando capa (cron / navegador),
	 * licencia, shortcodes que lo usan y los datos COMBINADOS de varias fuentes.
	 *
	 * Proyecto público de la Gobernación de Nariño; pensada para la comunidad,
	 * investigadores y periodistas.
	 */
	public function pagina_apis_datos() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config   = get_option( 'man_api_config', array() );
		$pestanas = $this->mapa_apis_datos();
		$slugs    = array_keys( $pestanas );
		?>
		<div class="wrap">
			<h1>Monitor Ambiental — APIs y datos</h1>
			<p class="man-api-intro">Esta página documenta, con fines de <strong>transparencia</strong>, de qué API o fuente
				oficial proviene cada dato que muestra el plugin, cómo se obtiene y qué componentes lo usan. Es información
				pública orientada a la ciudadanía, la academia y el periodismo. Para el estado en vivo de cada fuente consulta
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=man-salud' ) ); ?>">Salud de APIs</a> y para reconfigurarlas,
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=man-fuentes' ) ); ?>">Fuentes</a>.</p>

			<p class="man-api-leyenda">
				<span><span class="man-badge man-badge-cron">Cron</span> sincronización en el servidor (WP-Cron) y caché.</span>
				<span><span class="man-badge man-badge-navegador">Navegador</span> consumo directo desde el navegador (sin clave, CORS).</span>
				<span><span class="man-badge man-badge-mixto">Mixto</span> cron o navegador según el volumen.</span>
			</p>

			<h2 class="nav-tab-wrapper man-api-tabs">
				<?php foreach ( $pestanas as $slug => $tab ) : ?>
					<a href="#<?php echo esc_attr( $slug ); ?>" class="nav-tab<?php echo $slug === $slugs[0] ? ' nav-tab-active' : ''; ?>"
						data-man-tab="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $tab['titulo'] ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php foreach ( $pestanas as $slug => $tab ) : ?>
				<div class="man-api-panel<?php echo $slug === $slugs[0] ? ' is-activo' : ''; ?>" id="man-api-panel-<?php echo esc_attr( $slug ); ?>" data-man-panel="<?php echo esc_attr( $slug ); ?>">
					<h2><?php echo esc_html( $tab['titulo'] ); ?></h2>
					<?php if ( ! empty( $tab['intro'] ) ) : ?>
						<p><?php echo esc_html( $tab['intro'] ); ?></p>
					<?php endif; ?>

					<table class="widefat striped man-tabla-api">
						<thead>
							<tr>
								<th>Dato que produce</th>
								<th>API / fuente</th>
								<th>Obtención</th>
								<th>Licencia</th>
								<th>Shortcodes que lo usan</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $tab['filas'] as $fila ) : ?>
							<tr>
								<td><?php echo esc_html( $fila['dato'] ); ?></td>
								<td>
									<?php echo esc_html( $fila['fuente'] ); ?>
									<?php if ( ! empty( $fila['endpoint'] ) ) : ?>
										<span class="man-fuente"><code><?php echo esc_html( $fila['endpoint'] ); ?></code></span>
									<?php endif; ?>
									<?php if ( ! empty( $fila['config'] ) ) : ?>
										<?php echo $this->estado_fuente( $fila['config'], $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML controlado y escapado dentro del helper. ?>
									<?php endif; ?>
								</td>
								<td><?php echo $this->badge_capa( $fila['capa'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML controlado y escapado dentro del helper. ?></td>
								<td class="man-lic"><?php echo esc_html( $fila['licencia'] ); ?></td>
								<td class="man-sc-lista">
									<?php foreach ( $fila['shortcodes'] as $sc ) : ?>
										<code><?php echo esc_html( $sc ); ?></code>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( ! empty( $tab['combinados'] ) ) : ?>
						<?php foreach ( $tab['combinados'] as $comb ) : ?>
							<div class="man-combina">
								<h3><?php echo esc_html( $comb['titulo'] ); ?></h3>
								<?php if ( ! empty( $comb['formula'] ) ) : ?>
									<code class="man-formula"><?php echo esc_html( $comb['formula'] ); ?></code>
								<?php endif; ?>
								<?php if ( ! empty( $comb['detalle'] ) ) : ?>
									<p><?php echo esc_html( $comb['detalle'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $comb['fuentes'] ) ) : ?>
									<ul>
										<?php foreach ( $comb['fuentes'] as $f ) : ?>
											<li><?php echo esc_html( $f ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<?php if ( ! empty( $comb['shortcodes'] ) ) : ?>
									<p class="man-sc-lista">Se muestra en:
										<?php foreach ( $comb['shortcodes'] as $sc ) : ?>
											<code><?php echo esc_html( $sc ); ?></code>
										<?php endforeach; ?>
									</p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<p class="man-api-pie">Atribución obligatoria de fuentes: NOAA/CPC, IRI (Columbia), IDEAM (vía datos.gov.co),
				Open-Meteo (CC BY 4.0), NASA POWER, IOC/VLIZ Sea Level, INS/SIVIGILA y DANE (cartografía). Los datos abiertos
				del plugin se publican bajo CC BY 4.0.</p>
		</div>

		<script>
		( function () {
			var tabs = document.querySelectorAll( '.man-api-tabs .nav-tab' );
			function activar( slug ) {
				tabs.forEach( function ( t ) {
					t.classList.toggle( 'nav-tab-active', t.getAttribute( 'data-man-tab' ) === slug );
				} );
				document.querySelectorAll( '.man-api-panel' ).forEach( function ( p ) {
					p.classList.toggle( 'is-activo', p.getAttribute( 'data-man-panel' ) === slug );
				} );
			}
			tabs.forEach( function ( t ) {
				t.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					activar( t.getAttribute( 'data-man-tab' ) );
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Devuelve el HTML de una etiqueta de capa de obtención del dato.
	 *
	 * @param string $capa cron | navegador | mixto.
	 * @return string HTML escapado.
	 */
	private function badge_capa( $capa ) {
		$mapa = array(
			'cron'      => array( 'Cron (servidor)', 'man-badge-cron' ),
			'navegador' => array( 'Navegador', 'man-badge-navegador' ),
			'mixto'     => array( 'Mixto', 'man-badge-mixto' ),
		);
		$def = isset( $mapa[ $capa ] ) ? $mapa[ $capa ] : array( ucfirst( $capa ), 'man-badge-mixto' );
		return '<span class="man-badge ' . esc_attr( $def[1] ) . '">' . esc_html( $def[0] ) . '</span>';
	}

	/**
	 * Indica, a partir de la configuración guardada, si una fuente está activa.
	 *
	 * @param string $slug   Slug de la fuente en man_api_config.
	 * @param array  $config Opción man_api_config.
	 * @return string HTML escapado (o cadena vacía si no está configurada).
	 */
	private function estado_fuente( $slug, $config ) {
		if ( empty( $config[ $slug ] ) ) {
			return '';
		}
		$activa = ! empty( $config[ $slug ]['activa'] );
		$clase  = $activa ? 'on' : 'off';
		$texto  = $activa ? 'fuente activa' : 'fuente inactiva';
		return '<span class="man-api-estado ' . esc_attr( $clase ) . '">● ' . esc_html( $texto ) . '</span>';
	}

	/**
	 * Mapa dato→fuente organizado por dominio (pestañas) para la página de
	 * transparencia. Basado en la Sección 3 y la matriz 3.9 de la guía.
	 *
	 * @return array<string,array>
	 */
	private function mapa_apis_datos() {
		return array(
			'enso'      => array(
				'titulo' => 'Fenómeno ENSO',
				'intro'  => 'El estado oficial de El Niño / La Niña proviene de los índices de la NOAA y de las plumas de probabilidad del IRI/CPC. Son fuentes canónicas que cambian lento (mensual/semanal), se sincronizan por cron y se cachean.',
				'filas'  => array(
					array(
						'dato'       => 'Índice ONI (anomalía trimestral móvil) y clasificación de fase e intensidad',
						'fuente'     => 'NOAA / CPC — archivo ASCII oni.ascii.txt',
						'endpoint'   => 'cpc.ncep.noaa.gov/data/indices/oni.ascii.txt',
						'capa'       => 'cron',
						'licencia'   => 'Dominio público (obra del gobierno de EE. UU.)',
						'config'     => 'noaa_oni',
						'shortcodes' => array( '[man_estado]', '[man_globo]', '[man_grafico]', '[man_estadisticas]', '[man_historico]' ),
					),
					array(
						'dato'       => 'Anomalía SST semanal de la región Niño 3.4 (pulso semanal del semáforo)',
						'fuente'     => 'NOAA / CPC — wksst8110.for (columnas de ancho fijo)',
						'endpoint'   => 'cpc.ncep.noaa.gov/data/indices/wksst8110.for',
						'capa'       => 'cron',
						'licencia'   => 'Dominio público (obra del gobierno de EE. UU.)',
						'config'     => 'noaa_oni',
						'shortcodes' => array( '[man_estado]', '[man_globo]' ),
					),
					array(
						'dato'       => 'Plumas / probabilidad de fase (El Niño · Neutral · La Niña) por trimestre',
						'fuente'     => 'IRI (Columbia) / CPC — ensamble de probabilidades ENSO',
						'endpoint'   => '',
						'capa'       => 'cron',
						'licencia'   => 'IRI/CPC — uso con atribución',
						'config'     => '',
						'shortcodes' => array( '[man_prediccion]', '[man_grafico]', '[man_estadisticas]' ),
					),
				),
			),
			'clima'     => array(
				'titulo' => 'Clima y pronóstico',
				'intro'  => 'El pronóstico puntual por municipio lo entrega Open-Meteo directamente al navegador (sin clave, con CORS, bajo licencia CC BY 4.0). El clima histórico y la climatología de referencia provienen de NASA POWER y de los reanálisis de Open-Meteo.',
				'filas'  => array(
					array(
						'dato'       => 'Pronóstico diario y horario 7–16 días (temperatura, precipitación, viento, humedad)',
						'fuente'     => 'Open-Meteo — Forecast API',
						'endpoint'   => 'api.open-meteo.com/v1/forecast',
						'capa'       => 'navegador',
						'licencia'   => 'CC BY 4.0',
						'config'     => 'open_meteo',
						'shortcodes' => array( '[man_pronostico]', '[man_estado]', '[man_mapa]' ),
					),
					array(
						'dato'       => 'Oleaje y altura de olas del Pacífico frente a Tumaco',
						'fuente'     => 'Open-Meteo — Marine API',
						'endpoint'   => 'marine-api.open-meteo.com/v1/marine',
						'capa'       => 'navegador',
						'licencia'   => 'CC BY 4.0',
						'config'     => 'open_meteo',
						'shortcodes' => array( '[man_mar]' ),
					),
					array(
						'dato'       => 'Calidad del aire (PM2.5, PM10, O₃, NO₂) por municipio',
						'fuente'     => 'Open-Meteo — Air Quality API',
						'endpoint'   => 'air-quality-api.open-meteo.com/v1/air-quality',
						'capa'       => 'navegador',
						'licencia'   => 'CC BY 4.0',
						'config'     => 'open_meteo',
						'shortcodes' => array( '[man_pronostico]', '[man_estado]' ),
					),
					array(
						'dato'       => 'Caudal de ríos para alerta hidrológica',
						'fuente'     => 'Open-Meteo — Flood API (GloFAS)',
						'endpoint'   => 'flood-api.open-meteo.com/v1/flood',
						'capa'       => 'navegador',
						'licencia'   => 'CC BY 4.0',
						'config'     => 'open_meteo',
						'shortcodes' => array( '[man_hidrico]' ),
					),
					array(
						'dato'       => 'Clima histórico y climatología de referencia (1991–2020) para cálculo de anomalías',
						'fuente'     => 'NASA POWER (perfiles agroclimáticos) · Open-Meteo Historical (ERA5)',
						'endpoint'   => 'power.larc.nasa.gov/api/temporal/daily/point',
						'capa'       => 'mixto',
						'licencia'   => 'NASA POWER: uso libre con atribución · ERA5: CC BY 4.0',
						'config'     => 'nasa_power',
						'shortcodes' => array( '[man_historico]', '[man_grafico]' ),
					),
				),
			),
			'territorio' => array(
				'titulo' => 'Territorio y alertas',
				'intro'  => 'Las alertas y el pronóstico oficial colombiano provienen del IDEAM a través del portal de datos abiertos datos.gov.co (consultas SoQL, sincronizadas por cron). La cartografía de los 64 municipios es del DANE y se sirve desde un GeoJSON local del plugin.',
				'filas'  => array(
					array(
						'dato'       => 'Alertas por municipio (fenómeno, nivel, fechas, sinopsis) y pronóstico oficial',
						'fuente'     => 'IDEAM vía datos.gov.co (Socrata/SODA, SoQL)',
						'endpoint'   => 'datos.gov.co/resource/{dataset-id}.json',
						'capa'       => 'cron',
						'licencia'   => 'Datos abiertos de Colombia',
						'config'     => 'ideam',
						'shortcodes' => array( '[man_mapa]', '[man_estado]' ),
					),
					array(
						'dato'       => 'Cartografía y límites de los 64 municipios (centroides y polígonos DIVIPOLA)',
						'fuente'     => 'DANE — GeoJSON local (data/narino_municipios.geojson)',
						'endpoint'   => '',
						'capa'       => 'navegador',
						'licencia'   => 'DANE — datos abiertos',
						'config'     => '',
						'shortcodes' => array( '[man_mapa]' ),
					),
				),
			),
			'mar'       => array(
				'titulo' => 'Mar',
				'intro'  => 'El nivel del mar en la costa Pacífica de Nariño proviene del sistema de mareógrafos del IOC/VLIZ (COI-UNESCO), sincronizado por cron. El oleaje complementario se documenta en la pestaña «Clima y pronóstico» (Open-Meteo Marine).',
				'filas'  => array(
					array(
						'dato'       => 'Nivel del mar en tiempo real (mareógrafos) frente a la costa de Nariño',
						'fuente'     => 'IOC Sea Level Monitoring (VLIZ / COI-UNESCO)',
						'endpoint'   => 'api.ioc-sealevelmonitoring.org/?query=data',
						'capa'       => 'cron',
						'licencia'   => 'IOC/VLIZ — uso con atribución (v2 requiere clave)',
						'config'     => 'ioc',
						'shortcodes' => array( '[man_mar]' ),
					),
				),
				'combinados' => array(
					array(
						'titulo'     => 'Condición marina costera = IOC (nivel) + Open-Meteo Marine (oleaje)',
						'formula'    => '',
						'detalle'    => 'El componente del mar combina el nivel del mar del IOC con el oleaje de Open-Meteo Marine para los 7 municipios costeros (Tumaco, Francisco Pizarro, Mosquera, Olaya Herrera, El Charco, La Tola, Santa Bárbara).',
						'fuentes'    => array(
							'IOC Sea Level (nivel del mar, cron)',
							'Open-Meteo Marine (altura y periodo de olas, navegador, CC BY 4.0)',
						),
						'shortcodes' => array( '[man_mar]' ),
					),
				),
			),
			'salud'     => array(
				'titulo' => 'Salud',
				'intro'  => 'Los casos de eventos de vigilancia sensibles al clima (dengue, EDA, IRA) provienen del INS/SIVIGILA a través de datos.gov.co (SoQL, cron). Se usan siempre agregados por municipio y semana epidemiológica; el plugin no maneja datos personales.',
				'filas'  => array(
					array(
						'dato'       => 'Casos de dengue, dengue grave, EDA e IRA por departamento y semana epidemiológica',
						'fuente'     => 'INS / SIVIGILA vía datos.gov.co (Socrata/SODA, SoQL)',
						'endpoint'   => 'datos.gov.co/resource/{dataset-dengue}.json',
						'capa'       => 'cron',
						'licencia'   => 'Datos abiertos de Colombia',
						'config'     => 'sivigila',
						'shortcodes' => array( '[man_salud]' ),
					),
				),
				'combinados' => array(
					array(
						'titulo'     => 'Correlación salud–clima = SIVIGILA (casos) + Open-Meteo/NASA POWER (temperatura y lluvia)',
						'formula'    => '',
						'detalle'    => 'El componente de salud relaciona los casos de dengue (favorecido por El Niño) con las variables climáticas del mismo periodo para evidenciar la sensibilidad climática del vector.',
						'fuentes'    => array(
							'INS/SIVIGILA (casos agregados, cron)',
							'Open-Meteo / NASA POWER (temperatura y precipitación)',
						),
						'shortcodes' => array( '[man_salud]' ),
					),
				),
			),
			'combinados' => array(
				'titulo' => 'Datos combinados',
				'intro'  => 'Estos productos no provienen de una sola API: el plugin los construye combinando varias fuentes y aplicando algoritmos transparentes y auditables (Sección 5 de la especificación). Aquí se explica qué entra en cada uno.',
				'filas'  => array(
					array(
						'dato'       => 'Anomalías por municipio (temperatura y lluvia respecto a lo normal)',
						'fuente'     => 'Pronóstico Open-Meteo − climatología 1991–2020 (NASA POWER / ERA5)',
						'endpoint'   => '',
						'capa'       => 'mixto',
						'licencia'   => 'Open-Meteo CC BY 4.0 · NASA POWER atribución',
						'config'     => '',
						'shortcodes' => array( '[man_mapa]', '[man_estado]', '[man_grafico]' ),
					),
				),
				'combinados' => array(
					array(
						'titulo'     => 'Índice de riesgo municipal (0–1, por municipio y mes)',
						'formula'    => 'riesgo = w1·f_enso(ONI) + w2·g_anom(lluvia) + w3·h_expo(municipio) + w4·k_sector(municipio)',
						'detalle'    => 'Índice compuesto, heurístico y auditable. El signo del empuje del fenómeno (f_enso) se invierte por subregión: en la zona andina El Niño se asocia a déficit de lluvia y en el litoral Pacífico a más lluvia y oleaje. Los pesos son configurables.',
						'fuentes'    => array(
							'NOAA ONI (empuje del fenómeno)',
							'IDEAM — lluvia y alertas oficiales',
							'DANE — exposición (población, ladera, costa)',
							'Predicciones del plugin (escenario del ONI)',
						),
						'shortcodes' => array( '[man_mapa]', '[man_grafico]', '[man_estadisticas]' ),
					),
					array(
						'titulo'     => 'Predicción del ONI (trayectoria hasta el mes objetivo, p. ej. feb-2027)',
						'formula'    => 'nivel_h = nivel_(h-1) + b·φ^h − λ·(nivel_(h-1) − μ);  banda σ_h = σ0 + α·√h',
						'detalle'    => 'La línea central usa el ensamble oficial NOAA-CPC/IRI cuando está disponible; el modelo estadístico propio (tendencia lineal amortiguada con reversión a la media) se dibuja como segunda opinión. La banda de incertidumbre se amplía en la primavera boreal (MAM) por la barrera de predictibilidad, y la probabilidad de fase se obtiene por integración gaussiana sobre los umbrales NOAA ±0,5 °C.',
						'fuentes'    => array(
							'NOAA — ONI observado',
							'Ensamble NOAA-CPC / IRI (probabilidades por trimestre)',
							'Modelo estadístico del plugin (class-man-forecast.php)',
						),
						'shortcodes' => array( '[man_prediccion]', '[man_grafico]', '[man_estadisticas]' ),
					),
				),
			),
		);
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
