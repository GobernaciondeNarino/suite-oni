<?php
/**
 * Análisis ENSO: clasificación de fase e intensidad, parseo de los archivos
 * oficiales de NOAA/CPC (ONI ASCII y Niño 3.4 semanal) y detección de
 * episodios (Sección 5.1 de la especificación).
 *
 * Umbrales NOAA: ONI >= +0.5 °C = El Niño; <= -0.5 °C = La Niña; resto Neutral.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Enso {

	/**
	 * Clasifica la fase ENSO según el ONI.
	 *
	 * @param float $oni Índice oceánico de El Niño.
	 * @return string 'El Niño' | 'La Niña' | 'Neutral'
	 */
	public static function clasificar_fase( $oni ) {
		$oni = (float) $oni;
		if ( $oni >= 0.5 ) {
			return 'El Niño';
		}
		if ( $oni <= -0.5 ) {
			return 'La Niña';
		}
		return 'Neutral';
	}

	/**
	 * Etiqueta de intensidad según |ONI| (umbrales NOAA).
	 *
	 * @param float $oni ONI.
	 * @return string
	 */
	public static function intensidad( $oni ) {
		$a = abs( (float) $oni );
		if ( $a < 0.5 ) {
			return 'sin intensidad';
		}
		if ( $a <= 0.9 ) {
			return 'débil';
		}
		if ( $a <= 1.4 ) {
			return 'moderado';
		}
		if ( $a <= 1.9 ) {
			return 'fuerte';
		}
		return 'muy fuerte';
	}

	/**
	 * Color semántico universal de la fase (cálido rojo / frío azul / neutral).
	 *
	 * @param float $oni ONI.
	 * @return string Hex.
	 */
	public static function color_fase( $oni ) {
		$oni = (float) $oni;
		if ( $oni >= 0.5 ) {
			return '#c62828'; // El Niño — cálido
		}
		if ( $oni <= -0.5 ) {
			return '#1565c0'; // La Niña — frío
		}
		return '#2e7d32'; // Neutral
	}

	/**
	 * Parsea el archivo oni.ascii.txt de NOAA (columnas SEAS YR TOTAL ANOM).
	 *
	 * @param string $texto Contenido del archivo.
	 * @return array[] Filas {seas, anio, mes, total, oni}.
	 */
	public static function parse_oni_ascii( $texto ) {
		$filas  = array();
		$lineas = preg_split( '/\r\n|\r|\n/', (string) $texto );

		foreach ( $lineas as $linea ) {
			$linea = trim( $linea );
			if ( '' === $linea || 0 === stripos( $linea, 'SEAS' ) ) {
				continue; // encabezado o vacío
			}
			$cols = preg_split( '/\s+/', $linea );
			if ( count( $cols ) < 4 || ! preg_match( '/^[A-Z]{3}$/', $cols[0] ) ) {
				continue;
			}
			$seas = strtoupper( $cols[0] );
			$anio = (int) $cols[1];
			$filas[] = array(
				'seas'  => $seas,
				'anio'  => $anio,
				'mes'   => self::seas_a_mes( $seas, $anio ),
				'total' => (float) $cols[2],
				'oni'   => (float) $cols[3],
			);
		}
		return $filas;
	}

	/**
	 * Convierte un trimestre móvil (DJF…NDJ) a su mes central AAAA-MM.
	 *
	 * @param string $seas Trimestre de 3 letras.
	 * @param int    $anio Año.
	 * @return string AAAA-MM.
	 */
	public static function seas_a_mes( $seas, $anio ) {
		$map = array(
			'DJF' => 1, 'JFM' => 2, 'FMA' => 3, 'MAM' => 4,
			'AMJ' => 5, 'MJJ' => 6, 'JJA' => 7, 'JAS' => 8,
			'ASO' => 9, 'SON' => 10, 'OND' => 11, 'NDJ' => 12,
		);
		$seas = strtoupper( $seas );
		$mes  = isset( $map[ $seas ] ) ? $map[ $seas ] : 1;
		return sprintf( '%04d-%02d', (int) $anio, $mes );
	}

	/**
	 * Extrae la anomalía Niño 3.4 más reciente de wksst8110.for.
	 *
	 * @param string $texto Contenido del archivo (ancho fijo).
	 * @return array|null {fecha, nino34_anom} o null si no se pudo parsear.
	 */
	public static function parse_wksst_nino34( $texto ) {
		$lineas = preg_split( '/\r\n|\r|\n/', (string) $texto );
		$ultima = null;
		foreach ( $lineas as $linea ) {
			if ( preg_match( '/^\s*\d{2}[A-Z]{3}\d{4}/', $linea ) ) {
				$ultima = $linea;
			}
		}
		if ( null === $ultima ) {
			return null;
		}
		// Pares (SST, ANOM) por región: Niño1+2, Niño3, Niño3.4, Niño4.
		if ( preg_match_all( '/-?\d+\.\d/', $ultima, $m ) && count( $m[0] ) >= 6 ) {
			return array(
				'fecha'       => substr( trim( $ultima ), 0, 9 ),
				'nino34_anom' => (float) $m[0][5], // ANOM de la región 3.4
			);
		}
		return null;
	}

	/**
	 * ¿La serie contiene un episodio oficial (umbral superado en 5 trimestres
	 * consecutivos del mismo signo)?
	 *
	 * @param float[] $serie ONI en orden cronológico.
	 * @return bool
	 */
	public static function es_episodio( array $serie ) {
		$run   = 0;
		$signo = 0;
		foreach ( $serie as $v ) {
			$v = (float) $v;
			if ( abs( $v ) >= 0.5 ) {
				$s = $v > 0 ? 1 : -1;
				if ( $s === $signo ) {
					$run++;
				} else {
					$signo = $s;
					$run   = 1;
				}
				if ( $run >= 5 ) {
					return true;
				}
			} else {
				$run   = 0;
				$signo = 0;
			}
		}
		return false;
	}

	/**
	 * Parsea las probabilidades ENSO oficiales (NOAA/CPC, consenso CPC/IRI).
	 *
	 * Tolera tres formas de la misma tabla: CSV, tabla de texto plano y HTML.
	 * Cada fila válida es «trimestre (3 letras + año)» seguido de tres
	 * porcentajes en el orden El Niño, Neutral, La Niña.
	 *
	 * @param string $texto Cuerpo (CSV, texto o HTML).
	 * @return array[] Filas {season, el_nino, neutral, la_nina} en % (0..100).
	 */
	public static function parse_iri_probabilities( $texto ) {
		$texto = (string) $texto;

		// Inserta saltos de línea en límites de fila/celda para no fundir la
		// tabla en una sola línea, y elimina el resto del marcado.
		$texto = preg_replace( '/<\s*(tr|table|thead|tbody|br|p|div|li)[^>]*>/i', "\n", $texto );
		$texto = preg_replace( '/<\s*td[^>]*>/i', ' ', $texto );
		$texto = wp_strip_all_tags( $texto );
		$texto = html_entity_decode( $texto, ENT_QUOTES, 'UTF-8' );

		$filas  = array();
		$lineas = preg_split( '/\r\n|\r|\n/', $texto );
		foreach ( $lineas as $linea ) {
			$linea = trim( preg_replace( '/\s+/', ' ', $linea ) );
			if ( '' === $linea ) {
				continue;
			}
			// season = 3 letras + año (4 díg.); luego 3 enteros 0..100.
			if ( preg_match( '/\b([A-Za-z]{3})\s*(\d{4})\D+(\d{1,3})\D+(\d{1,3})\D+(\d{1,3})\b/', $linea, $m ) ) {
				$en = (float) $m[3];
				$ne = (float) $m[4];
				$ln = (float) $m[5];
				// Descarta líneas espurias cuya suma no se acerca a 100.
				if ( ( $en + $ne + $ln ) < 80 || ( $en + $ne + $ln ) > 120 ) {
					continue;
				}
				$filas[] = array(
					'season'  => strtoupper( $m[1] ) . ' ' . $m[2],
					'el_nino' => $en,
					'neutral' => $ne,
					'la_nina' => $ln,
				);
			}
		}
		return $filas;
	}
}
