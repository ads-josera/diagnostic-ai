<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Security;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;

/**
 * Impide que un token de acceso se use más de una vez (§10).
 *
 * El token viaja en la URL, así que acaba en el historial del navegador y
 * puede quedar en registros de servidores intermedios. Que caduque en segundos
 * limita el daño, pero dentro de esa ventana un token capturado seguiría
 * sirviendo. Consumirlo al primer uso cierra esa ventana del todo.
 *
 * La operación tiene que ser atómica. Comprobar si existe y después
 * escribirlo dejaría un hueco entre ambas cosas: dos peticiones simultáneas
 * con el mismo token pasarían las dos la comprobación. Por eso se usa
 * setWithExpireIfNotExists(), que resuelve la comprobación y la escritura en
 * un solo paso y devuelve si ha escrito.
 */
final class ReplayGuard {

  /**
   * Colección donde se registran los identificadores consumidos.
   */
  private const COLLECTION = 'sales_leadership_diagnostic.consumed_tokens';

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
  ) {}

  /**
   * Marca un identificador de token como usado.
   *
   * @param string $tokenId
   *   Claim `jti` del token.
   * @param int $lifetime
   *   Cuánto recordarlo. Basta con que supere la vigencia del token: pasado
   *   ese punto el propio `exp` ya lo rechaza, y seguir recordándolo solo
   *   ocuparía espacio.
   *
   * @return bool
   *   TRUE si es la primera vez; FALSE si ya se había consumido.
   */
  public function consume(string $tokenId, int $lifetime): bool {
    if (trim($tokenId) === '') {
      // Un token sin identificador no puede protegerse contra repetición.
      // Rechazarlo es preferible a aceptarlo sin esa garantía.
      return FALSE;
    }

    return $this->keyValueFactory
      ->get(self::COLLECTION)
      ->setWithExpireIfNotExists($this->key($tokenId), TRUE, max(1, $lifetime));
  }

  /**
   * Deriva la clave de almacenamiento.
   *
   * Se guarda la huella y no el identificador en claro: el almacén es una
   * tabla más de la base de datos y no hay ninguna razón para conservar ahí
   * material relacionado con tokens.
   */
  private function key(string $tokenId): string {
    return hash('sha256', $tokenId);
  }

}
