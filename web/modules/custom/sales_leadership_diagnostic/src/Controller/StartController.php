<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Exception\CannotStartDiagnosticException;
use Drupal\sales_leadership_diagnostic\Exception\RateLimitException;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticStarter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\sales_leadership_diagnostic\Service\Security\ExceptionRedactor;

/**
 * Inicia un diagnóstico y lleva al alumno a la conversación.
 *
 * Responde solo a POST. Un GET habría bastado para el enlace del panel, pero
 * crear una sesión es una operación que cambia el estado del sistema, y con GET
 * la crearía cualquier cosa que precargue enlaces: el navegador, un antivirus
 * que inspecciona el correo, un rastreador. El POST con token CSRF también
 * impide que otro sitio pueda dispararla desde el navegador del alumno.
 *
 * El controlador no decide nada: pregunta al servicio y traduce el resultado a
 * una redirección. Toda la política vive en DiagnosticStarter, de modo que
 * llamar a esta ruta directamente no salta ninguna comprobación.
 */
final class StartController extends ControllerBase {

  public function __construct(
    private readonly DiagnosticStarter $starter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(DiagnosticStarter::class),
    );
  }

  /**
   * Crea la sesión y redirige a la conversación.
   */
  public function start(DiagnosticAgentInterface $sld_agent): RedirectResponse {
    try {
      $session = $this->starter->start($this->currentUser(), $sld_agent);
    }
    catch (CannotStartDiagnosticException $e) {
      $this->messenger()->addWarning($this->explain($e->getReason()));

      return $this->backToDashboard();
    }
    catch (RateLimitException $e) {
      // El mensaje de la excepción es técnico y nombra al usuario y al límite
      // concreto: sirve para el registro, no para la pantalla del alumno
      // (§58). Se registra tal cual y se le muestra una explicación neutra.
      $this->getLogger('sales_leadership_diagnostic')->warning(
        'Inicio de diagnóstico rechazado por límite de uso: @detalle',
        ['@detalle' => ExceptionRedactor::redact($e)],
      );

      $this->messenger()->addWarning($this->t('Has iniciado varios diagnósticos hoy. Vuelve a intentarlo mañana.'));

      return $this->backToDashboard();
    }

    return new RedirectResponse(
      Url::fromRoute(
        'sales_leadership_diagnostic.session',
        ['sld_diagnostic_session' => $session->id()],
      )->toString(),
    );
  }

  /**
   * Traduce el motivo técnico a una explicación para el alumno.
   *
   * Los literales están escritos uno a uno, y no compuestos, para que el
   * extractor de traducciones de Drupal pueda encontrarlos.
   */
  private function explain(string $reason): string {
    $message = match ($reason) {
      CannotStartDiagnosticException::REASON_ALREADY_DONE => $this->t('Ya has realizado el diagnóstico que incluye tu acceso actual. Podrás hacer uno nuevo cuando renueves.'),
      CannotStartDiagnosticException::REASON_NOT_AUTHORIZED => $this->t('Tu acceso al diagnóstico no está vigente. Comprueba tu cuenta del programa.'),
      CannotStartDiagnosticException::REASON_IN_FLIGHT => $this->t('Ya se está iniciando tu diagnóstico. Espera unos segundos y vuelve a intentarlo.'),
      // Incluye REASON_NOT_READY: al alumno no se le cuenta que falta
      // configurar el módulo, porque no es asunto suyo ni puede hacer nada.
      default => $this->t('El diagnóstico no está disponible en este momento. Inténtalo más tarde.'),
    };

    return (string) $message;
  }

  /**
   * Devuelve al alumno a su panel.
   */
  private function backToDashboard(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
    );
  }

}
