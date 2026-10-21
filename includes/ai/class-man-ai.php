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

	/** Opción con los últimos eventos de diagnóstico (sin claves). */
	const OPCION_DIAG = 'man_ai_diagnostico';

	/** Cuántos eventos se conservan. */
	const MAX_DIAG = 15;

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
	/* Diagnóstico                                                       */
	/* ----------------------------------------------------------------- */

	/**
	 * Registra un evento para el panel de diagnóstico.
	 *
	 * Guarda lo que hace falta para entender un fallo sin tener acceso al
	 * servidor: paso, proveedor, código HTTP y el cuerpo que devolvió el
	 * proveedor, recortado. Nunca guarda la clave.
	 *
	 * @param string $paso    Etapa donde ocurrió.
	 * @param string $nivel   ok|error.
	 * @param string $mensaje Resumen legible.
	 * @param array  $extra   Datos adicionales (url, codigo, cuerpo…).
	 */
	public static function diag( $paso, $nivel, $mensaje, $extra = array() ) {
		$log = get_option( self::OPCION_DIAG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// La clave jamás entra al registro, venga de donde venga.
		foreach ( array( 'clave', 'api_key', 'key', 'authorization', 'x-api-key' ) as $prohibido ) {
			unset( $extra[ $prohibido ] );
		}
		if ( isset( $extra['cuerpo'] ) ) {
			$extra['cuerpo'] = mb_substr( (string) $extra['cuerpo'], 0, 600 );
		}

		array_unshift(
			$log,
			array(
				'ts'      => current_time( 'mysql', true ),
				'paso'    => (string) $paso,
				'nivel'   => ( 'ok' === $nivel ) ? 'ok' : 'error',
				'mensaje' => mb_substr( (string) $mensaje, 0, 400 ),
				'extra'   => $extra,
			)
		);
		update_option( self::OPCION_DIAG, array_slice( $log, 0, self::MAX_DIAG ), false );
	}

	/** Eventos de diagnóstico guardados, del más reciente al más antiguo. */
	public static function diagnostico() {
		$log = get_option( self::OPCION_DIAG, array() );
		return is_array( $log ) ? $log : array();
	}

	/** Vacía el registro de diagnóstico. */
	public static function limpiar_diagnostico() {
		delete_option( self::OPCION_DIAG );
	}

	/**
	 * Comprobación paso a paso de la configuración.
	 *
	 * Recorre toda la cadena en orden y se detiene donde falla, de modo que el
	 * operador sepa QUÉ arreglar y no solo que «no funciona». No gasta una
	 * consulta al proveedor: eso lo hace el botón «Probar».
	 *
	 * @return array[] Filas {paso, estado, mensaje, pista}
	 */
	public static function revisar_configuracion() {
		$cfg   = self::config();
		$filas = array();

		$add = function ( $paso, $estado, $mensaje, $pista = '' ) use ( &$filas ) {
			$filas[] = array(
				'paso'    => $paso,
				'estado'  => $estado, // ok | aviso | error
				'mensaje' => $mensaje,
				'pista'   => $pista,
			);
		};

		// 1) Interruptores.
		$add(
			'Módulo activado',
			! empty( $cfg['activo'] ) ? 'ok' : 'error',
			! empty( $cfg['activo'] ) ? 'Sí' : 'No: el asistente no responderá',
			! empty( $cfg['activo'] ) ? '' : 'Marque «Activar» arriba y guarde.'
		);
		$add(
			'Visible en el sitio',
			! empty( $cfg['visible'] ) ? 'ok' : 'aviso',
			! empty( $cfg['visible'] ) ? 'Sí' : 'No: está configurado pero no se pinta',
			! empty( $cfg['visible'] ) ? '' : 'Normal mientras lo prueba. Márquelo cuando quiera publicarlo.'
		);

		// 2) Proveedor y clave.
		$prov        = $cfg['proveedor'];
		$proveedores = self::proveedores();
		$nombre      = isset( $proveedores[ $prov ] ) ? $proveedores[ $prov ]['nombre'] : $prov;
		$guardada    = self::clave_proveedor( $cfg, $prov );

		$add( 'Proveedor seleccionado', 'ok', $nombre );

		if ( '' === $guardada ) {
			$add( 'Clave del proveedor', 'error', 'No hay clave guardada', 'Pegue la clave de ' . $nombre . ' y guarde la configuración.' );
		} else {
			$plana = self::descifrar( $guardada );
			if ( '' === $plana ) {
				$add(
					'Clave del proveedor',
					'error',
					'Hay una clave guardada pero NO se puede descifrar',
					'Las sales de seguridad de wp-config.php cambiaron desde que se guardó. Vuelva a pegar la clave y guarde.'
				);
			} else {
				$add( 'Clave del proveedor', 'ok', 'Guardada y legible (' . strlen( $plana ) . ' caracteres)' );
			}
		}

		// 3) Modelo.
		$campo_modelo = $prov . '_modelo';
		$modelo       = isset( $cfg[ $campo_modelo ] ) ? $cfg[ $campo_modelo ] : '';
		$valido       = isset( $proveedores[ $prov ]['modelos'][ $modelo ] );
		$add(
			'Modelo',
			$valido ? 'ok' : 'error',
			$modelo ? $modelo : '(sin modelo)',
			$valido ? '' : 'El modelo guardado no está en el catálogo. Elija uno del desplegable y guarde.'
		);

		// 4) Salida a internet del servidor: el fallo más común en hosting
		//    gestionado, y el que más se confunde con «la clave está mal».
		$destinos = array(
			'claude' => 'https://api.anthropic.com',
			'openai' => 'https://api.openai.com',
			'gemini' => 'https://generativelanguage.googleapis.com',
		);
		$destino  = isset( $destinos[ $prov ] ) ? $destinos[ $prov ] : '';
		if ( $destino ) {
			$ping = wp_remote_get( $destino, array( 'timeout' => 12 ) );
			if ( is_wp_error( $ping ) ) {
				$add(
					'Salida a internet',
					'error',
					'El servidor NO pudo conectar con ' . wp_parse_url( $destino, PHP_URL_HOST ) . ': ' . $ping->get_error_message(),
					'El hosting bloquea las conexiones salientes. Pida que permitan ese dominio, o revise WP_HTTP_BLOCK_EXTERNAL en wp-config.php.'
				);
			} else {
				$add( 'Salida a internet', 'ok', 'El servidor alcanza ' . wp_parse_url( $destino, PHP_URL_HOST ) . ' (HTTP ' . (int) wp_remote_retrieve_response_code( $ping ) . ')' );
			}
		}

		// 5) Cifrado disponible.
		$add(
			'Cifrado de claves',
			function_exists( 'sodium_crypto_secretbox' ) ? 'ok' : 'error',
			function_exists( 'sodium_crypto_secretbox' ) ? 'Disponible (sodium)' : 'La extensión sodium de PHP no está disponible',
			function_exists( 'sodium_crypto_secretbox' ) ? '' : 'Sin sodium las claves no pueden guardarse cifradas. Pida que activen la extensión.'
		);

		// 6) Voz.
		if ( empty( $cfg['voz_activa'] ) ) {
			$add( 'Voz (ElevenLabs)', 'aviso', 'Desactivada', 'Opcional: actívela si quiere que las respuestas se puedan escuchar.' );
		} else {
			$vk = self::descifrar( $cfg['voz_clave'] );
			if ( '' === $vk ) {
				$add( 'Voz (ElevenLabs)', 'error', 'Activada pero sin clave legible', 'Vuelva a pegar la clave de ElevenLabs y guarde.' );
			} elseif ( '' === trim( (string) $cfg['voz_id'] ) ) {
				$add( 'Voz (ElevenLabs)', 'error', 'Activada y con clave, pero falta el ID de voz', 'Copie el ID desde su biblioteca de voces de ElevenLabs.' );
			} else {
				$add( 'Voz (ElevenLabs)', 'ok', 'Clave y ID de voz presentes', 'Use «Probar clave y voz» para confirmar que el ID existe en la cuenta.' );
			}
		}

		// 7) Cupos.
		$consumo = self::consumo_hoy();
		$tope    = (int) $cfg['limite_dia'];
		if ( $tope > 0 && $consumo >= $tope ) {
			$add( 'Tope diario', 'error', 'Alcanzado: ' . $consumo . ' de ' . $tope, 'Suba el tope o espere a mañana. Mientras tanto el asistente rechaza las consultas nuevas.' );
		} else {
			$add( 'Tope diario', 'ok', $tope > 0 ? $consumo . ' de ' . $tope . ' consultas hoy' : 'Sin tope · ' . $consumo . ' consultas hoy' );
		}

		return $filas;
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

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( is_wp_error( $resp ) ) {
			// Fallo de red/DNS/TLS: el hosting suele bloquear la salida HTTP.
			self::diag(
				'red',
				'error',
				$resp->get_error_message(),
				array( 'host' => $host, 'pista' => 'El servidor no pudo salir a internet. Revise el cortafuegos de salida o la lista blanca de dominios del hosting.' )
			);
			return new \WP_Error(
				'man_ai_red',
				'El asistente no está disponible en este momento. Vuelva a intentarlo en unos minutos.',
				array( 'detalle' => 'Sin salida a internet desde el servidor: ' . $resp->get_error_message() )
			);
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		$bruto  = wp_remote_retrieve_body( $resp );
		$json   = json_decode( $bruto, true );

		if ( $codigo < 200 || $codigo >= 300 ) {
			$detalle = self::detalle_error( $json, $codigo );
			self::diag(
				'proveedor',
				'error',
				'HTTP ' . $codigo . ' — ' . $detalle,
				array( 'host' => $host, 'codigo' => $codigo, 'cuerpo' => $bruto, 'pista' => self::pista( $codigo ) )
			);
			return new \WP_Error(
				'man_ai_proveedor',
				// Mensaje llano: el texto del proveedor puede describir la cuenta
				// o el modelo, y no tiene por qué llegar a un visitante.
				'El asistente no está disponible en este momento. Vuelva a intentarlo en unos minutos.',
				array( 'detalle' => 'HTTP ' . $codigo . ' — ' . $detalle, 'codigo_proveedor' => $codigo )
			);
		}

		if ( ! is_array( $json ) ) {
			self::diag( 'respuesta', 'error', 'La respuesta no es JSON', array( 'host' => $host, 'codigo' => $codigo, 'cuerpo' => $bruto ) );
			return new \WP_Error(
				'man_ai_json',
				'El asistente no está disponible en este momento. Vuelva a intentarlo en unos minutos.',
				array( 'detalle' => 'Respuesta no JSON: ' . mb_substr( (string) $bruto, 0, 200 ) )
			);
		}

		self::diag( 'proveedor', 'ok', 'HTTP ' . $codigo . ' correcto', array( 'host' => $host, 'codigo' => $codigo ) );
		return $json;
	}

	/**
	 * Extrae el motivo que informa el proveedor, cada uno con su forma.
	 *
	 * @param array|null $json   Respuesta decodificada.
	 * @param int        $codigo Código HTTP.
	 * @return string
	 */
	private static function detalle_error( $json, $codigo ) {
		if ( ! is_array( $json ) ) {
			return 'sin detalle (la respuesta no era JSON)';
		}
		// Anthropic y OpenAI: {error:{message}}. Google: {error:{message}}.
		if ( isset( $json['error']['message'] ) ) {
			return (string) $json['error']['message'];
		}
		if ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
			return $json['error'];
		}
		if ( isset( $json['detail']['message'] ) ) {
			return (string) $json['detail']['message'];
		}
		if ( isset( $json['message'] ) ) {
			return (string) $json['message'];
		}
		return 'HTTP ' . $codigo . ' sin mensaje del proveedor';
	}

	/**
	 * Pista accionable según el código HTTP del proveedor.
	 *
	 * @param int $codigo Código.
	 * @return string
	 */
	private static function pista( $codigo ) {
		switch ( $codigo ) {
			case 401:
			case 403:
				return 'La clave no es válida, caducó o no tiene permiso para este modelo. Vuelva a pegarla en el panel y guarde.';
			case 404:
				return 'El modelo seleccionado no existe para esta cuenta. Elija otro en el desplegable.';
			case 429:
				return 'La cuenta del proveedor superó su límite de peticiones o se quedó sin saldo.';
			case 400:
				return 'El proveedor rechazó la petición. El detalle de arriba dice exactamente qué parámetro no aceptó.';
			case 500:
			case 502:
			case 503:
			case 529:
				return 'Avería temporal del proveedor. Reintente en unos minutos.';
			default:
				return '';
		}
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
			$datos = $res->get_error_data();
			// Aquí sí se muestra el motivo real: quien lo lee administra el sitio.
			$detalle = ( is_array( $datos ) && ! empty( $datos['detalle'] ) )
				? $datos['detalle']
				: $res->get_error_message();
			return array( 'ok' => false, 'mensaje' => $detalle );
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
