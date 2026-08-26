<?php
/**
 * Conector SIVIGILA / INS vía datos.gov.co (Sección 3.7) — eventos de salud
 * pública sensibles al clima en Nariño. Datos agregados, nunca individuales.
 *
 * Dataset «Eventos de notificación obligatoria» (4hyg-wa9d): una fila por
 * evento, año, semana epidemiológica y municipio de ocurrencia, con el conteo
 * de casos. Cubre 2007–2022 y se filtra por `cod_dpto_o=52` (Nariño).
 *
 * La agregación se hace en el servidor con SoQL ($select/$group), de modo que
 * cada sincronización trae unos pocos kilobytes en lugar de las ~100.000 filas
 * del departamento.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Sivigila {

	/** Clave de caché del payload de salud. */
	const CLAVE = 'sivigila_eventos';

	/** Identificador del dataset en datos.gov.co. */
	const DATASET = '4hyg-wa9d';

	/** Código DANE del departamento de Nariño. */
	const DEPARTAMENTO = '52';

	/** Años de la ventana «reciente» del ranking municipal. */
	const VENTANA_MUNICIPIOS = 5;

	/**
	 * Catálogo de eventos vigilados, por código SIVIGILA.
	 *
	 * grupo: ETV (transmitidas por vectores) o ETA (por agua y alimentos).
	 * corto: etiqueta legible para las gráficas.
	 * letal: el evento cuenta muertes, no casos (no se suma a la incidencia).
	 *
	 * @return array<string,array>
	 */
	public static function eventos() {
		return array(
			// --- ETV: el calor acelera la reproducción del vector y acorta el
			// ciclo de incubación; la sequía multiplica los criaderos por el
			// almacenamiento doméstico de agua.
			'210' => array( 'grupo' => 'ETV', 'corto' => 'Dengue',                 'nombre' => 'DENGUE' ),
			'220' => array( 'grupo' => 'ETV', 'corto' => 'Dengue grave',           'nombre' => 'DENGUE GRAVE' ),
			'580' => array( 'grupo' => 'ETV', 'corto' => 'Mortalidad por dengue',  'nombre' => 'MORTALIDAD POR DENGUE', 'letal' => true ),
			'217' => array( 'grupo' => 'ETV', 'corto' => 'Chikunguña',             'nombre' => 'CHIKUNGUNYA' ),
			'895' => array( 'grupo' => 'ETV', 'corto' => 'Zika',                   'nombre' => 'ZIKA' ),
			'420' => array( 'grupo' => 'ETV', 'corto' => 'Leishmaniasis cutánea',  'nombre' => 'LEISHMANIASIS CUTANEA' ),
			'430' => array( 'grupo' => 'ETV', 'corto' => 'Leishmaniasis mucosa',   'nombre' => 'LEISHMANIASIS MUCOSA' ),
			'470' => array( 'grupo' => 'ETV', 'corto' => 'Malaria falcíparum',     'nombre' => 'MALARIA FALCIPARUM' ),
			'490' => array( 'grupo' => 'ETV', 'corto' => 'Malaria vivax',          'nombre' => 'MALARIA VIVAX' ),
			'480' => array( 'grupo' => 'ETV', 'corto' => 'Malaria malariae',       'nombre' => 'MALARIA MALARIE' ),
			'460' => array( 'grupo' => 'ETV', 'corto' => 'Malaria mixta',          'nombre' => 'MALARIA ASOCIADA (FORMAS MIXTAS)' ),
			'495' => array( 'grupo' => 'ETV', 'corto' => 'Malaria complicada',     'nombre' => 'MALARIA COMPLICADA' ),
			'540' => array( 'grupo' => 'ETV', 'corto' => 'Mortalidad por malaria', 'nombre' => 'MORTALIDAD POR MALARIA', 'letal' => true ),

			// --- ETA: la sequía reduce el agua potable y las inundaciones
			// contaminan las fuentes hídricas.
			'320' => array( 'grupo' => 'ETA', 'corto' => 'Fiebre tifoidea',        'nombre' => 'FIEBRE TIFOIDEA Y PARATIFOIDEA' ),
			'330' => array( 'grupo' => 'ETA', 'corto' => 'Hepatitis A',            'nombre' => 'HEPATITIS A' ),
		);
	}

	/**
	 * Etiqueta larga de un grupo.
	 *
	 * @param string $grupo ETV|ETA.
	 * @return string
	 */
	public static function nombre_grupo( $grupo ) {
		return ( 'ETA' === $grupo )
			? 'Transmitidas por agua y alimentos'
			: 'Transmitidas por vectores';
	}

	/**
	 * Sincroniza los eventos sensibles al clima de Nariño.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$ds   = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : self::DATASET;
		$base = ! empty( $cfg['url'] ) ? rtrim( $cfg['url'], '/' ) . '/' : 'https://www.datos.gov.co/resource/';
		// Verificación TLS activa por defecto: datos.gov.co presenta un
		// certificado válido; desactivarla solo si el panel lo indica.
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 43200;

		$recurso = $base . rawurlencode( $ds ) . '.json';
		$eventos = self::eventos();
		$filtro  = self::filtro_eventos( $eventos );

		// 1) Serie anual por evento (base de todo lo demás).
		$serie = self::consultar(
			$recurso,
			array(
				'$select' => 'ano,cod_eve,sum(conteo) as casos',
				'$where'  => $filtro,
				'$group'  => 'ano,cod_eve',
				'$order'  => 'ano',
				'$limit'  => '2000',
			),
			$ssl
		);
		if ( ! $serie['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => $serie['mensaje'] );
		}
		if ( ! $serie['filas'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Sin registros para Nariño en el dataset ' . $ds );
		}

		$anios = self::anios( $serie['filas'] );
		$desde = $anios ? min( $anios ) : '';
		$hasta = $anios ? max( $anios ) : '';

		// 2) Ranking municipal de la ventana reciente.
		$corte = $hasta ? (string) ( (int) $hasta - self::VENTANA_MUNICIPIOS + 1 ) : '';
		$muni  = self::consultar(
			$recurso,
			array(
				'$select' => 'cod_mun_o,municipio_ocurrencia,cod_eve,sum(conteo) as casos',
				'$where'  => $filtro . ( $corte ? " AND ano>='" . $corte . "'" : '' ),
				'$group'  => 'cod_mun_o,municipio_ocurrencia,cod_eve',
				'$limit'  => '5000',
			),
			$ssl
		);

		// 3) Estacionalidad por semana epidemiológica (todo el histórico).
		$semanal = self::consultar(
			$recurso,
			array(
				'$select' => 'semana,cod_eve,sum(conteo) as casos',
				'$where'  => $filtro,
				'$group'  => 'semana,cod_eve',
				'$order'  => 'semana',
				'$limit'  => '2000',
			),
			$ssl
		);

		$payload = array(
			'anual'         => self::agrupar_anual( $serie['filas'], $eventos ),
			'por_evento'    => self::agrupar_evento( $serie['filas'], $eventos ),
			'municipios'    => $muni['ok'] ? self::agrupar_municipio( $muni['filas'], $eventos ) : array(),
			'estacional'    => $semanal['ok'] ? self::agrupar_semana( $semanal['filas'], $eventos ) : array(),
			'cobertura'     => array(
				'desde'             => $desde,
				'hasta'             => $hasta,
				'ventana_municipal' => $corte ? $corte . '–' . $hasta : '',
			),
			'dataset'       => $ds,
			'actualizado'   => current_time( 'mysql', true ),
			'fuente'        => 'INS / SIVIGILA vía datos.gov.co',
			'estado'        => 'ok',
		);

		MAN_Cache::set( self::CLAVE, $payload, $ttl, 'salud' );

		$total = 0;
		foreach ( $payload['anual'] as $fila ) {
			$total += (int) $fila['ETV'] + (int) $fila['ETA'];
		}

		return array(
			'ok'        => true,
			'registros' => count( $payload['anual'] ),
			'mensaje'   => number_format_i18n( $total ) . ' casos · ' . $desde . '–' . $hasta,
		);
	}

	/* ----------------------------------------------------------------- */
	/* Consulta                                                          */
	/* ----------------------------------------------------------------- */

	/**
	 * Cláusula WHERE con los códigos de evento vigilados.
	 *
	 * Se filtra por código y no por nombre: los rótulos de datos.gov.co varían
	 * en tildes y mayúsculas entre vigencias, los códigos no.
	 *
	 * @param array $eventos Catálogo.
	 * @return string
	 */
	private static function filtro_eventos( $eventos ) {
		$codigos = array();
		foreach ( array_keys( $eventos ) as $cod ) {
			$codigos[] = "'" . $cod . "'";
		}
		return "cod_dpto_o='" . self::DEPARTAMENTO . "' AND cod_eve in(" . implode( ',', $codigos ) . ')';
	}

	/**
	 * Ejecuta una consulta SoQL y decodifica la respuesta.
	 *
	 * @param string $recurso URL del recurso .json.
	 * @param array  $params  Parámetros SoQL.
	 * @param bool   $ssl     Verificar certificado.
	 * @return array {ok, filas, mensaje}
	 */
	private static function consultar( $recurso, $params, $ssl ) {
		$url = add_query_arg( array_map( 'rawurlencode', $params ), $recurso );
		$r   = MAN_Sync::http_get( $url, $ssl, array( 'timeout' => 30 ) );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'filas' => array(), 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}
		$json = json_decode( $r['cuerpo'], true );
		if ( ! is_array( $json ) ) {
			return array( 'ok' => false, 'filas' => array(), 'mensaje' => 'JSON inválido de datos.gov.co' );
		}
		// Socrata devuelve {error:true, message:…} con HTTP 200 en consultas mal
		// formadas: sin esta comprobación se cachearía un error como si fuesen datos.
		if ( isset( $json['error'] ) ) {
			$msg = isset( $json['message'] ) ? (string) $json['message'] : 'consulta rechazada';
			return array( 'ok' => false, 'filas' => array(), 'mensaje' => 'SoQL: ' . $msg );
		}
		return array( 'ok' => true, 'filas' => $json, 'mensaje' => '' );
	}

	/* ----------------------------------------------------------------- */
	/* Normalización                                                     */
	/* ----------------------------------------------------------------- */

	/**
	 * Años presentes en la serie.
	 *
	 * @param array $filas Filas crudas.
	 * @return string[]
	 */
	private static function anios( $filas ) {
		$out = array();
		foreach ( $filas as $f ) {
			if ( isset( $f['ano'] ) && preg_match( '/^\d{4}$/', (string) $f['ano'] ) ) {
				$out[ (string) $f['ano'] ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Casos por año y grupo: [{anio, ETV, ETA, muertes_dengue, muertes_malaria}].
	 *
	 * Las muertes se separan de la incidencia para no sumar personas fallecidas
	 * a los casos notificados.
	 *
	 * @param array $filas   Filas crudas {ano, cod_eve, casos}.
	 * @param array $eventos Catálogo.
	 * @return array[]
	 */
	private static function agrupar_anual( $filas, $eventos ) {
		$acc = array();
		foreach ( $filas as $f ) {
			$anio = isset( $f['ano'] ) ? (string) $f['ano'] : '';
			$cod  = isset( $f['cod_eve'] ) ? (string) $f['cod_eve'] : '';
			if ( '' === $anio || ! isset( $eventos[ $cod ] ) ) {
				continue;
			}
			if ( ! isset( $acc[ $anio ] ) ) {
				$acc[ $anio ] = array(
					'anio'            => $anio,
					'ETV'             => 0,
					'ETA'             => 0,
					'muertes_dengue'  => 0,
					'muertes_malaria' => 0,
				);
			}
			$casos = (int) round( (float) ( isset( $f['casos'] ) ? $f['casos'] : 0 ) );
			if ( ! empty( $eventos[ $cod ]['letal'] ) ) {
				$clave                  = ( '580' === $cod ) ? 'muertes_dengue' : 'muertes_malaria';
				$acc[ $anio ][ $clave ] += $casos;
				continue;
			}
			$acc[ $anio ][ $eventos[ $cod ]['grupo'] ] += $casos;
		}
		ksort( $acc );
		return array_values( $acc );
	}

	/**
	 * Casos por evento y año: [{anio, evento, grupo, casos, letal}].
	 *
	 * @param array $filas   Filas crudas.
	 * @param array $eventos Catálogo.
	 * @return array[]
	 */
	private static function agrupar_evento( $filas, $eventos ) {
		$out = array();
		foreach ( $filas as $f ) {
			$cod = isset( $f['cod_eve'] ) ? (string) $f['cod_eve'] : '';
			if ( ! isset( $eventos[ $cod ] ) ) {
				continue;
			}
			$out[] = array(
				'anio'   => isset( $f['ano'] ) ? (string) $f['ano'] : '',
				'codigo' => $cod,
				'evento' => $eventos[ $cod ]['corto'],
				'grupo'  => $eventos[ $cod ]['grupo'],
				'letal'  => ! empty( $eventos[ $cod ]['letal'] ),
				'casos'  => (int) round( (float) ( isset( $f['casos'] ) ? $f['casos'] : 0 ) ),
			);
		}
		return $out;
	}

	/**
	 * Casos por municipio: [{divipola, municipio, ETV, ETA, total}].
	 *
	 * Solo se conservan los 64 municipios reconocidos por el plugin, para que
	 * el mapa y la lista blanca de DIVIPOLA sigan coincidiendo.
	 *
	 * @param array $filas   Filas crudas.
	 * @param array $eventos Catálogo.
	 * @return array[]
	 */
	private static function agrupar_municipio( $filas, $eventos ) {
		$acc = array();
		foreach ( $filas as $f ) {
			$cod  = isset( $f['cod_eve'] ) ? (string) $f['cod_eve'] : '';
			$divi = isset( $f['cod_mun_o'] ) ? preg_replace( '/[^0-9]/', '', (string) $f['cod_mun_o'] ) : '';
			if ( ! isset( $eventos[ $cod ] ) || ! MAN_Municipios::existe( $divi ) ) {
				continue;
			}
			if ( ! isset( $acc[ $divi ] ) ) {
				$mun          = MAN_Municipios::por_divipola( $divi );
				$acc[ $divi ] = array(
					'divipola'  => $divi,
					'municipio' => $mun ? $mun['nombre'] : $divi,
					'subregion' => $mun ? $mun['subregion'] : '',
					'ETV'       => 0,
					'ETA'       => 0,
					'total'     => 0,
				);
			}
			$casos = (int) round( (float) ( isset( $f['casos'] ) ? $f['casos'] : 0 ) );
			if ( ! empty( $eventos[ $cod ]['letal'] ) ) {
				continue; // Las muertes no engrosan la incidencia municipal.
			}
			$acc[ $divi ][ $eventos[ $cod ]['grupo'] ] += $casos;
			$acc[ $divi ]['total']                     += $casos;
		}
		$out = array_values( $acc );
		usort(
			$out,
			function ( $a, $b ) {
				return (int) $b['total'] - (int) $a['total'];
			}
		);
		return $out;
	}

	/**
	 * Perfil por semana epidemiológica: [{semana, ETV, ETA}].
	 *
	 * Acumula todo el histórico, así que describe la estacionalidad típica del
	 * departamento, no un año concreto.
	 *
	 * @param array $filas   Filas crudas.
	 * @param array $eventos Catálogo.
	 * @return array[]
	 */
	private static function agrupar_semana( $filas, $eventos ) {
		$acc = array();
		foreach ( $filas as $f ) {
			$cod    = isset( $f['cod_eve'] ) ? (string) $f['cod_eve'] : '';
			$semana = (int) ( isset( $f['semana'] ) ? $f['semana'] : 0 );
			if ( ! isset( $eventos[ $cod ] ) || $semana < 1 || $semana > 53 ) {
				continue;
			}
			if ( ! empty( $eventos[ $cod ]['letal'] ) ) {
				continue;
			}
			if ( ! isset( $acc[ $semana ] ) ) {
				$acc[ $semana ] = array( 'semana' => $semana, 'ETV' => 0, 'ETA' => 0 );
			}
			$acc[ $semana ][ $eventos[ $cod ]['grupo'] ] += (int) round( (float) ( isset( $f['casos'] ) ? $f['casos'] : 0 ) );
		}
		ksort( $acc );
		return array_values( $acc );
	}
}
