<?php
/**
 * Tabla maestra de los 64 municipios de Nariño (cartografía DANE / DIVIPOLA).
 *
 * Fuente: Sección 10 de la especificación. Coordenadas del centroide
 * municipal redondeadas a 5 decimales. Usada como lista blanca de seguridad
 * y para resolver coordenadas en Open-Meteo / NASA POWER.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Municipios {

	/** @var array|null Caché en memoria de la lista. */
	private static $lista = null;

	/** @var array Índice rápido divipola → fila. */
	private static $indice = null;

	/**
	 * Subregiones de influencia litoral Pacífica. En estas, El Niño suele
	 * traer MÁS lluvia y oleaje (signo invertido respecto a la zona andina).
	 *
	 * @return string[]
	 */
	public static function litoral_subregiones() {
		return array( 'Sanquianga', 'Pacífico Sur', 'Telembí', 'Pie de Monte Costero' );
	}

	/**
	 * Devuelve los 64 municipios.
	 *
	 * @return array[]
	 */
	public static function todos() {
		if ( null !== self::$lista ) {
			return self::$lista;
		}

		self::$lista = array(
			array( 'divipola' => '52019', 'nombre' => 'ALBÁN', 'lat' => 1.46985, 'lon' => -77.06881, 'subregion' => 'Río Mayo' ),
			array( 'divipola' => '52022', 'nombre' => 'ALDANA', 'lat' => 0.91343, 'lon' => -77.69539, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52036', 'nombre' => 'ANCUYA', 'lat' => 1.24525, 'lon' => -77.53116, 'subregion' => 'Occidente' ),
			array( 'divipola' => '52051', 'nombre' => 'ARBOLEDA', 'lat' => 1.48005, 'lon' => -77.12985, 'subregion' => 'Juanambú' ),
			array( 'divipola' => '52079', 'nombre' => 'BARBACOAS', 'lat' => 1.44564, 'lon' => -78.15621, 'subregion' => 'Telembí' ),
			array( 'divipola' => '52083', 'nombre' => 'BELÉN', 'lat' => 1.59076, 'lon' => -77.04290, 'subregion' => 'Río Mayo' ),
			array( 'divipola' => '52110', 'nombre' => 'BUESACO', 'lat' => 1.31522, 'lon' => -77.11637, 'subregion' => 'Juanambú' ),
			array( 'divipola' => '52240', 'nombre' => 'CHACHAGÜÍ', 'lat' => 1.38650, 'lon' => -77.26969, 'subregion' => 'Centro' ),
			array( 'divipola' => '52203', 'nombre' => 'COLÓN', 'lat' => 1.63633, 'lon' => -77.04732, 'subregion' => 'Río Mayo' ),
			array( 'divipola' => '52207', 'nombre' => 'CONSACÁ', 'lat' => 1.20907, 'lon' => -77.44064, 'subregion' => 'Occidente' ),
			array( 'divipola' => '52210', 'nombre' => 'CONTADERO', 'lat' => 0.93267, 'lon' => -77.52809, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52224', 'nombre' => 'CUASPUD CARLOSAMA', 'lat' => 0.87543, 'lon' => -77.73592, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52227', 'nombre' => 'CUMBAL', 'lat' => 0.94422, 'lon' => -77.95958, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52233', 'nombre' => 'CUMBITARA', 'lat' => 1.72559, 'lon' => -77.59282, 'subregion' => 'Cordillera' ),
			array( 'divipola' => '52215', 'nombre' => 'CÓRDOBA', 'lat' => 0.77080, 'lon' => -77.36033, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52250', 'nombre' => 'EL CHARCO', 'lat' => 2.18315, 'lon' => -77.79574, 'subregion' => 'Sanquianga' ),
			array( 'divipola' => '52254', 'nombre' => 'EL PEÑOL', 'lat' => 1.51228, 'lon' => -77.43051, 'subregion' => 'Cordillera' ),
			array( 'divipola' => '52256', 'nombre' => 'EL ROSARIO', 'lat' => 1.84509, 'lon' => -77.43826, 'subregion' => 'Cordillera' ),
			array( 'divipola' => '52258', 'nombre' => 'EL TABLÓN DE GÓMEZ', 'lat' => 1.40943, 'lon' => -76.98527, 'subregion' => 'Juanambú' ),
			array( 'divipola' => '52260', 'nombre' => 'EL TAMBO', 'lat' => 1.43026, 'lon' => -77.38312, 'subregion' => 'Frontera Pacífica' ),
			array( 'divipola' => '52520', 'nombre' => 'FRANCISCO PIZARRO', 'lat' => 2.08853, 'lon' => -78.59193, 'subregion' => 'Pacífico Sur' ),
			array( 'divipola' => '52287', 'nombre' => 'FUNES', 'lat' => 0.91422, 'lon' => -77.32843, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52317', 'nombre' => 'GUACHUCAL', 'lat' => 0.97504, 'lon' => -77.73759, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52320', 'nombre' => 'GUAITARILLA', 'lat' => 1.15137, 'lon' => -77.53011, 'subregion' => 'Sabana' ),
			array( 'divipola' => '52323', 'nombre' => 'GUALMATÁN', 'lat' => 0.92864, 'lon' => -77.58262, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52352', 'nombre' => 'ILES', 'lat' => 0.98053, 'lon' => -77.51866, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52354', 'nombre' => 'IMUÉS', 'lat' => 1.07288, 'lon' => -77.50151, 'subregion' => 'Sabana' ),
			array( 'divipola' => '52356', 'nombre' => 'IPIALES', 'lat' => 0.55861, 'lon' => -77.37036, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52378', 'nombre' => 'LA CRUZ', 'lat' => 1.58418, 'lon' => -76.92335, 'subregion' => 'Río Mayo' ),
			array( 'divipola' => '52381', 'nombre' => 'LA FLORIDA', 'lat' => 1.33393, 'lon' => -77.38823, 'subregion' => 'Centro' ),
			array( 'divipola' => '52385', 'nombre' => 'LA LLANADA', 'lat' => 1.55401, 'lon' => -77.70317, 'subregion' => 'Abades-La Llanada' ),
			array( 'divipola' => '52390', 'nombre' => 'LA TOLA', 'lat' => 2.41931, 'lon' => -78.20991, 'subregion' => 'Sanquianga' ),
			array( 'divipola' => '52399', 'nombre' => 'LA UNIÓN', 'lat' => 1.61970, 'lon' => -77.14285, 'subregion' => 'Río Mayo' ),
			array( 'divipola' => '52405', 'nombre' => 'LEIVA', 'lat' => 1.93898, 'lon' => -77.31194, 'subregion' => 'Cordillera' ),
			array( 'divipola' => '52411', 'nombre' => 'LINARES', 'lat' => 1.39517, 'lon' => -77.52094, 'subregion' => 'Occidente' ),
			array( 'divipola' => '52418', 'nombre' => 'LOS ANDES', 'lat' => 1.67260, 'lon' => -77.71054, 'subregion' => 'Abades-La Llanada' ),
			array( 'divipola' => '52427', 'nombre' => 'MAGÜÍ', 'lat' => 1.90686, 'lon' => -78.04474, 'subregion' => 'Telembí' ),
			array( 'divipola' => '52435', 'nombre' => 'MALLAMA', 'lat' => 1.15595, 'lon' => -77.84665, 'subregion' => 'Centro-Occidente / Abades' ),
			array( 'divipola' => '52473', 'nombre' => 'MOSQUERA', 'lat' => 2.44249, 'lon' => -78.43883, 'subregion' => 'Sanquianga' ),
			array( 'divipola' => '52480', 'nombre' => 'NARIÑO', 'lat' => 1.28086, 'lon' => -77.35389, 'subregion' => 'Centro' ),
			array( 'divipola' => '52490', 'nombre' => 'OLAYA HERRERA', 'lat' => 2.28989, 'lon' => -78.29472, 'subregion' => 'Sanquianga' ),
			array( 'divipola' => '52506', 'nombre' => 'OSPINA', 'lat' => 1.02982, 'lon' => -77.55235, 'subregion' => 'Sabana' ),
			array( 'divipola' => '52001', 'nombre' => 'PASTO', 'lat' => 1.08361, 'lon' => -77.20610, 'subregion' => 'Centro' ),
			array( 'divipola' => '52540', 'nombre' => 'POLICARPA', 'lat' => 1.73535, 'lon' => -77.48134, 'subregion' => 'Cordillera' ),
			array( 'divipola' => '52560', 'nombre' => 'POTOSÍ', 'lat' => 0.72268, 'lon' => -77.42481, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52565', 'nombre' => 'PROVIDENCIA', 'lat' => 1.23286, 'lon' => -77.59844, 'subregion' => 'Centro-Occidente / Abades' ),
			array( 'divipola' => '52573', 'nombre' => 'PUERRES', 'lat' => 0.82652, 'lon' => -77.32225, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52585', 'nombre' => 'PUPIALES', 'lat' => 0.91677, 'lon' => -77.63337, 'subregion' => 'Ex-Provincia de Obando' ),
			array( 'divipola' => '52612', 'nombre' => 'RICAURTE', 'lat' => 1.20276, 'lon' => -78.04765, 'subregion' => 'Pie de Monte Costero' ),
			array( 'divipola' => '52621', 'nombre' => 'ROBERTO PAYÁN', 'lat' => 1.89758, 'lon' => -78.38112, 'subregion' => 'Telembí' ),
			array( 'divipola' => '52678', 'nombre' => 'SAMANIEGO', 'lat' => 1.43056, 'lon' => -77.69180, 'subregion' => 'Centro-Occidente / Abades' ),
			array( 'divipola' => '52835', 'nombre' => 'SAN ANDRÉS DE TUMACO', 'lat' => 1.63610, 'lon' => -78.61391, 'subregion' => 'Pacífico Sur' ),
			array( 'divipola' => '52685', 'nombre' => 'SAN BERNARDO', 'lat' => 1.52978, 'lon' => -77.02071, 'subregion' => 'Juanambú' ),
			array( 'divipola' => '52687', 'nombre' => 'SAN LORENZO', 'lat' => 1.54214, 'lon' => -77.21873, 'subregion' => 'Juanambú' ),
			array( 'divipola' => '52693', 'nombre' => 'SAN PABLO', 'lat' => 1.68158, 'lon' => -76.97528, 'subregion' => 'Río Mayo' ),
			array( 'divipola' => '52694', 'nombre' => 'SAN PEDRO DE CARTAGO', 'lat' => 1.53682, 'lon' => -77.10140, 'subregion' => 'Juanambú' ),
			array( 'divipola' => '52683', 'nombre' => 'SANDONÁ', 'lat' => 1.28811, 'lon' => -77.45670, 'subregion' => 'Occidente' ),
			array( 'divipola' => '52696', 'nombre' => 'SANTA BÁRBARA', 'lat' => 2.30216, 'lon' => -77.87437, 'subregion' => 'Sanquianga' ),
			array( 'divipola' => '52699', 'nombre' => 'SANTACRUZ', 'lat' => 1.28518, 'lon' => -77.74457, 'subregion' => 'Centro-Occidente / Abades' ),
			array( 'divipola' => '52720', 'nombre' => 'SAPUYES', 'lat' => 1.03619, 'lon' => -77.68045, 'subregion' => 'Sabana' ),
			array( 'divipola' => '52786', 'nombre' => 'TAMINANGO', 'lat' => 1.59166, 'lon' => -77.32525, 'subregion' => 'Cordillera' ),
			array( 'divipola' => '52788', 'nombre' => 'TANGUA', 'lat' => 1.06408, 'lon' => -77.35063, 'subregion' => 'Centro' ),
			array( 'divipola' => '52838', 'nombre' => 'TÚQUERRES', 'lat' => 1.13444, 'lon' => -77.63073, 'subregion' => 'Sabana' ),
			array( 'divipola' => '52885', 'nombre' => 'YACUANQUER', 'lat' => 1.12555, 'lon' => -77.42468, 'subregion' => 'Centro' ),
		);

		return self::$lista;
	}

	/**
	 * Construye (una vez) el índice divipola → fila.
	 */
	private static function indexar() {
		if ( null !== self::$indice ) {
			return;
		}
		self::$indice = array();
		foreach ( self::todos() as $m ) {
			self::$indice[ $m['divipola'] ] = $m;
		}
	}

	/**
	 * ¿Existe el DIVIPOLA en la lista blanca?
	 *
	 * @param string $divipola Código.
	 * @return bool
	 */
	public static function existe( $divipola ) {
		self::indexar();
		return isset( self::$indice[ (string) $divipola ] );
	}

	/**
	 * Devuelve la fila de un municipio por DIVIPOLA.
	 *
	 * @param string $divipola Código.
	 * @return array|null
	 */
	public static function por_divipola( $divipola ) {
		self::indexar();
		$divipola = (string) $divipola;
		return isset( self::$indice[ $divipola ] ) ? self::$indice[ $divipola ] : null;
	}

	/**
	 * Busca un municipio por nombre (sin distinción de mayúsculas/acentos).
	 *
	 * @param string $nombre Nombre del municipio.
	 * @return array|null
	 */
	public static function por_nombre( $nombre ) {
		$clave = self::clave_nombre( $nombre );
		if ( '' === $clave ) {
			return null;
		}
		foreach ( self::todos() as $m ) {
			if ( self::clave_nombre( $m['nombre'] ) === $clave ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Lista de los 64 códigos DIVIPOLA (lista blanca de seguridad).
	 *
	 * @return string[]
	 */
	public static function codigos() {
		return wp_list_pluck( self::todos(), 'divipola' );
	}

	/**
	 * ¿La subregión tiene comportamiento litoral Pacífico?
	 *
	 * @param string $subregion Nombre de la subregión.
	 * @return bool
	 */
	public static function es_litoral( $subregion ) {
		return in_array( $subregion, self::litoral_subregiones(), true );
	}

	/**
	 * Normaliza un nombre para comparaciones (mayúsculas, sin acentos).
	 *
	 * @param string $n Nombre.
	 * @return string
	 */
	private static function clave_nombre( $n ) {
		return strtoupper( remove_accents( trim( (string) $n ) ) );
	}

	/* ================================================================= */
	/* Geometría — focos por municipio y centroides                      */
	/* ================================================================= */

	/**
	 * Centroides divipola => [lat, lon] desde la lista maestra.
	 *
	 * @return array
	 */
	public static function centroides() {
		$res = array();
		foreach ( self::todos() as $m ) {
			$res[ $m['divipola'] ] = array( (float) $m['lat'], (float) $m['lon'] );
		}
		return $res;
	}

	/**
	 * Punto en polígono por ray casting (anillo exterior, coordenadas lon/lat
	 * tratadas como plano cartesiano — suficiente a escala municipal).
	 *
	 * @param float   $x      Longitud del punto.
	 * @param float   $y      Latitud del punto.
	 * @param array[] $anillo Vértices [ [lon,lat], ... ].
	 * @return bool
	 */
	public static function punto_en_poligono( $x, $y, array $anillo ) {
		$dentro = false;
		$n      = count( $anillo );
		for ( $i = 0, $j = $n - 1; $i < $n; $j = $i++ ) {
			$xi = (float) $anillo[ $i ][0];
			$yi = (float) $anillo[ $i ][1];
			$xj = (float) $anillo[ $j ][0];
			$yj = (float) $anillo[ $j ][1];
			$dy = ( $yj - $yi );
			if ( 0.0 === $dy ) {
				$dy = 1e-12;
			}
			$cruza = ( ( $yi > $y ) !== ( $yj > $y ) )
				&& ( $x < ( $xj - $xi ) * ( $y - $yi ) / $dy + $xi );
			if ( $cruza ) {
				$dentro = ! $dentro;
			}
		}
		return $dentro;
	}

	/**
	 * Cuenta focos (puntos [lon,lat]) dentro de cada municipio del GeoJSON.
	 * Si no hay GeoJSON, cae a asignación por centroide más cercano.
	 *
	 * @param array[] $puntos Lista [lon, lat].
	 * @return array divipola => conteo.
	 */
	public static function contar_focos_por_municipio( array $puntos ) {
		$geo = self::cargar_geojson();
		$res = array();

		if ( $geo && ! empty( $geo['features'] ) ) {
			foreach ( $geo['features'] as $f ) {
				$cod = self::codigo_de_feature( $f );
				if ( '' === $cod || empty( $f['geometry'] ) ) {
					continue;
				}
				$anillos = self::anillos_exteriores( $f['geometry'] );
				$n = 0;
				foreach ( $puntos as $pt ) {
					foreach ( $anillos as $anillo ) {
						if ( self::punto_en_poligono( $pt[0], $pt[1], $anillo ) ) {
							$n++;
							break;
						}
					}
				}
				if ( $n > 0 ) {
					$res[ $cod ] = $n;
				}
			}
			return $res;
		}

		// Fallback sin GeoJSON: centroide más cercano.
		$cen = self::centroides();
		foreach ( $puntos as $pt ) {
			$mejor = '';
			$mdist = INF;
			foreach ( $cen as $cod => $ll ) {
				$d = ( $ll[1] - $pt[0] ) * ( $ll[1] - $pt[0] ) + ( $ll[0] - $pt[1] ) * ( $ll[0] - $pt[1] );
				if ( $d < $mdist ) {
					$mdist = $d;
					$mejor = $cod;
				}
			}
			if ( '' !== $mejor ) {
				$res[ $mejor ] = isset( $res[ $mejor ] ) ? $res[ $mejor ] + 1 : 1;
			}
		}
		return $res;
	}

	/**
	 * Carga el GeoJSON de municipios (semilla en data/).
	 *
	 * @return array|null
	 */
	public static function cargar_geojson() {
		$g = MAN_Cache::semilla( 'narino_municipios.geojson' );
		return is_array( $g ) ? $g : null;
	}

	/**
	 * Extrae el DIVIPOLA de un feature, tolerando varias claves de propiedad.
	 *
	 * @param array $f Feature GeoJSON.
	 * @return string
	 */
	private static function codigo_de_feature( $f ) {
		$p = isset( $f['properties'] ) ? $f['properties'] : array();
		foreach ( array( 'MPIO_CDPMP', 'MPIO_CCDGO', 'DPTOMPIO', 'divipola', 'codigo' ) as $k ) {
			if ( ! empty( $p[ $k ] ) ) {
				return (string) $p[ $k ];
			}
		}
		return '';
	}

	/**
	 * Anillos exteriores de un geometry Polygon/MultiPolygon.
	 *
	 * @param array $geom Geometry GeoJSON.
	 * @return array[] Lista de anillos [ [ [lon,lat], ... ], ... ].
	 */
	private static function anillos_exteriores( $geom ) {
		if ( empty( $geom['type'] ) || empty( $geom['coordinates'] ) ) {
			return array();
		}
		if ( 'Polygon' === $geom['type'] ) {
			return array( $geom['coordinates'][0] );
		}
		if ( 'MultiPolygon' === $geom['type'] ) {
			$out = array();
			foreach ( $geom['coordinates'] as $poli ) {
				if ( ! empty( $poli[0] ) ) {
					$out[] = $poli[0];
				}
			}
			return $out;
		}
		return array();
	}
}
