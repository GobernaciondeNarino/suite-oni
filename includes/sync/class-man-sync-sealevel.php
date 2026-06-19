<?php
/**
 * Conector IOC Sea Level Monitoring — nivel del mar en la costa Pacífica
 * (estación de Tumaco, código `tumc2`). Usa el servicio abierto service.php,
 * que devuelve un arreglo JSON de muestras {slevel, stime, sensor}. Filtra las
 * anomalías de sensor (picos no físicos) y entrega una serie limpia con el
 * último valor y la amplitud de marea.
 *
 * Endpoint:  https://www.ioc-sealevelmonitoring.org/service.php?query=data&code=tumc2&format=json&period=1
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Sealevel {

	/** Servicio de datos del IOC (el antiguo api.ioc-… devuelve HTML, no datos). */
	const SERVICIO = 'https://www.ioc-sealevelmonitoring.org/service.php';

	/** Rango físico plausible del nivel (m) para descartar anomalías de sensor. */
	const NIVEL_MIN = -2.0;
	const NIVEL_MAX = 15.0;

	/**
	 * Sincroniza el nivel del mar de una estación IOC.
	 *
	 * @param array $cfg Configuración (dataset_id = código de estación; period días).
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$code = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : 'tumc2';
		$ssl  = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : false;
		$ttl  = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 3600;
		// 'period' en días (admite decimales). Por defecto 1 día (ciclo de marea).
		$period = isset( $cfg['period'] ) && is_numeric( $cfg['period'] ) ? (float) $cfg['period'] : 1;

		// Usa el servicio canónico; solo respeta una URL personalizada si apunta a service.php.
		$base = ( ! empty( $cfg['url'] ) && false !== strpos( $cfg['url'], 'service.php' ) ) ? $cfg['url'] : self::SERVICIO;
		$url  = $base . '?query=data&format=json&code=' . rawurlencode( $code ) . '&period=' . rawurlencode( (string) $period );

		$r = MAN_Sync::http_get( $url, $ssl, array( 'timeout' => 25 ) );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}
		$json = json_decode( $r['cuerpo'], true );
		if ( ! is_array( $json ) || empty( $json ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Respuesta IOC vacía o no es una serie de datos' );
		}

		// Agrupa por sensor, descartando muestras no numéricas o fuera de rango físico.
		$por_sensor = array();
		foreach ( $json as $m ) {
			if ( ! isset( $m['slevel'], $m['stime'], $m['sensor'] ) || ! is_numeric( $m['slevel'] ) ) {
				continue;
			}
			$v = (float) $m['slevel'];
			if ( $v < self::NIVEL_MIN || $v > self::NIVEL_MAX ) {
				continue; // anomalía de sensor (p. ej. picos de 101 m).
			}
			$s = sanitize_key( $m['sensor'] );
			$por_sensor[ $s ][] = array( 'hora' => (string) $m['stime'], 'valor' => round( $v, 3 ) );
		}
		if ( empty( $por_sensor ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Sin muestras válidas tras filtrar anomalías' );
		}

		// Elige el sensor con más muestras válidas (serie más continua).
		$sensor = '';
		$mejor  = -1;
		foreach ( $por_sensor as $s => $muestras ) {
			if ( count( $muestras ) > $mejor ) {
				$mejor  = count( $muestras );
				$sensor = $s;
			}
		}
		$serie = $por_sensor[ $sensor ];

		// Estadísticos: último valor y amplitud de marea (min/max).
		$valores = array_column( $serie, 'valor' );
		$ultimo  = end( $serie );
		$min     = min( $valores );
		$max     = max( $valores );

		// Submuestrea a ~150 puntos para no inflar la caché ni el gráfico.
		$serie = self::submuestrear( $serie, 150 );

		MAN_Cache::set(
			'mar_nivel',
			array(
				'estacion'    => $code,
				'sensor'      => $sensor,
				'unidad'      => 'm',
				'ultimo'      => $ultimo,
				'min'         => round( $min, 3 ),
				'max'         => round( $max, 3 ),
				'rango'       => round( $max - $min, 3 ),
				'serie'       => $serie,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'IOC / VLIZ Sea Level (' . $sensor . ')',
			),
			$ttl,
			'mar'
		);

		return array(
			'ok'        => true,
			'registros' => count( $serie ),
			'mensaje'   => sprintf( '%s: último %.2f m (marea %.2f–%.2f m), sensor %s', $code, $ultimo['valor'], $min, $max, $sensor ),
		);
	}

	/**
	 * Submuestrea una serie a un máximo de N puntos conservando el último.
	 *
	 * @param array[] $serie Serie {hora, valor}.
	 * @param int     $max   Máximo de puntos.
	 * @return array[]
	 */
	private static function submuestrear( $serie, $max ) {
		$n = count( $serie );
		if ( $n <= $max ) {
			return $serie;
		}
		$paso = (int) ceil( $n / $max );
		$out  = array();
		for ( $i = 0; $i < $n; $i += $paso ) {
			$out[] = $serie[ $i ];
		}
		// Garantiza que el último punto (más reciente) esté presente.
		$ult = $serie[ $n - 1 ];
		if ( end( $out ) !== $ult ) {
			$out[] = $ult;
		}
		return $out;
	}
}
