<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Knowledge;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\FileInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Smalot\PdfParser\Parser as PdfParser;
use Drupal\sales_leadership_diagnostic\Service\Security\ExceptionRedactor;

/**
 * Extrae el texto plano de un documento de conocimiento.
 *
 * El texto se extrae UNA vez, al subir el archivo, y se guarda. No se vuelve a
 * extraer en cada conversación por dos motivos: abrir y analizar nueve PDF en
 * cada turno costaría segundos de espera al alumno, y un archivo que se
 * corrompiera después dejaría de responder el diagnóstico en lugar de fallar
 * en la pantalla del gestor, que es quien puede arreglarlo.
 *
 * Ninguna extracción lanza excepción hacia arriba. Un documento ilegible es un
 * problema del documento, no del sistema: se devuelve el motivo para poder
 * enseñarlo en la administración, y el resto de la biblioteca sigue sirviendo.
 */
final class DocumentTextExtractor {

  use StringTranslationTrait;

  /**
   * Extensiones que se saben leer.
   *
   * DOC (el binario antiguo de Word) queda fuera a propósito: su formato no es
   * XML y leerlo exigiría otra dependencia para un caso que hoy nadie usa.
   *
   * @var string[]
   */
  public const EXTENSIONS = ['pdf', 'docx', 'txt', 'md'];

  /**
   * Canal de registro del módulo.
   */
  private readonly LoggerChannelInterface $logger;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Extrae el texto de un archivo.
   *
   * @param \Drupal\file\FileInterface $file
   *   Archivo ya guardado.
   *
   * @return \Drupal\sales_leadership_diagnostic\Service\Knowledge\ExtractionResult
   *   El texto, o el motivo por el que no se pudo leer.
   */
  public function extract(FileInterface $file): ExtractionResult {
    $ruta = $this->fileSystem->realpath($file->getFileUri());

    if ($ruta === FALSE || !is_readable($ruta)) {
      return ExtractionResult::fallo(
        (string) $this->t('No se pudo abrir el archivo en el disco.'),
      );
    }

    $extension = strtolower(pathinfo((string) $file->getFilename(), PATHINFO_EXTENSION));

    try {
      $texto = match ($extension) {
        'pdf' => $this->desdePdf($ruta),
        'docx' => $this->desdeDocx($ruta),
        'txt', 'md' => (string) file_get_contents($ruta),
        default => NULL,
      };
    }
    catch (\Throwable $e) {
      // Se registra con el nombre del archivo, nunca con su contenido: un
      // documento de conocimiento es material propietario del cliente.
      $this->logger->error(
        'No se pudo extraer el texto de @archivo: @motivo',
        ['@archivo' => $file->getFilename(), '@motivo' => ExceptionRedactor::redact($e)],
      );

      return ExtractionResult::fallo(
        (string) $this->t('El archivo no se pudo leer. Puede estar dañado o protegido con contraseña.'),
      );
    }

    if ($texto === NULL) {
      return ExtractionResult::fallo(
        (string) $this->t('Formato no admitido: @ext.', ['@ext' => $extension]),
      );
    }

    $texto = $this->normalizar($texto);

    if ($texto === '') {
      // Caso real y frecuente: un PDF que son imágenes escaneadas. El archivo
      // se abre sin error y no contiene una sola letra. Sin este aviso, el
      // gestor lo daría por cargado y el agente no vería nada.
      return ExtractionResult::fallo(
        (string) $this->t('El archivo se abrió pero no contiene texto. Si es un PDF escaneado, hace falta una versión con texto seleccionable.'),
      );
    }

    return ExtractionResult::exito($texto);
  }

  /**
   * Texto de un PDF.
   */
  private function desdePdf(string $ruta): string {
    return (new PdfParser())->parseFile($ruta)->getText();
  }

  /**
   * Texto de un DOCX.
   *
   * Un DOCX es un ZIP con el documento en XML. Se lee directamente en lugar de
   * añadir una librería de ofimática completa: aquí solo hace falta el texto,
   * y PhpWord traería un árbol de dependencias grande para no usarlo.
   */
  private function desdeDocx(string $ruta): ?string {
    $zip = new \ZipArchive();

    if ($zip->open($ruta) !== TRUE) {
      return NULL;
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === FALSE) {
      return NULL;
    }

    // Los finales de párrafo se conservan como saltos de línea; sin esto el
    // documento entero llegaría al modelo como un solo párrafo ilegible.
    $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
    $xml = preg_replace('#<w:br[^>]*/?>#', "\n", $xml) ?? $xml;

    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }

  /**
   * Deja el texto en una forma estable y sin ruido.
   */
  private function normalizar(string $texto): string {
    // Los PDF traen a menudo espacios finos y no separables que el modelo no
    // necesita y que engordan el recuento de tokens.
    $texto = str_replace(["\xC2\xA0", "\xE2\x80\x89"], ' ', $texto);
    $texto = preg_replace('/\R/u', "\n", $texto) ?? $texto;
    $texto = preg_replace('/[ \t]+/u', ' ', $texto) ?? $texto;
    $texto = preg_replace('/\n{3,}/u', "\n\n", $texto) ?? $texto;

    return trim($texto);
  }

}
