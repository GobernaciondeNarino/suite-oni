<?php
/**
 * Conector del pronóstico OFICIAL de ENSO (consenso CPC/IRI publicado por
 * NOAA/CPC: probabilidades de El Niño / Neutral / La Niña para los próximos
 * trimestres). Complementa el modelo propio del plugin; NUNCA lo sustituye.
 *
 * Nota: el IRI dejó de publicar archivos de datos descargables; la fuente
 * primaria es la página oficial de probabilidades de NOAA/CPC. El parser es
 * tolerante (CSV / texto / HTML) y, si la fuente falla, se cae a la semilla.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Iri {

	/** Clave de caché del pronóstico oficial. */
	const CLAVE = 'enso_pronostico_oficial';

	/**
	 * Sincroniza el pronóstico oficial ENSO.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$url = ! empty( $cfg['url'] )
			? $cfg['url']
			: 'https://www.cpc.ncep.noaa.gov/products/analysis_monitoring/enso/roni/probabilities.php';
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 43200;

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return self::fallback( 'HTTP ' . $r['codigo'] . ' ' . $r['error'], $ttl );
		}

		$probs = MAN_Enso::parse_iri_probabilities( $r['cuerpo'] );
		if ( count( $probs ) < 1 ) {
			return self::fallback( 'Sin probabilidades parseables', $ttl );
		}

		$payload = array(
			'probabilidades' => $probs,
			'actualizado'    => current_time( 'mysql', true ),
			'fuente'         => 'NOAA/CPC (consenso CPC/IRI, oficial)',
			'estado'         => 'ok',
		);
		MAN_Cache::set( self::CLAVE, $payload, $ttl, 'enso' );

		return array(
			'ok'        => true,
			'registros' => count( $probs ),
			'mensaje'   => count( $probs ) . ' trimestres oficiales',
		);
	}

	/**
	 * Conserva la última caché durable o la semilla y marca mantenimiento.
	 *
	 * @param string $motivo Motivo del fallo.
	 * @param int    $ttl    TTL en segundos.
	 * @return array
	 */
	private static function fallback( $motivo, $ttl ) {
		$durable = MAN_Cache::get_durable( self::CLAVE );
		if ( is_array( $durable ) && ! empty( $durable['probabilidades'] ) ) {
			$payload = $durable;
		} else {
			$semilla = MAN_Cache::semilla( 'predicciones_elnino_narino_2026.json' );
			$payload = is_array( $semilla )
				? array( 'probabilidades' => self::probs_desde_semilla( $semilla ) )
				: array( 'probabilidades' => array() );
			$payload['fuente'] = 'semilla (fallback)';
		}
		$payload['estado']      = 'mantenimiento';
		$payload['actualizado'] = current_time( 'mysql', true );
		MAN_Cache::set( self::CLAVE, $payload, $ttl, 'enso' );

		return array( 'ok' => false, 'registros' => 0, 'mensaje' => $motivo . ' → fallback semilla' );
	}

	/**
	 * Extrae (best-effort) probabilidades trimestrales de la semilla, si las trae.
	 *
	 * @param array $semilla Semilla decodificada.
	 * @return array[]
	 */
	private static function probs_desde_semilla( $semilla ) {
		if ( ! empty( $semilla['probabilidades'] ) && is_array( $semilla['probabilidades'] ) ) {
			return $semilla['probabilidades'];
		}
		return array();
	}
}
