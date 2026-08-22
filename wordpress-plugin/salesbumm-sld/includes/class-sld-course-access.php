<?php
/**
 * Consulta del derecho de acceso a un curso de LearnDash.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Responde a la única pregunta que Drupal le hace a WordPress.
 *
 * Es deliberadamente estrecha: recibe un usuario y un curso, y devuelve sí o
 * no. No expone el perfil del alumno, ni su progreso, ni la lista de cursos
 * que tiene. Cuanto menos viaje por el canal, menos hay que proteger.
 *
 * Toda la lógica de LearnDash queda encapsulada aquí. Si el cliente cambiara
 * de LMS, se reescribe esta clase y el módulo de Drupal no se entera.
 */
class CourseAccess {

	/**
	 * Comprueba si un usuario tiene acceso a un curso.
	 *
	 * @param int $user_id   Identificador del usuario en WordPress.
	 * @param int $course_id Identificador del curso en LearnDash.
	 *
	 * @return bool|null TRUE o FALSE con una respuesta fiable; NULL si no se
	 *                   puede determinar, por ejemplo si LearnDash no está
	 *                   activo.
	 */
	public function user_has_access( int $user_id, int $course_id ): ?bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}

		// Un usuario que no existe no tiene acceso, y conviene comprobarlo
		// antes de preguntar a LearnDash.
		if ( ! get_userdata( $user_id ) ) {
			return false;
		}

		if ( ! $this->course_exists( $course_id ) ) {
			return false;
		}

		if ( ! function_exists( 'sfwd_lms_has_access' ) ) {
			// LearnDash no está disponible. Se devuelve NULL en lugar de FALSE
			// para que el endpoint pueda distinguir "no tiene acceso" de "no
			// he podido comprobarlo": son situaciones distintas y Drupal las
			// trata de forma distinta.
			return null;
		}

		// sfwd_lms_has_access() es la vía canónica de LearnDash y ya contempla
		// las distintas formas de obtener acceso: inscripción directa, compra,
		// pertenencia a un grupo o acceso abierto.
		return (bool) sfwd_lms_has_access( $course_id, $user_id );
	}

	/**
	 * Comprueba que el identificador corresponda a un curso publicado.
	 *
	 * Evita responder afirmativamente sobre un identificador que apunte a otro
	 * tipo de contenido o a un curso en papelera.
	 *
	 * @param int $course_id Identificador del curso.
	 */
	private function course_exists( int $course_id ): bool {
		$post = get_post( $course_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		$course_post_type = function_exists( 'learndash_get_post_type_slug' )
			? learndash_get_post_type_slug( 'course' )
			: 'sfwd-courses';

		return $post->post_type === $course_post_type;
	}
}
