<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

use Drupal\sales_leadership_diagnostic\MissionState;
use Drupal\sales_leadership_diagnostic\ResearchAccess;

/**
 * Qué puede investigar una persona en la semana en curso.
 *
 * Es el «modelo de entitlement» del §2 de la especificación del cliente. Su
 * alcance no es la conversación sino **la persona y el periodo**, y eso es lo
 * que hace que abrir otro chat no devuelva una misión nueva.
 */
final readonly class Entitlement {

  /**
   * Construye el estado de una persona.
   *
   * @param int $uid
   *   Persona.
   * @param string $period
   *   Semana ISO en su zona horaria, por ejemplo `2026-W37`.
   * @param \Drupal\sales_leadership_diagnostic\MissionState $state
   *   En qué punto está su misión.
   * @param string $missionId
   *   Identificador opaco de la misión. Vacío si no ha abierto ninguna.
   * @param int|null $sessionId
   *   Conversación donde la abrió, si la abrió.
   * @param int $rechecksUsed
   *   Comprobaciones puntuales gastadas tras cerrarla.
   */
  public function __construct(
    public int $uid,
    public string $period,
    public MissionState $state,
    public string $missionId = '',
    public ?int $sessionId = NULL,
    public int $rechecksUsed = 0,
  ) {}

  /**
   * Cuánta investigación externa permite este estado.
   *
   * @param int $maxRechecks
   *   Comprobaciones puntuales que se conceden tras cerrar la misión.
   */
  public function access(int $maxRechecks): ResearchAccess {
    return match ($this->state) {
      // Con la misión abierta se investiga; los topes del gateway ponen el
      // resto de los límites.
      MissionState::Active => ResearchAccess::Allowed,

      // Cerrada, quedan comprobaciones puntuales y nada más. Es el «permitir
      // alcance estrecho; NO resetear misión» de su §4.
      MissionState::Completed, MissionState::LockedUntilRenewal => $this->rechecksUsed < $maxRechecks
        ? ResearchAccess::TargetedOnly
        : ResearchAccess::NotAvailable,

      // Puede investigar, y al hacerlo abrirá su misión de la semana.
      //
      // La misión se abre en la PRIMERA búsqueda concedida, no al empezar la
      // conversación. Abrirla al empezar quemaría la misión semanal de quien
      // entra solo a preguntar algo, y el §4 exige que la transición la haga
      // el backend, que es justo lo que ocurre: la abre el gateway al conceder
      // la primera llamada, no el modelo al pedirla.
      MissionState::Available => ResearchAccess::Allowed,
    };
  }

}
