<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
use Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\SsoDenialReason;
use Drupal\sales_leadership_diagnostic\Service\Authorization\DiagnosticAccessChecker;
use Drupal\sales_leadership_diagnostic\Service\Security\SsoTokenValidator;
use Drupal\sales_leadership_diagnostic\Service\Security\UserProvisioner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;


/**
 * Punto de entrada del alumno desde WordPress (§10).
 *
 * El orden de las comprobaciones es deliberado y no debe alterarse:
 *
 *  1. Límite por IP, antes de gastar nada en criptografía.
 *  2. Validez del token: firma, vigencia, emisor, audiencia y unicidad.
 *  3. AUTORIZACIÓN: ¿tiene el curso? Se consulta a WordPress.
 *  4. Solo entonces se crea o recupera la cuenta.
 *  5. Y solo entonces se inicia sesión.
 *
 * Que la autorización preceda a la provisión importa: nunca se crea una cuenta
 * de Drupal para alguien que no tiene derecho de acceso. De lo contrario, un
 * token válido de cualquier usuario de WordPress —lo tenga comprado o no—
 * bastaría para ir sembrando cuentas en el sitio.
 *
 * Ningún fallo de esta ruta lanza una excepción HTTP: todos redirigen a una
 * página de rechazo. La razón es que Drupal registra la URI completa de cada
 * 403, y aquí el token viaja en la cadena de consulta: lanzar dejaría tokens
 * escritos en los logs, en contra de §43. Redirigiendo, lo que se registra es
 * una ruta limpia. De paso, el alumno recibe una explicación en lugar de la
 * página de acceso denegado de Drupal.
 */
final class SsoController extends ControllerBase {

  /**
   * Evento de flood para limitar intentos por IP.
   */
  private const FLOOD_EVENT = 'sales_leadership_diagnostic.sso';

  /**
   * Intentos permitidos por IP y ventana.
   *
   * Acota el sondeo de tokens desde una misma dirección. Es generoso a
   * propósito: varios alumnos pueden compartir la IP de una misma oficina.
   */
  private const FLOOD_THRESHOLD = 20;
  private const FLOOD_WINDOW = 300;

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly SsoTokenValidator $validator,
    private readonly DiagnosticAccessChecker $accessChecker,
    private readonly UserProvisioner $provisioner,
    private readonly ExternalAuthInterface $externalAuth,
    private readonly FloodInterface $flood,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(SsoTokenValidator::class),
      $container->get(DiagnosticAccessChecker::class),
      $container->get(UserProvisioner::class),
      $container->get('externalauth.externalauth'),
      $container->get('flood'),
      $container->get('logger.factory'),
    );
  }

  /**
   * Valida el token entrante y abre sesión.
   */
  public function login(Request $request): RedirectResponse {
    if (!$this->isAllowed($request)) {
      $this->logger->warning('Demasiados intentos de acceso desde una misma dirección.');

      return $this->deny(SsoDenialReason::TooManyAttempts);
    }

    $token = (string) $request->query->get('token', '');

    try {
      $identity = $this->validator->validate(
        $token,
        $this->getExpectedIssuer(),
        $this->getExpectedAudience($request),
      );
    }
    catch (InvalidTokenException $e) {
      $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $request->getClientIp());

      // El motivo concreto ya quedó en el log. Al visitante se le da siempre
      // el mismo, porque distinguirlos solo ayudaría a quien pruebe tokens.
      return $this->deny(SsoDenialReason::InvalidToken);
    }

    // La autorización se comprueba ANTES de tocar la base de datos de usuarios.
    if (!$this->accessChecker->isAuthorized($identity->externalUserId)) {
      $this->logger->info('Acceso denegado por falta de curso al alumno externo @uid.', [
        '@uid' => $identity->externalUserId,
      ]);

      return $this->deny(SsoDenialReason::NoCourse);
    }

    try {
      $account = $this->provisioner->provision($identity);
    }
    catch (DiagnosticException $e) {
      // El motivo ya se registró con detalle en el provisioner.
      return $this->deny(SsoDenialReason::AccountUnavailable);
    }

    $this->externalAuth->userLoginFinalize(
      $account,
      $identity->externalUserId,
      SalesLeadershipDiagnostic::AUTHMAP_PROVIDER,
    );

    $this->logger->info('Alumno externo @uid autenticado correctamente (uid @drupal).', [
      '@uid' => $identity->externalUserId,
      '@drupal' => $account->id(),
    ]);

    return new RedirectResponse(
      Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
      302,
    );
  }

  /**
   * Muestra la página de rechazo.
   *
   * El destino no contiene el token, que es justo el objetivo: la URL que
   * Drupal registre a partir de aquí queda limpia.
   */
  private function deny(SsoDenialReason $reason): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('sales_leadership_diagnostic.sso_denied', [], [
        'query' => ['motivo' => $reason->value],
      ])->toString(),
      302,
    );
  }

  /**
   * Comprueba el límite de intentos por IP.
   */
  private function isAllowed(Request $request): bool {
    return $this->flood->isAllowed(
      self::FLOOD_EVENT,
      self::FLOOD_THRESHOLD,
      self::FLOOD_WINDOW,
      $request->getClientIp(),
    );
  }

  /**
   * Renderiza la explicación de por qué no se pudo entrar.
   */
  public function denied(Request $request): array {
    $reason = SsoDenialReason::fromRequestValue($request->query->get('motivo'));

    $messages = [
      SsoDenialReason::InvalidToken->value => $this->t('El enlace de acceso no es válido o ha caducado. Vuelve a WordPress y pulsa de nuevo el botón del diagnóstico.'),
      SsoDenialReason::NoCourse->value => $this->t('Tu cuenta no tiene acceso a este diagnóstico. Si acabas de adquirir el curso, espera unos minutos e inténtalo de nuevo.'),
      SsoDenialReason::AccountUnavailable->value => $this->t('No hemos podido preparar tu acceso. Ponte en contacto con soporte para que lo revisen.'),
      SsoDenialReason::TooManyAttempts->value => $this->t('Demasiados intentos seguidos. Espera unos minutos antes de volver a intentarlo.'),
    ];

    return [
      '#theme' => 'sld_sso_denied',
      '#message' => $messages[$reason->value],
      '#attached' => ['library' => ['sales_leadership_diagnostic/dashboard']],
      // Depende del motivo, que viene en la URL.
      '#cache' => ['contexts' => ['url.query_args:motivo']],
    ];
  }

  /**
   * Emisor esperado: el WordPress configurado.
   */
  private function getExpectedIssuer(): string {
    $base = trim((string) $this->config('sales_leadership_diagnostic.settings')->get('wordpress.api_base_url'));

    return rtrim($base, '/');
  }

  /**
   * Audiencia esperada: el origen de este mismo sitio.
   *
   * Se deriva de la petición en lugar de configurarse aparte, para que no
   * pueda quedar desincronizada con el sitio donde realmente corre el módulo.
   */
  private function getExpectedAudience(Request $request): string {
    return rtrim($request->getSchemeAndHttpHost(), '/');
  }

}
