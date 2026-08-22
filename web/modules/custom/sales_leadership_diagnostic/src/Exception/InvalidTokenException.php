<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Exception;

/**
 * El token de acceso no es válido.
 *
 * Cubre firma incorrecta, expiración, emisor o audiencia que no coinciden,
 * formato malformado y reutilización de un token ya consumido.
 *
 * Todas esas causas se tratan igual de cara al usuario: no se entra. Se
 * distinguen únicamente en el log, porque para un administrador no es lo mismo
 * un token caducado —normal, alguien tardó demasiado— que una firma inválida,
 * que puede indicar que los secretos se han desincronizado o que alguien está
 * probando suerte.
 */
final class InvalidTokenException extends DiagnosticException {

}
