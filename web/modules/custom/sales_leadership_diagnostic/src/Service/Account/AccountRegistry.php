<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Account;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Database\Connection;
use Drupal\sales_leadership_diagnostic\BuyerTruth;
use Drupal\sales_leadership_diagnostic\ExecutionState;

/**
 * Las cuentas de cada alumno, semana tras semana, y lo que pasó con ellas.
 *
 * Existe porque el acceso dura un año. Con una misión por semana y unas diez
 * cuentas por misión, un alumno acumula del orden de quinientas cuentas, y
 * hasta el 11-09-2026 el sistema no recordaba ninguna de una semana a otra: la
 * memoria del alumno guarda dos o tres frases por tema, y el Evidence Ledger
 * guarda lo que se encontró en fuentes públicas, no lo que pasó al contactar.
 * El agente podía volver a proponer una cuenta que ya no contestó, y el
 * Director tenía que volver a contarlo todo cada lunes.
 *
 * El diseño sale del Documento 8 del cliente y respeta su regla central, la
 * del §4: «NEW EVIDENCE APPENDS TO THE SNAPSHOT. IT NEVER RETROACTIVELY
 * ALTERS THE ORIGINAL THESIS». Por eso hay dos tablas:
 *
 *  - `sld_account`: una fila por cuenta y alumno. Solo identidad y fechas.
 *  - `sld_account_event`: lo que ha ido pasando, y **solo crece**. Cada Pack
 *    que la incluye deja un evento; cada resultado que registra el alumno,
 *    otro. Nada se edita.
 *
 * La tesis original no se copia aquí: vive en el resultado del Pack, que ya es
 * inmutable, y cada evento lo referencia. Copiarla sería tener dos versiones
 * de la misma verdad.
 *
 * El estado actual NO se guarda en la fila de la cuenta: se deduce del último
 * evento al leer. Un estado cacheado y su historial acaban diciendo cosas
 * distintas, y entonces el que se cree es el que confunde.
 */
final class AccountRegistry {

  /**
   * Tabla de cuentas.
   */
  private const CUENTAS = 'sld_account';

  /**
   * Tabla de eventos. Solo crece.
   */
  private const EVENTOS = 'sld_account_event';

