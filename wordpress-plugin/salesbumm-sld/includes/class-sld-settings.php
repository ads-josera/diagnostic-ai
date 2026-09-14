<?php
/**
 * Ajustes del puente.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuración no sensible del plugin, mediante la Settings API.
 *
 * Aquí solo vive lo que puede estar en la base de datos sin riesgo: la URL de
 * destino, el curso y la vigencia del token. Los secretos se definen en
 * wp-config.php y esta pantalla se limita a informar de si están presentes.
 */
class Settings {

	public const OPTION_GROUP = 'sld_settings';
	public const PAGE_SLUG    = 'salesbumm-sld';

	public const OPTION_SSO_URL    = 'sld_drupal_sso_url';
	public const OPTION_COURSE_IDS = 'sld_course_ids';
	public const OPTION_TOKEN_TTL  = 'sld_token_ttl';
	public const OPTION_MONTHS     = 'sld_access_months';
	public const OPTION_START      = 'sld_access_start_origin';

	/**
	 * Cursos de LearnDash que representan una suscripción activa.
	 *
	 * Desde la 1.3.0. WooCommerce Subscriptions concede el curso mientras la
	 * suscripción está al corriente y lo retira al cancelarse.
	 */
	public const OPTION_SUBSCRIPTION_IDS = 'sld_subscription_course_ids';

	/**
	 * Duración por defecto del acceso al diagnóstico, en meses.
	 */
	private const DEFAULT_MONTHS = 12;

	/**
	 * Opción antigua, de cuando solo había un curso autorizador.
	 *
	 * Se sigue leyendo para no perder la configuración de instalaciones
	 * anteriores; ver get_course_ids().
	 */
	private const LEGACY_OPTION_COURSE_ID = 'sld_course_id';

	/**
	 * Vigencia por defecto del token, en segundos.
	 *
	 * Solo tiene que sobrevivir a una redirección de navegador.
	 */
	private const DEFAULT_TTL = 90;

	/**
	 * Registra el menú de administración.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Salesbumm Diagnostic AI', 'salesbumm-sld' ),
			__( 'Diagnostic AI', 'salesbumm-sld' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registra los ajustes.
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_SSO_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_sso_url' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_COURSE_IDS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_course_ids' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_SUBSCRIPTION_IDS,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_subscription_course_ids' ),
				'default'           => '',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_MONTHS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_months' ),
				'default'           => self::DEFAULT_MONTHS,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_START,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_start_origin' ),
				'default'           => 'now',
			)
		);

		register_setting(
			self::OPTION_GROUP,
			self::OPTION_TOKEN_TTL,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_ttl' ),
				'default'           => self::DEFAULT_TTL,
			)
		);
	}

	/**
	 * Exige HTTPS en la URL de destino.
	 *
	 * El token viaja en esa URL. Sobre HTTP iría en claro y cualquiera en la
	 * red podría capturarlo y usarlo para entrar como el alumno.
	 *
	 * @param mixed $value Valor recibido.
	 */
	public function sanitize_sso_url( $value ): string {
		$url = esc_url_raw( trim( (string) $value ) );

		if ( '' === $url ) {
			return '';
		}

		if ( 0 !== strpos( $url, 'https://' ) && ! $this->is_local_host( $url ) ) {
			add_settings_error(
				self::OPTION_SSO_URL,
				'sld_https_required',
				__( 'La URL de destino debe usar HTTPS: el token de acceso viaja en ella.', 'salesbumm-sld' )
			);

			return (string) get_option( self::OPTION_SSO_URL, '' );
		}

		return untrailingslashit( $url );
	}

	/**
	 * Acota la vigencia del token.
	 *
	 * @param mixed $value Valor recibido.
	 */
	public function sanitize_ttl( $value ): int {
		$ttl = absint( $value );

		if ( $ttl < 30 ) {
			return 30;
		}

		return min( $ttl, 300 );
	}

	/**
	 * URL de destino en Drupal.
	 */
	public function get_drupal_sso_url(): string {
		return (string) get_option( self::OPTION_SSO_URL, '' );
	}

	/**
	 * Audiencia del token: el origen del Drupal de destino.
	 *
	 * Se deriva de la URL configurada para que no haya dos campos que puedan
	 * quedar desincronizados. Debe coincidir con lo que Drupal espera.
	 */
	public function get_audience(): string {
		$url = $this->get_drupal_sso_url();

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = isset( $parts['scheme'] ) ? (string) $parts['scheme'] : 'https';

		return $scheme . '://' . (string) $parts['host'];
	}

