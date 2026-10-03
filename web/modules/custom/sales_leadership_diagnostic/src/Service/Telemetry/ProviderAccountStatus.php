<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Telemetry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Recuerda qué proveedor se quedó sin servicio por su cuenta, y desde cuándo.
 *
 * Existe por lo que se vio el 02-10-2026 al agotarse el saldo de la cuenta de
 * pruebas de OpenAI: el alumno recibía «intenta nuevamente» —que nunca iba a
 * funcionar—, el registro decía «el proveedor está limitando las peticiones»
 * —que era falso— y nadie más se enteraba. Con el buscador era peor: sin
 * créditos en Tavily el agente seguía, declaraba que no pudo investigar y
 * entregaba un Pack vacío, que es exactamente el fallo de aquella mañana.
 *
 * Un problema de CUENTA —sin saldo, sin créditos, clave rechazada— no se
 * arregla reintentando ni esperando: lo arregla una persona recargando o
 * cambiando la clave. Así que lo que hace falta es que esa persona lo sepa en
 * cuanto pasa. Esto lo deja anotado para que lo muestren el informe de estado
 * y la pantalla de consumo, y se borra solo en cuanto el proveedor vuelve a
 * responder bien: nadie tiene que acordarse de quitar el aviso.
 */
final class ProviderAccountStatus {

  /**
   * El modelo de lenguaje.
   */
  public const IA = 'openai';

  /**
   * El buscador web.
   */
  public const BUSCADOR = 'tavily';

  /**
   * Dónde se guarda.
   *
   * En State y no en configuración: es un hecho que cambia solo, no un ajuste,
   * y no debe viajar en un `config:export`.
   */
  private const CLAVE = 'sales_leadership_diagnostic.proveedores_sin_servicio';

  /**
   * Donde queda constancia del corte y de la vuelta.
   */
  private readonly LoggerChannelInterface $logger;

  public function __construct(
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Anota que un proveedor no da servicio por un problema de su cuenta.
   *
   * Conserva la hora de la PRIMERA vez: «sin saldo desde las 19:55» dice
   * cuánto lleva cortado, y eso es lo que decide la urgencia. Solo se escribe
   * y se registra al empezar el corte, no en cada llamada que falla durante
   * él, que con varios alumnos serían decenas por minuto.
   */
  public function markUnavailable(string $proveedor, string $motivo): void {
    $cortes = $this->cortes();

    if (isset($cortes[$proveedor])) {
      return;
    }

    $cortes[$proveedor] = [
      'motivo' => $motivo,
      'desde' => $this->time->getRequestTime(),
    ];
    $this->state->set(self::CLAVE, $cortes);

    $this->logger->critical('proveedor_sin_servicio: @proveedor — @motivo. Los alumnos no pueden usar el agente hasta que se resuelva.', [
      '@proveedor' => $proveedor,
      '@motivo' => $motivo,
    ]);
  }

  /**
   * Anota que un proveedor ha vuelto a responder bien.
   *
   * Se llama en cada respuesta correcta, así que lo normal es que no haya nada
   * que borrar: en ese caso no se escribe, para no tocar la base en cada
   * llamada.
   */
  public function markAvailable(string $proveedor): void {
    $cortes = $this->cortes();

    if (!isset($cortes[$proveedor])) {
      return;
    }

    $desde = (int) $cortes[$proveedor]['desde'];
    unset($cortes[$proveedor]);
    $this->state->set(self::CLAVE, $cortes);

    $this->logger->notice('proveedor_recuperado: @proveedor vuelve a responder tras @minutos minutos sin servicio.', [
      '@proveedor' => $proveedor,
      '@minutos' => (int) round(($this->time->getRequestTime() - $desde) / 60),
    ]);
  }

  /**
   * Los proveedores sin servicio ahora mismo.
   *
   * @return array<string, array{motivo: string, desde: int}>
   *   Por proveedor, el motivo y desde cuándo. Vacío si todo responde.
   */
  public function problems(): array {
    return $this->cortes();
  }

  /**
   * Si un proveedor concreto está sin servicio.
   */
  public function isUnavailable(string $proveedor): bool {
    return isset($this->cortes()[$proveedor]);
  }

  /**
   * Lo guardado, con una forma fiable aunque alguien lo haya tocado a mano.
   *
   * @return array<string, array{motivo: string, desde: int}>
   *   Los cortes anotados.
   */
  private function cortes(): array {
    $guardado = $this->state->get(self::CLAVE, []);

    return is_array($guardado) ? $guardado : [];
  }

}
