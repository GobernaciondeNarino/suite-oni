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
		wp_add_inline_style( 'common', '.man-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:14px 0;max-width:760px}.man-card h2 code{font-size:11px;color:#787c82;background:#f0f0f1;padding:2px 6px;border-radius:4px}.man-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;vertical-align:middle}.man-resultado{margin-left:10px;font-style:italic}.man-tabla-salud td,.man-tabla-salud th{padding:8px 10px}' );
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
