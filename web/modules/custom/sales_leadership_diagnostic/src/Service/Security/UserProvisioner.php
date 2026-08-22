<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Security;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\externalauth\ExternalAuthInterface;
use Drupal\sales_leadership_diagnostic\DTO\SsoIdentity;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\user\UserInterface;

/**
 * Crea o recupera la cuenta de Drupal que corresponde a un alumno.
 *
 * La correspondencia se guarda en el authmap de externalauth, indexada por el
 * identificador de WordPress. Ese identificador es LA identidad; el correo
 * electrónico solo sirve para poblar el perfil.
 *
 * El nombre de usuario que se genera es técnico y determinista —«sld_wp_4821»—
 * y no se deriva del nombre que envía WordPress. Derivarlo del nombre para
 * mostrar permitiría que alguien con un nombre elegido a conveniencia acabara
 * con una cuenta llamada como otra persona. La interfaz del módulo muestra el
 * nombre real, que se guarda junto a la correspondencia.
 */
final class UserProvisioner {

  private LoggerChannelInterface $logger;

  public function __construct(
    private readonly ExternalAuthInterface $externalAuth,
    private readonly AuthmapInterface $authmap,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL);
  }

  /**
   * Devuelve la cuenta del alumno, creándola si es su primera visita.
   *
   * No inicia sesión: eso lo hace el controlador, y solo después de que la
   * autorización se haya confirmado.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\DiagnosticException
   */
  public function provision(SsoIdentity $identity): UserInterface {
    $existing = $this->externalAuth->load(
      $identity->externalUserId,
      SalesLeadershipDiagnostic::AUTHMAP_PROVIDER,
    );

    if ($existing instanceof UserInterface) {
      return $this->refresh($existing, $identity);
    }

    $this->assertNoEmailCollision($identity);

    return $this->register($identity);
  }

  /**
   * Nombre para mostrar de una cuenta provisionada.
   *
   * Se lee de los datos guardados con la correspondencia. Si no hay ninguno
   * —una cuenta creada a mano, por ejemplo— se cae al nombre de usuario.
   */
  public function getDisplayName(AccountInterface $account): string {
    $uid = (int) $account->id();

    if ($uid <= 0) {
      return $account->getAccountName();
    }

    $data = $this->authmap->getAuthData($uid, SalesLeadershipDiagnostic::AUTHMAP_PROVIDER);

    if (is_array($data) && isset($data['data'])) {
      $decoded = json_decode((string) $data['data'], TRUE);

      if (is_array($decoded) && trim((string) ($decoded['name'] ?? '')) !== '') {
        return (string) $decoded['name'];
      }
    }

    return $account->getAccountName();
  }

  /**
   * Identificador de WordPress asociado a una cuenta de Drupal.
   *
   * Devuelve NULL si la cuenta no procede de WordPress. Esa distinción es la
   * que impide que una cuenta creada a mano en Drupal entre al diagnóstico.
   */
  public function getExternalUserId(AccountInterface $account): ?string {
    $uid = (int) $account->id();

    if ($uid <= 0) {
      return NULL;
    }

    $authname = $this->authmap->get($uid, SalesLeadershipDiagnostic::AUTHMAP_PROVIDER);

    return is_string($authname) && $authname !== '' ? $authname : NULL;
  }

  /**
   * Actualiza una cuenta existente con los datos del token.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\DiagnosticException
   */
  private function refresh(UserInterface $account, SsoIdentity $identity): UserInterface {
    if ($account->isBlocked()) {
      // Una cuenta bloqueada lo está por una decisión de un administrador.
      // El token no debe poder revertirla.
      $this->logger->warning('Se rechazó el acceso de una cuenta bloqueada (uid @uid).', [
        '@uid' => $account->id(),
      ]);

      throw new DiagnosticException('La cuenta está bloqueada.');
    }

    $changed = FALSE;

    // El rol se reasigna si falta. Hace la operación idempotente y repara una
    // cuenta a la que se le hubiera retirado el rol por error.
    if (!$account->hasRole(SalesLeadershipDiagnostic::STUDENT_ROLE_ID)) {
      $account->addRole(SalesLeadershipDiagnostic::STUDENT_ROLE_ID);
      $changed = TRUE;
    }

    // El correo se actualiza si cambió en WordPress, salvo que ya pertenezca a
    // otra cuenta de Drupal.
    if ($identity->email !== '' && $account->getEmail() !== $identity->email) {
      if ($this->findUserByEmail($identity->email, (int) $account->id()) === NULL) {
        $account->setEmail($identity->email);
        $changed = TRUE;
      }
      else {
        $this->logger->warning('No se actualizó el correo de la cuenta @uid porque ya pertenece a otra cuenta.', [
          '@uid' => $account->id(),
        ]);
      }
    }

    if ($changed) {
      $account->save();
    }

    $this->storeDisplayName($account, $identity);

    return $account;
  }

  /**
   * Registra una cuenta nueva vinculada al usuario de WordPress.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\DiagnosticException
   */
  private function register(SsoIdentity $identity): UserInterface {
    try {
      $account = $this->externalAuth->register(
        $identity->externalUserId,
        SalesLeadershipDiagnostic::AUTHMAP_PROVIDER,
        [
          'mail' => $identity->email !== '' ? $identity->email : NULL,
          'status' => 1,
        ],
        json_encode(['name' => $identity->name], JSON_UNESCAPED_UNICODE),
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('No se pudo crear la cuenta del alumno externo @uid: @type.', [
        '@uid' => $identity->externalUserId,
        '@type' => get_class($e),
      ]);

      throw new DiagnosticException('No se pudo crear la cuenta del alumno.', 0, $e);
    }

    if (!$account instanceof UserInterface) {
      throw new DiagnosticException('El registro no devolvió una cuenta válida.');
    }

    $account->addRole(SalesLeadershipDiagnostic::STUDENT_ROLE_ID);
    $account->save();

    $this->logger->info('Cuenta creada para el alumno externo @uid (uid de Drupal: @drupal).', [
      '@uid' => $identity->externalUserId,
      '@drupal' => $account->id(),
    ]);

    return $account;
  }

  /**
   * Impide vincular por correo una cuenta que no está mapeada.
   *
   * Esta es la comprobación de seguridad más importante de la clase. Si el
   * correo del token ya pertenece a una cuenta de Drupal que NO corresponde a
   * este usuario de WordPress, no se vincula: se deniega y se avisa.
   *
   * Vincular automáticamente sería un vector de apropiación de cuentas. Quien
   * pudiera fijar una dirección en WordPress heredaría la cuenta de Drupal con
   * ese mismo correo, incluida la de un administrador. §11 lo dice sin
   * ambigüedad: no confiar únicamente en el correo electrónico.
   *
   * @throws \Drupal\sales_leadership_diagnostic\Exception\DiagnosticException
   */
  private function assertNoEmailCollision(SsoIdentity $identity): void {
    if ($identity->email === '') {
      return;
    }

    $conflicting = $this->findUserByEmail($identity->email);

    if ($conflicting === NULL) {
      return;
    }

    $this->logger->error('Colisión de correo al provisionar al alumno externo @uid: la dirección ya pertenece a la cuenta @conflict, que no está vinculada a WordPress. No se vinculan cuentas por correo electrónico; requiere resolución manual.', [
      '@uid' => $identity->externalUserId,
      '@conflict' => $conflicting->id(),
    ]);

    throw new DiagnosticException('El correo ya está en uso por otra cuenta.');
  }

  /**
   * Busca una cuenta por correo, excluyendo opcionalmente una.
   */
  private function findUserByEmail(string $email, int $excludeUid = 0): ?UserInterface {
    $accounts = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);

    foreach ($accounts as $account) {
      if ($account instanceof UserInterface && (int) $account->id() !== $excludeUid) {
        return $account;
      }
    }

    return NULL;
  }

  /**
   * Guarda el nombre para mostrar junto a la correspondencia.
   */
  private function storeDisplayName(UserInterface $account, SsoIdentity $identity): void {
    if ($identity->name === '') {
      return;
    }

    if ($this->getDisplayName($account) === $identity->name) {
      return;
    }

    $this->authmap->save(
      $account,
      SalesLeadershipDiagnostic::AUTHMAP_PROVIDER,
      $identity->externalUserId,
      json_encode(['name' => $identity->name], JSON_UNESCAPED_UNICODE),
    );
  }

}
