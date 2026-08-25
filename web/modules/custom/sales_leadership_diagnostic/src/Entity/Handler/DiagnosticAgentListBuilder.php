<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity\Handler;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;

/**
 * Listado de agentes para el gestor.
 *
 * Enseña de un vistazo lo que hace falta para saber si un agente está listo:
 * su curso, su versión y si le falta algo. Un agente sin curso o sin prompt no
 * se le ofrece a ningún alumno, y sin decirlo aquí esa ausencia solo se
 * descubre cuando alguien se queja de que no ve su diagnóstico.
 */
final class DiagnosticAgentListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'label' => $this->t('Agente'),
      'course' => $this->t('Curso'),
      'version' => $this->t('Versión'),
      'documents' => $this->t('Documentos'),
      'state' => $this->t('Estado'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof DiagnosticAgentInterface);

    $row['label'] = $entity->label();
    $row['course'] = $entity->getCourseId() !== ''
      ? $entity->getCourseId()
      : $this->t('— sin curso —');
    $row['version'] = $entity->getVersion() !== ''
      ? $entity->getVersion()
      : $this->t('— sin versión —');

    $documentos = count($entity->getKnowledgeFids());
    $row['documents'] = $documentos === 0
      ? $this->t('ninguno')
      : $this->formatPlural($documentos, '1 documento', '@count documentos');

    $row['state'] = $this->describeState($entity);

    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity): array {
    $operations = parent::getOperations($entity);

    $operations['knowledge'] = [
      'title' => $this->t('Documentos'),
      'weight' => 20,
      'url' => Url::fromRoute(
        'sales_leadership_diagnostic.knowledge_agent',
        ['sld_agent' => $entity->id()],
      ),
    ];

    return $operations;
  }

  /**
   * Por qué un agente no está disponible, o que sí lo está.
   *
   * Se dice el motivo concreto y no un «incompleto» genérico: lo que se busca
   * es que quien lo lee sepa qué le falta sin abrir el formulario.
   */
  private function describeState(DiagnosticAgentInterface $entity): string {
    if (!$entity->status()) {
      return (string) $this->t('Deshabilitado');
    }

    $faltan = [];

    if ($entity->getCourseId() === '') {
      $faltan[] = (string) $this->t('el curso');
    }

    if ($entity->getSystemPrompt() === '') {
      $faltan[] = (string) $this->t('el prompt');
    }

    if ($faltan === []) {
      return (string) $this->t('Disponible');
    }

    return (string) $this->t('No se ofrece: falta @que', [
      '@que' => implode(' y ', $faltan),
    ]);
  }

}
