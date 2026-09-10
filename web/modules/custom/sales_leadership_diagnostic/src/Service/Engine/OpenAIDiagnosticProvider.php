<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolBoxFactory;

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
          // Banda de madurez y confianza globales. Son parte del informe del
          // cliente y hasta el 26-08-2026 no tenían campo: se colaban dentro
          // del resumen en prosa, donde no se pueden consultar ni comparar.
          'maturity' => ['type' => 'string'],
          'confidence' => ['type' => 'string'],
          // Puntuación dimensión a dimensión. Es el corazón de la metodología
          // del cliente —diez dimensiones con su nivel y su confianza— y era
          // lo único de su informe que se perdía por completo: quedaba en la
          // conversación como prosa y en ningún sitio consultable.
          'dimensions' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'properties' => [
                'name' => ['type' => 'string'],
                // Decimal a propósito: la metodología del cliente usa medios
                // puntos (un 7.5 aparece en sus propios ejemplos).
                'score' => ['type' => 'number'],
                'max' => ['type' => 'number'],
                'level' => ['type' => 'string'],
                'confidence' => ['type' => 'string'],
              ],
              'required' => ['name', 'score', 'max', 'level', 'confidence'],
              'additionalProperties' => FALSE,
            ],
          ],
          'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
          'opportunities' => ['type' => 'array', 'items' => ['type' => 'string']],
          // Cuántas cuentas candidatas se cribaron. Su metodología pide un
          // «pool auditable», y auditable quiere decir que la cifra se pueda
          // contrastar con las cuentas de abajo en vez de creerla.
          'pool_declared' => ['type' => 'integer'],
          // El Weekly GOLD Pack, cuenta por cuenta.
          //
          // Hasta el 10-09-2026 el Pack solo existía como prosa: cada cuenta
          // era una línea dentro de `opportunities`, y por eso no se podía
          // listar, ni filtrar, ni comprobar, ni seguir de una semana a otra.
          // Es el mismo problema que tenían las dimensiones del otro agente y
          // se resuelve igual: dándole campos.
          //
          // Los nombres salen de su propio vocabulario —la línea del CORE que
          // enumera el Pack— para que el agente los reconozca sin traducir
          // nada.
          'accounts' => [
            'type' => 'array',
            'items' => [
              'type' => 'object',
              'properties' => [
                // Nominal. Una cuenta sin nombre no es una cuenta: es un
                // hueco, y su A11 existe justamente para impedirlos.
                'name' => ['type' => 'string'],
                // Su clasificación: GOLD, SILVER, NURTURE, WATCH, DESCARTAR.
                'disposition' => ['type' => 'string'],
                // Posición en el Opportunity/Execution Ranking. Cero si la
                // cuenta no entra en el ranking.
                'rank' => ['type' => 'integer'],
                // RELEASED, PREPARED o BLOCKED. Es lo que su A12 llama
                // «copy/paste o bloqueo», y lo que decide si alguien puede
                // enviar hoy.
                'outreach_status' => ['type' => 'string'],
                'blocked_reason' => ['type' => 'string'],
                'why_now' => ['type' => 'string'],
                'gap_hypothesis' => ['type' => 'string'],
                // La alternativa que compite con la tesis. Su metodología la
                // exige, y es lo que separa una hipótesis de una afirmación.
                'competing_alternative' => ['type' => 'string'],
                'buyer' => ['type' => 'string'],
                'buyer_verified' => ['type' => 'boolean'],
                'do_not_claim' => ['type' => 'array', 'items' => ['type' => 'string']],
                'routing' => ['type' => 'string'],
                // El mensaje listo para copiar. Vacío si está bloqueada, y esa
                // combinación es la que comprueba su A10.
                'outreach_message' => ['type' => 'string'],
                'next_step' => ['type' => 'string'],
                'sources' => [
                  'type' => 'array',
                  'items' => [
                    'type' => 'object',
                    'properties' => [
                      'url' => ['type' => 'string'],
                      'label' => ['type' => 'string'],
                      // Fecha de publicación, si la fuente la trae. Sin ella
                      // un why-now no se puede fechar, y un catalyst sin fecha
                      // no es un catalyst.
                      'published' => ['type' => 'string'],
                    ],
                    'required' => ['url', 'label', 'published'],
                    'additionalProperties' => FALSE,
                  ],
                ],
              ],
              'required' => [
                'name',
                'disposition',
                'rank',
                'outreach_status',
                'blocked_reason',
                'why_now',
                'gap_hypothesis',
                'competing_alternative',
                'buyer',
                'buyer_verified',
                'do_not_claim',
                'routing',
                'outreach_message',
                'next_step',
                'sources',
              ],
              'additionalProperties' => FALSE,
            ],
          ],
          'risks' => ['type' => 'array', 'items' => ['type' => 'string']],
          'missing_evidence' => ['type' => 'array', 'items' => ['type' => 'string']],
          'recommendations' => ['type' => 'array', 'items' => ['type' => 'string']],
          'priority_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
        // El modo estricto exige declarar TODAS las propiedades como
        // requeridas. Las que no apliquen se devuelven vacías.
        'required' => [
          'summary',
          'score',
          'maturity',
          'confidence',
          'dimensions',
          'strengths',
          'opportunities',
          'pool_declared',
          'accounts',
          'risks',
          'missing_evidence',
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
    private readonly ToolBoxFactory $tools,
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
      NULL,
      // Qué herramientas hay lo decide la fábrica, no el motor. Aquí solo se
      // le pasan: el día que el Research Entitlement gobierne quién puede
      // buscar y cuándo, esta línea no cambia.
      $this->tools->forTurn(),
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

    // El bloque de capacidad va DETRÁS del prompt y no al final de la
    // conversación: el prompt del cliente razona con él desde su primera
    // decisión, y verlo al final llegaría tarde.
    //
    // Cuesta algo: cuando el estado cambia a mitad de misión —de AVAILABLE a
    // ACTIVE en la primera búsqueda— cambia el prefijo y se pierde la caché de
    // ese turno. Una vez por misión, unos veinte centavos de dólar. Se paga a
    // gusto: la alternativa es que el agente decida sin saber qué puede hacer.
    if ($context->researchRuntime !== '') {
      $messages[] = ['role' => 'system', 'content' => $context->researchRuntime];
    }

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
