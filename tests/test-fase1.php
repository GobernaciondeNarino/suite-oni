<?php
/**
 * Tests CLI de lógica pura — Fase 1 (datos/APIs).
 *
 * No requiere WordPress: define stubs mínimos de las funciones WP usadas por
 * los métodos puros bajo prueba. Ejecutar con:  php tests/test-fase1.php
 *
 * @package MonitorAmbientalNarino
 */

error_reporting( E_ALL & ~E_DEPRECATED );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// --- Stubs mínimos de WordPress para los métodos puros ---
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
		$string = strip_tags( $string );
		return trim( $string );
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, $d ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
}

require __DIR__ . '/../includes/analysis/class-man-enso.php';
require __DIR__ . '/../includes/data/class-man-municipios.php';
require __DIR__ . '/../includes/sync/class-man-sync-deficit.php';

use GobernacionNarino\MonitorAmbiental\MAN_Enso;
use GobernacionNarino\MonitorAmbiental\MAN_Municipios;
use GobernacionNarino\MonitorAmbiental\MAN_Sync_Deficit;

$fallos = 0;
function chk( $cond, $msg ) {
	global $fallos;
	if ( $cond ) {
		echo "  ok  $msg\n";
	} else {
		echo "FAIL  $msg\n";
		$fallos++;
	}
}

function chk_silencioso( $cond, $msg ) {
	global $fallos;
	if ( ! $cond ) {
		echo "FAIL  $msg\n";
		$fallos++;
	}
}

/* ---------- Task 1: parse_iri_probabilities ---------- */
$csv = "Season,ElNino,Neutral,LaNina\nMJJ 2026,55,40,5\nJJA 2026,60,38,2\n";
$p   = MAN_Enso::parse_iri_probabilities( $csv );
chk( count( $p ) === 2, 'IRI/CSV: 2 trimestres' );
chk( isset( $p[0]['season'] ) && $p[0]['season'] === 'MJJ 2026', 'IRI/CSV: primer season' );
chk( abs( $p[0]['el_nino'] - 55 ) < 0.01, 'IRI/CSV: El Niño = 55' );
chk( abs( $p[1]['la_nina'] - 2 ) < 0.01, 'IRI/CSV: La Niña = 2' );

$html = '<table><tr><td>Season</td><td>EN</td><td>N</td><td>LN</td></tr>'
	. '<tr><td>ASO 2026</td><td>70</td><td>28</td><td>2</td></tr></table>';
$ph = MAN_Enso::parse_iri_probabilities( $html );
chk( count( $ph ) === 1, 'IRI/HTML: 1 trimestre' );
chk( isset( $ph[0]['season'] ) && $ph[0]['season'] === 'ASO 2026', 'IRI/HTML: season' );
chk( abs( $ph[0]['el_nino'] - 70 ) < 0.01, 'IRI/HTML: El Niño = 70' );

// Línea espuria (suma lejos de 100) se descarta.
$ruido = "FOO 2026 1 2 3\nMJJ 2026 50 45 5\n";
$pr = MAN_Enso::parse_iri_probabilities( $ruido );
chk( count( $pr ) === 1, 'IRI: descarta fila cuya suma != ~100' );

/* --- Formato OFICIAL vigente de NOAA/CPC: trigrama SIN año y columnas
       en el orden La Niña · Neutral · El Niño (regresión v1.38.0). --- */
$cpc = "Season | La Niña | Neutral | El Niño\n"
	. "JAS Jul Aug Sep | 0 | 0 | 100\n"
	. "ASO Aug Sep Oct | 0 | 2 | 98\n"
	. "NDJ Nov Dec Jan | 0 | 5 | 95\n"
	. "DJF Dec Jan Feb | 0 | 10 | 90\n";
$pc = MAN_Enso::parse_iri_probabilities( $cpc, '2026-08' );
chk( count( $pc ) === 4, 'CPC: 4 trimestres sin año' );
chk( isset( $pc[0]['season'] ) && $pc[0]['season'] === 'JAS 2026', 'CPC: año inferido del mes de emisión' );
chk( isset( $pc[0]['el_nino'] ) && abs( $pc[0]['el_nino'] - 100 ) < 0.01, 'CPC: columnas leídas de la cabecera (El Niño = 100)' );
chk( isset( $pc[0]['la_nina'] ) && abs( $pc[0]['la_nina'] - 0 ) < 0.01, 'CPC: La Niña = 0 (no invertido)' );
chk( isset( $pc[3]['season'] ) && $pc[3]['season'] === 'DJF 2027', 'CPC: DJF cruza al año siguiente' );

