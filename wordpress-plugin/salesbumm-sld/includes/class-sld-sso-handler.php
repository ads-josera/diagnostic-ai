<?php
/**
 * Emisión del token y redirección del alumno hacia Drupal.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Botón «Acceder al Diagnostic AI» y emisión del token.
 *
 * Decisión importante: el token NO se genera al pintar el botón, sino cuando
 * el alumno lo pulsa.
 *
 * Si el enlace del botón llevara el token incrustado, cualquier capa de caché
 * de página —y casi todo WordPress en producción tiene una— serviría a todos
 * los visitantes el token del primer alumno que cargó la página. Sería una
 * suplantación de identidad servida por la propia infraestructura, y además
 * silenciosa: el sitio funcionaría con normalidad.
 *
 * Por eso el botón apunta a admin-post.php, que WordPress nunca cachea, y el
 * token se emite en esa petición, ya con la sesión del alumno resuelta.
 */
class SsoHandler {

	/**
	 * Acción de admin-post.php.
	 */
	public const ACTION = 'sld_sso';

	/**
	 * Acción del nonce de WordPress.
	 */
	private const NONCE_ACTION = 'sld_sso_redirect';

	/**
	 * Firmante del token.
	 *
	 * @var JwtSigner
	 */
	private $signer;

	/**
	 * Consulta de acceso.
	 *
	 * @var CourseAccess
	 */
	private $access;

	/**
	 * Ajustes del plugin.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param JwtSigner    $signer   Firmante.
	 * @param CourseAccess $access   Consulta de acceso.
	 * @param Settings     $settings Ajustes.
	 */
	public function __construct( JwtSigner $signer, CourseAccess $access, Settings $settings ) {
		$this->signer   = $signer;
		$this->access   = $access;
		$this->settings = $settings;
	}

	/**
	 * Atiende la pulsación del botón y redirige al alumno.
	 */
	public function handle_redirect(): void {
		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		// El nonce protege frente a que un tercero fuerce la emisión de un
		// token desde otra página.
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->fail( __( 'El enlace ha caducado. Vuelve a la página anterior e inténtalo de nuevo.', 'salesbumm-sld' ), 403 );
		}

		$user      = wp_get_current_user();
		$course_id = $this->settings->get_course_id();
		$target    = $this->settings->get_drupal_sso_url();
		$secret    = Secrets::get( Secrets::JWT );

		if ( '' === $target || $course_id <= 0 || '' === $secret ) {
			// Configuración incompleta. Se le dice al alumno que no está
			// disponible; el detalle queda para el administrador.
			$this->log( 'Configuración incompleta: falta URL de destino, curso o secreto.' );
			$this->fail( __( 'El diagnóstico no está disponible en este momento.', 'salesbumm-sld' ), 503 );
		}

		$has_access = $this->access->user_has_access( (int) $user->ID, $course_id );

		if ( true !== $has_access ) {
			// WordPress ya sabe aquí que el alumno no tiene el curso, así que
			// se evita el viaje a Drupal para que le deniegue el acceso.
			$this->fail(
				__( 'Tu cuenta no tiene acceso a este diagnóstico. Si acabas de adquirir el curso, espera unos minutos e inténtalo de nuevo.', 'salesbumm-sld' ),
				403
			);
		}

		$claims = $this->signer->build_claims(
			$user,
			untrailingslashit( (string) home_url() ),
			$this->settings->get_audience(),
			$this->settings->get_token_ttl()
		);

		$token = $this->signer->sign( $claims, $secret );

		if ( '' === $token ) {
			$this->log( 'No se pudo firmar el token.' );
			$this->fail( __( 'El diagnóstico no está disponible en este momento.', 'salesbumm-sld' ), 503 );
		}

		// El token viaja en la URL porque es un redirect de navegador y no hay
		// alternativa práctica. Es aceptable porque dura segundos, se consume
		// una sola vez y el canal es HTTPS. Aun así, se envía una cabecera que
		// evita que la URL con el token acabe en el Referer de la página
		// siguiente.
		$separator = ( false === strpos( $target, '?' ) ) ? '?' : '&';
		$url       = $target . $separator . 'token=' . rawurlencode( $token );

		header( 'Referrer-Policy: no-referrer' );
		nocache_headers();
		wp_redirect( $url, 302 );
		exit;
	}

	/**
	 * Devuelve el marcado del botón de acceso.
	 *
	 * Se usa como shortcode: [salesbumm_diagnostic_button]
	 *
	 * @param array<string,mixed> $atts Atributos del shortcode.
	 */
	public function render_button( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'label' => __( 'Acceder al Diagnostic AI', 'salesbumm-sld' ),
				'class' => 'sld-button',
			),
			is_array( $atts ) ? $atts : array(),
			'salesbumm_diagnostic_button'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		$course_id = $this->settings->get_course_id();

		// Un alumno sin el curso no ve el botón. No es un control de
		// seguridad —el control real está en handle_redirect()— sino una
		// cortesía: ofrecer un botón que va a denegar el acceso es peor
		// experiencia que no ofrecerlo.
		if ( $course_id <= 0 || true !== $this->access->user_has_access( get_current_user_id(), $course_id ) ) {
			return '';
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION ),
			self::NONCE_ACTION
		);

		return sprintf(
			'<a class="%1$s" href="%2$s" rel="nofollow noreferrer">%3$s</a>',
			esc_attr( (string) $atts['class'] ),
			esc_url( $url ),
			esc_html( (string) $atts['label'] )
		);
	}

	/**
	 * Termina la petición con un mensaje para el alumno.
	 *
	 * @param string $message Mensaje.
	 * @param int    $status  Código HTTP.
	 */
	private function fail( string $message, int $status ): void {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Diagnostic AI', 'salesbumm-sld' ),
			array( 'response' => $status )
		);
	}

	/**
	 * Registra un problema de configuración.
	 *
	 * Nunca se registra el token ni el secreto.
	 *
	 * @param string $message Mensaje.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[salesbumm-sld] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
