<?php
/**
 * Tests CLI del módulo de IA — sin red y sin WordPress.
 *
 * Valida el cuerpo que se envía a cada proveedor (Claude, OpenAI, Gemini), la
 * lectura de su respuesta, la caché de respuestas, los topes de gasto y los
 * interruptores de activación y visibilidad. Ejecutar con:
 *   php tests/test-ai.php
 *
 * @package MonitorAmbientalNarino
 */
namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'MAN_VERSION', '1.41.0' );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	$GLOBALS['__c'] = array();
	$GLOBALS['__opt'] = array();
	$GLOBALS['__post'] = array();

	function wp_strip_all_tags( $s, $b = false ) { return trim( strip_tags( (string) $s ) ); }
	function remove_accents( $s ) { return strtr( $s, array( 'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n' ) ); }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
	function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); }
	function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
	function wp_remote_get( $url, $args = array() ) { return isset( $GLOBALS['__get'] ) ? $GLOBALS['__get'] : array( 'code' => 200, 'body' => '' ); }
	function delete_option( $k ) { unset( $GLOBALS['__opt'][ $k ] ); return true; }
	function current_time( $t, $g = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
	function get_option( $k, $d = false ) { return isset( $GLOBALS['__opt'][ $k ] ) ? $GLOBALS['__opt'][ $k ] : $d; }
	function update_option( $k, $v ) { $GLOBALS['__opt'][ $k ] = $v; return true; }
	function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
	function is_wp_error( $t ) { return $t instanceof \WP_Error; }
	function wp_remote_post( $url, $args ) {
		$GLOBALS['__post'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['__resp'];
	}
	function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
	function wp_remote_retrieve_body( $r ) { return $r['body']; }

	class WP_Error {
		public $code; public $msg; public $data;
		public function __construct( $c = '', $m = '', $d = array() ) { $this->code = $c; $this->msg = $m; $this->data = $d; }
		public function get_error_message() { return $this->msg; }
		public function get_error_data() { return $this->data; }
	}
}
namespace GobernacionNarino\MonitorAmbiental {
	class MAN_Cache {
		public static function set( $k, $v, $t = 0, $g = '' ) { $GLOBALS['__c'][ $k ] = $v; return true; }
		public static function get( $k ) { return isset( $GLOBALS['__c'][ $k ] ) ? $GLOBALS['__c'][ $k ] : null; }
	}
	class MAN_Security {
		public static $permitir = true;
		public static function rate_limit( $c, $m = 60, $v = 60 ) { return self::$permitir; }
		public static function cifrar( $t ) { return base64_encode( 'C:' . $t ); }
		public static function descifrar( $p ) {
			$raw = base64_decode( $p, true );
			return ( $raw && 0 === strpos( $raw, 'C:' ) ) ? substr( $raw, 2 ) : '';
		}
		public static function sanitizar_divipola( $v ) { return preg_match( '/^\d{5}$/', (string) $v ) ? $v : 'departamento'; }
		public static function mes_actual() { return '2026-10'; }
	}
	class MAN_Sync { public static function auditar( ...$a ) {} }
	class MAN_AI_Contexto { public static function sistema( $m = '' ) { return 'CONTEXTO DE PRUEBA para ' . ( $m ? $m : 'departamento' ); } }
	require __DIR__ . '/../includes/ai/class-man-ai-codigos.php';
	require __DIR__ . '/../includes/ai/class-man-ai.php';
}
namespace {
	use GobernacionNarino\MonitorAmbiental\MAN_AI;
	use GobernacionNarino\MonitorAmbiental\MAN_Security;

	$fallos = 0;
	function chk( $c, $m ) { global $fallos; echo ( $c ? "  ok  " : "FAIL  " ) . "$m\n"; if ( ! $c ) { $fallos++; } }
	function ultimo() { return end( $GLOBALS['__post'] ); }

	// Config base: activo, con clave para los tres proveedores.
	update_option( MAN_AI::OPCION, array_merge( MAN_AI::config_por_defecto(), array(
		'activo' => true, 'visible' => true,
		'claude_clave' => MAN_Security::cifrar( 'sk-ant-prueba' ),
		'openai_clave' => MAN_Security::cifrar( 'sk-openai-prueba' ),
		'gemini_clave' => MAN_Security::cifrar( 'AIza-prueba' ),
	) ) );

	echo "--- 1. Claude ---\n";
	$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array(
		'stop_reason' => 'end_turn',
		'content' => array( array( 'type' => 'thinking', 'thinking' => '' ), array( 'type' => 'text', 'text' => 'Estamos en fase El Niño.' ) ),
	) ) );
	$r = MAN_AI::preguntar( '¿Qué fase hay?' );
	chk( ! is_wp_error( $r ) && 'Estamos en fase El Niño.' === $r['respuesta'], 'lee el bloque de texto, ignorando el de razonamiento' );
	$u = ultimo();
	chk( false !== strpos( $u['url'], 'api.anthropic.com/v1/messages' ), 'endpoint correcto' );
	chk( isset( $u['args']['headers']['x-api-key'] ) && 'sk-ant-prueba' === $u['args']['headers']['x-api-key'], 'clave descifrada en la cabecera' );
	chk( isset( $u['args']['headers']['anthropic-version'] ), 'envía anthropic-version' );
	$b = json_decode( $u['args']['body'], true );
	chk( 'claude-opus-5-5' === $b['model'], 'modelo por defecto: ' . $b['model'] );
	chk( ! isset( $b['thinking'] ), 'NO envía thinking (en estos modelos es adaptativo y un disabled sería rechazado)' );
	chk( ! isset( $b['temperature'] ), 'NO envía temperature (rechazada por estos modelos)' );
	chk( isset( $b['output_config']['effort'] ), 'controla el gasto con effort: ' . $b['output_config']['effort'] );
	chk( 'user' === $b['messages'][0]['role'] && 1 === count( $b['messages'] ), 'la pregunta va como turno de usuario, no en el sistema' );

	echo "\n--- 2. Rechazo del proveedor (HTTP 200 con stop_reason=refusal) ---\n";
	$GLOBALS['__c'] = array(); // limpiar caché de respuestas
	$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array( 'stop_reason' => 'refusal', 'content' => array() ) ) );
	$r = MAN_AI::preguntar( 'Algo que será rechazado' );
	chk( is_wp_error( $r ) && 'man_ai_303' === $r->code, 'se distingue del fallo técnico y da mensaje al ciudadano' );

	echo "\n--- 3. OpenAI ---\n";
	$GLOBALS['__c'] = array();
	$cfg = get_option( MAN_AI::OPCION ); $cfg['proveedor'] = 'openai'; update_option( MAN_AI::OPCION, $cfg );
	$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array(
		'choices' => array( array( 'message' => array( 'content' => 'Respuesta de OpenAI.' ) ) ),
	) ) );
	$r = MAN_AI::preguntar( '¿Y ahora?' );
	chk( ! is_wp_error( $r ) && 'Respuesta de OpenAI.' === $r['respuesta'], 'lee choices[0].message.content' );
	$u = ultimo();
	chk( false !== strpos( $u['url'], 'api.openai.com' ), 'endpoint correcto' );
	chk( 'Bearer sk-openai-prueba' === $u['args']['headers']['Authorization'], 'autenticación Bearer' );
	$b = json_decode( $u['args']['body'], true );
	chk( 'system' === $b['messages'][0]['role'] && 'user' === $b['messages'][1]['role'], 'sistema y usuario separados' );

	echo "\n--- 4. Gemini ---\n";
	$GLOBALS['__c'] = array();
	$cfg = get_option( MAN_AI::OPCION ); $cfg['proveedor'] = 'gemini'; update_option( MAN_AI::OPCION, $cfg );
	$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array(
		'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'Respuesta de Gemini.' ) ) ) ) ),
	) ) );
	$r = MAN_AI::preguntar( '¿Y Gemini?' );
	chk( ! is_wp_error( $r ) && 'Respuesta de Gemini.' === $r['respuesta'], 'lee candidates[0].content.parts' );
	$u = ultimo();
	chk( false === strpos( $u['url'], 'AIza-prueba' ), 'la clave NO viaja en la URL (quedaría en logs de proxy)' );
	chk( isset( $u['args']['headers']['x-goog-api-key'] ), 'la clave va en cabecera' );
	$b = json_decode( $u['args']['body'], true );
	chk( isset( $b['systemInstruction']['parts'][0]['text'] ), 'usa systemInstruction' );

	echo "\n--- 5. Caché de respuestas (ahorro de costo) ---\n";
	$GLOBALS['__post'] = array();
	$antes = count( $GLOBALS['__post'] );
	MAN_AI::preguntar( '¿Y Gemini?' ); // misma pregunta
	chk( 0 === count( $GLOBALS['__post'] ), 'una pregunta repetida no vuelve a llamar al proveedor' );
	$GLOBALS['__post'] = array();
	MAN_AI::preguntar( '  ¿¿Y   GEMINI??  ' ); // variante trivial
	chk( 0 === count( $GLOBALS['__post'] ), 'tildes, mayúsculas y espacios comparten caché' );

	echo "\n--- 6. Defensas ---\n";
	MAN_Security::$permitir = false;
	$GLOBALS['__c'] = array();
	$r = MAN_AI::preguntar( 'Pregunta nueva sin caché' );
	chk( is_wp_error( $r ) && 'man_ai_401' === $r->code, 'el cupo por IP corta antes de gastar' );
	MAN_Security::$permitir = true;

	$cfg = get_option( MAN_AI::OPCION ); $cfg['limite_dia'] = 1; update_option( MAN_AI::OPCION, $cfg );
	$GLOBALS['__c'] = array();
	$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'ok' ) ) ) ) ) ) ) );
	MAN_AI::preguntar( 'primera del dia' );
	$r = MAN_AI::preguntar( 'segunda del dia' );
	chk( is_wp_error( $r ) && 'man_ai_402' === $r->code, 'el tope diario protege el presupuesto' );

	echo "\n--- 7. Estado público ---\n";
	$cfg = get_option( MAN_AI::OPCION ); $cfg['limite_dia'] = 500; update_option( MAN_AI::OPCION, $cfg );
	$e = MAN_AI::estado();
	chk( ! isset( $e['claude_clave'], $e['voz_clave'], $e['proveedor'] ), 'el estado público NO expone claves ni proveedor' );
	chk( isset( $e['activo'], $e['visible'], $e['voz'] ), 'expone solo capacidades' );

	echo "\n--- 8. Apagado y oculto ---\n";
	$cfg = get_option( MAN_AI::OPCION ); $cfg['activo'] = false; update_option( MAN_AI::OPCION, $cfg );
	chk( ! MAN_AI::activo() && ! MAN_AI::visible(), 'desactivar apaga también la visibilidad' );
	$GLOBALS['__c'] = array();
	$r = MAN_AI::preguntar( 'cualquier cosa' );
	chk( is_wp_error( $r ) && 'man_ai_001' === $r->code, 'apagado, no responde' );

	$cfg['activo'] = true; $cfg['visible'] = false; update_option( MAN_AI::OPCION, $cfg );
	chk( MAN_AI::activo() && ! MAN_AI::visible(), 'activo pero oculto: se puede probar sin publicarlo' );

	$cfg['visible'] = true; $cfg['claude_clave'] = ''; $cfg['proveedor'] = 'claude'; update_option( MAN_AI::OPCION, $cfg );
	chk( ! MAN_AI::activo(), 'sin clave del proveedor activo, el módulo no se considera activo' );

	echo "\n--- 9. Diagnóstico: el motivo real queda registrado ---\n";
	$GLOBALS['__opt'][ MAN_AI::OPCION_DIAG ] = array();
	$cfg = get_option( MAN_AI::OPCION );
	$cfg['activo'] = true; $cfg['visible'] = true; $cfg['proveedor'] = 'claude';
	$cfg['claude_clave'] = MAN_Security::cifrar( 'sk-ant-invalida' );
	update_option( MAN_AI::OPCION, $cfg );
	$GLOBALS['__c'] = array();

	// Clave rechazada: el caso más común y el que antes se veía como «HTTP 502».
	$GLOBALS['__resp'] = array( 'code' => 401, 'body' => json_encode( array(
		'type' => 'error',
		'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ),
	) ) );
	$r = MAN_AI::preguntar( 'pregunta con clave invalida' );

	chk( is_wp_error( $r ), 'devuelve error' );
	chk( false === strpos( $r->get_error_message(), 'invalid x-api-key' ), 'el mensaje PÚBLICO no filtra el detalle del proveedor' );
	$datos = $r->get_error_data();
	chk( isset( $datos['detalle'] ) && false !== strpos( $datos['detalle'], 'invalid x-api-key' ), 'el detalle real viaja aparte, para administradores' );
	chk( isset( $datos['codigo_proveedor'] ) && 401 === $datos['codigo_proveedor'], 'conserva el código HTTP del proveedor' );
	chk( isset( $datos['codigo_interno'] ) && 'MAN-AI-201' === $datos['codigo_interno'], 'un 401 del proveedor se traduce a MAN-AI-201' );
	chk( 'man_ai_201' === $r->code, 'el código del error lleva el número interno (lo lee la consola)' );

	$log = MAN_AI::diagnostico();
	chk( ! empty( $log ), 'el fallo queda en el registro de diagnóstico' );
	chk( isset( $log[0]['mensaje'] ) && false !== strpos( $log[0]['mensaje'], 'invalid x-api-key' ), 'el registro guarda el motivo del proveedor' );
	chk( isset( $log[0]['extra']['cuerpo'] ) && false !== strpos( $log[0]['extra']['cuerpo'], 'authentication_error' ), 'guarda la respuesta cruda' );
	chk( isset( $log[0]['extra']['pista'] ) && '' !== $log[0]['extra']['pista'], 'incluye una pista accionable: ' . $log[0]['extra']['pista'] );
	$serializado = json_encode( $log );
	chk( false === strpos( $serializado, 'sk-ant-invalida' ), 'la clave NUNCA entra al registro' );

	echo "\n--- 10. Sin salida a internet (hosting que bloquea) ---\n";
	$GLOBALS['__opt'][ MAN_AI::OPCION_DIAG ] = array();
	$GLOBALS['__c'] = array();
	$GLOBALS['__resp'] = new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
	$r = MAN_AI::preguntar( 'otra pregunta distinta' );
	chk( is_wp_error( $r ) && 'man_ai_101' === $r->code, 'se distingue de un fallo del proveedor' );
	$log = MAN_AI::diagnostico();
	chk( ! empty( $log ) && 'red' === $log[0]['paso'], 'registrado como fallo de red' );
	chk( false !== strpos( $log[0]['mensaje'], 'MAN-AI-101' ), 'el registro lleva el código interno MAN-AI-101' );
	chk( false !== strpos( $log[0]['extra']['pista'], 'hosting' ), 'la pista apunta al bloqueo de salida del hosting' );

	echo "\n--- 11. Revisión de la configuración ---\n";
	$filas = MAN_AI::revisar_configuracion();
	chk( ! empty( $filas ), 'devuelve comprobaciones: ' . count( $filas ) );
	$pasos = array();
	foreach ( $filas as $f ) { $pasos[] = $f['paso']; }
	chk( in_array( 'Clave del proveedor', $pasos, true ), 'comprueba la clave' );
	chk( in_array( 'Modelo', $pasos, true ), 'comprueba el modelo' );
	chk( in_array( 'Salida a internet', $pasos, true ), 'comprueba la salida a internet' );

	// Clave guardada pero ilegible: el fallo silencioso que ya corregimos antes.
	$cfg = get_option( MAN_AI::OPCION );
	$cfg['claude_clave'] = 'esto-no-es-un-paquete-cifrado-valido';
	update_option( MAN_AI::OPCION, $cfg );
	$filas = MAN_AI::revisar_configuracion();
	$encontrado = false;
	foreach ( $filas as $f ) {
		if ( 'Clave del proveedor' === $f['paso'] && 'error' === $f['estado'] && false !== strpos( $f['mensaje'], 'descifrar' ) ) {
			$encontrado = true;
		}
	}
	chk( $encontrado, 'detecta una clave guardada que ya no se puede descifrar' );

	echo "\n--- 12. Catálogo de códigos internos ---\n";
	$cat = \GobernacionNarino\MonitorAmbiental\MAN_AI_Codigos::catalogo();
	chk( count( $cat ) >= 20, 'catálogo con ' . count( $cat ) . ' códigos' );
	$completos = true;
	foreach ( $cat as $num => $f ) {
		if ( ! preg_match( '/^\d{3}$/', (string) $num ) || empty( $f['tipo'] ) || empty( $f['que'] ) || ! isset( $f['haz'] ) ) {
			$completos = false;
		}
	}
	chk( $completos, 'todos tienen número de 3 cifras, tipo, qué pasó y qué hacer' );

	$http = array( 401 => '201', 403 => '202', 404 => '203', 429 => '204', 400 => '205', 500 => '206', 529 => '206' );
	$bien = true;
	foreach ( $http as $h => $esperado ) {
		if ( \GobernacionNarino\MonitorAmbiental\MAN_AI_Codigos::desde_http( $h ) !== $esperado ) { $bien = false; }
	}
	chk( $bien, 'cada código HTTP del proveedor se traduce a su código interno' );

	// Todo código que lance el módulo debe existir en el catálogo.
	$fuente = file_get_contents( __DIR__ . '/../includes/ai/class-man-ai.php' )
		. file_get_contents( __DIR__ . '/../includes/ai/class-man-ai-voz.php' );
	preg_match_all( "/self::error\(\s*'(\d{3})'/", $fuente, $m );
	$huerfanos = array_diff( array_unique( $m[1] ), array_keys( $cat ) );
	chk( empty( $huerfanos ), 'ningún código lanzado falta en el catálogo' . ( $huerfanos ? ': ' . implode( ',', $huerfanos ) : '' ) );

	// Los códigos de navegador los emite el JS: deben existir también.
	$js = file_get_contents( __DIR__ . '/../assets/js/asistente.js' );
	preg_match_all( "/reportar\('(\d{3})'/", $js, $mj );
	$huerfanos_js = array_diff( array_unique( $mj[1] ), array_keys( $cat ) );
	chk( empty( $huerfanos_js ), 'ningún código del navegador falta en el catálogo' . ( $huerfanos_js ? ': ' . implode( ',', $huerfanos_js ) : '' ) );

	echo "\n--- 13. Anfitrión y sugerencias ---\n";
	$cfg = get_option( MAN_AI::OPCION );
	$cfg['anfitrion'] = ''; $cfg['saludo'] = ''; $cfg['sugerencias'] = '';
	update_option( MAN_AI::OPCION, $cfg );
	chk( 'Tu anfitrión' === MAN_AI::anfitrion(), 'sin nombre, usa «Tu anfitrión»' );
	chk( '' !== MAN_AI::saludo(), 'sin saludo, usa uno por defecto' );
	chk( count( MAN_AI::sugerencias() ) >= 3, 'sin sugerencias, ofrece las de ejemplo' );

	$cfg['anfitrion']   = 'Ñaño';
	$cfg['sugerencias'] = "¿Qué es el ONI?\n\n  ¿Cómo está Tumaco?  \r\n" . str_repeat( 'x', 200 );
	update_option( MAN_AI::OPCION, $cfg );
	$sug = MAN_AI::sugerencias();
	chk( 'Ñaño' === MAN_AI::anfitrion(), 'respeta el nombre configurado' );
	chk( 3 === count( $sug ), 'una por línea, ignorando vacías y con saltos Windows: ' . count( $sug ) );
	chk( '¿Cómo está Tumaco?' === $sug[1], 'recorta espacios' );
	chk( mb_strlen( $sug[2] ) <= 120, 'acota la longitud de cada sugerencia' );

	$cfg['sugerencias'] = implode( "\n", array_fill( 0, 20, 'pregunta' ) );
	update_option( MAN_AI::OPCION, $cfg );
	chk( count( MAN_AI::sugerencias() ) <= 8, 'máximo 8 sugerencias' );

	$e = MAN_AI::estado();
	chk( isset( $e['anfitrion'], $e['saludo'], $e['sugerencias'] ), 'el estado público incluye anfitrión, saludo y sugerencias' );

	echo "\n" . ( $fallos ? "$fallos FALLO(S)\n" : "TODO OK\n" );
	exit( $fallos ? 1 : 0 );
}
