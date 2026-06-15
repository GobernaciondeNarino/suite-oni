# Fase 1 — Datos/APIs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Que el pronóstico ENSO use una fuente oficial en vivo (IRI/CPC) además del modelo propio, y derivar de APIs reales los impactos hoy modelados (déficit hídrico vía Open-Meteo, focos de calor vía NASA FIRMS), con procedencia honesta por dato.

**Architecture:** Se siguen los patrones existentes. Cada fuente nueva es una clase `MAN_Sync_*` con método estático `sincronizar($cfg)` que devuelve `{ok, registros, mensaje}`, usa `MAN_Sync::http_get()` y cachea con `MAN_Cache::set($clave, $payload, $ttl, $grupo)`. Se registran en el mapa `MAN_Sync::FUENTES` y se siembran en `MAN_Activator::config_apis_por_defecto()`. El cron orquestador (`MAN_Sync::ejecutar`) ya recorre todas las fuentes activas; el formulario admin ya renderiza cualquier fuente del config. La resiliencia (caché → durable → semilla) ya existe en `MAN_Cache`.

**Tech Stack:** PHP 7.4+ (WordPress), `wp_remote_get`, `MAN_Cache` (transient + tabla durable), GeoJSON DANE de 64 municipios (`mockup-oni/data/narino_municipios.geojson` y/o `data/`).

**Entorno de pruebas:** No hay PHP/WordPress local. Los tests de lógica pura se escriben como scripts PHP CLI ejecutables (`tests/`) para correr cuando haya PHP; la verificación de integración se documenta como checklist post-deploy en Plesk. Cada tarea termina en commit.

---

## Estructura de archivos

| Archivo | Responsabilidad |
|---|---|
| `includes/sync/class-man-sync-iri.php` | **Nuevo.** Sync del pronóstico oficial ENSO (probabilidades por trimestre + curva proyectada). |
| `includes/analysis/class-man-enso.php` | Añadir parser puro `parse_iri_probabilities()` (testeable). |
| `includes/sync/class-man-sync-firms.php` | **Nuevo.** Sync de focos de calor NASA FIRMS, conteo por municipio. |
| `includes/data/class-man-municipios.php` | Añadir helpers puros `punto_en_poligono()` y `cargar_geojson()` (testeables). |
| `includes/sync/class-man-sync-deficit.php` | **Nuevo.** Déficit hídrico derivado real (Open-Meteo precip vs. climatología) por municipio. |
| `includes/analysis/class-man-risk.php` | Usar déficit real + focos reales cuando existan; si no, modelado etiquetado. |
| `includes/sync/class-man-sync.php` | Registrar `iri_enso`, `firms`, `deficit` en `FUENTES`. |
| `includes/class-man-activator.php` | Sembrar config por defecto de las 3 fuentes nuevas. |
| `includes/class-man-rest.php` | `/pronostico/oni` y `/prediccion` aceptan `fuente=oficial\|modelo\|ambos`; `fuente` en payloads; `/estado-apis` reporta procedencia. |
| `includes/admin/class-man-api-config.php` | Casos de `url_de_prueba()` para los 3 slugs nuevos. |
| `tests/test-fase1.php` | Tests CLI de lógica pura (parsers, point-in-polygon, índice déficit). |

---

## Task 1: Parser puro de probabilidades IRI/CPC

**Files:**
- Modify: `includes/analysis/class-man-enso.php` (añadir método estático `parse_iri_probabilities`)
- Test: `tests/test-fase1.php`

La fuente oficial IRI/CPC publica probabilidades ENSO por trimestre. El formato exacto se confirma en la Task 2 vía WebFetch; el parser acepta CSV/tabla con columnas `season, el_nino, neutral, la_nina` (porcentajes) y es defensivo ante variantes de espaciado y encabezados.

- [ ] **Step 1: Escribir el test que falla**

