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
					// course_id ya no es obligatorio. La lista de cursos que
					// autorizan y la vigencia son reglas de negocio y viven
					// aquí, no en Drupal: WordPress es quien sabe qué compró
					// cada alumno y cuándo.
					'course_id'  => array(
						'required'          => false,
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
		$user_id = (int) $request->get_param( 'wp_user_id' );

		$decision = $this->access->evaluate( $user_id );

		if ( null === $decision ) {
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
				'has_access' => $decision['has_access'],
				'wp_user_id' => $user_id,
				// Curso que concedió el acceso, o null si ninguno lo hizo.
				'course_id'  => null === $decision['course_id'] ? null : (string) $decision['course_id'],
				// Momento en que caduca, en formato ISO 8601. Null significa
				// que no caduca o que no llegó a concederse. Drupal lo usa
				// para avisar al alumno antes de que expire.
				'expires_at' => null === $decision['expires_at'] ? null : gmdate( 'c', $decision['expires_at'] ),
				'checked_at' => gmdate( 'c' ),
			),
			200
		);
	}
}
