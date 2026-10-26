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

	/** Opción con los modelos que la cuenta de cada proveedor dice tener. */
	const OPCION_MODELOS = 'man_ai_modelos_detectados';

	/** Límites de entrada y salida. */
	const MAX_PREGUNTA   = 500;
	const MAX_RESPUESTA  = 600;
	const TTL_RESPUESTA  = 6 * HOUR_IN_SECONDS;

	/**
	 * Tope de tokens para los modelos que razonan antes de responder (Claude y
	 * Gemini actuales). El razonamiento cuenta dentro del tope aunque no se
	 * devuelva: con 600, el modelo podía gastarlo entero pensando y responder
	 * vacío o cortado. El largo de la respuesta lo fija el prompt (2 a 5
	 * frases) y solo se cobra lo que se genera, así que subir el tope no
	 * encarece las respuestas normales.
	 */
	const MAX_TOKENS_RAZONADOR = 4096;

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
				'nombre'     => 'Claude (Anthropic)',
				'modelos'    => array(
					'claude-opus-5-5'   => 'Claude Opus 5.5 — recomendado',
					'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — equilibrado',
					'claude-haiku-5-5'  => 'Claude Haiku 5.5 — el más económico',
				),
				'ayuda'      => 'Clave de console.anthropic.com (empieza por sk-ant-).',
				'detectable' => true,
			),
			'openai' => array(
				'nombre'     => 'ChatGPT (OpenAI)',
				'modelos'    => array(
					'gpt-4o'      => 'GPT-4o',
					'gpt-4o-mini' => 'GPT-4o mini — más económico',
				),
				'ayuda'      => 'Clave de platform.openai.com (empieza por sk-).',
				'detectable' => false,
			),
			// Gemini 2.0 Flash se apagó el 1 de junio de 2026 y 1.5 Pro mucho
			// antes: con ellos toda consulta devolvía HTTP 404.
			'gemini' => array(
				'nombre'     => 'Gemini (Google)',
				'modelos'    => array(
					'gemini-3.8-flash'      => 'Gemini 3.8 Flash — recomendado',
					'gemini-4-argon'        => 'Gemini 4 Argon — el más capaz',
					'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite — el más económico',
				),
				'ayuda'      => 'Clave de aistudio.google.com/apikey. Desde 2026 Google rechaza las claves estándar sin restricción: use una clave creada en AI Studio o restrinja la suya a «Generative Language API».',
				'detectable' => true,
			),
		);
	}

	/**
	 * Modelos elegibles para un proveedor: los del catálogo más los que la
	 * cuenta informó con «Detectar modelos».
	 *
	 * @param string $slug Proveedor.
	 * @return array<string,string> id => etiqueta.
	 */
	public static function modelos_de( $slug ) {
		$proveedores = self::proveedores();
		if ( ! isset( $proveedores[ $slug ] ) ) {
			return array();
		}
		$lista     = $proveedores[ $slug ]['modelos'];
		$detectado = self::modelos_detectados( $slug );
		foreach ( $detectado['modelos'] as $id => $nombre ) {
			if ( ! isset( $lista[ $id ] ) ) {
				$lista[ $id ] = $nombre . ' · detectado en su cuenta';
			}
		}
		return $lista;
	}

	/**
	 * Última detección guardada para un proveedor.
	 *
	 * @param string $slug Proveedor.
	 * @return array {fecha:int, modelos:array<string,string>}
	 */
	public static function modelos_detectados( $slug ) {
		$todo = get_option( self::OPCION_MODELOS, array() );
		$item = ( is_array( $todo ) && isset( $todo[ $slug ] ) && is_array( $todo[ $slug ] ) ) ? $todo[ $slug ] : array();
		return array(
			'fecha'   => isset( $item['fecha'] ) ? (int) $item['fecha'] : 0,
			'modelos' => ( isset( $item['modelos'] ) && is_array( $item['modelos'] ) ) ? $item['modelos'] : array(),
		);
	}

	/**
	 * Modelo con el que se llama de verdad: el guardado si sigue siendo
	 * elegible; si no (p. ej. el proveedor lo retiró), el recomendado.
	 *
	 * @param string $slug   Proveedor.
	 * @param string $modelo Modelo guardado.
	 * @return string
	 */
	public static function modelo_vigente( $slug, $modelo ) {
		$elegibles = self::modelos_de( $slug );
		if ( '' !== (string) $modelo && isset( $elegibles[ $modelo ] ) ) {
			return (string) $modelo;
		}
		$proveedores = self::proveedores();
		return isset( $proveedores[ $slug ] ) ? (string) key( $proveedores[ $slug ]['modelos'] ) : (string) $modelo;
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
			'gemini_modelo'   => 'gemini-3.8-flash',
			// Voz (ElevenLabs).
			'voz_activa'      => false,
			'voz_clave'       => '',
			'voz_id'          => '',
			'voz_modelo'      => 'eleven_multilingual_v2',
			// Defensas.
			'limite_ip_hora'  => 10,
			'limite_dia'      => 500,
			// Presentación del asistente.
			'anfitrion'       => 'Tu anfitrión',
			'saludo'          => '',
			'sugerencias'     => '',
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
			'activo'      => self::activo(),
			'visible'     => self::visible(),
			'voz'         => self::voz_disponible(),
			'anfitrion'   => self::anfitrion(),
			'saludo'      => self::saludo(),
			'sugerencias' => self::sugerencias(),
			'limite'      => (int) $c['limite_ip_hora'],
		);
	}

	/** Nombre con el que se presenta el asistente. */
	public static function anfitrion() {
		$c = self::config();
		$n = trim( (string) $c['anfitrion'] );
		return '' !== $n ? $n : 'Tu anfitrión';
	}

	/** Saludo inicial, con uno por defecto si no se configuró. */
	public static function saludo() {
		$c = self::config();
		$s = trim( (string) $c['saludo'] );
		if ( '' !== $s ) {
			return $s;
		}
		return '¡Hola! Le doy la bienvenida. Puedo contarle cómo está el fenómeno El Niño y las condiciones ambientales en Nariño. ¿Qué le gustaría saber?';
	}

	/**
	 * Preguntas sugeridas que se ofrecen como atajos.
	 *
	 * Se guardan una por línea; si no hay ninguna, se usan las de ejemplo, que
	 * son las que el sistema puede responder con los datos que publica.
	 *
	 * @return string[]
	 */
	public static function sugerencias() {
		$c     = self::config();
		$crudo = trim( (string) $c['sugerencias'] );

		if ( '' === $crudo ) {
			return array(
				'¿Cómo está el fenómeno El Niño ahora?',
				'¿Qué se espera para los próximos meses?',
				'¿Qué municipios tienen más riesgo?',
				'¿Qué significa el índice ONI?',
			);
		}

		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $crudo ) as $linea ) {
			$linea = trim( $linea );
			if ( '' !== $linea ) {
				$out[] = mb_substr( $linea, 0, 120 );
			}
		}
		return array_slice( $out, 0, 8 );
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
			return self::error( '001', 'El asistente no está disponible en este momento.' );
		}

		$pregunta = trim( wp_strip_all_tags( (string) $pregunta ) );
		if ( '' === $pregunta ) {
			return self::error( '403', 'Escriba una pregunta.' );
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
			return self::error(
				'401',
				'Ha alcanzado el límite de consultas por hora. Intente más tarde; mientras tanto puede explorar los datos del sitio.'
			);
		}

		// 3) Tope diario global: protege el presupuesto de la entidad.
		if ( ! self::consumir_cupo_diario( (int) $cfg['limite_dia'] ) ) {
			return self::error( '402', 'El asistente alcanzó su límite de consultas del día. Vuelva mañana.' );
		}

		$sistema = MAN_AI_Contexto::sistema( $municipio );
		$clave   = self::descifrar( self::clave_proveedor( $cfg, $cfg['proveedor'] ) );

		$res = self::llamar( $cfg['proveedor'], $clave, $cfg, $sistema, $pregunta );

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
	 * Construye el error con su código interno adjunto.
	 *
	 * El visitante ve un mensaje llano; el código y el detalle viajan en los
	 * datos y solo se muestran a quien administra el sitio.
	 *
	 * @param string $num      Número del catálogo (ver MAN_AI_Codigos).
	 * @param string $publico  Mensaje para el visitante.
	 * @param string $detalle  Motivo técnico.
	 * @param array  $extra    Datos adicionales para el registro.
	 * @return \WP_Error
	 */
	private static function error( $num, $publico, $detalle = '', $extra = array() ) {
		$ficha = MAN_AI_Codigos::ficha( $num );

		self::diag(
			$ficha['tipo'],
			'error',
			$ficha['codigo'] . ' · ' . $ficha['que'] . ( '' !== $detalle ? ' — ' . $detalle : '' ),
			array_merge( array( 'codigo_interno' => $ficha['codigo'], 'pista' => $ficha['haz'] ), $extra )
		);

		$datos = array(
			'codigo_interno' => $ficha['codigo'],
			'tipo'           => $ficha['tipo'],
			'detalle'        => '' !== $detalle ? $detalle : $ficha['que'],
			'pista'          => $ficha['haz'],
		);
		// El código HTTP del proveedor debe llegar también a la respuesta (no
		// solo al registro): es lo que distingue un 401 de un 429 en consola.
		if ( isset( $extra['codigo_proveedor'] ) ) {
			$datos['codigo_proveedor'] = (int) $extra['codigo_proveedor'];
		}

		return new \WP_Error( 'man_ai_' . $num, $publico, $datos );
	}

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

		// 3) Modelo: debe ser elegible y, si ya se detectaron los de la
		//    cuenta, figurar entre ellos (si no, el proveedor responde 404).
		$campo_modelo = $prov . '_modelo';
		$modelo       = isset( $cfg[ $campo_modelo ] ) ? $cfg[ $campo_modelo ] : '';
		$elegibles    = self::modelos_de( $prov );
		$detectados   = self::modelos_detectados( $prov );
		if ( ! isset( $elegibles[ $modelo ] ) ) {
			$add(
				'Modelo',
				'error',
				( $modelo ? $modelo : '(sin modelo)' ) . ' — retirado o fuera del catálogo; se está usando ' . self::modelo_vigente( $prov, $modelo ),
				'Elija un modelo del desplegable y guarde' . ( ! empty( $proveedores[ $prov ]['detectable'] ) ? ', o pulse «Detectar modelos» para ver los de su cuenta.' : '.' )
			);
		} elseif ( $detectados['fecha'] && ! isset( $detectados['modelos'][ $modelo ] ) ) {
			$add(
				'Modelo',
				'aviso',
				$modelo . ' — no figura entre los modelos que su cuenta informó el ' . gmdate( 'Y-m-d', $detectados['fecha'] ),
				'Si las consultas fallan con MAN-AI-203, elija uno de los detectados y guarde.'
			);
		} else {
			$add( 'Modelo', 'ok', $modelo . ( $detectados['fecha'] ? ' (disponible en su cuenta)' : '' ) );
		}

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
	 * Enruta la consulta al proveedor con el modelo vigente.
	 *
	 * @param string $slug     Proveedor.
	 * @param string $clave    Clave en claro.
	 * @param array  $cfg      Configuración.
	 * @param string $sistema  Instrucción de sistema.
	 * @param string $pregunta Pregunta.
	 * @return string|\WP_Error
	 */
	private static function llamar( $slug, $clave, $cfg, $sistema, $pregunta ) {
		$campo  = $slug . '_modelo';
		$modelo = self::modelo_vigente( $slug, isset( $cfg[ $campo ] ) ? $cfg[ $campo ] : '' );

		switch ( $slug ) {
			case 'openai':
				return self::llamar_openai( $clave, $modelo, $sistema, $pregunta );
			case 'gemini':
				return self::llamar_gemini( $clave, $modelo, $sistema, $pregunta );
			case 'claude':
			default:
				return self::llamar_claude( $clave, $modelo, $sistema, $pregunta );
		}
	}

	/**
	 * Claude (Anthropic) — POST /v1/messages.
	 *
	 * En los modelos actuales el razonamiento es adaptativo, siempre activo y
	 * cuenta dentro de max_tokens; se controla con el esfuerzo, que se baja a
	 * «low» porque aquí se piden respuestas cortas de divulgación.
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
			'max_tokens'    => self::MAX_TOKENS_RAZONADOR,
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
			return self::error( '303', 'No puedo responder a esa consulta. Pregúnteme sobre el clima y el ambiente en Nariño.' );
		}

		// Los bloques de razonamiento («thinking») preceden al texto: se lee
		// por tipo, no por posición.
		$texto = '';
		foreach ( ( isset( $r['content'] ) && is_array( $r['content'] ) ? $r['content'] : array() ) as $bloque ) {
			if ( isset( $bloque['type'], $bloque['text'] ) && 'text' === $bloque['type'] ) {
				$texto .= $bloque['text'];
			}
		}
		$cortada = isset( $r['stop_reason'] ) && 'max_tokens' === $r['stop_reason'];
		return self::texto_o_corte( $texto, $cortada, 'stop_reason=max_tokens' );
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
		$modelo = $modelo ? $modelo : 'gemini-3.8-flash';
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
			'generationConfig'  => self::generacion_gemini( $modelo ),
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

		// Una pregunta bloqueada por los filtros llega sin candidatos.
		if ( ! empty( $r['promptFeedback']['blockReason'] ) ) {
			return self::error( '303', 'No puedo responder a esa consulta. Pregúnteme sobre el clima y el ambiente en Nariño.', 'promptFeedback.blockReason=' . $r['promptFeedback']['blockReason'] );
		}

		$texto = '';
		foreach ( ( isset( $r['candidates'][0]['content']['parts'] ) ? $r['candidates'][0]['content']['parts'] : array() ) as $parte ) {
			// Las partes de razonamiento vienen marcadas con thought=true.
			if ( isset( $parte['text'] ) && empty( $parte['thought'] ) ) {
				$texto .= $parte['text'];
			}
		}

		$motivo = isset( $r['candidates'][0]['finishReason'] ) ? (string) $r['candidates'][0]['finishReason'] : '';
		if ( '' === trim( $texto ) && in_array( $motivo, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII', 'RECITATION' ), true ) ) {
			return self::error( '303', 'No puedo responder a esa consulta. Pregúnteme sobre el clima y el ambiente en Nariño.', 'finishReason=' . $motivo );
		}
		return self::texto_o_corte( $texto, 'MAX_TOKENS' === $motivo, 'finishReason=MAX_TOKENS' );
	}

	/**
	 * generationConfig de Gemini según la generación del modelo.
	 *
	 * Gemini 3 y 4 razonan siempre y se regulan con thinkingLevel; la serie
	 * 2.5 usa thinkingBudget (Pro no admite 0). Mandar el campo equivocado, o
	 * los dos a la vez, es un HTTP 400.
	 *
	 * @param string $modelo Modelo.
	 * @return array
	 */
	private static function generacion_gemini( $modelo ) {
		$gen = array( 'maxOutputTokens' => self::MAX_TOKENS_RAZONADOR );

		if ( preg_match( '/^gemini-[34][.-]/', $modelo ) ) {
			$gen['thinkingConfig'] = array( 'thinkingLevel' => 'low' );
		} elseif ( 0 === strpos( $modelo, 'gemini-2.5-pro' ) ) {
			$gen['thinkingConfig'] = array( 'thinkingBudget' => 128 );
		} elseif ( 0 === strpos( $modelo, 'gemini-2.5-' ) ) {
			$gen['thinkingConfig'] = array( 'thinkingBudget' => 0 );
		}
		return $gen;
	}

	/**
	 * Texto final, distinguiendo una respuesta vacía de una que se cortó.
	 *
	 * Si el modelo agotó el tope razonando y no llegó a escribir, se reporta
	 * como MAN-AI-304; si alcanzó a escribir algo, se entrega lo que hay.
	 *
	 * @param string $texto   Texto acumulado.
	 * @param bool   $cortada El proveedor informó que llegó al tope.
	 * @param string $motivo  Motivo técnico para el registro.
	 * @return string|\WP_Error
	 */
	private static function texto_o_corte( $texto, $cortada, $motivo ) {
		if ( $cortada && '' === trim( wp_strip_all_tags( (string) $texto ) ) ) {
			return self::error( '304', 'El asistente no pudo completar la respuesta. Intente con una pregunta más concreta.', $motivo );
		}
		$texto = self::validar_texto( $texto );
		if ( $cortada && ! is_wp_error( $texto ) ) {
			$texto .= '…';
		}
		return $texto;
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
		return self::procesar_http( $resp, $url );
	}

	/**
	 * GET con el mismo manejo de error (listado de modelos).
	 *
	 * @param string $url       Endpoint.
	 * @param array  $cabeceras Cabeceras de autenticación.
	 * @return array|\WP_Error Respuesta decodificada.
	 */
	private static function http_get( $url, $cabeceras ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'headers'    => $cabeceras,
				'user-agent' => 'MonitorAmbientalNarino/' . MAN_VERSION,
			)
		);
		return self::procesar_http( $resp, $url );
	}

	/**
	 * Traduce la respuesta HTTP del proveedor a JSON o a un error con código.
	 *
	 * @param array|\WP_Error $resp Respuesta de wp_remote_*.
	 * @param string          $url  Endpoint (para el registro).
	 * @return array|\WP_Error
	 */
	private static function procesar_http( $resp, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( is_wp_error( $resp ) ) {
			// Fallo de red/DNS/TLS: el hosting suele bloquear la salida HTTP.
			$msg = $resp->get_error_message();
			// Un tiempo de espera agotado no es lo mismo que una salida bloqueada.
			$num = ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) ? '102' : '101';
			return self::error(
				$num,
				'El asistente no está disponible en este momento. Vuelva a intentarlo en unos minutos.',
				$msg,
				array( 'host' => $host )
			);
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		$bruto  = wp_remote_retrieve_body( $resp );
		$json   = json_decode( $bruto, true );

		if ( $codigo < 200 || $codigo >= 300 ) {
			$detalle = self::detalle_error( $json, $codigo );
			// Desde mayo de 2026 Google rechaza las claves estándar sin
			// restricción y bloquea las que llevan tiempo inactivas: el 400/403
			// resultante se confunde con «clave mal copiada».
			if ( 'generativelanguage.googleapis.com' === $host && in_array( $codigo, array( 400, 401, 403 ), true )
				&& preg_match( '/api[ _-]?key|unrestricted|blocked|permission/i', $detalle ) ) {
				$detalle .= ' · Pista: Gemini ya no acepta claves estándar sin restricción ni claves inactivas. Cree una clave nueva en aistudio.google.com/apikey o restrinja la suya a «Generative Language API».';
			}
			return self::error(
				MAN_AI_Codigos::desde_http( $codigo ),
				// Mensaje llano: el texto del proveedor puede describir la cuenta
				// o el modelo, y no tiene por qué llegar a un visitante.
				'El asistente no está disponible en este momento. Vuelva a intentarlo en unos minutos.',
				'HTTP ' . $codigo . ' — ' . $detalle,
				array( 'host' => $host, 'codigo' => $codigo, 'cuerpo' => $bruto, 'codigo_proveedor' => $codigo )
			);
		}

		if ( ! is_array( $json ) ) {
			return self::error(
				'301',
				'El asistente no está disponible en este momento. Vuelva a intentarlo en unos minutos.',
				'Respuesta no JSON: ' . mb_substr( (string) $bruto, 0, 200 ),
				array( 'host' => $host, 'codigo' => $codigo, 'cuerpo' => $bruto )
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
	 * Valida y recorta el texto devuelto por el modelo.
	 *
	 * @param string $texto Texto crudo.
	 * @return string|\WP_Error
	 */
	private static function validar_texto( $texto ) {
		$texto = trim( wp_strip_all_tags( (string) $texto ) );
		if ( '' === $texto ) {
			return self::error( '302', 'El asistente no pudo formular una respuesta. Intente reformular la pregunta.' );
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

		$res = self::llamar( $slug, $clave, $cfg, $sistema, $pregunta );

		if ( is_wp_error( $res ) ) {
			$datos = $res->get_error_data();
			// Aquí sí se muestra el motivo real: quien lo lee administra el sitio.
			$detalle = ( is_array( $datos ) && ! empty( $datos['detalle'] ) )
				? $datos['detalle']
				: $res->get_error_message();
			return array( 'ok' => false, 'mensaje' => $detalle );
		}
		$modelo = self::modelo_vigente( $slug, isset( $cfg[ $slug . '_modelo' ] ) ? $cfg[ $slug . '_modelo' ] : '' );
		return array( 'ok' => true, 'mensaje' => 'Responde correctamente con ' . $modelo . ': «' . mb_substr( $res, 0, 180 ) . '…»' );
	}

	/**
	 * «Detectar modelos»: pregunta al proveedor qué modelos admite la clave.
	 *
	 * Verifica la clave sin gastar consultas (el listado no consume tokens) y
	 * deja un catálogo vivo: un modelo nuevo se puede elegir sin actualizar el
	 * plugin, y uno retirado se nota en el panel antes de que un visitante
	 * reciba el error.
	 *
	 * @param string $slug Proveedor (claude|gemini).
	 * @return array {ok, aviso, mensaje, modelos}
	 */
	public static function detectar_modelos( $slug ) {
		$proveedores = self::proveedores();
		if ( empty( $proveedores[ $slug ]['detectable'] ) ) {
			return array( 'ok' => false, 'aviso' => false, 'mensaje' => 'Este proveedor no admite la detección de modelos.', 'modelos' => array() );
		}

		$cfg   = self::config();
		$clave = self::descifrar( self::clave_proveedor( $cfg, $slug ) );
		if ( '' === $clave ) {
			return array( 'ok' => false, 'aviso' => false, 'mensaje' => 'No hay clave guardada para este proveedor (o no se pudo descifrar). Pegue la clave y guarde antes de detectar.', 'modelos' => array() );
		}

		$hallados = 'gemini' === $slug ? self::listar_gemini( $clave ) : self::listar_claude( $clave );
		if ( is_wp_error( $hallados ) ) {
			$datos = $hallados->get_error_data();
			$cod   = ( is_array( $datos ) && ! empty( $datos['codigo_interno'] ) ) ? $datos['codigo_interno'] . ' · ' : '';
			$det   = ( is_array( $datos ) && ! empty( $datos['detalle'] ) ) ? $datos['detalle'] : $hallados->get_error_message();
			return array( 'ok' => false, 'aviso' => false, 'mensaje' => 'La clave no fue reconocida: ' . $cod . $det, 'modelos' => array() );
		}
		if ( empty( $hallados ) ) {
			return array( 'ok' => false, 'aviso' => false, 'mensaje' => 'La clave es válida, pero la cuenta no informó ningún modelo de texto.', 'modelos' => array() );
		}

		$todo          = get_option( self::OPCION_MODELOS, array() );
		$todo          = is_array( $todo ) ? $todo : array();
		$todo[ $slug ] = array( 'fecha' => time(), 'modelos' => $hallados );
		update_option( self::OPCION_MODELOS, $todo );

		$actual     = isset( $cfg[ $slug . '_modelo' ] ) ? (string) $cfg[ $slug . '_modelo' ] : '';
		$disponible = isset( $hallados[ $actual ] );
		self::diag( 'proveedor', 'ok', 'Clave reconocida por ' . $slug . ': ' . count( $hallados ) . ' modelos de texto' );

		return array(
			'ok'      => true,
			'aviso'   => ! $disponible,
			'mensaje' => 'Clave reconocida: la cuenta tiene ' . count( $hallados ) . ' modelos de texto. '
				. ( $disponible
					? 'El modelo elegido (' . $actual . ') está disponible.'
					: 'El modelo elegido (' . $actual . ') NO está entre ellos: elija uno de la lista y guarde.' ),
			'modelos' => self::modelos_de( $slug ),
		);
	}

	/**
	 * Modelos de la cuenta de Anthropic — GET /v1/models (paginado).
	 *
	 * @param string $clave Clave en claro.
	 * @return array<string,string>|\WP_Error id => nombre.
	 */
	private static function listar_claude( $clave ) {
		$modelos = array();
		$despues = '';
		for ( $pagina = 0; $pagina < 5; $pagina++ ) {
			$r = self::http_get(
				'https://api.anthropic.com/v1/models?limit=100' . ( '' !== $despues ? '&after_id=' . rawurlencode( $despues ) : '' ),
				array(
					'x-api-key'         => $clave,
					'anthropic-version' => '2023-06-01',
				)
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( ( isset( $r['data'] ) && is_array( $r['data'] ) ? $r['data'] : array() ) as $m ) {
				$id = isset( $m['id'] ) ? (string) $m['id'] : '';
				if ( self::id_modelo_valido( $id ) ) {
					$modelos[ $id ] = ! empty( $m['display_name'] ) ? sanitize_text_field( $m['display_name'] ) : $id;
				}
			}
			if ( empty( $r['has_more'] ) || empty( $r['last_id'] ) ) {
				break;
			}
			$despues = (string) $r['last_id'];
		}
		return $modelos;
	}

	/**
	 * Modelos de texto de la cuenta de Google — GET /v1beta/models (paginado).
	 *
	 * El listado trae también voz, imagen, tiempo real y embeddings: solo
	 * sirven los que admiten generateContent y producen texto.
	 *
	 * @param string $clave Clave en claro.
	 * @return array<string,string>|\WP_Error id => nombre.
	 */
	private static function listar_gemini( $clave ) {
		$modelos = array();
		$token   = '';
		for ( $pagina = 0; $pagina < 5; $pagina++ ) {
			// La clave va en cabecera también aquí, nunca en la URL.
			$r = self::http_get(
				'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200' . ( '' !== $token ? '&pageToken=' . rawurlencode( $token ) : '' ),
				array( 'x-goog-api-key' => $clave )
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			foreach ( ( isset( $r['models'] ) && is_array( $r['models'] ) ? $r['models'] : array() ) as $m ) {
				$metodos = ( isset( $m['supportedGenerationMethods'] ) && is_array( $m['supportedGenerationMethods'] ) ) ? $m['supportedGenerationMethods'] : array();
				$id      = isset( $m['name'] ) ? preg_replace( '#^models/#', '', (string) $m['name'] ) : '';
				if ( ! in_array( 'generateContent', $metodos, true )
					|| 0 !== strpos( $id, 'gemini-' )
					|| preg_match( '/tts|image|live|embedding|transcribe|audio|robotics|computer-use|nano-banana|translate|omni/i', $id )
					|| ! self::id_modelo_valido( $id ) ) {
					continue;
				}
				$modelos[ $id ] = ! empty( $m['displayName'] ) ? sanitize_text_field( $m['displayName'] ) : $id;
			}
			$token = isset( $r['nextPageToken'] ) ? (string) $r['nextPageToken'] : '';
			if ( '' === $token ) {
				break;
			}
		}
		return $modelos;
	}

	/**
	 * Un ID de modelo que viene de fuera se guarda, se pinta y va en una URL:
	 * solo se aceptan letras, cifras, puntos y guiones.
	 *
	 * @param string $id Identificador.
	 * @return bool
	 */
	private static function id_modelo_valido( $id ) {
		return (bool) preg_match( '/^[a-z0-9][a-z0-9.\-]{1,79}$/i', (string) $id );
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
