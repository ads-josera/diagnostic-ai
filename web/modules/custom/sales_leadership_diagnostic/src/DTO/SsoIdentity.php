<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\DTO;

/**
 * Identidad extraída de un token de acceso ya validado.
 *
 * Que este objeto exista significa que la firma, la expiración, el emisor, la
 * audiencia y la unicidad del token ya se comprobaron. Ningún consumidor
 * necesita volver a verificarlos, y ninguno debería construirlo a mano.
 */
final readonly class SsoIdentity {

  /**
   * @param string $externalUserId
   *   Identificador del alumno en WordPress. Es LA identidad.
   * @param string $email
   *   Correo, solo para poblar el perfil la primera vez.
   * @param string $name
   *   Nombre para mostrar, solo para poblar el perfil.
   */
  public function __construct(
    public string $externalUserId,
    public string $email,
    public string $name,
  ) {}

}
