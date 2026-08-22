<?php
/**
 * Acceso a los secretos compartidos con Drupal.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto único de acceso a los secretos del puente.
 *
 * Los secretos viven en wp-config.php como constantes, no en la tabla de
 * opciones. La razón es que la base de datos de WordPress se copia con
 * frecuencia —a un entorno de pruebas, a un volcado para depurar, a una copia
 * de seguridad que acaba en un disco compartido— y un secreto guardado ahí
 * viaja en todas esas copias. Una constante de wp-config.php se queda en el
 * servidor donde se definió.
 *
 * Es el mismo criterio que aplica el lado de Drupal, donde los secretos vienen
 * de variables de entorno y nunca de la configuración exportable.
 */
class Secrets {

	/**
	 * Secreto con el que se firma el token de acceso del alumno.
	 */
	public const JWT = 'SLD_JWT_SHARED_SECRET';

	/**
	 * Secreto con el que Drupal firma sus consultas de autorización.
	 */
	public const HMAC = 'SLD_WP_HMAC_SECRET';

	/**
	 * Longitud mínima de un secreto, en bytes.
	 *
	 * No es una preferencia: la librería que verifica el token del otro lado
	 * (firebase/php-jwt) rechaza cualquier clave HMAC de menos de 256 bits
	 * para HS256, y lo hace lanzando una excepción genérica en el momento del
	 * login. Comprobarlo aquí convierte ese fallo opaco y remoto en un aviso
	 * claro en la pantalla de quien configura el plugin.
	 *
	 * `openssl rand -hex 32` produce 64 caracteres y cumple de sobra.
	 */
	public const MIN_LENGTH = 32;

	/**
	 * Devuelve un secreto, o cadena vacía si no está definido.
	 *
	 * @param string $name Nombre de la constante.
	 */
	public static function get( string $name ): string {
		if ( ! defined( $name ) ) {
			return '';
		}

		$value = constant( $name );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Indica si un secreto está definido, sin revelar su valor.
	 *
	 * @param string $name Nombre de la constante.
	 */
	public static function has( string $name ): bool {
		return '' !== self::get( $name );
	}

	/**
	 * Indica si un secreto tiene longitud suficiente.
	 *
	 * @param string $name Nombre de la constante.
	 */
	public static function is_long_enough( string $name ): bool {
		return strlen( self::get( $name ) ) >= self::MIN_LENGTH;
	}

	/**
	 * Nombres de los secretos que faltan por definir.
	 *
	 * @return string[]
	 */
	public static function missing(): array {
		$missing = array();

		foreach ( self::all() as $name ) {
			if ( ! self::has( $name ) ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}

	/**
	 * Nombres de los secretos definidos pero demasiado cortos.
	 *
	 * @return string[]
	 */
	public static function too_short(): array {
		$weak = array();

		foreach ( self::all() as $name ) {
			if ( self::has( $name ) && ! self::is_long_enough( $name ) ) {
				$weak[] = $name;
			}
		}

		return $weak;
	}

	/**
	 * Todos los secretos que el puente necesita.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::JWT, self::HMAC );
	}
}
