<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Engine;

use Drupal\sales_leadership_diagnostic\Service\Research\SourceRepair;
use Drupal\sales_leadership_diagnostic\Service\Research\BuyerEvidenceCheck;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticContext;
use Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
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
                // La URL que prueba al comprador verificado: la plataforma
                // comprueba que salió de la búsqueda y que lo nombra.
                'buyer_source' => ['type' => 'string'],
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
                'buyer_source',
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

  /**
   * Canal de log del módulo.
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly OpenAIClient $client,
    private readonly DiagnosticResponseValidator $validator,
    private readonly ToolBoxFactory $tools,
    LoggerChannelFactoryInterface $loggerFactory,
    // Opcional para las pruebas que no miran compradores; en la plataforma lo
    // pasa siempre el contenedor.
    private readonly ?BuyerEvidenceCheck $buyers = NULL,
    private readonly ?SourceRepair $sourceRepair = NULL,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * {@inheritdoc}
   */
  public function process(DiagnosticContext $context): DiagnosticTurn {
    $mensajes = $this->buildMessages($context);
    $raw = $this->pedir($mensajes, 'Turno generado');
    $turno = $this->validator->validate($raw);
    $descuadre = $this->validator->arithmeticGap($turno);

    if ($descuadre !== NULL) {
      $turno = $this->corregirDescuadre($mensajes, $raw, $turno, $descuadre);
    }

    return $this->respaldarCompradores($mensajes, $this->repararFuentes($turno));
  }

  /**
   * Devuelve a su dirección real las fuentes que el agente reescribió.
   *
   * El 05-10-2026 una sola misión citó tres páginas con la dirección retocada
   * de memoria, y las tres daban 404. La buena la trajo la búsqueda, así que no
   * hace falta preguntarle al modelo. Va antes de comprobar los compradores:
   * si la fuente de uno era una dirección retocada, se lee ya la buena.
   */
  private function repararFuentes(DiagnosticTurn $turno): DiagnosticTurn {
    if ($this->sourceRepair === NULL || !$turno->completed || !is_array($turno->result)) {
      return $turno;
    }

    $reparado = $this->sourceRepair->forCurrentTurn($turno->result, $turno->message);

    if ($reparado['reparadas'] === []) {
      return $turno;
    }

    // Cuántas, no cuáles: las direcciones son públicas, pero no hace falta.
    $this->logger->info('fuentes_reparadas: @n fuente(s) reescritas de memoria vuelven a la dirección que trajo la búsqueda.', [
      '@n' => count($reparado['reparadas']),
    ]);

    return new DiagnosticTurn($reparado['message'], $turno->completed, $reparado['result'], $turno->raw);
  }

  /**
   * Que cada comprador dado por verificado tenga una fuente que lo nombre.
   *
   * El 03-10-2026 el agente dio por verificado al directivo de un banco con
   * dos fuentes que no lo demostraban, mientras otra de sus propios resultados,
   * que sí lo nombraba con su cargo, se quedaba sin citar. Para quien recibe el
   * Pack, «verificado» es la luz verde para escribirle a esa persona.
   *
   * Mismo camino que el descuadre: se le pide al agente que lo arregle antes de
   * guardar —que cite la fuente que lo prueba, que la busque o que lo baje a no
   * verificado— y se revisa otra vez. Si sigue sin respaldo, la plataforma lo
   * baja a no verificado y lo dice en el Pack. Lo que nunca sale es un
   * «verificado» sin la fuente que lo demuestre.
   *
   * @param array<int, array{role: string, content: string}> $mensajes
   *   La conversación que se envió.
   * @param \Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn $turno
   *   El turno, ya validado y con la aritmética resuelta.
   */
  private function respaldarCompradores(array $mensajes, DiagnosticTurn $turno): DiagnosticTurn {
    if ($this->buyers === NULL || !$turno->completed || !is_array($turno->result)) {
      return $turno;
    }

    $huecos = $this->buyers->gaps($turno->result);
    $vistos = $this->buyers->lastStats();

    // Siempre que haya compradores verificados, se dice cuántos se comprobaron
    // LEYENDO su fuente. Sin esta línea, «ninguno sin respaldo» no distingue
    // entre que todo cuadraba y que no había texto que leer.
    if ($vistos['verificados'] > 0) {
      $this->logger->info('compradores_comprobados: @v verificado(s), @l comprobado(s) leyendo su fuente, @h sin respaldo.', [
        '@v' => $vistos['verificados'],
        '@l' => $vistos['leidos'],
        '@h' => count($huecos),
      ]);
    }

    if ($huecos === []) {
      return $turno;
    }

    // Solo cuántos y por qué: nunca nombres ni contenido (§43).
    $this->logger->warning('compradores_sin_respaldo: @n comprador(es) dados por verificados sin una fuente que los nombre; se pide al agente que lo corrija antes de guardar.', [
      '@n' => count($huecos),
    ]);

    $lista = implode("\n", array_map(
      static fn (array $h) => sprintf('- %s (%s): %s', $h['cuenta'], $h['comprador'], $h['motivo']),
      $huecos,
    ));

    $mensajes[] = ['role' => 'assistant', 'content' => (string) json_encode($turno->raw, JSON_UNESCAPED_UNICODE)];
    $mensajes[] = [
      'role' => 'system',
      'content' => "Control de evidencia de la plataforma. Marcaste estos compradores como verificados, pero la fuente que los respalda no lo demuestra:\n" . $lista . "\n\nPara cada uno: si entre tus resultados de búsqueda hay una página que nombra a esa persona con su cargo en esa empresa, ponla en buyer_source y en las sources de la cuenta. Si no la tienes, puedes buscarla. Si no la encuentras, márcalo como no verificado según tu metodología —buyer_verified false, el rol en lugar del nombre, buyer_source vacío— y ajusta el mensaje y el correo de esa cuenta. No relajes ninguna de tus reglas y no cambies nada que esto no afecte. Devuelve el Pack completo —el mensaje y el resultado— en el mismo formato.",
    ];

    try {
      $corregido = $this->validator->validate($this->pedir($mensajes, 'Corrección de evidencia de compradores'));
    }
    catch (DiagnosticException $e) {
      $this->logger->warning('No se pudo obtener la corrección de los compradores: se guarda el Pack con ellos bajados a no verificado.');
      return $this->bajarSinRespaldo($turno, $huecos);
    }

    if (!$corregido->completed || !is_array($corregido->result)) {
      $this->logger->warning('La corrección de compradores no devolvió un Pack: se guarda el original con ellos bajados a no verificado.');
      return $this->bajarSinRespaldo($turno, $huecos);
    }

    $siguen = $this->buyers->gaps($corregido->result);

    if ($siguen !== []) {
      $this->logger->warning('compradores_sin_respaldo: tras pedir la corrección siguen @n sin una fuente que los nombre; se bajan a no verificado.', [
        '@n' => count($siguen),
      ]);
      return $this->bajarSinRespaldo($corregido, $siguen);
    }

    $this->logger->info('compradores_respaldados: el agente respaldó o corrigió los compradores antes de guardar.');

    return $corregido;
  }

  /**
   * Baja a no verificado los compradores sin respaldo y lo dice en el Pack.
   *
   * Último recurso, cuando el agente no lo resolvió. Se cambia el dato y se
   * añade una nota al mensaje, porque el texto del agente puede seguir diciendo
   * «verificado» y es lo que la persona lee: sin la nota, el dato y el texto se
   * contradirían justo donde más importa.
   *
   * @param \Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn $turno
   *   El turno con los compradores sin respaldo.
   * @param array<int, array{cuenta: string, comprador: string, motivo: string}> $huecos
   *   Las cuentas sin respaldo.
   */
  private function bajarSinRespaldo(DiagnosticTurn $turno, array $huecos): DiagnosticTurn {
    $cuentas = array_column($huecos, 'cuenta');
    $resultado = $turno->result;

    foreach ($resultado['accounts'] ?? [] as $i => $cuenta) {
      if (is_array($cuenta) && in_array(trim((string) ($cuenta['name'] ?? '')), $cuentas, TRUE)) {
        $resultado['accounts'][$i]['buyer_verified'] = FALSE;
        $resultado['accounts'][$i]['buyer_source'] = '';
      }
    }

    $nota = "\n\n**Comprobación de la plataforma:** " . (count($huecos) === 1
      ? 'el comprador indicado para ' . $huecos[0]['cuenta'] . ' no está confirmado por una fuente de esta investigación. Verifícalo antes de contactar.'
      : 'los compradores indicados para ' . implode(', ', $cuentas) . ' no están confirmados por una fuente de esta investigación. Verifícalos antes de contactar.');

    return new DiagnosticTurn(rtrim($turno->message) . $nota, $turno->completed, $resultado, $turno->raw);
  }

  /**
   * Pide al agente que corrija un informe cuyo global no es la suma.
   *
   * Es un control de la PLATAFORMA, no un cambio de metodología: no se toca
   * ningún número, se le devuelve su propio informe y se le pide que lo
   * recalcule con su Scoring Engine. Es lo que su Orchestrator manda ante un
   * «Arithmetic Integrity FAIL»: detener, corregir en el motor responsable y
   * revalidar. El alumno no llega a ver el informe que no cuadraba.
   *
   * Si la corrección falla —otra llamada que no cuadra, una respuesta que ya
   * no es el informe, un error del proveedor— se guarda el ORIGINAL con un
   * aviso. Dejar al alumno sin su informe por un descuadre sería peor que el
   * descuadre, y cambiar la cifra por nuestra cuenta sería alterar un
   * resultado del cliente.
   *
   * @param array<int, array{role: string, content: string}> $mensajes
   *   La conversación que se envió.
   * @param array<string, mixed> $raw
   *   La respuesta que no cuadraba.
   * @param \Drupal\sales_leadership_diagnostic\DTO\DiagnosticTurn $turno
   *   El turno que no cuadraba, ya validado.
   * @param array{global: float, suma: float} $descuadre
   *   Las dos cifras.
   */
  private function corregirDescuadre(array $mensajes, array $raw, DiagnosticTurn $turno, array $descuadre): DiagnosticTurn {
    // Solo cifras: nunca contenido de la conversación (§43).
    $this->logger->warning('El informe declaró un Score global de @global y sus dimensiones suman @suma: se pide al agente que lo corrija antes de guardarlo.', [
      '@global' => $descuadre['global'],
      '@suma' => $descuadre['suma'],
    ]);

    $mensajes[] = ['role' => 'assistant', 'content' => (string) json_encode($raw, JSON_UNESCAPED_UNICODE)];
    $mensajes[] = [
      'role' => 'system',
      'content' => sprintf(
        'Control de integridad de la plataforma: en el resultado que acabas de entregar, las dimensiones suman %s y el Score Global declarado es %s. Tu Scoring Engine exige que el Score Global sea la suma de las diez dimensiones. Revisa los valores, corrige lo que esté mal y devuelve el informe completo —el mensaje y el resultado— en el mismo formato. No cambies nada que este descuadre no afecte.',
        $descuadre['suma'],
        $descuadre['global'],
      ),
    ];

    try {
      $corregido = $this->validator->validate($this->pedir($mensajes, 'Corrección de integridad'));
    }
    catch (DiagnosticException $e) {
      $this->logger->warning('No se pudo obtener la corrección del informe: se guarda el original, que no cuadra.');
      return $turno;
    }

    if (!$corregido->completed) {
      $this->logger->warning('La corrección pedida no devolvió un informe: se guarda el original, que no cuadra.');
      return $turno;
    }

    if ($this->validator->arithmeticGap($corregido) !== NULL) {
      $this->logger->warning('El informe sigue sin cuadrar después de pedir la corrección: se guarda con este aviso para no dejar al alumno sin él.');
      return $corregido;
    }

    $this->logger->info('El agente corrigió el descuadre del Score global antes de guardarlo.');

    return $corregido;
  }

  /**
   * Una llamada al modelo con el esquema del turno.
   *
   * @param array<int, array{role: string, content: string}> $mensajes
   *   La conversación.
   * @param string $proposito
   *   Para qué es la llamada, tal como se anota en el consumo.
   *
   * @return array<string, mixed>
   *   La respuesta del modelo.
   */
  private function pedir(array $mensajes, string $proposito): array {
    return $this->client->completeJson(
      $mensajes,
      'diagnostic_turn',
      self::RESPONSE_SCHEMA,
      $proposito,
      NULL,
      // Qué herramientas hay lo decide la fábrica, no el motor. Aquí solo se
      // le pasan: el día que el Research Entitlement gobierne quién puede
      // buscar y cuándo, esta línea no cambia.
      $this->tools->forTurn(),
    );
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
