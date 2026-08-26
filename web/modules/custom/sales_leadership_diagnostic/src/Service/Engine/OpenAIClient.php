<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Exception\EngineException;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Hablar con OpenAI y volver con un objeto JSON válido.
 *
 * Es la ÚNICA clase del módulo que sabe que el proveedor es OpenAI: conoce el
 * endpoint, las credenciales, qué errores merecen reintento y cómo viene
 * envuelta la respuesta. Lo que NO sabe es para qué se le pregunta.
 *
 * Esa separación existe porque el módulo hace ya dos llamadas de naturaleza
 * distinta —conducir el diagnóstico y extraer la memoria del alumno— y tendrá
 * más. Todas necesitan la misma fontanería y ninguna necesita el esquema de
 * las demás. Antes de separarlo, añadir la segunda habría significado copiar
 * los reintentos, el manejo de errores y el registro de consumo, con lo que
 * corregir un fallo en uno de los dos sitios habría dejado el otro intacto.
 *
 * Cuanto se afirma aquí sobre el comportamiento del proveedor se comprobó
 * contra la API real antes de escribirlo, no se dio por supuesto:
 *
 *  - Se usa `max_completion_tokens`; `max_tokens` no aplica a estos modelos.
 *  - NO se envía `temperature`. El modelo la rechaza con un 400 salvo que sea
 *    su valor por defecto, así que enviarla rompería todas las llamadas.
 *  - El modelo razona antes de responder y ese razonamiento consume parte del
 *    presupuesto de tokens. De ahí que el límite sea configurable y holgado:
 *    si se agota, la respuesta llega cortada y el JSON queda inservible.
 *  - Las respuestas estructuradas con esquema estricto funcionan, incluidos
 *    objetos anidados y campos que admiten nulo.
 */
final class OpenAIClient {

  /**
   * Endpoint de conversación.
   */
  private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

  /**
   * Códigos que merecen reintento.
   *
   * 429 y 5xx son transitorios: el mismo intento más tarde puede funcionar.
   * Un 400 o un 401 no lo son —la petición o las credenciales están mal— y
   * repetirlos solo multiplica el coste y el tiempo de espera del alumno.
   */
  private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

