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

	public const OPTION_SSO_URL   = 'sld_drupal_sso_url';
	public const OPTION_COURSE_ID = 'sld_course_id';
	public const OPTION_TOKEN_TTL = 'sld_token_ttl';

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
			self::OPTION_COURSE_ID,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'default'           => 0,
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
	 * Curso que autoriza el diagnóstico.
	 */
	public function get_course_id(): int {
		return (int) get_option( self::OPTION_COURSE_ID, 0 );
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
									✘ <?php
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
							<label for="sld_course_id"><?php echo esc_html__( 'Curso que da acceso', 'salesbumm-sld' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_COURSE_ID ); ?>"
								id="sld_course_id"
								type="number"
								min="0"
								value="<?php echo esc_attr( (string) $this->get_course_id() ); ?>">
							<p class="description">
								<?php echo esc_html__( 'ID del curso de LearnDash cuya compra habilita el diagnóstico. Debe coincidir con el configurado en Drupal.', 'salesbumm-sld' ); ?>
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
