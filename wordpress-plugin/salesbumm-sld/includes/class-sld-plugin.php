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
	/**
	 * Consulta de acceso, expuesta para los enganches de reactivación.
	 *
	 * @var CourseAccess
	 */
	private $course_access;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->settings = new Settings();

		$course_access       = new CourseAccess( $this->settings, new AccessClock( $this->settings ) );
		$this->course_access = $course_access;

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

		// Reactivación: volver a darle acceso a un curso autorizador reinicia
		// el periodo. Es lo que ocurre cuando el alumno compra de nuevo.
		add_action( 'learndash_update_course_access', array( $this, 'on_course_access_granted' ), 10, 4 );
	}

	/**
	 * Reinicia el periodo cuando se concede acceso a un curso autorizador.
	 *
	 * LearnDash dispara esta acción tanto en una compra como en una alta
	 * manual o por grupo. Se filtra por los cursos configurados para no
	 * reactivar por la compra de un curso cualquiera del catálogo.
	 *
	 * @param int  $user_id   Usuario.
	 * @param int  $course_id Curso.
	 * @param array $access_list Lista de accesos. No se usa.
	 * @param bool $remove    TRUE si se está RETIRANDO el acceso.
	 */
	public function on_course_access_granted( $user_id, $course_id, $access_list = array(), $remove = false ): void {
		// Retirar el acceso a un curso no debe reiniciar nada.
		if ( $remove ) {
			return;
		}

		$user_id   = (int) $user_id;
		$course_id = (int) $course_id;

		if ( $user_id <= 0 || ! in_array( $course_id, $this->settings->get_course_ids(), true ) ) {
			return;
		}

		$this->course_access->reactivate(
			$user_id,
			sprintf( 'reactivación por acceso al curso %d', $course_id )
		);
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

		if ( array() === $this->settings->get_course_ids() ) {
			$problems[] = __( 'faltan los cursos que dan acceso', 'salesbumm-sld' );
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
