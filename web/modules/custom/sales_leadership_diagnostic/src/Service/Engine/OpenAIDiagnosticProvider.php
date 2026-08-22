<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Exception\EngineException;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Motor de diagnóstico sobre la API de OpenAI (§28, §29).
 *
 * Es la ÚNICA clase del módulo que sabe que el proveedor es OpenAI. Añadir
 * otro proveedor será escribir otra implementación de la interfaz y cambiar
 * una línea en la fábrica.
 *
 * Todo lo que hay aquí sobre el comportamiento del proveedor se comprobó
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
final class OpenAIDiagnosticProvider implements DiagnosticEngineInterface {

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
   * Esquema que se exige a la respuesta del modelo (§32).
   *
   * Vive aquí y no en la configuración del cliente a propósito: es una
   * necesidad técnica del módulo —lo que permite validar y almacenar— y no
   * parte de la metodología, que sí es del cliente (§15). El cliente controla
   * el prompt; el módulo controla la forma de la respuesta.
   *
   * El modo estricto obliga a que todas las propiedades estén declaradas como
   * requeridas, así que los campos opcionales se expresan admitiendo nulo.
   */
  private const RESPONSE_SCHEMA = [
    'type' => 'object',
    'properties' => [
      'type' => [
        'type' => 'string',
        'enum' => ['diagnostic_response', 'diagnostic_result'],
      ],
      'message' => ['type' => 'string'],
      'status' => [
        'type' => 'string',
        'enum' => ['in_progress', 'completed', 'failed'],
      ],
      'result' => [
        'type' => ['object', 'null'],
        'properties' => [
          'summary' => ['type' => 'string'],
          'score' => ['type' => ['integer', 'null']],
          'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
          'opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
          'recommendations' => ['type' => 'array', 'items' => ['type' => 'string']],
          'priority_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        'required' => [
          'summary',
          'score',
          'strengths',
          'opportunities',
          'recommendations',
          'priority_actions',
        ],
        'additionalProperties' => FALSE,
      ],
    ],
    'required' => ['type', 'message', 'status', 'result'],
    'additionalProperties' => FALSE,
  ];

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly SecretsProvider $secrets,
    private readonly DiagnosticResponseValidator $validator,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public function process(DiagnosticContext $context): DiagnosticTurn {
    $model = $this->getModel();

    if ($model === '') {
      throw new EngineException('No hay ningún modelo de IA seleccionado en la configuración.');
    }

    $payload = $this->buildPayload($context, $model);
    $raw = $this->requestWithRetries($payload);

    return $this->validator->validate($raw);
  }

  /**
   * Construye el cuerpo de la petición.
   *
   * @return array<string, mixed>
   */
  private function buildPayload(DiagnosticContext $context, string $model): array {
    $messages = [
      ['role' => 'system', 'content' => $context->systemPrompt],
    ];

    foreach ($context->historyAsPayload() as $message) {
      $messages[] = $message;
    }

    // Cuando el tope de turnos obliga a cerrar, se le dice al modelo que
    // concluya. Sin este aviso seguiría preguntando y la conversación
    // terminaría cortada a mitad, sin resultado que guardar.
    if ($context->isFinalTurn()) {
      $messages[] = [
        'role' => 'system',
        'content' => 'Este es el último turno disponible. Concluye el diagnóstico ahora con la información recogida y devuelve el resultado completo.',
      ];
    }

    return [
      'model' => $model,
      'messages' => $messages,
      'max_completion_tokens' => $this->getMaxCompletionTokens(),
      'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
          'name' => 'diagnostic_turn',
          'strict' => TRUE,
          'schema' => self::RESPONSE_SCHEMA,
        ],
      ],
    ];
  }

  /**
   * Ejecuta la petición, reintentando solo lo que merece reintento.
   *
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   *   Respuesta del modelo, ya decodificada.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function requestWithRetries(array $payload): array {
    $attempts = $this->getMaxRetries() + 1;
    $lastError = NULL;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
      try {
        return $this->requestOnce($payload);
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
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function requestOnce(array $payload): array {
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

    return $this->extractTurn($body);
  }

  /**
   * Extrae el turno estructurado de la respuesta del proveedor.
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function extractTurn(string $body): array {
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
    $turn = json_decode($content, TRUE);

    if (!is_array($turn)) {
      throw new InvalidEngineResponseException('El proveedor no devolvió un objeto JSON válido.');
    }

    $this->logUsage($decoded['usage'] ?? []);

    return $turn;
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
   */
  private function logUsage(array $usage): void {
    if ($usage === []) {
      return;
    }

    $this->logger->info('Turno generado. Tokens: entrada @in, salida @out (razonamiento @reasoning).', [
      '@in' => (int) ($usage['prompt_tokens'] ?? 0),
      '@out' => (int) ($usage['completion_tokens'] ?? 0),
      '@reasoning' => (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
    ]);
  }

  private function getModel(): string {
    return trim((string) $this->config()->get('openai.model'));
  }

  private function getTimeout(): int {
    $value = (int) $this->config()->get('openai.timeout');

    return $value > 0 ? $value : 60;
  }

  private function getMaxRetries(): int {
    return max(0, min((int) $this->config()->get('openai.max_retries'), 5));
  }

  private function getMaxCompletionTokens(): int {
    $value = (int) $this->config()->get('openai.max_completion_tokens');

    return $value > 0 ? $value : 2000;
  }

  private function config() {
    return $this->configFactory->get('sales_leadership_diagnostic.settings');
  }

}
