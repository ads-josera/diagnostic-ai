<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Agent;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;

/**
 * Qué agentes existen, y a cuáles tiene derecho un alumno.
 *
 * La correspondencia entre curso y agente vive AQUÍ, del lado de Drupal, y no
 * en WordPress. El plugin conoce cursos, que es su dominio; el agente es un
 * concepto de este producto. Gracias a eso, añadir un agente nuevo se hace
 * enteramente en Drupal y no obliga a desplegar nada en el WordPress del
 * cliente.
 *
 * Un alumno puede tener derecho a varios agentes a la vez: compra el curso de
 * uno y más tarde el de otro. Lo que decide es qué cursos posee, que es lo que
 * responde el plugin.
 */
final class AgentRegistry {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Agentes utilizables, ya ordenados.
   *
   * Se excluyen los deshabilitados y los que están a medias —sin curso o sin
   * prompt—: un agente incompleto que aparece en el panel del alumno se
   * ofrece y luego falla, que es peor que no ofrecerlo.
   *
   * @return \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface[]
   *   Agentes indexados por su identificador.
   */
  public function getUsable(): array {
    $agentes = array_filter(
      $this->storage()->loadMultiple(),
      static fn (DiagnosticAgentInterface $a): bool => $a->isUsable(),
    );

    uasort(
      $agentes,
      static fn (DiagnosticAgentInterface $a, DiagnosticAgentInterface $b): int
        => [$a->getWeight(), $a->label()] <=> [$b->getWeight(), $b->label()],
    );

    return $agentes;
  }

  /**
   * Un agente por su identificador, o NULL si no existe o no es utilizable.
   */
  public function get(string $id): ?DiagnosticAgentInterface {
    $agente = $this->storage()->load($id);

    return $agente instanceof DiagnosticAgentInterface && $agente->isUsable()
      ? $agente
      : NULL;
  }

  /**
   * Agentes a los que da derecho una decisión de acceso.
   *
   * Devuelve lista vacía si el acceso está denegado, sin mirar los cursos: un
   * periodo caducado no da derecho a ningún agente aunque los cursos sigan
   * comprados. Que la denegación mande está concentrado aquí a propósito, para
   * que no dependa de que cada pantalla se acuerde de comprobarlo.
   *
   * @return \Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface[]
   *   Agentes indexados por identificador, en el orden configurado.
   */
  public function forDecision(?AccessDecision $decision): array {
    if ($decision === NULL || !$decision->granted) {
      return [];
    }

    $cursos = $decision->getOwnedCourses();

    if ($cursos === []) {
      return [];
    }

    return array_filter(
      $this->getUsable(),
      static fn (DiagnosticAgentInterface $a): bool
        => in_array($a->getCourseId(), $cursos, TRUE),
    );
  }

  /**
   * Cursos que conceden algún agente, para preguntar solo por lo que importa.
   *
   * @return string[]
   *   Identificadores de curso, sin repetir.
   */
  public function getCourseIds(): array {
    return array_values(array_unique(array_map(
      static fn (DiagnosticAgentInterface $a): string => $a->getCourseId(),
      $this->getUsable(),
    )));
  }

  /**
   * Etiquetas de cache de la lista de agentes.
   *
   * Quien pinte agentes debe declararlas, o crear uno nuevo no se vería hasta
   * que la página caducase por otro motivo.
   *
   * @return string[]
   *   Etiquetas de cache.
   */
  public function getCacheTags(): array {
    return $this->entityTypeManager->getDefinition('sld_agent')->getListCacheTags();
  }

  /**
   * Almacén de agentes.
   */
  private function storage() {
    return $this->entityTypeManager->getStorage('sld_agent');
  }

}