  /**
   * Largo máximo de la nota del alumno.
   *
   * Su §11 pide «one sentence». Doscientos ochenta caracteres dan para una
   * frase larga y no para un informe, que es la diferencia que importa.
   */
  public const NOTE_MAX = 280;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly TransliterationInterface $transliteration,
  ) {}

  /**
   * La clave con la que se reconoce una cuenta aunque se escriba distinto.
   *
   * «Traxión» y «Traxion», o «PepsiCo  Alimentos México» con dos espacios, son
   * la misma cuenta y no deben dar dos filas.
   *
   * Es conservadora a propósito: no quita «Grupo», «S.A. de C.V.» ni
   * palabras sueltas. Juntar de más mezcla dos empresas distintas en una sola
   * historia, y eso es peor que un duplicado: el duplicado se ve, la mezcla
   * no.
   */
  public function keyFor(string $name): string {
    $plano = $this->transliteration->transliterate(trim($name), 'es');
    $plano = mb_strtolower($plano);
    $plano = (string) preg_replace('/[^a-z0-9]+/', ' ', $plano);

    return trim($plano);
  }

  /**
   * Registra las cuentas de un Pack recién guardado.
   *
   * Idempotente: pasar el mismo Pack dos veces no duplica nada. Importa para
   * la carga de los Packs que ya existían, que se puede repetir sin miedo.
   *
   * @param int $uid
   *   Dueño del Pack.
   * @param string $agentId
   *   Agente que lo produjo.
   * @param int $resultId
   *   Resultado donde vive la tesis original de cada cuenta.
   * @param array<int, array<string, mixed>> $accounts
   *   Las cuentas, tal como las devuelve
   *   DiagnosticResultInterface::getAccounts().
   * @param int|null $when
   *   Cuándo se entregó el Pack. Por defecto, ahora.
   *
   * @return int
   *   Cuántas cuentas se registraron de este Pack.
   */
  public function ingestPack(int $uid, string $agentId, int $resultId, array $accounts, ?int $when = NULL): int {
    $cuando = $when ?? $this->time->getRequestTime();
    $registradas = 0;
    $transaccion = $this->database->startTransaction();

    try {
      foreach ($accounts as $cuenta) {
        $nombre = trim((string) ($cuenta['name'] ?? ''));
        $clave = $this->keyFor($nombre);

        if ($clave === '') {
          continue;
        }

        $id = $this->idFor($uid, $clave);

        if ($id === NULL) {
          $id = (int) $this->database->insert(self::CUENTAS)->fields([
            'uid' => $uid,
            'agent' => $agentId,
            'name' => mb_substr($nombre, 0, 255),
            'name_key' => mb_substr($clave, 0, 255),
            'first_result_id' => $resultId,
            'last_result_id' => $resultId,
            'times_in_pack' => 0,
            'first_seen' => $cuando,
            'last_seen' => $cuando,
          ])->execute();
        }

        // Ya registrada desde este mismo Pack: nada que hacer.
        if ($this->packEventExists($id, $resultId)) {
          continue;
        }

        $this->database->insert(self::EVENTOS)->fields([
          'account_id' => $id,
          'uid' => $uid,
          'kind' => 'pack',
          'result_id' => $resultId,
          'disposition' => mb_substr((string) ($cuenta['disposition'] ?? ''), 0, 32),
          'outreach_status' => mb_substr((string) ($cuenta['outreach_status'] ?? ''), 0, 32),
          'created' => $cuando,
        ])->execute();

        // El nombre visible es el del Pack más reciente: si el agente lo
        // escribió mejor esta semana, se ve mejor. La clave no cambia.
        $this->database->update(self::CUENTAS)
          ->fields([
            'name' => mb_substr($nombre, 0, 255),
            'last_result_id' => $resultId,
            'last_seen' => $cuando,
          ])
          ->expression('times_in_pack', 'times_in_pack + 1')
          ->condition('id', $id)
          ->execute();

        $registradas++;
      }
    }
    catch (\Throwable $e) {
      $transaccion->rollBack();
      throw $e;
    }

    return $registradas;
  }

  /**
   * Anota lo que pasó con una cuenta.
   *
   * No edita nada: añade un evento. Si el alumno se equivoca y lo corrige, se
   * ve el error y la corrección, que es lo que su §4 pide para no reescribir la
   * historia.
   *
   * @return bool
   *   Falso si la cuenta no existe o no es de esa persona. Se comprueba aquí y
   *   no solo en quien llama: es la última puerta antes de escribir.
   */
  public function recordOutcome(int $uid, int $accountId, ExecutionState $state, ?BuyerTruth $truth = NULL, string $note = ''): bool {
    $dueno = $this->database->select(self::CUENTAS, 'c')
      ->fields('c', ['uid'])
      ->condition('id', $accountId)
      ->execute()
      ->fetchField();

    if ($dueno === FALSE || (int) $dueno !== $uid) {
      return FALSE;
    }

    $nota = trim((string) preg_replace('/\s+/u', ' ', $note));

    $this->database->insert(self::EVENTOS)->fields([
      'account_id' => $accountId,
      'uid' => $uid,
      'kind' => 'outcome',
      'state' => $state->value,
      'truth' => $truth?->value ?? '',
      'note' => mb_substr($nota, 0, self::NOTE_MAX),
      'created' => $this->time->getRequestTime(),
    ])->execute();

    return TRUE;
  }

  /**
   * Las cuentas de una persona, con lo último que se sabe de cada una.
   *
   * El estado se deduce aquí, del último evento de resultado. No hay columna
   * de estado que pueda quedarse vieja.
   *
   * @return array<int, array<string, mixed>>
   *   Una entrada por cuenta, la vista más recientemente primero.
   */
  public function forUser(int $uid, ?string $agentId = NULL): array {
    $consulta = $this->database->select(self::CUENTAS, 'c')
      ->fields('c')
      ->condition('uid', $uid)
      ->orderBy('last_seen', 'DESC')
      ->orderBy('name');

    if ($agentId !== NULL) {
      $consulta->condition('agent', $agentId);
    }

    $cuentas = [];

    foreach ($consulta->execute() as $fila) {
      $cuentas[(int) $fila->id] = [
        'id' => (int) $fila->id,
        'name' => (string) $fila->name,
        'agent' => (string) $fila->agent,
        'first_seen' => (int) $fila->first_seen,
        'last_seen' => (int) $fila->last_seen,
        'times_in_pack' => (int) $fila->times_in_pack,
        'last_result_id' => (int) $fila->last_result_id,
        'disposition' => '',
        'outreach_status' => '',
        'state' => NULL,
        'truth' => NULL,
        'note' => '',
        'outcome_at' => NULL,
        'outcomes' => 0,
      ];
    }

    if ($cuentas === []) {
      return [];
    }

    // En orden de llegada: cada evento pisa al anterior del mismo tipo, así
    // que al terminar queda lo último de cada cosa.
    $eventos = $this->database->select(self::EVENTOS, 'e')
      ->fields('e')
      ->condition('uid', $uid)
      ->condition('account_id', array_keys($cuentas), 'IN')
      ->orderBy('id')
      ->execute();

    foreach ($eventos as $evento) {
      $id = (int) $evento->account_id;

      if ($evento->kind === 'pack') {
        $cuentas[$id]['disposition'] = (string) $evento->disposition;
        $cuentas[$id]['outreach_status'] = (string) $evento->outreach_status;
        continue;
      }

      $cuentas[$id]['state'] = ExecutionState::tryFrom((string) $evento->state);
      $cuentas[$id]['truth'] = BuyerTruth::tryFrom((string) $evento->truth);
      $cuentas[$id]['note'] = (string) $evento->note;
      $cuentas[$id]['outcome_at'] = (int) $evento->created;
      $cuentas[$id]['outcomes']++;
    }

    return array_values($cuentas);
  }

  /**
   * El historial de una cuenta, del más antiguo al más reciente.
   *
   * @return array<int, array<string, mixed>>
   *   Los eventos. Vacío si la cuenta no es de esa persona.
   */
  public function history(int $uid, int $accountId): array {
    $filas = $this->database->select(self::EVENTOS, 'e')
      ->fields('e')
      ->condition('uid', $uid)
      ->condition('account_id', $accountId)
      ->orderBy('id')
      ->execute();

    $salida = [];

    foreach ($filas as $fila) {
      $salida[] = [
        'kind' => (string) $fila->kind,
        'result_id' => (int) $fila->result_id,
        'disposition' => (string) $fila->disposition,
        'outreach_status' => (string) $fila->outreach_status,
        'state' => ExecutionState::tryFrom((string) $fila->state),
        'truth' => BuyerTruth::tryFrom((string) $fila->truth),
        'note' => (string) $fila->note,
        'created' => (int) $fila->created,
      ];
    }

    return $salida;
  }

  /**
   * Borra todo lo de una persona. Lo usa el borrado de la cuenta.
   *
   * @return int
   *   Cuántas cuentas se borraron.
   */
  public function forgetAll(int $uid): int {
    $this->database->delete(self::EVENTOS)->condition('uid', $uid)->execute();

    return (int) $this->database->delete(self::CUENTAS)->condition('uid', $uid)->execute();
  }

  /**
   * El identificador de una cuenta por su clave, si ya existe.
   */
  private function idFor(int $uid, string $clave): ?int {
    $id = $this->database->select(self::CUENTAS, 'c')
      ->fields('c', ['id'])
      ->condition('uid', $uid)
      ->condition('name_key', $clave)
      ->execute()
      ->fetchField();

    return $id === FALSE ? NULL : (int) $id;
  }

  /**
   * Si una cuenta ya tiene el evento de ese Pack.
   */
  private function packEventExists(int $accountId, int $resultId): bool {
    return (bool) $this->database->select(self::EVENTOS, 'e')
      ->condition('account_id', $accountId)
      ->condition('kind', 'pack')
      ->condition('result_id', $resultId)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
