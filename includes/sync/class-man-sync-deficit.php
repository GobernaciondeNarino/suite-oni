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
	 * Sincroniza el déficit de todos los municipios con centroide conocido.
	 *
	 * @param array $cfg Configuración (url base Open-Meteo, ttl, climatica_mm).
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$base  = ! empty( $cfg['url'] ) ? $cfg['url'] : 'https://api.open-meteo.com/v1/forecast';
		$ssl   = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl   = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 21600;
		$clima = isset( $cfg['climatica_mm'] ) ? (float) $cfg['climatica_mm'] : 120.0; // mm/30 d de referencia.

		$centroides = MAN_Municipios::centroides();
		if ( empty( $centroides ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Sin centroides de municipios' );
		}

		$out = array();
		$n   = 0;
		foreach ( $centroides as $cod => $ll ) {
			$url = add_query_arg(
				array(
					'latitude'      => $ll[0],
					'longitude'     => $ll[1],
					'daily'         => 'precipitation_sum',
					'past_days'     => 31,
					'forecast_days' => 1,
					'timezone'      => 'America/Bogota',
				),
				$base
			);

			$r = MAN_Sync::http_get( $url, $ssl, array( 'timeout' => 12 ) );
			if ( ! $r['ok'] ) {
				continue;
			}
			$j     = json_decode( $r['cuerpo'], true );
			$serie = isset( $j['daily']['precipitation_sum'] ) && is_array( $j['daily']['precipitation_sum'] )
				? $j['daily']['precipitation_sum'] : array();
			if ( empty( $serie ) ) {
				continue;
			}
			$suma = 0.0;
			foreach ( $serie as $v ) {
				$suma += (float) $v;
			}
			$out[ $cod ] = array(
				'deficit'   => self::indice_deficit( $suma, $clima ),
				'precip_mm' => round( $suma, 1 ),
			);
			$n++;
		}

		MAN_Cache::set(
			self::CLAVE,
			array(
				'por_muni'     => $out,
				'climatica_mm' => $clima,
				'actualizado'  => current_time( 'mysql', true ),
				'fuente'       => 'Open-Meteo (déficit derivado real)',
				'estado'       => $n > 0 ? 'ok' : 'mantenimiento',
			),
			$ttl,
			'ambiental'
		);

		return array(
			'ok'        => $n > 0,
			'registros' => $n,
			'mensaje'   => $n . ' municipios con déficit calculado',
		);
	}
}
