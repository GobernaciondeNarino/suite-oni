<?php
/**
 * Seguridad transversal (Sección 9 de la especificación).
 *
 * Sanitización de entradas, validación de DIVIPOLA y bounding-box de Nariño
 * (anti-SSRF), rate-limiting por IP y cifrado en reposo de claves de API.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Security {

	/** Recuadro válido de Nariño (Sección 10.3). */
	const BBOX = array(
		'latMin' => 0.35,
		'latMax' => 2.70,
		'lonMin' => -79.10,
		'lonMax' => -76.85,
	);

	/* ----------------------------------------------------------------- */
	/* Sanitización de entradas                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Normaliza un municipio a un DIVIPOLA válido o 'departamento'.
	 * Acepta código de 5 dígitos o nombre; valida contra la lista blanca.
	 *
	 * @param string $valor Código o nombre.
	 * @return string DIVIPOLA de 5 dígitos o 'departamento'.
	 */
	public static function sanitizar_divipola( $valor ) {
		$valor = sanitize_text_field( (string) $valor );

		if ( '' === $valor || 0 === strcasecmp( $valor, 'departamento' ) ) {
			return 'departamento';
		}

		if ( preg_match( '/^\d{5}$/', $valor ) ) {
			return MAN_Municipios::existe( $valor ) ? $valor : 'departamento';
		}

		$mun = MAN_Municipios::por_nombre( $valor );
		return $mun ? $mun['divipola'] : 'departamento';
	}

	/**
	 * Sanitiza un mes en formato AAAA-MM; cae al mes actual si es inválido.
	 *
	 * @param string $valor Mes.
	 * @return string AAAA-MM.
	 */
	public static function sanitizar_mes( $valor ) {
		$valor = sanitize_text_field( (string) $valor );
		if ( preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $valor ) ) {
			return $valor;
		}
		return gmdate( 'Y-m' );
	}

	/**
	 * Valida que un par lat/lon caiga dentro del bounding-box de Nariño.
	 *
	 * Utilidad anti-SSRF reservada: aplíquese antes de construir cualquier URL
	 * a APIs externas SI en el futuro se proxean coordenadas desde el servidor.
	 * Hoy Open-Meteo se consume directo desde el navegador (no hay proxy PHP).
	 *
	 * @param float $lat Latitud.
	 * @param float $lon Longitud.
	 * @return bool
	 */
	public static function validar_bbox( $lat, $lon ) {
		$lat = (float) $lat;
		$lon = (float) $lon;
		return $lat >= self::BBOX['latMin'] && $lat <= self::BBOX['latMax']
			&& $lon >= self::BBOX['lonMin'] && $lon <= self::BBOX['lonMax'];
	}

	/* ----------------------------------------------------------------- */
	/* Rate-limiting                                                     */
	/* ----------------------------------------------------------------- */

	/**
	 * Limita peticiones por IP usando un contador en transient.
	 *
	 * @param string $clave_base Identificador del recurso protegido.
	 * @param int    $max        Máximo de peticiones por ventana.
	 * @param int    $ventana    Tamaño de la ventana en segundos.
	 * @return bool True si se permite; false si se excedió el límite.
	 */
	public static function rate_limit( $clave_base, $max = 60, $ventana = 60 ) {
		$ip    = self::ip_cliente();
		$clave = 'man_rl_' . md5( $clave_base . '|' . $ip );
		$n     = (int) get_transient( $clave );

		if ( $n >= (int) $max ) {
			return false;
		}
		set_transient( $clave, $n + 1, (int) $ventana );
		return true;
	}

	/**
	 * Obtiene la IP del cliente de forma segura.
	 *
	 * @return string
	 */
	public static function ip_cliente() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		$ip = filter_var( $ip, \FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
		return $ip;
	}

	/* ----------------------------------------------------------------- */
	/* Cifrado en reposo de claves de API (Sección 9.4)                  */
	/* ----------------------------------------------------------------- */

	/**
	 * Cifra un texto con sodium_crypto_secretbox.
	 *
	 * @param string $texto Texto plano.
	 * @return string Paquete base64 (nonce + cifrado) o '' si no se pudo.
	 */
	public static function cifrar( $texto ) {
		$texto = (string) $texto;
		if ( '' === $texto || ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}
		try {
			$nonce   = random_bytes( \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cifrado = sodium_crypto_secretbox( $texto, $nonce, self::clave_cifrado() );
			return base64_encode( $nonce . $cifrado );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/**
	 * Descifra un paquete generado por self::cifrar().
	 *
	 * @param string $paquete Paquete base64.
	 * @return string Texto plano o '' si falla.
	 */
	public static function descifrar( $paquete ) {
		$paquete = (string) $paquete;
		if ( '' === $paquete || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}
		$raw = base64_decode( $paquete, true );
		if ( false === $raw || strlen( $raw ) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$nonce   = substr( $raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cifrado = substr( $raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plano   = sodium_crypto_secretbox_open( $cifrado, $nonce, self::clave_cifrado() );
		return false === $plano ? '' : $plano;
	}

	/**
	 * Deriva la clave de 32 bytes a partir de las sales de wp-config.
	 *
	 * @return string 32 bytes binarios.
	 */
	private static function clave_cifrado() {
		$material = '';
		if ( defined( 'AUTH_KEY' ) ) {
			$material .= \AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_SALT' ) ) {
			$material .= \SECURE_AUTH_SALT;
		}
		if ( '' === $material && function_exists( 'wp_salt' ) ) {
			$material = wp_salt( 'secure_auth' );
		}
		return hash( 'sha256', 'man-cifrado|' . $material, true );
	}
}
