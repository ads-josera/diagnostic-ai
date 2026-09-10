<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\Core\Form\FormInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Form\DiagnosticAgentForm;
use Drupal\sales_leadership_diagnostic\Form\HomePageForm;
use Drupal\sales_leadership_diagnostic\Form\KnowledgeDocumentsForm;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Los formularios que suben archivos sobreviven a ir y volver de la caché.
 *
 * No es una prueba teórica. Un `managed_file` sube por AJAX, y eso obliga a
 * Drupal a guardar el objeto del formulario serializado entre la construcción
 * y el envío. Al recuperarlo, **los servicios inyectados no vuelven solos**.
 *
 * El síntoma es de los peores que hay: la página carga bien, el archivo sube
 * bien, y el error fatal salta al pulsar «Guardar». Nada antes de ese momento
 * da una pista. Pasó dos veces —en la biblioteca de documentos, y otra vez el
 * 10-09-2026 al subir el icono de un agente— porque la primera vez se arregló
 * el formulario que fallaba y no se buscaron sus hermanos.
 *
 * Esta prueba es la búsqueda de hermanos, hecha de una vez: cualquier
 * formulario nuevo que suba archivos se añade aquí y deja de poder fallar así.
 */
#[CoversNothing]
final class FormSerializationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * Los formularios del módulo que suben archivos.
   *
   * @return array<string, array{class-string<\Drupal\Core\Form\FormInterface>}>
   *   Uno por entrada, con su clase.
   */
  public static function formulariosQueSubenArchivos(): array {
    return [
      'ficha del agente' => [DiagnosticAgentForm::class],
      'portada' => [HomePageForm::class],
      'biblioteca de documentos' => [KnowledgeDocumentsForm::class],
    ];
  }

  /**
   * Ningún servicio se queda por el camino.
   *
   * Se comprueba con reflexión y no llamando a un método concreto: lo que hay
   * que garantizar es que NINGUNA propiedad quede sin inicializar, no que una
   * en particular sobreviva. Una dependencia nueva que alguien olvide reponer
   * en `__wakeup()` cae aquí sola.
   *
   * @param class-string<\Drupal\Core\Form\FormInterface> $clase
   *   Formulario a comprobar.
   */
  #[DataProvider('formulariosQueSubenArchivos')]
  public function testElFormularioVuelveEnteroDeLaCache(string $clase): void {
    $formulario = $clase::create($this->container);
    $this->assertInstanceOf(FormInterface::class, $formulario);

    // Ir y volver: es exactamente lo que hace Drupal en cada vuelta de AJAX.
    //
    // Sin acotar las clases a proposito: acotarlas mediria otra cosa. Lo que
    // se comprueba es que ESTE objeto sobrevive al viaje que hace de verdad, y
    // lo que se deserializa es lo que acaba de serializar la linea de al lado.
    // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
    $recuperado = unserialize(serialize($formulario));

    $sinInicializar = [];

    foreach ((new \ReflectionObject($recuperado))->getProperties() as $propiedad) {
      if (!$propiedad->isStatic() && !$propiedad->isInitialized($recuperado)) {
        $sinInicializar[] = $propiedad->getName();
      }
    }

    $this->assertSame([], $sinInicializar, sprintf(
      'Tras volver de la caché, %s se quedó sin: %s. Al pulsar Guardar reventaría.',
      $clase,
      implode(', ', $sinInicializar),
    ));
  }

}
