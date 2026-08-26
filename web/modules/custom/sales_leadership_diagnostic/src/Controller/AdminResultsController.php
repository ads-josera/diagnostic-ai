<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSessionInterface;
use Drupal\sales_leadership_diagnostic\Form\ResultsFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Listado de administración de los resultados de diagnóstico.
 *
 * El módulo declara sus resultados SIN handler de Views a propósito (§43): sus
 * campos contienen el análisis del negocio del alumno, y una vista de Views
 * dejaría que cualquier administrador añadiese el campo «payload» a una tabla
 * y expusiera ese contenido sin darse cuenta.
 *
 * Este listado es la alternativa deliberada. Muestra únicamente metadatos
 * —cuándo, quién, con qué agente, en qué estado, cuántos turnos, qué
 * puntuación y con qué versión— y nunca el contenido del diagnóstico. Para
 * leerlo hay que abrir el resultado concreto, y esa lectura queda registrada
 * cuando el resultado no es propio.
 *
 * **Lista SESIONES, no resultados** (26-08-2026). Listaba resultados, y un
 * resultado solo existe cuando el diagnóstico terminó: quien empezaba, se
 * atascaba y abandonaba era invisible. Para un producto que se vende como
 * entregable esa es la cifra que más duele ignorar, porque el cliente se
 * enteraba por una queja en lugar de por una pantalla.
 *
 * Sirve a tres necesidades operativas reales:
 *
 *  - Soporte: responder a un alumno que pregunta por su diagnóstico.
 *  - Entrega: ver quién empezó y no terminó, que es a quién hay que empujar.
 *  - Control de calidad del prompt: la distribución de puntuaciones y el
 *    número de turnos. Un diagnóstico cerrado en tres turnos concluyó sin
 *    evidencia; uno que llegó al tope se cortó a la fuerza. Las dos cosas
 *    delatan un prompt que no funciona, y de otro modo solo se verían leyendo
 *    conversaciones una a una.
 */
final class AdminResultsController extends ControllerBase {

  /**
   * Resultados por página.
   *
   * Suficiente para revisar una tanda sin paginar constantemente, y lo bastante
   * bajo para no cargar miles de entidades en memoria.
   */
  private const PER_PAGE = 50;

  /**
   * Valor del filtro que agrupa todo lo que no llegó a resultado.
   *
   * No es un estado de la entidad: es la pregunta que de verdad se hace el
   * gestor —«¿quién empezó y no terminó?»—, y responderla obligaba si no a
   * marcar cuatro casillas.
   */
  public const ESTADO_SIN_TERMINAR = 'sin_terminar';

  /**
   * Nombres de agente ya resueltos, para no consultar uno por fila.
   *
   * @var array<string, string>
   */
  private array $agentLabels = [];

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  /**
   * Construye la página del listado.
   */
  public function view(): array {
    $filters = $this->currentFilters();

    $build['filtros'] = $this->formBuilder()->getForm(ResultsFilterForm::class);

    $ids = $this->querySessionIds($filters);

    $build['tabla'] = [
      '#type' => 'table',
      '#header' => [
        'created' => $this->t('Fecha'),
        'user' => $this->t('Alumno'),
        'agent' => $this->t('Agente'),
        'status' => $this->t('Estado'),
        'turns' => $this->t('Turnos'),
        'score' => $this->t('Puntuación'),
        'version' => $this->t('Versión'),
        'operations' => $this->t('Operaciones'),
      ],
      '#rows' => $this->buildRows($ids),
      '#empty' => $this->t('No hay diagnósticos que coincidan con el filtro.'),
      '#attributes' => ['class' => ['sld-admin-results']],
    ];

    $build['pager'] = ['#type' => 'pager'];

    $build['#cache'] = [
      // El listado depende de los parámetros de la URL y del permiso de quien
      // mira. No se cachea entre usuarios ni entre filtros distintos.
      'contexts' => ['url.query_args', 'user.permissions'],
      // Depende de las sesiones y de los resultados: una sesión que termina
      // cambia su estado, y el resultado que nace le añade la puntuación.
      'tags' => [
        'sld_diagnostic_session_list',
        'sld_diagnostic_result_list',
      ],
    ];

    return $build;
  }

