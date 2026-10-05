<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\WebSearchTool;
use Drupal\sales_leadership_diagnostic\Service\Research\BuyerEvidenceCheck;
use Drupal\sales_leadership_diagnostic\Service\Research\RetrievedPages;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Un comprador dado por verificado tiene que tener una fuente que lo nombre.
 *
 * El 03-10-2026, en la prueba con la cuenta del cliente, el agente dio por
 * verificado al directivo de un banco con una página que hablaba del banco y
 * no lo mencionaba, mientras otra de sus propios resultados, que sí lo
 * nombraba con su cargo, se quedaba sin citar. Los casos de abajo salen de
 * aquel Pack.
 */
#[CoversClass(BuyerEvidenceCheck::class)]
final class BuyerEvidenceCheckTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * Conversación de las pruebas.
   */
  private const SESION = 37;

  /**
   * La página que SÍ lo nombra con su cargo.
   */
  private const AMCHAM = 'https://www.linkedin.com/posts/amcham-quito_lasso-ceo';

  /**
   * La página del banco que NO lo nombra.
   */
  private const EKOS = 'https://www.facebook.com/revistaekos/posts/banco-guayaquil-150-millones';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('sales_leadership_diagnostic', ['sld_tool_call']);
    $this->container->get(CurrentTurn::class)->begin(14, self::SESION, FALSE, 'prospecting_diagnostic');

    // Lo que trajo la búsqueda de aquella misión, resumido.
    $this->buscado(self::AMCHAM, 'AMCHAM Quito: Guillermo Lasso Alcivar, CEO Banco Guayaquil, nos cuenta sobre su visión.');
    $this->buscado(self::EKOS, 'Banco Guayaquil anunció un fortalecimiento patrimonial de USD 150 millones.');
  }

  /**
   * Con la página que lo nombra, está respaldado.
   *
   * El nombre lleva tilde en el Pack y no en la página: no puede contar como
   * diferencia, o la comprobación rechazaría a media prensa latinoamericana.
   */
  public function testConLaPaginaQueLoNombraEstaRespaldado(): void {
    $this->assertSame([], $this->huecos($this->cuenta(self::AMCHAM)));
  }

  /**
   * Con la página que habla del banco pero no de él, NO está respaldado.
   *
   * Es exactamente el caso del 03-10-2026.
   */
  public function testConUnaPaginaQueNoLoNombraNoEstaRespaldado(): void {
    $huecos = $this->huecos($this->cuenta(self::EKOS));

    $this->assertCount(1, $huecos);
    $this->assertSame('la fuente que lo prueba no lo nombra', $huecos[0]['motivo']);
  }

  /**
   * Sin decir qué fuente lo prueba, no está respaldado.
   */
  public function testSinFuenteQueLoPruebeNoEstaRespaldado(): void {
    $huecos = $this->huecos($this->cuenta(''));

    $this->assertCount(1, $huecos);
    $this->assertStringContainsString('buyer_source vacío', $huecos[0]['motivo']);
  }

  /**
   * La fuente que lo prueba tiene que estar entre las fuentes de la cuenta.
   *
   * Si no, la persona que lee el Pack no la ve y no puede comprobarla.
   */
  public function testLaFuenteTieneQueEstarEntreLasDeLaCuenta(): void {
    $cuenta = $this->cuenta(self::AMCHAM);
    $cuenta['sources'] = [['url' => self::EKOS, 'label' => 'Ekos', 'published' => '']];

    $huecos = $this->huecos($cuenta);

    $this->assertCount(1, $huecos);
    $this->assertStringContainsString('no está entre las fuentes', $huecos[0]['motivo']);
  }

  /**
   * Las tildes y las mayúsculas no cuentan como diferencia.
   *
   * Aquí sí deciden: con un nombre de dos partes hacen falta las dos, y las
   * dos llevan tilde en el Pack y ninguna en la página.
   */
  public function testLasTildesNoCuentanComoDiferencia(): void {
    $url = 'https://ejemplo.ec/nombramiento';
    $this->buscado($url, 'JOSE PEREZ asumió como gerente general de la compañía.');
    $cuenta = $this->cuenta($url);
    $cuenta['buyer'] = 'José Pérez, Gerente General';

    $this->assertSame([], $this->huecos($cuenta));
  }

  /**
   * Un apellido suelto no basta: coincide con demasiada gente.
   */
  public function testUnApellidoSueltoNoBasta(): void {
    $this->buscado('https://ejemplo.ec/lasso-mendoza', 'El expresidente Lasso visitó Guayaquil.');

    $huecos = $this->huecos($this->cuenta('https://ejemplo.ec/lasso-mendoza'));

    $this->assertCount(1, $huecos);
  }

  /**
   * Una página que salió en otro turno no se acusa por no poder leerla.
   *
   * Su texto ya no está en memoria, pero salió de una búsqueda de la misión:
   * no hay base para decir que no lo nombra.
   */
  public function testUnaPaginaDeOtroTurnoNoSeAcusa(): void {
    $url = 'https://www.forbes.com.ec/perfil-lasso';
    $this->container->get(ToolCallRepository::class)->record(
      uid: 14, sessionId: self::SESION, tool: WebSearchTool::NAME, query: 'otra', allowed: TRUE, results: 1, resultUrls: [$url],
    );

    $this->assertSame([], $this->huecos($this->cuenta($url)));
  }

  /**
   * Una página que no salió de ninguna búsqueda no respalda nada.
   */
  public function testUnaPaginaQueNoSalioDeLaBusquedaNoRespalda(): void {
    $huecos = $this->huecos($this->cuenta('https://inventada.ec/perfil'));

    $this->assertCount(1, $huecos);
    $this->assertStringContainsString('no salió de ninguna búsqueda', $huecos[0]['motivo']);
  }

  /**
   * «Verificado» con solo el rol es una contradicción.
   */
  public function testVerificadoConSoloElRolEsUnaContradiccion(): void {
    $cuenta = $this->cuenta(self::AMCHAM);
    $cuenta['buyer'] = 'ROLE-ONLY — Gerencia General';

    $this->assertCount(1, $this->huecos($cuenta));
  }

  /**
   * Un comprador no verificado no se comprueba.
   */
  public function testUnCompradorNoVerificadoNoSeComprueba(): void {
    $cuenta = $this->cuenta('');
    $cuenta['buyer_verified'] = FALSE;

    $this->assertSame([], $this->huecos($cuenta));
  }

  /**
   * La cuenta de aquel Pack, con la fuente que el agente diga.
   */
  private function cuenta(string $fuente): array {
    return [
      'name' => 'Banco Guayaquil',
      'buyer' => 'Guillermo Lasso Alcívar — CEO de Banco Guayaquil (P1)',
      'buyer_verified' => TRUE,
      'buyer_source' => $fuente,
      'sources' => [
        ['url' => self::AMCHAM, 'label' => 'AMCHAM', 'published' => '2026-07-25'],
        ['url' => self::EKOS, 'label' => 'Ekos', 'published' => ''],
        ['url' => 'https://ejemplo.ec/lasso-mendoza', 'label' => 'x', 'published' => ''],
        ['url' => 'https://www.forbes.com.ec/perfil-lasso', 'label' => 'Forbes', 'published' => ''],
        ['url' => 'https://inventada.ec/perfil', 'label' => 'x', 'published' => ''],
        ['url' => 'https://ejemplo.ec/nombramiento', 'label' => 'x', 'published' => ''],
      ],
    ];
  }

  /**
   * Los huecos de un Pack con esa sola cuenta.
   */
  private function huecos(array $cuenta): array {
    return $this->container->get(BuyerEvidenceCheck::class)->gaps(['accounts' => [$cuenta]]);
  }

  /**
   * Anota un resultado de búsqueda de esta misión, con su texto.
   */
  private function buscado(string $url, string $texto): void {
    $this->container->get(ToolCallRepository::class)->record(
      uid: 14, sessionId: self::SESION, tool: WebSearchTool::NAME, query: 'consulta', allowed: TRUE, results: 1, resultUrls: [$url],
    );
    $this->container->get(RetrievedPages::class)->remember(self::SESION, $url, $texto);
  }

}
