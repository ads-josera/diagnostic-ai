<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Telemetry;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\sales_leadership_diagnostic\Exception\SpendLimitException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Impide que una llamada ocurra cuando el presupuesto se agotó.
 *
 * La palabra que importa es «impide». No avisa después ni deja constancia de
 * lo gastado: se consulta ANTES de llamar al proveedor, y si no hay margen la
 * llamada no llega a hacerse. Registrar el exceso a posteriori no es un tope,
 * es un informe de daños.
 *
 * Tres escalones, porque cortar de golpe al llegar al 100 % es la peor forma
 * de gastar un presupuesto:
 *
 *  - **80 %**: se avisa a quien administra, una vez por periodo. El alumno no
 *    nota nada.
 *  - **100 % individual**: ese alumno no inicia turnos nuevos. Sus resultados
 *    anteriores siguen consultables.
 *  - **100 % global**: no se inician turnos nuevos para nadie. Es la única
 *    defensa contra un caso raro que se dispare de madrugada.
 *
 * El periodo es el MES NATURAL y no una ventana móvil de treinta días. Es como
 * razona quien paga —«cien pesos por alumno al mes»— y, sobre todo, se
 * reinicia en una fecha que se puede decir. Con una ventana móvil, quien gastó
 * de más queda bloqueado sin una fecha clara en la que deje de estarlo.
 *
 * Los dos topes vienen en DÓLARES, que es lo que cobra el proveedor. Cero
 * significa sin tope, que es el comportamiento que el módulo ha tenido siempre:
 * activar un límite de fábrica cortaría el servicio en instalaciones que nunca
 * pidieron uno.
 */
final class SpendGuard {

  /**
   * Nombre del objeto de configuración.
   */
  private const CONFIG_NAME = 'sales_leadership_diagnostic.settings';

  /**
   * Prefijo de la marca de «ya avisé de este alumno este mes».
   */
  private const AVISADO = 'sld.spend_warned.';

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly AiUsageRepository $usage,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Comprueba que este alumno puede provocar otra llamada.
   *
   * Se llama ANTES de hablar con el proveedor. Comprueba el tope global y el
   * suyo, y de paso avisa si acaba de cruzar el 80 %.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\SpendLimitException
   *   Si no queda margen. El mensaje NO lleva cifras: quien lo lee es el
   *   alumno, y lo que gasta la instalación no es asunto suyo (§43).
   */
  public function assertCanSpend(int $uid): void {
    $this->assertGlobalHeadroom();

    $tope = $this->userLimit();

    if ($tope <= 0) {
      return;
    }

    $gastado = $this->usage->costForUser($uid, $this->periodStart());

    if ($gastado >= $tope) {
      $this->logger->warning('Alumno @uid bloqueado: lleva @gasto USD de un tope de @tope USD este periodo.', [
        '@uid' => $uid,
        '@gasto' => number_format($gastado, 4),
        '@tope' => number_format($tope, 2),
      ]);

      throw new SpendLimitException('El alumno agotó su presupuesto del periodo.');
    }

    $this->warnIfNear($uid, $gastado, $tope);
  }

  /**
   * Comprueba solo el tope global.
   *
   * Va aparte porque NO necesita saber de quién es la llamada, y por eso puede
   * comprobarse en el punto por donde pasa todo, incluidas las llamadas que no
   * pertenecen a ningún alumno. Es la red que sigue puesta aunque un camino
   * nuevo se olvide de comprobar el tope individual.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\SpendLimitException
   *   Si la instalación agotó su presupuesto.
   */
  public function assertGlobalHeadroom(): void {
    $tope = $this->globalLimit();

    if ($tope <= 0) {
      return;
    }

    $gastado = $this->usage->costGlobal($this->periodStart());

    if ($gastado >= $tope) {
      $this->logger->error('TOPE GLOBAL alcanzado: @gasto USD de @tope USD. No se inician turnos nuevos.', [
        '@gasto' => number_format($gastado, 4),
        '@tope' => number_format($tope, 2),
      ]);

      throw new SpendLimitException('La instalación agotó su presupuesto del periodo.');
    }
  }

  /**
   * Cómo va de presupuesto un alumno, para enseñarlo.
   *
   * @return array{limit: float, spent: float, percent: int, blocked: bool}|null
   *   NULL si no hay tope configurado, que es distinto de estar al 0 %.
   */
  public function statusForUser(int $uid): ?array {
    $tope = $this->userLimit();

    if ($tope <= 0) {
      return NULL;
    }

    $gastado = $this->usage->costForUser($uid, $this->periodStart());

    return [
      'limit' => $tope,
      'spent' => $gastado,
      'percent' => (int) round($gastado / $tope * 100),
      'blocked' => $gastado >= $tope,
    ];
  }

  /**
   * Cómo va de presupuesto la instalación.
   *
   * @return array{limit: float, spent: float, percent: int, blocked: bool}|null
   *   NULL si no hay tope configurado.
   */
  public function statusGlobal(): ?array {
    $tope = $this->globalLimit();

    if ($tope <= 0) {
      return NULL;
    }

    $gastado = $this->usage->costGlobal($this->periodStart());

    return [
      'limit' => $tope,
      'spent' => $gastado,
      'percent' => (int) round($gastado / $tope * 100),
      'blocked' => $gastado >= $tope,
    ];
  }

  /**
   * Principio del periodo en curso.
   *
   * Mes natural, en la zona horaria del sitio: si el servidor está en UTC y el
   * cliente en México, el corte a medianoche no cae donde él lo espera.
   */
  public function periodStart(): int {
    $zona = $this->configFactory->get('system.date')->get('timezone.default') ?: 'UTC';
    $ahora = new \DateTimeImmutable('@' . $this->time->getRequestTime());

    return (int) $ahora
      ->setTimezone(new \DateTimeZone($zona))
      ->modify('first day of this month')
      ->setTime(0, 0)
      ->getTimestamp();
  }

  /**
   * Avisa UNA vez por periodo cuando un alumno cruza el umbral.
   *
   * Una vez, no en cada turno: un aviso que se repite treinta veces se deja de
   * leer, y entonces el que importa pasa desapercibido.
   */
  private function warnIfNear(int $uid, float $gastado, float $tope): void {
    $umbral = $this->warnPercent();

    if ($umbral <= 0 || $gastado < $tope * $umbral / 100) {
      return;
    }

    $clave = self::AVISADO . $uid . '.' . $this->periodStart();

    if ($this->state->get($clave) !== NULL) {
      return;
    }

    $this->state->set($clave, TRUE);

    $this->logger->warning('Alumno @uid al @pct % de su presupuesto (@gasto de @tope USD).', [
      '@uid' => $uid,
      '@pct' => (int) round($gastado / $tope * 100),
      '@gasto' => number_format($gastado, 4),
      '@tope' => number_format($tope, 2),
    ]);
  }

  /**
   * Tope por alumno y periodo, en dólares. Cero es sin tope.
   */
  public function userLimit(): float {
    return max(0.0, (float) $this->config()->get('spending.per_user_limit'));
  }

  /**
   * Tope de la instalación por periodo, en dólares. Cero es sin tope.
   */
  public function globalLimit(): float {
    return max(0.0, (float) $this->config()->get('spending.global_limit'));
  }

  /**
   * Porcentaje a partir del cual se avisa.
   */
  private function warnPercent(): int {
    $valor = (int) $this->config()->get('spending.warn_at_percent');

    return $valor > 0 && $valor < 100 ? $valor : 80;
  }

  /**
   * Configuración del módulo.
   */
  private function config() {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
