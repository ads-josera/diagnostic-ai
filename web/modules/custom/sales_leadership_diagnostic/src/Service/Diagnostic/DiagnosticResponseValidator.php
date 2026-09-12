<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Diagnostic;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Valida y normaliza la respuesta estructurada del motor (§32).
 *
 * Se valida en el servidor aunque el proveedor garantice el formato. Esa
 * garantía es de terceros y puede fallar por un cambio de modelo, una
 * degradación del servicio o un prompt que la contradiga; almacenar sin
 * comprobar significaría descubrirlo al mostrar un resultado corrupto al
 * alumno, cuando ya es tarde.
 *
 * El límite de longitud existe porque el texto acaba en la base de datos y en
 * la pantalla: una respuesta desbocada no debe poder llenar ninguna de las dos.
 */
final class DiagnosticResponseValidator {

  /**
   * Longitud máxima admitida para el mensaje conversacional.
   */
  private const MAX_MESSAGE_LENGTH = 20000;

  /**
   * Tipos de turno reconocidos.
   */
  private const TYPE_RESPONSE = 'diagnostic_response';
  private const TYPE_RESULT = 'diagnostic_result';

  /**
   * Descuadre admitido entre el global y la suma de dimensiones.
   *
   * Medio punto: la rúbrica del cliente usa medios puntos por dimensión, y
   * nuestro campo de puntuación es entero, así que un global de 20,5 se guarda
   * como 21 sin que nadie se haya equivocado. Por encima de eso ya no es
   * redondeo.
   */
  private const TOLERANCIA_ARITMETICA = 0.5;

  /**
   * Estados de envío que solo tienen sentido con un mensaje escrito.
   *
   * Los dos, y no solo PREPARED: si PREPARED sin mensaje no vale, RELEASED sin
   * mensaje vale menos todavía —dice que se puede enviar YA—. Su A10 nombra
   * uno; taparlo solo ahí dejaría abierta la puerta más ancha.
   */
  private const ESTADOS_QUE_EXIGEN_MENSAJE = ['PREPARED', 'RELEASED'];

  /**
   * Estado al que se corrige una cuenta que no puede sostener el suyo.
   */
  private const BLOQUEADA = 'BLOCKED';

  /**
   * Canal de log del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Valida la respuesta cruda y la convierte en un turno.
   *
   * @param array<string, mixed> $raw
   *   Respuesta del motor, ya decodificada.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException
   */
  public function validate(array $raw): DiagnosticTurn {
    $type = $this->readString($raw, 'type');

    if (!in_array($type, [self::TYPE_RESPONSE, self::TYPE_RESULT], TRUE)) {
      throw new InvalidEngineResponseException(sprintf(
        'El motor devolvió un tipo de turno desconocido: "%s".',
        // Se acota antes de registrarlo: el valor viene de fuera y acaba en
        // el log.
        mb_substr($type, 0, 40),
      ));
    }

    $message = $this->readString($raw, 'message');

    if (trim($message) === '') {
      throw new InvalidEngineResponseException('El motor devolvió un turno sin mensaje para el alumno.');
    }

    if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
      throw new InvalidEngineResponseException(sprintf(
        'El mensaje del motor supera el máximo admitido (%d caracteres).',
        self::MAX_MESSAGE_LENGTH,
      ));
    }

    $status = $this->readString($raw, 'status');
    $completed = $type === self::TYPE_RESULT || $status === 'completed';

    $result = NULL;

    if ($completed) {
      $result = $this->extractResult($raw);
    }

    // Dar la conversación por terminada SIN resultado es legítimo: el alumno
    // escribe «cerrar» y el agente, correctamente, cierra sin inventarse un
    // Score que no tiene con qué sostener. Lo hizo dos de cada tres veces al
    // reproducir el caso del 11-09-2026.
    //
    // Antes esto era un error, y el error tiraba el mensaje del agente: el
    // alumno veía «No hemos podido procesar tu solicitud» y su conversación
    // quedaba sin respuesta. Ahora se guarda el mensaje y la sesión sigue
    // abierta, que es lo que el propio agente ofrece —«cuando quieras
    // retomarlo»—. Nada se pierde y no se crea un resultado vacío.
    if ($completed && $result === NULL) {
      // Solo el tipo: nunca contenido de la conversación (§43).
      $this->logger->warning('El agente dio la conversación por terminada sin devolver resultado (tipo @type). Se guarda su mensaje y la sesión sigue abierta.', [
        '@type' => $type,
      ]);
      $completed = FALSE;
    }