	/**
	 * Cursos que autorizan el diagnóstico.
	 *
	 * Poseer CUALQUIERA de ellos concede acceso. Esto es lo que permite que
	 * una compra posterior —del mismo programa o de otro que se designe—
	 * reactive al alumno sin tocar configuración.
	 *
	 * Un curso que figure también como de suscripción NO cuenta aquí: como
	 * curso normal le arrancaría el reloj al alumno y se lo cerraría a los
	 * doce meses aunque siguiera pagando.
	 *
	 * @return int[]
	 */
	public function get_course_ids(): array {
		return array_values( array_diff( $this->get_configured_course_ids(), $this->get_subscription_course_ids() ) );
	}

	/**
	 * Cursos que representan una suscripción activa.
	 *
	 * Tenerlo abre TODOS los agentes y no caduca por su cuenta: dura lo que
	 * dure la suscripción, que es quien concede y retira el curso.
	 *
	 * @return int[]
	 */
	public function get_subscription_course_ids(): array {
		return $this->parse_ids( (string) get_option( self::OPTION_SUBSCRIPTION_IDS, '' ) );
	}

	/**
	 * Cursos que aparecen en las dos listas: un error de configuración.
	 *
	 * @return int[]
	 */
	public function get_overlapping_course_ids(): array {
		return array_values( array_intersect( $this->get_configured_course_ids(), $this->get_subscription_course_ids() ) );
	}

	/**
	 * Duración del acceso al diagnóstico, en meses.
	 *
	 * Cero significa sin caducidad.
	 */
	public function get_access_months(): int {
		$value = get_option( self::OPTION_MONTHS, self::DEFAULT_MONTHS );

		return is_numeric( $value ) ? max( 0, (int) $value ) : self::DEFAULT_MONTHS;
	}

	/**
	 * Desde cuándo cuenta el periodo de acceso de un alumno nuevo.
	 *
	 * 'enrollment' usa la fecha de alta en el curso; 'now' la fecha en que se
	 * detecta por primera vez.
	 */
	public function get_access_start_origin(): string {
		$value = (string) get_option( self::OPTION_START, 'now' );

		return 'enrollment' === $value ? 'enrollment' : 'now';
	}

	/**
	 * Normaliza la lista de cursos.
	 *
	 * @param mixed $value Valor recibido.
	 */
	public function sanitize_course_ids( $value ): string {
		return implode( ', ', $this->parse_ids( (string) $value ) );
	}

	/**
	 * Normaliza la lista de cursos de suscripción.
	 *
	 * @param mixed $value Valor recibido.
	 */
	public function sanitize_subscription_course_ids( $value ): string {
		return implode( ', ', $this->parse_ids( (string) $value ) );
	}

	/**
	 * Acota la duración del acceso.
	 *
	 * @param mixed $value Valor recibido.
	 */
	public function sanitize_months( $value ): int {
		$months = absint( $value );

		// Un tope alto pero finito: 120 meses son diez años, más allá de lo
		// cual conviene marcar 0 y decir explícitamente que no caduca.
		return min( $months, 120 );
	}

	/**
	 * Valida el origen de la fecha de inicio.
	 *
	 * @param mixed $value Valor recibido.
	 */
	public function sanitize_start_origin( $value ): string {
		return 'enrollment' === (string) $value ? 'enrollment' : 'now';
	}

	/**
	 * Vigencia del token en segundos.
	 */
	public function get_token_ttl(): int {
		$ttl = (int) get_option( self::OPTION_TOKEN_TTL, self::DEFAULT_TTL );

		return $ttl > 0 ? $ttl : self::DEFAULT_TTL;
	}

