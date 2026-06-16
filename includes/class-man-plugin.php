<?php
/**
 * Orquestador singleton del plugin.
 *
 * Responsabilidad única: cargar las dependencias y registrar los hooks de
 * cada subsistema (estilos, REST, shortcodes, sincronización y admin).
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Plugin {

	/** @var MAN_Plugin|null Instancia única. */
	private static $instancia = null;

	/** @var bool Evita requerir las dependencias dos veces. */
	private static $cargado = false;

	/** @var MAN_Estilos */
	public $estilos;
	/** @var MAN_Rest */
	public $rest;
	/** @var MAN_Shortcodes */
	public $shortcodes;
	/** @var MAN_Sync */
	public $sync;
	/** @var MAN_Admin|null */
	public $admin = null;

	/**
	 * Devuelve (creando si hace falta) la instancia única.
	 *
	 * @return MAN_Plugin
	 */
	public static function instancia() {
		if ( null === self::$instancia ) {
			self::$instancia = new self();
		}
		return self::$instancia;
	}

	/**
	 * Requiere todos los archivos de clase del plugin.
	 * Idempotente: seguro llamarlo varias veces.
	 */
	public static function cargar_dependencias() {
		if ( self::$cargado ) {
			return;
		}

		$base = MAN_DIR . 'includes/';

		// Núcleo.
		require_once $base . 'class-man-activator.php';
		require_once $base . 'class-man-cache.php';
		require_once $base . 'class-man-security.php';
		require_once $base . 'class-man-estilos.php';
		require_once $base . 'class-man-rest.php';

		// Datos.
		require_once $base . 'data/class-man-municipios.php';
		require_once $base . 'data/class-man-views.php';

		// Análisis.
		require_once $base . 'analysis/class-man-enso.php';
		require_once $base . 'analysis/class-man-forecast.php';
		require_once $base . 'analysis/class-man-risk.php';
		require_once $base . 'analysis/class-man-texto.php';

		// Sincronización (Capa 1 — cron).
		require_once $base . 'sync/class-man-sync-oni.php';
		require_once $base . 'sync/class-man-sync-ideam.php';
		require_once $base . 'sync/class-man-sync-sivigila.php';
		require_once $base . 'sync/class-man-sync-sealevel.php';
		require_once $base . 'sync/class-man-sync-iri.php';
		require_once $base . 'sync/class-man-sync-firms.php';
		require_once $base . 'sync/class-man-sync-deficit.php';
		require_once $base . 'sync/class-man-sync.php';

		// Presentación.
		require_once $base . 'shortcodes/class-man-shortcodes.php';

		// Administración (solo se usa en el panel, pero se requiere siempre
		// para que las acciones AJAX/cron registradas existan).
		require_once $base . 'admin/class-man-api-config.php';
		require_once $base . 'admin/class-man-admin.php';

		self::$cargado = true;
	}

	/**
	 * Constructor privado: registra los hooks de los subsistemas.
	 */
	private function __construct() {
		self::cargar_dependencias();

		// Idioma (es_CO).
		add_action( 'init', array( $this, 'cargar_textdomain' ) );

		// Migración al actualizar (en admin): siembra fuentes nuevas sin reactivar.
		add_action( 'admin_init', array( MAN_Activator::class, 'migrar_si_necesario' ) );

		// Subsistemas: cada uno registra sus propios hooks en su constructor.
		$this->estilos    = new MAN_Estilos();
		$this->rest       = new MAN_Rest();
		$this->shortcodes = new MAN_Shortcodes();
		$this->sync       = new MAN_Sync();

		if ( is_admin() ) {
			$this->admin = new MAN_Admin();
		}
	}

	/**
	 * Carga las traducciones del plugin.
	 */
	public function cargar_textdomain() {
		load_plugin_textdomain(
			'monitor-ambiental-narino',
			false,
			dirname( MAN_BASENAME ) . '/languages'
		);
	}

	/** Clonación e hidratación deshabilitadas (singleton). */
	private function __clone() {}
	public function __wakeup() {
		throw new \Exception( 'No se permite deserializar MAN_Plugin.' );
	}
}
