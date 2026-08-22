<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\ReadinessBlocker;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticResultRepository;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticSessionRepository;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticReadiness;
use Drupal\sales_leadership_diagnostic\Service\Security\UserProvisioner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Panel del alumno: punto de entrada al diagnóstico y su historial.
 *
 * El controller no contiene lógica de negocio: consulta servicios y compone
 * un array de renderizado. Las decisiones —si puede empezar, qué sesiones son
 * suyas— las toman los servicios correspondientes.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly DiagnosticSessionRepository $sessions,
    private readonly DiagnosticResultRepository $results,
    private readonly DiagnosticReadiness $readiness,
    private readonly UserProvisioner $provisioner,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(DiagnosticSessionRepository::class),
      $container->get(DiagnosticResultRepository::class),
      $container->get(DiagnosticReadiness::class),
      $container->get(UserProvisioner::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Muestra el panel del alumno.
   */
  public function view(): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();

    $sessions = $this->sessions->loadForUser($uid);
    $results = $this->results->loadForUserIndexedBySession($uid);

    return [
      '#theme' => 'sld_dashboard',
      // El nombre de usuario es técnico —«sld_wp_4821»— porque derivarlo del
      // nombre real permitiría suplantaciones. Para saludar se usa el nombre
      // que envió WordPress, guardado junto a la correspondencia.
      '#user_name' => $this->provisioner->getDisplayName($account),
      '#can_start' => $this->readiness->isReady(),
      '#unavailable_notice' => $this->buildUnavailableNotice(),
      '#history' => $this->buildHistory($sessions, $results),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/dashboard'],
      ],
      '#cache' => [
        // El panel es distinto para cada alumno y cambia cuando cambian sus
        // sesiones o la configuración de la que depende la disponibilidad.
        'contexts' => ['user'],
        'tags' => array_merge(
          ['sld_diagnostic_session_list', 'sld_diagnostic_result_list'],
          $this->readiness->getCacheTags(),
        ),
      ],
    ];
  }

  /**
   * Construye las filas del historial (§36).
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   * @param array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface> $results
   */
  private function buildHistory(array $sessions, array $results): array {
    $rows = [];
    $statusLabels = DiagnosticStatus::allowedValues();

    foreach ($sessions as $session) {
      $id = (int) $session->id();
      $status = $session->getStatus();

      $rows[] = [
        'id' => $id,
        'date' => $this->dateFormatter->format((int) $session->get('created')->value, 'short'),
        'status' => $statusLabels[$status->value] ?? $status->value,
        'status_machine' => $status->value,
        'version' => $session->getDiagnosticVersion(),
        // El enlace al resultado solo aparece si el resultado existe de verdad.
        // Ofrecer un enlace que lleva a un 403 o a un 404 sería peor que no
        // ofrecer ninguno.
        'result_id' => isset($results[$id]) ? (int) $results[$id]->id() : NULL,
        'is_resumable' => $status->acceptsMessages(),
      ];
    }

    return $rows;
  }

  /**
   * Aviso a mostrar cuando no puede iniciarse un diagnóstico.
   *
   * Un alumno recibe un mensaje neutro; un administrador, el motivo concreto.
   * Los detalles técnicos de configuración no son asunto del alumno y no le
   * ayudarían en nada (§58).
   */
  private function buildUnavailableNotice(): ?array {
    $blockers = $this->readiness->blockers();

    if ($blockers === []) {
      return NULL;
    }

    if (!$this->currentUser()->hasPermission(SalesLeadershipDiagnostic::PERMISSION_ADMINISTER)) {
      return [
        'message' => $this->t('El diagnóstico no está disponible en este momento. Vuelve a intentarlo más tarde.'),
        'details' => [],
      ];
    }

    $descriptions = [
      ReadinessBlocker::MissingSecrets->value => $this->t('Faltan variables de entorno con secretos.'),
      ReadinessBlocker::WordPressNotConfigured->value => $this->t('La integración con WordPress está incompleta.'),
      ReadinessBlocker::AgentNotLoaded->value => $this->t('El prompt del agente aún no se ha cargado.'),
    ];

    return [
      'message' => $this->t('El diagnóstico no puede iniciarse todavía. Solo los administradores ven este detalle:'),
      'details' => array_map(
        static fn (ReadinessBlocker $blocker) => $descriptions[$blocker->value],
        $blockers,
      ),
    ];
  }

}
