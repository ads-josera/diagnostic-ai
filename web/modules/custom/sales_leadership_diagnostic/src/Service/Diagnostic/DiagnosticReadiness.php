<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sales_leadership_diagnostic\ReadinessBlocker;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;

/**
 * Determina si el módulo está en condiciones de ejecutar un diagnóstico.
 *
 * Existe como servicio propio para que "estar listo" se defina en un único
 * sitio. Lo consultan el informe de estado del administrador y el panel del
 * alumno, y una divergencia entre ambos —un panel que ofrece empezar mientras
 * el informe avisa de que falta la API key— sería una fuente segura de errores
 * incomprensibles para el alumno.
 */
final class DiagnosticReadiness {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SecretsProvider $secrets,
    private readonly AgentRegistry $agents,
  ) {}

  /**
   * Indica si puede iniciarse un diagnóstico.
   */
  public function isReady(): bool {
    return $this->blockers() === [];
  }

  /**
   * Motivos que impiden ejecutar diagnósticos ahora mismo.
   *
   * @return \Drupal\sales_leadership_diagnostic\ReadinessBlocker[]
   *   Los motivos que impiden diagnosticar; vacío si no hay ninguno.
   */
  public function blockers(): array {
    $blockers = [];

    if ($this->missingSecrets() !== []) {
      $blockers[] = ReadinessBlocker::MissingSecrets;
    }

    if ($this->missingWordPressSettings() !== []) {
      $blockers[] = ReadinessBlocker::WordPressNotConfigured;
    }

    if (!$this->isAgentLoaded()) {
      $blockers[] = ReadinessBlocker::AgentNotLoaded;
    }

    return $blockers;
  }

  /**
   * Nombres de los secretos que faltan por configurar.
   *
   * @return string[]
   *   Nombres de los ajustes de secreto que siguen vacíos.
   */
  public function missingSecrets(): array {
    return $this->secrets->missing();
  }

  /**
   * Claves de configuración de WordPress que siguen vacías.
   *
   * @return string[]
   *   Claves de WordPress pendientes de configurar.
   */
  public function missingWordPressSettings(): array {
    $config = $this->configFactory->get('sales_leadership_diagnostic.settings');
    $missing = [];

    foreach (['api_base_url', 'course_id'] as $key) {
      if (trim((string) $config->get('wordpress.' . $key)) === '') {
        $missing[] = $key;
      }
    }

    return $missing;
  }

  /**
   * Indica si hay al menos un agente en condiciones de diagnosticar.
   *
   * Se pregunta a los AGENTES, no a la configuración. Hasta el 26-08-2026
   * esto miraba el `system_prompt` de la configuración antigua, que dejó de
   * usarse al pasar a varios agentes: contestaba «sí» porque allí había
   * quedado el prompt provisional, no porque hubiera nada utilizable. Y el
   * día que esa configuración se vaciara, el panel del alumno habría dicho
   * que no puede empezar teniendo su agente perfectamente cargado.
   *
   * `getUsable()` ya exige lo que hace falta de verdad: agente activo, con
   * curso que lo conceda y con prompt.
   */
  public function isAgentLoaded(): bool {
    return $this->agents->getUsable() !== [];
  }

  /**
   * Etiquetas de cache de las que depende esta decisión.
   *
   * Permite que quien la use marque correctamente su propia salida. Los
   * secretos no aparecen aquí porque viven en variables de entorno y no en
   * configuración: cambiarlos exige reiniciar el entorno, que ya invalida
   * toda la cache.
   *
   * @return string[]
   *   Las etiquetas de cache de las que depende esta decisión.
   */
  public function getCacheTags(): array {
    return array_merge(
      ['config:sales_leadership_diagnostic.settings'],
      // Crear, publicar o deshabilitar un agente cambia esta decisión.
      $this->agents->getCacheTags(),
    );
  }

}
