<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;

/**
 * Conversaciones de ensayo con las que el gestor prueba el prompt.
 *
 * Son conversaciones REALES —el mismo motor, el mismo servicio, el mismo
 * recorrido— pero marcadas como prueba. Esa marca es lo que las mantiene fuera
 * del listado de resultados y del límite de diagnósticos por periodo.
 *
 * Se ensayan con el prompt en BORRADOR, que es todo el sentido del estudio:
 * poder ver cómo se comporta un cambio antes de que lo viva ningún alumno.
 *
 * Cada gestor tiene la suya y solo puede haber una viva a la vez. Acumular
 * ensayos no aporta nada —lo que se quiere es probar el prompt de ahora— y
 * llenaría la base de datos de conversaciones que nadie va a volver a leer.
 */
final class SandboxSessionManager {

  /**
   * Valor que ocupa el lugar de los datos de WordPress.
   *
   * Los campos son obligatorios porque para un alumno lo son. Un ensayo no
   * procede de ningún alumno ni de ningún curso, y este valor lo deja escrito
   * en la propia fila en lugar de dejar algo que parezca un dato real.
   */
  private const NOT_APPLICABLE = 'sandbox';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PromptDraft $draft,
    private readonly DiagnosticPromptManager $prompts,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Devuelve la conversación de ensayo del gestor, creándola si hace falta.
   */
  public function getOrCreate(AccountInterface $account): DiagnosticSessionInterface {
    $existing = $this->findFor($account);

    return $existing ?? $this->create($account);
  }

  /**
   * Descarta la conversación actual y empieza una nueva.
   *
   * Es la operación más usada del estudio: se cambia el prompt, se reinicia y
   * se vuelve a probar desde el primer turno, que es donde se nota el cambio.
   */
  public function reset(AccountInterface $account): DiagnosticSessionInterface {
    $this->deleteFor($account);

    return $this->create($account);
  }

  /**
   * Elimina las conversaciones de ensayo del gestor.
   *
   * Borra la sesión entera, con sus mensajes: Drupal se encarga de la tabla de
   * mensajes al eliminar la sesión, porque cuelga de ella.
   */
  public function deleteFor(AccountInterface $account): void {
    $storage = $this->entityTypeManager->getStorage('sld_diagnostic_session');
    $sessions = $storage->loadMultiple($this->idsFor($account));

    if ($sessions !== []) {
      $storage->delete($sessions);
    }
  }

  /**
   * Indica si una sesión es de ensayo.
   *
   * Lo usan las rutas del estudio para negarse a operar sobre una conversación
   * de un alumno, por mucho que quien lo intente tenga permiso para editar el
   * prompt.
   */
  public function isSandbox(DiagnosticSessionInterface $session): bool {
    return (bool) $session->get('is_sandbox')->value;
  }

  /**
   * Busca la conversación de ensayo viva del gestor.
   */
  private function findFor(AccountInterface $account): ?DiagnosticSessionInterface {
    $ids = $this->idsFor($account);

    if ($ids === []) {
      return NULL;
    }

    $session = $this->entityTypeManager
      ->getStorage('sld_diagnostic_session')
      ->load(reset($ids));

    return $session instanceof DiagnosticSessionInterface ? $session : NULL;
  }

  /**
   * Identificadores de las conversaciones de ensayo del gestor.
   *
   * @return int[]
   *   Identificadores, del más reciente al más antiguo.
   */
  private function idsFor(AccountInterface $account): array {
    $ids = $this->entityTypeManager
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', (int) $account->id())
      ->condition('is_sandbox', TRUE)
      ->sort('created', 'DESC')
      ->execute();

    return array_map('intval', array_values($ids));
  }

  /**
   * Crea una conversación de ensayo con el prompt en borrador.
   */
  private function create(AccountInterface $account): DiagnosticSessionInterface {
    $values = $this->draft->exists() ? $this->draft->get() : [];

    // Sin borrador se ensaya el prompt publicado. Es lo razonable al abrir el
    // estudio por primera vez: se ve cómo se comporta lo que hay hoy antes de
    // decidir qué cambiar.
    $prompt = $values === []
      ? $this->prompts->compose()
      : PromptDraft::compose($values);

    $version = trim((string) ($values['version'] ?? ''));

    $session = $this->entityTypeManager
      ->getStorage('sld_diagnostic_session')
      ->create([
        'uid' => $account->id(),
        'wp_user_id' => self::NOT_APPLICABLE,
        'course_id' => self::NOT_APPLICABLE,
        'diagnostic_version' => $version === '' ? $this->prompts->getCurrentVersion() : $version,
        'prompt_snapshot' => $prompt,
        'prompt_hash' => $this->prompts->hash($prompt),
        'started_at' => $this->time->getRequestTime(),
        'is_sandbox' => TRUE,
      ]);

    $session->setStatus(DiagnosticStatus::Draft);
    $session->save();

    return $session;
  }

}
