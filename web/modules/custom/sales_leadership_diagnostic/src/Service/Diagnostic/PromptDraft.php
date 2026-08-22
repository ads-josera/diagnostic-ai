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
 */
final class PromptDraft {

  /**
   * Clave del estado donde vive el borrador.
   */
  private const STATE_KEY = 'sales_leadership_diagnostic.prompt_draft';

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
   * Indica si hay un borrador sin publicar.
   */
  public function exists(): bool {
    return is_array($this->state->get(self::STATE_KEY));
  }

  /**
   * Devuelve el borrador guardado, o un array vacío si no hay ninguno.
   *
   * @return array<string, string>
   *   Los campos del borrador.
   */
  public function get(): array {
    $stored = $this->state->get(self::STATE_KEY);

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
  public function getSavedAt(): ?int {
    $stored = $this->state->get(self::STATE_KEY);

    return is_array($stored) && is_numeric($stored['saved_at'] ?? NULL)
      ? (int) $stored['saved_at']
      : NULL;
  }

  /**
   * Guarda el borrador.
   *
   * @param array<string, string> $values
   *   Los campos a guardar. Los que no vengan se guardan vacíos.
   */
  public function save(array $values): void {
    $draft = ['saved_at' => $this->time->getRequestTime()];

    foreach (self::FIELDS as $field) {
      $draft[$field] = trim((string) ($values[$field] ?? ''));
    }

    $this->state->set(self::STATE_KEY, $draft);
  }

  /**
   * Elimina el borrador.
   *
   * Se usa al descartarlo y también al publicarlo: una vez publicado deja de
   * ser un borrador, y conservarlo haría que el estudio siguiera mostrando
   * «hay cambios sin publicar» cuando ya no los hay.
   */
  public function discard(): void {
    $this->state->delete(self::STATE_KEY);
  }

  /**
   * Compone el prompt del borrador, para ensayarlo.
   *
   * Misma composición que la del prompt publicado —las tres partes unidas por
   * una línea en blanco— porque si el ensayo no compusiera igual, probaría algo
   * distinto de lo que luego vivirá el alumno.
   *
   * @param array<string, string> $values
   *   Campos del borrador.
   */
  public static function compose(array $values): string {
    $parts = array_filter(
      [
        trim((string) ($values['system_prompt'] ?? '')),
        trim((string) ($values['instructions'] ?? '')),
        trim((string) ($values['output_contract'] ?? '')),
      ],
      static fn (string $part): bool => $part !== '',
    );

    return implode("\n\n", $parts);
  }

}
