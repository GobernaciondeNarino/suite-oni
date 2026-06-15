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

/* ---------- Resumen ---------- */
echo "\n" . ( $fallos === 0 ? "TODO OK" : "$fallos FALLO(S)" ) . "\n";
exit( $fallos === 0 ? 0 : 1 );