```php
// tests/test-fase1.php  (cabecera + primer test)
<?php
// Test CLI de lógica pura — no requiere WordPress.
// Stubs mínimos para poder cargar las clases sin WP:
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require __DIR__ . '/../includes/analysis/class-man-enso.php';
use GobernacionNarino\MonitorAmbiental\MAN_Enso;

$fallos = 0;
function chk( $cond, $msg ) { global $fallos; if ( $cond ) { echo "  ok  $msg\n"; } else { echo "FAIL  $msg\n"; $fallos++; } }

// --- Task 1: parse_iri_probabilities ---
$csv = "Season,ElNino,Neutral,LaNina\nMJJ 2026,55,40,5\nJJA 2026,60,38,2\n";
$p = MAN_Enso::parse_iri_probabilities( $csv );
chk( count( $p ) === 2, 'IRI: 2 trimestres parseados' );
chk( $p[0]['season'] === 'MJJ 2026', 'IRI: primer season' );
chk( abs( $p[0]['el_nino'] - 55 ) < 0.01, 'IRI: prob El Niño = 55' );
chk( abs( $p[1]['la_nina'] - 2 ) < 0.01, 'IRI: prob La Niña = 2' );
```

- [ ] **Step 2: Correr el test (debe fallar)**

Run: `php tests/test-fase1.php`
Expected: FAIL en "IRI: 2 trimestres parseados" (método no existe → error fatal `Call to undefined method`). Cuando no haya PHP local: marcar como pendiente de ejecución post-entorno.

- [ ] **Step 3: Implementar el parser**

En `includes/analysis/class-man-enso.php`, antes del cierre de la clase (`}` final, línea 182), añadir:

```php
	/**
	 * Parsea probabilidades ENSO oficiales (IRI/CPC) desde CSV/tabla.
	 * Acepta filas «Season, ElNino, Neutral, LaNina» en %; tolera espacios,
	 * comas o tabs como separadores y encabezados con/sin acentos.
	 *
	 * @param string $texto Cuerpo CSV/tabla.
	 * @return array[] Filas {season, el_nino, neutral, la_nina} (0..100).
	 */
	public static function parse_iri_probabilities( $texto ) {
		$filas  = array();
		$lineas = preg_split( '/\r\n|\r|\n/', (string) $texto );
		foreach ( $lineas as $linea ) {
			$linea = trim( $linea );
			if ( '' === $linea ) {
				continue;
			}
			// Encabezado: contiene letras de etiqueta sin 3 números seguidos.
			if ( stripos( $linea, 'nino' ) !== false && ! preg_match( '/\d/', $linea ) ) {
				continue;
			}
			// season = 3 letras + año; luego 3 porcentajes.
			if ( preg_match( '/^([A-Za-z]{3}\s*\d{4})\D+(\d{1,3})\D+(\d{1,3})\D+(\d{1,3})/', $linea, $m ) ) {
				$filas[] = array(
					'season'  => strtoupper( preg_replace( '/\s+/', ' ', trim( $m[1] ) ) ),
					'el_nino' => (float) $m[2],
					'neutral' => (float) $m[3],
					'la_nina' => (float) $m[4],
				);
			}
		}
		return $filas;
	}
```

- [ ] **Step 4: Correr el test (debe pasar)**

Run: `php tests/test-fase1.php`
Expected: las 4 aserciones de IRI en `ok`.

- [ ] **Step 5: Commit**

```bash
git add includes/analysis/class-man-enso.php tests/test-fase1.php
git commit -m "feat(datos): parser puro de probabilidades ENSO oficiales (IRI/CPC)"
```

---

## Task 2: Conector MAN_Sync_Iri (pronóstico oficial en vivo)

**Files:**
- Create: `includes/sync/class-man-sync-iri.php`

Antes de implementar, **confirmar la URL real** de la fuente oficial con WebFetch (riesgo conocido del spec). Candidatos: IRI ENSO forecast (probabilidades) y curva de ensamble Niño-3.4. Si no hay endpoint estable, el conector usa la URL configurada y, si falla, cae a la semilla existente. El payload cacheado expone `fuente='IRI/CPC (oficial)'` y `estado` para `/estado-apis`.

- [ ] **Step 1: Confirmar fuente**

Usar WebFetch sobre el candidato oficial y anotar el formato real. Ajustar `parse_iri_probabilities` (Task 1) si el formato difiere (re-correr su test).

- [ ] **Step 2: Implementar el conector**

