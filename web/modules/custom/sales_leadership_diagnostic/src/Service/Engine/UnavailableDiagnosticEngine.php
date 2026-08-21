<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Exception\EngineException;

/**
 * Motor que siempre falla, para cuando no hay ninguno configurado.
 *
 * Es el objeto nulo del sistema y existe para que la ausencia de proveedor sea
 * un fallo explícito y no una rama de código que alguien olvide comprobar. El
 * alumno recibe el aviso neutro de siempre; el log recoge la causa real.
 */
final class UnavailableDiagnosticEngine implements DiagnosticEngineInterface {

  /**
   * {@inheritdoc}
   */
  public function process(DiagnosticContext $context): DiagnosticTurn {
    throw new EngineException('No hay ningún proveedor de IA configurado. Configure el proveedor real o habilite el motor simulado en settings.php.');
  }

}
