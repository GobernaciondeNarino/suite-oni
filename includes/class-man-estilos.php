<?php
/**
 * Módulo de Apariencia (configurable).
 *
 * Convierte la opción `man_estilo` en variables CSS `--man-*` aplicadas al
 * contenedor `.man` que envuelve cada shortcode. Por defecto TODO es
 * minimalista puro: fondo transparente, sin bordes, sin sombras, sin franjas,
 * hereda tipografía y color de texto de la página anfitriona. Solo los colores
 * semánticos del clima (semáforo, escala del mapa) quedan fuera de configuración.
 *
 * @package MonitorAmbientalNarino
 */

namespace GobernacionNarino\MonitorAmbiental;

defined( 'ABSPATH' ) || exit;

final class MAN_Estilos {

	const HANDLE = 'man-estilos';

	public function __construct() {
		// Prioridad 5: registra el estilo antes de que los shortcodes lo encolen (10).
		add_action( 'wp_enqueue_scripts', array( $this, 'registrar' ), 5 );
	}

	/**
	 * Registra la hoja base e inyecta las variables de apariencia.
	 */
	public function registrar() {
		wp_register_style( self::HANDLE, MAN_URL . 'assets/css/estilos.css', array(), MAN_VERSION );
		wp_add_inline_style( self::HANDLE, self::css_global() );
	}

	/**
	 * Devuelve la configuración de apariencia fusionada con los valores por defecto.
	 *
	 * @return array
	 */
	public static function estilo() {
		$def = MAN_Activator::estilo_por_defecto();
		$cfg = get_option( 'man_estilo', array() );
		return wp_parse_args( is_array( $cfg ) ? $cfg : array(), $def );
	}

	/**
	 * Construye el bloque CSS con las variables globales bajo `.man`.
	 *
	 * @return string
	 */
	public static function css_global() {
		$e = self::estilo();

		$borde = ( 'none' === $e['borde'] || '0' === (string) $e['borde'] )
			? 'none'
			: self::sanitizar_css( $e['borde'] ) . ' solid ' . self::sanitizar_css( $e['borde_color'] );

		$vars = array(
			'--man-fondo'          => self::sanitizar_css( $e['fondo'] ),
			'--man-texto'          => self::sanitizar_css( $e['texto'] ),
			'--man-tipografia'     => self::sanitizar_css( $e['tipografia'] ),
			'--man-acento'         => self::sanitizar_css( $e['acento'] ),
			'--man-acento-2'       => self::sanitizar_css( $e['acento_2'] ),
			'--man-acento-tecnico' => self::sanitizar_css( $e['acento_tecnico'] ),
			'--man-mute'           => self::sanitizar_css( $e['mute'] ),
			'--man-borde-color'    => self::sanitizar_css( $e['borde_color'] ),
			'--man-borde'          => $borde,
			'--man-borde-radio'    => self::sanitizar_css( $e['borde_radio'] ),
			'--man-sombra'         => self::sanitizar_css( $e['sombra'] ),
			'--man-ancho-max'      => self::sanitizar_css( $e['ancho_max'] ),
			'--man-espaciado'      => self::sanitizar_css( $e['espaciado'] ),
		);

		$cuerpo = '';
		foreach ( $vars as $k => $v ) {
			$cuerpo .= $k . ':' . $v . ';';
		}
		return '.man{' . $cuerpo . '}';
	}

	/**
	 * Genera el estilo en línea de overrides por atributo de shortcode.
	 *
	 * @param array $atts Atributos (fondo, acento, borde, sombra, ancho…).
	 * @return string Cadena CSS lista para un atributo style (sin comillas).
	 */
	public static function estilo_inline( $atts ) {
		$map = array(
			'fondo'     => '--man-fondo',
			'acento'    => '--man-acento',
			'acento2'   => '--man-acento-2',
			'tecnico'   => '--man-acento-tecnico',
			'texto'     => '--man-texto',
			'sombra'    => '--man-sombra',
			'ancho'     => '--man-ancho-max',
			'espaciado' => '--man-espaciado',
			'radio'     => '--man-borde-radio',
		);

		$out = '';
		foreach ( $map as $att => $var ) {
			if ( isset( $atts[ $att ] ) && '' !== $atts[ $att ] ) {
				$out .= $var . ':' . self::sanitizar_css( $atts[ $att ] ) . ';';
			}
		}

		// El borde se compone aparte (ancho + color).
		if ( isset( $atts['borde'] ) && '' !== $atts['borde'] ) {
			$b = self::sanitizar_css( $atts['borde'] );
			$out .= '--man-borde:' . ( ( 'none' === $b || '0' === $b ) ? 'none' : $b . ' solid var(--man-borde-color,#e5e7eb)' ) . ';';
		}

		return $out;
	}

	/**
	 * Sanea un valor para inserción segura en CSS (anti-inyección).
	 *
	 * @param string $v Valor crudo.
	 * @return string
	 */
	public static function sanitizar_css( $v ) {
		$v = (string) $v;
		$v = str_replace( array( ';', '{', '}', '<', '>', '\\', '"', "'" ), '', $v );
		$v = preg_replace( '/url\s*\(/i', '', $v );
		$v = preg_replace( '/expression\s*\(/i', '', $v );
		return trim( $v );
	}
}
