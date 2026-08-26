<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\State\StateInterface;

/**
 * Borrador del prompt, mientras se está ajustando.
 *
 * Existe por una razón concreta: sin él, la única forma de probar un cambio en
 * el prompt sería guardarlo, y guardarlo significa que el siguiente alumno que
 * empiece un diagnóstico lo hará con un prompt a medio cocinar. Las
 * conversaciones ya en curso están a salvo —cada sesión congela su copia
 * (§57)— pero las nuevas no.
 *
 * Con el borrador, el gestor ensaya cuanto quiera y publica cuando le
 * convence. Publicar es un acto deliberado y separado.
 *
 * Vive en el estado y NO en configuración, por dos motivos:
 *
 *  - La configuración se exporta a Git y se despliega entre entornos. El
 *    borrador de alguien a medio escribir no es algo que deba viajar.
 *  - Nadie debería poder publicar un prompt sin querer al importar
 *    configuración desde otro sitio.
 *
 * Hay un borrador POR AGENTE, desde el 26-08-2026. Con uno solo daba igual;
 * con varios, un borrador compartido significaba que ensayar un cambio en el
 * agente de prospección pisara el borrador a medias del de liderazgo, y que
 * publicar escribiera en el agente equivocado.
 */
final class PromptDraft {

  /**
   * Prefijo de la clave del estado. Se completa con el agente.
   */
  private const STATE_PREFIX = 'sales_leadership_diagnostic.prompt_draft.';

  /**
   * Campos que componen el prompt.
   *
   * @var string[]
   */
  public const FIELDS = [
    'version',
    'system_prompt',
    'instructions',
    'output_contract',
  ];

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Indica si hay un borrador sin publicar para el agente.
   */
  public function exists(string $agentId): bool {
    return is_array($this->state->get($this->key($agentId)));
  }

  /**
   * Devuelve el borrador guardado, o un array vacío si no hay ninguno.
   *
   * @param string $agentId
   *   Agente cuyo borrador se pide.
   *
   * @return array<string, string>
   *   Los campos del borrador.
   */
  public function get(string $agentId): array {
    $stored = $this->state->get($this->key($agentId));

    if (!is_array($stored)) {
      return [];
    }

    $draft = [];

    foreach (self::FIELDS as $field) {
      $draft[$field] = is_string($stored[$field] ?? NULL) ? $stored[$field] : '';
    }

    return $draft;
  }

  /**
   * Momento del último guardado, o NULL si no hay borrador.
   */
  public function getSavedAt(string $agentId): ?int {
    $stored = $this->state->get($this->key($agentId));

    return is_array($stored) && is_numeric($stored['saved_at'] ?? NULL)
      ? (int) $stored['saved_at']
      : NULL;
  }

  /**
   * Guarda el borrador.
   *
   * @param string $agentId
   *   Agente al que pertenece.
   * @param array<string, string> $values
   *   Los campos a guardar. Los que no vengan se guardan vacíos.
   */
  public function save(string $agentId, array $values): void {
    $draft = ['saved_at' => $this->time->getRequestTime()];

    foreach (self::FIELDS as $field) {
      $draft[$field] = trim((string) ($values[$field] ?? ''));
    }

    $this->state->set($this->key($agentId), $draft);
  }

  /**
   * Elimina el borrador.
   *
   * Se usa al descartarlo y también al publicarlo: una vez publicado deja de
   * ser un borrador, y conservarlo haría que el estudio siguiera mostrando
   * «hay cambios sin publicar» cuando ya no los hay.
   */
  public function discard(string $agentId): void {
    $this->state->delete($this->key($agentId));
  }

  /**
   * Clave del estado para un agente.
   */
  private function key(string $agentId): string {
    return self::STATE_PREFIX . $agentId;
  }

}