  /**
   * Canal de log del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly SecretsProvider $secrets,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Pide al modelo un objeto JSON que cumpla el esquema indicado.
   *
   * @param array<int, array{role: string, content: string}> $messages
   *   Conversación que se envía, en el formato del proveedor.
   * @param string $schemaName
   *   Nombre del esquema. Lo exige la API y aparece en sus registros; conviene
   *   que diga de qué llamada se trata.
   * @param array<string, mixed> $schema
   *   Esquema JSON estricto al que debe ajustarse la respuesta.
   * @param string $purpose
   *   Para qué era la llamada. Solo se usa al registrar el consumo, de modo
   *   que en el log se distinga qué gastó qué.
   * @param int|null $maxTokens
   *   Presupuesto de la respuesta. Sin valor, el de la configuración.
   *
   * @return array<string, mixed>
   *   El objeto que devolvió el modelo, ya decodificado.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  public function completeJson(array $messages, string $schemaName, array $schema, string $purpose, ?int $maxTokens = NULL): array {
    $model = $this->getModel();

    if ($model === '') {
      throw new EngineException('No hay ningún modelo de IA seleccionado en la configuración.');
    }

    return $this->requestWithRetries([
      'model' => $model,
      'messages' => $messages,
      'max_completion_tokens' => $maxTokens ?? $this->getMaxCompletionTokens(),
      'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => $schemaName,
          'strict' => TRUE,
          'schema' => $schema,
        ],
      ],
    ], $purpose);
  }

  /**
   * Ejecuta la petición, reintentando solo lo que merece reintento.
   *
   * @param array<string, mixed> $payload
   *   Cuerpo de la petición.
   * @param string $purpose
   *   Para qué era la llamada, para el registro.
   *
   * @return array<string, mixed>
   *   Respuesta del modelo, ya decodificada.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function requestWithRetries(array $payload, string $purpose): array {
    $attempts = $this->getMaxRetries() + 1;
    $lastError = NULL;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
      try {
        return $this->requestOnce($payload, $purpose);
      }
      catch (EngineException $e) {
        $lastError = $e;

        // El código del error indica si tiene sentido repetir.
        if (!in_array($e->getCode(), self::RETRYABLE_STATUSES, TRUE) || $attempt === $attempts) {
          throw $e;
        }

        // Espera creciente: 1 s, 2 s, 4 s. Reintentar de inmediato contra un
        // proveedor saturado suele empeorar la saturación.
        $wait = 2 ** ($attempt - 1);

        $this->logger->warning('Reintento @n de @total tras un error @code del proveedor; esperando @wait s.', [
          '@n' => $attempt,
          '@total' => $attempts - 1,
          '@code' => $e->getCode(),
          '@wait' => $wait,
        ]);

        sleep($wait);
      }
    }

    throw $lastError ?? new EngineException('El proveedor no devolvió ninguna respuesta.');
  }

  /**
   * Una sola llamada al proveedor.
   *
   * @param array<string, mixed> $payload
   *   Cuerpo de la petición.
   * @param string $purpose
   *   Para qué era la llamada, para el registro.
   *
   * @return array<string, mixed>
   *   Respuesta del proveedor, ya decodificada.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function requestOnce(array $payload, string $purpose): array {
    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->secrets->get(SecretsProvider::OPENAI_API_KEY),
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => $this->getTimeout(),
        'connect_timeout' => 15,
        'http_errors' => FALSE,
      ]);
    }
    catch (GuzzleException $e) {
      // Un fallo de red es transitorio: se marca con un código reintentable.
      throw new EngineException('No se pudo contactar con el proveedor de IA.', 503, $e);
    }

    $status = $response->getStatusCode();
    $body = (string) $response->getBody();

    if ($status !== 200) {
      throw new EngineException($this->describeError($status, $body), $status);
    }

    return $this->extractObject($body, $purpose);
  }

  /**
   * Extrae el objeto estructurado de la respuesta del proveedor.
   *
   * @param string $body
   *   Cuerpo de la respuesta.
   * @param string $purpose
   *   Para qué era la llamada, para el registro.
   *
   * @return array<string, mixed>
   *   El objeto que devolvió el modelo.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function extractObject(string $body, string $purpose): array {
    $decoded = json_decode($body, TRUE);

    if (!is_array($decoded) || !isset($decoded['choices'][0])) {
      throw new InvalidEngineResponseException('La respuesta del proveedor no tiene la forma esperada.');
    }

    $choice = $decoded['choices'][0];
    $finishReason = (string) ($choice['finish_reason'] ?? '');

    if ($finishReason === 'length') {
      // El presupuesto de tokens se agotó antes de terminar. El JSON llega
      // cortado, así que no hay nada que salvar. Se nombra la causa real
      // porque el síntoma —"JSON inválido"— apunta al sitio equivocado.
      $this->logger->error('El proveedor agotó el presupuesto de tokens antes de completar la respuesta. Aumente el límite de tokens en la configuración.');

      throw new InvalidEngineResponseException('La respuesta se cortó por falta de presupuesto de tokens.');
    }

    $content = (string) ($choice['message']['content'] ?? '');
    $objeto = json_decode($content, TRUE);

    if (!is_array($objeto)) {
      throw new InvalidEngineResponseException('El proveedor no devolvió un objeto JSON válido.');
    }

    $this->logUsage($decoded['usage'] ?? [], $purpose);

    return $objeto;
  }

  /**
   * Describe un error del proveedor sin filtrar nada sensible.
   */
  private function describeError(int $status, string $body): string {
    $decoded = json_decode($body, TRUE);
    $code = is_array($decoded) ? (string) ($decoded['error']['code'] ?? '') : '';

    // Se registra el código del error, nunca el cuerpo completo: puede
    // contener fragmentos del prompt o de la conversación (§43).
    $this->logger->error('El proveedor de IA respondió @status@code.', [
      '@status' => $status,
      '@code' => $code !== '' ? ' (' . $code . ')' : '',
    ]);

    if ($status === 401) {
      return 'El proveedor rechazó las credenciales. Revise la API key.';
    }

    if ($status === 429) {
      return 'El proveedor está limitando las peticiones.';
    }

    if ($status === 400 && str_contains($code, 'model')) {
      return 'El modelo configurado no existe o la cuenta no tiene acceso a él.';
    }

    return sprintf('El proveedor de IA respondió con el código %d.', $status);
  }

  /**
   * Registra el consumo de tokens.
   *
   * Son cifras, no contenido: permiten vigilar el coste sin guardar nada de
   * la conversación (§43).
   *
   * @param array<string, mixed> $usage
   *   Cifras de consumo que devuelve el proveedor.
   * @param string $purpose
   *   Para qué era la llamada, de modo que se distinga qué gastó qué.
   */
  private function logUsage(array $usage, string $purpose): void {
    if ($usage === []) {
      return;
    }

    $this->logger->info('@purpose. Tokens: entrada @in, salida @out (razonamiento @reasoning).', [
      '@purpose' => $purpose,
      '@in' => (int) ($usage['prompt_tokens'] ?? 0),
      '@out' => (int) ($usage['completion_tokens'] ?? 0),
      '@reasoning' => (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
    ]);
  }

  /**
   * Identificador del modelo seleccionado.
   */
  private function getModel(): string {
    return trim((string) $this->config()->get('openai.model'));
  }

  /**
   * Timeout configurado para las peticiones, en segundos.
   */
  private function getTimeout(): int {
    $value = (int) $this->config()->get('openai.timeout');

    return $value > 0 ? $value : 60;
  }

  /**
   * Número de reintentos admitidos, acotado a un máximo razonable.
   */
  private function getMaxRetries(): int {
    return max(0, min((int) $this->config()->get('openai.max_retries'), 5));
  }

  /**
   * Presupuesto de tokens por respuesta.
   */
  private function getMaxCompletionTokens(): int {
    $value = (int) $this->config()->get('openai.max_completion_tokens');

    return $value > 0 ? $value : 2000;
  }

  /**
   * Configuración del módulo.
   */
  private function config() {
    return $this->configFactory->get('sales_leadership_diagnostic.settings');
  }

}
