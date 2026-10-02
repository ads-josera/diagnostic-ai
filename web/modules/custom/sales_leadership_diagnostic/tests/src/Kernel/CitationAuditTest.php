<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Research\CitationAudit;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Una fuente citada tiene que haber salido de una búsqueda de esa misión.
 *
 * El 02-10-2026 un Weekly GOLD Pack citó catorce fuentes con su URL —cinco de
 * ellas identificando a un ejecutivo por nombre y cargo— y no había NADA con
 * que comprobar que salieran de las trece búsquedas que se hicieron. El
 * registro guardaba la consulta y los recuentos; los enlaces, no.
 *
 * Es el fallo más caro posible de este producto porque no se ve: una URL
 * inventada no parece un error, parece un hecho.
 */
#[CoversClass(CitationAudit::class)]
final class CitationAuditTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('sales_leadership_diagnostic', ['sld_tool_call']);
    $this->installConfig(['sales_leadership_diagnostic']);
  }

  /**
   * Misión de las pruebas.
   */
  private const SESION = 30;

  /**
   * Lo que salió de una búsqueda queda respaldado.
   */
  public function testUnaCitaQueSalioDeUnaBusquedaQuedaRespaldada(): void {
    $this->busqueda(['https://primicias.ec/economia/mabe-planta-guayaquil']);

    $revision = $this->revisar(['https://primicias.ec/economia/mabe-planta-guayaquil']);

    $this->assertSame(
      ['https://primicias.ec/economia/mabe-planta-guayaquil'],
      $revision[CitationAudit::RESPALDADAS],
    );
    $this->assertSame([], $revision[CitationAudit::NO_VISTAS]);
  }

  /**
   * Una ruta inventada dentro de un dominio real NO pasa por buena.
   *
   * Es la invención más probable, y la razón por la que la comparación se hace
   * con la dirección completa y no con el dominio: normalizar hasta el dominio
   * dejaría pasar exactamente esto, que es el caso peligroso.
   */
  public function testUnaRutaInventadaDentroDeUnDominioRealSeSepara(): void {
    $this->busqueda(['https://primicias.ec/economia/mabe-planta-guayaquil']);

    $revision = $this->revisar(['https://primicias.ec/economia/entrevista-que-nunca-existio']);

    $this->assertSame([], $revision[CitationAudit::RESPALDADAS]);
    $this->assertSame(
      ['https://primicias.ec/economia/entrevista-que-nunca-existio'],
      $revision[CitationAudit::OTRA_PAGINA],
    );
    $this->assertSame([], $revision[CitationAudit::NO_VISTAS]);
  }

  /**
   * Un dominio que no se visitó nunca queda como no visto.
   */
  public function testUnDominioQueNoSeVisitoNuncaQuedaComoNoVisto(): void {
    $this->busqueda(['https://primicias.ec/economia/mabe-planta-guayaquil']);

    $revision = $this->revisar(['https://ejemplo-inventado.com/nota']);

    $this->assertSame(
      ['https://ejemplo-inventado.com/nota'],
      $revision[CitationAudit::NO_VISTAS],
    );
  }

  /**
   * Lo que no cambia la página no cuenta como diferencia.
   *
   * El «www.», el esquema y la barra final son la misma página escrita de otra
   * forma. Si contaran, la revisión avisaría de citas inventadas que no lo son
   * y en dos semanas nadie volvería a mirar el aviso.
   */
  public function testLasDiferenciasQueNoCambianLaPaginaNoCuentan(): void {
    $this->busqueda(['http://www.primicias.ec/economia/mabe/']);

    $revision = $this->revisar(['https://primicias.ec/economia/mabe']);

    $this->assertCount(1, $revision[CitationAudit::RESPALDADAS]);
    $this->assertSame([], $revision[CitationAudit::NO_VISTAS]);
    $this->assertSame([], $revision[CitationAudit::OTRA_PAGINA]);
  }

  /**
   * Una misión sin URL guardadas se declara así, no se acusa.
   *
   * Es el caso de todo lo anterior al 02-10-2026: no se guardaron y no se
   * pueden reconstruir. Devolver «catorce citas no vistas» ahí sería una
   * acusación falsa, y es la clase de cifra que luego alguien repite.
   */
  public function testUnaMisionSinUrlGuardadasSeDeclaraSinRegistro(): void {
    $revision = $this->revisar(['https://primicias.ec/economia/mabe']);

    $this->assertTrue($revision[CitationAudit::SIN_REGISTRO]);
    $this->assertSame([], $revision[CitationAudit::NO_VISTAS]);
    $this->assertSame([], $revision[CitationAudit::RESPALDADAS]);
  }

  /**
   * Un entregable sin ninguna cita no es una misión sin registro.
   *
   * Son dos cosas distintas: aquí no hay nada que comprobar, y allí hay algo
   * que comprobar y no se puede.
   */
  public function testUnEntregableSinCitasNoSeDeclaraSinRegistro(): void {
    $revision = $this->revisar([]);

    $this->assertFalse($revision[CitationAudit::SIN_REGISTRO]);
  }

  /**
   * Una búsqueda denegada no respalda nada.
   *
   * No trajo URL, así que no puede prestar respaldo a una cita. Contarla
   * convertiría una denegación en coartada.
   */
  public function testUnaBusquedaDenegadaNoRespaldaNada(): void {
    $this->busqueda(['https://primicias.ec/nota'], permitida: FALSE);

    $revision = $this->revisar(['https://primicias.ec/nota']);

    $this->assertTrue($revision[CitationAudit::SIN_REGISTRO]);
  }

  /**
   * Revisa un entregable que cita estas URL.
   */
  private function revisar(array $urls): array {
    $cuentas = [];

    foreach ($urls as $i => $url) {
      $cuentas[] = [
        'name' => 'Cuenta ' . $i,
        'sources' => [['url' => $url, 'label' => 'señal', 'published' => '']],
      ];
    }

    return $this->container->get(CitationAudit::class)
      ->review(self::SESION, ['accounts' => $cuentas]);
  }

  /**
   * Anota una búsqueda que devolvió estas URL.
   */
  private function busqueda(array $urls, bool $permitida = TRUE): void {
    $this->container->get(ToolCallRepository::class)->record(
      uid: 14,
      sessionId: self::SESION,
      tool: WebSearchTool::NAME,
      query: 'consulta',
      allowed: $permitida,
      results: count($urls),
      resultUrls: $urls,
    );
  }

}