    if ($completed) {
      $this->comprobarAritmetica($result);

      // Estas dos SÍ modifican el resultado, al contrario que la aritmética.
      // La diferencia no es de estilo: un descuadre de medio punto es una nota
      // al margen, pero una cuenta marcada como lista para enviar sin mensaje
      // que enviar es algo que alguien va a intentar usar.
      $result = $this->bloquearCuentasSinMensaje($result);
      $result = $this->cuadrarElPoolDeclarado($result);
    }

    return new DiagnosticTurn(
      message: $message,
      completed: $completed,
      result: $result,
      raw: $raw,
    );
  }

  /**
   * Comprueba que el global cuadre con la suma de las dimensiones.
   *
   * Lo pide la propia metodología del cliente, que en su control de calidad
   * exige verificar que «la aritmética del Score Global es correcta». Es
   * exactamente el tipo de error que un modelo comete sin avisar y que nadie
   * detecta leyendo el informe, porque las dos cifras están en secciones
   * distintas.
   *
   * NO se rechaza el resultado: un descuadre de un punto no invalida veinte
   * minutos de conversación, y perder el diagnóstico entero sería peor que
   * guardarlo con una nota. Queda en el registro para que se pueda revisar.
   *
   * @param array<string, mixed> $result
   *   Resultado ya extraído.
   */
  private function comprobarAritmetica(array $result): void {
    $global = $result['score'] ?? NULL;
    $dimensiones = $result['dimensions'] ?? NULL;

    // Sin puntuación global o sin dimensiones no hay nada que cuadrar: es el
    // caso de un diagnóstico parcial, que su metodología prohíbe puntuar.
    if (!is_numeric($global) || !is_array($dimensiones) || $dimensiones === []) {
      return;
    }

    $suma = 0.0;

    foreach ($dimensiones as $dimension) {
      if (is_array($dimension) && is_numeric($dimension['score'] ?? NULL)) {
        $suma += (float) $dimension['score'];
      }
    }

    $desvio = abs((float) $global - $suma);

    if ($desvio <= self::TOLERANCIA_ARITMETICA) {
      return;
    }

    $this->logger->warning(
      'El diagnóstico declaró una puntuación global de @global pero sus @n dimensiones suman @suma. Conviene revisarlo: la metodología exige que el global sea la suma.',
      [
        '@global' => $global,
        '@n' => count($dimensiones),
        '@suma' => $suma,
      ],
    );
  }

  /**
   * A10 — una cuenta sin mensaje no puede salir marcada como lista.
   *
   * Su §13 lo pide con estas palabras: «PREPARED sin mensaje: el validador lo
   * corrige a BLOCKED». Y corregir, no avisar: el estado de envío es lo que
   * mira alguien para decidir si manda algo hoy, y una cuenta que dice
   * PREPARED con el mensaje vacío es una promesa que no se puede cumplir.
   *
   * En la misión medida el agente lo hizo bien solo: bloqueó las diez cuentas
   * porque faltaba el chequeo de CRM. Esto no está aquí porque falle, sino
   * porque **el sistema tiene que comprobarlo** en vez de confiar en que salga
   * bien cada vez. Es la diferencia entre un agente que se porta bien y una
   * garantía.
   *
   * @param array<string, mixed> $result
   *   Resultado ya extraído.
   *
   * @return array<string, mixed>
   *   El mismo resultado, con las cuentas incongruentes corregidas.
   */
  private function bloquearCuentasSinMensaje(array $result): array {
    if (!is_array($result['accounts'] ?? NULL)) {
      return $result;
    }

    $corregidas = 0;

    foreach ($result['accounts'] as $i => $cuenta) {
      if (!is_array($cuenta)) {
        continue;
      }

      $estado = strtoupper(trim((string) ($cuenta['outreach_status'] ?? '')));
      $mensaje = trim((string) ($cuenta['outreach_message'] ?? ''));

      if (!in_array($estado, self::ESTADOS_QUE_EXIGEN_MENSAJE, TRUE) || $mensaje !== '') {
        continue;
      }

      $result['accounts'][$i]['outreach_status'] = self::BLOQUEADA;
      $motivo = trim((string) ($cuenta['blocked_reason'] ?? ''));
      $result['accounts'][$i]['blocked_reason'] = $motivo !== ''
        ? $motivo
        : 'Sin mensaje preparado que enviar.';

      $corregidas++;
    }

    if ($corregidas > 0) {
      $this->logger->warning(
        '@n cuenta(s) venían marcadas como listas para enviar sin mensaje que enviar. Se han bloqueado.',
        ['@n' => $corregidas],
      );
    }

    return $result;
  }

  /**
   * A11 — el pool declarado no puede ser mayor que las cuentas con nombre.
   *
   * Su §13 lo pide así: «Pool declarado ≥10: el validador exige cuentas
   * nominales». Declarar que se cribaron diez y nombrar tres no es un pool
   * auditable: es una cifra que nadie puede contrastar, y su metodología
   * insiste en que el pool lo sea.
   *
   * No se rechaza la misión ni se borra nada. Se baja la cifra a lo que se
   * puede sostener y **se conserva lo que el agente declaró** en
   * `pool_claimed`, porque la diferencia entre lo dicho y lo sostenido es
   * justo el dato que hace falta para saber si el agente está inflando.
   *
   * @param array<string, mixed> $result
   *   Resultado ya extraído.
   *
   * @return array<string, mixed>
   *   El mismo resultado, con el pool cuadrado.
   */
  private function cuadrarElPoolDeclarado(array $result): array {
    if (!isset($result['pool_declared'])) {
      return $result;
    }

    $declarado = (int) $result['pool_declared'];
    $cuentas = is_array($result['accounts'] ?? NULL) ? $result['accounts'] : [];

    $nominales = 0;

    foreach ($cuentas as $cuenta) {
      if (is_array($cuenta) && trim((string) ($cuenta['name'] ?? '')) !== '') {
        $nominales++;
      }
    }

    if ($declarado <= $nominales) {
      return $result;
    }

    $result['pool_claimed'] = $declarado;
    $result['pool_declared'] = $nominales;

    $this->logger->warning(
      'La misión declaró un pool de @declarado cuentas y solo nombró @nominales. Se ha bajado la cifra a lo que se puede auditar.',
      ['@declarado' => $declarado, '@nominales' => $nominales],
    );

    return $result;
  }

  /**
   * Extrae la estructura del resultado final.
   *
   * La forma definitiva depende de la metodología del cliente (§32), así que
   * aquí solo se exige que exista y sea una estructura. Cuando el cliente
   * entregue su formato, este es el punto donde añadir las comprobaciones
   * concretas.
   *
   * @return array<string, mixed>|null
   *   La estructura del resultado final, tal como la devolvió el motor, o NULL
   *   si no trae ninguno.
   */
  private function extractResult(array $raw): ?array {
    // El resultado puede venir anidado en "result" o al mismo nivel que el
    // mensaje, según cómo lo formule el prompt del cliente. Se admiten ambos.
    if (isset($raw['result']) && is_array($raw['result']) && $raw['result'] !== []) {
      return $raw['result'];
    }

    $inline = array_intersect_key($raw, array_flip([
      'summary',
      'score',
      'strengths',
      'opportunities',
      'recommendations',
      'priority_actions',
    ]));

    if ($inline !== []) {
      return $inline;
    }

    return NULL;
  }

  /**
   * Lee una clave como cadena, tolerando su ausencia.
   */
  private function readString(array $raw, string $key): string {
    $value = $raw[$key] ?? '';

    return is_scalar($value) ? (string) $value : '';
  }

}