// La prosa de la página nombra las tres fases en otro orden: no es cabecera.
$prosa = "The bars show the chance of El Niño (red bars), ENSO-Neutral (grey bars), and La Niña (blue bars).\n"
	. "Season | La Niña | Neutral | El Niño\n"
	. "MAM Mar Apr May | 0 | 18 | 82\n";
$pp = MAN_Enso::parse_iri_probabilities( $prosa, '2027-04' );
chk( count( $pp ) === 1 && abs( $pp[0]['el_nino'] - 82 ) < 0.01, 'CPC: el párrafo descriptivo no se toma por cabecera' );

// Fuente caída o página sin tabla: 0 filas → el conector cae a la semilla.
chk( count( MAN_Enso::parse_iri_probabilities( '<html><body><p>No disponible</p></body></html>' ) ) === 0, 'IRI: página sin tabla → 0 filas' );

/* ---------- Task 3: punto_en_poligono ---------- */
$poli = array( array( 0, 0 ), array( 10, 0 ), array( 10, 10 ), array( 0, 10 ) );
chk( MAN_Municipios::punto_en_poligono( 5, 5, $poli ) === true, 'PIP: centro dentro' );
chk( MAN_Municipios::punto_en_poligono( 15, 5, $poli ) === false, 'PIP: fuera derecha' );
chk( MAN_Municipios::punto_en_poligono( -1, -1, $poli ) === false, 'PIP: fuera abajo-izq' );

/* ---------- Task 5: indice_deficit ---------- */
chk( MAN_Sync_Deficit::indice_deficit( 0, 100 ) === 100, 'Déficit: sequía total = 100' );
chk( MAN_Sync_Deficit::indice_deficit( 100, 100 ) === 0, 'Déficit: normal = 0' );
chk( MAN_Sync_Deficit::indice_deficit( 50, 100 ) === 50, 'Déficit: mitad = 50' );
chk( MAN_Sync_Deficit::indice_deficit( 200, 100 ) === 0, 'Déficit: exceso recorta a 0' );

/* ---------- SIVIGILA: catálogo de eventos sensibles al clima ---------- */
require __DIR__ . '/../includes/sync/class-man-sync-sivigila.php';
$eventos = \GobernacionNarino\MonitorAmbiental\MAN_Sync_Sivigila::eventos();

chk( count( $eventos ) === 15, 'SIVIGILA: 15 eventos catalogados' );

// Los códigos son la clave de la consulta: si cambian, el filtro deja de traer datos.
$esperados = array( '210', '220', '580', '217', '895', '420', '430', '470', '490', '480', '460', '495', '540', '320', '330' );
$faltan    = array_diff( $esperados, array_keys( $eventos ) );
chk( empty( $faltan ), 'SIVIGILA: están los 15 códigos verificados contra datos.gov.co' );

$etv = 0;
$eta = 0;
$letales = 0;
foreach ( $eventos as $cod => $e ) {
	chk_silencioso( isset( $e['grupo'], $e['corto'], $e['nombre'] ), 'evento ' . $cod . ' completo' );
	if ( 'ETV' === $e['grupo'] ) { $etv++; } elseif ( 'ETA' === $e['grupo'] ) { $eta++; }
	if ( ! empty( $e['letal'] ) ) { $letales++; }
}
chk( 13 === $etv, 'SIVIGILA: 13 eventos ETV (vectores)' );
chk( 2 === $eta, 'SIVIGILA: 2 eventos ETA (agua y alimentos)' );
chk( 2 === $letales, 'SIVIGILA: 2 eventos de mortalidad, separados de la incidencia' );

// Las ETA son las dos de transmisión hídrica; el resto es vectorial.
chk( 'ETA' === $eventos['320']['grupo'] && 'ETA' === $eventos['330']['grupo'], 'SIVIGILA: fiebre tifoidea y hepatitis A clasificadas como ETA' );
chk( 'ETV' === $eventos['470']['grupo'] && 'ETV' === $eventos['210']['grupo'], 'SIVIGILA: malaria y dengue clasificados como ETV' );
chk( ! empty( $eventos['580']['letal'] ) && ! empty( $eventos['540']['letal'] ), 'SIVIGILA: las dos mortalidades marcadas como letal' );

/* ---------- Resumen ---------- */
echo "\n" . ( $fallos === 0 ? "TODO OK" : "$fallos FALLO(S)" ) . "\n";
exit( $fallos === 0 ? 0 : 1 );
