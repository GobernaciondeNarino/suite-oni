<?php
/**
 * Caché de dos niveles: transient (rápido) + tabla durable wp_man_cache.
 *
 * Los datos sincronizados por cron sobreviven al vaciado de transients
 * gracias a la tabla; además sirve de fallback junto a las semillas JSON
 * de la carpeta data/ (Principio de resiliencia, Sección 1.1).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Cache {

	/** Prefijo de transients. */
	const PREFIJO = 'man_';

	/**
	 * Lee un valor de caché. Primero transient, luego tabla durable.
	 *
	 * @param string $clave Clave lógica (sin prefijo).
	 * @return mixed|null   Valor decodificado o null si no existe / expiró.
	 */
	public static function get( $clave ) {
		$clave = self::normalizar( $clave );

		$t = get_transient( self::PREFIJO . $clave );
		if ( false !== $t ) {
			return $t;
		}

		global $wpdb;
		$tabla = $wpdb->prefix . 'man_cache';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$fila = $wpdb->get_row(
			$wpdb->prepare( "SELECT valor, expira FROM {$tabla} WHERE clave = %s", $clave ),
			\ARRAY_A
		);

		if ( ! $fila ) {
			return null;
		}

		if ( (int) $fila['expira'] > 0 && (int) $fila['expira'] < time() ) {
			return null; // expirado (pero la fila permanece como fallback durable)
		}

		$valor = json_decode( $fila['valor'], true );

		// Re-ceba el transient para acelerar siguientes lecturas.
		$ttl = max( 60, (int) $fila['expira'] - time() );
		set_transient( self::PREFIJO . $clave, $valor, $ttl );

		return $valor;
	}

	/**
	 * Lee el valor durable ignorando expiración (fallback de resiliencia).
	 *
	 * @param string $clave Clave lógica.
	 * @return mixed|null
	 */
	public static function get_durable( $clave ) {
		global $wpdb;
		$clave = self::normalizar( $clave );
		$tabla = $wpdb->prefix . 'man_cache';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$valor = $wpdb->get_var( $wpdb->prepare( "SELECT valor FROM {$tabla} WHERE clave = %s", $clave ) );
		return null === $valor ? null : json_decode( $valor, true );
	}

	/**
	 * Guarda un valor en ambos niveles.
	 *
	 * @param string $clave        Clave lógica.
	 * @param mixed  $valor        Valor serializable a JSON.
	 * @param int    $ttl_segundos Vida en segundos.
	 * @param string $grupo        Grupo lógico (auditoría/limpieza).
	 * @return bool
	 */
	public static function set( $clave, $valor, $ttl_segundos = 3600, $grupo = 'general' ) {
		$clave = self::normalizar( $clave );
		$ttl   = max( 60, (int) $ttl_segundos );

		set_transient( self::PREFIJO . $clave, $valor, $ttl );

		global $wpdb;
		$tabla = $wpdb->prefix . 'man_cache';
		$json  = wp_json_encode( $valor );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$res = $wpdb->replace(
			$tabla,
			array(
				'clave'       => $clave,
				'grupo'       => $grupo,
				'valor'       => $json,
				'expira'      => time() + $ttl,
				'actualizado' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		return false !== $res;
	}

	/**
	 * Elimina una clave de ambos niveles.
	 *
	 * @param string $clave Clave lógica.
	 */
	public static function delete( $clave ) {
		$clave = self::normalizar( $clave );
		delete_transient( self::PREFIJO . $clave );
		global $wpdb;
		$tabla = $wpdb->prefix . 'man_cache';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $tabla, array( 'clave' => $clave ), array( '%s' ) );
	}

	/**
	 * Carga una semilla JSON de la carpeta data/ (último fallback).
	 *
	 * @param string $archivo Nombre de archivo dentro de data/.
	 * @return mixed|null
	 */
	public static function semilla( $archivo ) {
		$ruta = MAN_DIR . 'data/' . ltrim( $archivo, '/\\' );
		// Evita traspaso de directorio.
		$ruta_real = realpath( $ruta );
		$base_real = realpath( MAN_DIR . 'data' );
		if ( ! $ruta_real || ! $base_real || 0 !== strpos( $ruta_real, $base_real ) ) {
			return null;
		}
		$contenido = file_get_contents( $ruta_real );
		if ( false === $contenido ) {
			return null;
		}
		return json_decode( $contenido, true );
	}

	/**
	 * Normaliza la clave a 160 chars seguros (la columna admite 191).
	 *
	 * @param string $clave Clave cruda.
	 * @return string
	 */
	private static function normalizar( $clave ) {
		$clave = preg_replace( '/[^a-z0-9_\-:.]/i', '_', (string) $clave );
		return substr( $clave, 0, 160 );
	}
}
