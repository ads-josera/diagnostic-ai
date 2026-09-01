<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;

/**
 * Las filas del historial que ve el alumno.
 *
 * Vive aparte porque desde el 31-08-2026 lo pintan DOS pantallas y no una: el
 * panel, cuando el alumno tiene un solo agente, y la página de cada agente,
 * cuando tiene varios. Con el historial dentro del controller del panel, la
 * segunda pantalla habría copiado las mismas veinte líneas, que es como
 * empiezan a divergir dos tablas que deberían ser la misma.
 *
 * El filtrado por agente no es cosmético: es lo que sustituye a poner una
 * columna «Agente» en la tabla. Cuando cada agente tiene su propia página, la
 * página YA dice de quién es el historial, y una columna que repite el título
 * de la pantalla en todas sus filas es ruido.
 */
final class StudentHistoryBuilder {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Todas las sesiones del alumno.
   *
   * Es lo que ve quien tiene un solo agente: ahí no hay nada que separar.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno, de la más reciente a la más antigua.
   * @param array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface> $results
   *   Sus resultados, indexados por la sesión que los produjo.
   *
   * @return array<int, array<string, mixed>>
   *   Filas listas para la plantilla.
   */
  public function all(array $sessions, array $results): array {
    return $this->build($sessions, $results, static fn (): bool => TRUE);
  }

  /**
   * Solo las sesiones de un agente.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno.
   * @param array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface> $results
   *   Sus resultados, indexados por sesión.
   * @param string $agentId
   *   Agente del que se quiere el historial.
   *
   * @return array<int, array<string, mixed>>
   *   Filas listas para la plantilla.
   */
  public function forAgent(array $sessions, array $results, string $agentId): array {
    return $this->build(
      $sessions,
      $results,
      static fn (DiagnosticSessionInterface $s): bool => $s->getAgentId() === $agentId,
    );
  }

  /**
   * Las sesiones que NO son de ninguno de los agentes indicados.
   *
   * Existe para que no se pierda de vista un diagnóstico pagado. Cuando el
   * alumno tiene varios agentes, su historial se reparte entre las páginas de
   * cada uno; una sesión de un agente que ya no aparece —porque el
   * administrador lo deshabilitó, o porque caducó el curso que lo concedía—
   * se quedaría sin página donde salir y el alumno perdería el enlace a un
   * resultado que es suyo.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno.
   * @param array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface> $results
   *   Sus resultados, indexados por sesión.
   * @param string[] $agentIds
   *   Agentes que ya tienen su propia página.
   *
   * @return array<int, array<string, mixed>>
   *   Filas listas para la plantilla. Vacío es lo normal.
   */
  public function excludingAgents(array $sessions, array $results, array $agentIds): array {
    return $this->build(
      $sessions,
      $results,
      static fn (DiagnosticSessionInterface $s): bool
        => !in_array($s->getAgentId(), $agentIds, TRUE),
    );
  }

  /**
   * Construye las filas de las sesiones que pasan el filtro (§36).
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno.
   * @param array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface> $results
   *   Sus resultados, indexados por sesión.
   * @param callable $filtro
   *   Recibe la sesión y decide si entra.
   *
   * @return array<int, array<string, mixed>>
   *   Filas listas para la plantilla.
   */
  private function build(array $sessions, array $results, callable $filtro): array {
    $rows = [];
    $statusLabels = DiagnosticStatus::allowedValues();

    foreach ($sessions as $session) {
      if (!$filtro($session)) {
        continue;
      }

      $id = (int) $session->id();
      $status = $session->getStatus();

      $rows[] = [
        'id' => $id,
        'date' => $this->dateFormatter->format((int) $session->get('created')->value, 'short'),
        'status' => $statusLabels[$status->value] ?? $status->value,
        'status_machine' => $status->value,
        'version' => $session->getDiagnosticVersion(),
        // El enlace al resultado solo aparece si el resultado existe de
        // verdad. Ofrecer un enlace que lleva a un 403 o a un 404 sería peor
        // que no ofrecer ninguno.
        'result_id' => isset($results[$id]) ? (int) $results[$id]->id() : NULL,
        'is_resumable' => $status->acceptsMessages(),
      ];
    }

    return $rows;
  }

}
