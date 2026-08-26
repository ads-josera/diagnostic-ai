<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\Entity\Handler\DiagnosticResultAccessControlHandler;
use Drupal\user\EntityOwnerTrait;

/**
 * Resultado de un diagnóstico completado.
 *
 * Es una entidad separada de la sesión y no un campo suyo porque tiene su
 * propia ruta, su propio control de acceso y su propia vida en el historial
 * del alumno.
 *
 * A diferencia de la sesión, no declara handler de Views: sus campos contienen
 * el contenido del diagnóstico —información empresarial sensible— y no debe
 * quedar navegable desde la interfaz de administración (§43).
 */
#[ContentEntityType(
  id: 'sld_diagnostic_result',
  label: new TranslatableMarkup('Resultado de diagnóstico'),
  label_singular: new TranslatableMarkup('resultado de diagnóstico'),
  label_plural: new TranslatableMarkup('resultados de diagnóstico'),
  label_collection: new TranslatableMarkup('Resultados de diagnóstico'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'uid' => 'uid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => DiagnosticResultAccessControlHandler::class,
  ],
  base_table: 'sld_diagnostic_result',
  admin_permission: 'administer sales leadership diagnostic',
  label_count: [
    'singular' => '@count resultado de diagnóstico',
    'plural' => '@count resultados de diagnóstico',
  ],
)]
class DiagnosticResult extends ContentEntityBase implements DiagnosticResultInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['session_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Sesión'))
      ->setRequired(TRUE)
      ->setSetting('target_type', 'sld_diagnostic_session');

    $fields['agent'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Agente'))
      ->setDescription(new TranslatableMarkup('Agente con el que se hizo. Se guarda por sesión y no se deduce del curso: el curso que concede un agente puede cambiar, y el historial debe seguir diciendo con cuál se conversó de verdad.'))
      ->setSetting('max_length', 64);

    $fields['diagnostic_version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Versión del diagnóstico'))
      ->setDescription(new TranslatableMarkup('Se copia de la sesión para que el resultado siga siendo interpretable aunque la sesión se elimine.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['summary'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Resumen'));

    $fields['score'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Puntuación'))
      ->setDescription(new TranslatableMarkup('Opcional: solo tiene valor si la metodología del cliente contempla una puntuación.'));

    $fields['payload'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Estructura completa'))
      ->setDescription(new TranslatableMarkup('Resultado íntegro en JSON, ya validado. Se guarda como estructura en lugar de desplegarse en campos porque la forma definitiva depende de la metodología del cliente; los campos que necesiten consulta se promoverán después mediante una actualización.'));

    $fields['is_sandbox'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Conversación de prueba'))
      ->setDescription(new TranslatableMarkup('Marca las conversaciones que el gestor crea al ajustar el prompt. Se excluyen del listado de resultados y no cuentan para el límite de diagnósticos por periodo: son ensayos, no diagnósticos de un alumno.'))
      ->setDefaultValue(FALSE)
      ->setSetting('on_label', new TranslatableMarkup('Prueba'))
      ->setSetting('off_label', new TranslatableMarkup('Real'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Creado'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) new TranslatableMarkup('Resultado #@id', ['@id' => $this->id() ?? '—']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSession(): ?DiagnosticSessionInterface {
    $session = $this->get('session_id')->entity;
    return $session instanceof DiagnosticSessionInterface ? $session : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSessionId(): ?int {
    $value = $this->get('session_id')->target_id;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getAgentId(): string {
    return (string) $this->get('agent')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getDiagnosticVersion(): string {
    return (string) $this->get('diagnostic_version')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSummary(): string {
    return (string) $this->get('summary')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getScore(): ?int {
    $value = $this->get('score')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   *
   * Un JSON corrupto devuelve un array vacío en lugar de propagar un error:
   * el historial debe seguir siendo navegable aunque un registro concreto esté
   * dañado.
   */
  public function getPayload(): array {
    $raw = (string) $this->get('payload')->value;

    if ($raw === '') {
      return [];
    }

    $decoded = json_decode($raw, TRUE);

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setPayload(array $payload): static {
    $this->set('payload', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMaturity(): string {
    return trim((string) ($this->getPayload()['maturity'] ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfidence(): string {
    return trim((string) ($this->getPayload()['confidence'] ?? ''));
  }

  /**
   * {@inheritdoc}
   */
  public function getDimensions(): array {
    $crudas = $this->getPayload()['dimensions'] ?? [];

    if (!is_array($crudas)) {
      return [];
    }

    $dimensiones = [];

    foreach ($crudas as $cruda) {
      if (!is_array($cruda)) {
        continue;
      }

      $nombre = trim((string) ($cruda['name'] ?? ''));

      // Una dimensión sin nombre no se puede mostrar ni comparar con nada.
      // Se descarta esa y se siguen leyendo las demás: perder la tabla entera
      // porque una entrada viniera mal sería peor.
      if ($nombre === '') {
        continue;
      }

      // El máximo cae a 10 si no viene o no tiene sentido: es el de la
      // metodología del cliente, y un cero haría estallar cualquier división
      // al pintar la barra.
      $maximo = (float) ($cruda['max'] ?? 0);

      $dimensiones[] = [
        'name' => $nombre,
        'score' => (float) ($cruda['score'] ?? 0),
        'max' => $maximo > 0 ? $maximo : 10.0,
        'level' => trim((string) ($cruda['level'] ?? '')),
        'confidence' => trim((string) ($cruda['confidence'] ?? '')),
      ];
    }

    return $dimensiones;
  }

}
