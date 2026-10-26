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
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['__gets'][] = array( 'url' => $url, 'args' => $args );
		if ( ! empty( $GLOBALS['__get_cola'] ) ) { return array_shift( $GLOBALS['__get_cola'] ); }
		return isset( $GLOBALS['__get'] ) ? $GLOBALS['__get'] : array( 'code' => 200, 'body' => '' );
	}
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
	class MAN_Sync_Firms { const DIAS_MAX = 5; }
	require __DIR__ . '/../includes/class-man-activator.php';
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

	// Helpers para las secciones de modelos.
	function cuerpo_ultimo() { $u = ultimo(); return json_decode( $u['args']['body'], true ); }
	function usar( $prov, $modelo = null ) {
		$cfg = get_option( MAN_AI::OPCION );
		$cfg['proveedor'] = $prov;
		if ( null !== $modelo ) { $cfg[ $prov . '_modelo' ] = $modelo; }
		update_option( MAN_AI::OPCION, $cfg );
		$GLOBALS['__c'] = array(); // sin caché ni contador
	}
	function json_resp( $datos, $code = 200 ) { return array( 'code' => $code, 'body' => json_encode( $datos ) ); }

	echo "\n--- 14. Catálogo de modelos vigente ---\n";
	$prov = MAN_AI::proveedores();
	$todos = array_merge( array_keys( $prov['claude']['modelos'] ), array_keys( $prov['gemini']['modelos'] ) );
	chk( ! preg_grep( '/^gemini-(1\.|2\.0)/', $todos ), 'ningún Gemini apagado (1.5, 2.0) en el catálogo' );
	chk( 'gemini-3.8-flash' === key( $prov['gemini']['modelos'] ), 'Gemini recomendado: gemini-3.8-flash' );
	chk( 'claude-opus-5-5' === key( $prov['claude']['modelos'] ), 'Claude recomendado: claude-opus-5-5' );
	$def = MAN_AI::config_por_defecto();
	chk( isset( $prov['gemini']['modelos'][ $def['gemini_modelo'] ], $prov['claude']['modelos'][ $def['claude_modelo'] ] ), 'los modelos por defecto están en el catálogo' );

	echo "\n--- 15. Claude: el razonamiento cabe en el tope ---\n";
	usar( 'claude', 'claude-opus-5-5' );
	$GLOBALS['__resp'] = json_resp( array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => 'Ok.' ) ) ) );
	MAN_AI::preguntar( 'tope claude' );
	$b = cuerpo_ultimo();
	chk( $b['max_tokens'] >= 2048, 'max_tokens deja sitio al razonamiento: ' . $b['max_tokens'] );
	chk( 'low' === $b['output_config']['effort'], 'esfuerzo bajo para respuestas cortas' );

	usar( 'claude' );
	$GLOBALS['__resp'] = json_resp( array( 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'thinking', 'thinking' => '' ) ) ) );
	$r = MAN_AI::preguntar( 'se agotó pensando' );
	chk( is_wp_error( $r ) && 'man_ai_304' === $r->code, 'tope agotado sin texto → MAN-AI-304 (no un 302 genérico)' );

	usar( 'claude' );
	$GLOBALS['__resp'] = json_resp( array( 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'text', 'text' => 'El ONI está en' ) ) ) );
	$r = MAN_AI::preguntar( 'se cortó escribiendo' );
	chk( ! is_wp_error( $r ) && 'El ONI está en…' === $r['respuesta'], 'si alcanzó a escribir, entrega lo escrito marcado como cortado' );

	echo "\n--- 16. Gemini 3/4: thinkingLevel; 2.5: thinkingBudget ---\n";
	usar( 'gemini', 'gemini-3.8-flash' );
	$GLOBALS['__resp'] = json_resp( array( 'candidates' => array( array(
		'finishReason' => 'STOP',
		'content'      => array( 'parts' => array(
			array( 'text' => 'pensando en voz alta', 'thought' => true ),
			array( 'text' => 'Respuesta visible.' ),
		) ),
	) ) ) );
	$r = MAN_AI::preguntar( 'gemini 3.8' );
	$u = ultimo();
	$b = cuerpo_ultimo();
	chk( false !== strpos( $u['url'], '/models/gemini-3.8-flash:generateContent' ), 'llama a gemini-3.8-flash' );
	chk( isset( $b['generationConfig']['thinkingConfig']['thinkingLevel'] ) && 'low' === $b['generationConfig']['thinkingConfig']['thinkingLevel'], 'Gemini 3.x: thinkingLevel=low' );
	chk( ! isset( $b['generationConfig']['thinkingConfig']['thinkingBudget'] ), 'sin thinkingBudget a la vez (sería HTTP 400)' );
	chk( $b['generationConfig']['maxOutputTokens'] >= 2048, 'maxOutputTokens deja sitio al razonamiento: ' . $b['generationConfig']['maxOutputTokens'] );
	chk( ! is_wp_error( $r ) && 'Respuesta visible.' === $r['respuesta'], 'descarta las partes de razonamiento (thought=true)' );

	usar( 'gemini', 'gemini-4-argon' );
	$GLOBALS['__resp'] = json_resp( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'ok' ) ) ) ) ) ) );
	MAN_AI::preguntar( 'gemini 4' );
	$b = cuerpo_ultimo();
	chk( 'low' === $b['generationConfig']['thinkingConfig']['thinkingLevel'], 'Gemini 4 Argon: thinkingLevel=low' );

	// Modelos 2.5 solo llegan por detección (cuentas que ya los usaban).
	update_option( MAN_AI::OPCION_MODELOS, array( 'gemini' => array( 'fecha' => time(), 'modelos' => array( 'gemini-2.5-flash' => 'Gemini 2.5 Flash', 'gemini-2.5-pro' => 'Gemini 2.5 Pro' ) ) ) );
	usar( 'gemini', 'gemini-2.5-flash' );
	MAN_AI::preguntar( 'gemini 2.5 flash' );
	$b = cuerpo_ultimo();
	chk( isset( $b['generationConfig']['thinkingConfig']['thinkingBudget'] ) && 0 === $b['generationConfig']['thinkingConfig']['thinkingBudget'], '2.5 Flash: thinkingBudget=0' );
	chk( ! isset( $b['generationConfig']['thinkingConfig']['thinkingLevel'] ), '2.5 Flash: sin thinkingLevel (no lo admite)' );
	usar( 'gemini', 'gemini-2.5-pro' );
	MAN_AI::preguntar( 'gemini 2.5 pro' );
	$b = cuerpo_ultimo();
	chk( 128 === $b['generationConfig']['thinkingConfig']['thinkingBudget'], '2.5 Pro: thinkingBudget=128 (no admite 0)' );
	delete_option( MAN_AI::OPCION_MODELOS );

	echo "\n--- 17. Gemini: cortes y bloqueos ---\n";
	usar( 'gemini', 'gemini-3.8-flash' );
	$GLOBALS['__resp'] = json_resp( array( 'candidates' => array( array( 'finishReason' => 'MAX_TOKENS', 'content' => array( 'parts' => array() ) ) ) ) );
	$r = MAN_AI::preguntar( 'gemini se agotó' );
	chk( is_wp_error( $r ) && 'man_ai_304' === $r->code, 'MAX_TOKENS sin texto → MAN-AI-304' );
	usar( 'gemini' );
	$GLOBALS['__resp'] = json_resp( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) );
	$r = MAN_AI::preguntar( 'gemini bloqueada' );
	chk( is_wp_error( $r ) && 'man_ai_303' === $r->code, 'pregunta bloqueada (promptFeedback) → MAN-AI-303' );
	usar( 'gemini' );
	$GLOBALS['__resp'] = json_resp( array( 'candidates' => array( array( 'finishReason' => 'SAFETY' ) ) ) );
	$r = MAN_AI::preguntar( 'gemini filtrada' );
	chk( is_wp_error( $r ) && 'man_ai_303' === $r->code, 'respuesta filtrada (finishReason=SAFETY) → MAN-AI-303' );
	usar( 'gemini' );
	$GLOBALS['__resp'] = json_resp( array( 'error' => array( 'code' => 403, 'message' => 'Requests from unrestricted API keys are blocked.' ) ), 403 );
	$res = MAN_AI::probar_proveedor( 'gemini' );
	chk( ! $res['ok'] && false !== strpos( $res['mensaje'], 'aistudio.google.com/apikey' ), 'clave estándar rechazada: el panel explica qué clave crear' );

	echo "\n--- 18. Modelo retirado guardado ---\n";
	usar( 'gemini', 'gemini-2.0-flash' );
	$GLOBALS['__resp'] = json_resp( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'ok' ) ) ) ) ) ) );
	MAN_AI::preguntar( 'con modelo viejo' );
	$u = ultimo();
	chk( false !== strpos( $u['url'], 'gemini-3.8-flash' ) && false === strpos( $u['url'], 'gemini-2.0' ), 'un modelo apagado se sustituye por el recomendado al llamar' );
	$fila = null;
	foreach ( MAN_AI::revisar_configuracion() as $f ) { if ( 'Modelo' === $f['paso'] ) { $fila = $f; } }
	chk( $fila && 'error' === $fila['estado'] && false !== strpos( $fila['mensaje'], 'gemini-3.8-flash' ), 'el diagnóstico lo señala y dice qué modelo se usa en su lugar' );
	chk( 'gemini-3.8-flash' === MAN_AI::modelo_vigente( 'gemini', 'gemini-1.5-pro' ), 'gemini-1.5-pro → gemini-3.8-flash' );
	chk( 'claude-sonnet-5-5' === MAN_AI::modelo_vigente( 'claude', 'claude-sonnet-5-5' ), 'un modelo vigente se respeta' );

	echo "\n--- 19. Detectar modelos: Claude ---\n";
	usar( 'claude', 'claude-opus-5-5' );
	$cfg = get_option( MAN_AI::OPCION ); $cfg['claude_clave'] = MAN_Security::cifrar( 'sk-ant-prueba' ); update_option( MAN_AI::OPCION, $cfg );
	$GLOBALS['__gets'] = array();
	$GLOBALS['__get_cola'] = array(
		json_resp( array( 'data' => array(
			array( 'type' => 'model', 'id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5' ),
			array( 'type' => 'model', 'id' => 'claude-fable-5-1', 'display_name' => 'Claude Fable 5.1' ),
		), 'has_more' => true, 'last_id' => 'claude-fable-5-1' ) ),
		json_resp( array( 'data' => array(
			array( 'type' => 'model', 'id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5' ),
			array( 'type' => 'model', 'id' => 'x"><script>', 'display_name' => 'malicioso' ),
		), 'has_more' => false ) ),
	);
	$d = MAN_AI::detectar_modelos( 'claude' );
	chk( $d['ok'] && ! $d['aviso'], 'clave reconocida y el modelo elegido está disponible' );
	chk( 2 === count( $GLOBALS['__gets'] ) && false !== strpos( $GLOBALS['__gets'][1]['url'], 'after_id=claude-fable-5-1' ), 'recorre las páginas con after_id' );
	$g = $GLOBALS['__gets'][0];
	chk( false !== strpos( $g['url'], 'api.anthropic.com/v1/models' ) && 'sk-ant-prueba' === $g['args']['headers']['x-api-key'] && isset( $g['args']['headers']['anthropic-version'] ), 'GET /v1/models con x-api-key y anthropic-version' );
	$det = MAN_AI::modelos_detectados( 'claude' );
	chk( 3 === count( $det['modelos'] ) && ! isset( $det['modelos']['x"><script>'] ), 'guarda 3 modelos y descarta un ID con caracteres no válidos' );
	$m = MAN_AI::modelos_de( 'claude' );
	chk( isset( $m['claude-fable-5-1'] ) && false !== strpos( $m['claude-fable-5-1'], 'detectado' ), 'un modelo nuevo de la cuenta se puede elegir sin actualizar el plugin' );
	chk( 'claude-fable-5-1' === MAN_AI::modelo_vigente( 'claude', 'claude-fable-5-1' ), 'y se usa tal cual al llamar' );

	$GLOBALS['__get_cola'] = array( json_resp( array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ), 401 ) );
	$d = MAN_AI::detectar_modelos( 'claude' );
	chk( ! $d['ok'] && false !== strpos( $d['mensaje'], 'MAN-AI-201' ) && false !== strpos( $d['mensaje'], 'invalid x-api-key' ), 'clave rechazada: código y motivo del proveedor' );

	echo "\n--- 20. Detectar modelos: Gemini ---\n";
	usar( 'gemini', 'gemini-3.8-flash' );
	$GLOBALS['__gets'] = array();
	$GLOBALS['__get_cola'] = array( json_resp( array( 'models' => array(
		array( 'name' => 'models/gemini-3.8-flash', 'displayName' => 'Gemini 3.8 Flash', 'supportedGenerationMethods' => array( 'generateContent', 'countTokens' ) ),
		array( 'name' => 'models/gemini-4-argon', 'displayName' => 'Gemini 4 Argon', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		array( 'name' => 'models/gemini-3.8-flash-tts', 'displayName' => 'TTS', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		array( 'name' => 'models/gemini-3.1-flash-image', 'displayName' => 'Imagen', 'supportedGenerationMethods' => array( 'generateContent' ) ),
		array( 'name' => 'models/gemini-embedding-001', 'displayName' => 'Embedding', 'supportedGenerationMethods' => array( 'embedContent' ) ),
	) ) ) );
	$d = MAN_AI::detectar_modelos( 'gemini' );
	$g = $GLOBALS['__gets'][0];
	chk( $d['ok'] && ! $d['aviso'], 'clave reconocida y el modelo elegido está disponible' );
	chk( false === strpos( $g['url'], 'AIza-prueba' ) && 'AIza-prueba' === $g['args']['headers']['x-goog-api-key'], 'la clave va en cabecera, no en la URL' );
	$det = MAN_AI::modelos_detectados( 'gemini' );
	chk( array( 'gemini-3.8-flash', 'gemini-4-argon' ) === array_keys( $det['modelos'] ), 'solo modelos de texto: fuera voz, imagen y embeddings' );

	usar( 'gemini', 'gemini-3.5-flash-lite' );
	$GLOBALS['__get_cola'] = array( json_resp( array( 'models' => array(
		array( 'name' => 'models/gemini-3.8-flash', 'supportedGenerationMethods' => array( 'generateContent' ) ),
	) ) ) );
	$d = MAN_AI::detectar_modelos( 'gemini' );
	chk( $d['ok'] && $d['aviso'] && false !== strpos( $d['mensaje'], 'NO está' ), 'avisa si el modelo elegido no está en la cuenta' );

	$d = MAN_AI::detectar_modelos( 'openai' );
	chk( ! $d['ok'], 'OpenAI no ofrece detección: lo dice en vez de fallar' );
	$GLOBALS['__get_cola'] = array();
	delete_option( MAN_AI::OPCION_MODELOS );

	echo "\n--- 21. Migración al actualizar el plugin ---\n";
	$cfg = get_option( MAN_AI::OPCION );
	$cfg['gemini_modelo'] = 'gemini-2.0-flash';
	$cfg['claude_modelo'] = 'claude-sonnet-5-5';
	update_option( MAN_AI::OPCION, $cfg );
	update_option( 'man_version', '1.42.0' );
	\GobernacionNarino\MonitorAmbiental\MAN_Activator::migrar_si_necesario();
	$cfg = get_option( MAN_AI::OPCION );
	chk( 'gemini-3.8-flash' === $cfg['gemini_modelo'], 'la config guardada con gemini-2.0-flash pasa a gemini-3.8-flash' );
	chk( 'claude-sonnet-5-5' === $cfg['claude_modelo'], 'un modelo vigente elegido por el operador no se toca' );
	chk( MAN_VERSION === get_option( 'man_version' ), 'marca la versión migrada' );

	echo "\n" . ( $fallos ? "$fallos FALLO(S)\n" : "TODO OK\n" );
	exit( $fallos ? 1 : 0 );
}