```php
<?php
/**
 * Conector IRI/CPC — pronóstico OFICIAL de ENSO (probabilidades por trimestre
 * y curva proyectada de Niño-3.4). Complementa el modelo propio del plugin;
 * NUNCA lo sustituye. Resiliente: cae a la semilla si la fuente falla.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Iri {

	/**
	 * Sincroniza el pronóstico oficial ENSO.
	 *
	 * @param array $cfg Configuración de la fuente.
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$url = ! empty( $cfg['url'] ) ? $cfg['url'] : 'https://iri.columbia.edu/~forecast/ensofcst/Data/ensofcst_ALL.csv';
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
			'fuente'         => 'IRI/CPC (oficial)',
			'estado'         => 'ok',
		);
		MAN_Cache::set( 'enso_pronostico_oficial', $payload, $ttl, 'enso' );

		return array(
			'ok'        => true,
			'registros' => count( $probs ),
			'mensaje'   => count( $probs ) . ' trimestres oficiales',
		);
	}

	/**
	 * Conserva la última caché o la semilla y marca estado=mantenimiento.
	 *
	 * @param string $motivo Motivo del fallo.
	 * @param int    $ttl    TTL en segundos.
	 * @return array
	 */
	private static function fallback( $motivo, $ttl ) {
		$durable = MAN_Cache::get_durable( 'enso_pronostico_oficial' );
		$semilla = is_array( $durable ) ? $durable : MAN_Cache::semilla( 'predicciones_elnino_narino_2026.json' );
		$payload = is_array( $semilla ) ? $semilla : array( 'probabilidades' => array() );
		$payload['estado'] = 'mantenimiento';
		$payload['fuente'] = isset( $payload['fuente'] ) ? $payload['fuente'] : 'semilla (fallback)';
		MAN_Cache::set( 'enso_pronostico_oficial', $payload, $ttl, 'enso' );
		return array( 'ok' => false, 'registros' => 0, 'mensaje' => $motivo . ' → fallback' );
	}
}
```

- [ ] **Step 3: Commit**

```bash
git add includes/sync/class-man-sync-iri.php
git commit -m "feat(datos): conector IRI/CPC del pronóstico ENSO oficial (resiliente)"
```

---

## Task 3: Helpers puros de municipio (GeoJSON + point-in-polygon)

**Files:**
- Modify: `includes/data/class-man-municipios.php`
- Test: `tests/test-fase1.php`

- [ ] **Step 1: Añadir el test que falla**

```php
// tests/test-fase1.php  (añadir al final, antes del resumen)
require __DIR__ . '/../includes/data/class-man-municipios.php';
use GobernacionNarino\MonitorAmbiental\MAN_Municipios;

// Cuadrado unidad (0,0)-(10,10)
$poli = array( array(0,0), array(10,0), array(10,10), array(0,10) );
chk( MAN_Municipios::punto_en_poligono( 5, 5, $poli ) === true,  'PIP: centro dentro' );
chk( MAN_Municipios::punto_en_poligono( 15, 5, $poli ) === false, 'PIP: fuera a la derecha' );
chk( MAN_Municipios::punto_en_poligono( -1, -1, $poli ) === false, 'PIP: fuera abajo-izq' );
```

- [ ] **Step 2: Correr (debe fallar)**

Run: `php tests/test-fase1.php`
Expected: FAIL "PIP: centro dentro" (método indefinido).

- [ ] **Step 3: Implementar el helper (ray casting)**

Añadir a la clase `MAN_Municipios` (antes del `}` final):

```php
	/**
	 * Punto en polígono por ray casting (anillo exterior, lon/lat planos).
	 *
	 * @param float   $x     Longitud del punto.
	 * @param float   $y     Latitud del punto.
	 * @param array[] $anillo Lista de vértices [ [lon,lat], ... ].
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
			$cruza = ( ( $yi > $y ) !== ( $yj > $y ) )
				&& ( $x < ( $xj - $xi ) * ( $y - $yi ) / ( ( $yj - $yi ) ?: 1e-12 ) + $xi );
			if ( $cruza ) {
				$dentro = ! $dentro;
			}
		}
		return $dentro;
	}
```

- [ ] **Step 4: Correr (debe pasar)**

Run: `php tests/test-fase1.php`
Expected: 3 aserciones PIP en `ok`.

- [ ] **Step 5: Commit**

```bash
git add includes/data/class-man-municipios.php tests/test-fase1.php
git commit -m "feat(datos): helper point-in-polygon para asignar focos por municipio"
```

---

## Task 4: Conector MAN_Sync_Firms (focos de calor reales)

**Files:**
- Create: `includes/sync/class-man-sync-firms.php`

NASA FIRMS expone CSV por área (`/api/area/csv/{MAP_KEY}/VIIRS_SNPP_NRT/{bbox}/{days}`). bbox de Nariño ≈ `-79.1,0.3,-76.8,2.7`. La `MAP_KEY` se guarda cifrada (campo `clave`) y llega como `clave_plana`. Conteo total + por municipio (point-in-polygon de la Task 3). Si no hay clave o falla, cae a focos modelados (etiquetado).

