<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;

/**
 * En qué punto está un alumno con un agente: lo hecho y lo pendiente.
 *
 * La tarjeta de cada agente decía solo «A medias», «Realizado» o «Sin
 * empezar», y el 12-09-2026 José Raúl no lo entendía: a un alumno con tres
 * diagnósticos terminados le salía «A medias» porque había abierto el chat y
 * salido sin escribir nada. Dos cosas estaban mal:
 *
 *  - **Una conversación abierta y vacía contaba como empezada.** Basta con
 *    entrar al chat para que nazca en borrador. No es trabajo a medias: no hay
 *    nada que retomar. Si el alumno pulsa «Iniciar», el sistema reutiliza ese
 *    borrador igualmente, así que no se duplica nada.
 *  - **«A medias» tapaba lo ya hecho.** Ahora se dicen las dos cosas:
 *    «3 realizados · uno a medias».
 *
 * Vive aparte y no en los controladores para que el panel y la página del
 * agente no puedan contar distinto, y para poder probarla sola.
 */
final class AgentProgress {

  use StringTranslationTrait;

  /**
   * Lo hecho y lo pendiente del alumno con un agente.
   *
   * @param \Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface[] $sessions
   *   Sesiones del alumno, de la más reciente a la más antigua.
   * @param string $agentId
   *   Agente del que se pregunta.
   *
   * @return array{label: string, completed: int, open: bool, resumable: int|null}
   *   - label: el texto de la tarjeta.
   *   - completed: cuántas terminó.
   *   - open: si tiene una conversación empezada y sin terminar.
   *   - resumable: la conversación que puede continuar escribiendo, o NULL.
   *     Solo cambia el texto del botón a «Continuar»: quien decide qué se
   *     retoma de verdad es DiagnosticStarter.
   */
  public function describe(array $sessions, string $agentId): array {
    $realizados = 0;
    $abierta = FALSE;
    $continuable = NULL;

    foreach ($sessions as $session) {
      // Lo de otro agente no cuenta, ni los ensayos del gestor: no son del
      // alumno aunque figuren a su nombre.
      if ($session->getAgentId() !== $agentId || (bool) $session->get('is_sandbox')->value) {
        continue;
      }

      $estado = $session->getStatus();

      if ($estado === DiagnosticStatus::Completed) {
        $realizados++;
        continue;
      }

      // Empezada de verdad: con al menos un turno, o con uno generándose en
      // segundo plano. El borrador —chat abierto sin nada escrito— no.
      if ($estado === DiagnosticStatus::InProgress || $estado === DiagnosticStatus::Processing) {
        $abierta = TRUE;

        // La más reciente que admite mensajes. Una que se está procesando no
        // se puede continuar escribiendo todavía.
        if ($continuable === NULL && $estado->acceptsMessages()) {
          $continuable = (int) $session->id();
        }
      }
    }

    return [
      'label' => $this->etiqueta($realizados, $abierta),
      'completed' => $realizados,
      'open' => $abierta,
      'resumable' => $continuable,
    ];
  }

  /**
   * El texto de la tarjeta.
   */
  private function etiqueta(int $realizados, bool $abierta): string {
    if ($realizados === 0) {
      return (string) ($abierta ? $this->t('A medias') : $this->t('Sin empezar'));
    }

    $hechos = (string) $this->formatPlural($realizados, '1 realizado', '@count realizados');

    return $abierta
      ? (string) $this->t('@hechos · uno a medias', ['@hechos' => $hechos])
      : $hechos;
  }

}
