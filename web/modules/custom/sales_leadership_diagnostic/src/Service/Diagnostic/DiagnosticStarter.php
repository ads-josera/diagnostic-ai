<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Exception\CannotStartDiagnosticException;
use Drupal\sales_leadership_diagnostic\RepeatPolicy;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Authorization\DiagnosticAccessChecker;
use Drupal\sales_leadership_diagnostic\Service\Security\RateLimiter;
use Drupal\sales_leadership_diagnostic\Service\Security\UserProvisioner;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Crea la sesión con la que arranca un diagnóstico.
 *
 * Es el único punto del módulo que da existencia a una sesión, y por eso
 * concentra todo lo que debe cumplirse antes: configuración completa,
 * autorización vigente, política de repetición y límite de uso. Repartir esas
 * comprobaciones entre el controlador y la plantilla habría hecho posible
 * saltárselas llamando a la ruta directamente.
 *
 * Guarda además una copia del prompt y su hash en la propia sesión (§57): el
 * prompt cambia con el tiempo, y sin esa copia sería imposible saber con qué
 * instrucciones se produjo un diagnóstico de hace seis meses.
 */
final class DiagnosticStarter {

  /**
   * Cuánto se espera al cerrojo que evita sesiones duplicadas.
   *
   * Corto a propósito: si otra petición del mismo alumno está creando una
   * sesión ahora mismo, lo correcto es rendirse y pedirle que reintente, no
   * dejarle esperando a que acabe.
   */
  private const LOCK_TIMEOUT = 5.0;

  /**
   * Canal de registro del módulo.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DiagnosticReadiness $readiness,
    private readonly DiagnosticPromptManager $prompts,
    private readonly DiagnosticAccessChecker $accessChecker,
    private readonly AgentRegistry $agents,
    private readonly UserProvisioner $provisioner,
    private readonly RateLimiter $rateLimiter,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Inicia un diagnóstico para la cuenta indicada.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Alumno que quiere empezar.
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface $agent
   *   Agente con el que quiere hacerlo. Que llegue hasta aquí no lo autoriza:
   *   se comprueba contra los cursos que posee antes de crear nada.
   *
   * @return \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface
   *   La sesión recién creada, o la que ya estuviera en curso.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\CannotStartDiagnosticException
   *   Si no se cumple alguna de las condiciones para empezar.
   */
  public function start(AccountInterface $account, DiagnosticAgentInterface $agent): DiagnosticSessionInterface {
    $uid = (int) $account->id();

    if (!$this->readiness->isReady()) {
      // Falta configuración: sin prompt o sin credenciales, la sesión nacería
      // condenada a fallar en el primer mensaje.
      throw new CannotStartDiagnosticException(
        'El diagnóstico no está disponible en este momento.',
        CannotStartDiagnosticException::REASON_NOT_READY,
      );
    }

    $externalUserId = $this->provisioner->getExternalUserId($account);

    if ($externalUserId === NULL) {
      throw new CannotStartDiagnosticException(
        'La cuenta no procede de WordPress.',
        CannotStartDiagnosticException::REASON_NOT_AUTHORIZED,
      );
    }

    // Se vuelve a consultar la autorización aquí, aunque la ruta ya la haya
    // comprobado: entre una y otra puede haber pasado tiempo, y crear una
    // sesión es la operación que compromete recursos.
    $decision = $this->accessChecker->decide($externalUserId);

    if ($decision === NULL || !$decision->granted) {
      throw new CannotStartDiagnosticException(
        'La autorización del alumno ya no está vigente.',
        CannotStartDiagnosticException::REASON_NOT_AUTHORIZED,
      );
    }

    // Tener acceso al diagnóstico no basta: hay que tener derecho a ESTE
    // agente. Sin esta comprobación, quien compró un curso podría iniciar
    // cualquier agente escribiendo su identificador en la URL.
    if (!array_key_exists($agent->id(), $this->agents->forDecision($decision))) {
      throw new CannotStartDiagnosticException(
        'El alumno no tiene derecho a este agente.',
        CannotStartDiagnosticException::REASON_NOT_AUTHORIZED,
      );
    }

    // El cerrojo es por alumno Y AGENTE: dos alumnos distintos pueden empezar
    // a la vez sin estorbarse, y un mismo alumno puede abrir dos agentes
    // distintos. Evita que un doble clic —o dos pestañas— creen dos sesiones
    // del mismo agente para la misma persona.
    $lockId = 'sld_start_' . $uid . '_' . $agent->id();

    if (!$this->lock->acquire($lockId, self::LOCK_TIMEOUT)) {
      throw new CannotStartDiagnosticException(
        'Ya se está iniciando un diagnóstico para esta cuenta.',
        CannotStartDiagnosticException::REASON_IN_FLIGHT,
      );
    }

    try {
      // Dentro del cerrojo, porque la comprobación y la creación tienen que
      // ser indivisibles: comprobar fuera dejaría pasar dos peticiones
      // simultáneas que vieran ambas «no hay ninguna».
      $existing = $this->findResumableSession($uid, (string) $agent->id());

      if ($existing !== NULL) {
        // No es un error: quien pulsa «empezar» teniendo una conversación a
        // medias quiere seguirla, y devolverle la suya es más útil que
        // rechazarle o que abrirle una nueva y perder la anterior.
        return $existing;
      }

      $this->assertRepeatPolicyAllows($uid, $decision->startedAt);

      // El límite de uso se comprueba el último: es el más caro de los cuatro
      // y no tiene sentido consultarlo para alguien a quien ya se va a
      // rechazar por otro motivo.
      $this->rateLimiter->assertCanStartDiagnostic($uid);

      $session = $this->createSession($account, $agent, $externalUserId, $decision->courseId);

      $this->rateLimiter->registerDiagnostic($uid);

      return $session;
    }
    finally {
      $this->lock->release($lockId);
    }
  }

