<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;

/**
 * Pantalla que ve el alumno antes de escribir nada.
 *
 * Existe porque la conversación arrancaba en blanco: el alumno llegaba a un
 * campo que pedía «tu respuesta» sin que nadie le hubiera preguntado. El
 * agente no habla primero, así que la pantalla tiene que explicar de qué va
 * esto y ofrecer por dónde empezar.
 *
 * **La bienvenida es DEL AGENTE**, y desde el 26-08-2026 se lee de él. Antes
 * salía de un objeto de configuración único, que era correcto cuando solo
 * había un agente y dejó de serlo en cuanto hubo varios: quien comprara el
 * curso de prospección se habría encontrado la bienvenida del diagnóstico de
 * liderazgo, presentándole algo que no era lo que había comprado. Cada agente
 * se presenta a sí mismo.
 *
 * Cada pieza del contenido es opcional. Si el gestor la vacía, la pantalla se
 * reduce a lo que había antes en lugar de romperse, y las sugerencias vacías
 * se descartan una a una: una lista con huecos pintaría botones sin texto.
 */
final class ChatWelcome {

  /**
   * Número de sugerencias que se recomienda configurar.
   *
   * Cuatro caben en una fila en pantalla ancha y en dos columnas en el móvil.
   * Con más, la pantalla de bienvenida empieza a parecer un menú y deja de
   * cumplir su función, que es quitar la parálisis del primer mensaje. Ya no
   * es un tope duro —el formulario del agente las admite una por línea—, sino
   * la guía que se le da al gestor.
   */
  public const SUGGESTION_SLOTS = 4;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Texto introductorio, o NULL si el gestor lo dejó vacío.
   */
  public function getIntro(?DiagnosticAgentInterface $agent): ?string {
    $value = $agent === NULL ? '' : trim($agent->getWelcomeIntro());

    return $value === '' ? NULL : $value;
  }

  /**
   * Sugerencias con texto, en orden.
   *
   * @return string[]
   *   Las sugerencias no vacías.
   */
  public function getSuggestions(?DiagnosticAgentInterface $agent): array {
    if ($agent === NULL) {
      return [];
    }

    $suggestions = array_map(
      static fn ($value): string => is_string($value) ? trim($value) : '',
      $agent->getWelcomeSuggestions(),
    );

    // array_values() reindexa: sin él, un hueco en medio dejaría un array con
    // claves salteadas y Twig lo recorrería igual, pero cualquier código que
    // asumiera índices consecutivos se llevaría una sorpresa.
    return array_values(array_filter(
      $suggestions,
      static fn (string $value): bool => $value !== '',
    ));
  }

  /**
   * URL del icono del agente, o NULL si no hay ninguno.
   */
  public function getIconUrl(?DiagnosticAgentInterface $agent): ?string {
    $fid = $agent === NULL ? 0 : $agent->getWelcomeIconFid();

    if ($fid <= 0) {
      return NULL;
    }

    $file = $this->entityTypeManager->getStorage('file')->load($fid);

    // El archivo pudo borrarse desde la administración sin pasar por el
    // formulario. Un icono que ya no existe no debe dejar una imagen rota en
    // la primera pantalla que ve el alumno.
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

  /**
   * Indica si hay algo que mostrar.
   *
   * Sin esto habría que comprobar las tres cosas en la plantilla, y una
   * pantalla de bienvenida completamente vacía ocuparía sitio sin decir nada.
   */
  public function hasContent(?DiagnosticAgentInterface $agent): bool {
    return $this->getIntro($agent) !== NULL
      || $this->getSuggestions($agent) !== []
      || $this->getIconUrl($agent) !== NULL;
  }

  /**
   * Etiquetas de cache de las que depende la bienvenida.
   *
   * Se marca el agente concreto cuando se conoce, y la lista cuando no: así,
   * editar la bienvenida de un agente no invalida las páginas de los demás.
   *
   * @return string[]
   *   Etiquetas de cache.
   */
  public function getCacheTags(?DiagnosticAgentInterface $agent): array {
    if ($agent === NULL) {
      return $this->entityTypeManager->getDefinition('sld_agent')->getListCacheTags();
    }

    return $agent->getCacheTags();
  }

}