	/**
	 * Pinta la pantalla de ajustes.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$missing   = Secrets::missing();
		$too_short = Secrets::too_short();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Salesbumm — Diagnostic AI (puente)', 'salesbumm-sld' ); ?></h1>

			<p>
				<?php echo esc_html__( 'Este plugin conecta WordPress con el módulo de diagnóstico alojado en Drupal. No añade funcionalidad visible salvo el botón de acceso.', 'salesbumm-sld' ); ?>
			</p>

			<h2><?php echo esc_html__( 'Secretos', 'salesbumm-sld' ); ?></h2>

			<p>
				<?php echo esc_html__( 'Se definen en wp-config.php y no son editables desde aquí: la base de datos de WordPress se copia con frecuencia y un secreto guardado en ella viaja en todas esas copias.', 'salesbumm-sld' ); ?>
			</p>

			<table class="widefat striped" style="max-width:40em">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Constante', 'salesbumm-sld' ); ?></th>
						<th><?php echo esc_html__( 'Estado', 'salesbumm-sld' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( array( Secrets::JWT, Secrets::HMAC ) as $constant ) : ?>
					<tr>
						<td><code><?php echo esc_html( $constant ); ?></code></td>
						<td>
							<?php if ( ! Secrets::has( $constant ) ) : ?>
								<span style="color:#b3261e">✘ <?php echo esc_html__( 'No definido', 'salesbumm-sld' ); ?></span>
							<?php elseif ( ! Secrets::is_long_enough( $constant ) ) : ?>
								<span style="color:#b3261e">
									✘ 
									<?php
									printf(
										/* translators: %d: longitud mínima en bytes. */
										esc_html__( 'Demasiado corto: mínimo %d caracteres', 'salesbumm-sld' ),
										(int) Secrets::MIN_LENGTH
									);
									?>
								</span>
							<?php else : ?>
								<span style="color:#1a7f4b">✔ <?php echo esc_html__( 'Definido', 'salesbumm-sld' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $too_short ) ) : ?>
				<div class="notice notice-error" style="max-width:40em">
					<p>
						<strong><?php echo esc_html__( 'Secreto demasiado corto.', 'salesbumm-sld' ); ?></strong>
						<?php
						printf(
							/* translators: 1: lista de constantes, 2: longitud mínima. */
							esc_html__( '%1$s no llega a %2$d caracteres. La librería que valida el token en Drupal rechaza las claves más cortas, así que el acceso fallará con un error difícil de diagnosticar. Regenéralo con: openssl rand -hex 32', 'salesbumm-sld' ),
							'<code>' . esc_html( implode( '</code>, <code>', $too_short ) ) . '</code>',
							(int) Secrets::MIN_LENGTH
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $missing ) ) : ?>
				<div class="notice notice-error" style="max-width:40em">
					<p><?php echo esc_html__( 'Añade estas líneas a wp-config.php, antes de la línea que dice «That\'s all, stop editing!»:', 'salesbumm-sld' ); ?></p>
					<pre><?php foreach ( $missing as $constant ) : ?>
