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
		return self::mes_actual();
	}

	/**
	 * Mes en curso en hora local del sitio (Colombia, UTC-5).
	 *
	 * Con gmdate() el plugin saltaba de mes a las 19:00 hora local del último
	 * día, pidiendo un mes sin datos durante cinco horas.
	 *
	 * @return string AAAA-MM.
	 */
	public static function mes_actual() {
		if ( function_exists( 'wp_date' ) ) {
			$mes = wp_date( 'Y-m' );
			if ( $mes ) {
				return $mes;
			}
		}
		return gmdate( 'Y-m', time() - 5 * HOUR_IN_SECONDS );
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
		$ventana = max( 1, (int) $ventana );
		$ip      = self::ip_cliente();

		// Ventana FIJA codificada en la clave. Con ventana deslizante, cada
		// petición permitida renovaba el TTL y el contador nunca expiraba: un
		// cliente legítimo que sondease sin pausa acababa bloqueado pese a
		// mantenerse por debajo del límite por minuto.
		$slot  = (int) floor( time() / $ventana );
		$clave = 'man_rl_' . md5( $clave_base . '|' . $ip . '|' . $slot );

		$n = (int) get_transient( $clave );
		if ( $n >= (int) $max ) {
			return false;
		}
		// TTL doble: cubre el desfase entre slots sin estirar la ventana.
		set_transient( $clave, $n + 1, $ventana * 2 );
		return true;
	}

	/**
	 * Obtiene la IP del cliente de forma segura.
	 *
	 * Se usa REMOTE_ADDR porque las cabeceras X-Forwarded-* son falsificables.
	 * Tras un proxy o CDN de confianza, todos los visitantes comparten IP y
	 * agotarían el mismo cupo: en ese caso resuelva la IP real con el filtro
	 * `man_ip_cliente`, que debe devolver una IP ya validada.
	 *
	 * @return string
	 */
	public static function ip_cliente() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0.0.0.0';
		$ip = filter_var( $ip, \FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';

		/**
		 * Filtra la IP usada para el rate-limit (entornos con proxy inverso).
		 *
		 * @param string $ip IP validada de REMOTE_ADDR.
		 */
		$filtrada = apply_filters( 'man_ip_cliente', $ip );
		return ( is_string( $filtrada ) && filter_var( $filtrada, \FILTER_VALIDATE_IP ) ) ? $filtrada : $ip;
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
