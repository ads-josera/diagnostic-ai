<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticResultRepository;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticSessionRepository;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Authorization\DiagnosticAccessChecker;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ChatWelcome;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticStarter;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\StudentHistoryBuilder;
use Drupal\sales_leadership_diagnostic\Service\Security\UserProvisioner;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * La página de UN agente: su presentación y su historial.
 *
 * Es el segundo nivel de la navegación del alumno, y solo existe porque puede
 * tener varios agentes. Con uno solo el panel lo enseña todo de una vez y
 * nadie pasa por aquí (decisión del 31-08-2026): obligar a elegir entre una
 * sola opción y luego volver a pulsar es un clic de más en todas las visitas
 * de la mayoría de los alumnos.
 *
 * Lo que se resolvió partiendo la pantalla:
 *
 *  - El panel apilaba tres cosas de alcance distinto: qué puedo hacer, qué
 *    hice y qué se sabe de mí. Con un agente se leía como una sola página;
 *    con dos, no.
 *  - **El historial no decía de qué agente era cada fila.** Aquí no hace
 *    falta decirlo: la página entera es de un agente. Se arregla acotando en
 *    lugar de añadiendo una columna que repetiría el título en cada fila.
 *
 * Lo que NO se trajo, y conviene que siga fuera: **la memoria del alumno**.
 * Se guarda por alumno, no por agente, y se le inyecta entera al prompt de
 * cada uno de ellos. Enseñarla aquí diría que cada agente recuerda por su
 * cuenta, que es falso, y «olvidar» en uno haría desaparecer el dato del otro.
 * Se queda en el panel, que es su alcance real.
 */
final class AgentPageController extends ControllerBase {

  public function __construct(
    private readonly DiagnosticSessionRepository $sessions,
    private readonly DiagnosticResultRepository $results,
    private readonly StudentHistoryBuilder $history,
    private readonly AgentRegistry $agents,
    private readonly DiagnosticAccessChecker $accessChecker,
    private readonly UserProvisioner $provisioner,
    private readonly ChatWelcome $welcome,
    private readonly DiagnosticStarter $starter,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(DiagnosticSessionRepository::class),
      $container->get(DiagnosticResultRepository::class),
      $container->get(StudentHistoryBuilder::class),
      $container->get(AgentRegistry::class),
      $container->get(DiagnosticAccessChecker::class),
      $container->get(UserProvisioner::class),
      $container->get(ChatWelcome::class),
      $container->get(DiagnosticStarter::class),
      $container->get('csrf_token'),
    );
  }

  /**
   * Muestra la página de un agente.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface $sld_agent
   *   Agente pedido en la ruta. Que esté en la URL no autoriza a verlo.
   */
  public function view(DiagnosticAgentInterface $sld_agent): array {
    $account = $this->currentUser();
    $uid = (int) $account->id();

    // Que el identificador viaje en la URL no concede nada. Sin esta
    // comprobación, quien compró un curso vería la ficha de cualquier otro
    // agente escribiendo su nombre en la barra de direcciones. La misma
    // comprobación la repite DiagnosticStarter antes de crear la sesión: esto
    // es lo que impide MIRAR, aquello lo que impide EMPEZAR.
    if (!array_key_exists($sld_agent->id(), $this->agents->forDecision($this->decisionDelAlumno()))) {
      throw new AccessDeniedHttpException('El alumno no tiene derecho a este agente.');
    }

    $sessions = $this->sessions->loadForUser($uid);
    $agentId = (string) $sld_agent->id();

    return [
      '#theme' => 'sld_agent_page',
      '#agent_label' => $sld_agent->label(),
      '#agent_description' => $sld_agent->getDescription(),
      '#icon_url' => $this->welcome->getIconUrl($sld_agent),
      // La presentación que hasta ahora solo se veía dentro del chat, y solo
      // mientras la conversación estuviera vacía. Aquí se lee ANTES de
      // empezar, que es cuando sirve para decidir.
      '#intro' => $this->welcome->getIntro($sld_agent),
      '#resume_session_id' => $this->findResumableId($sessions, $agentId),
      '#start_url' => $this->buildStartUrl($agentId),
      '#dashboard_url' => Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
      '#repeat_notice' => $this->buildRepeatNotice(),
      '#history' => $this->history->forAgent(
        $sessions,
        $this->results->loadForUserIndexedBySession($uid),
        $agentId,
      ),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/dashboard'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => array_merge(
          [
            'sld_diagnostic_session_list',
            'sld_diagnostic_result_list',
          ],
          // Editar este agente debe verse aquí; editar otro, no.
          $this->welcome->getCacheTags($sld_agent),
          $this->starter->getCacheTags(),
        ),
      ],
    ];
  }

  /**
   * Título de la página: el nombre del agente.
   *
   * Se calcula aquí en lugar de fijarlo en la ruta para que la pestaña del
   * navegador y el encabezado digan de qué agente se trata. Con varios
   * agentes, un título común los volvería indistinguibles en el historial del
   * navegador y en los marcadores.
   */
  public function title(DiagnosticAgentInterface $sld_agent): string {
    return (string) $sld_agent->label();
  }

  /**
   * Autorización del alumno, o NULL si no se pudo comprobar.
   */
  private function decisionDelAlumno(): ?AccessDecision {
    $externalUserId = $this->provisioner->getExternalUserId($this->currentUser());

    return $externalUserId === NULL
      ? NULL
      : $this->accessChecker->decide($externalUserId);
  }

  /**
   * Identificador de la conversación que tiene a medias con este agente.
   *
   * Solo cambia el texto del botón. Quién puede empezar lo decide el servidor.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno.
   * @param string $agentId
   *   Agente de esta página.
   */
  private function findResumableId(array $sessions, string $agentId): ?int {
    foreach ($sessions as $session) {
      if ($session->getStatus()->acceptsMessages() && $session->getAgentId() === $agentId) {
        return (int) $session->id();
      }
    }

    return NULL;
  }

  /**
   * Aviso sobre la política de repetición, o NULL si no hay límite.
   */
  private function buildRepeatNotice(): ?string {
    return $this->starter->isLimitedToOnePerPeriod()
      ? (string) $this->t('Tu acceso incluye un diagnóstico. Podrás realizar uno nuevo cuando renueves.')
      : NULL;
  }

  /**
   * URL del formulario de inicio, con su token CSRF.
   *
   * El token se calcula sobre la ruta interna sin la barra inicial, que es lo
   * que espera el validador de Drupal.
   *
   * @param string $agentId
   *   Agente que se va a iniciar.
   */
  private function buildStartUrl(string $agentId): string {
    $parametros = ['sld_agent' => $agentId];
    $ruta = 'sales_leadership_diagnostic.start';
    $internal = ltrim(Url::fromRoute($ruta, $parametros)->getInternalPath(), '/');

    return Url::fromRoute(
      $ruta,
      $parametros,
      ['query' => ['token' => $this->csrfToken->get($internal)]],
    )->toString();
  }

}
