<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Exception\InvalidTokenException;
use Drupal\sales_leadership_diagnostic\Service\Security\SsoTokenValidator;
use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba la validación del token de acceso (§10).
 *
 * El token es lo único que separa a un visitante cualquiera de la sesión de un
 * alumno. Cada uno de estos tests fija una puerta que debe permanecer cerrada.
 */
#[CoversClass(SsoTokenValidator::class)]
final class SsoTokenValidatorTest extends KernelTestBase {

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

  private const EMISOR = 'https://salesbumm.com';
  private const AUDIENCIA = 'https://diagnostico.salesbumm.com';

  /**
   * Secreto de pruebas. Debe superar los 32 caracteres que exige la librería.
   */
  private const SECRETO = 'secreto-de-pruebas-con-longitud-mas-que-suficiente-para-hs256';

  private SsoTokenValidator $validador;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['sales_leadership_diagnostic']);

    $this->setSetting('sld_jwt_shared_secret', self::SECRETO);

    $this->validador = $this->container->get(SsoTokenValidator::class);
  }

  /**
   * Un token correcto devuelve la identidad que contiene.
   */
  public function testAceptaUnTokenValido(): void {
    $identidad = $this->validar($this->firmar());

    $this->assertSame('4821', $identidad->externalUserId);
    $this->assertSame('alumno@example.com', $identidad->email);
    $this->assertSame('José Ramírez', $identidad->name);
  }

  /**
   * El mismo token no sirve dos veces.
   *
   * El token viaja en la URL y queda en el historial del navegador: sin este
   * control, recuperarlo de ahí bastaría para entrar como el alumno.
   */
  public function testUnTokenNoPuedeReutilizarse(): void {
    $token = $this->firmar();

    $this->validar($token);

    $this->expectException(InvalidTokenException::class);
    $this->validar($token);
  }

  /**
   * Un token firmado con otro secreto se rechaza.
   */
  public function testRechazaOtraFirma(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar([], 'otro-secreto-igualmente-largo-para-pasar-la-validacion'));
  }

  /**
   * Un token caducado se rechaza.
   */
  public function testRechazaTokenCaducado(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar([
      'iat' => time() - 500,
      'exp' => time() - 400,
    ]));
  }

  /**
   * Un token de otro emisor se rechaza.
   */
  public function testRechazaOtroEmisor(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar(['iss' => 'https://atacante.example']));
  }

  /**
   * Un token dirigido a otro Drupal se rechaza.
   *
   * Aunque la firma sea correcta: son sistemas distintos con datos distintos.
   */
  public function testRechazaOtraAudiencia(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar(['aud' => 'https://otro-drupal.example']));
  }

  /**
   * Un token con vigencia desmesurada se rechaza.
   *
   * La librería comprueba que no haya caducado, pero no cuánta vida se le
   * concedió. Sin esto, un WordPress comprometido podría emitir tokens
   * eternos y este sitio los aceptaría.
   */
  public function testRechazaVigenciaExcesiva(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar(['exp' => time() + 31536000]));
  }

  /**
   * Un token sin identificador único se rechaza.
   *
   * Sin `jti` no puede protegerse contra reutilización, y aceptarlo sería
   * renunciar a esa garantía en silencio.
   */
  public function testRechazaTokenSinIdentificador(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar(['jti' => '']));
  }

  /**
   * Un token sin sujeto se rechaza.
   */
  public function testRechazaTokenSinSujeto(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar($this->firmar(['sub' => '']));
  }

  /**
   * Una cadena arbitraria se rechaza sin reventar.
   */
  public function testRechazaBasura(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar('esto-no-es-un-token');
  }

  /**
   * Un token vacío se rechaza.
   */
  public function testRechazaTokenVacio(): void {
    $this->expectException(InvalidTokenException::class);

    $this->validar('');
  }

  /**
   * El ataque clásico de algoritmo "none" se rechaza.
   */
  public function testRechazaAlgoritmoNone(): void {
    $cabecera = $this->base64Url((string) json_encode(['typ' => 'JWT', 'alg' => 'none']));
    $cuerpo = $this->base64Url((string) json_encode($this->claims()));

    $this->expectException(InvalidTokenException::class);

    $this->validar($cabecera . '.' . $cuerpo . '.');
  }

  /**
   * Atajo de validación con el emisor y la audiencia esperados.
   */
  private function validar(string $token) {
    return $this->validador->validate($token, self::EMISOR, self::AUDIENCIA);
  }

  /**
   * Firma un token con los claims indicados.
   *
   * @param array<string, mixed> $sobrescribir
   */
  private function firmar(array $sobrescribir = [], ?string $secreto = NULL): string {
    return JWT::encode(
      $sobrescribir + $this->claims(),
      $secreto ?? self::SECRETO,
      'HS256',
    );
  }

  /**
   * Claims por defecto de un token legítimo.
   *
   * @return array<string, mixed>
   */
  private function claims(): array {
    $ahora = time();

    return [
      'iss' => self::EMISOR,
      'aud' => self::AUDIENCIA,
      'sub' => '4821',
      'jti' => bin2hex(random_bytes(16)),
      'iat' => $ahora,
      'exp' => $ahora + 90,
      'email' => 'alumno@example.com',
      'name' => 'José Ramírez',
    ];
  }

  /**
   * Codificación base64 segura para URL.
   */
  private function base64Url(string $datos): string {
    return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
  }

}
