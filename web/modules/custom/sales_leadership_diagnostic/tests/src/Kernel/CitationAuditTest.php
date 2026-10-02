<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Evidence\EvidenceLedger;
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
    $this->installSchema('sales_leadership_diagnostic', ['sld_tool_call', 'sld_evidence']);
    $this->installConfig(['sales_leadership_diagnostic']);
  }

  /**
   * Misión de las pruebas.
   */
  private const SESION = 30;

  /**
   * Alumno de las pruebas.
   */
  private const ALUMNO = 14;

  /**
   * Una conversación anterior del mismo alumno.
   */
  private const SESION_ANTERIOR = 29;

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
   * Si TODAS las búsquedas se denegaron, una cita no tiene de dónde salir.
   *
   * Es el caso grave, y la primera versión de esta clase lo dejaba en silencio:
   * trataba «no hay URL con que comparar» igual que «esta misión es anterior a
   * que se guardaran», o sea sin decir una palabra. Una denegación no puede
   * servir de coartada.
   */
  public function testSiTodasLasBusquedasSeDenegaronLaCitaNoTieneOrigen(): void {
    $this->busqueda(['https://primicias.ec/nota'], permitida: FALSE);

    $revision = $this->revisar(['https://primicias.ec/nota']);

    $this->assertFalse($revision[CitationAudit::SIN_REGISTRO]);
    $this->assertSame(
      ['https://primicias.ec/nota'],
      $revision[CitationAudit::NO_VISTAS],
    );
  }

  /**
   * Sin ninguna búsqueda, lo citado tampoco tiene de dónde salir.
   */
  public function testSinNingunaBusquedaLoCitadoNoTieneOrigen(): void {
    $revision = $this->revisar(['https://primicias.ec/nota']);

    $this->assertFalse($revision[CitationAudit::SIN_REGISTRO]);
    $this->assertSame(
      ['https://primicias.ec/nota'],
      $revision[CitationAudit::NO_VISTAS],
    );
  }

  /**
   * Una misión que buscó pero no guardó sus URL sí es «sin registro».
   *
   * Es el caso de todo lo anterior al 02-10-2026, y el único en que no se
   * puede concluir nada. Se distingue del anterior por lo único que los
   * distingue de verdad: que HUBO búsquedas concedidas.
   */
  public function testUnaMisionQueBuscoSinGuardarUrlEsSinRegistro(): void {
    $this->busqueda([]);

    $revision = $this->revisar(['https://primicias.ec/nota']);

    $this->assertTrue($revision[CitationAudit::SIN_REGISTRO]);
    $this->assertSame([], $revision[CitationAudit::NO_VISTAS]);
  }

  /**
   * Una evidencia que el agente ya tenía anotada NO se denuncia como invento.
   *
   * Reutilizar evidencia de semanas anteriores es justo para lo que el ledger
   * existe, y su URL no sale de ninguna búsqueda de ESTA misión. Sin esto, la
   * revisión avisaría de invención cada vez que el sistema hace lo que debe, y
   * en dos semanas nadie volvería a mirar el aviso.
   *
   * Va en su propia categoría y no como respaldada, porque esa fila la escribió
   * el propio agente: dice que ya declaró la fuente antes, no que exista.
   */
  public function testUnaEvidenciaYaAnotadaNoSeDenunciaComoInvento(): void {
    $this->busqueda(['https://otra-cosa.com/x']);
    $this->evidencia('https://expreso.ec/economia/tia-402-locales', self::SESION_ANTERIOR);

    $revision = $this->revisar(['https://expreso.ec/economia/tia-402-locales']);

    $this->assertSame(
      ['https://expreso.ec/economia/tia-402-locales'],
      $revision[CitationAudit::DECLARADAS],
    );
    $this->assertSame([], $revision[CitationAudit::NO_VISTAS]);
    $this->assertSame([], $revision[CitationAudit::RESPALDADAS]);
  }

  /**
   * Lo que el agente anota en ESTA conversación no se respalda a sí mismo.
   *
   * Es el caso real del 02-10-2026: la búsqueda devolvió una URL con «10-anos»,
   * el agente escribió «diez-anos», anotó esa URL rota en el ledger y la citó.
   * Como «declarada», pasó en silencio, y daba 404. Se contrasta contra lo que
   * la búsqueda trajo, y sale como lo que es: otra página del mismo sitio.
   */
  public function testLoAnotadoEnEstaConversacionNoRespaldaSuPropiaCita(): void {
    $this->busqueda(['https://primicias.ec/banco-pichincha-regresa-tras-10-anos']);
    $this->evidencia('https://primicias.ec/banco-pichincha-regresa-tras-diez-anos', self::SESION);

    $revision = $this->revisar(['https://primicias.ec/banco-pichincha-regresa-tras-diez-anos']);

    $this->assertSame([], $revision[CitationAudit::DECLARADAS]);
    $this->assertSame(
      ['https://primicias.ec/banco-pichincha-regresa-tras-diez-anos'],
      $revision[CitationAudit::OTRA_PAGINA],
    );
  }

  /**
   * Un enlace que solo está en el texto del Pack también se audita.
   *
   * Desde el 02-10-2026 el contrato le pide al agente los enlaces en el
   * `message`, que es lo único que la persona lee. Mirar solo las `sources`
   * dejaría sin revisar exactamente lo que se le acababa de pedir poner en el
   * otro sitio.
   */
  public function testUnEnlaceQueSoloEstaEnElTextoTambienSeAudita(): void {
    $this->busqueda(['https://primicias.ec/real']);

    $revision = $this->container->get(CitationAudit::class)->review(
      self::ALUMNO,
      self::SESION,
      ['accounts' => []],
      'Mabe abre planta ([Inventado](https://no-se-busco-nunca.com/nota)).',
    );

    $this->assertSame(
      ['https://no-se-busco-nunca.com/nota'],
      $revision[CitationAudit::NO_VISTAS],
    );
  }

  /**
   * Un punto final de la frase no es parte de la dirección.
   */
  public function testElPuntoFinalDeLaFraseNoEsParteDeLaDireccion(): void {
    $this->busqueda(['https://primicias.ec/nota']);

    $revision = $this->container->get(CitationAudit::class)->review(
      self::ALUMNO,
      self::SESION,
      ['accounts' => []],
      'La fuente es https://primicias.ec/nota.',
    );

    $this->assertSame(
      ['https://primicias.ec/nota'],
      $revision[CitationAudit::RESPALDADAS],
    );
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
      ->review(self::ALUMNO, self::SESION, ['accounts' => $cuentas]);
  }

  /**
   * Anota una evidencia del alumno con esta procedencia.
   */
  private function evidencia(string $fuente, int $sesion): void {
    $this->container->get(EvidenceLedger::class)->record(
      self::ALUMNO,
      'mision-' . $sesion,
      $sesion,
      [
        'scope' => 'Almacenes Tía',
        'claim' => 'Opera 402 locales.',
        'summary' => 'Dato de su expansión.',
        'source' => $fuente,
        'evidence_type' => 'HECHO',
        'confidence' => 'ALTA',
      ],
    );
  }

  /**
   * Anota una búsqueda que devolvió estas URL.
   */
  private function busqueda(array $urls, bool $permitida = TRUE): void {
    $this->container->get(ToolCallRepository::class)->record(
      uid: self::ALUMNO,
      sessionId: self::SESION,
      tool: WebSearchTool::NAME,
      query: 'consulta',
      allowed: $permitida,
      results: count($urls),
      resultUrls: $urls,
    );
  }

}
