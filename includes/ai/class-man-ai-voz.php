<?php
/**
 * Síntesis de voz con ElevenLabs para las respuestas del asistente.
 *
 * El audio se genera en el servidor (la clave nunca llega al navegador) y se
 * guarda en caché por texto: una misma respuesta no se vuelve a sintetizar, que
 * es donde se va la mayor parte del costo de esta API.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_AI_Voz {

	/** Prefijo de caché del audio ya sintetizado. */
	const CLAVE = 'ai_voz_';

	/** Vida del audio cacheado. */
	const TTL = DAY_IN_SECONDS;

	/** Longitud máxima a sintetizar (controla costo y latencia). */
	const MAX_TEXTO = 700;

	/**
	 * Convierte texto en audio.
	 *
	 * @param string $texto Texto a leer.
	 * @return array|\WP_Error {audio (base64), tipo}
	 */
	public static function sintetizar( $texto ) {
		if ( ! MAN_AI::voz_disponible() ) {
			return new \WP_Error( 'man_voz_inactiva', 'La respuesta hablada no está disponible.', array( 'status' => 503 ) );
		}

		$texto = trim( wp_strip_all_tags( (string) $texto ) );
		if ( '' === $texto ) {
			return new \WP_Error( 'man_voz_vacia', 'No hay texto que leer.', array( 'status' => 400 ) );
		}
		if ( mb_strlen( $texto ) > self::MAX_TEXTO ) {
			$texto = mb_substr( $texto, 0, self::MAX_TEXTO );
		}

		$cfg   = MAN_AI::config();
		$voz   = trim( (string) $cfg['voz_id'] );
		$clave = self::CLAVE . md5( $voz . '|' . $texto );

		$cache = MAN_Cache::get( $clave );
		if ( is_array( $cache ) && ! empty( $cache['audio'] ) ) {
			$cache['cacheada'] = true;
			return $cache;
		}

		// Cupo propio: la síntesis es más cara que el texto, y el audio sin
		// cachear se pediría una vez por visitante.
		if ( ! MAN_Security::rate_limit( 'ai_voz', 20, HOUR_IN_SECONDS ) ) {
			return new \WP_Error( 'man_voz_cupo', 'Demasiadas peticiones de audio. Intente más tarde.', array( 'status' => 429 ) );
		}

		$api = MAN_AI::descifrar( $cfg['voz_clave'] );
		if ( '' === $api ) {
			return new \WP_Error( 'man_voz_clave', 'La clave de voz guardada no se pudo descifrar. Vuelva a escribirla en el panel.', array( 'status' => 500 ) );
		}

		$resp = wp_remote_post(
			'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voz ),
			array(
				'timeout'    => 30,
				'headers'    => array(
					'xi-api-key'   => $api,
					'Content-Type' => 'application/json',
					'Accept'       => 'audio/mpeg',
				),
				'body'       => wp_json_encode(
					array(
						'text'     => $texto,
						'model_id' => ! empty( $cfg['voz_modelo'] ) ? $cfg['voz_modelo'] : 'eleven_multilingual_v2',
					)
				),
				'user-agent' => 'MonitorAmbientalNarino/' . MAN_VERSION,
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new \WP_Error( 'man_voz_red', 'No se pudo generar el audio.', array( 'status' => 502 ) );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		$cuerpo = wp_remote_retrieve_body( $resp );

		if ( $codigo < 200 || $codigo >= 300 ) {
			// ElevenLabs explica el motivo en JSON aunque se pida audio.
			$json    = json_decode( $cuerpo, true );
			$detalle = isset( $json['detail']['message'] ) ? $json['detail']['message'] : 'HTTP ' . $codigo;
			MAN_Sync::auditar( 'ai_voz', 'elevenlabs', 'error', 0, $detalle );
			return new \WP_Error( 'man_voz_proveedor', 'El servicio de voz respondió con un error (' . $codigo . ').', array( 'status' => 502 ) );
		}

		if ( '' === $cuerpo ) {
			return new \WP_Error( 'man_voz_vacio', 'El servicio de voz devolvió un audio vacío.', array( 'status' => 502 ) );
		}

		$salida = array(
			'audio'    => base64_encode( $cuerpo ),
			'tipo'     => 'audio/mpeg',
			'cacheada' => false,
		);
		MAN_Cache::set( $clave, $salida, self::TTL, 'ai' );
		return $salida;
	}

	/**
	 * Comprueba la clave y la voz configuradas (para el botón «Probar» del panel).
	 *
	 * @return array {ok, mensaje}
	 */
	public static function probar() {
		$cfg = MAN_AI::config();
		$api = MAN_AI::descifrar( $cfg['voz_clave'] );
		if ( '' === $api ) {
			return array( 'ok' => false, 'mensaje' => 'Falta la clave de ElevenLabs (o no se pudo descifrar).' );
		}

		$resp = wp_remote_get(
			'https://api.elevenlabs.io/v1/voices',
			array(
				'timeout' => 20,
				'headers' => array( 'xi-api-key' => $api ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'mensaje' => 'Sin conexión con ElevenLabs: ' . $resp->get_error_message() );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $resp );
		if ( 401 === $codigo ) {
			return array( 'ok' => false, 'mensaje' => 'La clave de ElevenLabs no es válida.' );
		}
		if ( $codigo < 200 || $codigo >= 300 ) {
			return array( 'ok' => false, 'mensaje' => 'ElevenLabs respondió HTTP ' . $codigo . '.' );
		}

		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) || empty( $json['voices'] ) ) {
			return array( 'ok' => false, 'mensaje' => 'No se pudo leer el catálogo de voces.' );
		}

		// Se comprueba que el ID configurado exista de verdad: una voz borrada
		// o de otra cuenta falla solo al sintetizar, ya ante el ciudadano.
		$buscada = trim( (string) $cfg['voz_id'] );
		$nombres = array();
		foreach ( $json['voices'] as $v ) {
			if ( isset( $v['voice_id'] ) && $v['voice_id'] === $buscada ) {
				return array(
					'ok'      => true,
					'mensaje' => 'Clave válida · voz «' . ( isset( $v['name'] ) ? $v['name'] : $buscada ) . '» encontrada.',
				);
			}
			if ( isset( $v['name'], $v['voice_id'] ) && count( $nombres ) < 5 ) {
				$nombres[] = $v['name'] . ' (' . $v['voice_id'] . ')';
			}
		}

		if ( '' === $buscada ) {
			return array(
				'ok'      => false,
				'mensaje' => 'Clave válida, pero falta el ID de voz. Disponibles: ' . implode( ', ', $nombres ),
			);
		}
		return array(
			'ok'      => false,
			'mensaje' => 'Clave válida, pero el ID de voz no existe en esta cuenta. Disponibles: ' . implode( ', ', $nombres ),
		);
	}
}
