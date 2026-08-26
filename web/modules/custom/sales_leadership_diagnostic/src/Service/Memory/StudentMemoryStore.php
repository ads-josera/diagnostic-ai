<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Memory;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sales_leadership_diagnostic\Entity\StudentMemoryInterface;
use Drupal\sales_leadership_diagnostic\MemoryTopic;

/**
 * Guarda y recupera lo que el sistema recuerda de cada alumno.
 *
 * Es el único punto por el que se escribe memoria, y por eso concentra la
 * regla que la mantiene manejable: un hecho por tema y alumno. Recordar algo
 * de un tema que ya existe REEMPLAZA lo anterior en vez de añadirse.
 *
 * Todas las consultas van filtradas por propietario. El aislamiento real lo
 * garantiza el handler de acceso de la entidad, pero un servicio que pudiera
 * devolver la memoria de otro alumno por descuido sería una manera de
 * saltárselo sin darse cuenta, así que aquí no existe ninguna consulta que no
 * lleve el uid.
 */
final class StudentMemoryStore {

  /**
   * Cuánto texto se admite por tema.
   *
   * El límite no es de almacenamiento —el campo es de texto largo— sino del
   * prompt: la memoria entera se envía en cada conversación nueva, y sin tope
   * un modelo hablador la haría crecer hasta desplazar a la metodología. Con
   * seis temas, el bloque completo se queda muy por debajo del millar de
   * palabras.
   */
  public const MAX_LONGITUD = 600;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Lo que se recuerda de un alumno, en el orden de presentación de los temas.
   *
   * @return array<string, \Drupal\sales_leadership_diagnostic\Entity\StudentMemoryInterface>
   *   Hechos indexados por el valor de su tema.
   */
  public function forUser(int $uid): array {
    $storage = $this->entityTypeManager->getStorage('sld_student_memory');

    $ids = $storage->getQuery()
      // Se comprueba por propietario explícitamente, que es más estrecho que
      // el acceso del usuario en curso: esto lo llama también la extracción,
      // que corre sin usuario, y ahí un accessCheck(TRUE) no devolvería nada.
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $porTema = [];

    foreach ($storage->loadMultiple($ids) as $entidad) {
      if ($entidad instanceof StudentMemoryInterface && $entidad->getTopic() !== NULL) {
        $porTema[$entidad->getTopic()->value] = $entidad;
      }
    }

    // Se ordena por la lista de temas y no por fecha: el alumno tiene que
    // encontrar su ficha siempre igual, aunque un tema se haya actualizado
    // ayer y otro hace medio año.
    $ordenados = [];

    foreach (MemoryTopic::order() as $tema) {
      if (isset($porTema[$tema])) {
        $ordenados[$tema] = $porTema[$tema];
      }
    }

    return $ordenados;
  }

  /**
   * Recuerda algo, reemplazando lo que hubiera de ese tema.
   *
   * @param int $uid
   *   Alumno del que se recuerda.
   * @param \Drupal\sales_leadership_diagnostic\MemoryTopic $topic
   *   Tema al que pertenece.
   * @param string $content
   *   Lo que se recuerda. Vacío equivale a olvidar el tema.
   * @param string $agentId
   *   Agente de cuya conversación salió.
   * @param int|null $sessionId
   *   Sesión de la que salió, si se conoce.
   */
  public function remember(int $uid, MemoryTopic $topic, string $content, string $agentId, ?int $sessionId = NULL): void {
    $content = $this->recortar($content);

    if ($content === '') {
      $this->forgetTopic($uid, $topic);

      return;
    }

    $existente = $this->forUser($uid)[$topic->value] ?? NULL;

    if ($existente !== NULL) {
      $existente->setContent($content)->setSource($agentId, $sessionId)->save();

      return;
    }

    $this->entityTypeManager->getStorage('sld_student_memory')->create([
      'uid' => $uid,
      'topic' => $topic->value,
      'content' => $content,
      'source_agent' => $agentId,
      'source_session' => $sessionId,
    ])->save();
  }

  /**
   * Olvida un tema concreto de un alumno.
   */
  public function forgetTopic(int $uid, MemoryTopic $topic): void {
    $existente = $this->forUser($uid)[$topic->value] ?? NULL;

    if ($existente !== NULL) {
      $existente->delete();
    }
  }

  /**
   * Olvida todo lo de un alumno.
   *
   * @return int
   *   Cuántos hechos se borraron.
   */
  public function forgetAll(int $uid): int {
    $hechos = $this->forUser($uid);

    if ($hechos === []) {
      return 0;
    }

    $this->entityTypeManager->getStorage('sld_student_memory')->delete($hechos);

    return count($hechos);
  }

  /**
   * Si hay algo recordado de este alumno.
   */
  public function isEmpty(int $uid): bool {
    return $this->forUser($uid) === [];
  }

  /**
   * El bloque de memoria que se añade al prompt de una sesión nueva.
   *
   * Devuelve cadena vacía si no hay nada recordado, de modo que la primera
   * conversación de un alumno sea exactamente igual que antes de que esto
   * existiera.
   *
   * El texto que envuelve a los hechos es tan importante como los hechos. La
   * memoria la escribió un modelo leyendo una conversación de hace meses:
   * puede estar desactualizada y puede estar mal. Si el agente la tratara como
   * un dato firme, dos cosas malas ocurrirían a la vez —daría por sabido algo
   * falso y lo contaría entre las evidencias— y la metodología del cliente,
   * que exige evidencia antes que opinión, quedaría contaminada desde el
   * primer turno. Por eso se le dice explícitamente que esto NO es evidencia y
   * que hay que confirmarlo.
   */
  public function compose(int $uid): string {
    $hechos = $this->forUser($uid);

    if ($hechos === []) {
      return '';
    }

    $lineas = [];

    foreach ($hechos as $hecho) {
      $tema = $hecho->getTopic();

      if ($tema !== NULL) {
        $lineas[] = '- ' . $tema->label() . ': ' . $hecho->getContent();
      }
    }

    return implode("\n", [
      '## Lo que ya sabíamos de esta persona',
      '',
      'Recogido en conversaciones anteriores suyas con este sistema.',
      '',
      implode("\n", $lineas),
      '',
      'Úsalo para no hacerle repetir lo que ya contó: da por sabido el',
      'contexto y arranca desde ahí.',
      '',
      'IMPORTANTE: esto NO es evidencia y no puede citarse como tal. Puede',
      'haber quedado desactualizado o ser incorrecto. Antes de apoyarte en',
      'cualquiera de estos datos para una conclusión, confírmalo con ella en',
      'la conversación.',
    ]);
  }

  /**
   * Recorta el texto sin partir una palabra por la mitad.
   */
  private function recortar(string $content): string {
    $content = trim(preg_replace('/\s+/u', ' ', $content) ?? '');

    if ($content === '' || mb_strlen($content) <= self::MAX_LONGITUD) {
      return $content;
    }

    $cortado = mb_substr($content, 0, self::MAX_LONGITUD);
    $ultimoEspacio = mb_strrpos($cortado, ' ');

    return rtrim($ultimoEspacio === FALSE ? $cortado : mb_substr($cortado, 0, $ultimoEspacio), " ,;:.");
  }

}
