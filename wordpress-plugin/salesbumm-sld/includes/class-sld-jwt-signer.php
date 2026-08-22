<?php
/**
 * Firma del token de acceso del alumno.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emite el JWT firmado con el que el alumno entra en Drupal.
 *
 * Se implementa la firma a mano en lugar de traer una librería, y conviene
 * explicar por qué eso es aceptable aquí y no lo sería del otro lado.
 *
 * Los fallos graves de JWT están casi todos en la VERIFICACIÓN: aceptar
 * "alg": "none", confundir HS256 con RS256, no comprobar la expiración. Nada
 * de eso ocurre al firmar. Firmar es concatenar dos JSON en base64url y
 * calcular un HMAC: hay una sola ruta de código y ningún dato de entrada que
 * pueda alterar el algoritmo.
 *
 * La verificación, que es la parte delicada, ocurre en Drupal y allí sí se usa
 * una librería auditada (firebase/php-jwt).
 *
 * A cambio, el plugin no necesita Composer ni un directorio vendor, lo que
 * simplifica mucho instalarlo y mantenerlo en el WordPress del cliente.
 */
class JwtSigner {

	/**
	 * Codifica un conjunto de claims como JWT HS256.
	 *
	 * @param array<string,mixed> $claims Claims del token.
	 * @param string              $secret Secreto compartido.
	 *
	 * @return string Token firmado, o cadena vacía si no hay secreto.
	 */
	public function sign( array $claims, string $secret ): string {
		if ( '' === $secret ) {
			return '';
		}

		$header = array(
			'typ' => 'JWT',
			'alg' => 'HS256',
		);

		$segments = array(
			$this->base64url_encode( (string) wp_json_encode( $header ) ),
			$this->base64url_encode( (string) wp_json_encode( $claims ) ),
		);

		$signing_input = implode( '.', $segments );
		$signature     = hash_hmac( 'sha256', $signing_input, $secret, true );

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Construye los claims del token de un usuario.
	 *
	 * `email` y `name` viajan solo para poblar el perfil en Drupal la primera
	 * vez. La identidad es `sub`: Drupal no autoriza por correo (§11).
	 *
	 * @param \WP_User $user     Usuario autenticado.
	 * @param string   $issuer   Emisor: la URL de este WordPress.
	 * @param string   $audience Destinatario: la URL del Drupal.
	 * @param int      $ttl      Vigencia en segundos.
	 *
	 * @return array<string,mixed>
	 */
	public function build_claims( \WP_User $user, string $issuer, string $audience, int $ttl ): array {
		$now = time();

		return array(
			'iss'   => $issuer,
			'aud'   => $audience,
			'sub'   => (string) $user->ID,
			// Identificador único del token. Drupal lo consume una sola vez,
			// de modo que reenviar el mismo token no vuelve a dar acceso.
			'jti'   => wp_generate_uuid4(),
			'iat'   => $now,
			'exp'   => $now + $ttl,
			'email' => (string) $user->user_email,
			'name'  => (string) $user->display_name,
		);
	}

	/**
	 * Codificación base64 segura para URL, sin relleno.
	 *
	 * @param string $data Datos a codificar.
	 */
	private function base64url_encode( string $data ): string {
		// base64 aquí no oculta nada: es la codificación que el propio formato
		// JWT exige para sus tres segmentos. El aviso del verificador apunta a
		// su uso para disimular código, que no es el caso.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