  /**
   * Devuelve la sesión que el alumno tiene a medias, si la hay.
   *
   * Solo cuenta como reanudable la que admite mensajes. Una sesión en estado
   * «processing» está esperando respuesta del modelo, y una fallida o
   * completada ya no admite más turnos.
   */
  private function findResumableSession(int $uid, string $agentId): ?DiagnosticSessionInterface {
    $storage = $this->entityTypeManager->getStorage('sld_diagnostic_session');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      // Del MISMO agente. Sin esta condición, tener una conversación a medias
      // con un agente impedía empezar con otro: el alumno pulsaba «empezar» en
      // el segundo y aterrizaba en la conversación del primero.
      ->condition('agent', $agentId)
      ->condition('status', [DiagnosticStatus::Draft->value, DiagnosticStatus::InProgress->value], 'IN')
      // Una prueba a medias no debe secuestrar el botón del alumno.
      ->condition('is_sandbox', FALSE)
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $session = $storage->load((int) reset($ids));

    return $session instanceof DiagnosticSessionInterface ? $session : NULL;
  }

  /**
   * Comprueba que la política de repetición permite empezar otro.
   *
   * @param int $uid
   *   Alumno.
   * @param int|null $periodStart
   *   Momento en que empezó el periodo de acceso vigente, o NULL si WordPress
   *   no lo ha informado.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\CannotStartDiagnosticException
   *   Si ya agotó el diagnóstico de este periodo.
   */
  private function assertRepeatPolicyAllows(int $uid, ?int $periodStart): void {
    $policy = RepeatPolicy::fromConfigValue(
      $this->configFactory->get('sales_leadership_diagnostic.settings')->get('diagnostic.repeat_policy'),
    );

    if ($policy === RepeatPolicy::Unlimited) {
      return;
    }

    if ($periodStart === NULL) {
      // No se puede saber a qué periodo pertenece nada, así que no se aplica
      // el límite. Rechazar sería peor: dejaría sin su diagnóstico a alguien
      // que ha pagado, por un dato que no depende de él. Queda constancia
      // para que el administrador pueda actuar sobre la causa.
      $this->logger->warning(
        'No se ha aplicado el límite de un diagnóstico por periodo a la cuenta @uid porque WordPress no informó del inicio del periodo. Revise que el plugin esté actualizado.',
        ['@uid' => $uid],
      );

      return;
    }

    if ($this->countSessionsSince($uid, $periodStart) === 0) {
      return;
    }

    throw new CannotStartDiagnosticException(
      'Ya se ha realizado el diagnóstico de este periodo.',
      CannotStartDiagnosticException::REASON_ALREADY_DONE,
    );
  }

  /**
   * Cuenta las sesiones del alumno creadas dentro del periodo vigente.
   *
   * Se cuentan las SESIONES y no los resultados: una sesión que falló a mitad
   * también consumió el diagnóstico del periodo, y contar solo los resultados
   * dejaría un hueco por el que reintentar indefinidamente provocando fallos.
   */
  private function countSessionsSince(int $uid, int $periodStart): int {
    return (int) $this->entityTypeManager
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->condition('created', $periodStart, '>=')
      // Un ensayo del prompt no gasta el diagnóstico de nadie.
      ->condition('is_sandbox', FALSE)
      ->count()
      ->execute();
  }

  /**
   * Crea y guarda la sesión.
   */
  private function createSession(AccountInterface $account, DiagnosticAgentInterface $agent, string $externalUserId, string $courseId): DiagnosticSessionInterface {
    $prompt = $this->prompts->composeFor($agent);

    $session = $this->entityTypeManager
      ->getStorage('sld_diagnostic_session')
      ->create([
        'uid' => $account->id(),
        'wp_user_id' => $externalUserId,
        'course_id' => $courseId,
        'agent' => $agent->id(),
        'diagnostic_version' => $agent->getVersion(),
        // Copia literal del prompt con el que se conduce ESTA conversación.
        // Sin ella, un cambio posterior del prompt haría imposible saber con
        // qué instrucciones se generó un diagnóstico antiguo (§57).
        'prompt_snapshot' => $prompt,
        'prompt_hash' => $this->prompts->hash($prompt),
        'started_at' => $this->time->getRequestTime(),
      ]);

    $session->setStatus(DiagnosticStatus::Draft);
    $session->save();

    $this->logger->info(
      'Diagnóstico iniciado: sesión @session, cuenta @uid, versión @version.',
      [
        '@session' => $session->id(),
        '@uid' => $account->id(),
        '@version' => $session->getDiagnosticVersion(),
      ],
    );

    return $session;
  }

  /**
   * Indica si la política vigente limita a un diagnóstico por periodo.
   *
   * Lo consume el panel para explicar al alumno por qué no puede empezar otro.
   */
  public function isLimitedToOnePerPeriod(): bool {
    return RepeatPolicy::fromConfigValue(
      $this->configFactory->get('sales_leadership_diagnostic.settings')->get('diagnostic.repeat_policy'),
    ) === RepeatPolicy::OncePerPeriod;
  }

  /**
   * Etiquetas de cache de la configuración que consulta.
   *
   * @return string[]
   *   Etiquetas de cache.
   */
  public function getCacheTags(): array {
    return $this->configFactory->get('sales_leadership_diagnostic.settings')->getCacheTags();
  }

}