- [ ] **Step 1: Implementar el conector**

```php
<?php
/**
 * Conector NASA FIRMS — focos de calor activos (VIIRS/MODIS) en Nariño.
 * Conteo total y por municipio (point-in-polygon). Requiere MAP_KEY (gratuita),
 * guardada cifrada. Resiliente: sin clave o ante fallo → focos modelados.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Firms {

	const BBOX = '-79.1,0.3,-76.8,2.7'; // W,S,E,N de Nariño

	/**
	 * Sincroniza focos de calor.
	 *
	 * @param array $cfg Configuración (clave_plana = MAP_KEY).
	 * @return array {ok, registros, mensaje}.
	 */
	public static function sincronizar( $cfg ) {
		$key = isset( $cfg['clave_plana'] ) ? trim( (string) $cfg['clave_plana'] ) : '';
		$ssl = isset( $cfg['sslverify'] ) ? (bool) $cfg['sslverify'] : true;
		$ttl = isset( $cfg['ttl'] ) ? (int) $cfg['ttl'] * 60 : 43200;
		if ( '' === $key ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Falta MAP_KEY de FIRMS (focos en modelado)' );
		}

		$fuente = ! empty( $cfg['dataset_id'] ) ? sanitize_text_field( $cfg['dataset_id'] ) : 'VIIRS_SNPP_NRT';
		$dias   = 2;
		$url    = 'https://firms.modaps.eosdis.nasa.gov/api/area/csv/' . rawurlencode( $key )
			. '/' . rawurlencode( $fuente ) . '/' . self::BBOX . '/' . $dias;

		$r = MAN_Sync::http_get( $url, $ssl );
		if ( ! $r['ok'] ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'HTTP ' . $r['codigo'] . ' ' . $r['error'] );
		}

		$puntos = self::parse_csv_lat_lon( $r['cuerpo'] );
		$por_muni = MAN_Municipios::contar_focos_por_municipio( $puntos );

		MAN_Cache::set(
			'focos_calor',
			array(
				'total'       => count( $puntos ),
				'por_muni'    => $por_muni,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'NASA FIRMS (' . $fuente . ')',
				'estado'      => 'ok',
			),
			$ttl,
			'ambiental'
		);

		return array( 'ok' => true, 'registros' => count( $puntos ), 'mensaje' => count( $puntos ) . ' focos en ' . $dias . ' días' );
	}

	/**
	 * Extrae [lon,lat] de un CSV de FIRMS (columnas latitude,longitude).
	 *
	 * @param string $csv Cuerpo CSV.
	 * @return array[] Lista [lon, lat].
	 */
	public static function parse_csv_lat_lon( $csv ) {
		$lineas = preg_split( '/\r\n|\r|\n/', (string) $csv );
		$cab    = array();
		$out    = array();
		foreach ( $lineas as $i => $linea ) {
			$linea = trim( $linea );
			if ( '' === $linea ) {
				continue;
			}
			$cols = str_getcsv( $linea );
			if ( ! $cab ) {
				$cab = array_map( 'strtolower', $cols );
				continue;
			}
			$la = array_search( 'latitude', $cab, true );
			$lo = array_search( 'longitude', $cab, true );
			if ( false !== $la && false !== $lo && isset( $cols[ $la ], $cols[ $lo ] ) ) {
				$out[] = array( (float) $cols[ $lo ], (float) $cols[ $la ] );
			}
		}
		return $out;
	}
}
```

- [ ] **Step 2: Añadir `contar_focos_por_municipio` a MAN_Municipios**

```php
	/**
	 * Cuenta focos (puntos [lon,lat]) dentro de cada municipio del GeoJSON.
	 *
	 * @param array[] $puntos Lista [lon, lat].
	 * @return array divipola => conteo.
	 */
	public static function contar_focos_por_municipio( array $puntos ) {
		$geo = self::cargar_geojson();
		$res = array();
		if ( ! $geo || empty( $geo['features'] ) ) {
			return $res;
		}
		foreach ( $geo['features'] as $f ) {
			$cod = isset( $f['properties']['MPIO_CDPMP'] ) ? (string) $f['properties']['MPIO_CDPMP'] : '';
			if ( '' === $cod ) {
				continue;
			}
			$anillos = self::anillos_exteriores( $f['geometry'] );
			$n = 0;
			foreach ( $puntos as $p ) {
				foreach ( $anillos as $anillo ) {
					if ( self::punto_en_poligono( $p[0], $p[1], $anillo ) ) {
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
	 * Devuelve los anillos exteriores de un geometry Polygon/MultiPolygon.
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
```

