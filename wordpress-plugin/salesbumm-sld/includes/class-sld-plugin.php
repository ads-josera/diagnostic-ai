<?php
/**
 * Ensamblado del plugin.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto de ensamblado: construye las piezas y las engancha a WordPress.
 *
 * Concentrar aquí los add_action deja el resto de clases libres de acoplarse
 * al ciclo de vida de WordPress, de modo que pueden probarse por separado.
 */
class Plugin {

	/**
	 * Instancia única.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Manejador de acceso.
	 *
	 * @var SsoHandler
	 */
	private $sso;

	/**
	 * Endpoint REST.
	 *
	 * @var AccessEndpoint
	 */
	private $endpoint;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new Settings();

		$course_access = new CourseAccess();

		$this->sso = new SsoHandler(
			new JwtSigner(),
			$course_access,
			$this->settings
		);

		$this->endpoint = new AccessEndpoint(
			new RequestVerifier(),
			$course_access
		);
	}

	/**
	 * Devuelve la instancia única.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Engancha el plugin al ciclo de vida de WordPress.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this->settings, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings, 'register_settings' ) );

		add_action( 'rest_api_init', array( $this->endpoint, 'register_routes' ) );

		// Solo la variante con sesión: emitir un token para un visitante
		// anónimo no tendría sentido, y registrar la variante _nopriv abriría
		// el endpoint a cualquiera.
		add_action( 'admin_post_' . SsoHandler::ACTION, array( $this->sso, 'handle_redirect' ) );

		add_shortcode( 'salesbumm_diagnostic_button', array( $this->sso, 'render_button' ) );

		add_action( 'admin_notices', array( $this, 'render_configuration_notice' ) );
	}

	/**
	 * Avisa al administrador si falta configuración.
	 *
	 * Sin este aviso, una instalación a medias se manifiesta como un botón que
	 * no aparece o un acceso que se deniega, sin ninguna pista de la causa.
	 */
	public function render_configuration_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$problems = array();

		if ( Secrets::missing() ) {
			$problems[] = __( 'faltan secretos en wp-config.php', 'salesbumm-sld' );
		}

		if ( Secrets::too_short() ) {
			$problems[] = __( 'algún secreto es demasiado corto y hará fallar el acceso', 'salesbumm-sld' );
		}

		if ( '' === $this->settings->get_drupal_sso_url() ) {
			$problems[] = __( 'falta la URL de acceso en Drupal', 'salesbumm-sld' );
		}

		if ( $this->settings->get_course_id() <= 0 ) {
			$problems[] = __( 'falta el curso que da acceso', 'salesbumm-sld' );
		}

		if ( ! function_exists( 'sfwd_lms_has_access' ) ) {
			$problems[] = __( 'LearnDash no está activo', 'salesbumm-sld' );
		}

		if ( ! $problems ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s. <a href="%s">%s</a></p></div>',
			esc_html__( 'Diagnostic AI:', 'salesbumm-sld' ),
			esc_html( implode( ', ', $problems ) ),
			esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE_SLUG ) ),
			esc_html__( 'Revisar la configuración', 'salesbumm-sld' )
		);
	}
}
