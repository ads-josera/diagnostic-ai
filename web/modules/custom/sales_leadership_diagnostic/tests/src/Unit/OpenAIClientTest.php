<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\sales_leadership_diagnostic\Exception\EngineException;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba cómo se comporta el módulo ante lo que devuelve el proveedor.
 *
 * Esta clase es la que habla con OpenAI, y hasta ahora no tenía pruebas. Se le
 * ponen al separarla del motor de diagnóstico, porque desde entonces la
 * comparten dos llamadas —conducir el diagnóstico y extraer la memoria— y un
 * fallo aquí las rompe las dos a la vez.
 *
 * Lo que se prueba no es que la llamada funcione, que eso depende del
 * proveedor, sino que el módulo distinga bien entre lo transitorio y lo que no
 * lo es. Reintentar un 401 multiplica el coste y la espera sin arreglar nada;
 * no reintentar un 429 deja caer un turno que habría salido bien un segundo
 * después.
 *
 * El proveedor se sustituye por un doble de Guzzle: los tests no deben
 * depender de la red (§48).
 */
#[CoversClass(OpenAIClient::class)]
final class OpenAIClientTest extends UnitTestCase {

  /**
   * Una respuesta correcta se devuelve ya decodificada.
   */
  public function testDevuelveElObjetoQueDevolvioElModelo(): void {
    $client = $this->client([
      $this->respuestaCorrecta(['topic' => 'empresa', 'content' => 'Distribuidora.']),
    ]);

    $this->assertSame(
      ['topic' => 'empresa', 'content' => 'Distribuidora.'],
      $this->llamar($client),
    );
  }

  /**
   * Un 429 se reintenta, y el segundo intento vale.
   *
   * Es el caso frecuente en producción: el proveedor limita durante unos
   * segundos y la misma petición pasa después.
   */
  public function testReintentaCuandoElProveedorLimita(): void {
    $client = $this->client([
      new Response(429, [], json_encode(['error' => ['code' => 'rate_limit_exceeded']])),
      $this->respuestaCorrecta(['ok' => TRUE]),
    ], reintentos: 1);

    $this->assertSame(['ok' => TRUE], $this->llamar($client));
  }

  /**
   * Un 401 NO se reintenta.
   *
   * Las credenciales no van a arreglarse solas entre un intento y el
   * siguiente. Si se reintentara, el segundo elemento de la cola se
   * consumiría y el test devolvería un resultado correcto.
   */
  public function testNoReintentaCuandoLasCredencialesSonMalas(): void {
    $client = $this->client([
      new Response(401, [], json_encode(['error' => ['code' => 'invalid_api_key']])),
      $this->respuestaCorrecta(['no' => 'deberia llegarse aqui']),
    ], reintentos: 3);

    $this->expectException(EngineException::class);
    $this->expectExceptionMessage('Revise la API key');

    $this->llamar($client);
  }

  /**
   * Una respuesta cortada por falta de tokens se nombra por su causa.
   *
   * El síntoma es un JSON inválido, y ese mensaje manda a buscar el problema
   * al sitio equivocado: lo que hay que subir es el presupuesto de tokens.
   */
  public function testUnaRespuestaCortadaSeNombraPorSuCausa(): void {
    $client = $this->client([
      new Response(200, [], (string) json_encode([
        'choices' => [
          [
            'finish_reason' => 'length',
            'message' => ['content' => '{"topic":"emp'],
          ],
        ],
      ])),
    ]);

    $this->expectException(InvalidEngineResponseException::class);
    $this->expectExceptionMessage('presupuesto de tokens');

    $this->llamar($client);
  }

  /**
   * Sin modelo configurado se falla antes de salir a la red.
   */
  public function testSinModeloNoSeLlamaAlProveedor(): void {
    $client = $this->client([], modelo: '');

    $this->expectException(EngineException::class);
    $this->expectExceptionMessage('modelo de IA');

    $this->llamar($client);
  }

  /**
   * Hace una llamada cualquiera, que a este nivel da igual cuál sea.
   *
   * @return array<string, mixed>
   *   Lo que devolvió el cliente.
   */
  private function llamar(OpenAIClient $client): array {
    return $client->completeJson(
      [['role' => 'user', 'content' => 'Hola.']],
      'prueba',
      ['type' => 'object'],
      'Prueba',
    );
  }

  /**
   * Una respuesta del proveedor con el objeto indicado dentro.
   *
   * @param array<string, mixed> $objeto
   *   Lo que se quiere que haya devuelto el modelo.
   */
  private function respuestaCorrecta(array $objeto): Response {
    return new Response(200, [], (string) json_encode([
      'choices' => [
        [
          'finish_reason' => 'stop',
          'message' => ['content' => json_encode($objeto)],
        ],
      ],
      'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
    ]));
  }

  /**
   * Construye el cliente con una cola de respuestas preparadas.
   *
   * @param \GuzzleHttp\Psr7\Response[] $respuestas
   *   Lo que devolverá el proveedor, en orden.
   * @param int $reintentos
   *   Reintentos configurados.
   * @param string $modelo
   *   Modelo configurado.
   */
  private function client(array $respuestas, int $reintentos = 0, string $modelo = 'gpt-prueba'): OpenAIClient {
    $logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);

    return new OpenAIClient(
      new Client(['handler' => HandlerStack::create(new MockHandler($respuestas))]),
      // No se puede sustituir por un doble: es final a propósito, para que no
      // haya más de una forma de leer un secreto. Se construye de verdad con
      // unos ajustes de prueba.
      new SecretsProvider(new Settings([SecretsProvider::OPENAI_API_KEY => 'sk-de-prueba'])),
      $this->getConfigFactoryStub([
        'sales_leadership_diagnostic.settings' => [
          'openai' => [
            'model' => $modelo,
            'timeout' => 30,
            'max_retries' => $reintentos,
            'max_completion_tokens' => 2000,
          ],
        ],
      ]),
      $loggerFactory,
    );
  }

}
