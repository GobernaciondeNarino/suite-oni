<?php
/**
 * Plugin Name:  Monitor Ambiental y FenÃ³meno El NiÃ±o â€” NariÃ±o
 * Plugin URI:   https://gobiernoabierto.narino.gov.co/datos/enso/
 * Description:  VisualizaciÃ³n ciudadana de ENSO (El NiÃ±o / La NiÃ±a) y condiciones ambientales de los 64 municipios de NariÃ±o. Shortcodes independientes, APIs en tiempo real y front minimalista configurable.
 * Version:      1.18.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:       GobernaciÃ³n de NariÃ±o Â· SecretarÃ­a TIC, InnovaciÃ³n y Gobierno Abierto
 * Author URI:   https://gobiernoabierto.narino.gov.co
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  monitor-ambiental-narino
 * Domain Path:  /languages
 *
 * Fuentes de datos (atribuciÃ³n obligatoria): NOAA/CPC, IDEAM (datos.gov.co),
 * Open-Meteo (CC BY 4.0), NASA POWER, IOC/VLIZ Sea Level, INS/SIVIGILA, DANE.
 */

// Salida directa bloqueada: ningÃºn acceso fuera de WordPress.
defined( 'ABSPATH' ) || exit;

use GobernacionNarino\MonitorAmbiental\MAN_Plugin;
use GobernacionNarino\MonitorAmbiental\MAN_Activator;

/* -------------------------------------------------------------------------
 * Constantes del plugin
 * ---------------------------------------------------------------------- */
define( 'MAN_VERSION', '1.18.1' );
define( 'MAN_FILE', __FILE__ );
define( 'MAN_DIR', plugin_dir_path( __FILE__ ) );      // .../monitor-ambiental-narino/
define( 'MAN_URL', plugin_dir_url( __FILE__ ) );        // URL pÃºblica de assets
define( 'MAN_BASENAME', plugin_basename( __FILE__ ) );

/* -------------------------------------------------------------------------
 * Carga del orquestador y de todas las dependencias.
 * Se hace en el load del archivo (no en un hook) para que el activador
 * tenga las clases disponibles al registrar tablas y cron.
 * ---------------------------------------------------------------------- */
require_once MAN_DIR . 'includes/class-man-plugin.php';
MAN_Plugin::cargar_dependencias();

/* -------------------------------------------------------------------------
 * Ciclo de vida
 * ---------------------------------------------------------------------- */
register_activation_hook( __FILE__, array( MAN_Activator::class, 'activar' ) );
register_deactivation_hook( __FILE__, array( MAN_Activator::class, 'desactivar' ) );

// Arranque: instancia singleton en plugins_loaded (registra todos los hooks).
add_action( 'plugins_loaded', array( MAN_Plugin::class, 'instancia' ) );
