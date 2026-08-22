<?php
/**
 * Decide si un alumno tiene derecho al diagnóstico.
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
 * La respuesta se compone de dos condiciones independientes:
 *
 *  1. El alumno posee alguno de los cursos autorizadores.
 *  2. Su acceso al diagnóstico sigue dentro del periodo de vigencia.
 *
 * Que sean independientes es el punto central del diseño. El alumno conserva
 * el curso —sus vídeos, sus materiales— indefinidamente, pero el acceso al
 * diagnóstico caduca. Derivar la caducidad del curso sería imposible
 * precisamente porque el curso no caduca.
 *
 * Toda la lógica de LearnDash queda encapsulada aquí. Si el cliente cambiara
 * de LMS, se reescribe esta clase y el módulo de Drupal no se entera.
 */
class CourseAccess {

	/**
	 * Ajustes del plugin.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Reloj de vigencia.
	 *
	 * @var AccessClock
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Ajustes.
	 * @param AccessClock $clock    Reloj de vigencia.
	 */
	public function __construct( Settings $settings, AccessClock $clock ) {
		$this->settings = $settings;
		$this->clock    = $clock;
	}

	/**
	 * Evalúa el derecho de acceso al diagnóstico de un usuario.
	 *
	 * @param int $user_id Identificador del usuario en WordPress.
	 *
	 * @return array{has_access: bool, started_at: ?int, expires_at: ?int, course_id: ?int, reason: string}|null
	 *   La decisión, o NULL si no se ha podido determinar (por ejemplo, si
	 *   LearnDash no está disponible). NULL y "sin acceso" son cosas distintas
	 *   y Drupal las trata de forma distinta.
	 */
	public function evaluate( int $user_id ): ?array {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return $this->deny( 'usuario inexistente' );
		}

		$courses = $this->settings->get_course_ids();

		if ( array() === $courses ) {
			return $this->deny( 'sin cursos autorizadores configurados' );
		}

		if ( ! function_exists( 'sfwd_lms_has_access' ) ) {
			// No se puede determinar. Se distingue de una denegación para que
			// Drupal pueda aplicar su política ante averías.
			return null;
		}

		$owned = $this->find_owned_course( $user_id, $courses );

		if ( null === $owned ) {
			return $this->deny( 'no posee ningún curso autorizador' );
		}

		// El alumno tiene el curso: si nunca se le inició el reloj, se inicia
		// ahora. El origen de esa fecha es una decisión de negocio y por eso
		// es configurable.
		$this->clock->start_if_absent( $user_id, $this->resolve_start( $user_id, $owned ) );

		if ( ! $this->clock->is_active( $user_id ) ) {
			return array(
				'has_access' => false,
				'started_at' => $this->clock->get_started_at( $user_id ),
				'expires_at' => $this->clock->get_expires_at( $user_id ),
				'course_id'  => $owned,
				'reason'     => 'periodo de acceso caducado',
			);
		}

		return array(
			'has_access' => true,
			// Cuando EMPEZO el periodo, no solo cuando acaba. Drupal lo
			// necesita para poder limitar el diagnostico a uno por periodo:
			// sin este dato no hay forma de saber que sesiones pertenecen al
			// periodo vigente y cuales son de una compra anterior.
			'started_at' => $this->clock->get_started_at( $user_id ),
			'expires_at' => $this->clock->get_expires_at( $user_id ),
			'course_id'  => $owned,
			'reason'     => 'acceso vigente',
		);
	}

	/**
	 * Reinicia el periodo de acceso de un usuario.
	 *
	 * Es lo que ocurre cuando el alumno vuelve a comprar: recupera el periodo
	 * completo desde ese momento.
	 *
	 * @param int    $user_id Usuario.
	 * @param string $reason  Motivo, para soporte.
	 */
	public function reactivate( int $user_id, string $reason ): void {
		$this->clock->start( $user_id, $reason );
	}

	/**
	 * Devuelve el primer curso autorizador que posea el alumno.
	 *
	 * @param int   $user_id Usuario.
	 * @param int[] $courses Cursos configurados.
	 */
	private function find_owned_course( int $user_id, array $courses ): ?int {
		foreach ( $courses as $course_id ) {
			if ( ! $this->course_exists( $course_id ) ) {
				continue;
			}

			// sfwd_lms_has_access() es la vía canónica de LearnDash y ya
			// contempla inscripción directa, compra, grupo y acceso abierto.
			if ( sfwd_lms_has_access( $course_id, $user_id ) ) {
				return $course_id;
			}
		}

		return null;
	}

	/**
	 * Decide desde cuándo cuenta el periodo de acceso de un alumno nuevo.
	 *
	 * Es una decisión de negocio, no técnica, y por eso se configura:
	 *
	 *  - Desde el alta en el curso: quien compró hace más de un año queda
	 *    caducado de inmediato. Es lo que dice el requisito al pie de la letra.
	 *  - Desde ahora: todos los alumnos existentes reciben el periodo completo
	 *    a partir del día en que se active la caducidad.
	 *
	 * Si se pide la fecha de alta y LearnDash no la expone, se cae a "ahora"
	 * en lugar de fallar: es preferible conceder de más a bloquear a un alumno
	 * por un dato que no hemos podido leer.
	 *
	 * @param int $user_id   Usuario.
	 * @param int $course_id Curso que le da acceso.
	 */
	private function resolve_start( int $user_id, int $course_id ): int {
		if ( 'enrollment' !== $this->settings->get_access_start_origin() ) {
			return time();
		}

		if ( function_exists( 'ld_course_access_from' ) ) {
			$from = ld_course_access_from( $course_id, $user_id );

			if ( is_numeric( $from ) && (int) $from > 0 ) {
				return (int) $from;
			}
		}

		return time();
	}

	/**
	 * Comprueba que el identificador corresponda a un curso publicado.
	 *
	 * @param int $course_id Identificador del curso.
	 */
	private function course_exists( int $course_id ): bool {
		if ( $course_id <= 0 ) {
			return false;
		}

		$post = get_post( $course_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		$course_post_type = function_exists( 'learndash_get_post_type_slug' )
			? learndash_get_post_type_slug( 'course' )
			: 'sfwd-courses';

		return $post->post_type === $course_post_type;
	}

	/**
	 * Construye una denegación.
	 *
	 * @param string $reason Motivo interno.
	 *
	 * @return array{has_access: bool, expires_at: ?int, course_id: ?int, reason: string}
	 */
	private function deny( string $reason ): array {
		return array(
			'has_access' => false,
			'started_at' => null,
			'expires_at' => null,
			'course_id'  => null,
			'reason'     => $reason,
		);
	}
}
