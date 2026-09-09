<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Evidence;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;

/**
 * Lo que ya se sabe, con su procedencia, y sobrevive a la conversación.
 *
 * Es el Evidence Ledger del §7 de la especificación del cliente. Guarda
 * **afirmaciones que el agente usó**, no páginas ni resultados de búsqueda: la
 * diferencia es lo que permite reutilizar sin volver a leer nada, y es lo que
 * el §9 pide cuando dice «no reenviar contenido completo si basta el ledger».
 *
 * Cuelga de la PERSONA. Su §14 pide que «una cuenta ya investigada que
 * reaparece la semana siguiente» se reutilice en vez de empezar de cero, y eso
 * exige que la evidencia sobreviva al cierre de la misión que la recogió.
 *
 * No guarda nada sin procedencia. La metodología del cliente prohíbe afirmar
 * sin ella, así que una anotación sin fuente no serviría: no se podría citar
 * ni comprobar.
 */
final class EvidenceLedger {

  /**
   * Nombre de la tabla.
   */
  private const TABLE = 'sld_evidence';

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Anota una afirmación con su procedencia.
   *
   * @param int $uid
   *   Persona a la que pertenece.
   * @param string $missionId
   *   Misión que la recogió.
   * @param int $sessionId
   *   Conversación donde se anotó.
   * @param array<string, string> $campos
   *   Los campos del §7: scope, claim, summary, source, evidence_type,
   *   confidence.
   *
   * @return bool
   *   Falso si la anotación no era utilizable. No se lanza: el agente tiene
   *   que poder enterarse y corregir sin que se le caiga el turno.
   */
  public function record(int $uid, string $missionId, int $sessionId, array $campos): bool {
    $claim = trim($campos['claim'] ?? '');
    $source = trim($campos['source'] ?? '');

    // Sin afirmación no hay nada que guardar; sin fuente, nada que reutilizar.
    // Se rechaza en vez de guardar a medias: una evidencia sin procedencia
    // reaparecería más tarde como si fuera comprobable.
    if ($claim === '' || $source === '') {
      return FALSE;
    }

    $this->database->insert(self::TABLE)->fields([
      'uid' => $uid,
      'mission_id' => $missionId,
      'session_id' => $sessionId,
      'scope' => mb_substr(trim($campos['scope'] ?? ''), 0, 255),
      'claim' => $claim,
      'summary' => mb_substr(trim($campos['summary'] ?? ''), 0, 2000),
      'source' => mb_substr($source, 0, 1024),
      'evidence_type' => mb_substr(trim($campos['evidence_type'] ?? ''), 0, 32),
      'confidence' => mb_substr(trim($campos['confidence'] ?? ''), 0, 16),
      'status' => 'CURRENT',
      'observed_at' => $this->time->getRequestTime(),
    ])->execute();

    return TRUE;
  }

