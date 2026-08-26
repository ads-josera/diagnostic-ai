<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Memory;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;

/**
 * Saca de una conversación terminada lo que conviene recordar del alumno.
 *
 * Es una llamada al modelo APARTE de la del diagnóstico, con prompt propio, y
 * eso es deliberado. La metodología del agente es del cliente y no se toca
 * (§15): pedirle al mismo turno que además emita la memoria obligaría a
 * ampliar su contrato de salida, es decir, a alterar su metodología. Aquí se
 * relee la conversación ya cerrada y se le pregunta otra cosa distinta.
 *
 * Nunca propaga un fallo. La memoria es una comodidad: que no se extraiga
 * significa que el alumno tendrá que volver a contar su empresa, no que su
 * diagnóstico se haya perdido. Hacer que un error del proveedor tumbara algo
 * más sería cambiar una molestia por un daño.
 */
final class MemoryExtractor {

  /**
   * Cuántos mensajes finales se releen.
   *
   * La conversación entera puede ser larga y reenviarla completa cuesta lo que
   * cuesta un turno de diagnóstico. Los datos del negocio salen sobre todo al
   * principio y se van refinando, así que se manda la conversación acotada por
   * el mismo criterio que usa el diagnóstico: los últimos turnos.
   */
  private const MAX_MENSAJES = 60;

  /**
   * Presupuesto de la respuesta.
   *
   * Seis campos de texto corto. Holgado frente a eso, y muy por debajo del
   * presupuesto de un turno de diagnóstico, que sí produce un informe.
   */
  private const MAX_TOKENS = 1500;

  /**
   * Canal de log del módulo.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly OpenAIClient $client,
    private readonly StudentMemoryStore $store,
    private readonly DiagnosticMessageRepository $messages,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Extrae y guarda la memoria de una sesión ya terminada.
   *
   * @param int $sessionId
   *   Sesión de la que extraer.
   *
   * @return bool
   *   Si se llegó a guardar algo.
   */
  public function extractFromSession(int $sessionId): bool {
    try {
      $session = $this->entityTypeManager
        ->getStorage('sld_diagnostic_session')
        ->load($sessionId);

      if (!$session instanceof DiagnosticSessionInterface) {
        return FALSE;
      }

      // Un ensayo del gestor no es de nadie: escribir su contenido en la
      // memoria de la cuenta que lo hizo mezclaría el negocio del alumno que
      // se estuviera simulando con el del gestor.
      if ((bool) $session->get('is_sandbox')->value) {
        return FALSE;
      }

      return $this->extract($session);
    }
    catch (\Throwable $e) {
      // A propósito se captura todo, no solo las excepciones del motor: esto
      // corre en segundo plano y su único deber es no arrastrar a nada más.
      $this->logger->warning(
        'No se pudo extraer la memoria de la sesión @id: @motivo. El diagnóstico no se ve afectado.',
        ['@id' => $sessionId, '@motivo' => $e->getMessage()],
      );

      return FALSE;
    }
  }

  /**
   * El trabajo, una vez comprobado que la sesión sirve.
   */
  private function extract(DiagnosticSessionInterface $session): bool {
    $uid = (int) $session->getOwnerId();
    $conversacion = $this->messages->loadForSession((int) $session->id(), self::MAX_MENSAJES);

    if ($conversacion === []) {
      return FALSE;
    }

    $transcripcion = [];

    foreach ($conversacion as $mensaje) {
      $transcripcion[] = strtoupper($mensaje->role->value) . ': ' . $mensaje->content;
    }

    $respuesta = $this->client->completeJson(
      [
        ['role' => 'system', 'content' => $this->buildInstructions()],
        ['role' => 'user', 'content' => $this->buildInput($uid, $transcripcion)],
      ],
      'student_memory',
      $this->buildSchema(),
      'Memoria extraída',
      self::MAX_TOKENS,
    );

    return $this->guardar($uid, (string) $session->getAgentId(), (int) $session->id(), $respuesta);
  }

