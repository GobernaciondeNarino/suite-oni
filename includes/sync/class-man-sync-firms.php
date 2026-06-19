<?php
/**
 * Conector NASA FIRMS — focos de calor activos (VIIRS/MODIS) en Nariño.
 * Conteo total y por municipio (point-in-polygon). Requiere MAP_KEY gratuita,
 * guardada cifrada. Resiliente: sin clave o ante fallo no rompe el render
 * (los componentes caen a focos modelados etiquetados).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Firms {

	/** Bounding-box de Nariño: W,S,E,N. */
	const BBOX = '-79.1,0.3,-76.8,2.7';

	/** Clave de caché de focos. */
	const CLAVE = 'focos_calor';

	/**
	 * Sincroniza los focos de calor.
	 *
	 * @param array $cfg Configuración (clave_plana = MAP_KEY, dataset_id = sensor).
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$key = isset( $cfg['clave_plana'] ) ? trim( (string) $cfg['clave_plana'] ) : '';
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 43200;

		if ( '' === $key ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Falta MAP_KEY de FIRMS (focos quedan en modelado)' );
		}

		$sensor = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : 'VIIRS_SNPP_NRT';
		// Ventana de detección en días (FIRMS admite 1–10). 7 días captura focos en
		// más municipios; con 1–2 días, en temporada húmeda suele aparecer uno solo.
		$dias   = isset( $cfg['dias'] ) && is_numeric( $cfg['dias'] ) ? max( 1, min( 10, (int) $cfg['dias'] ) ) : 7;
		$base   = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) : 'https://firms.modaps.eosdis.nasa.gov/api/area/csv';
		$url    = $base . '/' . rawurlencode( $key ) . '/' . rawurlencode( $sensor ) . '/' . self::BBOX . '/' . $dias;

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$puntos   = self::parse_csv_lat_lon( $r['cuerpo'] );
		$por_muni = MAN_Municipios::contar_focos_por_municipio( $puntos );

		MAN_Cache::set(
			self::CLAVE,
			array(
				'total'       => count( $puntos ),
				'por_muni'    => $por_muni,
				'sensor'      => $sensor,
				'dias'        => $dias,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'NASA FIRMS (' . $sensor . ')',
				'estado'      => 'ok',
			),
			$ttl,
			'ambiental'
		);

		return array(
			'ok'        => true,
			'registros' => count( $puntos ),
			'mensaje'   => count( $puntos ) . ' focos en ' . $dias . ' días',
		);
	}

	/**
	 * Extrae [lon,lat] de un CSV de FIRMS (columnas latitude / longitude).
	 *
	 * @param string $csv Cuerpo CSV.
	 * @return array[] Lista [lon, lat].
	 */
	public static function parse_csv_lat_lon( $csv ) {
		$lineas = preg_split( '/\r\n|\r|\n/', (string) $csv );
		$cab    = array();
		$out    = array();
		foreach ( $lineas as $linea ) {
			$linea = trim( $linea );
			if ( '' === $linea ) {
				continue;
			}
			$cols = str_getcsv( $linea );
			if ( ! $cab ) {
				$cab = array_map( 'strtolower', array_map( 'trim', $cols ) );
				continue;
			}
			$la = array_search( 'latitude', $cab, true );
			$lo = array_search( 'longitude', $cab, true );
			if ( false !== $la && false !== $lo && isset( $cols[ $la ], $cols[ $lo ] ) && is_numeric( $cols[ $la ] ) && is_numeric( $cols[ $lo ] ) ) {
				$out[] = array( (float) $cols[ $lo ], (float) $cols[ $la ] );
			}
		}
		return $out;
	}
}
