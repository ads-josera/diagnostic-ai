<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;

/**
 * Pantalla que ve el alumno antes de escribir nada.
 *
 * Existe porque la conversación arrancaba en blanco: el alumno llegaba a un
 * campo que pedía «tu respuesta» sin que nadie le hubiera preguntado. El
 * agente no habla primero, así que la pantalla tiene que explicar de qué va
 * esto y ofrecer por dónde empezar.
 *
 * Lo administra el gestor, no quien mantiene la instalación: son textos de
 * negocio, y quien conoce la metodología es quien debe redactarlos.
 *
 * Cada pieza del contenido es opcional. Si el gestor la vacía, la pantalla se
 * reduce a lo que había antes en lugar de romperse, y las sugerencias vacías
 * se descartan una a una: una lista con huecos pintaría botones sin texto.
 */
final class ChatWelcome {

  /**
   * Nombre del objeto de configuración.
   */
  public const CONFIG_NAME = 'sales_leadership_diagnostic.diagnostic';

  /**
   * Número de sugerencias que se pueden configurar.
   *
   * Cuatro caben en una fila en pantalla ancha y en dos columnas en el móvil.
   * Con más, la pantalla de bienvenida empieza a parecer un menú y deja de
   * cumplir su función, que es quitar la parálisis del primer mensaje.
   */
  public const SUGGESTION_SLOTS = 4;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Texto introductorio, o NULL si el gestor lo dejó vacío.
   */
  public function getIntro(): ?string {
    $value = trim((string) $this->config()->get('welcome_intro'));

    return $value === '' ? NULL : $value;
  }

  /**
   * Sugerencias con texto, en orden.
   *
   * @return string[]
   *   Las sugerencias no vacías.
   */
  public function getSuggestions(): array {
    $stored = $this->config()->get('welcome_suggestions');

    if (!is_array($stored)) {
      return [];
    }

    $suggestions = array_map(
      static fn ($value): string => is_string($value) ? trim($value) : '',
      $stored,
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
  public function getIconUrl(): ?string {
    $fid = $this->config()->get('welcome_icon_fid');

    if (!is_numeric($fid) || (int) $fid <= 0) {
      return NULL;
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $fid);

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
  public function hasContent(): bool {
    return $this->getIntro() !== NULL
      || $this->getSuggestions() !== []
      || $this->getIconUrl() !== NULL;
  }

  /**
   * Etiquetas de cache de la configuración que consulta.
   *
   * @return string[]
   *   Etiquetas de cache.
   */
  public function getCacheTags(): array {
    return $this->config()->getCacheTags();
  }

  /**
   * Configuración del agente.
   */
  private function config() {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