> Nota: confirmar que existe `data/narino_municipios.geojson` en el plugin; si solo está en `mockup-oni/data/`, copiarlo a `data/` en esta tarea (es semilla legítima).

- [ ] **Step 3: Commit**

```bash
git add includes/sync/class-man-sync-firms.php includes/data/class-man-municipios.php
git commit -m "feat(datos): conector NASA FIRMS + conteo de focos por municipio"
```

---

## Task 5: Déficit hídrico derivado real (Open-Meteo)

**Files:**
- Create: `includes/sync/class-man-sync-deficit.php`
- Modify: `includes/data/class-man-municipios.php` (necesita `centroides()` divipola→[lat,lon])
- Test: `tests/test-fase1.php`

Índice de déficit 0–100 desde precipitación reciente (Open-Meteo daily `precipitation_sum`, últimos 30 días) comparada con un umbral climatológico simple por municipio. Server-side (cron), cacheado `deficit_municipios`. Cálculo del índice es función pura testeable.

- [ ] **Step 1: Test del índice puro**

```php
// tests/test-fase1.php (añadir)
require __DIR__ . '/../includes/sync/class-man-sync-deficit.php';
use GobernacionNarino\MonitorAmbiental\MAN_Sync_Deficit;

// 0 mm de 100 esperados → déficit 100; 100/100 → 0; 50/100 → 50.
chk( MAN_Sync_Deficit::indice_deficit( 0, 100 )   === 100, 'Déficit: sequía total = 100' );
chk( MAN_Sync_Deficit::indice_deficit( 100, 100 ) === 0,   'Déficit: normal = 0' );
chk( MAN_Sync_Deficit::indice_deficit( 50, 100 )  === 50,  'Déficit: mitad = 50' );
chk( MAN_Sync_Deficit::indice_deficit( 200, 100 ) === 0,   'Déficit: exceso recorta a 0' );
```

- [ ] **Step 2: Correr (debe fallar)** — Run `php tests/test-fase1.php` → FAIL método indefinido.

- [ ] **Step 3: Implementar**

```php
<?php
/**
 * Déficit hídrico derivado REAL por municipio: precipitación reciente
 * (Open-Meteo) vs. umbral climatológico simple. Server-side (cron), cacheado.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Sync_Deficit {

	/**
	 * Índice de déficit 0..100 (100 = sin lluvia vs. lo esperado).
	 *
	 * @param float $precip_mm     Precipitación acumulada reciente (mm).
	 * @param float $climatica_mm  Precipitación climática esperada (mm) > 0.
	 * @return int
	 */
	public static function indice_deficit( $precip_mm, $climatica_mm ) {
		$c = max( 1.0, (float) $climatica_mm );
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
		$clima = isset( $cfg['climatica_mm'] ) ? (float) $cfg['climatica_mm'] : 120.0; // mm/30d ref.

		$centroides = MAN_Municipios::centroides();
		if ( empty( $centroides ) ) {
			return array( 'ok' => false, 'registros' => 0, 'mensaje' => 'Sin centroides de municipios' );
		}

		$out = array();
		$n   = 0;
		foreach ( $centroides as $cod => $ll ) {
			$url = add_query_arg(
				array(
					'latitude'  => $ll[0],
					'longitude' => $ll[1],
					'daily'     => 'precipitation_sum',
					'past_days' => 30,
					'forecast_days' => 1,
					'timezone'  => 'America/Bogota',
				),
				$base
			);
			$r = MAN_Sync::http_get( $url, $ssl, array( 'timeout' => 12 ) );
			if ( ! $r['ok'] ) {
				continue;
			}
			$j = json_decode( $r['cuerpo'], true );
			$serie = isset( $j['daily']['precipitation_sum'] ) ? $j['daily']['precipitation_sum'] : array();
			$suma  = 0.0;
			foreach ( $serie as $v ) { $suma += (float) $v; }
			$out[ $cod ] = array(
				'deficit'   => self::indice_deficit( $suma, $clima ),
				'precip_mm' => round( $suma, 1 ),
			);
			$n++;
		}

		MAN_Cache::set(
			'deficit_municipios',
			array(
				'por_muni'    => $out,
				'climatica_mm'=> $clima,
				'actualizado' => current_time( 'mysql', true ),
				'fuente'      => 'Open-Meteo (derivado real)',
				'estado'      => $n > 0 ? 'ok' : 'mantenimiento',
			),
			$ttl,
			'ambiental'
		);

		return array( 'ok' => $n > 0, 'registros' => $n, 'mensaje' => $n . ' municipios con déficit calculado' );
	}
}
```

