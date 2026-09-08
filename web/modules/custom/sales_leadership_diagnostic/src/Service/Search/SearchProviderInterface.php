<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Search;

/**
 * Contrato de un buscador externo.
 *
 * Es la única frontera entre el módulo y quien busca. Ninguna otra clase debe
 * saber cuál es, de modo que cambiar de proveedor sea una clase más y una
 * línea de services.yml: la elección se tomó con precios de un día concreto y
 * esos cambian.
 *
 * Devuelve resultados con su enlace, nunca texto suelto. La metodología del
 * cliente prohíbe afirmar sin procedencia, así que un buscador que no diga de
 * dónde sale cada cosa no sirve aquí.
 */
interface SearchProviderInterface {

  /**
   * Busca y devuelve resultados con su procedencia.
   *
   * @param string $query
   *   Lo que se busca, tal como lo pidió el modelo.
   * @param int $max
   *   Cuántos resultados como mucho. Quien llama lo acota: el texto
   *   recuperado es la mayor parte de lo que cuesta una búsqueda.
   *
   * @return \Drupal\sales_leadership_diagnostic\DTO\SearchResult[]
   *   Los resultados, en el orden que dio el proveedor.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\SearchException
   *   Si no se pudo buscar. Nunca se devuelve una lista vacía para disimular
   *   un fallo: «no encontré nada» y «no pude buscar» llevan al agente a
   *   conclusiones distintas, y confundirlas le haría declarar cobertura que
   *   no tuvo.
   */
  public function search(string $query, int $max): array;

  /**
   * Si el buscador está configurado y puede usarse.
   *
   * Lo consulta el informe de estado, que necesita saberlo sin gastar una
   * búsqueda para averiguarlo.
   */
  public function isAvailable(): bool;

}
