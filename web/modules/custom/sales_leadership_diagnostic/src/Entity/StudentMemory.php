<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\Entity\Handler\StudentMemoryAccessControlHandler;
use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\user\EntityOwnerTrait;

/**
 * Lo que el sistema recuerda del negocio de un alumno entre diagnósticos.
 *
 * Existe para que volver no sea empezar de cero: quien diagnosticó su
 * liderazgo comercial en marzo y compra un segundo curso en septiembre no
 * debería tener que volver a contar cuántos vendedores tiene ni a quién le
 * vende.
 *
 * Tres decisiones que conviene no deshacer sin motivo:
 *
 * La memoria es DEL ALUMNO, no del agente. La empresa y el mercado del alumno
 * son los mismos hable con quien hable, y hacerla por agente obligaría a
 * repetir la ficha entera en cada curso que compre, que es justo lo que esto
 * viene a evitar. Cada hecho guarda igualmente de qué agente salió, así que la
 * procedencia nunca se pierde.
 *
 * Hay como mucho UN hecho por tema y alumno (§ver MemoryTopic). Al extraer de
 * nuevo se reemplaza el contenido del tema, no se añade otro: sin ese límite
 * la memoria crece hasta no caber en el prompt, y el alumno deja de poder
 * revisarla, que es condición para poder corregirla.
 *
 * No lleva handler de Views, por el mismo motivo que el resultado (§43): su
 * contenido es información del negocio del alumno y no debe quedar navegable
 * desde la administración.
 */
#[ContentEntityType(
  id: 'sld_student_memory',
  label: new TranslatableMarkup('Memoria del alumno'),
  label_singular: new TranslatableMarkup('memoria del alumno'),
  label_plural: new TranslatableMarkup('memorias del alumno'),
  label_collection: new TranslatableMarkup('Memoria de los alumnos'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'uid' => 'uid',
    'owner' => 'uid',
  ],
  handlers: [
    'access' => StudentMemoryAccessControlHandler::class,
  ],
  base_table: 'sld_student_memory',
  admin_permission: 'administer sales leadership diagnostic',
  label_count: [
    'singular' => '@count memoria del alumno',
    'plural' => '@count memorias del alumno',
  ],
)]
class StudentMemory extends ContentEntityBase implements StudentMemoryInterface {

  use EntityOwnerTrait;
  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['topic'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Tema'))
      ->setDescription(new TranslatableMarkup('Uno de los temas de la lista cerrada. Junto con el alumno identifica el hecho: al recordar algo nuevo del mismo tema se reemplaza este.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32);

    $fields['content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Lo que se recuerda'))
      ->setDescription(new TranslatableMarkup('En prosa y en pocas frases. Se le muestra al alumno tal cual, así que tiene que poder leerlo y reconocerse en ello.'))
      ->setRequired(TRUE);

    $fields['source_agent'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Agente de origen'))
      ->setDescription(new TranslatableMarkup('De qué conversación salió. La memoria se comparte entre agentes, pero saber de cuál viene cada cosa es lo que permite explicarla y depurarla.'))
      ->setSetting('max_length', 64);

    $fields['source_session'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Sesión de origen'))
      ->setDescription(new TranslatableMarkup('Puede quedar vacía: las sesiones se purgan y la memoria les sobrevive a propósito.'))
      ->setSetting('target_type', 'sld_diagnostic_session');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Recordado por primera vez'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Actualizado'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getTopic(): ?MemoryTopic {
    return MemoryTopic::tryFromValue($this->get('topic')->value);
  }

  /**
   * {@inheritdoc}
   */
  public function getContent(): string {
    return (string) $this->get('content')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setContent(string $content): static {
    $this->set('content', $content);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceAgentId(): string {
    return (string) $this->get('source_agent')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSourceSessionId(): ?int {
    $value = $this->get('source_session')->target_id;

    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSource(string $agentId, ?int $sessionId): static {
    $this->set('source_agent', $agentId);
    $this->set('source_session', $sessionId);

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    $topic = $this->getTopic();

    // La etiqueta acaba en registros y en mensajes de administración, así que
    // no se pone aquí el contenido: es información del negocio del alumno.
    return (string) ($topic?->label() ?? $this->get('topic')->value);
  }

}
