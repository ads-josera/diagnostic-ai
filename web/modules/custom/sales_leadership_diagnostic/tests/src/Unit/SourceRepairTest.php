<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\sales_leadership_diagnostic\Service\Research\SourceRepair;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Las fuentes reescritas de memoria vuelven a su dirección real.
 *
 * Las tres direcciones de abajo son las del 05-10-2026: el agente les quitó o
 * les añadió palabras al título, y las retocadas daban 404 mientras las
 * buscadas daban 200.
 */
#[CoversClass(SourceRepair::class)]
final class SourceRepairTest extends UnitTestCase {

  /**
   * Las que trajo la búsqueda aquel día.
   */
  private const BUSCADAS = [
    'https://www.elcomercio.com/empresariales/tigo-realizo-su-lanzamiento-oficial-en-ecuador-con-un-espectaculo-de-drones',
    'https://www.revistazonalibre.ec/2026/09/09/mabe-celebra-80-anos-de-trayectoria-en-ecuador-anunciando-nueva-planta-de-fabricacion-y-una-renovada-imagen-corporativa',
    'https://es.linkedin.com/posts/almacenestia_elefectotia-retail-innovaci%C3%B3n-activity-7488629639016382464-Clf7',
    'https://www.elcomercio.com/negocios/banco-pichincha-resultados-2026',
  ];

  /**
   * A un título le quitó palabras: vuelve a la dirección buscada.
   */
  public function testUnTituloAlQueLeQuitoPalabrasRecuperaSuDireccion(): void {
    $citada = 'https://www.elcomercio.com/empresariales/tigo-realizo-su-lanzamiento-oficial-en-un-espectaculo-de-drones';

    $this->assertSame(self::BUSCADAS[0], $this->reparada($citada));
  }

  /**
   * Otro de aquel día, del mismo tipo, con barra final.
   */
  public function testOtroTituloRecortadoRecuperaSuDireccion(): void {
    $citada = 'https://www.revistazonalibre.ec/2026/09/09/mabe-celebra-80-anos-en-ecuador-anunciando-nueva-planta-de-fabricacion-y-una-renovada-imagen-corporativa/';

    $this->assertSame(self::BUSCADAS[1], $this->reparada($citada));
  }

  /**
   * Una publicación con palabras añadidas pero el mismo número es la misma.
   */
  public function testElMismoIdentificadorEsLaMismaPublicacion(): void {
    // Tantas palabras añadidas que por el título ya no se parece: solo el
    // número de la publicación puede decir que es la misma. Con el título del
    // 05-10-2026 tal cual, el parecido daba un 80 % justo y la prueba pasaba
    // aunque esta regla no existiera.
    $citada = 'https://es.linkedin.com/posts/almacenestia_elefectotia-retail-innovación-transformación-logística-tíago-ecuador-nuevos-formatos-expansion-nacional-2026-activity-7488629639016382464-Clf7';

    $this->assertSame(self::BUSCADAS[2], $this->reparada($citada));
  }

  /**
   * Una dirección que ya es la buscada no se toca.
   */
  public function testUnaDireccionYaBuenaNoSeToca(): void {
    $salida = $this->reparar(self::BUSCADAS[0]);

    $this->assertSame([], $salida['reparadas']);
  }

  /**
   * Otro artículo del mismo sitio no se confunde con la citada.
   *
   * Es el caso que no puede salir mal: cambiar una dirección por la de otra
   * página sería peor que dejarla rota, porque nadie lo señalaría.
   */
  public function testOtroArticuloDelMismoSitioNoSeConfunde(): void {
    $citada = 'https://www.elcomercio.com/negocios/claro-invierte-600-millones-en-redes';

    $this->assertNull($this->reparada($citada));
  }

  /**
   * Con dos candidatas casi iguales, hay duda y no se toca.
   */
  public function testConDosCandidatasCasiIgualesNoSeToca(): void {
    $buscadas = [
      'https://diario.ec/a/tia-abre-tiendas-en-quito-2026',
      'https://diario.ec/a/tia-abre-tiendas-en-cuenca-2026',
    ];

    $salida = (new SourceRepair())->repair($this->pack('https://diario.ec/a/tia-abre-tiendas-en-2026'), '', $buscadas);

    $this->assertSame([], $salida['reparadas']);
  }

  /**
   * La dirección retocada también se cambia en el texto del Pack.
   */
  public function testTambienSeCambiaEnElTexto(): void {
    $citada = 'https://www.elcomercio.com/empresariales/tigo-realizo-su-lanzamiento-oficial-en-un-espectaculo-de-drones';

    $salida = (new SourceRepair())->repair($this->pack($citada), "Lanzamiento ([El Comercio]($citada)).", self::BUSCADAS);

    $this->assertStringContainsString(self::BUSCADAS[0], $salida['message']);
    $this->assertStringNotContainsString($citada . ')', $salida['message']);
  }

  /**
   * Y la fuente que prueba al comprador, si es la retocada.
   */
  public function testTambienSeReparaLaFuenteDelComprador(): void {
    $citada = 'https://www.elcomercio.com/empresariales/tigo-realizo-su-lanzamiento-oficial-en-un-espectaculo-de-drones';
    $pack = $this->pack($citada);
    $pack['accounts'][0]['buyer_source'] = $citada;

    $salida = (new SourceRepair())->repair($pack, '', self::BUSCADAS);

    $this->assertSame(self::BUSCADAS[0], $salida['result']['accounts'][0]['buyer_source']);
  }

  /**
   * La dirección con la que quedó la fuente, o NULL si no se cambió.
   */
  private function reparada(string $citada): ?string {
    $salida = $this->reparar($citada);

    return $salida['reparadas'] === [] ? NULL : $salida['result']['accounts'][0]['sources'][0]['url'];
  }

  /**
   * Repara un Pack con esa sola fuente contra las buscadas de aquel día.
   */
  private function reparar(string $citada): array {
    return (new SourceRepair())->repair($this->pack($citada), '', self::BUSCADAS);
  }

  /**
   * Un Pack con una cuenta y esa fuente.
   */
  private function pack(string $url): array {
    return [
      'accounts' => [[
        'name' => 'Cuenta',
        'buyer_source' => '',
        'sources' => [['url' => $url, 'label' => 'x', 'published' => '']],
      ],
      ],
    ];
  }

}
