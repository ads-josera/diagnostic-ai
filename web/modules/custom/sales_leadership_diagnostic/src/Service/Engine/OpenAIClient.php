<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Exception\EngineException;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\DTO\AiCall;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox;
use Drupal\sales_leadership_diagnostic\Service\Security\SecretsProvider;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\AiUsageCollector;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;
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
 *  - Se habla por `/v1/responses`. El endpoint anterior no admite
 *    herramientas con este modelo salvo apagando su razonamiento (§0001).
 *  - Se usa `max_output_tokens`; `max_tokens` no aplica a estos modelos.
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
   *
   * Es `/v1/responses` y no `/v1/chat/completions` por una razón medida, no
   * por modernidad: el modelo en uso **no admite herramientas** en el endpoint
   * antiguo salvo apagando su razonamiento, y apagarlo degradaría también al
   * agente de diagnóstico, que comparte esta clase. Lo dijo la propia API al
   * probarlo el 07-09-2026. Ver `docs/decisiones/0001`.
   */
  private const ENDPOINT = 'https://api.openai.com/v1/responses';

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
    private readonly AiUsageCollector $usage,
    private readonly SpendGuard $spend,
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
   * @param \Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox|null $tools
   *   Herramientas que el modelo puede pedir en este turno. Sin ellas no se
   *   declara ninguna, ni siquiera una lista vacía: declararlas cambia el
   *   prefijo del prompt y con él se pierde la caché.
   *
   * @return array<string, mixed>
   *   El objeto que devolvió el modelo, ya decodificado.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  public function completeJson(array $messages, string $schemaName, array $schema, string $purpose, ?int $maxTokens = NULL, ?ToolBox $tools = NULL): array {
    $model = $this->getModel();

    if ($model === '') {
      throw new EngineException('No hay ningún modelo de IA seleccionado en la configuración.');
    }

    return $this->converse([
      'model' => $model,
      // `input` y no `messages`; la forma de cada mensaje —rol y contenido—
      // es la misma, así que quien llama no se entera del cambio.
      'input' => $messages,
      'max_output_tokens' => $maxTokens ?? $this->getMaxCompletionTokens(),
      // El esquema estricto vive un nivel más arriba que en el endpoint
      // anterior, y su nombre deja de estar anidado.
      'text' => [
        'format' => [
          'type' => 'json_schema',
          'name' => $schemaName,
          'strict' => TRUE,
          'schema' => $schema,
        ],
      ],
    ], $purpose, $tools);
  }

  /**
   * Conversa con el modelo hasta que deja de pedir herramientas.
   *
   * Un turno del alumno puede ser VARIAS llamadas al proveedor: el modelo pide
   * una herramienta, se le da el resultado, y con él pide otra. Al sondearlo
   * el 08-09-2026 pidió dos búsquedas más nada más recibir la primera, así que
   * el tope de vueltas no es defensivo: es el caso normal.
   *
   * Dos cosas se aprendieron probando y no se pueden deducir leyendo:
   *
   *  - Hay que reenviarle **su propio razonamiento** junto a la petición de
   *    herramienta. Devolver solo el resultado da un 400 que nombra el
   *    elemento de razonamiento que falta.
   *  - Por eso se reenvía la salida ENTERA del turno anterior, sin filtrar.
   *
   * @param array<string, mixed> $payload
   *   Cuerpo de la primera petición.
   * @param string $purpose
   *   Para qué era, para el registro de consumo.
   * @param \Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBox|null $tools
   *   Herramientas del turno. Sin ellas no se declara nada, ni siquiera una
   *   lista vacía: declararlas cambia el prefijo del prompt y con él se
   *   perdería la caché de los turnos que no las necesitan.
   *
   * @return array<string, mixed>
   *   El objeto que acabó devolviendo el modelo.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function converse(array $payload, string $purpose, ?ToolBox $tools): array {
    $usaHerramientas = $tools !== NULL && !$tools->isEmpty();

    if ($usaHerramientas) {
      $payload['tools'] = $tools->declarations();
    }

    $vueltas = $usaHerramientas ? $this->getMaxToolRounds() : 1;

    for ($vuelta = 1; $vuelta <= $vueltas; $vuelta++) {
      $decoded = $this->requestWithRetries($payload, $purpose);
      $peticiones = $this->toolCallsOf($decoded);

      if ($peticiones === [] || !$usaHerramientas) {
        return $this->extractObject($decoded, $purpose);
      }

      // La salida entera, sin filtrar: el razonamiento va con la petición y el
      // proveedor rechaza la una sin el otro.
      $payload['input'] = array_merge($payload['input'], $decoded['output']);

      foreach ($peticiones as $peticion) {
        $payload['input'][] = [
          'type' => 'function_call_output',
          'call_id' => $peticion['call_id'],
          'output' => $tools->run(
            (string) $peticion['name'],
            (array) (json_decode((string) $peticion['arguments'], TRUE) ?? []),
          ),
        ];
      }
    }

    // Se acabaron las vueltas y el modelo seguía pidiendo. No se le da otra
    // ronda en silencio: cada una cuesta dinero y el techo existe para eso.
    $this->logger->warning('El modelo agotó las @n vueltas de herramientas sin cerrar la respuesta.', [
      '@n' => $vueltas,
    ]);

    throw new InvalidEngineResponseException('El modelo siguió pidiendo herramientas más allá del límite de vueltas.');
  }

  /**
   * Peticiones de herramienta que trae una respuesta.
   *
   * @param array<string, mixed> $decoded
   *   Respuesta del proveedor, ya decodificada.
   *
   * @return array<int, array<string, mixed>>
   *   Las peticiones, en orden. Vacío si no pidió nada.
   */
  private function toolCallsOf(array $decoded): array {
    $peticiones = [];

    foreach ($decoded['output'] ?? [] as $item) {
      if (($item['type'] ?? '') === 'function_call' && isset($item['call_id'], $item['name'])) {
        $peticiones[] = $item;
      }
    }

    return $peticiones;
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
   *   Cuerpo de la respuesta, ya decodificado y sin interpretar.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\EngineException
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function requestWithRetries(array $payload, string $purpose): array {
    // El tope GLOBAL se comprueba aquí, en el único punto por donde pasan
    // TODAS las llamadas del módulo, incluidas las que no pertenecen a ningún
    // alumno. No necesita saber de quién es la llamada, así que puede vivir en
    // una clase que deliberadamente no lo sabe (§31, §43).
    //
    // Es la red que sigue puesta aunque un camino nuevo se olvide de comprobar
    // el tope individual: lo peor que puede pasar con un presupuesto no es que
    // un alumno lo agote, es que nadie lo esté mirando de madrugada.
    $this->spend->assertGlobalHeadroom();

    $attempts = $this->getMaxRetries() + 1;
    $lastError = NULL;
    $inicio = microtime(TRUE);

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
      try {
        $decoded = $this->requestOnce($payload);

        // Se anota ANTES de mirar si el contenido sirve. Una respuesta 200 con
        // el JSON cortado ya se pagó —y es de las caras, porque agotó el
        // presupuesto de salida—; registrarla solo cuando es aprovechable
        // dejaría ese gasto fuera de la cuenta.
        $this->registrar($payload, $purpose, $decoded, $inicio, $attempt, '');

        // Devuelve el cuerpo crudo: quien conversa necesita ver si el modelo
        // pidió una herramienta antes de intentar leer una respuesta que
        // todavía no existe.
        return $decoded;
      }
      catch (EngineException $e) {
        $lastError = $e;

        // El código del error indica si tiene sentido repetir.
        if (!in_array($e->getCode(), self::RETRYABLE_STATUSES, TRUE) || $attempt === $attempts) {
          $this->registrar($payload, $purpose, [], $inicio, $attempt, $e->getMessage());

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
   *
   * @return array<string, mixed>
   *   Respuesta del proveedor, ya decodificada. Sin comprobar todavía si su
   *   contenido sirve: eso lo mira extractObject(), y en medio hay que anotar
   *   el consumo, porque una respuesta inservible ya se pagó.
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

    $decoded = json_decode($body, TRUE);

    if (!is_array($decoded)) {
      throw new InvalidEngineResponseException('La respuesta del proveedor no tiene la forma esperada.');
    }

    return $decoded;
  }

  /**
   * Extrae el objeto estructurado de la respuesta del proveedor.
   *
   * @param array<string, mixed> $decoded
   *   Respuesta del proveedor, ya decodificada.
   * @param string $purpose
   *   Para qué era la llamada; solo se usa al explicar un fallo.
   *
   * @return array<string, mixed>
   *   El objeto que devolvió el modelo.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  private function extractObject(array $decoded, string $purpose): array {
    $estado = (string) ($decoded['status'] ?? '');

    if ($estado === 'incomplete') {
      $motivo = (string) ($decoded['incomplete_details']['reason'] ?? 'desconocido');

      // Se nombra la causa real porque el síntoma —«JSON inválido»— apunta al
      // sitio equivocado. Comprobado el 08-09-2026 contra la API: cuando se
      // agota el presupuesto, `output` solo trae el razonamiento y no llega a
      // haber mensaje, así que no hay nada que salvar.
      if ($motivo === 'max_output_tokens') {
        $this->logger->error('El proveedor agotó el presupuesto de tokens antes de completar la respuesta. Aumente el límite de tokens en la configuración.');

        throw new InvalidEngineResponseException('La respuesta se cortó por falta de presupuesto de tokens.');
      }

      $this->logger->error('El proveedor devolvió una respuesta incompleta (@motivo).', ['@motivo' => $motivo]);

      throw new InvalidEngineResponseException('El proveedor devolvió una respuesta incompleta.');
    }

    $objeto = json_decode($this->textOf($decoded), TRUE);

    if (!is_array($objeto)) {
      throw new InvalidEngineResponseException('El proveedor no devolvió un objeto JSON válido.');
    }

    return $objeto;
  }

  /**
   * Texto que devolvió el modelo, recorriendo su lista de salidas.
   *
   * La respuesta no trae un único mensaje sino una LISTA de elementos
   * tipados: el razonamiento va como uno más, y las peticiones de herramienta
   * también. Quedarse con el primero devolvería el razonamiento en lugar de la
   * respuesta.
   *
   * No se usa el campo `output_text` que documenta el proveedor: al medirlo el
   * 08-09-2026 no venía en la respuesta.
   *
   * @param array<string, mixed> $decoded
   *   Respuesta del proveedor, ya decodificada.
   */
  private function textOf(array $decoded): string {
    $texto = '';

    foreach ($decoded['output'] ?? [] as $item) {
      if (($item['type'] ?? '') !== 'message') {
        continue;
      }

      foreach ($item['content'] ?? [] as $parte) {
        $texto .= (string) ($parte['text'] ?? '');
      }
    }

    return $texto;
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
   * Anota el consumo de una llamada.
   *
   * Son cifras, no contenido: permiten vigilar el coste sin guardar nada de la
   * conversación (§43).
   *
   * Deposita en el colector, que NO sabe de qué alumno se trata. Esta clase
   * tampoco lo sabe, y no debe: quien conduce la conversación recogerá esto y
   * le pondrá nombre. Si nadie lo recoge, se pierde sin más.
   *
   * Los tokens cacheados se separan a propósito. El proveedor los cobra al
   * 10 %, y en una conversación larga son la mayor parte de la entrada:
   * sumarlos al precio completo multiplica el coste por varias veces. El
   * 07-09-2026, nueve llamadas figuraban como $26 MXN y costaron unos $16.
   *
   * @param array<string, mixed> $payload
   *   Lo que se envió; de aquí sale el modelo.
   * @param string $purpose
   *   Para qué era la llamada, de modo que se distinga qué gastó qué.
   * @param array<string, mixed> $decoded
   *   Respuesta del proveedor. Vacía si no llegó a haberla.
   * @param float $inicio
   *   Marca de microtime al empezar, reintentos incluidos.
   * @param int $intentos
   *   Intentos facturados hasta aquí. El reintento se paga.
   * @param string $error
   *   Vacío si terminó bien.
   */
  private function registrar(array $payload, string $purpose, array $decoded, float $inicio, int $intentos, string $error): void {
    $usage = is_array($decoded['usage'] ?? NULL) ? $decoded['usage'] : [];

    $llamada = new AiCall(
      model: (string) ($payload['model'] ?? ''),
      purpose: $purpose,
      inputTokens: (int) ($usage['input_tokens'] ?? 0),
      cachedInputTokens: (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
      outputTokens: (int) ($usage['output_tokens'] ?? 0),
      reasoningTokens: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
      latencyMs: (int) round((microtime(TRUE) - $inicio) * 1000),
      attempts: $intentos,
      error: $error,
    );

    $this->usage->record($llamada);

    if ($usage === []) {
      return;
    }

    $this->logger->info('@purpose. Tokens: entrada @in (@cached cacheados), salida @out (razonamiento @reasoning).', [
      '@purpose' => $purpose,
      '@in' => $llamada->inputTokens,
      '@cached' => $llamada->cachedInputTokens,
      '@out' => $llamada->outputTokens,
      '@reasoning' => $llamada->reasoningTokens,
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
   * Cuántas veces puede pedir herramientas el modelo en un mismo turno.
   *
   * Configurable porque es un techo de gasto disfrazado: cada vuelta es una
   * llamada al proveedor y, si hay búsqueda, varias búsquedas más. Al sondear
   * el ciclo, el modelo pidió dos búsquedas adicionales nada más recibir la
   * primera; sin tope, un solo turno se convierte en una factura.
   */
  private function getMaxToolRounds(): int {
    $value = (int) $this->config()->get('search.max_tool_rounds');

    return $value > 0 ? min($value, 20) : 4;
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
