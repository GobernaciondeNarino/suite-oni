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
	 * Trigramas ENSO en orden, con el mes central de cada trimestre solapado.
	 * DJF centra en enero (1) … NDJ centra en diciembre (12).
	 *
	 * @var array<string,int>
	 */
	const TRIMESTRES = array(
		'DJF' => 1,
		'JFM' => 2,
		'FMA' => 3,
		'MAM' => 4,
		'AMJ' => 5,
		'MJJ' => 6,
		'JJA' => 7,
		'JAS' => 8,
		'ASO' => 9,
		'SON' => 10,
		'OND' => 11,
		'NDJ' => 12,
	);

	/**
	 * Parsea las probabilidades ENSO oficiales (NOAA/CPC, consenso CPC/IRI).
	 *
	 * Tolera CSV, texto plano y el HTML de la página oficial. La tabla real de
	 * NOAA/CPC publica el trimestre SIN año («JAS Jul Aug Sep | 0 | 0 | 100») y
	 * en el orden de columnas La Niña · Neutral · El Niño, por lo que:
	 *
	 * - el orden de las tres columnas se deduce de la fila de cabecera y solo
	 *   se asume el orden oficial cuando no hay cabecera reconocible;
	 * - el año de cada trimestre se infiere del mes de emisión («Issued August
	 *   2026») o, en su defecto, del mes en curso, avanzando un mes por fila.
	 *
	 * @param string $texto Cuerpo (CSV, texto o HTML).
	 * @param string $ahora Mes de referencia 'Y-m' (por defecto, el actual UTC).
	 * @return array[] Filas {season, el_nino, neutral, la_nina} en % (0..100).
	 */
	public static function parse_iri_probabilities( $texto, $ahora = '' ) {
		$texto = (string) $texto;

		// Inserta saltos de línea en límites de fila/celda para no fundir la
		// tabla en una sola línea, y elimina el resto del marcado.
		$plano = preg_replace( '/<\s*(tr|table|thead|tbody|br|p|div|li)[^>]*>/i', "\n", $texto );
		$plano = preg_replace( '/<\s*t[dh][^>]*>/i', ' | ', $plano );
		$plano = wp_strip_all_tags( $plano );
		$plano = html_entity_decode( $plano, ENT_QUOTES, 'UTF-8' );

		// Orden de columnas: manda la cabecera si es reconocible; si no, se usa
		// el orden propio de cada formato (ver orden_por_defecto()).
		$orden_cab  = self::orden_columnas( $plano );
		$referencia = self::mes_emision( $plano, $ahora );

		$filas    = array();
		$anterior = null;
		$lineas   = preg_split( '/\r\n|\r|\n/', $plano );
		foreach ( $lineas as $linea ) {
			$linea = trim( preg_replace( '/\s+/', ' ', $linea ) );
			if ( '' === $linea ) {
				continue;
			}

			$season = null;
			$nums   = null;
			$con_anio = false;

			// Formato con año explícito (archivos históricos del IRI).
			if ( preg_match( '/\b([A-Za-z]{3})\s*(\d{4})\D+(\d{1,3})\D+(\d{1,3})\D+(\d{1,3})\b/', $linea, $m )
				&& isset( self::TRIMESTRES[ strtoupper( $m[1] ) ] ) ) {
				$season   = strtoupper( $m[1] ) . ' ' . $m[2];
				$nums     = array( (float) $m[3], (float) $m[4], (float) $m[5] );
				$con_anio = true;
			} elseif ( preg_match( '/\b([A-Za-z]{3})\b(?:[^0-9]*?)(\d{1,3})\D+(\d{1,3})\D+(\d{1,3})\b/', $linea, $m )
				&& isset( self::TRIMESTRES[ strtoupper( $m[1] ) ] ) ) {
				// Formato oficial vigente: trigrama sin año; se infiere el año.
				$trigrama = strtoupper( $m[1] );
				$anio     = self::anio_de_trimestre( $trigrama, $referencia, $anterior );
				$anterior = array( 'trigrama' => $trigrama, 'anio' => $anio );
				$season   = $trigrama . ' ' . $anio;
				$nums     = array( (float) $m[2], (float) $m[3], (float) $m[4] );
			}

			if ( null === $season ) {
				continue;
			}

			$orden = ( null !== $orden_cab ) ? $orden_cab : self::orden_por_defecto( $con_anio );
			$en    = $nums[ $orden['el_nino'] ];
			$ne    = $nums[ $orden['neutral'] ];
			$ln    = $nums[ $orden['la_nina'] ];

			// Descarta líneas espurias cuya suma no se acerca a 100.
			$suma = $en + $ne + $ln;
			if ( $suma < 80 || $suma > 120 ) {
				continue;
			}

			$filas[] = array(
				'season'  => $season,
				'el_nino' => $en,
				'neutral' => $ne,
				'la_nina' => $ln,
			);
		}
		return $filas;
	}

	/**
	 * Deduce el orden de las tres columnas de probabilidad desde la cabecera.
	 *
	 * Solo se consideran filas de cabecera reales (cortas y sin prosa): la
	 * página oficial incluye un párrafo descriptivo que nombra las tres fases
	 * en otro orden y que, de tomarse por cabecera, invertiría los datos.
	 *
	 * @param string $plano Texto ya sin marcado.
	 * @return array<string,int>|null Índices 0..2 de cada fase, o null si no
	 *                                hay cabecera reconocible.
	 */
	private static function orden_columnas( $plano ) {
		$lineas = preg_split( '/\r\n|\r|\n/', $plano );
		foreach ( $lineas as $linea ) {
			$l = strtolower( self::sin_tildes( trim( preg_replace( '/\s+/', ' ', $linea ) ) ) );
			if ( false === strpos( $l, 'nino' ) || false === strpos( $l, 'nina' ) || false === strpos( $l, 'neutral' ) ) {
				continue;
			}
			// Prosa, no cabecera: demasiadas palabras o puntuación de frase.
			if ( str_word_count( $l ) > 12 || preg_match( '/[(),.;]/', $l ) ) {
				continue;
			}
			// El separador entre artículo y nombre es opcional: la cabecera
			// puede venir como «El Niño», «ElNino» o «el_nino».
			$pos = array();
			foreach ( array( 'el_nino' => '/el[\s_-]*nino/', 'la_nina' => '/la[\s_-]*nina/', 'neutral' => '/neutral/' ) as $fase => $re ) {
				if ( ! preg_match( $re, $l, $m, PREG_OFFSET_CAPTURE ) ) {
					continue 2;
				}
				$pos[ $fase ] = $m[0][1];
			}
			asort( $pos );
			$orden = array();
			$i     = 0;
			foreach ( $pos as $fase => $unused ) {
				$orden[ $fase ] = $i++;
			}
			return $orden;
		}
		return null;
	}

	/**
	 * Orden de columnas asumido cuando no hay cabecera reconocible.
	 *
	 * Los archivos históricos del IRI (trigrama con año) publicaban El Niño
	 * primero; la página vigente de NOAA/CPC (trigrama sin año) publica
	 * La Niña primero.
	 *
	 * @param bool $con_anio Si la fila trae el año explícito.
	 * @return array<string,int>
	 */
	private static function orden_por_defecto( $con_anio ) {
		return $con_anio
			? array( 'el_nino' => 0, 'neutral' => 1, 'la_nina' => 2 )
			: array( 'la_nina' => 0, 'neutral' => 1, 'el_nino' => 2 );
	}

	/**
	 * Quita tildes sin depender de que WordPress esté cargado (los tests CLI
	 * ejercitan el parser de forma aislada).
	 *
	 * @param string $texto Texto.
	 * @return string
	 */
	private static function sin_tildes( $texto ) {
		if ( function_exists( 'remove_accents' ) ) {
			return remove_accents( $texto );
		}
		return strtr(
			$texto,
			array(
				'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
				'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U',
			)
		);
	}

	/**
	 * Mes de referencia para inferir años: «Issued August 2026» o el mes actual.
	 *
	 * @param string $plano Texto ya sin marcado.
	 * @param string $ahora Mes 'Y-m' de respaldo.
	 * @return array {anio:int, mes:int}
	 */
	private static function mes_emision( $plano, $ahora = '' ) {
		if ( preg_match( '/Issued\s+([A-Za-z]+)\s+(\d{4})/i', $plano, $m ) ) {
			$ts = strtotime( $m[1] . ' 1, ' . $m[2] . ' UTC' );
			if ( $ts ) {
				return array( 'anio' => (int) gmdate( 'Y', $ts ), 'mes' => (int) gmdate( 'n', $ts ) );
			}
		}
		$ref = preg_match( '/^\d{4}-\d{2}$/', (string) $ahora ) ? $ahora : gmdate( 'Y-m' );
		$p   = explode( '-', $ref );
		return array( 'anio' => (int) $p[0], 'mes' => (int) $p[1] );
	}

	/**
	 * Año que corresponde a un trigrama sin año.
	 *
	 * La primera fila se ancla al trimestre más cercano al mes de emisión; las
	 * siguientes avanzan un mes, incrementando el año al cruzar diciembre.
	 *
	 * @param string     $trigrama Trigrama ENSO (p. ej. 'JAS').
	 * @param array      $ref      Mes de emisión {anio, mes}.
	 * @param array|null $anterior Fila previa {trigrama, anio}, si la hay.
	 * @return int
	 */
	private static function anio_de_trimestre( $trigrama, $ref, $anterior ) {
		$centro = self::TRIMESTRES[ $trigrama ];

		if ( is_array( $anterior ) && isset( self::TRIMESTRES[ $anterior['trigrama'] ] ) ) {
			// Secuencia continua: el centro avanza un mes por fila.
			$centro_prev = self::TRIMESTRES[ $anterior['trigrama'] ];
			return ( $centro < $centro_prev ) ? (int) $anterior['anio'] + 1 : (int) $anterior['anio'];
		}

		// Primera fila: año cuyo mes central queda más cerca de la emisión.
		$objetivo = (int) $ref['anio'] * 12 + (int) $ref['mes'];
		$mejor    = (int) $ref['anio'];
		$dist     = PHP_INT_MAX;
		foreach ( array( (int) $ref['anio'] - 1, (int) $ref['anio'], (int) $ref['anio'] + 1 ) as $anio ) {
			$d = abs( ( $anio * 12 + $centro ) - $objetivo );
			if ( $d < $dist ) {
				$dist  = $d;
				$mejor = $anio;
			}
		}
		return $mejor;
	}
}
