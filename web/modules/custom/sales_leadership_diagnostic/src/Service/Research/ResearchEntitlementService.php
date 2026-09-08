<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\Entitlement;
use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Gobierna quién puede investigar y cuándo.
 *
 * Implementa el modelo y la máquina de estados de los §2 y §4 de la
 * especificación del cliente. Tres decisiones suyas mandan sobre el diseño:
 *
 *  - **El alcance es persona + periodo, no conversación.** «Compartido entre
 *    conversaciones y Entry Modes». Por eso el estado no cuelga de la sesión:
 *    si colgara, abrir otro chat devolvería una misión nueva.
 *  - **La renovación es semanal y no depende de abrir un chat.** Se calcula con
 *    la zona horaria de la persona, así que la semana empieza donde ella vive y
 *    no donde esté el servidor.
 *  - **Solo el backend cambia el estado.** El agente lo lee; no lo pide ni lo
 *    negocia.
 *
 * La transición a ACTIVE es atómica. El §14 pide «lock atómico por user/week
 * para impedir dos misiones ACTIVE», y aquí lo da la propia base: la clave
 * única por persona y semana, más un UPDATE condicionado al estado anterior.
 * Dos peticiones simultáneas no abren dos misiones; la segunda no toca ninguna
 * fila y se entera.
 */
final class ResearchEntitlementService {

  /**
   * Nombre de la tabla.
   */
  private const TABLE = 'sld_research_entitlement';

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * El entitlement de una persona para la semana en curso.
   *
   * Si no existe, se crea en AVAILABLE. La renovación semanal no necesita
   * ningún proceso que la dispare: al cambiar la semana cambia la clave, no se
   * encuentra fila y nace una nueva. Lo anterior se conserva, que es lo que
   * pide «conservar evidencia histórica válida».
   */
  public function forUser(int $uid): Entitlement {
    $periodo = $this->periodFor($uid);
    $fila = $this->load($uid, $periodo);

    if ($fila === NULL) {
      $this->database->merge(self::TABLE)
        ->keys(['uid' => $uid, 'period' => $periodo])
        ->fields([
          'mission_state' => MissionState::Available->value,
          'changed' => $this->time->getRequestTime(),
        ])
        ->execute();

      $fila = $this->load($uid, $periodo);
    }

    return $this->toDto($uid, $periodo, $fila ?? []);
  }

  /**
   * Abre una misión, si se puede.
   *
   * @return bool
   *   Cierto si esta llamada fue la que la abrió. Falso si ya no era posible
   *   —porque el estado no lo permitía o porque otra petición se adelantó—.
   */
  public function startMission(int $uid, int $sessionId): bool {
    $periodo = $this->periodFor($uid);
    $this->forUser($uid);

    $ahora = $this->time->getRequestTime();

    // Condicionado al estado anterior: es lo que impide que dos peticiones
    // simultáneas abran dos misiones. La que llegue segunda no toca ninguna
    // fila.
    $tocadas = $this->database->update(self::TABLE)
      ->fields([
        'mission_state' => MissionState::Active->value,
        'mission_id' => $this->newMissionId($uid, $periodo),
        'session_id' => $sessionId,
        'started_at' => $ahora,
        'changed' => $ahora,
      ])
      ->condition('uid', $uid)
      ->condition('period', $periodo)
      ->condition('mission_state', MissionState::Available->value)
      ->execute();

    if ($tocadas > 0) {
      $this->logger->info('Misión de investigación abierta para el alumno @uid en @periodo.', [
        '@uid' => $uid,
        '@periodo' => $periodo,
      ]);
    }

    return $tocadas > 0;
  }

  /**
   * Cierra la misión en curso.
   *
   * Después de cerrar, los follow-ups siguen funcionando con la evidencia ya
   * recogida: lo que se acaba es la capacidad de abrir investigación nueva.
   */
  public function completeMission(int $uid): void {
    $ahora = $this->time->getRequestTime();

    $this->database->update(self::TABLE)
      ->fields([
        'mission_state' => MissionState::Completed->value,
        'completed_at' => $ahora,
        'changed' => $ahora,
      ])
      ->condition('uid', $uid)
      ->condition('period', $this->periodFor($uid))
      ->condition('mission_state', MissionState::Active->value)
      ->execute();
  }

