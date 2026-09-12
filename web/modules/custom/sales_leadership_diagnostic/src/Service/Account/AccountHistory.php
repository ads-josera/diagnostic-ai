<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Account;

use Drupal\sales_leadership_diagnostic\BuyerTruth;
use Drupal\sales_leadership_diagnostic\ExecutionState;

/**
 * El historial de cuentas que recibe el agente al empezar cada misión.
 *
 * Sin él, el agente arranca cada semana sin saber qué cuentas ya salieron ni
 * qué pasó con ellas, y puede volver a proponer una que no contestó o que
 * pidió que no la contactaran. Su Documento 8 tiene las reglas para eso —el
 * §26, «Carryover / Recycling Gate»— y aquí NO se reprograman: la metodología
 * es suya (§15). Lo que se hace es darle los datos para que las aplique él.
 *
 * Este bloque es nuestro, no del §5 de su especificación, y por eso lleva
 * otro nombre, igual que PLATFORM_RUNTIME. Los estados y etiquetas van en su
 * vocabulario exacto, que es el que su prompt reconoce.
 *
 * No lleva identidad de nadie (§31, §43): nombres de empresa, fechas, estados
 * y la frase que escribió el alumno, que es contenido de negocio igual que la
 * conversación.
 */
final class AccountHistory {

  /**
   * Cuántas cuentas caben como mucho.
   *
   * Cien líneas son unos cuatro mil tokens, y cubren unas diez semanas de
   * Packs. Más no compensa: la caché del prompt reutiliza este bloque durante
   * toda la conversación, pero la primera llamada lo paga entero. Si hay más,
   * el bloque dice cuántas se quedaron fuera en vez de callarlo.
   */
  public const MAX_CUENTAS = 100;

  /**
   * Largo máximo de la nota del alumno dentro del bloque.
   */
  private const NOTA_MAX = 140;

  public function __construct(
    private readonly AccountRegistry $registry,
  ) {}

  /**
   * El bloque, listo para añadirse al prompt. Vacío si no hay cuentas.
   *
   * Solo las cuentas de ESE agente: el de diagnóstico no produce cuentas y no
   * debe cargar con un bloque que no usa ni perder por él la caché.
   *
   * @param int $uid
   *   Alumno. No viaja en el bloque: solo sirve para leer sus cuentas.
   * @param string $agentId
   *   Agente de la misión que empieza.
   */
  public function compose(int $uid, string $agentId): string {
    $cuentas = $this->registry->forUser($uid, $agentId);

    if ($cuentas === []) {
      return '';
    }

    // Primero las que tienen algo registrado: son las que cambian lo que el
    // agente debe hacer —una DO NOT CONTACT, una GAP REJECTED—, y no pueden
    // quedarse fuera por el tope. Dentro de cada grupo, lo más reciente
    // primero.
    usort($cuentas, static function (array $a, array $b): int {
      $conA = $a['outcome_at'] !== NULL ? 0 : 1;
      $conB = $b['outcome_at'] !== NULL ? 0 : 1;

      return [$conA, -((int) ($a['outcome_at'] ?? $a['last_seen']))]
        <=> [$conB, -((int) ($b['outcome_at'] ?? $b['last_seen']))];
    });

    $total = count($cuentas);
    $lineas = [];

    foreach (array_slice($cuentas, 0, self::MAX_CUENTAS) as $cuenta) {
      $lineas[] = $this->linea($cuenta);
    }

    $bloque = [
      'ACCOUNT_HISTORY',
      'source: platform',
      'accounts_known: ' . $total,
      // La guía va en su vocabulario y apunta a SU regla, sin reescribirla.
      'guidance: Estas cuentas ya aparecieron en Packs anteriores de esta persona. Antes de volver a proponer cualquiera, aplica tu Carryover / Recycling Gate (Documento 08, §26). "execution: NOT RECORDED" significa que no se sabe qué pasó, no que no se contactó: no lo asumas en ningún sentido.',
      'accounts:',
      ...$lineas,
    ];

    if ($total > self::MAX_CUENTAS) {
      $bloque[] = 'omitted: ' . ($total - self::MAX_CUENTAS) . ' cuentas más antiguas sin resultado registrado.';
    }

    return implode("\n", $bloque);
  }

  /**
   * Una cuenta en una línea.
   *
   * @param array<string, mixed> $cuenta
   *   La cuenta, tal como la devuelve AccountRegistry::forUser().
   */
  private function linea(array $cuenta): string {
    $partes = [
      '- ' . $this->limpiar((string) $cuenta['name']),
      'first_pack: ' . $this->fecha((int) $cuenta['first_seen']),
      'last_pack: ' . $this->fecha((int) $cuenta['last_seen']),
      'packs: ' . (int) $cuenta['times_in_pack'],
    ];

    if ($cuenta['disposition'] !== '') {
      $partes[] = 'last_disposition: ' . $cuenta['disposition'];
    }

    $estado = $cuenta['state'];
    $partes[] = $estado instanceof ExecutionState
      ? 'execution: ' . $estado->clientLabel() . ' (' . $this->fecha((int) $cuenta['outcome_at']) . ')'
      : 'execution: NOT RECORDED';

    if ($cuenta['truth'] instanceof BuyerTruth) {
      $partes[] = 'buyer_truth: ' . $cuenta['truth']->clientLabel();
    }

    if ($cuenta['note'] !== '') {
      $partes[] = 'seller_note: "' . $this->limpiar(mb_substr((string) $cuenta['note'], 0, self::NOTA_MAX)) . '"';
    }

    return implode(' | ', $partes);
  }

  /**
   * Una fecha, sin hora: lo que importa aquí son semanas, no minutos.
   */
  private function fecha(int $marca): string {
    return $marca > 0 ? gmdate('Y-m-d', $marca) : 'unknown';
  }

  /**
   * Quita lo que podría romper el formato de la línea.
   *
   * El separador y las comillas los pone el bloque; si vinieran en el nombre
   * o en la nota, el agente leería un campo donde no lo hay.
   */
  private function limpiar(string $texto): string {
    return trim(str_replace(['|', '"', "\n", "\r"], ['/', "'", ' ', ' '], $texto));
  }

}
