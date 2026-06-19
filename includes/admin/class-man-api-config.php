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
					<?php
					if ( 'ideam' === $slug ) {
						echo self::panel_fews_jsons(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML controlado y escapado dentro del helper.
					}
					?>

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
				$code = ! empty( $cfg['dataset_id'] ) ? $cfg['dataset_id'] : 'tumc2';
				$svc  = ( $url && false !== strpos( $url, 'service.php' ) ) ? $url : 'https://www.ioc-sealevelmonitoring.org/service.php';
				return $svc . '?query=data&format=json&period=0.1&code=' . rawurlencode( $code );
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
				return 'Usa <strong>FEWS de IDEAM</strong> (visorfews): trae las redes de estaciones de Nariño (nivel, precipitación, caudal, temperatura, nivel y caudal pronosticados, calidad del agua) y las subzonas hidrográficas. No requiere clave. El cron sincroniza la red de nivel; las demás se consultan en vivo con caché. El antiguo dataset de datos.gov.co dejó de existir. Revisa cada capa en las pestañas de abajo.';
			case 'ioc':
				return 'Nivel del mar del <strong>IOC Sea Level Monitoring</strong> (COI-UNESCO). Usa el servicio <code>service.php</code> (el antiguo <code>api.ioc-…</code> devuelve HTML, no datos). El <code>dataset-id</code> es el código de estación: <strong>tumc2</strong> para Tumaco. Devuelve muestras por minuto de dos sensores (burbuja y radar) en metros; el plugin filtra las anomalías y se queda con el sensor más continuo.';
			case 'sivigila':
				return 'El dataset de SIVIGILA en datos.gov.co cambia de identificador con frecuencia y puede no existir. Fija un <code>dataset-id</code> vigente o deja la fuente <strong>inactiva</strong>.';
			default:
				return '';
		}
	}

	/**
	 * Metadatos de las 10 capas JSON del visor FEWS de IDEAM, para inspección en
	 * la tarjeta de la fuente. Coberturas de Nariño verificadas contra la API.
	 *
	 * @return array[]
	 */
	private static function jsons_fews() {
		return array(
			array(
				'archivo' => 'ReporteTablaEstaciones.json',
				'titulo'  => 'Nivel de ríos (observado)',
				'campos'  => 'id, nombre, lat, lng, corriente, municipio, depart, ultimonivelobs/sen, umbralobs/sen',
				'narino'  => 'Estaciones limnimétricas; se filtran por el campo depart = Nariño. Alerta = nivel ≥ umbral.',
				'shortcode' => '[man_estaciones variable="nivel"] · [man_grafico view="fews_nivel"]',
			),
			array(
				'archivo' => 'ReporteTablaEstacionesPobs.json',
				'titulo'  => 'Precipitación (observada, mm)',
				'campos'  => 'id, nombre, lat, lng, municipio, depart, ultimodatoobs/sen',
				'narino'  => 'Estaciones pluviométricas de Nariño (filtro depart). Sin umbral de alerta.',
				'shortcode' => '[man_estaciones variable="precipitacion"] · [man_grafico view="fews_precipitacion"]',
			),
			array(
				'archivo' => 'ReporteTablaEstacionesQ.json',
				'titulo'  => 'Caudal (observado, m³/s)',
				'campos'  => 'id, nombre, lat, lng, corriente, municipio, depart, ultimoqobs/sen',
				'narino'  => 'Estaciones hidrométricas de Nariño (filtro depart).',
				'shortcode' => '[man_estaciones variable="caudal"] · [man_grafico view="fews_caudal"]',
			),
			array(
				'archivo' => 'ReporteTablaEstacionesTobs.json',
				'titulo'  => 'Temperatura (observada, °C)',
				'campos'  => 'id, nombre, lat, lng, municipio, depart, ultimodatoobs/sen',
				'narino'  => 'Estaciones con sensor de temperatura en Nariño (filtro depart).',
				'shortcode' => '[man_estaciones variable="temperatura"] · [man_grafico view="fews_temperatura"]',
			),
			array(
				'archivo' => 'ReporteTablaEstacionesHsim.json',
				'titulo'  => 'Nivel pronosticado (simulado, m)',
				'campos'  => 'id, nombre, maxnivel, uamarilla, unaranja, uroja, municipio, depart',
				'narino'  => '17 estaciones en Nariño. Alerta graduada amarilla/naranja/roja según maxnivel.',
				'shortcode' => '[man_estaciones variable="nivel_pronostico"] · [man_grafico view="fews_nivel_pronostico"]',
			),
			array(
				'archivo' => 'ReporteTablaEstacionesQsim.json',
				'titulo'  => 'Caudal pronosticado (simulado, m³/s)',
				'campos'  => 'id, nombre, maxcaudal, uamarilla, unaranja, uroja, municipio, depart',
				'narino'  => '12 estaciones en Nariño. Alerta graduada según maxcaudal.',
				'shortcode' => '[man_estaciones variable="caudal_pronostico"] · [man_grafico view="fews_caudal_pronostico"]',
			),
			array(
				'archivo' => 'ReporteTablaEstacionesCalidad.json',
				'titulo'  => 'Calidad del agua (ICA 0–1)',
				'campos'  => 'id, nombre, ultimodatoica6v, ultimodatoph/od/turb/tw, subred, municipio, depart',
				'narino'  => '12 estaciones en Nariño (incl. Laguna de La Cocha). Menor ICA = peor calidad.',
				'shortcode' => '[man_estaciones variable="calidad"] · [man_grafico view="fews_calidad"]',
			),
			array(
				'archivo' => 'ReporteTablaEmbalsesVolUtil.json',
				'titulo'  => 'Embalses · volumen útil (no aplica)',
				'campos'  => 'id, nombre, region, ultimovalor, lat, lng',
				'narino'  => '25 embalses en el país, 0 en Nariño. El departamento no tiene grandes embalses de regulación.',
				'shortcode' => '— (sin gráfico departamental)',
			),
			array(
				'archivo' => 'SZH_Alertas.json',
				'titulo'  => 'Alertas por subzona hidrográfica (~8 MB)',
				'campos'  => 'SZH, NOMSZH, AH/NOMAH, ZH/NOMZH, Alerta, umbralaler, pobsszh, Fecha',
				'narino'  => '316 subzonas nacionales (polígonos). Se acotan 16 de las cuencas de Nariño (Pacífico sur 51/52/53 y alto Putumayo 47). Cacheada 6 h.',
				'shortcode' => '[man_grafico view="fews_szh_alertas"]',
			),
			array(
				'archivo' => 'SZH_Pobs.json',
				'titulo'  => 'Precipitación por subzona hidrográfica (~8 MB)',
				'campos'  => 'SZH, NOMSZH, AH/NOMAH, ZH/NOMZH, Alerta, umbralaler, pobsszh, Fecha',
				'narino'  => 'Mismo payload que SZH_Alertas; se usa el campo pobsszh (precipitación) por subzona de Nariño. Cacheada 6 h.',
				'shortcode' => '[man_grafico view="fews_szh_pobs"]',
			),
		);
	}

	/**
	 * Panel con pestañas internas para inspeccionar cada JSON del visor FEWS:
	 * descripción, campos, cobertura de Nariño, enlace al JSON crudo y shortcodes.
	 *
	 * @return string HTML escapado.
	 */
	private static function panel_fews_jsons() {
		$b     = 'https://fews.ideam.gov.co/visorfews/data/';
		$jsons = self::jsons_fews();
		ob_start();
		?>
		<div class="man-fews-jsons">
			<style>
				.man-fews-jsons{margin:10px 0 4px;border:1px solid #dcdcde;border-radius:6px;overflow:hidden}
				.man-fews-jsons h4{margin:0;padding:8px 10px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:#50575e}
				.man-fjt-tabs{display:flex;flex-wrap:wrap;gap:4px;padding:8px;background:#fbfbfc;border-bottom:1px solid #dcdcde}
				.man-fjt-tab{font-size:11px;padding:3px 8px;border:1px solid #c3c4c7;border-radius:999px;background:#fff;cursor:pointer;color:#1d2327}
				.man-fjt-tab.activa{background:#2271b1;border-color:#2271b1;color:#fff}
				.man-fjt-panel{padding:10px 12px;font-size:13px;display:none}
				.man-fjt-panel.activa{display:block}
				.man-fjt-panel dt{font-weight:600;color:#50575e;font-size:11px;text-transform:uppercase;letter-spacing:.02em;margin-top:6px}
				.man-fjt-panel dd{margin:2px 0 0}
				.man-fjt-panel code{font-size:12px}
			</style>
			<h4>Revisar las 10 capas JSON del visor FEWS</h4>
			<div class="man-fjt-tabs" role="tablist">
				<?php foreach ( $jsons as $i => $j ) : ?>
					<button type="button" class="man-fjt-tab<?php echo 0 === $i ? ' activa' : ''; ?>" data-fjt="<?php echo (int) $i; ?>"><?php echo esc_html( $j['titulo'] ); ?></button>
				<?php endforeach; ?>
			</div>
			<?php foreach ( $jsons as $i => $j ) : $url = $b . $j['archivo']; ?>
				<div class="man-fjt-panel<?php echo 0 === $i ? ' activa' : ''; ?>" data-fjt-panel="<?php echo (int) $i; ?>">
					<dl>
						<dt>Endpoint</dt>
						<dd><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $j['archivo'] ); ?></code></a> — abre el JSON crudo en una pestaña nueva</dd>
						<dt>Campos clave</dt>
						<dd><code><?php echo esc_html( $j['campos'] ); ?></code></dd>
						<dt>Uso para Nariño</dt>
						<dd><?php echo esc_html( $j['narino'] ); ?></dd>
						<dt>Shortcodes</dt>
						<dd><code><?php echo esc_html( $j['shortcode'] ); ?></code></dd>
					</dl>
				</div>
			<?php endforeach; ?>
			<script>
			(function(){
				var raiz = document.currentScript ? document.currentScript.closest('.man-fews-jsons') : null;
				if (!raiz) { return; }
				var tabs = raiz.querySelectorAll('.man-fjt-tab');
				Array.prototype.forEach.call(tabs, function(t){
					t.addEventListener('click', function(){
						var idx = t.getAttribute('data-fjt');
						Array.prototype.forEach.call(tabs, function(x){ x.classList.remove('activa'); });
						t.classList.add('activa');
						Array.prototype.forEach.call(raiz.querySelectorAll('.man-fjt-panel'), function(p){
							p.classList.toggle('activa', p.getAttribute('data-fjt-panel') === idx);
						});
					});
				});
			})();
			</script>
		</div>
		<?php
		return ob_get_clean();
	}
}
