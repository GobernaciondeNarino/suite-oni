<?php
/**
 * Orquestador de sincronización (Capa 1 — servidor / WP-Cron).
 *
 * Recorre las fuentes activas, delega en cada conector, actualiza el estado
 * en la configuración y registra cada sincronización en wp_man_audit.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync {

	/** Mapa slug → clase conectora. */
	const FUENTES = array(
		'noaa_oni' => 'MAN_Sync_Oni',
		'ideam'    => 'MAN_Sync_Ideam',
		'sivigila' => 'MAN_Sync_Sivigila',
		'ioc'      => 'MAN_Sync_Sealevel',
	);

	public function __construct() {
		add_action( MAN_Activator::HOOK_CRON, array( $this, 'ejecutar' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'intervalos_personalizados' ) );
	}

	/**
	 * Añade intervalos de cron propios de 6 y 12 horas (el evento se agenda
	 * con man_12h en el activador, de modo que aparece etiquetado en WP-Cron).
	 * Estático para poder registrarlo también durante la activación.
	 *
	 * @param array $programas Programas existentes.
	 * @return array
	 */
	public static function intervalos_personalizados( $programas ) {
		$programas['man_6h']  = array(
			'interval' => 6 * 3600,
			'display'  => __( 'Cada 6 horas (Monitor Ambiental)', 'monitor-ambiental-narino' ),
		);
		$programas['man_12h'] = array(
			'interval' => 12 * 3600,
			'display'  => __( 'Cada 12 horas (Monitor Ambiental)', 'monitor-ambiental-narino' ),
		);
		return $programas;
	}

	/**
	 * Callback del cron: sincroniza todas las fuentes activas.
	 */
	public function ejecutar() {
		$config = get_option( 'man_api_config', array() );
		foreach ( self::FUENTES as $slug => $clase ) {
			if ( empty( $config[ $slug ] ) || empty( $config[ $slug ]['activa'] ) ) {
				continue;
			}
			$this->ejecutar_fuente( $slug );
		}
	}

	/**
	 * Sincroniza una fuente concreta y actualiza su estado.
	 *
	 * @param string $slug Slug de la fuente.
	 * @return array {ok, registros, mensaje, latencia_ms}.
	 */
	public function ejecutar_fuente( $slug ) {
		$config = get_option( 'man_api_config', array() );
		if ( empty( $config[ $slug ] ) || ! isset( self::FUENTES[ $slug ] ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Fuente desconocida', 'latencia_ms' => 0 );
		}

		$clase = __NAMESPACE__ . '\\' . self::FUENTES[ $slug ];
		$cfg   = $config[ $slug ];

		// Descifra la clave (si existe) para que el conector pueda autenticar
		// sin que la clave se almacene ni viaje en texto plano.
		if ( ! empty( $cfg['clave'] ) ) {
			$cfg['clave_plana'] = MAN_Security::descifrar( $cfg['clave'] );
		}

		$t0  = microtime( true );
		$res = call_user_func( array( $clase, 'sincronizar' ), $cfg );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( ! is_array( $res ) ) {
			$res = array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Respuesta inválida del conector' );
		}
		$res = wp_parse_args( $res, array( 'ok' => false, 'registros' => 0, 'mensaje' => '' ) );

		$config[ $slug ]['ultima_sync']      = time();
		$config[ $slug ]['ultimo_resultado'] = ( $res['ok'] ? 'OK' : 'ERROR' ) . ' · ' . (int) $res['registros'] . ' reg · ' . $ms . ' ms';
		update_option( 'man_api_config', $config );

		self::auditar( 'sync', $slug, $res['ok'] ? 'ok' : 'error', (int) $res['registros'], $res['mensaje'] );

		$res['latencia_ms'] = $ms;
		return $res;
	}

	/**
	 * GET HTTP resiliente para los conectores.
	 *
	 * @param string $url       URL.
	 * @param bool   $sslverify Verificar certificado.
	 * @param array  $args      Argumentos extra de wp_remote_get.
	 * @return array {ok, codigo, cuerpo, error}.
	 */
	public static function http_get( $url, $sslverify = true, $args = array() ) {
		$def  = array(
			'timeout'    => 20,
			'sslverify'  => (bool) $sslverify,
			'redirection'=> 3,
			'headers'    => array( 'Accept' => '*/*' ),
			'user-agent' => 'MonitorAmbientalNarino/1.0 (+https://gobiernoabierto.narino.gov.co)',
		);
		$args = array_merge( $def, $args );
		$resp = wp_remote_get( $url, $args );

		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'codigo' => 0, 'cuerpo' => '', 'error' => $resp->get_error_message() );
		}
		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		return array(
			'ok'     => ( $codigo >= 200 && $codigo < 300 ),
			'codigo' => $codigo,
			'cuerpo' => wp_remote_retrieve_body( $resp ),
			'error'  => '',
		);
	}

	/**
	 * Registra un evento en la tabla de auditoría (timestamp UTC).
	 *
	 * @param string $evento    Tipo de evento.
	 * @param string $fuente    Fuente.
	 * @param string $resultado ok|error|...
	 * @param int    $registros Nº de registros.
	 * @param string $detalle   Detalle.
	 */
	public static function auditar( $evento, $fuente, $resultado, $registros = 0, $detalle = '' ) {
		global $wpdb;
		$tabla = $wpdb->prefix . 'man_audit';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$tabla,
			array(
				'evento'    => substr( (string) $evento, 0, 64 ),
				'fuente'    => substr( (string) $fuente, 0, 64 ),
				'resultado' => substr( (string) $resultado, 0, 32 ),
				'detalle'   => substr( (string) $detalle, 0, 1000 ),
				'registros' => (int) $registros,
				'ts'        => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}
}
