<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\ReadinessBlocker;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticResultRepository;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticSessionRepository;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Authorization\DiagnosticAccessChecker;
use Drupal\sales_leadership_diagnostic\Service\Branding\Branding;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ChatWelcome;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticReadiness;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticStarter;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\StudentHistoryBuilder;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
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

  /**
   * Días de antelación con los que se avisa de la caducidad.
   */
  private const EXPIRY_WARNING_DAYS = 30;

  public function __construct(
    private readonly DiagnosticSessionRepository $sessions,
    private readonly DiagnosticResultRepository $results,
    private readonly DiagnosticReadiness $readiness,
    private readonly UserProvisioner $provisioner,
    private readonly DiagnosticAccessChecker $accessChecker,
    private readonly AgentRegistry $agents,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly Branding $branding,
    private readonly DiagnosticStarter $starter,
    private readonly StudentMemoryStore $memory,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly StudentHistoryBuilder $history,
    private readonly ChatWelcome $welcome,
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
      $container->get(DiagnosticAccessChecker::class),
      $container->get(AgentRegistry::class),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get(Branding::class),
      $container->get(DiagnosticStarter::class),
      $container->get(StudentMemoryStore::class),
      $container->get('csrf_token'),
      $container->get(StudentHistoryBuilder::class),
      $container->get(ChatWelcome::class),
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

    // Se decide UNA vez y se reparte. Antes se preguntaba en dos sitios; la
    // cache lo absorbía, pero además se perdía la distinción que importa:
    // NULL significa «no se pudo comprobar», no «no tiene acceso».
    $decision = $this->decisionDelAlumno($account);

    // Cuántos agentes tiene decide la forma de toda la pantalla, así que se
    // resuelve una sola vez y de aquí sale todo lo demás.
    //
    // Con UNO, el panel lo enseña todo: su botón y su historial completo,
    // igual que antes de que hubiera varios. Con VARIOS aparecen las tarjetas
    // y el historial se va a la página de cada agente, porque una tabla que
    // mezcla los diagnósticos de dos agentes sin decir de cuál es cada fila no
    // se puede leer.
    $agentes = $this->agents->forDecision($decision);
    $varios = count($agentes) > 1;

    return [
      '#theme' => 'sld_dashboard',
      // El nombre de usuario es técnico —«sld_wp_4821»— porque derivarlo del
      // nombre real permitiría suplantaciones. Para saludar se usa el nombre
      // que envió WordPress, guardado junto a la correspondencia.
      '#user_name' => $this->provisioner->getDisplayName($account),
      // Texto plano: lo escapa Twig. No se admite marcado ni Markdown, para no
      // abrir otra vía de HTML arbitrario en la página del alumno.
      '#welcome_text' => $this->branding->getWelcomeText(),
      '#can_start' => $this->readiness->isReady(),
      '#agents' => $this->buildAgents($agentes, $sessions, $varios),
      '#multiple_agents' => $varios,
      // El panel debe poder decir la verdad: que no sepamos si tiene acceso
      // no es lo mismo que saber que no lo tiene.
      '#cannot_verify' => $decision === NULL && $this->provisioner->getExternalUserId($account) !== NULL,
      '#repeat_notice' => $this->buildRepeatNotice(),
      '#unavailable_notice' => $this->buildUnavailableNotice(),
      '#expiry_notice' => $this->buildExpiryNotice($decision),
      // Con un agente, todo. Con varios, solo lo que NO tiene página propia
      // donde salir: una sesión de un agente que ya no aparece —deshabilitado,
      // o cuyo curso caducó— se quedaría sin enlace y el alumno perdería de
      // vista un resultado que es suyo. Lo normal es que esté vacío.
      '#history' => $varios
        ? $this->history->excludingAgents($sessions, $results, array_map('strval', array_keys($agentes)))
        : $this->history->all($sessions, $results),
      '#history_is_leftover' => $varios,
      '#memory' => $this->buildMemory($uid),
      '#memory_forget_all_url' => $this->buildForgetAllUrl(),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/dashboard'],
      ],
      '#cache' => [
        // El panel es distinto para cada alumno y cambia cuando cambian sus
        // sesiones o la configuración de la que depende la disponibilidad.
        'contexts' => ['user'],
        'tags' => array_merge(
          [
            'sld_diagnostic_session_list',
            'sld_diagnostic_result_list',
            // Olvidar un dato tiene que verse al volver al panel.
            'sld_student_memory_list',
          ],
          $this->readiness->getCacheTags(),
          // Sin esto, cambiar los colores o el texto de bienvenida no se
          // vería hasta que el panel caducase por otro motivo.
          $this->branding->getCacheTags(),
          // Igual con la política de repetición: cambiarla debe reflejarse en
          // el aviso que lee el alumno.
          $this->starter->getCacheTags(),
          // Crear o modificar un agente debe verse en el panel.
          $this->agents->getCacheTags(),
        ),
      ],
    ];
  }

  /**
   * Autorización del alumno, o NULL si no se pudo comprobar.
   *
   * NULL sale por dos motivos distintos que aquí se tratan igual: que la
   * cuenta no proceda de WordPress, o que WordPress no respondiera. Quien
   * necesita distinguirlos lo hace fuera.
   */
  private function decisionDelAlumno(AccountInterface $account): ?AccessDecision {
    $externalUserId = $this->provisioner->getExternalUserId($account);

    return $externalUserId === NULL
      ? NULL
      : $this->accessChecker->decide($externalUserId);
  }

  /**
   * Lo que el sistema recuerda del alumno, listo para pintar.
   *
   * Se le enseña porque la memoria condiciona sus conversaciones futuras y la
   * escribió un modelo que puede equivocarse: sin verla no tendría forma de
   * saber que el agente arranca dando por sabido algo falso. Cada hecho lleva
   * su propio botón de olvido, que es el único control que se le da; editarla
   * no, porque entonces dejaría de saberse qué salió de la conversación y qué
   * escribió él.
   *
   * @param int $uid
   *   Alumno.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por hecho recordado.
   */
  private function buildMemory(int $uid): array {
    $filas = [];

    foreach ($this->memory->forUser($uid) as $hecho) {
      $tema = $hecho->getTopic();

      if ($tema === NULL) {
        continue;
      }

      $filas[] = [
        'topic' => $tema->label(),
        'content' => $hecho->getContent(),
        'updated' => $this->dateFormatter->format($hecho->getChangedTime(), 'custom', 'd/m/Y'),
        'forget_url' => $this->buildForgetUrl((int) $hecho->id()),
      ];
    }

    return $filas;
  }

  /**
   * URL para olvidar un hecho, con su token CSRF.
   */
  private function buildForgetUrl(int $memoryId): string {
    return $this->buildTokenizedUrl(
      'sales_leadership_diagnostic.memory_forget',
      ['sld_student_memory' => $memoryId],
    );
  }

  /**
   * URL para olvidarlo todo, con su token CSRF.
   */
  private function buildForgetAllUrl(): string {
    return $this->buildTokenizedUrl('sales_leadership_diagnostic.memory_forget_all', []);
  }

  /**
   * Agentes que el alumno puede usar, cada uno con lo que necesita su tarjeta.
   *
   * El alumno con UN agente no ve ningún selector: el panel le enseña su botón
   * y entra directo. Decisión del usuario, 25-08-2026 — poner una pantalla
   * para elegir entre una sola opción es empeorarla, y se confirmó el
   * 31-08-2026 al añadir el segundo nivel.
   *
   * De ahí que las filas lleven DOS destinos, y que la plantilla use uno u
   * otro: con uno solo, el formulario que empieza la conversación; con varios,
   * el enlace a la página del agente, donde lee de qué va antes de decidir.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface[] $agentes
   *   Agentes a los que tiene derecho, indexados por identificador.
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sus sesiones, para saber cuáles tiene a medias.
   * @param bool $varios
   *   Si tiene más de uno. Decide qué datos hacen falta.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por agente disponible.
   */
  private function buildAgents(array $agentes, array $sessions, bool $varios): array {
    $filas = [];

    foreach ($agentes as $agent) {
      $id = (string) $agent->id();
      $enCurso = $this->findResumableId($sessions, $id);

      $filas[] = [
        'id' => $id,
        'label' => $agent->label(),
        'description' => $agent->getDescription(),
        'resume_session_id' => $enCurso,
        'start_url' => $this->buildStartUrl($id),
        // El icono ya existía: se cargaba en la ficha del agente y solo se
        // veía dentro del chat, con la conversación vacía. Aquí es lo que
        // distingue una tarjeta de otra de un vistazo.
        'icon_url' => $varios ? $this->welcome->getIconUrl($agent) : NULL,
        'page_url' => $varios
          ? Url::fromRoute('sales_leadership_diagnostic.agent_page', ['sld_agent' => $id])->toString()
          : NULL,
        'state' => $varios ? $this->estadoDe($enCurso, $sessions, $id) : NULL,
      ];
    }

    return $filas;
  }

  /**
   * En qué punto está el alumno con un agente, para su tarjeta.
   *
   * Se dice en la tarjeta y no solo dentro, porque es lo que hace que la
   * elección sea informada: quien dejó una conversación a medias necesita
   * verlo antes de entrar, no después.
   *
   * @param int|null $enCurso
   *   Conversación a medias con este agente, si la hay.
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno.
   * @param string $agentId
   *   Agente de la tarjeta.
   */
  private function estadoDe(?int $enCurso, array $sessions, string $agentId): string {
    if ($enCurso !== NULL) {
      return (string) $this->t('A medias');
    }

    foreach ($sessions as $session) {
      if ($session->getAgentId() === $agentId
        && $session->getStatus() === DiagnosticStatus::Completed) {
        return (string) $this->t('Realizado');
      }
    }

    return (string) $this->t('Sin empezar');
  }

  /**
   * URL del formulario de inicio de un agente, con su token CSRF.
   *
   * @param string $agentId
   *   Identificador del agente que se va a iniciar.
   */
  private function buildStartUrl(string $agentId): string {
    return $this->buildTokenizedUrl(
      'sales_leadership_diagnostic.start',
      ['sld_agent' => $agentId],
    );
  }

  /**
   * URL de una ruta que exige token CSRF, con el token ya puesto.
   *
   * El token viaja en la URL porque estas rutas lo validan con `_csrf_token`,
   * que es el mecanismo de Drupal para las que no son formularios de la Form
   * API. El valor se calcula sobre la ruta interna, sin la barra inicial, que
   * es lo que espera el validador — y como la ruta lleva sus parametros, cada
   * destino tiene su propio token.
   *
   * @param string $ruta
   *   Nombre de la ruta.
   * @param array<string, mixed> $parametros
   *   Parametros de la ruta.
   */
  private function buildTokenizedUrl(string $ruta, array $parametros): string {
    $internal = ltrim(Url::fromRoute($ruta, $parametros)->getInternalPath(), '/');

    return Url::fromRoute(
      $ruta,
      $parametros,
      ['query' => ['token' => $this->csrfToken->get($internal)]],
    )->toString();
  }

  /**
   * Identificador de la sesión que el alumno tiene a medias, si la hay.
   *
   * Sirve solo para cambiar el texto del botón: quien dejó una conversación
   * empezada lee «Continuar» en vez de «Iniciar», y así no teme perderla al
   * pulsar. Quién puede empezar de verdad lo decide el servidor.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno.
   * @param string $agentId
   *   Agente del que se busca la conversación a medias.
   */
  private function findResumableId(array $sessions, string $agentId): ?int {
    foreach ($sessions as $session) {
      // Se compara también el agente: una conversación a medias con uno no
      // debe cambiar el texto del botón de otro.
      if ($session->getStatus()->acceptsMessages() && $session->getAgentId() === $agentId) {
        return (int) $session->id();
      }
    }

    return NULL;
  }

  /**
   * Aviso sobre la política de repetición, o NULL si no hay límite.
   *
   * Se dice por adelantado y no solo al rechazar: descubrir que el diagnóstico
   * era único DESPUÉS de gastarlo es una mala sorpresa, y quien lo sabe antes
   * puede elegir cuándo dedicarle el rato que necesita.
   */
  private function buildRepeatNotice(): ?string {
    if (!$this->starter->isLimitedToOnePerPeriod()) {
      return NULL;
    }

    return (string) $this->t('Tu acceso incluye un diagnóstico. Podrás realizar uno nuevo cuando renueves.');
  }

  /**
   * Aviso de caducidad próxima del acceso.
   *
   * El acceso al diagnóstico caduca aunque el del curso no lo haga, así que
   * un alumno podría descubrirlo al intentar entrar y encontrarse fuera. El
   * aviso adelanta ese descubrimiento a cuando todavía puede hacer algo.
   *
   * Solo aparece en la recta final: mostrarlo durante todo el año lo
   * convertiría en ruido que nadie lee.
   */
  private function buildExpiryNotice(?AccessDecision $decision): ?array {
    if ($decision === NULL || !$decision->granted) {
      return NULL;
    }

    $dias = $decision->daysUntilExpiry($this->time->getRequestTime());

    if ($dias === NULL || $dias > self::EXPIRY_WARNING_DAYS) {
      return NULL;
    }

    if ($dias <= 0) {
      return ['message' => $this->t('Tu acceso al diagnóstico caduca hoy.')];
    }

    return [
      'message' => $this->formatPlural(
        $dias,
        'Tu acceso al diagnóstico caduca mañana.',
        'Tu acceso al diagnóstico caduca en @count días.',
      ),
    ];
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