- [ ] **Step 4: Añadir `centroides()` a MAN_Municipios** (lee LAT/LON del GeoJSON o de la lista DIVIPOLA existente):

```php
	/**
	 * Centroides divipola => [lat, lon] desde el GeoJSON (propiedades LATITUD/LONGITUD).
	 *
	 * @return array
	 */
	public static function centroides() {
		$geo = self::cargar_geojson();
		$res = array();
		if ( ! $geo || empty( $geo['features'] ) ) {
			return $res;
		}
		foreach ( $geo['features'] as $f ) {
			$p   = isset( $f['properties'] ) ? $f['properties'] : array();
			$cod = isset( $p['MPIO_CDPMP'] ) ? (string) $p['MPIO_CDPMP'] : '';
			if ( '' === $cod || ! isset( $p['LATITUD'], $p['LONGITUD'] ) ) {
				continue;
			}
			$res[ $cod ] = array( (float) $p['LATITUD'], (float) $p['LONGITUD'] );
		}
		return $res;
	}
```

- [ ] **Step 5: Correr (debe pasar)** — Run `php tests/test-fase1.php` → 4 aserciones Déficit en `ok`.

- [ ] **Step 6: Commit**

```bash
git add includes/sync/class-man-sync-deficit.php includes/data/class-man-municipios.php tests/test-fase1.php
git commit -m "feat(datos): déficit hídrico derivado real por municipio (Open-Meteo)"
```

---

## Task 6: Registrar las 3 fuentes en el orquestador y la config

**Files:**
- Modify: `includes/sync/class-man-sync.php:18-23` (mapa `FUENTES`)
- Modify: `includes/class-man-activator.php:139-206` (config por defecto)

- [ ] **Step 1: Añadir al mapa FUENTES**

Reemplazar la constante `FUENTES` por:

```php
	const FUENTES = array(
		'noaa_oni' => 'MAN_Sync_Oni',
		'ideam'    => 'MAN_Sync_Ideam',
		'sivigila' => 'MAN_Sync_Sivigila',
		'ioc'      => 'MAN_Sync_Sealevel',
		'iri_enso' => 'MAN_Sync_Iri',
		'firms'    => 'MAN_Sync_Firms',
		'deficit'  => 'MAN_Sync_Deficit',
	);
```

- [ ] **Step 2: Sembrar config por defecto**

Antes del `);` de cierre del `return array(...)` en `config_apis_por_defecto()` (tras el bloque `ioc`, línea 205), añadir:

```php
			'iri_enso' => array(
				'nombre'           => 'IRI/CPC — pronóstico ENSO oficial',
				'activa'           => true,
				'capa'             => 'cron',
				'url'              => 'https://iri.columbia.edu/~forecast/ensofcst/Data/ensofcst_ALL.csv',
				'dataset_id'       => '',
				'clave'            => '',
				'frecuencia'       => 12,
				'ttl'              => 720,
				'sslverify'        => true,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
			'firms' => array(
				'nombre'           => 'NASA FIRMS — focos de calor (requiere MAP_KEY)',
				'activa'           => false, // activar al fijar la clave gratuita
				'capa'             => 'cron',
				'url'              => 'https://firms.modaps.eosdis.nasa.gov/api/area/csv/',
				'dataset_id'       => 'VIIRS_SNPP_NRT',
				'clave'            => '',
				'frecuencia'       => 12,
				'ttl'              => 720,
				'sslverify'        => true,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
			'deficit' => array(
				'nombre'           => 'Déficit hídrico derivado (Open-Meteo)',
				'activa'           => true,
				'capa'             => 'cron',
				'url'              => 'https://api.open-meteo.com/v1/forecast',
				'dataset_id'       => '',
				'clave'            => '',
				'climatica_mm'     => 120,
				'frecuencia'       => 12,
				'ttl'              => 360,
				'sslverify'        => true,
				'ultima_sync'      => 0,
				'ultimo_resultado' => '',
			),
```

