<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\sales_leadership_diagnostic\Controller\ResultsController;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Cada informe se lee con su propio vocabulario.
 *
 * Hasta el 12-09-2026 la pantalla ponía las mismas etiquetas a todo: las fugas
 * comerciales del diagnóstico salían como «Oportunidades de mejora», y la
 * confianza como «MEDIUM» en mitad de un texto en español. Lo vio José Raúl
 * al revisar el informe de una organización madura.
 */
#[CoversClass(ResultsController::class)]
final class ResultVocabularyTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    // Para reproducir el choque con las traducciones del sitio.
    'language',
    'locale',
    'sales_leadership_diagnostic',
  ];

  /**
   * Dueño de los resultados.
   */
  private User $alumno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installConfig(['system', 'sales_leadership_diagnostic']);
    $this->container->get('router.builder')->rebuild();

    // El uid 1 es superusuario y no debe usarse como sujeto de prueba.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumno = User::create(['name' => 'alumna', 'status' => 1]);
    $this->alumno->save();
    $this->container->get('current_user')->setAccount($this->alumno);
  }

  /**
   * El diagnóstico usa las palabras de su informe final.
   */
  public function testElDiagnosticoUsaElVocabularioDeSuInforme(): void {
    $vista = $this->ver(84, [
      'dimensions' => [
        ['name' => 'Estrategia', 'score' => 8.5, 'max' => 10, 'level' => 'PREDICTIVE', 'confidence' => 'HIGH'],
      ],
      'confidence' => 'MEDIUM',
      'opportunities' => ['Prospección concentrada en tres vendedores.'],
      'recommendations' => ['Decidir si la dependencia es de capacidad.'],
      'priority_actions' => ['Lectura de productividad outbound.'],
    ]);

    $this->assertSame(
      ['Principales fugas comerciales', 'Prioridades', 'Primeros 30 días'],
      $this->etiquetas($vista),
    );
    $this->assertSame('Lectura ejecutiva', (string) $vista['#summary_label']);
    $this->assertSame('Media', $vista['#confidence']);
    $this->assertSame('Alta', $vista['#dimensions'][0]['confidence']);
    $this->assertFalse($vista['#partial'], 'Con Score global no es parcial.');
  }

  /**
   * El parcial conserva su confianza y se declara parcial.
   *
   * No tiene bloque de Score, que era donde vivía la confianza: sin esto
   * desaparecía de la pantalla.
   */
  public function testElParcialSeDeclaraConSuConfianza(): void {
    $vista = $this->ver(NULL, [
      'dimensions' => [
        ['name' => 'Forecast', 'score' => 5, 'max' => 10, 'level' => 'DEVELOPING', 'confidence' => 'MEDIA'],
      ],
      'confidence' => 'LOW',
      'opportunities' => ['Commit sin evidencia del cliente.'],
    ]);

    $this->assertTrue($vista['#partial']);
    $this->assertSame('Baja', $vista['#confidence']);
    $this->assertSame(['Principales fugas comerciales'], $this->etiquetas($vista));
  }

  /**
   * El Pack usa las palabras de su contrato, no las del diagnóstico.
   */
  public function testElPackUsaLasSuyas(): void {
    $vista = $this->ver(NULL, [
      'pool_declared' => 1,
      'accounts' => [['name' => 'Traxión', 'disposition' => 'GOLD']],
      'opportunities' => ['Traxión: expansión de flota.'],
      'missing_evidence' => ['Ownership sin verificar.'],
    ]);

    $this->assertSame(['Cuentas GOLD liberadas', 'Comprobaciones pendientes'], $this->etiquetas($vista));
    $this->assertSame('La misión y su cobertura', (string) $vista['#summary_label']);
    $this->assertFalse($vista['#partial'], 'Un Pack no es un diagnóstico parcial.');
  }

  /**
   * Lo que no encaja en ninguno conserva las etiquetas de siempre.
   *
   * Un resultado antiguo, anterior a las dimensiones y al Pack, tiene que
   * seguir leyéndose igual que el día que se generó.
   */
  public function testLoAntiguoConservaLasDeSiempre(): void {
    $vista = $this->ver(NULL, ['opportunities' => ['Algo que mejorar.']]);

    $this->assertSame(['Oportunidades de mejora'], $this->etiquetas($vista));
    $this->assertSame('Resumen', (string) $vista['#summary_label']);
  }

  /**
   * «Media» no se convierte en «Multimedia» en un sitio en español.
   *
   * Nuestras cadenas ya están en español y Drupal busca su traducción al
   * español como si fueran inglés. «Media» existe en el núcleo con traducción
   * «Multimedia», y la pantalla decía «Confianza: Multimedia». Las demás
   * pruebas no lo ven porque corren sin traducciones; esta monta el mismo
   * choque que tiene el sitio.
   */
  public function testMediaNoSeTraduceComoMultimedia(): void {
    $this->installSchema('locale', ['locales_source', 'locales_target', 'locales_location', 'locale_file']);
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('es')->save();
    $this->config('system.site')->set('default_langcode', 'es')->save();
    $this->container->get('language_manager')->reset();

    // La misma traducción que trae el núcleo, sin contexto.
    $almacen = $this->container->get('locale.storage');
    $fuente = $almacen->createString(['source' => 'Media', 'context' => ''])->save();
    $almacen->createTranslation(['lid' => $fuente->getId(), 'language' => 'es', 'translation' => 'Multimedia'])->save();

    // El traductor fija su idioma al construirse: cambiar el del sitio después
    // no le llega. Se le dice directamente, como lo tiene el sitio real.
    $traductor = $this->container->get('string_translation');
    $traductor->setDefaultLangcode('es');
    $traductor->reset();

    // Comprobación de la propia prueba: el choque está montado. Sin esto, un
    // idioma mal configurado haría pasar la prueba sin demostrar nada.
    $this->assertSame('Multimedia', (string) $this->container->get('string_translation')->translate('Media'));

    $vista = $this->ver(84, [
      'confidence' => 'MEDIUM',
      'dimensions' => [
        ['name' => 'Forecast', 'score' => 9, 'max' => 10, 'level' => 'PREDICTIVE', 'confidence' => 'MEDIUM'],
      ],
    ]);

    $this->assertSame('Media', $vista['#confidence']);
    $this->assertSame('Media', $vista['#dimensions'][0]['confidence']);
  }

  /**
   * Una confianza que no es de los tres niveles se deja tal cual.
   *
   * Mejor un valor raro a la vista que uno inventado.
   */
  public function testUnaConfianzaDesconocidaNoSeInventa(): void {
    $vista = $this->ver(50, ['confidence' => 'PROVISIONAL', 'dimensions' => []]);

    $this->assertSame('PROVISIONAL', $vista['#confidence']);
  }

  /**
   * El render array de un resultado con esa puntuación y ese contenido.
   *
   * @param int|null $score
   *   Puntuación global.
   * @param array<string, mixed> $payload
   *   Contenido del resultado.
   *
   * @return array<string, mixed>
   *   Lo que devuelve el controlador.
   */
  private function ver(?int $score, array $payload): array {
    $resultado = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->create([
        'uid' => $this->alumno->id(),
        'session_id' => 1,
        'agent' => '',
        'diagnostic_version' => '1.0',
        'summary' => 'Resumen.',
        'score' => $score,
      ]);
    assert($resultado instanceof DiagnosticResultInterface);
    $resultado->setPayload($payload);
    $resultado->save();

    return ResultsController::create($this->container)->view($resultado);
  }

  /**
   * Las etiquetas de las secciones, como texto.
   *
   * @param array<string, mixed> $vista
   *   Lo que devuelve el controlador.
   *
   * @return string[]
   *   Una por sección, en orden.
   */
  private function etiquetas(array $vista): array {
    return array_map(static fn (array $s): string => (string) $s['label'], $vista['#sections']);
  }

}
