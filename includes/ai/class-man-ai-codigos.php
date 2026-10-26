<?php
/**
 * Catálogo de códigos de error internos del asistente.
 *
 * Cada fallo posible tiene un código estable (MAN-AI-xxx) que aparece en la
 * consola del navegador, en el registro del panel y en el mensaje al operador.
 * Así, cuando alguien reporta «no funciona», el código dice exactamente dónde
 * se rompió y qué hay que tocar, sin tener que reproducir el problema.
 *
 * Rangos:
 *   0xx configuración   · lo que falta o está mal puesto en el panel
 *   1xx red             · el servidor no logra salir a internet
 *   2xx proveedor       · el servicio de IA respondió con un error
 *   3xx respuesta       · respondió, pero no de forma utilizable
 *   4xx límites         · cupos y topes de gasto propios
 *   5xx voz             · ElevenLabs
 *   6xx navegador       · micrófono, audio y permisos del visitante
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_AI_Codigos {

	/** Prefijo visible de todos los códigos. */
	const PREFIJO = 'MAN-AI';

	/**
	 * Catálogo completo.
	 *
	 * 'que'  — qué ocurrió, en lenguaje de operador.
	 * 'haz'  — la acción concreta que lo resuelve.
	 * 'tipo' — config|red|proveedor|respuesta|limite|voz|navegador.
	 *
	 * @return array<string,array>
	 */
	public static function catalogo() {
		return array(
			// --- 0xx configuración ---
			'001' => array(
				'tipo' => 'config',
				'que'  => 'El módulo de IA está desactivado.',
				'haz'  => 'Marque «Activar» en Monitor Ambiental → Asistente IA y guarde.',
			),
			'002' => array(
				'tipo' => 'config',
				'que'  => 'No hay clave guardada para el proveedor seleccionado.',
				'haz'  => 'Pegue la clave del proveedor activo y guarde la configuración.',
			),
			'003' => array(
				'tipo' => 'config',
				'que'  => 'Hay una clave guardada pero no se puede descifrar.',
				'haz'  => 'Las sales de wp-config.php cambiaron desde que se guardó: vuelva a pegar la clave.',
			),
			'004' => array(
				'tipo' => 'config',
				'que'  => 'El modelo guardado no está en el catálogo del proveedor.',
				'haz'  => 'Elija un modelo del desplegable y guarde, o pulse «Detectar modelos» para ver los de su cuenta.',
			),
			'005' => array(
				'tipo' => 'config',
				'que'  => 'La extensión sodium de PHP no está disponible.',
				'haz'  => 'Sin sodium las claves no pueden cifrarse. Pida al hosting que la active.',
			),

			// --- 1xx red ---
			'101' => array(
				'tipo' => 'red',
				'que'  => 'El servidor no pudo conectar con el proveedor.',
				'haz'  => 'El hosting bloquea las conexiones salientes. Pida que permitan el dominio del proveedor, o revise WP_HTTP_BLOCK_EXTERNAL en wp-config.php.',
			),
			'102' => array(
				'tipo' => 'red',
				'que'  => 'El proveedor tardó más de lo permitido en responder.',
				'haz'  => 'Suele ser pasajero. Si se repite, pruebe un modelo más rápido.',
			),

			// --- 2xx proveedor ---
			'201' => array(
				'tipo' => 'proveedor',
				'que'  => 'El proveedor rechazó la clave (HTTP 401).',
				'haz'  => 'La clave no es válida o fue revocada. Genere una nueva y péguela en el panel.',
			),
			'202' => array(
				'tipo' => 'proveedor',
				'que'  => 'La clave no tiene permiso para este modelo (HTTP 403).',
				'haz'  => 'Revise el plan de la cuenta o elija otro modelo.',
			),
			'203' => array(
				'tipo' => 'proveedor',
				'que'  => 'El modelo no existe para esta cuenta o el proveedor lo retiró (HTTP 404).',
				'haz'  => 'Pulse «Detectar modelos» para ver los que admite su cuenta, elija uno y guarde.',
			),
			'204' => array(
				'tipo' => 'proveedor',
				'que'  => 'La cuenta superó su límite o se quedó sin saldo (HTTP 429).',
				'haz'  => 'Revise el saldo y los límites en el panel del proveedor.',
			),
			'205' => array(
				'tipo' => 'proveedor',
				'que'  => 'El proveedor rechazó la petición (HTTP 400).',
				'haz'  => 'El detalle del registro dice qué parámetro no aceptó.',
			),
			'206' => array(
				'tipo' => 'proveedor',
				'que'  => 'Avería temporal del proveedor (HTTP 5xx).',
				'haz'  => 'No es un problema de configuración. Reintente en unos minutos.',
			),

			// --- 3xx respuesta ---
			'301' => array(
				'tipo' => 'respuesta',
				'que'  => 'El proveedor respondió algo que no es JSON.',
				'haz'  => 'Suele ser una página de error intermedia. Mire la respuesta cruda en el registro.',
			),
			'302' => array(
				'tipo' => 'respuesta',
				'que'  => 'El proveedor respondió sin texto utilizable.',
				'haz'  => 'Reintente. Si persiste, pruebe otro modelo.',
			),
			'303' => array(
				'tipo' => 'respuesta',
				'que'  => 'El modelo declinó responder a esa consulta.',
				'haz'  => 'No es un fallo: sus filtros de seguridad rechazaron la pregunta.',
			),
			'304' => array(
				'tipo' => 'respuesta',
				'que'  => 'La respuesta se cortó: el modelo agotó el tope de tokens razonando.',
				'haz'  => 'Suele ser una pregunta muy abierta. Si se repite, elija un modelo más rápido.',
			),

			// --- 4xx límites propios ---
			'401' => array(
				'tipo' => 'limite',
				'que'  => 'El visitante agotó su cupo de consultas por hora.',
				'haz'  => 'Es el comportamiento esperado. Suba el cupo si lo considera bajo.',
			),
			'402' => array(
				'tipo' => 'limite',
				'que'  => 'Se alcanzó el tope diario de consultas del sitio.',
				'haz'  => 'Suba el tope diario o espere al día siguiente.',
			),
			'403' => array(
				'tipo' => 'limite',
				'que'  => 'La pregunta llegó vacía.',
				'haz'  => 'Sin acción: el visitante envió el formulario sin escribir.',
			),

			// --- 5xx voz ---
			'501' => array(
				'tipo' => 'voz',
				'que'  => 'La respuesta hablada está desactivada o incompleta.',
				'haz'  => 'Active la voz y complete clave e ID en el panel.',
			),
			'502' => array(
				'tipo' => 'voz',
				'que'  => 'La clave de ElevenLabs no se puede descifrar.',
				'haz'  => 'Vuelva a pegar la clave de ElevenLabs y guarde.',
			),
			'503' => array(
				'tipo' => 'voz',
				'que'  => 'ElevenLabs rechazó la petición de audio.',
				'haz'  => 'Compruebe clave, ID de voz y saldo con «Probar clave y voz».',
			),
			'504' => array(
				'tipo' => 'voz',
				'que'  => 'El ID de voz no existe en la cuenta.',
				'haz'  => 'Copie el ID correcto desde su biblioteca de voces.',
			),

			// --- 6xx navegador ---
			'601' => array(
				'tipo' => 'navegador',
				'que'  => 'El navegador no admite dictado por voz.',
				'haz'  => 'Sin acción: Firefox y iOS no lo implementan. El botón se oculta y queda el texto.',
			),
			'602' => array(
				'tipo' => 'navegador',
				'que'  => 'El visitante denegó el permiso del micrófono.',
				'haz'  => 'Sin acción: debe concederlo desde los ajustes del navegador.',
			),
			'603' => array(
				'tipo' => 'navegador',
				'que'  => 'El navegador no pudo reproducir el audio.',
				'haz'  => 'Algunos navegadores exigen una interacción previa para reproducir sonido.',
			),
			'604' => array(
				'tipo' => 'navegador',
				'que'  => 'No se pudo contactar con la REST del propio sitio.',
				'haz'  => 'Revise que /wp-json/man/v1/ responda y que ningún plugin de seguridad lo bloquee.',
			),
			'605' => array(
				'tipo' => 'navegador',
				'que'  => 'El sitio no respondió dentro del tiempo de espera del navegador (45 s).',
				'haz'  => 'Revise el registro de errores: suele ser un proveedor lento (MAN-AI-102) o un hosting saturado.',
			),
		);
	}

	/**
	 * Código completo listo para mostrar, p. ej. «MAN-AI-201».
	 *
	 * @param string $num Número de tres cifras.
	 * @return string
	 */
	public static function codigo( $num ) {
		return self::PREFIJO . '-' . $num;
	}

	/**
	 * Ficha de un código.
	 *
	 * @param string $num Número.
	 * @return array {codigo, tipo, que, haz}
	 */
	public static function ficha( $num ) {
		$cat = self::catalogo();
		if ( ! isset( $cat[ $num ] ) ) {
			return array(
				'codigo' => self::codigo( $num ),
				'tipo'   => 'desconocido',
				'que'    => 'Error no catalogado.',
				'haz'    => '',
			);
		}
		return array_merge( array( 'codigo' => self::codigo( $num ) ), $cat[ $num ] );
	}

	/**
	 * Traduce un código HTTP del proveedor al código interno correspondiente.
	 *
	 * @param int $http Código HTTP.
	 * @return string Número de código interno.
	 */
	public static function desde_http( $http ) {
		$http = (int) $http;
		if ( 401 === $http ) {
			return '201';
		}
		if ( 403 === $http ) {
			return '202';
		}
		if ( 404 === $http ) {
			return '203';
		}
		if ( 429 === $http ) {
			return '204';
		}
		if ( 400 === $http ) {
			return '205';
		}
		if ( $http >= 500 ) {
			return '206';
		}
		return '205';
	}

	/**
	 * Catálogo en la forma que consume el JavaScript del front.
	 *
	 * @return array<string,array>
	 */
	public static function para_front() {
		$out = array();
		foreach ( self::catalogo() as $num => $f ) {
			$out[ $num ] = array(
				'codigo' => self::codigo( $num ),
				'tipo'   => $f['tipo'],
				'que'    => $f['que'],
				'haz'    => $f['haz'],
			);
		}
		return $out;
	}
}