> Las instalaciones existentes no re-siembran (`add_option` no sobrescribe). Documentar en el checklist post-deploy: en sitios ya activos, re-guardar la config o re-activar el plugin para que aparezcan las fuentes nuevas. Alternativa (opcional, fuera de Fase 1): migración por `man_version`.

- [ ] **Step 3: Commit**

```bash
git add includes/sync/class-man-sync.php includes/class-man-activator.php
git commit -m "feat(datos): registra IRI, FIRMS y déficit en orquestador y config por defecto"
```

---

## Task 7: REST — parámetro `fuente` y procedencia honesta

**Files:**
- Modify: `includes/class-man-rest.php` (handlers `/pronostico/oni`, `/prediccion`, `/estado-apis`)

Leer primero los handlers exactos (`grep` de `pronostico/oni`, `prediccion`, `estado-apis` en el archivo). Cambios:
1. `/prediccion` y `/pronostico/oni` aceptan `fuente` ∈ `{oficial, modelo, ambos}` (default `ambos`). `oficial` añade `MAN_Cache::get('enso_pronostico_oficial')`; `modelo` usa el cálculo Holt actual; `ambos` devuelve los dos con su etiqueta `fuente`.
2. Cada serie/indicador del payload lleva `"fuente": "real|oficial|modelado"`.
3. `/estado-apis` incluye las 3 fuentes nuevas con su `estado` (`ok|mantenimiento`) y `ultima_sync`.

- [ ] **Step 1: Localizar handlers** — `grep -n "pronostico/oni\|'prediccion'\|estado-apis\|register_rest_route" includes/class-man-rest.php`

- [ ] **Step 2: Añadir arg `fuente`** al `register_rest_route` de `/prediccion` y `/pronostico/oni`:

```php
'args' => array(
	'fuente' => array(
		'default'           => 'ambos',
		'sanitize_callback' => function ( $v ) {
			$v = sanitize_key( $v );
			return in_array( $v, array( 'oficial', 'modelo', 'ambos' ), true ) ? $v : 'ambos';
		},
	),
	// ... args existentes (hasta, etc.)
),
```

- [ ] **Step 3: En el callback de `/prediccion`**, tras calcular el modelo propio, integrar lo oficial:

```php
$fuente = $request->get_param( 'fuente' );
$salida = array( 'fuente_solicitada' => $fuente );

if ( 'modelo' !== $fuente ) {
	$oficial = MAN_Cache::get( 'enso_pronostico_oficial' );
	$salida['oficial'] = is_array( $oficial ) ? $oficial : array( 'probabilidades' => array(), 'estado' => 'sin_datos', 'fuente' => 'IRI/CPC (oficial)' );
}
if ( 'oficial' !== $fuente ) {
	// $proyeccion = resultado actual del modelo Holt (variable existente del callback)
	$salida['modelo'] = array(
		'serie'  => $proyeccion,       // reutiliza la variable ya calculada
		'fuente' => 'modelado',
	);
}
return rest_ensure_response( $salida );
```

> Mantener compatibilidad: si algún consumidor existente esperaba la forma vieja, conservarla bajo `modelo` y dejar el resto aditivo. Verificar el shape actual antes de editar.

- [ ] **Step 4: `/estado-apis`** — confirmar que enumera `man_api_config` completo (las 3 nuevas ya aparecen al iterar la opción). Añadir el `estado` leído de cada caché (`enso_pronostico_oficial`, `focos_calor`, `deficit_municipios`) si el endpoint hoy solo mira `ultima_sync`.

- [ ] **Step 5: Commit**

```bash
git add includes/class-man-rest.php
git commit -m "feat(datos): REST acepta fuente=oficial|modelo|ambos y reporta procedencia"
```

---

## Task 8: Riesgo municipal usa déficit y focos reales

**Files:**
- Modify: `includes/analysis/class-man-risk.php`

El riesgo compuesto debe preferir el déficit real (`deficit_municipios`) y los focos reales (`focos_calor`) cuando existan; si no, mantener el valor modelado de la semilla, etiquetando la procedencia.

- [ ] **Step 1: Leer `class-man-risk.php`** completo y localizar dónde se arma la anomalía/exposición por municipio.

- [ ] **Step 2: Inyectar fuentes reales** en el cálculo:

