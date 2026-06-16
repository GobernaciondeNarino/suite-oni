<?php
/**
 * Déficit hídrico derivado REAL por municipio: precipitación reciente
 * (Open-Meteo, suma diaria de ~30 días) comparada con un umbral climatológico
 * simple. Server-side (cron), cacheado. Reemplaza el valor semilla donde haya
 * cobertura; el índice es función pura y testeable.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Deficit {

	/** Clave de caché del déficit. */
	const CLAVE = 'deficit_municipios';

	/**
	 * Índice de déficit 0..100 (100 = sin lluvia frente a lo esperado).
	 *
	 * @param float $precip_mm    Precipitación acumulada reciente (mm).
	 * @param float $climatica_mm Precipitación climática esperada (mm) > 0.
	 * @return int
	 */
	public static function indice_deficit( $precip_mm, $climatica_mm ) {
		$c     = max( 1.0, (float) $climatica_mm );
		$ratio = max( 0.0, min( 1.0, (float) $precip_mm / $c ) );
		return (int) round( 100 * ( 1.0 - $ratio ) );
	}

	/**
	 * Climatología de referencia (mm de lluvia / ~30 días) por subregión.
	 * El litoral Pacífico es muy húmedo; el altiplano andino, más seco. Mejor
	 * que un único umbral fijo para los 64 municipios.
	 *
	 * @param string $subregion Subregión del municipio.
	 * @return float
	 */
	public static function climatologia( $subregion ) {
		$litoral = array(
			'Sanquianga'           => 300.0,
			'Pacífico Sur'         => 320.0,
			'Telembí'              => 340.0,
			'Pie de Monte Costero' => 300.0,
			'Frontera Pacífica'    => 220.0,
		);
		if ( isset( $litoral[ $subregion ] ) ) {
			return $litoral[ $subregion ];
		}
		$humedo_andino = array( 'Cordillera', 'Centro-Occidente / Abades', 'Abades-La Llanada', 'Occidente', 'Juanambú' );
		if ( in_array( $subregion, $humedo_andino, true ) ) {
			return 140.0;
		}
		return 90.0; // sabana / altiplano andino, más seco.
	}

	/**
	 * Sincroniza el déficit de todos los municipios. Agrupa las consultas a
	 * Open-Meteo en pocas peticiones (varias coordenadas por llamada) en vez de
	 * 64 llamadas sueltas: más rápido y menos frágil ante rate-limit.
	 *
	 * @param array $cfg Configuración (url base Open-Meteo, ttl).
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$base = ! empty( $cfg['url'] ) ? $cfg['url'] : 'https://api.open-meteo.com/v1/forecast';
		$ssl  = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl  = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 21600;

		$municipios = MAN_Municipios::todos();
		if ( empty( $municipios ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Sin municipios' );
		}

		$out    = array();
		$n      = 0;
		$lotes  = array_chunk( $municipios, 30 ); // varias coordenadas por petición.
		foreach ( $lotes as $lote ) {
			$lats = implode( ',', array_map( function ( $m ) { return $m['lat']; }, $lote ) );
			$lons = implode( ',', array_map( function ( $m ) { return $m['lon']; }, $lote ) );
			$url  = add_query_arg(
				array(
					'latitude'      => $lats,
					'longitude'     => $lons,
					'daily'         => 'precipitation_sum',
					'past_days'     => 31,
					'forecast_days' => 1,
					'timezone'      => 'America/Bogota',
				),
				$base
			);

			$r = MAN_Sync::http_get( $url, $ssl, array( 'timeout' => 20 ) );
			if ( ! $r['ok'] ) {
				continue;
			}
			$j = json_decode( $r['cuerpo'], true );
			if ( ! is_array( $j ) ) {
				continue;
			}
			// Con varias coordenadas Open-Meteo devuelve un array de ubicaciones;
			// con una sola, un objeto. Se normaliza a lista alineada con $lote.
			$locs = isset( $j[0] ) ? $j : array( $j );

			foreach ( $lote as $idx => $mun ) {
				$loc   = isset( $locs[ $idx ] ) ? $locs[ $idx ] : null;
				$serie = ( $loc && isset( $loc['daily']['precipitation_sum'] ) && is_array( $loc['daily']['precipitation_sum'] ) )
					? $loc['daily']['precipitation_sum'] : array();
				if ( empty( $serie ) ) {
					continue;
				}
				$suma = 0.0;
				foreach ( $serie as $v ) {
					$suma += (float) $v;
				}
				$clima            = self::climatologia( isset( $mun['subregion'] ) ? $mun['subregion'] : '' );
				$out[ $mun['divipola'] ] = array(
					'deficit'   => self::indice_deficit( $suma, $clima ),
					'precip_mm' => round( $suma, 1 ),
					'clima_mm'  => $clima,
				);
				$n++;
			}
		}

		MAN_Cache::set(
			self::CLAVE,
			array(
				'por_muni'    => $out,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'Open-Meteo (déficit derivado real, climatología por subregión)',
				'estado'      => $n > 0 ? 'ok' : 'mantenimiento',
			),
			$ttl,
			'ambiental'
		);

		return array(
			'ok'        => $n > 0,
			'registros' => $n,
			'mensaje'   => $n . ' municipios con déficit calculado en ' . count( $lotes ) . ' petición(es)',
		);
	}
}
