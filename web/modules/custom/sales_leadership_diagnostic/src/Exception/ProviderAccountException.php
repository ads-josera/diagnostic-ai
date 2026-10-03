<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * El proveedor de IA no da servicio por un problema de la cuenta.
 *
 * Sin saldo, sin créditos o con la clave rechazada. Se separa de cualquier otro
 * fallo del motor porque se trata distinto en todos los sitios: no se reintenta
 * —el segundo intento falla igual que el primero—, y al alumno no se le dice
 * «intenta nuevamente», porque no va a funcionar hasta que alguien recargue la
 * cuenta. Se le dice lo mismo que ante el tope de gasto: que no ha perdido nada
 * y que avise a su instructor.
 *
 * El código de la excepción es 402 a propósito: no está en la lista de códigos
 * que el cliente reintenta.
 */
final class ProviderAccountException extends EngineException {

}
