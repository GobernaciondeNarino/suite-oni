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
	chk( is_wp_error( $r ) && 'man_ai_rechazo' === $r->code, 'se distingue del fallo técnico y da mensaje al ciudadano' );

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
	chk( is_wp_error( $r ) && 'man_ai_cupo' === $r->code, 'el cupo por IP corta antes de gastar' );
	MAN_Security::$permitir = true;

	$cfg = get_option( MAN_AI::OPCION ); $cfg['limite_dia'] = 1; update_option( MAN_AI::OPCION, $cfg );
	$GLOBALS['__c'] = array();
	$GLOBALS['__resp'] = array( 'code' => 200, 'body' => json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'ok' ) ) ) ) ) ) ) );
	MAN_AI::preguntar( 'primera del dia' );
	$r = MAN_AI::preguntar( 'segunda del dia' );
	chk( is_wp_error( $r ) && 'man_ai_tope' === $r->code, 'el tope diario protege el presupuesto' );

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
	chk( is_wp_error( $r ) && 'man_ai_inactivo' === $r->code, 'apagado, no responde' );

	$cfg['activo'] = true; $cfg['visible'] = false; update_option( MAN_AI::OPCION, $cfg );
	chk( MAN_AI::activo() && ! MAN_AI::visible(), 'activo pero oculto: se puede probar sin publicarlo' );

	$cfg['visible'] = true; $cfg['claude_clave'] = ''; $cfg['proveedor'] = 'claude'; update_option( MAN_AI::OPCION, $cfg );
	chk( ! MAN_AI::activo(), 'sin clave del proveedor activo, el módulo no se considera activo' );

	echo "\n" . ( $fallos ? "$fallos FALLO(S)\n" : "TODO OK\n" );
	exit( $fallos ? 1 : 0 );
}