```php
$deficit_cache = MAN_Cache::get( 'deficit_municipios' );
$focos_cache   = MAN_Cache::get( 'focos_calor' );
$deficit_real  = ( is_array( $deficit_cache ) && ! empty( $deficit_cache['por_muni'][ $divipola ] ) )
	? (int) $deficit_cache['por_muni'][ $divipola ]['deficit'] : null;
$focos_real    = ( is_array( $focos_cache ) && isset( $focos_cache['por_muni'][ $divipola ] ) )
	? (int) $focos_cache['por_muni'][ $divipola ] : null;

$deficit = ( null !== $deficit_real ) ? $deficit_real : $deficit_modelado; // fallback semilla
$proc_deficit = ( null !== $deficit_real ) ? 'real' : 'modelado';
```

Exponer `procedencia` en el resultado del municipio (`{deficit, deficit_fuente, focos, focos_fuente, ...}`).

- [ ] **Step 3: Commit**

```bash
git add includes/analysis/class-man-risk.php
git commit -m "feat(datos): riesgo municipal usa déficit y focos reales con etiqueta de procedencia"
```

---

## Task 9: Admin — URLs de prueba para las fuentes nuevas

**Files:**
- Modify: `includes/admin/class-man-api-config.php:185-200` (`url_de_prueba`)

- [ ] **Step 1: Añadir casos al switch**

```php
			case 'iri_enso':
				return isset( $cfg['url'] ) ? $cfg['url'] : '';
			case 'deficit':
				return 'https://api.open-meteo.com/v1/forecast?latitude=1.21&longitude=-77.28&daily=precipitation_sum&past_days=7&forecast_days=1&timezone=America%2FBogota';
			case 'firms':
				$key = ! empty( $cfg['clave'] ) ? MAN_Security::descifrar( $cfg['clave'] ) : '';
				$ds  = isset( $cfg['dataset_id'] ) ? $cfg['dataset_id'] : 'VIIRS_SNPP_NRT';
				return 'https://firms.modaps.eosdis.nasa.gov/api/area/csv/' . rawurlencode( $key ) . '/' . rawurlencode( $ds ) . '/' . \GobernacionNarino\MonitorAmbiental\MAN_Sync_Firms::BBOX . '/1';
```

- [ ] **Step 2: Commit**

```bash
git add includes/admin/class-man-api-config.php
git commit -m "feat(admin): botón Probar conexión para IRI, FIRMS y déficit"
```

---

## Task 10: Verificación y checklist post-deploy

**Files:**
- Create: `docs/verificacion/fase1-checklist.md`

- [ ] **Step 1: Correr todos los tests de lógica pura** (cuando haya PHP): `php tests/test-fase1.php` → todo en `ok`, `0 fallos`.

- [ ] **Step 2: Escribir el checklist post-deploy** (Plesk/WordPress) con: re-guardar config para sembrar fuentes nuevas; fijar MAP_KEY FIRMS; pulsar «Sincronizar ahora» en IRI/FIRMS/déficit; verificar `/wp-json/man/v1/prediccion?fuente=ambos`, `/pronostico/oni?fuente=oficial`, `/estado-apis`; confirmar fallback (URL inválida temporal → estado=mantenimiento sin error fatal).

- [ ] **Step 3: Commit**

```bash
git add docs/verificacion/fase1-checklist.md
git commit -m "docs: checklist de verificación post-deploy Fase 1"
```

---

## Self-Review

- **Cobertura del spec:** 1.1 pronóstico oficial → Tasks 1,2,7. 1.2 impactos derivados → Tasks 3,4,5,8. 1.3 estado fiel → Task 7 (`fuente`, último observado). 1.4 provenance → Tasks 7,8. Wiring → Tasks 6,9. Verificación → Task 10. ✓
- **Sin placeholders:** todo el código nuevo va completo. Las ediciones de `class-man-rest.php` y `class-man-risk.php` (Tasks 7,8) requieren leer el archivo antes (anclas reales), por eso su Step 1 es "leer/localizar" — no es un placeholder de código sino una verificación obligatoria de ancla.
- **Consistencia de tipos:** claves de caché (`enso_pronostico_oficial`, `focos_calor`, `deficit_municipios`) y métodos (`punto_en_poligono`, `contar_focos_por_municipio`, `centroides`, `cargar_geojson`, `indice_deficit`, `parse_iri_probabilities`, `parse_csv_lat_lon`) se nombran igual en definición y uso. ✓
- **Riesgo abierto:** URL oficial IRI/CPC se confirma en Task 2 Step 1 (WebFetch) antes de cablear; el conector ya cae a semilla si falla.
