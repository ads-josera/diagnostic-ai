<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sales_leadership_diagnostic\DTO\Entitlement;
use Drupal\sales_leadership_diagnostic\ResearchAccess;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolGateway;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;

/**
 * Decide qué dice `research_budget` en el bloque que lee el agente.
 *
 * Existe por un fallo que llegó al cliente el 02-10-2026 y que costó una
 * comparación perdida contra ChatGPT.
 *
 * El §2 de la especificación del cliente define **tres** valores para ese
 * campo —`AVAILABLE | LIMITED | EXHAUSTED`— y el módulo solo emitía dos:
 * decía `LIMITED` siempre que hubiera cualquier capacidad, incluso en una
 * misión recién abierta con las cuarenta búsquedas intactas. En el propio
 * ejemplo de esa especificación, `LIMITED` aparece justo en el caso contrario:
 * una misión ya cerrada, con alcance estrecho.
 *
 * El agente razona con ese vocabulario, porque es el suyo. Leyó «presupuesto
 * limitado» desde la primera búsqueda, se racionó, hizo ocho de las cuarenta
 * que tenía y después le dijo a la persona que no había podido cribar diez
 * cuentas «dentro del límite de investigación disponible». No se había
 * denegado ni una sola llamada.
 *
 * Y había un segundo desajuste con la misma especificación: su tabla de
 * estados manda poner `EXHAUSTED` cuando el presupuesto se agota, y el módulo
 * solo lo ponía al acabarse el cupo SEMANAL. Si una misión chocaba contra sus
 * topes, el bloque seguía diciendo `LIMITED` mientras el gateway denegaba por
 * detrás: el agente no tenía forma de saber que ya no podía buscar.
 *
 * De ahí la regla de esta clase: **el campo sigue el consumo real**. Un estado
 * que no cambia nunca no es un estado, es un adorno.
 *
 * Lo que NO hace, y es deliberado: no dice cuántas búsquedas quedan ni cuánto
 * se ha gastado. El §5 lo prohíbe —«no exponer costos internos, secretos,
 * límites monetarios ni lógica sensible»— y lo que el agente necesita es
 * capacidad operativa, no contabilidad.
 */
final class ResearchBudget {

  /**
   * Proporción del tope a partir de la cual se avisa de que queda poco.
   *
   * Tres cuartos y no un valor más alto porque el aviso tiene que llegar con
   * margen para que el agente cambie de plan: enterarse al 95 % es enterarse
   * cuando ya no se puede hacer nada distinto.
   */
  private const CERCA_DEL_TOPE = 0.75;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ToolCallRepository $calls,
    private readonly SpendGuard $spend,
  ) {}

  /**
   * El valor de `research_budget` para esta sesión, ahora mismo.
   *
   * @param \Drupal\sales_leadership_diagnostic\DTO\Entitlement $entitlement
   *   Lo que la persona puede esta semana.
   * @param int $maxRechecks
   *   Comprobaciones puntuales que concede el periodo.
   * @param int $sessionId
   *   Conversación, para medir lo consumido por esta misión.
   *
   * @return string
   *   `AVAILABLE`, `LIMITED` o `EXHAUSTED`, el vocabulario del §2.
   */
  public function forSession(Entitlement $entitlement, int $maxRechecks, int $sessionId): string {
    $acceso = $entitlement->access($maxRechecks);

    // Sin entitlement no hay nada que medir: no puede buscar y punto.
    if (!$acceso->allowsAnything()) {
      return 'EXHAUSTED';
    }

    $uso = $this->calls->usedInMission($sessionId, ToolGateway::EXENTAS_DE_TOPE);
    $usadasEnElPeriodo = $this->calls->usedByUserSince(
      $entitlement->uid,
      $this->spend->periodStart(),
      ToolGateway::EXENTAS_DE_TOPE,
    );

    $proporciones = [
      $this->proporcion($uso['calls'], 'max_calls_per_mission'),
      $this->proporcion($uso['chars'], 'max_retrieved_chars_per_mission'),
      $this->proporcion($usadasEnElPeriodo, 'max_calls_per_user_period'),
    ];

    $gastado = max($proporciones);

    // Agotado de verdad: el gateway ya está denegando. Decirlo evita que el
    // agente siga intentando y acabe explicándole a la persona un fallo que
    // en realidad es un tope.
    if ($gastado >= 1.0) {
      return 'EXHAUSTED';
    }

    // Alcance estrecho —la misión de la semana ya se cerró y solo quedan
    // comprobaciones puntuales— o quedando poco margen. Son las dos
    // situaciones en las que el agente DEBE administrar lo que le queda.
    if ($acceso === ResearchAccess::TargetedOnly || $gastado >= self::CERCA_DEL_TOPE) {
      return 'LIMITED';
    }

    return 'AVAILABLE';
  }

  /**
   * Qué proporción de un tope se ha consumido.
   *
   * Un tope en cero significa «sin tope», y entonces no consume proporción
   * ninguna: devolver 1.0 ahí dejaría al agente creyendo que no puede buscar
   * justo cuando puede hacerlo sin medida.
   */
  private function proporcion(int $usado, string $ajuste): float {
    $tope = (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('tools.' . $ajuste);

    return $tope > 0 ? $usado / $tope : 0.0;
  }

}