define( '<?php echo esc_html( $constant ); ?>', '<?php echo esc_html__( 'pega-aqui-el-secreto', 'salesbumm-sld' ); ?>' );
<?php endforeach; ?></pre>
					<p>
						<?php echo esc_html__( 'Cada secreto debe ser idéntico al configurado en Drupal, y distinto del otro. Genéralos con: openssl rand -hex 32', 'salesbumm-sld' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__( 'Conexión', 'salesbumm-sld' ); ?></h2>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="sld_sso_url"><?php echo esc_html__( 'URL de acceso en Drupal', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_SSO_URL ); ?>"
								id="sld_sso_url"
								type="url"
								class="regular-text"
								value="<?php echo esc_attr( $this->get_drupal_sso_url() ); ?>"
								placeholder="https://diagnostico.salesbumm.com/sales-diagnostic/sso">
							<p class="description">
								<?php echo esc_html__( 'Ruta /sales-diagnostic/sso del sitio Drupal. Debe usar HTTPS: el token de acceso viaja en esta URL.', 'salesbumm-sld' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sld_course_ids"><?php echo esc_html__( 'Cursos que dan acceso', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_COURSE_IDS ); ?>"
								id="sld_course_ids"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr( implode( ', ', $this->get_course_ids() ) ); ?>"
								placeholder="35884, 41002">
							<p class="description">
								<?php echo esc_html__( 'IDs de los cursos de LearnDash separados por comas. Poseer CUALQUIERA de ellos concede acceso al diagnóstico, de modo que comprar otro programa designado reactiva al alumno sin tocar esta configuración.', 'salesbumm-sld' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sld_subscription_course_ids"><?php echo esc_html__( 'Cursos de suscripción', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_SUBSCRIPTION_IDS ); ?>"
								id="sld_subscription_course_ids"
								type="text"
								class="regular-text"
								value="<?php echo esc_attr( implode( ', ', $this->get_subscription_course_ids() ) ); ?>"
								placeholder="52310">
							<p class="description">
								<?php echo esc_html__( 'IDs de los cursos de LearnDash que vende la suscripción (mensual o anual). Tener uno abre TODOS los agentes y no caduca por su cuenta: dura lo que dure la suscripción, porque WooCommerce retira el curso al cancelarse o dejar de pagarse. Déjalo vacío si no hay suscripción.', 'salesbumm-sld' ); ?>
							</p>
							<?php
							$free_for_all = array_values( array_filter( $this->get_subscription_course_ids(), array( CourseAccess::class, 'is_free_for_all' ) ) );
							?>
							<?php if ( $free_for_all ) : ?>
								<p class="description" style="color:#b3261e">
									<?php
									printf(
										/* translators: %s: lista de IDs de curso. */
										esc_html__( 'No se tiene en cuenta: %s. Está en modo Abierto o Gratis en LearnDash, así que cualquiera lo tendría sin pagar. Ponlo en modo Cerrado.', 'salesbumm-sld' ),
										esc_html( implode( ', ', $free_for_all ) )
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( $this->get_overlapping_course_ids() ) : ?>
								<p class="description" style="color:#b3261e">
									<?php
									printf(
										/* translators: %s: lista de IDs de curso. */
										esc_html__( 'En las dos listas a la vez: %s. Se trata como suscripción; quítalo de «Cursos que dan acceso».', 'salesbumm-sld' ),
										esc_html( implode( ', ', $this->get_overlapping_course_ids() ) )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sld_months"><?php echo esc_html__( 'Duración del acceso (meses)', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_MONTHS ); ?>"
								id="sld_months"
								type="number"
								min="0"
								max="120"
								value="<?php echo esc_attr( (string) $this->get_access_months() ); ?>">
							<p class="description">
								<?php echo esc_html__( 'Cuánto dura el acceso al diagnóstico desde que se concede. El acceso al CURSO no se ve afectado: el alumno conserva sus vídeos y materiales aunque el diagnóstico caduque. Escribir 0 significa que no caduca.', 'salesbumm-sld' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sld_start"><?php echo esc_html__( 'El periodo empieza a contar', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_START ); ?>" id="sld_start">
								<option value="now" <?php selected( 'now', $this->get_access_start_origin() ); ?>>
									<?php echo esc_html__( 'Desde que se detecta al alumno por primera vez', 'salesbumm-sld' ); ?>
								</option>
								<option value="enrollment" <?php selected( 'enrollment', $this->get_access_start_origin() ); ?>>
									<?php echo esc_html__( 'Desde su alta en el curso', 'salesbumm-sld' ); ?>
								</option>
							</select>
							<p class="description">
								<strong><?php echo esc_html__( 'Decisión importante para los alumnos que ya compraron.', 'salesbumm-sld' ); ?></strong>
								<?php echo esc_html__( 'Con la primera opción, todos reciben el periodo completo a partir de ahora. Con la segunda, quien compró hace más tiempo que la duración configurada queda caducado de inmediato.', 'salesbumm-sld' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="sld_ttl"><?php echo esc_html__( 'Vigencia del token (segundos)', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_TOKEN_TTL ); ?>"
								id="sld_ttl"
								type="number"
								min="30"
								max="300"
								value="<?php echo esc_attr( (string) $this->get_token_ttl() ); ?>">
							<p class="description">
								<?php echo esc_html__( 'El token solo tiene que sobrevivir a una redirección. Cuanto más corto, menor es la ventana de un token robado. Recomendado: 90.', 'salesbumm-sld' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Cómo mostrar el botón', 'salesbumm-sld' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Inserta este shortcode donde quieras que aparezca. Solo lo ven los alumnos con acceso al curso:', 'salesbumm-sld' ); ?>
				<code>[salesbumm_diagnostic_button]</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Cursos autorizadores tal como están escritos, sin quitar nada.
	 *
	 * @return int[]
	 */
	private function get_configured_course_ids(): array {
		$raw = (string) get_option( self::OPTION_COURSE_IDS, '' );

		if ( '' === trim( $raw ) ) {
			// Compatibilidad con la configuración de un solo curso.
			$legacy = (int) get_option( self::LEGACY_OPTION_COURSE_ID, 0 );

			return $legacy > 0 ? array( $legacy ) : array();
		}

		return $this->parse_ids( $raw );
	}

	/**
	 * Convierte el texto de un campo en identificadores únicos, en su orden.
	 *
	 * @param string $raw Texto tal como lo escribió el administrador.
	 *
	 * @return int[]
	 */
	private function parse_ids( string $raw ): array {
		$ids = array_map( 'absint', $this->split_ids( $raw ) );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Parte una lista de identificadores separados por comas o espacios.
	 *
	 * @param string $raw Texto tal como lo escribió el administrador.
	 *
	 * @return string[] Los fragmentos, sin partes vacías.
	 */
	private function split_ids( string $raw ): array {
		$partes = preg_split( '/[\s,]+/', trim( $raw ) );

		return is_array( $partes ) ? $partes : array();
	}

	/**
	 * Indica si una URL apunta a un host de desarrollo local.
	 *
	 * @param string $url URL a comprobar.
	 */
	private function is_local_host( string $url ): bool {
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) && ! empty( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';

		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return true;
		}

		foreach ( array( '.ddev.site', '.test', '.local', '.localhost' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}
}
