<?php
/**
 * Conector NOAA/CPC — índice ONI y Niño 3.4 (Sección 3.2).
 * Descarga archivos de texto plano, los parsea y cachea la serie normalizada.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Oni {

	/**
	 * Sincroniza ONI + Niño 3.4.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$url = ! empty( $cfg['url'] ) ? $cfg['url'] : 'https://www.cpc.ncep.noaa.gov/data/indices/oni.ascii.txt';
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 43200;

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$filas = MAN_Enso::parse_oni_ascii( $r['cuerpo'] );
		if ( count( $filas ) < 12 ) {
			return array( 'ok' => false, 'registros' => count( $filas ), 'mensaje' => 'ONI parseado insuficiente' );
		}

		$recientes = array_slice( $filas, -36 );
		$actual    = end( $filas );
		$serie_oni = wp_list_pluck( $filas, 'oni' );

		$serie = array();
		foreach ( $recientes as $f ) {
			$serie[] = array(
				'mes'  => $f['mes'],
				'oni'  => $f['oni'],
				'fase' => MAN_Enso::clasificar_fase( $f['oni'] ),
			);
		}

		$payload = array(
			'actual'           => array(
				'mes'        => $actual['mes'],
				'oni'        => $actual['oni'],
				'fase'       => MAN_Enso::clasificar_fase( $actual['oni'] ),
				'intensidad' => MAN_Enso::intensidad( $actual['oni'] ),
			),
			'serie'            => $serie,
			'episodio_vigente' => MAN_Enso::es_episodio( array_slice( $serie_oni, -8 ) ),
			'actualizado'      => current_time( 'mysql', true ),
			'fuente'           => 'NOAA/CPC ONI',
		);

		// Niño 3.4 semanal (pulso del semáforo).
		if ( ! empty( $cfg['url_aux'] ) ) {
			$ra = MAN_Sync::http_get( $cfg['url_aux'], $ssl );
			if ( $ra['ok'] ) {
				$n34 = MAN_Enso::parse_wksst_nino34( $ra['cuerpo'] );
				if ( $n34 ) {
					$payload['nino34'] = $n34;
				}
			}
		}

		MAN_Cache::set( 'oni', $payload, $ttl, 'enso' );

		return array(
			'ok'        => true,
			'registros' => count( $recientes ),
			'mensaje'   => 'ONI ' . $actual['mes'] . ' = ' . $actual['oni'],
		);
	}
}
