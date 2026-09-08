<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine\Tool;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Exception\SpendLimitException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Telemetry\SpendGuard;

/**
 * Deja pasar o no cada llamada a herramienta, y deja constancia de ambas cosas.
 *
 * Lo exige el §6 de la especificación del cliente, y con esas palabras: «todas
 * las búsquedas externas pasan por un gateway backend; nunca dar acceso directo
 * no medido. **Antes de cada tool call** validar user_id, mission_id, estado,
 * clasificación del turno, presupuesto y límites».
 *
 * Envuelve la caja de herramientas en lugar de meterse dentro de cada una. Así
 * una herramienta nueva nace controlada sin que nadie tenga que acordarse: no
 * hay forma de llegar a ella sin pasar por aquí.
 *
 * ## Por qué hay dos topes y no uno
 *
 * El de la misión solo no sirve. La propia especificación nombra el agujero:
 * «evitar bypass por nueva conversación, nuevo dispositivo, Entry Mode
 * distinto». Un tope por misión se salta abriendo otra conversación, así que
 * hay además un tope **por persona y periodo** que ninguna conversación nueva
 * reinicia.
 *
 * ## Qué se le dice al modelo cuando se le niega
 *
 * Una negativa no es un error: es una respuesta que el modelo tiene que poder
 * leer y entender. Se le dice que no puede buscar y **que declare la
 * limitación en lugar de inventar**, que es exactamente lo que manda su propia
 * metodología. Devolverle una lista vacía le haría creer que buscó y no
 * encontró nada, y con eso cerraría una misión como ZERO-GOLD sin haber
 * mirado.
 */
final class ToolGateway implements ToolRunnerInterface {

  /**
   * Motivos de denegación. Se guardan tal cual para poder contarlos.
   */
  private const SIN_TURNO = 'sin_turno';
  private const PRESUPUESTO = 'presupuesto';
  private const TOPE_MISION = 'tope_llamadas_mision';
  private const TOPE_TEXTO = 'tope_texto_mision';
  private const TOPE_PERIODO = 'tope_llamadas_periodo';

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ToolRunnerInterface $tools,
    private readonly CurrentTurn $turn,
    private readonly ToolCallRepository $calls,
    private readonly SpendGuard $spend,
    private readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return $this->tools->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function declarations(): array {
    return $this->tools->declarations();
  }

  /**
   * {@inheritdoc}
   */
  public function run(string $name, array $arguments): string {
    $consulta = (string) ($arguments['consulta'] ?? '');
    $motivo = $this->denyReason();

    if ($motivo !== NULL) {
      return $this->deny($name, $consulta, $motivo);
    }

    $inicio = microtime(TRUE);
    $salida = $this->tools->run($name, $arguments);
    $latencia = (int) round((microtime(TRUE) - $inicio) * 1000);

    $this->calls->record(
      uid: $this->turn->uid(),
      sessionId: $this->turn->sessionId(),
      tool: $name,
      query: $consulta,
      allowed: TRUE,
      results: $this->countResults($salida),
      // Lo que se mide es la longitud de lo que ENTRA al modelo, no lo que
      // devolvió el buscador. Es lo que se paga y lo que el §9 pide acotar.
      retrievedChars: strlen($salida),
      latencyMs: $latencia,
      isSandbox: $this->turn->isSandbox(),
    );

    return $salida;
  }