  /**
   * Anota que se gastó una comprobación puntual.
   */
  public function useRecheck(int $uid): void {
    $this->database->update(self::TABLE)
      ->expression('rechecks_used', 'rechecks_used + 1')
      ->fields(['changed' => $this->time->getRequestTime()])
      ->condition('uid', $uid)
      ->condition('period', $this->periodFor($uid))
      ->execute();
  }

  /**
   * Retira la capacidad de investigar hasta la renovación.
   *
   * Se distingue de cerrar la misión: aquello dice «la hizo», esto dice «se le
   * retiró». Lo usa quien administra, no el agente.
   */
  public function lock(int $uid): void {
    $this->database->update(self::TABLE)
      ->fields([
        'mission_state' => MissionState::LockedUntilRenewal->value,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('uid', $uid)
      ->condition('period', $this->periodFor($uid))
      ->execute();
  }

  /**
   * Comprobaciones puntuales que se conceden tras cerrar la misión.
   */
  public function maxRechecks(): int {
    $valor = (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('research.max_rechecks_per_period');

    return max(0, $valor);
  }

  /**
   * La semana en curso de una persona, en SU zona horaria.
   *
   * No en la del servidor. Con el servidor en UTC y la persona en México, la
   * semana cambiaría a media tarde del domingo y la renovación llegaría cuando
   * nadie la espera.
   */
  public function periodFor(int $uid): string {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone($this->timezoneOf($uid)))
      // Año ISO y número de semana ISO: `2026-W37`. La semana empieza el lunes.
      ->format('o-\WW');
  }

  /**
   * Zona horaria de una persona, con la del sitio como respaldo.
   */
  private function timezoneOf(int $uid): string {
    try {
      $cuenta = $this->entityTypeManager->getStorage('user')->load($uid);
      $suya = $cuenta?->getTimeZone();

      if (is_string($suya) && $suya !== '') {
        return $suya;
      }
    }
    catch (\Throwable) {
      // Una cuenta ilegible no debe impedir calcular un periodo: se cae al del
      // sitio, que es lo que se haría de todos modos.
    }

    return (string) ($this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC');
  }

  /**
   * Identificador opaco de misión.
   *
   * Opaco a propósito: se le enseña al agente y de ahí puede acabar en un
   * texto que lea una persona. No debe revelar identificadores internos.
   *
   * @param int $uid
   *   Persona a la que pertenece la misión.
   * @param string $periodo
   *   Semana en la que se abre.
   */
  private function newMissionId(int $uid, string $periodo): string {
    return substr(hash('sha256', $uid . '|' . $periodo . '|' . $this->time->getRequestTime()), 0, 16);
  }

  /**
   * Carga la fila cruda, si existe.
   *
   * @param int $uid
   *   Persona.
   * @param string $periodo
   *   Semana.
   *
   * @return array<string, mixed>|null
   *   La fila, o NULL.
   */
  private function load(int $uid, string $periodo): ?array {
    $fila = $this->database->select(self::TABLE, 'e')
      ->fields('e')
      ->condition('uid', $uid)
      ->condition('period', $periodo)
      ->execute()
      ->fetchAssoc();

    return $fila === FALSE ? NULL : $fila;
  }

  /**
   * Convierte la fila en algo con sentido.
   *
   * @param int $uid
   *   Persona.
   * @param string $periodo
   *   Semana.
   * @param array<string, mixed> $fila
   *   Fila cruda.
   */
  private function toDto(int $uid, string $periodo, array $fila): Entitlement {
    return new Entitlement(
      uid: $uid,
      period: $periodo,
      state: MissionState::tryFrom((string) ($fila['mission_state'] ?? '')) ?? MissionState::Available,
      missionId: (string) ($fila['mission_id'] ?? ''),
      sessionId: isset($fila['session_id']) ? (int) $fila['session_id'] : NULL,
      rechecksUsed: (int) ($fila['rechecks_used'] ?? 0),
    );
  }

}
