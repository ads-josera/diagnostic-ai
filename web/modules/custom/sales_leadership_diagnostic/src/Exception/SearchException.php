<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * No se pudo buscar en internet.
 *
 * Existe separada de EngineException porque quien la recibe hace algo distinto
 * con ella: un fallo del motor corta el turno, y un fallo del buscador NO debe
 * cortarlo. El agente tiene que enterarse de que esa búsqueda no ocurrió para
 * poder declarar su cobertura real y seguir con lo que no dependa de ella, que
 * es exactamente lo que manda su metodología.
 */
final class SearchException extends DiagnosticException {

}
