<?php
/**
 * Endpoint REST que consulta Drupal.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expone POST /wp-json/salesbumm-sld/v1/access.
 *
 * Es el único endpoint que este plugin publica, y solo responde a Drupal.
 */
class AccessEndpoint {

	/**
	 * Verificador de firma.
	 *
	 * @var RequestVerifier
	 */
	private $verifier;

	/**
	 * Consulta de acceso a cursos.
	 *
	 * @var CourseAccess
	 */
	private $access;

	/**
	 * Constructor.
	 *
	 * @param RequestVerifier $verifier Verificador de firma.
	 * @param CourseAccess    $access   Consulta de acceso.
	 */
	public function __construct( RequestVerifier $verifier, CourseAccess $access ) {
		$this->verifier = $verifier;
		$this->access   = $access;
	}

	/**
	 * Registra la ruta.
	 */
	public function register_routes(): void {
		register_rest_route(
			SLD_REST_NAMESPACE,
			'/access',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'handle' ),
				// La verificación de firma va aquí y no en el callback: así una
				// petición no autenticada se rechaza antes de que se ejecute
				// ninguna lógica de negocio.
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'wp_user_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'course_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Autentica la petición mediante su firma HMAC.
	 *
	 * @param \WP_REST_Request $request Petición.
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		return $this->verifier->verify( $request );
	}

	/**
	 * Responde a la consulta de autorización.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id   = (int) $request->get_param( 'wp_user_id' );
		$course_id = (int) $request->get_param( 'course_id' );

		$has_access = $this->access->user_has_access( $user_id, $course_id );

		if ( null === $has_access ) {
			// No se ha podido determinar. Se responde con un error explícito
			// en lugar de con "false": Drupal debe poder distinguir una
			// denegación real de una avería, porque ante una avería puede
			// apoyarse en una autorización previamente validada en cache.
			return new \WP_REST_Response(
				array(
					'error'   => 'lms_unavailable',
					'message' => __( 'No se ha podido comprobar el acceso.', 'salesbumm-sld' ),
				),
				503
			);
		}

		return new \WP_REST_Response(
			array(
				'has_access' => $has_access,
				'wp_user_id' => $user_id,
				'course_id'  => (string) $course_id,
				'checked_at' => gmdate( 'c' ),
			),
			200
		);
	}
}