  /**
   * Lo que se sabe de un ámbito, con su antigüedad y su estado.
   *
   * El estado STALE no se guarda: se deduce al leer, comparando la fecha de
   * observación con el umbral configurado. Guardarlo obligaría a un proceso
   * que fuera marcando filas viejas, y una evidencia no cambia de naturaleza
   * porque nadie haya pasado a revisarla.
   *
   * @param int $uid
   *   Persona.
   * @param string $scope
   *   Empresa, persona o mercado. Vacío para traer lo más reciente de todo.
   * @param int $limit
   *   Cuántas anotaciones como mucho.
   *
   * @return array<int, array<string, mixed>>
   *   Las anotaciones, de la más reciente a la más antigua.
   */
  public function recall(int $uid, string $scope = '', int $limit = 20): array {
    $consulta = $this->database->select(self::TABLE, 'e')
      ->fields('e')
      ->condition('uid', $uid)
      ->orderBy('observed_at', 'DESC')
      ->range(0, max(1, min($limit, 100)));

    if (trim($scope) !== '') {
      // Coincidencia parcial: el agente escribe «Grupo Bimbo» donde antes
      // anotó «Grupo Bimbo — distribución Bajío», y exigir igualdad haría
      // inservible el ledger justo cuando más falta hace.
      $consulta->condition('scope', '%' . $this->database->escapeLike(trim($scope)) . '%', 'LIKE');
    }

    $filas = $consulta->execute()->fetchAll(FetchAs::Associative);
    $ahora = $this->time->getRequestTime();
    $umbral = $this->staleAfterDays() * 86400;

    foreach ($filas as $i => $fila) {
      $edad = $ahora - (int) $fila['observed_at'];
      $filas[$i]['age_days'] = (int) floor($edad / 86400);

      // Lo que el agente declaró como contradicho o superado manda sobre la
      // antigüedad: una evidencia contradicha no vuelve a ser válida por ser
      // reciente.
      if ($fila['status'] === 'CURRENT' && $edad > $umbral) {
        $filas[$i]['status'] = 'STALE';
      }
    }

    return $filas;
  }

  /**
   * Anota que unas evidencias se reutilizaron.
   *
   * Es la medida directa del ahorro que el §10 pide: cuántas veces se resolvió
   * algo sin volver a buscar.
   *
   * @param int[] $ids
   *   Identificadores de las anotaciones leídas.
   */
  public function markReused(array $ids): void {
    if ($ids === []) {
      return;
    }

    $this->database->update(self::TABLE)
      ->expression('reused', 'reused + 1')
      ->condition('id', $ids, 'IN')
      ->execute();
  }

  /**
   * Cambia el estado de una anotación.
   *
   * Lo usa el agente cuando encuentra que algo que anotó ya no se sostiene.
   * Que pueda contradecirse a sí mismo es parte de su metodología: su §7
   * contempla CONTRADICTED y SUPERSEDED como estados normales.
   */
  public function setStatus(int $uid, int $id, string $status): bool {
    if (!in_array($status, ['CURRENT', 'CONTRADICTED', 'SUPERSEDED'], TRUE)) {
      return FALSE;
    }

    return $this->database->update(self::TABLE)
      ->fields(['status' => $status])
      ->condition('uid', $uid)
      ->condition('id', $id)
      ->execute() > 0;
  }

  /**
   * Si esta persona tiene algo guardado.
   *
   * Lo consulta el bloque de runtime para decirle al agente si hay ledger que
   * consultar. Decirle que sí cuando está vacío le haría buscar ahí primero
   * para no encontrar nada.
   */
  public function hasAnyFor(int $uid): bool {
    return (int) $this->database->select(self::TABLE, 'e')
      ->condition('uid', $uid)
      ->countQuery()
      ->execute()
      ->fetchField() > 0;
  }

  /**
   * Cifras del periodo, para enseñarlas.
   *
   * @return array{entries: int, reused: int, scopes: int}
   *   Anotaciones, reutilizaciones y ámbitos distintos.
   */
  public function summarySince(int $since): array {
    $fila = $this->database->query(
      'SELECT COUNT(*) AS entries,
              COALESCE(SUM(reused), 0) AS reused,
              COUNT(DISTINCT scope) AS scopes
         FROM {' . self::TABLE . '}
        WHERE observed_at >= :desde',
      [':desde' => $since],
    )->fetchAssoc() ?: [];

    return [
      'entries' => (int) ($fila['entries'] ?? 0),
      'reused' => (int) ($fila['reused'] ?? 0),
      'scopes' => (int) ($fila['scopes'] ?? 0),
    ];
  }

  /**
   * A partir de cuántos días una evidencia se considera vieja.
   */
  private function staleAfterDays(): int {
    $valor = (int) $this->configFactory
      ->get('sales_leadership_diagnostic.settings')
      ->get('research.evidence_stale_days');

    return $valor > 0 ? $valor : 30;
  }

}
