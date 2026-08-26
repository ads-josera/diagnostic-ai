<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;

/**
 * Motor de diagnóstico sobre la API de OpenAI (§28, §29).
 *
 * Lo que queda aquí es la traducción entre el mundo del diagnóstico y el del
 * proveedor: qué mensajes se envían, qué forma debe tener la respuesta y cómo
 * se valida. La fontanería —endpoint, credenciales, reintentos, errores y
 * registro de consumo— vive en OpenAIClient, que la comparte con las demás
 * llamadas del módulo.
 *
 * Añadir otro proveedor será escribir otra implementación de la interfaz y
 * cambiar una línea en la fábrica.
 */
final class OpenAIDiagnosticProvider implements DiagnosticEngineInterface {

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
   *
   * @var array<string, mixed>
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

  public function __construct(
    private readonly OpenAIClient $client,
    private readonly DiagnosticResponseValidator $validator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(DiagnosticContext $context): DiagnosticTurn {
    $raw = $this->client->completeJson(
      $this->buildMessages($context),
      'diagnostic_turn',
      self::RESPONSE_SCHEMA,
      'Turno generado',
    );

    return $this->validator->validate($raw);
  }

  /**
   * Construye la conversación que se envía al modelo.
   *
   * @return array<int, array{role: string, content: string}>
   *   Mensajes en el formato del proveedor.
   */
  private function buildMessages(DiagnosticContext $context): array {
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

    return $messages;
  }

}
