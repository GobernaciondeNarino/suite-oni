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

	/** Ventana máxima que admite la API de FIRMS, en días. */
	const DIAS_MAX = 5;

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
		// Ventana de detección en días. FIRMS acepta SOLO 1–5: con 7 responde
		// HTTP 400 «Invalid day range. Expects [1..5]», que es lo que dejó esta
		// fuente sin sincronizar desde la v1.37.1. Se usa el máximo permitido
		// porque con 1–2 días, en temporada húmeda, suele aparecer un solo
		// municipio (que es lo que aquel cambio intentaba resolver).
		$dias   = isset( $cfg['dias'] ) && is_numeric( $cfg['dias'] ) ? max( 1, min( self::DIAS_MAX, (int) $cfg['dias'] ) ) : self::DIAS_MAX;
		$base   = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) : 'https://firms.modaps.eosdis.nasa.gov/api/area/csv';
		$url    = $base . '/' . rawurlencode( $key ) . '/' . rawurlencode( $sensor ) . '/' . self::BBOX . '/' . $dias;

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			// FIRMS explica el motivo en el cuerpo, en texto plano; sin él, un
			// «HTTP 400» a secas no distingue una clave inválida de un rango
			// de días fuera de lo admitido.
			$motivo = trim( wp_strip_all_tags( (string) $r['cuerpo'] ) );
			$motivo = ( '' !== $motivo && strlen( $motivo ) < 200 ) ? ' — ' . $motivo : '';
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] . $motivo );
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
		$la     = false;
		$lo     = false;
		foreach ( $lineas as $linea ) {
			$linea = trim( $linea );
			if ( '' === $linea ) {
				continue;
			}
			$cols = str_getcsv( $linea );
			if ( ! $cab ) {
				// Los índices de columna se resuelven una sola vez con la
				// cabecera: buscarlos por fila repetía la búsqueda miles de
				// veces en un CSV de focos grande.
				$cab = array_map( 'strtolower', array_map( 'trim', $cols ) );
				$la  = array_search( 'latitude', $cab, true );
				$lo  = array_search( 'longitude', $cab, true );
				continue;
			}
			if ( false !== $la && false !== $lo && isset( $cols[ $la ], $cols[ $lo ] ) && is_numeric( $cols[ $la ] ) && is_numeric( $cols[ $lo ] ) ) {
				$out[] = array( (float) $cols[ $lo ], (float) $cols[ $la ] );
			}
		}
		return $out;
	}
}