  /**
   * Guarda lo que devolvió el modelo.
   *
   * @param int $uid
   *   Alumno.
   * @param string $agentId
   *   Agente de cuya conversación salió.
   * @param int $sessionId
   *   Sesión de origen.
   * @param array<string, mixed> $respuesta
   *   Lo que devolvió el modelo.
   *
   * @return bool
   *   Si se guardó algo.
   */
  private function guardar(int $uid, string $agentId, int $sessionId, array $respuesta): bool {
    $guardados = 0;

    foreach (MemoryTopic::cases() as $tema) {
      // Una clave ausente NO es lo mismo que una vacía. Ausente significa que
      // el modelo no se pronunció, y entonces lo anterior se conserva; vacía
      // es una afirmación de que no se sabe nada, y entonces se olvida.
      if (!array_key_exists($tema->value, $respuesta)) {
        continue;
      }

      $valor = $respuesta[$tema->value];
      $contenido = is_string($valor) ? trim($valor) : '';

      $this->store->remember($uid, $tema, $contenido, $agentId, $sessionId);

      if ($contenido !== '') {
        $guardados++;
      }
    }

    $this->logger->info(
      'Memoria actualizada tras la sesión @id: @n tema(s) con contenido.',
      ['@id' => $sessionId, '@n' => $guardados],
    );

    return $guardados > 0;
  }

  /**
   * Instrucciones de la extracción.
   *
   * Van en español porque son NUESTRAS, no del cliente: describen qué hace
   * este módulo con la conversación, no cómo se diagnostica. El prompt del
   * agente es cosa aparte y no se toca.
   */
  private function buildInstructions(): string {
    $temas = [];

    foreach (MemoryTopic::cases() as $tema) {
      $temas[] = '- ' . $tema->value . ': ' . $tema->guidance();
    }

    return implode("\n", [
      'Eres un extractor de datos. NO diagnosticas, NO aconsejas y NO evalúas.',
      '',
      'Recibes lo que ya se sabía de una persona y la transcripción de una',
      'conversación suya que acaba de terminar. Devuelves lo que conviene',
      'recordar de su NEGOCIO para que, si vuelve dentro de unos meses, no',
      'tenga que contarlo todo otra vez.',
      '',
      'Temas, y qué va en cada uno:',
      implode("\n", $temas),
      '',
      'Reglas:',
      '1. Escribe hechos, no valoraciones. «Seis vendedores y un jefe de',
      '   ventas», no «equipo pequeño y mal estructurado».',
      '2. Solo lo que la persona haya dicho. No completes, no supongas y no',
      '   deduzcas nada del sector ni del tamaño.',
      '3. Devuelve el estado ACTUALIZADO de cada tema, no lo nuevo. Si la',
      '   conversación no añade nada sobre un tema, repite lo que ya se sabía',
      '   tal cual. Si lo corrige, devuelve la versión corregida.',
      '4. Devuelve cadena vacía en un tema solo si no se sabe nada de él.',
      '5. No incluyas conclusiones del diagnóstico, puntuaciones ni',
      '   recomendaciones: eso ya está guardado en su resultado.',
      '6. Sé breve: dos o tres frases por tema como mucho.',
      '7. Escribe en el mismo idioma en que habló la persona.',
    ]);
  }

  /**
   * Lo que se le da al modelo: lo que ya se sabía y la conversación.
   *
   * @param int $uid
   *   Alumno.
   * @param string[] $transcripcion
   *   Conversación, un mensaje por línea.
   */
  private function buildInput(int $uid, array $transcripcion): string {
    $previo = [];

    foreach ($this->store->forUser($uid) as $tema => $hecho) {
      $previo[] = $tema . ': ' . $hecho->getContent();
    }

    return implode("\n", [
      '### Lo que ya se sabía',
      $previo === [] ? '(nada todavía)' : implode("\n", $previo),
      '',
      '### Conversación que acaba de terminar',
      implode("\n\n", $transcripcion),
    ]);
  }

  /**
   * Esquema de la respuesta: una propiedad por tema y ninguna más.
   *
   * Lo importante es que el esquema, y no una instrucción del prompt, es lo
   * que impide que el modelo invente temas. Una instrucción se puede ignorar;
   * el esquema estricto lo rechaza el propio proveedor.
   *
   * @return array<string, mixed>
   *   Esquema JSON estricto.
   */
  private function buildSchema(): array {
    $propiedades = [];

    foreach (MemoryTopic::cases() as $tema) {
      $propiedades[$tema->value] = [
        'type' => 'string',
        'description' => $tema->guidance() . ' Cadena vacía si no se sabe nada.',
      ];
    }

    return [
      'type' => 'object',
      'properties' => $propiedades,
      // El modo estricto exige declarar todas las propiedades como requeridas.
      'required' => MemoryTopic::order(),
      'additionalProperties' => FALSE,
    ];
  }

}
