<?php
/**
 * Configuración por API (Sección 8). Cada fuente es una tarjeta editable:
 * estado, URL/dataset-id, clave cifrada, frecuencia, TTL, sslverify, con
 * botones «Probar conexión» y «Sincronizar ahora» (AJAX).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Api_Config {

	public function __construct() {
		add_action( 'admin_post_man_guardar_apis', array( $this, 'guardar' ) );
		add_action( 'wp_ajax_man_probar', array( $this, 'ajax_probar' ) );
		add_action( 'wp_ajax_man_sincronizar', array( $this, 'ajax_sincronizar' ) );
	}

	/**
	 * Renderiza el formulario de fuentes.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config = get_option( 'man_api_config', array() );
		$freqs  = array( 1 => '1 h', 6 => '6 h', 12 => '12 h', 24 => '24 h' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="man-admin-form">
			<input type="hidden" name="action" value="man_guardar_apis" />
			<?php wp_nonce_field( 'man_apis' ); ?>

			<?php foreach ( $config as $slug => $cfg ) : ?>
				<div class="man-card">
					<h2><?php echo esc_html( isset( $cfg['nombre'] ) ? $cfg['nombre'] : $slug ); ?>
						<code><?php echo esc_html( $slug ); ?></code></h2>

					<p>
						<label><input type="checkbox" name="man[<?php echo esc_attr( $slug ); ?>][activa]" value="1"
							<?php checked( ! empty( $cfg['activa'] ) ); ?> /> Activa</label>
						&nbsp;|&nbsp;
						<label><input type="checkbox" name="man[<?php echo esc_attr( $slug ); ?>][sslverify]" value="1"
							<?php checked( ! empty( $cfg['sslverify'] ) ); ?> /> Verificar SSL</label>
						<em>(desactivar para portales estatales CO)</em>
					</p>

					<p><label>URL base<br />
						<input type="url" class="regular-text" name="man[<?php echo esc_attr( $slug ); ?>][url]"
							value="<?php echo esc_attr( isset( $cfg['url'] ) ? $cfg['url'] : '' ); ?>" /></label></p>

					<p><label>dataset-id / código estación<br />
						<input type="text" class="regular-text" name="man[<?php echo esc_attr( $slug ); ?>][dataset_id]"
							value="<?php echo esc_attr( isset( $cfg['dataset_id'] ) ? $cfg['dataset_id'] : '' ); ?>" /></label></p>

					<p><label>Clave (se guarda cifrada)<br />
						<input type="password" class="regular-text" name="man[<?php echo esc_attr( $slug ); ?>][clave]"
							placeholder="<?php echo ! empty( $cfg['clave'] ) ? '•••••• (guardada)' : 'sin clave'; ?>" autocomplete="new-password" /></label></p>
					<?php $ayuda = self::ayuda_fuente( $slug ); ?>
					<?php if ( '' !== $ayuda ) : ?>
						<p class="description" style="margin-top:-6px"><?php echo wp_kses( $ayuda, array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ), 'code' => array(), 'strong' => array() ) ); ?></p>
					<?php endif; ?>

					<p>
						<label>Frecuencia
							<select name="man[<?php echo esc_attr( $slug ); ?>][frecuencia]">
								<?php foreach ( $freqs as $h => $et ) : ?>
									<option value="<?php echo esc_attr( $h ); ?>" <?php selected( (int) ( isset( $cfg['frecuencia'] ) ? $cfg['frecuencia'] : 12 ), $h ); ?>><?php echo esc_html( $et ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						&nbsp;
						<label>TTL caché (min)
							<input type="number" min="1" name="man[<?php echo esc_attr( $slug ); ?>][ttl]"
								value="<?php echo esc_attr( (int) ( isset( $cfg['ttl'] ) ? $cfg['ttl'] : 60 ) ); ?>" style="width:90px" />
						</label>
					</p>

					<p class="man-estado-linea">
						Última sincronización:
						<strong><?php echo esc_html( ! empty( $cfg['ultima_sync'] ) ? gmdate( 'Y-m-d H:i', (int) $cfg['ultima_sync'] ) . ' UTC' : 'nunca' ); ?></strong>
						— <?php echo esc_html( isset( $cfg['ultimo_resultado'] ) ? $cfg['ultimo_resultado'] : '—' ); ?>
					</p>

					<p>
						<button type="button" class="button man-probar" data-slug="<?php echo esc_attr( $slug ); ?>">Probar conexión</button>
						<button type="button" class="button man-sincronizar" data-slug="<?php echo esc_attr( $slug ); ?>">Sincronizar ahora</button>
						<span class="man-resultado" data-slug="<?php echo esc_attr( $slug ); ?>"></span>
					</p>
				</div>
			<?php endforeach; ?>

			<?php submit_button( 'Guardar configuración' ); ?>
		</form>
		<?php
	}

	/**
	 * Persiste la configuración enviada.
	 */
	public function guardar() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'man_apis' );

		$config = get_option( 'man_api_config', array() );
		$in     = isset( $_POST['man'] ) && is_array( $_POST['man'] ) ? wp_unslash( $_POST['man'] ) : array();

		foreach ( $config as $slug => $cfg ) {
			$nuevo = isset( $in[ $slug ] ) && is_array( $in[ $slug ] ) ? $in[ $slug ] : array();

			$config[ $slug ]['activa']     = ! empty( $nuevo['activa'] );
			$config[ $slug ]['sslverify']  = ! empty( $nuevo['sslverify'] );
			$config[ $slug ]['url']        = isset( $nuevo['url'] ) ? esc_url_raw( trim( $nuevo['url'] ) ) : ( isset( $cfg['url'] ) ? $cfg['url'] : '' );
			$config[ $slug ]['dataset_id'] = isset( $nuevo['dataset_id'] ) ? sanitize_text_field( $nuevo['dataset_id'] ) : '';
			$config[ $slug ]['frecuencia'] = isset( $nuevo['frecuencia'] ) ? max( 1, (int) $nuevo['frecuencia'] ) : 12;
			$config[ $slug ]['ttl']        = isset( $nuevo['ttl'] ) ? max( 1, (int) $nuevo['ttl'] ) : 60;

			// Clave: solo se reemplaza si se escribió una nueva (cifrada en reposo).
			if ( isset( $nuevo['clave'] ) && '' !== trim( $nuevo['clave'] ) ) {
				$config[ $slug ]['clave'] = MAN_Security::cifrar( trim( $nuevo['clave'] ) );
			}
		}

		update_option( 'man_api_config', $config );
		MAN_Sync::auditar( 'config', 'admin', 'ok', 0, 'Configuración de APIs actualizada' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'man-fuentes', 'man_msg' => 'guardado' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX: prueba ligera de conexión a una fuente.
	 */
	public function ajax_probar() {
		$this->verificar_ajax();
		$slug   = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$config = get_option( 'man_api_config', array() );
		if ( empty( $config[ $slug ] ) ) {
			wp_send_json_error( array( 'mensaje' => 'Fuente desconocida' ) );
		}
		$cfg = $config[ $slug ];
		$url = $this->url_de_prueba( $slug, $cfg );
		$ssl = ! empty( $cfg['sslverify'] );

		$t0 = microtime( true );
		$r  = MAN_Sync::http_get( $url, $ssl, array( 'timeout' => 12 ) );
		$ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( $r['ok'] ) {
			wp_send_json_success( array( 'mensaje' => 'OK · HTTP ' . $r['codigo'] . ' · ' . $ms . ' ms' ) );
		}
		wp_send_json_error( array( 'mensaje' => 'Falla · HTTP ' . $r['codigo'] . ' ' . $r['error'] ) );
	}

	/**
	 * AJAX: sincroniza una fuente ahora.
	 */
	public function ajax_sincronizar() {
		$this->verificar_ajax();
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$res  = MAN_Plugin::instancia()->sync->ejecutar_fuente( $slug );
		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( array( 'mensaje' => 'Sincronizado · ' . $res['mensaje'] . ' · ' . $res['latencia_ms'] . ' ms' ) );
		}
		wp_send_json_error( array( 'mensaje' => 'Error · ' . ( isset( $res['mensaje'] ) ? $res['mensaje'] : '' ) ) );
	}

	/**
	 * Verifica nonce y capacidad para acciones AJAX.
	 */
	private function verificar_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => 'No autorizado' ), 403 );
		}
		check_ajax_referer( 'man_admin', 'nonce' );
	}

	/**
	 * Construye una URL de prueba representativa por fuente.
	 *
	 * @param string $slug Slug.
	 * @param array  $cfg  Config.
	 * @return string
	 */
	private function url_de_prueba( $slug, $cfg ) {
		$url = isset( $cfg['url'] ) ? $cfg['url'] : '';
		switch ( $slug ) {
			case 'open_meteo':
				return 'https://api.open-meteo.com/v1/forecast?latitude=1.08&longitude=-77.21&current=temperature_2m&timezone=America%2FBogota';
			case 'ideam':
				// FEWS IDEAM: la prueba consulta la lista de estaciones.
				$u = ( $url && false === strpos( $url, 'datos.gov.co' ) ) ? $url : 'https://fews.ideam.gov.co/visorfews/data/ReporteTablaEstaciones.json';
				return $u;
			case 'sivigila':
				$ds = isset( $cfg['dataset_id'] ) ? $cfg['dataset_id'] : '';
				return rtrim( $url, '/' ) . '/' . rawurlencode( $ds ) . '.json?$limit=1';
			case 'ioc':
				$code = isset( $cfg['dataset_id'] ) ? $cfg['dataset_id'] : '';
				return rtrim( $url, '/' ) . '/?query=stationlist&code=' . rawurlencode( $code );
			case 'iri_enso':
				return $url; // página oficial NOAA/CPC de probabilidades ENSO
			case 'deficit':
				return 'https://api.open-meteo.com/v1/forecast?latitude=1.21&longitude=-77.28&daily=precipitation_sum&past_days=7&forecast_days=1&timezone=America%2FBogota';
			case 'firms':
				$key = ! empty( $cfg['clave'] ) ? MAN_Security::descifrar( $cfg['clave'] ) : '';
				$ds  = isset( $cfg['dataset_id'] ) ? $cfg['dataset_id'] : 'VIIRS_SNPP_NRT';
				$base = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) : 'https://firms.modaps.eosdis.nasa.gov/api/area/csv';
				return $base . '/' . rawurlencode( $key ) . '/' . rawurlencode( $ds ) . '/' . MAN_Sync_Firms::BBOX . '/1';
			default:
				return $url;
		}
	}

	/**
	 * Texto de ayuda por fuente (debajo del campo Clave). HTML restringido.
	 *
	 * @param string $slug Slug de la fuente.
	 * @return string
	 */
	private static function ayuda_fuente( $slug ) {
		switch ( $slug ) {
			case 'firms':
				return 'Consigue una <strong>MAP_KEY gratuita</strong> en <a href="https://firms.modaps.eosdis.nasa.gov/api/map_key/" target="_blank" rel="noopener">firms.modaps.eosdis.nasa.gov/api/map_key/</a>. Pega la clave y pulsa <strong>Guardar configuración</strong> ANTES de «Probar» o «Sincronizar»: si pruebas sin guardar, FIRMS responde <code>HTTP 400</code> porque la clave aún va vacía.';
			case 'ideam':
				return 'Usa <strong>FEWS de IDEAM</strong> (visorfews): trae las estaciones hidrológicas de Nariño con su nivel actual y umbral de alerta. No requiere clave. El antiguo dataset de datos.gov.co dejó de existir y se reemplazó por esta fuente.';
			case 'sivigila':
				return 'El dataset de SIVIGILA en datos.gov.co cambia de identificador con frecuencia y puede no existir. Fija un <code>dataset-id</code> vigente o deja la fuente <strong>inactiva</strong>.';
			default:
				return '';
		}
	}
}
