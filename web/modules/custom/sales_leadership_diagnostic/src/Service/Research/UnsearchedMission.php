<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\ProviderAccountStatus;

/**
 * Decide si una misión terminada no debe contarle al alumno.
 *
 * El 05-10-2026 se agotó el plan del buscador a mitad de una misión. El agente
 * cerró igualmente el Pack, sin una sola búsqueda útil, y con eso se gastó la
 * única misión de la semana del alumno: siete días sin agente por un corte que
 * era nuestro.
 *
 * Se devuelve solo si las dos cosas son ciertas a la vez:
 *
 * - el buscador está marcado sin servicio por un problema de su cuenta (lo
 *   anota ProviderAccountStatus, no se deduce aquí);
 * - y en esa misión ninguna búsqueda trajo nada.
 *
 * Con una sola de las dos no basta. Un buscador que se corta al final de una
 * misión que ya investigó deja un Pack bueno, y ese sí cuenta. Una misión sin
 * resultados con el buscador en marcha es del alumno o del agente, no del
 * servicio, y devolverla abriría la puerta a repetir misiones a voluntad.
 */
final class UnsearchedMission {

  /**
   * Lo que lee el alumno. Sin nombres de proveedores ni de saldo: no le toca.
   */
  public const NOTA = '**Aviso de la plataforma:** La investigación no pudo hacerse por un problema temporal del servicio. Esta misión no cuenta: podrás repetirla cuando se restablezca.';

  public function __construct(
    private readonly ProviderAccountStatus $providers,
    private readonly ToolCallRepository $calls,
  ) {}

  /**
   * Si a la misión de esta conversación hay que devolvérsela al alumno.
   *
   * @param int $sessionId
   *   Conversación que la abrió.
   *
   * @return bool
   *   Cierto si no pudo investigar por el corte del buscador.
   */
  public function applies(int $sessionId): bool {
    return $this->providers->isUnavailable(ProviderAccountStatus::BUSCADOR)
      && $this->calls->fruitfulInMission($sessionId, WebSearchTool::NAME) === 0;
  }

}
