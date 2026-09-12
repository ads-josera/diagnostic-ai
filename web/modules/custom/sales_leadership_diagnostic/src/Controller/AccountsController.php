<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\BuyerTruth;
use Drupal\sales_leadership_diagnostic\ExecutionState;
use Drupal\sales_leadership_diagnostic\Service\Account\AccountRegistry;
use Drupal\sales_leadership_diagnostic\Service\Agent\AgentRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * «Mis cuentas»: todas las cuentas del alumno y lo que pasó con cada una.
 *
 * La pantalla sigue el §11 del Documento 8 del cliente, que se titula
 * «Feedback Without Forms»: un clic para el estado, y como mucho una etiqueta
 * y una frase opcionales. Nada de formularios de doce campos. Los cinco botones
 * son exactamente las cinco preguntas de su §12.
 */
final class AccountsController extends ControllerBase {

  /**
   * Qué se enseña según la pestaña.
   */
  private const VISTAS = ['todas', 'pendientes', 'registradas'];

  public function __construct(
    private readonly AccountRegistry $registry,
    private readonly AgentRegistry $agents,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AccountRegistry::class),
      $container->get(AgentRegistry::class),
      $container->get('date.formatter'),
      $container->get('csrf_token'),
    );
  }

  /**
   * La pantalla.
   */
  public function view(Request $request): array {
    $uid = (int) $this->currentUser()->id();
    $vista = (string) $request->query->get('ver', 'todas');
    $vista = in_array($vista, self::VISTAS, TRUE) ? $vista : 'todas';

    $todas = $this->registry->forUser($uid);
    $eventos = $this->registry->eventsForUser($uid);

    $pendientes = array_filter($todas, static fn (array $c): bool => $c['state'] === NULL);
    $registradas = array_filter($todas, static fn (array $c): bool => $c['state'] !== NULL);

    $visibles = match ($vista) {
      'pendientes' => $pendientes,
      'registradas' => $registradas,
      default => $todas,
    };

    $filas = [];

    foreach ($visibles as $cuenta) {
      $filas[] = $this->fila($cuenta, $eventos[$cuenta['id']] ?? [], $vista);
    }

    return [
      '#theme' => 'sld_accounts',
      '#accounts' => $filas,
      '#view' => $vista,
      '#tabs' => $this->pestanas($vista, count($todas), count($pendientes), count($registradas)),
      '#figures' => $this->cifras($todas),
      '#quick_states' => $this->estadosRapidos(),
      '#other_states' => $this->otrosEstados(),
      '#truths' => $this->verdades(),
      '#note_max' => AccountRegistry::NOTE_MAX,
      '#dashboard_url' => Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/accounts'],
      ],
      '#cache' => [
        // Cambia con cada resultado que registra y con cada Pack nuevo, y es
        // de una sola persona: no se cachea.
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Anota lo que pasó con una cuenta y vuelve a ella.
   *
   * La ruta exige POST y token. Que la cuenta sea de quien la registra lo
   * comprueba el registro, que es la última puerta antes de escribir.
   */
  public function record(int $account, Request $request): RedirectResponse {
    $uid = (int) $this->currentUser()->id();

    // Un botón rápido manda su estado en `state`; el desplegable, en
    // `state_otro`. Si llegan los dos, manda el botón pulsado.
    $codigo = (string) ($request->request->get('state') ?: $request->request->get('state_otro', ''));
    $estado = ExecutionState::tryFrom($codigo);
    $verdad = BuyerTruth::tryFrom((string) $request->request->get('truth', ''));
    $nota = (string) $request->request->get('note', '');
    $vista = (string) $request->request->get('ver', 'todas');

    if ($estado === NULL) {
      $this->messenger()->addWarning($this->t('Elige qué pasó con la cuenta para poder anotarlo.'));

      return $this->volver($vista, $account);
    }

    if (!$this->registry->recordOutcome($uid, $account, $estado, $verdad, $nota)) {
      // No es suya, o no existe. No se dice cuál: distinguirlo le enseñaría a
      // quien prueba identificadores cuáles son de otra persona.
      throw new AccessDeniedHttpException();
    }

    $this->messenger()->addStatus($this->t('Anotado: @estado.', ['@estado' => $estado->label()]));

    return $this->volver($vista, $account);
  }

  /**
   * Una cuenta, lista para pintar.
   *
   * @param array<string, mixed> $cuenta
   *   La cuenta, tal como la devuelve AccountRegistry::forUser().
   * @param array<int, array<string, mixed>> $eventos
   *   Su historial.
   * @param string $vista
   *   Pestaña en la que se está, para volver a ella al registrar.
   *
   * @return array<string, mixed>
   *   Las variables de la plantilla.
   */
  private function fila(array $cuenta, array $eventos, string $vista): array {
    $estado = $cuenta['state'];
    $verdad = $cuenta['truth'];
    $agente = $this->agents->get((string) $cuenta['agent']);

    return [
      'id' => $cuenta['id'],
      'name' => $cuenta['name'],
      'agent' => $agente?->label() ?? '',
      'disposition' => $cuenta['disposition'],
      'packs' => $cuenta['times_in_pack'],
      'first_seen' => $this->dia((int) $cuenta['first_seen']),
      'last_seen' => $this->dia((int) $cuenta['last_seen']),
      'result_url' => $cuenta['last_result_id'] > 0
        ? Url::fromRoute('sales_leadership_diagnostic.result', ['sld_diagnostic_result' => $cuenta['last_result_id']])->toString()
        : NULL,
      'state' => $estado instanceof ExecutionState ? [
        'code' => $estado->value,
        'label' => $estado->label(),
        'tone' => $estado->tone(),
      ] : NULL,
      'truth' => $verdad instanceof BuyerTruth ? $verdad->label() : NULL,
      'note' => $cuenta['note'],
      'outcome_at' => $cuenta['outcome_at'] !== NULL ? $this->dia((int) $cuenta['outcome_at']) : NULL,
      'history' => $this->historial($eventos),
      'record_url' => $this->urlConToken('sales_leadership_diagnostic.account_outcome', ['account' => $cuenta['id']]),
      'view' => $vista,
    ];
  }

  /**
   * El historial de una cuenta, en frases que se leen.
   *
   * @param array<int, array<string, mixed>> $eventos
   *   Eventos de la cuenta.
   *
   * @return array<int, array<string, string>>
   *   Uno por evento, del más reciente al más antiguo.
   */
  private function historial(array $eventos): array {
    $salida = [];

    foreach ($eventos as $evento) {
      if ($evento['kind'] === 'pack') {
        $texto = (string) $this->t('Salió en un Pack como @d', [
          '@d' => $evento['disposition'] !== '' ? $evento['disposition'] : $this->t('cuenta cribada'),
        ]);
      }
      else {
        $estado = $evento['state'] instanceof ExecutionState ? $evento['state']->label() : '';
        $verdad = $evento['truth'] instanceof BuyerTruth ? ' · ' . $evento['truth']->label() : '';
        $texto = $estado . $verdad;
      }

      $salida[] = [
        'when' => $this->dia((int) $evento['created']),
        'text' => $texto,
        'note' => (string) $evento['note'],
      ];
    }

    return array_reverse($salida);
  }

  /**
   * Las pestañas, con cuántas hay en cada una.
   *
   * @return array<int, array<string, mixed>>
   *   Una por pestaña.
   */
  private function pestanas(string $actual, int $todas, int $pendientes, int $registradas): array {
    $salida = [];

    foreach ([
      'todas' => [$this->t('Todas'), $todas],
      'pendientes' => [$this->t('Sin registrar'), $pendientes],
      'registradas' => [$this->t('Con resultado'), $registradas],
    ] as $clave => [$titulo, $cuantas]) {
      $salida[] = [
        'label' => $titulo,
        'count' => $cuantas,
        'url' => Url::fromRoute('sales_leadership_diagnostic.accounts', [], ['query' => ['ver' => $clave]])->toString(),
        'active' => $clave === $actual,
      ];
    }

    return $salida;
  }

  /**
   * Las cifras de arriba.
   *
   * Siguen su §13 —«Activity Is Not Pipeline»—: se separan las que avanzan
   * de las que ya son oportunidad, en vez de sumar todo lo que se movió.
   *
   * @param array<int, array<string, mixed>> $cuentas
   *   Todas las cuentas.
   *
   * @return array<string, int>
   *   Las cifras.
   */
  private function cifras(array $cuentas): array {
    $cifras = ['total' => count($cuentas), 'pendientes' => 0, 'avanzan' => 0, 'oportunidades' => 0];

    foreach ($cuentas as $cuenta) {
      $estado = $cuenta['state'];

      if ($estado === NULL) {
        $cifras['pendientes']++;
        continue;
      }

      match ($estado->tone()) {
        'avance' => $cifras['avanzan']++,
        'logro' => $cifras['oportunidades']++,
        default => NULL,
      };
    }

    return $cifras;
  }

  /**
   * Los cinco estados de un clic, en el orden de su §12.
   *
   * @return array<int, array<string, mixed>>
   *   Código y nombre.
   */
  private function estadosRapidos(): array {
    return array_values(array_map(
      static fn (ExecutionState $e): array => ['code' => $e->value, 'label' => $e->label()],
      array_filter(ExecutionState::cases(), static fn (ExecutionState $e): bool => $e->isQuick()),
    ));
  }

  /**
   * El resto de estados, para el desplegable.
   *
   * @return array<int, array<string, mixed>>
   *   Código y nombre.
   */
  private function otrosEstados(): array {
    return array_values(array_map(
      static fn (ExecutionState $e): array => ['code' => $e->value, 'label' => $e->label()],
      array_filter(ExecutionState::cases(), static fn (ExecutionState $e): bool => !$e->isQuick()),
    ));
  }

  /**
   * Las etiquetas de verdad del Buyer.
   *
   * @return array<int, array<string, mixed>>
   *   Código y nombre.
   */
  private function verdades(): array {
    return array_map(
      static fn (BuyerTruth $v): array => ['code' => $v->value, 'label' => $v->label()],
      BuyerTruth::cases(),
    );
  }

  /**
   * De vuelta a la pantalla, en la misma pestaña y a la altura de la cuenta.
   */
  private function volver(string $vista, int $cuenta): RedirectResponse {
    $vista = in_array($vista, self::VISTAS, TRUE) ? $vista : 'todas';

    return new RedirectResponse(Url::fromRoute(
      'sales_leadership_diagnostic.accounts',
      [],
      ['query' => ['ver' => $vista], 'fragment' => 'cuenta-' . $cuenta],
    )->toString());
  }

  /**
   * Una fecha corta. Aquí importan semanas, no horas.
   */
  private function dia(int $marca): string {
    return $marca > 0 ? $this->dateFormatter->format($marca, 'custom', 'd/m/Y') : '';
  }

  /**
   * Una URL con su token CSRF, igual que las de olvidar memoria.
   *
   * @param string $ruta
   *   Ruta.
   * @param array<string, mixed> $parametros
   *   Parámetros de la ruta.
   */
  private function urlConToken(string $ruta, array $parametros): string {
    $interna = ltrim(Url::fromRoute($ruta, $parametros)->getInternalPath(), '/');

    return Url::fromRoute($ruta, $parametros, ['query' => ['token' => $this->csrfToken->get($interna)]])->toString();
  }

}
