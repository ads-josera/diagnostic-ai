<?php
/**
 * Verificación de las peticiones firmadas que llegan de Drupal.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autentica las consultas de autorización que hace Drupal.
 *
 * Este endpoint no usa cuentas de WordPress ni contraseñas de aplicación: se
 * autentica con una firma HMAC sobre el cuerpo de la petición. Así no existe
 * ninguna credencial de usuario que pueda robarse ni ampliarse de alcance, y
 * el endpoint no puede hacer nada más que responder sí o no sobre un curso.
 *
 * La firma sola no basta: alguien que capture una petición válida podría
 * repetirla. Por eso se exige además una marca de tiempo reciente y un nonce
 * de un solo uso.
 */
class RequestVerifier {

	/**
	 * Cabeceras del protocolo.
	 */
	public const HEADER_TIMESTAMP = 'X-SLD-Timestamp';
	public const HEADER_NONCE     = 'X-SLD-Nonce';
	public const HEADER_SIGNATURE = 'X-SLD-Signature';

	/**
	 * Desfase máximo admitido entre los relojes de Drupal y WordPress.
	 *
	 * Son máquinas distintas y sus relojes no coinciden al segundo. Cinco
	 * minutos tolera esa deriva sin dejar una ventana de repetición amplia.
	 */
	private const MAX_CLOCK_SKEW = 300;

	/**
	 * Cuánto se recuerda un nonce ya usado.
	 *
	 * Basta con que supere la ventana de reloj: pasado ese punto, la propia
	 * marca de tiempo ya rechaza la petición.
	 */
	private const NONCE_TTL = 600;

	/**
	 * Prefijo de los transients que guardan los nonces consumidos.
	 */
	private const NONCE_PREFIX = 'sld_nonce_';

	/**
	 * Verifica una petición entrante.
	 *
	 * @param \WP_REST_Request $request Petición.
	 *
	 * @return true|\WP_Error TRUE si es legítima; WP_Error si no.
	 */
	public function verify( \WP_REST_Request $request ) {
		$secret = Secrets::get( Secrets::HMAC );

		if ( '' === $secret ) {
			// Sin secreto no se puede verificar nada, así que no se atiende a
			// nadie. Fallar cerrado es lo correcto: lo contrario dejaría el
			// endpoint abierto justo cuando está mal configurado.
			return $this->denied( 'not_configured' );
		}

		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$nonce     = (string) $request->get_header( self::HEADER_NONCE );
		$signature = (string) $request->get_header( self::HEADER_SIGNATURE );
		$body      = (string) $request->get_body();

		if ( '' === $timestamp || '' === $nonce || '' === $signature ) {
			return $this->denied( 'missing_headers' );
		}

		if ( ! $this->is_timestamp_fresh( $timestamp ) ) {
			return $this->denied( 'stale_timestamp' );
		}

		if ( ! $this->is_signature_valid( $timestamp, $nonce, $body, $signature, $secret ) ) {
			return $this->denied( 'bad_signature' );
		}

		// El nonce se consume al final, cuando la firma ya es válida. Hacerlo
		// antes permitiría agotar el almacén de transients con peticiones
		// basura que ni siquiera van firmadas.
		if ( ! $this->consume_nonce( $nonce ) ) {
			return $this->denied( 'replayed_nonce' );
		}

		return true;
	}

	/**
	 * Comprueba que la marca de tiempo esté dentro de la ventana admitida.
	 *
	 * @param string $timestamp Marca de tiempo recibida.
	 */
	private function is_timestamp_fresh( string $timestamp ): bool {
		if ( ! ctype_digit( $timestamp ) ) {
			return false;
		}

		return abs( time() - (int) $timestamp ) <= self::MAX_CLOCK_SKEW;
	}

	/**
	 * Recalcula la firma y la compara en tiempo constante.
	 *
	 * La comparación en tiempo constante de hash_equals() evita que el tiempo
	 * de respuesta revele cuántos bytes iniciales de la firma eran correctos.
	 *
	 * @param string $timestamp Marca de tiempo.
	 * @param string $nonce     Nonce.
	 * @param string $body      Cuerpo crudo.
	 * @param string $signature Firma recibida.
	 * @param string $secret    Secreto compartido.
	 */
	private function is_signature_valid( string $timestamp, string $nonce, string $body, string $signature, string $secret ): bool {
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $nonce . '.' . $body, $secret );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Marca un nonce como usado.
	 *
	 * @param string $nonce Nonce recibido.
	 *
	 * @return bool FALSE si ya se había usado.
	 */
	private function consume_nonce( string $nonce ): bool {
		$key = self::NONCE_PREFIX . hash( 'sha256', $nonce );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, 1, self::NONCE_TTL );

		return true;
	}

	/**
	 * Construye la respuesta de rechazo.
	 *
	 * Siempre devuelve el mismo mensaje y el mismo código HTTP, sea cual sea
	 * la causa: distinguir "firma inválida" de "nonce repetido" ayudaría a
	 * quien esté sondeando el endpoint a afinar su intento. El motivo real se
	 * guarda en el log del servidor.
	 *
	 * @param string $reason Motivo interno.
	 */
	private function denied( string $reason ): \WP_Error {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[salesbumm-sld] Consulta de autorización rechazada: %s', $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return new \WP_Error(
			'sld_unauthorized',
			__( 'Petición no autorizada.', 'salesbumm-sld' ),
			array( 'status' => 401 )
		);
	}
}
