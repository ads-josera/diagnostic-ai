<?php
/**
 * Arranque de las pruebas del plugin: WordPress y LearnDash simulados.
 *
 * El plugin corre en el WordPress del cliente, que no está aquí. Estas
 * funciones imitan SOLO lo que el plugin usa —opciones, meta de usuario,
 * cursos, acceso de LearnDash— con un almacén en memoria que cada prueba deja
 * como quiere. Así se prueba la lógica de acceso sin instalar nada.
 *
 * Nació el 13-09-2026, antes de añadir el acceso por suscripción: la primera
 * tanda de pruebas fija lo que el plugin YA hacía, para que el cambio no pueda
 * romperlo sin que se note.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

/**
 * El «WordPress» de la prueba.
 */
final class SldWp {

	/**
	 * Opciones.
	 *
	 * @var array<string, mixed>
	 */
	public static $options = array();

	/**
	 * Meta de usuario: [uid][clave] => valor.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $user_meta = array();

	/**
	 * Usuarios que existen.
	 *
	 * @var array<int, bool>
	 */
	public static $users = array();

	/**
	 * Entradas (cursos): [id] => [estado, tipo].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public static $posts = array();

	/**
	 * Acceso de LearnDash: [uid][curso] => fecha de alta.
	 *
	 * @var array<int, array<int, int>>
	 */
	public static $access = array();

	/**
	 * Deja todo vacío.
	 */
	public static function reset(): void {
		self::$options   = array();
		self::$user_meta = array();
		self::$users     = array();
		self::$posts     = array();
		self::$access    = array();
	}

	/**
	 * Publica un curso de LearnDash.
	 *
	 * @param int    $id     Curso.
	 * @param string $status Estado.
	 */
	public static function course( int $id, string $status = 'publish' ): void {
		self::$posts[ $id ] = array( $status, 'sfwd-courses' );
	}

	/**
	 * Da (o quita) el acceso a un curso, como hace LearnDash.
	 *
	 * @param int      $uid    Usuario.
	 * @param int      $course Curso.
	 * @param int|null $since  Fecha de alta; NULL quita el acceso.
	 */
	public static function access( int $uid, int $course, ?int $since = null ): void {
		if ( null === $since ) {
			unset( self::$access[ $uid ][ $course ] );
			return;
		}
		self::$access[ $uid ][ $course ] = $since;
	}
}

/**
 * Entrada mínima de WordPress.
 */
class WP_Post {
	/**
	 * Estado.
	 *
	 * @var string
	 */
	public $post_status = '';

	/**
	 * Tipo.
	 *
	 * @var string
	 */
	public $post_type = '';
}

function get_option( $name, $default = false ) {
	return array_key_exists( $name, SldWp::$options ) ? SldWp::$options[ $name ] : $default;
}

function get_user_meta( $uid, $key, $single = false ) {
	return SldWp::$user_meta[ (int) $uid ][ $key ] ?? '';
}

function update_user_meta( $uid, $key, $value ) {
	SldWp::$user_meta[ (int) $uid ][ $key ] = $value;
	return true;
}

function delete_user_meta( $uid, $key ) {
	unset( SldWp::$user_meta[ (int) $uid ][ $key ] );
	return true;
}

function get_userdata( $uid ) {
	return isset( SldWp::$users[ (int) $uid ] ) ? (object) array( 'ID' => (int) $uid ) : false;
}

function get_post( $id ) {
	if ( ! isset( SldWp::$posts[ (int) $id ] ) ) {
		return null;
	}
	$post              = new WP_Post();
	$post->post_status = SldWp::$posts[ (int) $id ][0];
	$post->post_type   = SldWp::$posts[ (int) $id ][1];
	return $post;
}

function learndash_get_post_type_slug( $type ) {
	return 'course' === $type ? 'sfwd-courses' : $type;
}

function sfwd_lms_has_access( $course_id, $uid ) {
	return isset( SldWp::$access[ (int) $uid ][ (int) $course_id ] );
}

function ld_course_access_from( $course_id, $uid ) {
	return SldWp::$access[ (int) $uid ][ (int) $course_id ] ?? 0;
}

function absint( $value ) {
	return abs( (int) $value );
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function add_action( ...$args ) {
	return true;
}

function add_filter( ...$args ) {
	return true;
}

function add_shortcode( ...$args ) {
	return true;
}

$raiz = dirname( __DIR__ );
require_once $raiz . '/includes/class-sld-secrets.php';
require_once $raiz . '/includes/class-sld-settings.php';
require_once $raiz . '/includes/class-sld-jwt-signer.php';
require_once $raiz . '/includes/class-sld-request-verifier.php';
require_once $raiz . '/includes/class-sld-access-clock.php';
require_once $raiz . '/includes/class-sld-course-access.php';
require_once $raiz . '/includes/class-sld-access-endpoint.php';
require_once $raiz . '/includes/class-sld-sso-handler.php';
require_once $raiz . '/includes/class-sld-plugin.php';
