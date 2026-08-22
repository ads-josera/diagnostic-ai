<?php
/**
 * Reloj de vigencia del acceso al diagnóstico.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lleva la cuenta de cuándo empezó y cuándo caduca el acceso al diagnóstico.
 *
 * El acceso al diagnóstico y el acceso al curso son productos distintos: el
 * curso no caduca, el diagnóstico sí. Por eso la vigencia no se deriva de
 * LearnDash sino que se registra aquí, en un dato propio por alumno.
 *
 * Modelarlo así tiene tres consecuencias prácticas:
 *
 *  - No depende de que el LMS exponga fechas. Si mañana se cambia de LMS, la
 *    regla sigue en pie.
 *  - Es visible y corregible: se puede consultar cuándo caduca un alumno y
 *    reactivarlo a mano sin esperar a una compra.
 *  - La reactivación es un hecho registrado, no una inferencia.
 *
 * El dato vive en el meta del usuario, que es donde WordPress guarda lo que
 * pertenece a una cuenta y viaja con ella en exportaciones y migraciones.
 */
class AccessClock {

	/**
	 * Meta donde se guarda el inicio del acceso, como marca de tiempo.
	 */
	public const META_STARTED = '_sld_diagnostic_access_started';

	/**
	 * Meta que registra por qué se inició o reinició el acceso.
	 *
	 * Sirve para soporte: permite distinguir un alta automática de una
	 * reactivación por compra o de un ajuste manual.
	 */
	public const META_REASON = '_sld_diagnostic_access_reason';

	/**
	 * Ajustes del plugin.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Momento en que empezó el acceso, o NULL si nunca empezó.
	 *
	 * @param int $user_id Usuario.
	 */
	public function get_started_at( int $user_id ): ?int {
		$value = get_user_meta( $user_id, self::META_STARTED, true );

		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	/**
	 * Momento en que caduca el acceso, o NULL si nunca empezó.
	 *
	 * @param int $user_id Usuario.
	 */
	public function get_expires_at( int $user_id ): ?int {
		$started = $this->get_started_at( $user_id );

		if ( null === $started ) {
			return null;
		}

		$months = $this->settings->get_access_months();

		// Cero meses significa acceso sin caducidad. Es un caso legítimo: puede
		// haber programas cuyo diagnóstico no expire.
		if ( $months <= 0 ) {
			return null;
		}

		return (int) strtotime( sprintf( '+%d months', $months ), $started );
	}

	/**
	 * Indica si el acceso sigue vigente.
	 *
	 * Un alumno sin reloj iniciado NO tiene acceso: el reloj se inicia cuando
	 * se detecta que cumple los requisitos, no antes.
	 *
	 * @param int $user_id Usuario.
	 */
	public function is_active( int $user_id ): bool {
		if ( null === $this->get_started_at( $user_id ) ) {
			return false;
		}

		$expires = $this->get_expires_at( $user_id );

		// Sin fecha de caducidad, el acceso no expira.
		if ( null === $expires ) {
			return true;
		}

		return time() < $expires;
	}

	/**
	 * Inicia o reinicia el reloj.
	 *
	 * Reiniciar es lo que ocurre en una reactivación: el alumno vuelve a
	 * disponer del periodo completo desde este momento.
	 *
	 * @param int         $user_id Usuario.
	 * @param string      $reason  Motivo, para soporte.
	 * @param int|null    $when    Momento de inicio. Por defecto, ahora.
	 */
	public function start( int $user_id, string $reason, ?int $when = null ): void {
		$when = $when ?? time();

		update_user_meta( $user_id, self::META_STARTED, $when );
		update_user_meta(
			$user_id,
			self::META_REASON,
			sprintf( '%s (%s)', $reason, gmdate( 'c', $when ) )
		);
	}

	/**
	 * Inicia el reloj solo si aún no existía.
	 *
	 * Es lo que se aplica al detectar por primera vez que un alumno cumple los
	 * requisitos. NO reinicia a quien ya lo tenía: si lo hiciera, cada consulta
	 * renovaría el acceso y nada caducaría nunca.
	 *
	 * @param int $user_id Usuario.
	 * @param int $fallback_start Momento a usar si no hay otro dato mejor.
	 *
	 * @return bool TRUE si se ha iniciado ahora.
	 */
	public function start_if_absent( int $user_id, int $fallback_start ): bool {
		if ( null !== $this->get_started_at( $user_id ) ) {
			return false;
		}

		$this->start( $user_id, 'alta automática al detectar el curso', $fallback_start );

		return true;
	}

	/**
	 * Elimina el registro de un usuario.
	 *
	 * @param int $user_id Usuario.
	 */
	public function reset( int $user_id ): void {
		delete_user_meta( $user_id, self::META_STARTED );
		delete_user_meta( $user_id, self::META_REASON );
	}

	/**
	 * Motivo registrado del último inicio.
	 *
	 * @param int $user_id Usuario.
	 */
	public function get_reason( int $user_id ): string {
		$value = get_user_meta( $user_id, self::META_REASON, true );

		return is_string( $value ) ? $value : '';
	}
}
