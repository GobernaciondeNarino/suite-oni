<?php
/**
 * Módulo de IA: asistente ciudadano sobre los datos del monitor.
 *
 * Enruta la consulta al proveedor configurado (Claude, ChatGPT o Gemini) y
 * devuelve una respuesta breve construida ÚNICAMENTE sobre los datos que el
 * plugin ya publica. Las claves viven cifradas en el servidor y nunca llegan al
 * navegador: el front habla con la REST del plugin, que actúa de intermediaria.
 *
 * Defensas, porque el endpoint es público y cada consulta cuesta dinero:
 *   - cupo por IP y ventana fija (MAN_Security::rate_limit),
 *   - tope diario global de consultas,
 *   - caché de respuestas por pregunta normalizada,
 *   - longitud máxima de pregunta y de respuesta.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_AI {

	/** Opción donde vive la configuración del módulo. */
	const OPCION = 'man_ai_config';

	/** Prefijo de caché de respuestas. */
	const CLAVE_RESP = 'ai_resp_';

	/** Clave del contador diario de consultas. */
	const CLAVE_CONTADOR = 'ai_consumo_dia';

	/** Límites de entrada y salida. */
	const MAX_PREGUNTA   = 500;
	const MAX_RESPUESTA  = 600;
	const TTL_RESPUESTA  = 6 * HOUR_IN_SECONDS;

	/**
	 * Proveedores soportados y sus modelos sugeridos.
	 *
	 * El primer modelo de cada lista es el que se siembra por defecto.
	 *
	 * @return array<string,array>
	 */
	public static function proveedores() {
		return array(
			'claude' => array(
				'nombre'  => 'Claude (Anthropic)',
				'modelos' => array(
					'claude-opus-5-5'   => 'Claude Opus 5.5 — el más capaz',
					'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — equilibrado',
					'claude-haiku-5-5'  => 'Claude Haiku 5.5 — el más económico',
				),
				'ayuda'   => 'Clave de console.anthropic.com (empieza por sk-ant-).',
			),
			'openai' => array(
				'nombre'  => 'ChatGPT (OpenAI)',
				'modelos' => array(
					'gpt-4o'      => 'GPT-4o',
					'gpt-4o-mini' => 'GPT-4o mini — más económico',
				),
				'ayuda'   => 'Clave de platform.openai.com (empieza por sk-).',
			),
			'gemini' => array(
				'nombre'  => 'Gemini (Google)',
				'modelos' => array(
					'gemini-2.0-flash' => 'Gemini 2.0 Flash',
					'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
				),
				'ayuda'   => 'Clave de aistudio.google.com/apikey.',
			),
		);
	}

	/**
	 * Configuración vigente, con los valores por defecto aplicados.
	 *
	 * @return array
	 */
	public static function config() {
		$cfg = get_option( self::OPCION, array() );
		return wp_parse_args(
			is_array( $cfg ) ? $cfg : array(),
			self::config_por_defecto()
		);
	}

	/**
	 * Valores por defecto: el módulo nace APAGADO y oculto.
	 *
	 * @return array
	 */
	public static function config_por_defecto() {
		return array(
			'activo'          => false, // el módulo responde consultas
			'visible'         => false, // el componente se pinta en el front
			'proveedor'       => 'claude',
			'claude_clave'    => '',
			'claude_modelo'   => 'claude-opus-5-5',
			'openai_clave'    => '',
			'openai_modelo'   => 'gpt-4o-mini',
			'gemini_clave'    => '',
			'gemini_modelo'   => 'gemini-2.0-flash',
			// Voz (ElevenLabs).
			'voz_activa'      => false,
			'voz_clave'       => '',
			'voz_id'          => '',
			'voz_modelo'      => 'eleven_multilingual_v2',
			// Defensas.
			'limite_ip_hora'  => 10,
			'limite_dia'      => 500,
			'saludo'          => '',
		);
	}

	/** ¿El módulo puede responder? */
	public static function activo() {
		$c = self::config();
		return ! empty( $c['activo'] ) && '' !== self::clave_proveedor( $c, $c['proveedor'] );
	}

	/** ¿El componente debe pintarse en el front? */
	public static function visible() {
		$c = self::config();
		return ! empty( $c['visible'] ) && self::activo();
	}

	/** ¿La respuesta hablada está disponible? */
	public static function voz_disponible() {
		$c = self::config();
		return ! empty( $c['voz_activa'] )
			&& '' !== self::descifrar( $c['voz_clave'] )
			&& '' !== trim( (string) $c['voz_id'] );
	}

	/**
	 * Estado público del módulo (lo consulta el front para decidir si se pinta).
	 *
	 * Nunca expone claves ni nombres de modelo: solo capacidades.
	 *
	 * @return array
	 */
	public static function estado() {
		$c = self::config();
		return array(
			'activo'  => self::activo(),
			'visible' => self::visible(),
			'voz'     => self::voz_disponible(),
			'saludo'  => trim( (string) $c['saludo'] ),
			'limite'  => (int) $c['limite_ip_hora'],
		);
	}

	/* ----------------------------------------------------------------- */
	/* Consulta                                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Responde una pregunta ciudadana.
	 *
	 * @param string $pregunta  Texto de la persona.
	 * @param string $municipio DIVIPOLA opcional para enfocar el contexto.
	 * @return array|\WP_Error {respuesta, proveedor, cacheada}
	 */
	public static function preguntar( $pregunta, $municipio = '' ) {
		if ( ! self::activo() ) {
			return new \WP_Error( 'man_ai_inactivo', 'El asistente no está disponible en este momento.', array( 'status' => 503 ) );
		}

		$pregunta = trim( wp_strip_all_tags( (string) $pregunta ) );
		if ( '' === $pregunta ) {
			return new \WP_Error( 'man_ai_vacia', 'Escriba una pregunta.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $pregunta ) > self::MAX_PREGUNTA ) {
			$pregunta = mb_substr( $pregunta, 0, self::MAX_PREGUNTA );
		}

		$cfg = self::config();

		// 1) Respuesta ya calculada para la misma pregunta: ni cupo ni costo.
		$clave_cache = self::CLAVE_RESP . md5( $cfg['proveedor'] . '|' . $municipio . '|' . self::normalizar( $pregunta ) );
		$cacheada    = MAN_Cache::get( $clave_cache );
		if ( is_array( $cacheada ) && ! empty( $cacheada['respuesta'] ) ) {
			$cacheada['cacheada'] = true;
			return $cacheada;
		}

		// 2) Cupo por IP.
		$por_hora = max( 1, (int) $cfg['limite_ip_hora'] );
		if ( ! MAN_Security::rate_limit( 'ai_consulta', $por_hora, HOUR_IN_SECONDS ) ) {
			return new \WP_Error(
				'man_ai_cupo',
				'Ha alcanzado el límite de consultas por hora. Intente más tarde; mientras tanto puede explorar los datos del sitio.',
				array( 'status' => 429 )
			);
		}

		// 3) Tope diario global: protege el presupuesto de la entidad.
		if ( ! self::consumir_cupo_diario( (int) $cfg['limite_dia'] ) ) {
			return new \WP_Error(
				'man_ai_tope',
				'El asistente alcanzó su límite de consultas del día. Vuelva mañana.',
				array( 'status' => 429 )
			);
		}

		$sistema = MAN_AI_Contexto::sistema( $municipio );
		$clave   = self::descifrar( self::clave_proveedor( $cfg, $cfg['proveedor'] ) );

		switch ( $cfg['proveedor'] ) {
			case 'openai':
				$res = self::llamar_openai( $clave, $cfg['openai_modelo'], $sistema, $pregunta );
				break;
			case 'gemini':
				$res = self::llamar_gemini( $clave, $cfg['gemini_modelo'], $sistema, $pregunta );
				break;
			case 'claude':
			default:
				$res = self::llamar_claude( $clave, $cfg['claude_modelo'], $sistema, $pregunta );
				break;
		}

		if ( is_wp_error( $res ) ) {
			MAN_Sync::auditar( 'ai', $cfg['proveedor'], 'error', 0, $res->get_error_message() );
			return $res;
		}

		$salida = array(
			'respuesta' => $res,
			'proveedor' => $cfg['proveedor'],
			'cacheada'  => false,
		);
		MAN_Cache::set( $clave_cache, $salida, self::TTL_RESPUESTA, 'ai' );
		MAN_Sync::auditar( 'ai', $cfg['proveedor'], 'ok', 1, mb_substr( $pregunta, 0, 120 ) );

		return $salida;
	}

	/* ----------------------------------------------------------------- */
	/* Proveedores                                                       */
	/* ----------------------------------------------------------------- */

	/**
	 * Claude (Anthropic) — POST /v1/messages.
	 *
	 * En los modelos actuales el razonamiento es adaptativo y no se configura
	 * con presupuesto de tokens; el esfuerzo se baja a «low» porque aquí se
	 * piden respuestas cortas de divulgación, no análisis profundo.
	 *
	 * @param string $clave    Clave en claro.
	 * @param string $modelo   Identificador de modelo.
	 * @param string $sistema  Instrucción de sistema.
	 * @param string $pregunta Pregunta.
	 * @return string|\WP_Error
	 */
	private static function llamar_claude( $clave, $modelo, $sistema, $pregunta ) {
		$cuerpo = array(
			'model'         => $modelo ? $modelo : 'claude-opus-5-5',
			'max_tokens'    => self::MAX_RESPUESTA,
			'system'        => $sistema,
			'output_config' => array( 'effort' => 'low' ),
			'messages'      => array(
				array( 'role' => 'user', 'content' => $pregunta ),
			),
		);

		$r = self::http_post(
			'https://api.anthropic.com/v1/messages',
			$cuerpo,
			array(
				'x-api-key'         => $clave,
				'anthropic-version' => '2023-06-01',
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		// Las clasificaciones de seguridad del proveedor devuelven HTTP 200 con
		// stop_reason=refusal y sin texto: hay que distinguirlo de un fallo.
		if ( isset( $r['stop_reason'] ) && 'refusal' === $r['stop_reason'] ) {
			return new \WP_Error( 'man_ai_rechazo', 'No puedo responder a esa consulta. Pregúnteme sobre el clima y el ambiente en Nariño.', array( 'status' => 200 ) );
		}

		$texto = '';
		foreach ( ( isset( $r['content'] ) && is_array( $r['content'] ) ? $r['content'] : array() ) as $bloque ) {
			if ( isset( $bloque['type'], $bloque['text'] ) && 'text' === $bloque['type'] ) {
				$texto .= $bloque['text'];
			}
		}
		return self::validar_texto( $texto );
	}

	/**
	 * ChatGPT (OpenAI) — POST /v1/chat/completions.
	 *
	 * @param string $clave    Clave en claro.
	 * @param string $modelo   Modelo.
	 * @param string $sistema  Instrucción de sistema.
	 * @param string $pregunta Pregunta.
	 * @return string|\WP_Error
	 */
	private static function llamar_openai( $clave, $modelo, $sistema, $pregunta ) {
		$cuerpo = array(
			'model'      => $modelo ? $modelo : 'gpt-4o-mini',
			'max_tokens' => self::MAX_RESPUESTA,
			'messages'   => array(
				array( 'role' => 'system', 'content' => $sistema ),
				array( 'role' => 'user', 'content' => $pregunta ),
			),
		);

		$r = self::http_post(
			'https://api.openai.com/v1/chat/completions',
			$cuerpo,
			array( 'Authorization' => 'Bearer ' . $clave )
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		$texto = isset( $r['choices'][0]['message']['content'] ) ? $r['choices'][0]['message']['content'] : '';
		return self::validar_texto( $texto );
	}

	/**
	 * Gemini (Google) — POST /v1beta/models/{modelo}:generateContent.
	 *
	 * @param string $clave    Clave en claro.
	 * @param string $modelo   Modelo.
	 * @param string $sistema  Instrucción de sistema.
	 * @param string $pregunta Pregunta.
	 * @return string|\WP_Error
	 */
	private static function llamar_gemini( $clave, $modelo, $sistema, $pregunta ) {
		$modelo = $modelo ? $modelo : 'gemini-2.0-flash';
		$cuerpo = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => $sistema ) ),
			),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $pregunta ) ),
				),
			),
			'generationConfig'  => array( 'maxOutputTokens' => self::MAX_RESPUESTA ),
		);

		// La clave va en cabecera, no en la URL: así no queda en logs de proxy.
		$r = self::http_post(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $modelo ) . ':generateContent',
			$cuerpo,
			array( 'x-goog-api-key' => $clave )
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}

		$texto = '';
		foreach ( ( isset( $r['candidates'][0]['content']['parts'] ) ? $r['candidates'][0]['content']['parts'] : array() ) as $parte ) {
			if ( isset( $parte['text'] ) ) {
				$texto .= $parte['text'];
			}
		}
		return self::validar_texto( $texto );
	}

	/* ----------------------------------------------------------------- */
	/* Utilidades                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * POST JSON con manejo de error uniforme.
	 *
	 * @param string $url        Endpoint.
	 * @param array  $cuerpo     Cuerpo a serializar.
	 * @param array  $cabeceras  Cabeceras de autenticación.
	 * @return array|\WP_Error Respuesta decodificada.
	 */
	private static function http_post( $url, $cuerpo, $cabeceras ) {
		$resp = wp_remote_post(
			$url,
			array(
				'timeout'    => 30,
				'headers'    => array_merge(
					array( 'Content-Type' => 'application/json' ),
					$cabeceras
				),
				'body'       => wp_json_encode( $cuerpo ),
				'user-agent' => 'MonitorAmbientalNarino/' . MAN_VERSION,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'man_ai_red', 'No se pudo contactar al servicio de IA: ' . $resp->get_error_message(), array( 'status' => 502 ) );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		$json   = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( $codigo < 200 || $codigo >= 300 ) {
			// El detalle va al registro de auditoría, no al visitante: puede
			// contener información del proveedor que no debe hacerse pública.
			$detalle = isset( $json['error']['message'] ) ? $json['error']['message'] : 'HTTP ' . $codigo;
			return new \WP_Error( 'man_ai_proveedor', 'El servicio de IA respondió con un error (' . $codigo . ').', array( 'status' => 502, 'detalle' => $detalle ) );
		}

		if ( ! is_array( $json ) ) {
			return new \WP_Error( 'man_ai_json', 'El servicio de IA devolvió una respuesta ilegible.', array( 'status' => 502 ) );
		}
		return $json;
	}

	/**
	 * Valida y recorta el texto devuelto por el modelo.
	 *
	 * @param string $texto Texto crudo.
	 * @return string|\WP_Error
	 */
	private static function validar_texto( $texto ) {
		$texto = trim( wp_strip_all_tags( (string) $texto ) );
		if ( '' === $texto ) {
			return new \WP_Error( 'man_ai_vacio', 'El asistente no pudo formular una respuesta. Intente reformular la pregunta.', array( 'status' => 502 ) );
		}
		return $texto;
	}

	/**
	 * Normaliza la pregunta para que variantes triviales compartan caché.
	 *
	 * @param string $p Pregunta.
	 * @return string
	 */
	private static function normalizar( $p ) {
		$p = strtolower( remove_accents( $p ) );
		$p = preg_replace( '/[^a-z0-9ñ ]/', ' ', $p );
		return trim( preg_replace( '/\s+/', ' ', $p ) );
	}

	/**
	 * Descuenta una consulta del tope diario.
	 *
	 * @param int $tope Máximo de consultas al día (0 = sin tope).
	 * @return bool True si quedaba cupo.
	 */
	private static function consumir_cupo_diario( $tope ) {
		if ( $tope <= 0 ) {
			return true;
		}
		$clave = self::CLAVE_CONTADOR . '_' . gmdate( 'Y-m-d' );
		$n     = (int) MAN_Cache::get( $clave );
		if ( $n >= $tope ) {
			return false;
		}
		MAN_Cache::set( $clave, $n + 1, DAY_IN_SECONDS, 'ai' );
		return true;
	}

	/** Consultas consumidas hoy (para el panel). */
	public static function consumo_hoy() {
		return (int) MAN_Cache::get( self::CLAVE_CONTADOR . '_' . gmdate( 'Y-m-d' ) );
	}

	/**
	 * Consulta de prueba contra un proveedor concreto, para el panel.
	 *
	 * No pasa por la caché, el cupo ni la configuración guardada: comprueba que
	 * la clave y el modelo de ESE proveedor funcionan, aunque no sea el activo.
	 *
	 * @param string $slug Proveedor a probar.
	 * @return array {ok, mensaje}
	 */
	public static function probar_proveedor( $slug ) {
		$cfg   = self::config();
		$slug  = isset( self::proveedores()[ $slug ] ) ? $slug : $cfg['proveedor'];
		$clave = self::descifrar( self::clave_proveedor( $cfg, $slug ) );

		if ( '' === $clave ) {
			return array( 'ok' => false, 'mensaje' => 'No hay clave guardada para este proveedor (o no se pudo descifrar: revise si cambiaron las sales de wp-config.php).' );
		}

		$sistema  = MAN_AI_Contexto::sistema( '' );
		$pregunta = '¿En qué fase del fenómeno ENSO estamos y qué significa para Nariño?';

		switch ( $slug ) {
			case 'openai':
				$res = self::llamar_openai( $clave, $cfg['openai_modelo'], $sistema, $pregunta );
				break;
			case 'gemini':
				$res = self::llamar_gemini( $clave, $cfg['gemini_modelo'], $sistema, $pregunta );
				break;
			case 'claude':
			default:
				$res = self::llamar_claude( $clave, $cfg['claude_modelo'], $sistema, $pregunta );
				break;
		}

		if ( is_wp_error( $res ) ) {
			$datos   = $res->get_error_data();
			$detalle = is_array( $datos ) && ! empty( $datos['detalle'] ) ? ' — ' . $datos['detalle'] : '';
			return array( 'ok' => false, 'mensaje' => $res->get_error_message() . $detalle );
		}
		return array( 'ok' => true, 'mensaje' => 'Responde correctamente: «' . mb_substr( $res, 0, 180 ) . '…»' );
	}

	/**
	 * Clave cifrada del proveedor indicado.
	 *
	 * @param array  $cfg       Configuración.
	 * @param string $proveedor Slug.
	 * @return string
	 */
	public static function clave_proveedor( $cfg, $proveedor ) {
		$mapa = array(
			'claude' => 'claude_clave',
			'openai' => 'openai_clave',
			'gemini' => 'gemini_clave',
		);
		return isset( $mapa[ $proveedor ], $cfg[ $mapa[ $proveedor ] ] ) ? (string) $cfg[ $mapa[ $proveedor ] ] : '';
	}

	/**
	 * Descifra una clave guardada, tolerando que no se pueda leer.
	 *
	 * @param string $guardada Paquete cifrado.
	 * @return string
	 */
	public static function descifrar( $guardada ) {
		$guardada = (string) $guardada;
		if ( '' === $guardada ) {
			return '';
		}
		return MAN_Security::descifrar( $guardada );
	}
}