  /**
   * Lee y normaliza los filtros de la URL.
   *
   * Se leen de la petición y no del estado del formulario porque el formulario
   * usa método GET: así la URL filtrada es enlazable y se puede compartir con
   * un compañero de soporte sin explicarle qué hay que teclear.
   *
   * @return array{alumno: string, desde: ?int, hasta: ?int, agente: string, estado: string}
   *   Filtros ya validados.
   */
  private function currentFilters(): array {
    $query = $this->requestStack->getCurrentRequest()->query;

    return [
      'alumno' => trim((string) $query->get('alumno', '')),
      'agente' => trim((string) $query->get('agente', '')),
      'estado' => trim((string) $query->get('estado', '')),
      'desde' => $this->parseDate((string) $query->get('desde', '')),
      // El día «hasta» se incluye entero: quien filtra hasta el 20 espera ver
      // lo del día 20, no lo anterior a su medianoche.
      'hasta' => $this->parseDate((string) $query->get('hasta', ''), TRUE),
    ];
  }

  /**
   * Convierte una fecha del formulario en marca de tiempo.
   *
   * @param string $value
   *   Fecha en formato AAAA-MM-DD, o cadena vacía.
   * @param bool $endOfDay
   *   TRUE para situarla al final del día en lugar del principio.
   *
   * @return int|null
   *   Marca de tiempo, o NULL si no había fecha o no era válida.
   */
  private function parseDate(string $value, bool $endOfDay = FALSE): ?int {
    if ($value === '') {
      return NULL;
    }

    $date = \DateTimeImmutable::createFromFormat(
      'Y-m-d H:i:s',
      $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00'),
    );

    return $date === FALSE ? NULL : $date->getTimestamp();
  }

