<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\Handler\DiagnosticSessionAccessControlHandler;
use Drupal\user\EntityOwnerTrait;
use Drupal\views\EntityViewsData;

/**
 * Sesión de diagnóstico de un alumno.
 *
 * Es una entidad de contenido y no una tabla propia porque necesita las tres
 * cosas que Entity API ya resuelve bien: propiedad del registro, control de
 * acceso por entidad y resolución automática del parámetro de ruta. Esa
 * resolución es la que permite que la protección contra IDOR ocurra en el
 * enrutador, antes de entrar al controller.
 *
 * No declara handlers de rutas a propósito: la entidad no expone ninguna URL
 * propia. Todo acceso pasa por las rutas explícitas del módulo, que aplican
 * además autenticación, autorización y comprobación de propiedad.
 */
#[ContentEntityType(
  id: 'sld_diagnostic_session',
  label: new TranslatableMarkup('Sesión de diagnóstico'),
  label_singular: new TranslatableMarkup('sesión de diagnóstico'),
  label_plural: new TranslatableMarkup('sesiones de diagnóstico'),
  label_collection: new TranslatableMarkup('Sesiones de diagnóstico'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'uid' => 'uid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => DiagnosticSessionAccessControlHandler::class,
    'views_data' => EntityViewsData::class,
  ],
  base_table: 'sld_diagnostic_session',
  admin_permission: 'administer sales leadership diagnostic',
  label_count: [
    'singular' => '@count sesión de diagnóstico',
    'plural' => '@count sesiones de diagnóstico',
  ],
)]
class DiagnosticSession extends ContentEntityBase implements DiagnosticSessionInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['wp_user_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('ID de usuario en WordPress'))
      ->setDescription(new TranslatableMarkup('Identidad en el sistema que autoriza el acceso. Se conserva para poder rastrear una sesión hasta su origen aunque la cuenta de Drupal cambie.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['course_id'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('ID del curso'))
      ->setDescription(new TranslatableMarkup('Curso que autorizó esta sesión. Se guarda por sesión para que cambiar el curso configurado no reescriba el historial.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64);

    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Estado'))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', DiagnosticStatus::allowedValues())
      ->setDefaultValue(DiagnosticStatus::Draft->value);

    $fields['agent'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Agente'))
      ->setDescription(new TranslatableMarkup('Agente con el que se hizo. Se guarda por sesión y no se deduce del curso: el curso que concede un agente puede cambiar, y el historial debe seguir diciendo con cuál se conversó de verdad.'))
      ->setSetting('max_length', 64);

    $fields['diagnostic_version'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Versión del diagnóstico'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['prompt_snapshot'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Prompt utilizado'))
      ->setDescription(new TranslatableMarkup('Copia literal del prompt en el momento de iniciar la sesión. Congelarlo es lo que hace que un resultado antiguo siga siendo reproducible aunque el prompt configurado cambie después.'));

    $fields['prompt_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Huella del prompt'))
      ->setDescription(new TranslatableMarkup('SHA-256 de la metodología congelada. Permite detectar de un vistazo si dos sesiones de la misma versión usaron contenidos distintos. NO incluye la memoria del alumno, que es distinta para cada persona: incluirla haría que no hubiera dos huellas iguales y esa comparación dejaría de servir.'))
      ->setSetting('max_length', 64);

    $fields['turn_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Turnos consumidos'))
      ->setSetting('unsigned', TRUE)
      ->setDefaultValue(0);

    $fields['started_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Inicio de la conversación'));

    $fields['completed_at'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Fin del diagnóstico'));

    $fields['is_sandbox'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Conversación de prueba'))
      ->setDescription(new TranslatableMarkup('Marca las conversaciones que el gestor crea al ajustar el prompt. Se excluyen del listado de resultados y no cuentan para el límite de diagnósticos por periodo: son ensayos, no diagnósticos de un alumno.'))
      ->setDefaultValue(FALSE)
      ->setSetting('on_label', new TranslatableMarkup('Prueba'))
      ->setSetting('off_label', new TranslatableMarkup('Real'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Creado'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Modificado'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   *
   * La entidad no define una clave de etiqueta porque no tiene un título
   * natural. Se genera uno legible para logs y listados administrativos.
   */
  public function label(): string {
    return (string) new TranslatableMarkup('Diagnóstico #@id', ['@id' => $this->id() ?? '—']);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): DiagnosticStatus {
    return DiagnosticStatus::from((string) $this->get('status')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(DiagnosticStatus $status): static {
    $this->set('status', $status->value);

    if ($status === DiagnosticStatus::InProgress && $this->getStartedAt() === NULL) {
      $this->set('started_at', \Drupal::time()->getRequestTime());
    }

    if ($status->isFinal() && $this->getCompletedAt() === NULL) {
      $this->set('completed_at', \Drupal::time()->getRequestTime());
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWordPressUserId(): string {
    return (string) $this->get('wp_user_id')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCourseId(): string {
    return (string) $this->get('course_id')->value;
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
  public function getPromptSnapshot(): string {
    return (string) $this->get('prompt_snapshot')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getPromptHash(): string {
    return (string) $this->get('prompt_hash')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTurnCount(): int {
    return (int) $this->get('turn_count')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function incrementTurnCount(): static {
    $this->set('turn_count', $this->getTurnCount() + 1);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStartedAt(): ?int {
    $value = $this->get('started_at')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCompletedAt(): ?int {
    $value = $this->get('completed_at')->value;
    return $value === NULL ? NULL : (int) $value;
  }

}
