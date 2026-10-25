<?php
/**
 * Página de administración del módulo de IA.
 *
 * Permite activar el asistente, decidir si se muestra en el front, elegir el
 * proveedor (Claude / ChatGPT / Gemini) y configurar ElevenLabs con su ID de
 * voz. Las claves se guardan cifradas y nunca se devuelven al navegador.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_AI_Config {

	public function __construct() {
		add_action( 'admin_post_man_guardar_ai', array( $this, 'guardar' ) );
		add_action( 'wp_ajax_man_probar_voz', array( $this, 'ajax_probar_voz' ) );
		add_action( 'wp_ajax_man_probar_ai', array( $this, 'ajax_probar_ai' ) );
	}

	/**
	 * Renderiza el formulario del módulo.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Vaciado del registro (enlace con nonce desde la propia página).
		if ( isset( $_GET['man_ai_limpiar'] ) && check_admin_referer( 'man_ai_limpiar' ) ) {
			MAN_AI::limpiar_diagnostico();
			echo '<div class="notice notice-success is-dismissible"><p>Registro de diagnóstico vaciado.</p></div>';
		}

		$cfg         = MAN_AI::config();
		$proveedores = MAN_AI::proveedores();
		$consumo     = MAN_AI::consumo_hoy();
		?>
		<div class="wrap">
			<h1>Monitor Ambiental — Asistente de IA</h1>

			<?php if ( isset( $_GET['man_msg'] ) && 'guardado' === $_GET['man_msg'] ) : ?>
				<div class="notice notice-success is-dismissible"><p>Configuración del asistente guardada.</p></div>
			<?php endif; ?>

			<p class="description" style="max-width:52em">
				El asistente responde preguntas de la ciudadanía <strong>únicamente con los datos
				que este plugin ya publica</strong> (ONI, predicción, riesgo por municipio, alertas,
				salud y focos de calor). No tiene acceso a la base de datos ni navega por internet.
				Las claves se guardan cifradas en el servidor y nunca llegan al navegador del visitante.
			</p>

			<?php if ( ! MAN_AI::activo() ) : ?>
				<div class="notice notice-info">
					<p>El asistente está <strong>apagado</strong>. Para encenderlo: marque «Activar»,
						elija un proveedor y guarde su clave.</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="man-admin-form">
				<input type="hidden" name="action" value="man_guardar_ai" />
				<?php wp_nonce_field( 'man_ai' ); ?>

				<div class="man-card">
					<h2>Estado del módulo</h2>
					<p>
						<label>
							<input type="checkbox" name="man_ai[activo]" value="1" <?php checked( ! empty( $cfg['activo'] ) ); ?> />
							<strong>Activar</strong> — el asistente puede responder consultas.
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="man_ai[visible]" value="1" <?php checked( ! empty( $cfg['visible'] ) ); ?> />
							<strong>Visible en el sitio</strong> — se muestra junto al globo y donde
							se use el shortcode. Si lo desmarca, el asistente queda activo pero oculto
							(útil para probarlo antes de publicarlo).
						</label>
					</p>
					<p>
						<label>Nombre del anfitrión<br />
							<input type="text" class="regular-text" name="man_ai[anfitrion]" maxlength="60"
								value="<?php echo esc_attr( $cfg['anfitrion'] ); ?>"
								placeholder="Tu anfitrión" />
						</label>
						<span class="description">Se muestra encima del saludo, como quien recibe a la persona.</span>
					</p>
					<p>
						<label>Saludo inicial<br />
							<textarea class="large-text" rows="2" name="man_ai[saludo]" maxlength="300"
								placeholder="<?php echo esc_attr( MAN_AI::saludo() ); ?>"><?php echo esc_textarea( $cfg['saludo'] ); ?></textarea>
						</label>
						<span class="description">Si lo deja vacío se usa el texto del ejemplo.</span>
					</p>
					<p>
						<label>Preguntas sugeridas (una por línea)<br />
							<textarea class="large-text" rows="5" name="man_ai[sugerencias]"
								placeholder="<?php echo esc_attr( implode( "\n", MAN_AI::sugerencias() ) ); ?>"><?php echo esc_textarea( $cfg['sugerencias'] ); ?></textarea>
						</label>
						<span class="description">
							Aparecen como atajos bajo el saludo (máximo 8). Escriba preguntas que el
							asistente pueda responder con los datos del sitio. Vacío = las del ejemplo.
						</span>
					</p>
				</div>

				<div class="man-card">
					<h2>Proveedor de lenguaje</h2>
					<p>
						<label>Proveedor activo
							<select name="man_ai[proveedor]">
								<?php foreach ( $proveedores as $slug => $p ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $cfg['proveedor'], $slug ); ?>>
										<?php echo esc_html( $p['nombre'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
						<span class="description">Solo se usa el proveedor seleccionado; los demás quedan configurados en reserva.</span>
					</p>

					<?php foreach ( $proveedores as $slug => $p ) : ?>
						<?php
						$campo_clave  = $slug . '_clave';
						$campo_modelo = $slug . '_modelo';
						$tiene_clave  = ! empty( $cfg[ $campo_clave ] );
						?>
						<fieldset style="border:1px solid #dcdcde;padding:12px 16px;margin:12px 0">
							<legend><strong><?php echo esc_html( $p['nombre'] ); ?></strong>
								<?php if ( $cfg['proveedor'] === $slug ) : ?>
									<span class="man-pill" style="background:#10A13B;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px">en uso</span>
								<?php endif; ?>
							</legend>

							<p><label>Clave de API (se guarda cifrada)<br />
								<input type="password" class="regular-text" name="man_ai[<?php echo esc_attr( $campo_clave ); ?>]"
									autocomplete="new-password"
									placeholder="<?php echo $tiene_clave ? '•••••• (guardada)' : 'sin clave'; ?>" />
							</label></p>
							<p class="description" style="margin-top:-6px"><?php echo esc_html( $p['ayuda'] ); ?></p>

							<p><label>Modelo
								<select name="man_ai[<?php echo esc_attr( $campo_modelo ); ?>]">
									<?php foreach ( $p['modelos'] as $id => $etiqueta ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $cfg[ $campo_modelo ], $id ); ?>>
											<?php echo esc_html( $etiqueta ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</label></p>

							<?php if ( $tiene_clave ) : ?>
								<p>
									<button type="button" class="button man-probar-ai" data-proveedor="<?php echo esc_attr( $slug ); ?>">Probar</button>
									<span class="man-resultado-ai" data-proveedor="<?php echo esc_attr( $slug ); ?>"></span>
								</p>
							<?php endif; ?>
						</fieldset>
					<?php endforeach; ?>
				</div>

				<div class="man-card">
					<h2>Voz (ElevenLabs)</h2>
					<p class="description">
						Si la activa, el asistente puede leer sus respuestas en voz alta. La escucha
						del ciudadano (hablarle al asistente) usa el micrófono del navegador y no
						consume esta API.
					</p>
					<p>
						<label>
							<input type="checkbox" name="man_ai[voz_activa]" value="1" <?php checked( ! empty( $cfg['voz_activa'] ) ); ?> />
							<strong>Activar respuesta hablada</strong>
						</label>
					</p>
					<p><label>Clave de API de ElevenLabs (se guarda cifrada)<br />
						<input type="password" class="regular-text" name="man_ai[voz_clave]"
							autocomplete="new-password"
							placeholder="<?php echo ! empty( $cfg['voz_clave'] ) ? '•••••• (guardada)' : 'sin clave'; ?>" />
					</label></p>
					<p><label><strong>ID de voz</strong><br />
						<input type="text" class="regular-text" name="man_ai[voz_id]"
							value="<?php echo esc_attr( $cfg['voz_id'] ); ?>"
							placeholder="p. ej. 21m00Tcm4TlvDq8ikWAM" />
					</label></p>
					<p class="description" style="margin-top:-6px">
						Se encuentra en <a href="https://elevenlabs.io/app/voice-library" target="_blank" rel="noopener">su biblioteca de voces</a>,
						en «ID». Para español se recomienda una voz multilingüe.
					</p>
					<p><label>Modelo de voz<br />
						<input type="text" class="regular-text" name="man_ai[voz_modelo]"
							value="<?php echo esc_attr( $cfg['voz_modelo'] ); ?>" placeholder="eleven_multilingual_v2" />
					</label></p>
					<p>
						<button type="button" class="button" id="man-probar-voz">Probar clave y voz</button>
						<span id="man-resultado-voz"></span>
					</p>
				</div>

				<div class="man-card">
					<h2>Límites de uso</h2>
					<p class="description">
						Cada consulta tiene un costo para la entidad. Estos topes evitan que un uso
						automatizado agote el presupuesto.
					</p>
					<p>
						<label>Consultas por visitante y hora
							<input type="number" min="1" max="100" style="width:90px"
								name="man_ai[limite_ip_hora]" value="<?php echo esc_attr( (int) $cfg['limite_ip_hora'] ); ?>" />
						</label>
						&nbsp;&nbsp;
						<label>Tope de consultas al día (0 = sin tope)
							<input type="number" min="0" max="100000" style="width:110px"
								name="man_ai[limite_dia]" value="<?php echo esc_attr( (int) $cfg['limite_dia'] ); ?>" />
						</label>
					</p>
					<p class="man-estado-linea">
						Consumo de hoy: <strong><?php echo esc_html( number_format_i18n( $consumo ) ); ?></strong>
						de <?php echo (int) $cfg['limite_dia'] > 0 ? esc_html( number_format_i18n( (int) $cfg['limite_dia'] ) ) : 'sin tope'; ?>.
						<span class="description">Las respuestas repetidas se sirven de caché y no consumen cupo.</span>
					</p>
				</div>

				<?php submit_button( 'Guardar configuración del asistente' ); ?>
			</form>

			<h2>Diagnóstico</h2>
			<p class="description">
				Comprobación de toda la cadena, en orden. Si el asistente falla, el primer
				renglón en rojo dice qué arreglar.
			</p>

			<?php $revision = MAN_AI::revisar_configuracion(); ?>
			<table class="widefat striped" style="max-width:62em">
				<thead><tr><th style="width:14em">Comprobación</th><th>Resultado</th></tr></thead>
				<tbody>
				<?php foreach ( $revision as $fila ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $fila['paso'] ); ?></strong></td>
						<td>
							<span class="man-dot" style="background:<?php echo esc_attr( self::color_estado( $fila['estado'] ) ); ?>"></span>
							<?php echo esc_html( $fila['mensaje'] ); ?>
							<?php if ( ! empty( $fila['pista'] ) ) : ?>
								<br><span class="description"><?php echo esc_html( $fila['pista'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3>Registro de errores</h3>
			<?php $log = MAN_AI::diagnostico(); ?>
			<?php if ( empty( $log ) ) : ?>
				<p class="description">Sin eventos registrados. Aquí aparecerá el detalle exacto de cada
					fallo del proveedor: el código HTTP y el motivo que devuelve, que es lo que no se
					puede ver desde el sitio público.</p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:62em">
					<thead><tr><th style="width:12em">Cuándo</th><th style="width:8em">Paso</th><th>Detalle</th></tr></thead>
					<tbody>
					<?php foreach ( $log as $ev ) : ?>
						<tr>
							<td><?php echo esc_html( $ev['ts'] ); ?> UTC</td>
							<td>
								<span class="man-dot" style="background:<?php echo esc_attr( 'ok' === $ev['nivel'] ? '#2e7d32' : '#c62828' ); ?>"></span>
								<?php echo esc_html( $ev['paso'] ); ?>
							</td>
							<td>
								<?php echo esc_html( $ev['mensaje'] ); ?>
								<?php if ( ! empty( $ev['extra']['pista'] ) ) : ?>
									<br><span class="description"><strong>Qué hacer:</strong> <?php echo esc_html( $ev['extra']['pista'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $ev['extra']['cuerpo'] ) ) : ?>
									<details style="margin-top:4px">
										<summary class="description" style="cursor:pointer">Respuesta cruda del proveedor</summary>
										<pre style="white-space:pre-wrap;word-break:break-word;background:#f6f7f7;padding:8px;margin:4px 0 0;font-size:11px"><?php echo esc_html( $ev['extra']['cuerpo'] ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'man-ai', 'man_ai_limpiar' => '1' ), admin_url( 'admin.php' ) ), 'man_ai_limpiar' ) ); ?>">Vaciar registro</a>
				</p>
			<?php endif; ?>

			<p class="description" style="max-width:62em">
				En el sitio público, el detalle de cada fallo también se escribe en la
				<strong>consola del navegador</strong> (F12 → Consola). Si está con sesión de
				administrador verá ahí el motivo exacto del proveedor; si no, solo el mensaje
				general.
			</p>

			<h2>Códigos de error</h2>
			<p class="description" style="max-width:62em">
				Cada fallo tiene un código estable que aparece en la consola del navegador
				(F12 → Consola), en el registro de arriba y en los mensajes al operador. Si alguien
				reporta un problema, pídale el código: dice exactamente dónde se rompió.
			</p>
			<table class="widefat striped" style="max-width:62em">
				<thead><tr><th style="width:9em">Código</th><th>Qué ocurrió</th><th>Qué hacer</th></tr></thead>
				<tbody>
				<?php foreach ( MAN_AI_Codigos::catalogo() as $num => $f ) : ?>
					<tr>
						<td><code><?php echo esc_html( MAN_AI_Codigos::codigo( $num ) ); ?></code></td>
						<td><?php echo esc_html( $f['que'] ); ?></td>
						<td class="description"><?php echo esc_html( $f['haz'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Cómo publicarlo</h2>
			<p>El asistente aparece junto al globo cuando está activo y visible. También puede colocarlo aparte:</p>
			<p><code>[man_asistente]</code> — botón «Asistente» que abre la ventana.<br />
				<code>[man_asistente etiqueta="Pregúntame"]</code> — cambia el texto del botón.<br />
				<code>[man_asistente municipio="52835"]</code> — enfocado en un municipio.<br />
				<code>[man_asistente voz="no"]</code> — solo texto, sin micrófono ni escucha.</p>
			<p>En el globo puede desactivarlo con <code>[man_globo asistente="no"]</code>.</p>
		</div>
		<?php
	}

	/**
	 * Color del semáforo de una comprobación.
	 *
	 * @param string $estado ok|aviso|error.
	 * @return string
	 */
	private static function color_estado( $estado ) {
		switch ( $estado ) {
			case 'ok':
				return '#2e7d32';
			case 'aviso':
				return '#f9a825';
			default:
				return '#c62828';
		}
	}

	/**
	 * Persiste la configuración.
	 */
	public function guardar() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'man_ai' );

		$actual = MAN_AI::config();
		$in     = isset( $_POST['man_ai'] ) && is_array( $_POST['man_ai'] ) ? wp_unslash( $_POST['man_ai'] ) : array();

		$nuevo = $actual;

		$nuevo['activo']     = ! empty( $in['activo'] );
		$nuevo['visible']    = ! empty( $in['visible'] );
		$nuevo['voz_activa'] = ! empty( $in['voz_activa'] );

		$proveedores          = MAN_AI::proveedores();
		$prov                 = isset( $in['proveedor'] ) ? sanitize_key( $in['proveedor'] ) : 'claude';
		$nuevo['proveedor']   = isset( $proveedores[ $prov ] ) ? $prov : 'claude';
		$nuevo['anfitrion']   = isset( $in['anfitrion'] ) ? sanitize_text_field( $in['anfitrion'] ) : '';
		$nuevo['saludo']      = isset( $in['saludo'] ) ? sanitize_textarea_field( $in['saludo'] ) : '';
		$nuevo['sugerencias'] = isset( $in['sugerencias'] ) ? sanitize_textarea_field( $in['sugerencias'] ) : '';
		$nuevo['voz_id']      = isset( $in['voz_id'] ) ? sanitize_text_field( $in['voz_id'] ) : $actual['voz_id'];
		$nuevo['voz_modelo']  = isset( $in['voz_modelo'] ) && '' !== trim( $in['voz_modelo'] )
			? sanitize_text_field( $in['voz_modelo'] )
			: 'eleven_multilingual_v2';

		$nuevo['limite_ip_hora'] = isset( $in['limite_ip_hora'] ) ? max( 1, min( 100, (int) $in['limite_ip_hora'] ) ) : 10;
		$nuevo['limite_dia']     = isset( $in['limite_dia'] ) ? max( 0, min( 100000, (int) $in['limite_dia'] ) ) : 500;

		// Modelos: solo se aceptan los del catálogo, no texto libre.
		foreach ( $proveedores as $slug => $p ) {
			$campo = $slug . '_modelo';
			if ( isset( $in[ $campo ] ) && isset( $p['modelos'][ $in[ $campo ] ] ) ) {
				$nuevo[ $campo ] = $in[ $campo ];
			}
		}

		// Claves: solo se reemplazan si se escribió una nueva, igual que en el
		// panel de fuentes. Así un guardado no borra lo ya configurado.
		foreach ( array( 'claude_clave', 'openai_clave', 'gemini_clave', 'voz_clave' ) as $campo ) {
			if ( isset( $in[ $campo ] ) && '' !== trim( $in[ $campo ] ) ) {
				$nuevo[ $campo ] = MAN_Security::cifrar( trim( $in[ $campo ] ) );
			}
		}

		update_option( MAN_AI::OPCION, $nuevo );
		MAN_Sync::auditar( 'config', 'ai', 'ok', 0, 'Configuración del asistente actualizada' );

		wp_safe_redirect( add_query_arg( array( 'page' => 'man-ai', 'man_msg' => 'guardado' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * AJAX: comprueba la clave y el ID de voz de ElevenLabs.
	 */
	public function ajax_probar_voz() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => 'No autorizado' ), 403 );
		}
		check_ajax_referer( 'man_admin', 'nonce' );

		$res = MAN_AI_Voz::probar();
		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( array( 'mensaje' => $res['mensaje'] ) );
		}
		wp_send_json_error( array( 'mensaje' => $res['mensaje'] ) );
	}

	/**
	 * AJAX: pregunta de prueba al proveedor, sin tocar el cupo ni la
	 * configuración guardada.
	 */
	public function ajax_probar_ai() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => 'No autorizado' ), 403 );
		}
		check_ajax_referer( 'man_admin', 'nonce' );

		$slug = isset( $_POST['proveedor'] ) ? sanitize_key( wp_unslash( $_POST['proveedor'] ) ) : '';
		$res  = MAN_AI::probar_proveedor( $slug );

		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( array( 'mensaje' => $res['mensaje'] ) );
		}
		wp_send_json_error( array( 'mensaje' => $res['mensaje'] ) );
	}
}
