<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
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
 * —cuándo, quién, qué puntuación, con qué versión del prompt— y nunca el
 * contenido del diagnóstico. Para leerlo hay que abrir el resultado concreto,
 * y esa lectura queda registrada cuando el resultado no es propio.
 *
 * Sirve a dos necesidades operativas reales:
 *
 *  - Soporte: responder a un alumno que pregunta por su diagnóstico.
 *  - Control de calidad del prompt: ver la distribución de puntuaciones. Si el
 *    prompt no ancla la escala, aquí se ve de un vistazo que organizaciones
 *    parecidas reciben notas dispares.
 */
final class AdminResultsController extends ControllerBase {

  /**
   * Resultados por página.
   *
   * Suficiente para revisar una tanda sin paginar constantemente, y lo bastante
   * bajo para no cargar miles de entidades en memoria.
   */
  private const PER_PAGE = 50;

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

    $ids = $this->queryResultIds($filters);

    $build['tabla'] = [
      '#type' => 'table',
      '#header' => [
        'created' => $this->t('Fecha'),
        'user' => $this->t('Alumno'),
        'score' => $this->t('Puntuación'),
        'version' => $this->t('Versión del prompt'),
        'operations' => $this->t('Operaciones'),
      ],
      '#rows' => $this->buildRows($ids),
      '#empty' => $this->t('No hay resultados que coincidan con el filtro.'),
      '#attributes' => ['class' => ['sld-admin-results']],
    ];

    $build['pager'] = ['#type' => 'pager'];

    $build['#cache'] = [
      // El listado depende de los parámetros de la URL y del permiso de quien
      // mira. No se cachea entre usuarios ni entre filtros distintos.
      'contexts' => ['url.query_args', 'user.permissions'],
      'tags' => ['sld_diagnostic_result_list'],
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
   * @return array{alumno: string, desde: ?int, hasta: ?int}
   *   Filtros ya validados.
   */
  private function currentFilters(): array {
    $query = $this->requestStack->getCurrentRequest()->query;

    return [
      'alumno' => trim((string) $query->get('alumno', '')),
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
   * Consulta los identificadores de resultado que cumplen el filtro.
   *
   * @param array{alumno: string, desde: ?int, hasta: ?int} $filters
   *   Filtros normalizados.
   *
   * @return int[]
   *   Identificadores de la página actual, del más reciente al más antiguo.
   */
  private function queryResultIds(array $filters): array {
    $query = $this->entityTypeManager()
      ->getStorage('sld_diagnostic_result')
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

    if ($filters['alumno'] !== '') {
      $uids = $this->matchingUserIds($filters['alumno']);

      if ($uids === []) {
        // Ningún alumno coincide, así que el listado está vacío. Se fuerza con
        // una condición imposible en lugar de devolver antes, para que el
        // paginador y la tabla se construyan igual y la página no cambie de
        // forma según el filtro.
        $query->condition('uid', 0);
      }
      else {
        $query->condition('uid', $uids, 'IN');
      }
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
   * Construye las filas de la tabla.
   *
   * @param int[] $ids
   *   Identificadores de resultado.
   *
   * @return array<int, array<string, mixed>>
   *   Filas listas para el elemento table.
   */
  private function buildRows(array $ids): array {
    if ($ids === []) {
      return [];
    }

    $results = $this->entityTypeManager()
      ->getStorage('sld_diagnostic_result')
      ->loadMultiple($ids);

    $rows = [];

    foreach ($results as $result) {
      // loadMultiple() promete EntityInterface, no la interfaz del módulo. La
      // comprobación deja explícito el contrato en lugar de darlo por hecho.
      if (!$result instanceof DiagnosticResultInterface) {
        continue;
      }

      $owner = $result->getOwner();
      $score = $result->getScore();

      $rows[] = [
        'created' => $this->dateFormatter->format((int) $result->get('created')->value, 'short'),
        'user' => $owner === NULL
          ? $this->t('(cuenta eliminada)')
          : $owner->getAccountName(),
        // Un resultado sin puntuación es posible: la metodología del cliente
        // podría no usar escala numérica. Se distingue del cero explícito.
        'score' => $score ?? $this->t('—'),
        'version' => $result->getDiagnosticVersion(),
        'operations' => [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Ver'),
            '#url' => Url::fromRoute(
              'sales_leadership_diagnostic.result',
              ['sld_diagnostic_result' => $result->id()],
            ),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ],
      ];
    }

    return $rows;
  }

}
