<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Knowledge;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;

/**
 * Documentos de conocimiento que acompañan al prompt del agente.
 *
 * El prompt del cliente declara que la metodología autorizada vive en estos
 * archivos («Use the Salesbumm Knowledge files as the authoritative
 * methodology»), de modo que sin ellos el agente invoca un método que no puede
 * leer. Son parte del prompt, no un anexo opcional.
 *
 * QUÉ SE GUARDA DÓNDE, Y POR QUÉ
 *
 * La LISTA de documentos activos vive EN EL AGENTE, que es una entidad de
 * configuración: es una decisión estructural, se exporta y se despliega. Cada
 * agente tiene su propia biblioteca, porque cada uno tiene su metodología.
 *
 * El TEXTO extraído vive en el estado, no en configuración. Son cientos de
 * miles de caracteres de material propietario del cliente: meterlo en
 * configuración lo volcaría a Git en cada exportación, engordaría el
 * despliegue y lo repartiría por entornos que no tienen por qué tenerlo.
 * Además es dato derivado —se puede regenerar del archivo— y la configuración
 * es para decisiones, no para caché.
 */
final class KnowledgeLibrary {

  /**
   * Prefijo de las entradas de estado con el texto extraído.
   */
  private const STATE_PREFIX = 'sales_leadership_diagnostic.knowledge.';

  /**
   * Aviso cuando la biblioteca se acerca a un tamaño que encarece cada turno.
   *
   * No es un límite técnico: el contexto admite mucho más. Es el punto a
   * partir del cual conviene que alguien decida si todos los documentos hacen
   * falta, porque el prompt viaja al proveedor en CADA mensaje.
   */
  public const TOKENS_AVISO = 60000;

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DocumentTextExtractor $extractor,
  ) {}

  /**
   * Texto de todos los documentos, listo para anteponerse al prompt.
   *
   * Cada documento va rotulado con su nombre y delimitado. Sin delimitar, dos
   * metodologías seguidas se leen como una sola y el modelo mezcla reglas de
   * ambas; con el rótulo, además, puede citar de cuál procede lo que afirma.
   *
   * Devuelve cadena vacía si no hay documentos legibles, para no anteponer un
   * encabezado que no encabeza nada.
   */
  public function compose(DiagnosticAgentInterface $agent): string {
    $bloques = [];

    foreach ($agent->getKnowledgeFids() as $fid) {
      $guardado = $this->getStored($fid);

      if ($guardado === NULL || $guardado['texto'] === '') {
        continue;
      }

      $bloques[] = sprintf(
        "### DOCUMENTO: %s\n\n%s",
        $guardado['nombre'],
        $guardado['texto'],
      );
    }

    if ($bloques === []) {
      return '';
    }

    return "## KNOWLEDGE DOCUMENTS\n\n"
      . "Estos documentos son la metodología autorizada. Cuando algo de aquí\n"
      . "contradiga tu criterio general, manda el documento.\n\n"
      . implode("\n\n---\n\n", $bloques);
  }

  /**
   * Ficha de cada documento activo, para la pantalla de administración.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por documento, con fid, nombre, tamaño, tokens y estado.
   */
  public function getDocuments(DiagnosticAgentInterface $agent): array {
    $fichas = [];

    foreach ($agent->getKnowledgeFids() as $fid) {
      $guardado = $this->getStored($fid);

      if ($guardado === NULL) {
        // El archivo se borró desde la gestión de archivos sin pasar por
        // aquí. Se muestra igualmente para que el gestor pueda quitarlo de la
        // lista en lugar de encontrarse una fila fantasma.
        $fichas[] = [
          'fid' => $fid,
          'nombre' => (string) $fid,
          'bytes' => 0,
          'tokens' => 0,
          'correcto' => FALSE,
          'motivo' => 'El archivo ya no existe.',
        ];
        continue;
      }

      $fichas[] = [
        'fid' => $fid,
        'nombre' => $guardado['nombre'],
        'bytes' => $guardado['bytes'],
        'tokens' => $this->estimateTokens($guardado['texto']),
        'correcto' => $guardado['correcto'],
        'motivo' => $guardado['motivo'],
      ];
    }

    return $fichas;
  }

  /**
   * Suma de tokens estimados de la biblioteca completa.
   */
  public function getTotalTokens(DiagnosticAgentInterface $agent): int {
    return array_sum(array_column($this->getDocuments($agent), 'tokens'));
  }

  /**
   * Lee un archivo y guarda su texto.
   *
   * @param \Drupal\file\FileInterface $file
   *   Archivo recién subido.
   *
   * @return \Drupal\sales_leadership_diagnostic\Service\Knowledge\ExtractionResult
   *   Resultado de la lectura, para avisar en el acto si vino vacío.
   */
  public function remember(FileInterface $file): ExtractionResult {
    $resultado = $this->extractor->extract($file);

    $this->state->set(self::STATE_PREFIX . (int) $file->id(), [
      'nombre' => (string) $file->getFilename(),
      'bytes' => (int) $file->getSize(),
      'texto' => $resultado->texto,
      'correcto' => $resultado->correcto,
      'motivo' => $resultado->motivo,
    ]);

    return $resultado;
  }

  /**
   * Olvida el texto de un documento que ya no está en la lista.
   */
  public function forget(int $fid): void {
    $this->state->delete(self::STATE_PREFIX . $fid);
  }

  /**
   * Estimación de tokens de un texto.
   *
   * Es una aproximación deliberada, no una cuenta exacta: tokenizar de verdad
   * exigiría la librería del proveedor y el número solo sirve para que el
   * gestor calibre el tamaño de la biblioteca. Se usa la relación habitual de
   * ~1,4 tokens por palabra en textos de español e inglés mezclados.
   */
  public function estimateTokens(string $texto): int {
    if (trim($texto) === '') {
      return 0;
    }

    return (int) round(str_word_count($texto, 0) * 1.4);
  }

  /**
   * Texto y metadatos guardados de un documento, o NULL si no hay nada.
   *
   * @return array<string, mixed>|null
   *   Datos guardados, o NULL.
   */
  private function getStored(int $fid): ?array {
    $guardado = $this->state->get(self::STATE_PREFIX . $fid);

    if (is_array($guardado) && isset($guardado['texto'])) {
      return $guardado;
    }

    // No hay texto guardado: puede ser un despliegue nuevo, donde la lista
    // viaja en configuración y el estado no. Se reconstruye desde el archivo.
    $file = $this->entityTypeManager->getStorage('file')->load($fid);

    if (!$file instanceof FileInterface) {
      return NULL;
    }

    $this->remember($file);

    $guardado = $this->state->get(self::STATE_PREFIX . $fid);

    return is_array($guardado) ? $guardado : NULL;
  }

}
