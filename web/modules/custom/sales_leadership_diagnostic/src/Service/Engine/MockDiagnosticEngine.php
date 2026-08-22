<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;

/**
 * Motor simulado, para desarrollo y pruebas.
 *
 * Permite ejercitar el circuito completo —bloqueo, límites, persistencia,
 * renderizado, cierre y resultado— sin depender de un proveedor externo ni
 * gastar en llamadas reales. Los tests tampoco deben depender de la red (§48).
 *
 * NO se activa por defecto. Requiere declararlo explícitamente en settings.php:
 *
 * @code
 * $settings['sld_use_mock_engine'] = TRUE;
 * @endcode
 *
 * La razón es de seguridad de producto: un despliegue mal configurado que
 * cayera en silencio sobre este motor entregaría diagnósticos inventados a
 * alumnos reales, y nada en la interfaz lo delataría. Por eso el módulo
 * prefiere fallar de forma visible a funcionar con datos falsos.
 *
 * Sus textos van marcados como simulados a propósito: si alguna vez aparecen en
 * un entorno real, deben ser inmediatamente reconocibles.
 */
final class MockDiagnosticEngine implements DiagnosticEngineInterface {

  /**
   * Preguntas del guion simulado.
   *
   * @var string[]
   */
  private const SCRIPT = [
    "**[Simulación]** Gracias. Ahora cuéntame cómo gestionáis el **forecast**: ¿con qué frecuencia se revisa y quién participa?",
    "**[Simulación]** Entendido. ¿Qué criterios usáis hoy para decidir que una oportunidad avanza de etapa?",
    "**[Simulación]** Última pregunta. ¿Cómo se acompaña a un vendedor cuyo desempeño está por debajo del objetivo?",
  ];

  public function __construct(
    private readonly DiagnosticResponseValidator $validator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(DiagnosticContext $context): DiagnosticTurn {
    // El guion se agota, o el tope de turnos obliga a cerrar.
    $step = $this->countAssistantTurns($context);

    if ($step >= count(self::SCRIPT) || $context->isFinalTurn()) {
      return $this->validator->validate($this->buildResult($context));
    }

    return $this->validator->validate([
      'type' => 'diagnostic_response',
      'message' => self::SCRIPT[$step],
      'status' => 'in_progress',
      'next_step' => 'question_' . ($step + 2),
      'result' => NULL,
    ]);
  }

  /**
   * Cuántos turnos ha producido ya el agente en esta conversación.
   */
  private function countAssistantTurns(DiagnosticContext $context): int {
    $count = 0;

    foreach ($context->history as $message) {
      if ($message->role->value === 'assistant') {
        $count++;
      }
    }

    // El mensaje de apertura no forma parte del guion de preguntas.
    return max(0, $count - 1);
  }

  /**
   * Construye un resultado final simulado.
   *
   * @return array<string, mixed>
   *   Un resultado final simulado, con todas sus secciones.
   */
  private function buildResult(DiagnosticContext $context): array {
    return [
      'type' => 'diagnostic_result',
      'status' => 'completed',
      'message' => "**[Simulación]** Hemos terminado tu diagnóstico.\n\n"
      . "### Resumen\n\n"
      . "Tu estructura comercial está definida, pero el proceso depende en exceso de criterio individual.\n\n"
      . "Puedes consultar el detalle completo en tu historial.",
      'result' => [
        'summary' => '[Simulación] Estructura comercial definida con dependencia excesiva del criterio individual en la gestión del pipeline.',
        'score' => 62,
        'strengths' => [
          '[Simulación] Estructura de equipo clara y con tramos de control razonables.',
          '[Simulación] Cadencia de reunión comercial ya establecida.',
        ],
        'opportunities' => [
          '[Simulación] Ausencia de criterios objetivos de avance de etapa.',
          '[Simulación] Forecast sin contraste entre gerentes.',
        ],
        'recommendations' => [
          '[Simulación] Definir criterios de salida por etapa del pipeline.',
          '[Simulación] Introducir una revisión cruzada del forecast.',
        ],
        'priority_actions' => [
          '[Simulación] Documentar los criterios de avance en las próximas dos semanas.',
        ],
        'diagnostic_version' => $context->diagnosticVersion,
      ],
    ];
  }

}