  /**
   * Por qué NO se puede llamar, o NULL si se puede.
   *
   * El orden importa: primero lo que no depende de contar nada, y al final las
   * consultas a la base. No tiene sentido sumar el consumo de una misión para
   * alguien a quien ya se va a denegar por no tener turno.
   */
  private function denyReason(): ?string {
    // Sin turno declarado no se sabe a cuenta de quién sería la búsqueda, y
    // una búsqueda anónima no se puede acotar ni auditar. Se deniega: es
    // preferible una que no ocurre a una que ocurre sin dueño.
    if (!$this->turn->isSet()) {
      return self::SIN_TURNO;
    }

    try {
      $this->spend->assertCanSpend($this->turn->uid());
    }
    catch (SpendLimitException) {
      return self::PRESUPUESTO;
    }

    $usado = $this->calls->usedInMission($this->turn->sessionId());
    $topeLlamadas = $this->cap('max_calls_per_mission');
    $topeTexto = $this->cap('max_retrieved_chars_per_mission');

    if ($topeLlamadas > 0 && $usado['calls'] >= $topeLlamadas) {
      return self::TOPE_MISION;
    }

    if ($topeTexto > 0 && $usado['chars'] >= $topeTexto) {
      return self::TOPE_TEXTO;
    }

    // Los ensayos del gestor no gastan cupo de nadie, así que tampoco se les
    // aplica el tope por persona.
    $topePeriodo = $this->cap('max_calls_per_user_period');

    if ($topePeriodo > 0 && !$this->turn->isSandbox()) {
      $usadas = $this->calls->usedByUserSince($this->turn->uid(), $this->spend->periodStart());

      if ($usadas >= $topePeriodo) {
        return self::TOPE_PERIODO;
      }
    }

    return NULL;
  }

  /**
   * Deniega, lo deja anotado y le explica al modelo qué hacer.
   */
  private function deny(string $name, string $consulta, string $motivo): string {
    $this->calls->record(
      uid: $this->turn->uid(),
      sessionId: $this->turn->sessionId(),
      tool: $name,
      query: $consulta,
      allowed: FALSE,
      denialReason: $motivo,
      isSandbox: $this->turn->isSandbox(),
    );

    // `policy_denial` es el nombre que le da el §14 de la especificación. Se
    // registra siempre: es lo que permite ver que el gateway está haciendo
    // algo y distinguir un agente que no quiso buscar de uno al que no se le
    // dejó.
    $this->logger->warning('policy_denial: @tool denegada para el alumno @uid en la misión @sesion (@motivo).', [
      '@tool' => $name,
      '@uid' => $this->turn->uid(),
      '@sesion' => $this->turn->sessionId(),
      '@motivo' => $motivo,
    ]);

    return (string) json_encode([
      'error' => 'BUSQUEDA NO AUTORIZADA',
      'motivo' => $this->explain($motivo),
      'que_hacer' => 'NO inventes resultados ni supongas lo que habrías encontrado. Declara explícitamente que esta investigación no pudo realizarse, sigue con lo que no dependa de ella y marca como UNKNOWN o CHECK REQUIRED lo que quede sin verificar.',
    ], JSON_UNESCAPED_UNICODE);
  }

  /**
   * El motivo, en algo que el modelo pueda leer.
   */
  private function explain(string $motivo): string {
    return match ($motivo) {
      self::SIN_TURNO => 'La búsqueda no está asociada a una misión identificada.',
      self::PRESUPUESTO => 'Se agotó el presupuesto del periodo.',
      self::TOPE_MISION => 'Esta misión alcanzó su número máximo de búsquedas.',
      self::TOPE_TEXTO => 'Esta misión alcanzó el máximo de contenido externo que puede incorporar.',
      self::TOPE_PERIODO => 'Se alcanzó el máximo de búsquedas del periodo para este usuario.',
      default => 'No autorizada.',
    };
  }

  /**
   * Cuántos resultados trajo, para poder contarlos.
   *
   * Es una lectura de conveniencia y por eso no falla: si la herramienta
   * devolviera algo con otra forma, se anota cero en lugar de reventar una
   * búsqueda que sí ocurrió.
   */
  private function countResults(string $salida): int {
    $decoded = json_decode($salida, TRUE);

    return is_array($decoded) && is_array($decoded['resultados'] ?? NULL)
      ? count($decoded['resultados'])
      : 0;
  }

  /**
   * Un tope configurado. Cero significa sin tope.
   */
  private function cap(string $clave): int {
    return max(0, (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('tools.' . $clave));
  }

}