  /**
   * Consulta los identificadores de SESIÓN que cumplen el filtro.
   *
   * @param array{alumno: string, desde: ?int, hasta: ?int, agente: string, estado: string} $filters
   *   Filtros normalizados.
   *
   * @return int[]
   *   Identificadores de la página actual, del más reciente al más antiguo.
   */
  private function querySessionIds(array $filters): array {
    $query = $this->entityTypeManager()
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      // La puerta de este listado es el permiso de la ruta, comprobado por el
      // enrutador antes de llegar aquí. No se delega en accessCheck(): para una
      // entidad sin handler de acceso a consultas no filtra nada, y confiar en
      // que lo hace daría una falsa sensación de seguridad.
      ->accessCheck(FALSE)
      // Los ensayos del gestor no son diagnósticos de nadie: mezclarlos aquí
      // daría un listado en el que no se puede confiar para dar soporte.
      ->condition('is_sandbox', FALSE)
      ->sort('created', 'DESC')
      ->pager(self::PER_PAGE);

    if ($filters['desde'] !== NULL) {
      $query->condition('created', $filters['desde'], '>=');
    }

    if ($filters['hasta'] !== NULL) {
      $query->condition('created', $filters['hasta'], '<=');
    }

    if ($filters['agente'] !== '') {
      $query->condition('agent', $filters['agente']);
    }

    if ($filters['estado'] === self::ESTADO_SIN_TERMINAR) {
      // Agrupa lo que NO llegó a resultado. Es la consulta que justifica esta
      // pantalla: quien empezó y se quedó por el camino.
      $query->condition('status', [
        DiagnosticStatus::Draft->value,
        DiagnosticStatus::InProgress->value,
        DiagnosticStatus::Processing->value,
        DiagnosticStatus::Failed->value,
      ], 'IN');
    }
    elseif ($filters['estado'] !== '') {
      $query->condition('status', $filters['estado']);
    }

    if ($filters['alumno'] !== '') {
      $uids = $this->matchingUserIds($filters['alumno']);

      if ($uids === []) {
        return [];
      }

      $query->condition('uid', $uids, 'IN');
    }

    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Busca alumnos cuyo nombre o correo contenga el texto dado.
   *
   * @param string $needle
   *   Texto tecleado en el filtro.
   *
   * @return int[]
   *   Identificadores de usuario coincidentes.
   */
  private function matchingUserIds(string $needle): array {
    $storage = $this->entityTypeManager()->getStorage('user');

    $query = $storage->getQuery()->accessCheck(FALSE);
    $group = $query->orConditionGroup()
      ->condition('name', $needle, 'CONTAINS')
      ->condition('mail', $needle, 'CONTAINS');

    return array_map('intval', array_values($query->condition($group)->execute()));
  }

  /**
   * Construye las filas del listado.
   *
   * Se cargan los resultados de una sola consulta indexados por sesión, en vez
   * de uno por fila: con cincuenta filas, lo segundo son cincuenta consultas
   * para pintar una columna.
   *
   * @param int[] $ids
   *   Identificadores de sesión.
   *
   * @return array<int, array<string, mixed>>
   *   Filas listas para el elemento table.
   */
  private function buildRows(array $ids): array {
    if ($ids === []) {
      return [];
    }

    $sessions = $this->entityTypeManager()
      ->getStorage('sld_diagnostic_session')
      ->loadMultiple($ids);

    $results = $this->loadResultsBySession($ids);
    $etiquetas = DiagnosticStatus::allowedValues();
    $rows = [];

    foreach ($sessions as $session) {
      // loadMultiple() promete EntityInterface, no la interfaz del módulo. La
      // comprobación deja explícito el contrato en lugar de darlo por hecho.
      if (!$session instanceof DiagnosticSessionInterface) {
        continue;
      }

      $owner = $session->getOwner();
      $estado = $session->getStatus();
      $resultado = $results[(int) $session->id()] ?? NULL;

      $rows[] = [
        'created' => $this->dateFormatter->format((int) $session->get('created')->value, 'short'),
        'user' => $owner === NULL
          ? $this->t('(cuenta eliminada)')
          : $owner->getAccountName(),
        'agent' => $this->agentLabel($session->getAgentId()),
        'status' => [
          'data' => [
            '#type' => 'html_tag',
            '#tag' => 'span',
            '#attributes' => ['class' => ['sld-status', 'sld-status--' . $estado->value]],
            '#value' => $etiquetas[$estado->value] ?? $estado->value,
          ],
        ],
        'turns' => (int) $session->getTurnCount(),
        // Un resultado sin puntuación es posible: la metodología del cliente
        // podría no usar escala numérica. Se distingue del cero explícito, y
        // de que no haya resultado todavía.
        'score' => $resultado === NULL
          ? $this->t('—')
          : ($resultado->getScore() ?? $this->t('—')),
        'version' => $session->getDiagnosticVersion(),
        'operations' => $resultado === NULL
          ? $this->t('—')
          : [
            'data' => [
              '#type' => 'link',
              '#title' => $this->t('Ver'),
              '#url' => Url::fromRoute(
                'sales_leadership_diagnostic.result',
                ['sld_diagnostic_result' => $resultado->id()],
              ),
              '#attributes' => ['class' => ['button', 'button--small']],
            ],
          ],
      ];
    }

    return $rows;
  }

  /**
   * Resultados de las sesiones indicadas, indexados por sesión.
   *
   * @param int[] $sessionIds
   *   Sesiones de la página actual.
   *
   * @return array<int, \Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface>
   *   Resultados encontrados, por identificador de sesión.
   */
  private function loadResultsBySession(array $sessionIds): array {
    $storage = $this->entityTypeManager()->getStorage('sld_diagnostic_result');

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('session_id', $sessionIds, 'IN')
      ->execute();

    if ($ids === []) {
      return [];
    }

    $porSesion = [];

    foreach ($storage->loadMultiple($ids) as $resultado) {
      if ($resultado instanceof DiagnosticResultInterface) {
        $porSesion[(int) $resultado->getSessionId()] = $resultado;
      }
    }

    return $porSesion;
  }

  /**
   * Nombre legible del agente, o su identificador si ya no existe.
   *
   * Un agente puede borrarse teniendo diagnósticos hechos con él. El historial
   * conserva su identificador a propósito, así que lo peor que puede pasar es
   * que la columna muestre el identificador en vez del nombre — que sigue
   * diciendo la verdad.
   */
  private function agentLabel(string $agentId): string {
    if ($agentId === '') {
      return (string) $this->t('—');
    }

    if (!array_key_exists($agentId, $this->agentLabels)) {
      $agente = $this->entityTypeManager()->getStorage('sld_agent')->load($agentId);
      $this->agentLabels[$agentId] = $agente === NULL
        ? $agentId
        : (string) $agente->label();
    }

    return $this->agentLabels[$agentId];
  }

}
