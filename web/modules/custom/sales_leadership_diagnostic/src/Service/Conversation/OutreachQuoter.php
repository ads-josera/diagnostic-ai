<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

/**
 * Pone como cita, dentro del Pack, cada mensaje listo para enviar.
 *
 * Un correo de contacto se lee distinto que el análisis que lo rodea: es texto
 * para copiar tal cual. Cuando el agente lo escribía con `>` se veía apartado,
 * con su barra a la izquierda, y se distinguía de un vistazo. Pero eso lo
 * decidía él, y no siempre lo hacía: en la misión del 02-10-2026 a las 16:15
 * los dos correos salieron como párrafos normales, confundidos con el resto.
 *
 * No se le pide en el contrato a propósito: un formato que depende de que el
 * modelo obedezca sale unas veces sí y otras no —es justo lo que pasó con la
 * cita—, y la plataforma lo hace siempre. (Ese día se sospechó también que
 * una instrucción de formato le quitaba la búsqueda de compradores; no se
 * confirmó al repetirlo. Ver SourcesAppendix.)
 *
 * Cómo lo encuentra: el agente entrega cada correo por separado en
 * `accounts[].outreach_message`, y el contrato le pide que coincida con el
 * texto. Medido en producción, coincide párrafo a párrafo una vez quitadas las
 * marcas de Markdown —en el texto, «Asunto:» va en negrita—, entero, en orden
 * y en líneas seguidas. Si no aparece así, no se toca nada: en el peor caso el
 * Pack queda como lo escribió el agente.
 */
final class OutreachQuoter {

  /**
   * Por debajo de esto, un «mensaje» es demasiado corto para buscarlo.
   *
   * Una sola palabra o un saludo suelto podría coincidir con cualquier línea
   * del análisis, y entonces se citaría lo que no es.
   */
  private const LONGITUD_MINIMA = 40;

  /**
   * El mensaje del Pack con cada correo de contacto marcado como cita.
   *
   * @param string $markdown
   *   El Pack tal como lo escribió el agente.
   * @param array|null $payload
   *   El resultado estructurado, con el `outreach_message` de cada cuenta.
   *
   * @return string
   *   El mismo Pack, con los correos encontrados como cita.
   */
  public function quote(string $markdown, ?array $payload): string {
    $lineas = preg_split('/\R/u', $markdown) ?: [];

    foreach ($payload['accounts'] ?? [] as $cuenta) {
      $correo = is_array($cuenta) ? (string) ($cuenta['outreach_message'] ?? '') : '';
      $parrafos = array_values(array_filter(array_map([$this, 'normalizar'], preg_split('/\R/u', $correo) ?: []), 'strlen'));

      if (mb_strlen(implode(' ', $parrafos)) < self::LONGITUD_MINIMA) {
        continue;
      }

      $tramo = $this->buscar($lineas, $parrafos);

      if ($tramo !== NULL) {
        $lineas = $this->citar($lineas, $tramo[0], $tramo[1]);
      }
    }

    return implode("\n", $lineas);
  }

  /**
   * Dónde está el correo: primera y última línea, o NULL si no está entero.
   *
   * Busca los párrafos en líneas no vacías CONSECUTIVAS. Las líneas en blanco
   * entre ellos no cuentan, porque el agente separa los párrafos con una; pero
   * cualquier otra línea en medio significa que no es el mismo bloque.
   */
  private function buscar(array $lineas, array $parrafos): ?array {
    $utiles = [];

    foreach ($lineas as $i => $linea) {
      $normal = $this->normalizar($linea);

      if ($normal !== '') {
        $utiles[] = [$i, $normal];
      }
    }

    $total = count($parrafos);

    for ($inicio = 0; $inicio + $total <= count($utiles); $inicio++) {
      for ($k = 0; $k < $total; $k++) {
        if ($utiles[$inicio + $k][1] !== $parrafos[$k]) {
          continue 2;
        }
      }

      return [$utiles[$inicio][0], $utiles[$inicio + $total - 1][0]];
    }

    return NULL;
  }

  /**
   * Antepone «> » a cada línea del tramo, conservando su sangría.
   *
   * Las líneas en blanco de dentro llevan «>» también: en Markdown, una línea
   * en blanco sin él cierra la cita, y el correo saldría partido en tantas
   * citas como párrafos. Una línea que ya era cita se deja como está, para no
   * anidar una cita dentro de otra.
   */
  private function citar(array $lineas, int $desde, int $hasta): array {
    for ($i = $desde; $i <= $hasta; $i++) {
      if (preg_match('/^(\s*)>/u', $lineas[$i])) {
        continue;
      }

      $lineas[$i] = trim($lineas[$i]) === ''
        ? '>'
        : preg_replace('/^(\s*)/u', '$1> ', $lineas[$i], 1);
    }

    return $lineas;
  }

  /**
   * Una línea reducida a su texto, para comparar sin que el formato estorbe.
   *
   * Quita la marca de cita o de lista del principio y las de énfasis, y junta
   * los espacios. Es la misma comparación con la que se midió en producción
   * que los correos aparecen enteros en el texto.
   */
  private function normalizar(string $linea): string {
    // La cita no exige espacio detrás: una línea que es solo «>» separa
    // párrafos dentro de una cita y tiene que contar como vacía.
    $sinMarca = preg_replace('/^\s*(?:>\s*|[-*]\s+|\d+[.)]\s+)/u', '', $linea) ?? $linea;
    $sinEnfasis = str_replace(['**', '*', '_', '`'], '', $sinMarca);

    return trim(preg_replace('/\s+/u', ' ', $sinEnfasis) ?? $sinEnfasis);
  }

}
