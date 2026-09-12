<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResultInterface;
use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Presenta el resultado de un diagnóstico completado (§35).
 *
 * Igual que en el chat, la comprobación de propiedad no ocurre aquí: la ruta
 * declara el resultado como parámetro de entidad, de modo que el enrutador
 * aplica su handler de acceso antes de invocar este método. Un identificador
 * ajeno devuelve 403 sin ejecutar código del módulo.
 *
 * El contenido procede del motor de IA, así que se trata como no confiable:
 * el resumen pasa por el mismo saneador que los mensajes del chat, y el resto
 * lo escapa Twig.
 */
final class ResultsController extends ControllerBase {

  /**
   * Claves de las secciones del resultado, en el orden en que se muestran.
   *
   * La estructura definitiva depende de la metodología del cliente (§32). Las
   * secciones que no vengan en el resultado simplemente no se pintan, de modo
   * que una metodología con menos apartados no produce huecos vacíos.
   *
   * Las etiquetas NO viven aquí sino en sectionLabel(), como literales: el
   * extractor de traducciones de Drupal analiza el código fuente buscando
   * llamadas a t() con una cadena literal, de modo que una etiqueta pasada
   * como variable nunca llegaría al catálogo y no podría traducirse.
   *
   * @var string[]
   */
  private const SECTION_KEYS = [
    'strengths',
    'opportunities',
    'risks',
    'missing_evidence',
    'recommendations',
    'priority_actions',
  ];

  public function __construct(
    private readonly MarkdownRenderer $markdown,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(MarkdownRenderer::class),
      $container->get('date.formatter'),
    );
  }

  /**
   * Título de la página, que puede fijar cada agente.
   *
   * No todos los agentes entregan un diagnóstico. El de prospección cierra con
   * un Weekly GOLD Pack —cuentas, buyers, routing y outreach—, y encabezar esa
   * página con «Resultado de tu diagnóstico» describe mal lo que la persona
   * tiene delante. Cuando el agente no dice nada se usa el de siempre, así que
   * los agentes que ya existían no cambian.
   *
   * El agente se carga del almacén y NO del registro de agentes utilizables:
   * un resultado antiguo puede pertenecer a uno deshabilitado, y ahí lo que se
   * quiere es el título con el que se generó, no un 404.
   */
  public function title(DiagnosticResultInterface $sld_diagnostic_result): string {
    $propio = $this->agenteDe($sld_diagnostic_result)?->getResultTitle() ?? '';

    if ($propio !== '') {
      // El título propio también le habla al dueño —«Tu Weekly GOLD Pack»—, y
      // el gestor que abre el Pack de un alumno lo leía como suyo. Es el mismo
      // fallo que se corrigió el 04-09-2026 para el título por defecto, que
      // seguía abierto por este camino. Lo vio la verificación del 12-09-2026.
      return $this->esSuyo($sld_diagnostic_result) ? $propio : $this->sinPosesivo($propio);
    }

    // A quien NO es su dueño no se le puede decir «tu diagnóstico»: el gestor
    // abre el de un alumno desde su listado, y tutearle sobre algo ajeno hace
    // dudar de qué está viendo. Lo vio el usuario el 04-09-2026.
    return $this->esSuyo($sld_diagnostic_result)
      ? (string) $this->t('Resultado de tu diagnóstico')
      : (string) $this->t('Resultado del diagnóstico');
  }

  /**
   * Un título sin el «tu» o «tus» del principio.
   *
   * El título lo escribe quien configura el agente, así que no se puede saber
   * cómo vendrá. Solo se quita el posesivo inicial, que es lo que convierte en
   * «tuyo» algo ajeno; cualquier otro título se enseña tal cual. Si no quedara
   * nada, se deja el original: mejor un «tu» de más que una página sin título.
   */
  private function sinPosesivo(string $titulo): string {
    $resto = trim((string) preg_replace('/^tus?\s+/iu', '', $titulo));

    if ($resto === '' || $resto === $titulo) {
      return $titulo;
    }

    return mb_strtoupper(mb_substr($resto, 0, 1)) . mb_substr($resto, 1);
  }

  /**
   * Si quien mira es el dueño del resultado.
   */
  private function esSuyo(DiagnosticResultInterface $result): bool {
    return (string) $this->currentUser()->id() === (string) $result->getOwnerId();
  }

  /**
   * A dónde vuelve quien está mirando, y con qué texto.
   *
   * @return array<string, string>
   *   `url` y `label`.
   */
  private function buildBack(DiagnosticResultInterface $result): array {
    if ($this->esSuyo($result)) {
      return [
        'url' => Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
        'label' => (string) $this->t('← Volver a mi panel'),
      ];
    }

    return [
      'url' => Url::fromRoute('sales_leadership_diagnostic.admin_results')->toString(),
      'label' => (string) $this->t('← Volver a los resultados'),
    ];
  }

  /**
   * Agente con el que se generó un resultado, si todavía existe.
   */
  private function agenteDe(DiagnosticResultInterface $result): ?DiagnosticAgentInterface {
    $id = $result->getAgentId();

    if ($id === '') {
      return NULL;
    }

    // Se usa el accesor de ControllerBase en lugar de inyectarlo: la clase
    // base ya declara esa propiedad, y volver a declararla como readonly es un
    // error fatal de PHP que tumba la reconstrucción de cache entera.
    $agente = $this->entityTypeManager()->getStorage('sld_agent')->load($id);

    return $agente instanceof DiagnosticAgentInterface ? $agente : NULL;
  }

  /**
   * Renderiza el resultado.
   */
  public function view(DiagnosticResultInterface $sld_diagnostic_result): array {
    $result = $sld_diagnostic_result;
    $payload = $result->getPayload();
    $tipo = $this->tipoDe($result);
    $dimensiones = $result->getDimensions();

    $this->logForeignAccess($result);

    return [
      '#theme' => 'sld_result',
      // Se pasa el mismo título que devuelve el callback de la ruta. La página
      // usa el marco interno del módulo, que no pinta la región donde el tema
      // coloca su bloque de título, así que lo imprime la plantilla.
      '#title' => $this->title($result),
      '#summary' => Markup::create($this->markdown->render($result->getSummary())),
      '#summary_label' => $this->summaryLabel($tipo),
      '#score' => $result->getScore(),
      // Banda de madurez y confianza global. Hasta el 26-08-2026 no tenían
      // sitio y se colaban dentro del resumen en prosa.
      '#maturity' => $result->getMaturity(),
      // En castellano, la global y la de cada dimensión. El agente la escribe
      // como su metodología —HIGH, MEDIUM, LOW— y la pantalla decía
      // «Confianza: MEDIUM» en mitad de un texto en español.
      '#confidence' => $this->confianza($result->getConfidence()),
      '#dimensions' => array_map(
        fn (array $d): array => array_merge($d, ['confidence' => $this->confianza((string) ($d['confidence'] ?? ''))]),
        array_filter($dimensiones, 'is_array'),
      ),
      // Un diagnóstico parcial no tiene Score global, y su confianza se
      // pintaba dentro del bloque del Score: desaparecía con él. Su metodología
      // exige además decir que no representa la madurez global.
      '#partial' => $tipo === 'diagnostico' && $result->getScore() === NULL && $dimensiones !== [],
      // El Weekly GOLD Pack, cuenta por cuenta. Hasta el 10-09-2026 esto se
      // leía solo dentro de la conversación, como prosa: no se podía hojear,
      // ni saber de un vistazo cuáles se pueden enviar hoy, ni copiar un
      // mensaje sin seleccionarlo a mano.
      '#accounts' => $result->getAccounts(),
      '#pool' => $this->buildPool($result),
      // Registrar qué pasó con estas cuentas. Solo para su dueño, y solo si el
      // Pack tiene cuentas: el gestor que da soporte no registra por nadie.
      '#accounts_url' => $this->esSuyo($result) && $result->getAccounts() !== []
        ? Url::fromRoute('sales_leadership_diagnostic.accounts')->toString()
        : NULL,
      '#sections' => $this->buildSections($payload, $tipo),
      '#version' => $result->getDiagnosticVersion(),
      // A dónde vuelve quien mira. El alumno, a su panel; el gestor, al
      // listado del que vino. Sin esto se quedaba encerrado: desde aquí no
      // había ninguna salida hacia su propia sección.
      '#back' => $this->buildBack($result),
      // Si quien mira es el dueño. El pie decía «a partir de tus respuestas»
      // también al gestor que abre el resultado de un alumno.
      '#own' => $this->esSuyo($result),
      '#created' => $this->dateFormatter->format((int) $result->get('created')->value, 'long'),
      '#attached' => [
        'library' => ['sales_leadership_diagnostic/result'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['sld_diagnostic_result:' . $result->id()],
      ],
    ];
  }

  /**
   * El pool cribado, y si el agente declaró más de lo que sostiene.
   *
   * La discrepancia se ENSEÑA en vez de callarse. Es la única señal de que un
   * Pack venía inflado, y quien lee el informe es quien debe saberlo: si se
   * queda solo en el registro del sistema, no la ve nadie.
   *
   * @return array<string, mixed>|null
   *   Las cifras, o NULL si el agente no declaró pool.
   */
  private function buildPool(DiagnosticResultInterface $result): ?array {
    $auditables = $result->getPoolDeclared();

    if ($auditables === 0) {
      return NULL;
    }

    $declaradas = $result->getPoolClaimed();

    return [
      'audited' => $auditables,
      'claimed' => $declaradas > $auditables ? $declaradas : 0,
    ];
  }

  /**
   * Deja constancia de que alguien ha leído el diagnóstico de otra persona.
   *
   * Un resultado contiene el análisis del negocio del alumno. Que soporte pueda
   * consultarlo es necesario para atenderle; que nadie sepa nunca quién lo ha
   * consultado, no. El registro convierte ese acceso en un hecho auditable sin
   * estorbar el trabajo de nadie.
   *
   * Se anota la lectura, no el contenido: el mensaje lleva identificadores, y
   * jamás el resumen ni la puntuación (§43).
   */
  private function logForeignAccess(DiagnosticResultInterface $result): void {
    $viewer = $this->currentUser();

    if ((string) $viewer->id() === (string) $result->getOwnerId()) {
      return;
    }

    $this->getLogger('sales_leadership_diagnostic')->info(
      'La cuenta @viewer ha consultado el resultado @result, propiedad de la cuenta @owner.',
      [
        '@viewer' => $viewer->id(),
        '@result' => $result->id(),
        '@owner' => $result->getOwnerId(),
      ],
    );
  }

  /**
   * Prepara las secciones de lista del resultado.
   *
   * Los elementos se pasan como texto y los escapa Twig. No se interpretan
   * como Markdown: una lista dentro de una lista no aporta nada y sí ampliaría
   * la superficie de marcado generado por el modelo.
   *
   * @param array<string, mixed> $payload
   *   Estructura completa del resultado, tal como la guardó el motor.
   * @param string $tipo
   *   Clase de informe, según tipoDe().
   */
  private function buildSections(array $payload, string $tipo): array {
    $sections = [];

    foreach (self::SECTION_KEYS as $key) {
      $items = $payload[$key] ?? NULL;

      if (!is_array($items) || $items === []) {
        continue;
      }

      $sections[] = [
        'key' => $key,
        'label' => $this->sectionLabel($key, $tipo),
        'items' => array_values(array_filter(
          array_map(static fn ($item): string => is_scalar($item) ? trim((string) $item) : '', $items),
          static fn (string $item): bool => $item !== '',
        )),
      ];
    }

    return $sections;
  }

  /**
   * Qué clase de informe es: por su forma, no por el agente.
   *
   * Las etiquetas genéricas describían mal los dos informes que existen. La
   * lista `opportunities` son las fugas comerciales en el diagnóstico y las
   * cuentas GOLD en el Pack, y ninguna de las dos es una «oportunidad de
   * mejora». Lo vio José Raúl el 12-09-2026.
   *
   * Se decide por la forma porque el identificador del agente es configurable
   * y la forma no: la fija su contrato de salida. Un informe que no encaje en
   * ninguno de los dos conserva las etiquetas genéricas de siempre.
   *
   * @return string
   *   'pack', 'diagnostico' o 'generico'.
   */
  private function tipoDe(DiagnosticResultInterface $result): string {
    if ($result->getAccounts() !== [] || $result->getPoolDeclared() > 0) {
      return 'pack';
    }

    if ($result->getDimensions() !== [] || $result->getScore() !== NULL) {
      return 'diagnostico';
    }

    return 'generico';
  }

  /**
   * Título del resumen, con el nombre que le da cada informe.
   */
  private function summaryLabel(string $tipo): TranslatableMarkup {
    return match ($tipo) {
      // El EXECUTIVE READING de su informe final.
      'diagnostico' => $this->t('Lectura ejecutiva'),
      // Su contrato lo define como la misión y su cobertura de investigación.
      'pack' => $this->t('La misión y su cobertura'),
      default => $this->t('Resumen'),
    };
  }

  /**
   * La confianza en castellano.
   *
   * Lo que no sea uno de los tres niveles se deja tal cual: mejor un valor
   * raro a la vista que uno inventado.
   *
   * Llevan CONTEXTO de traducción, y no es adorno. Nuestras cadenas ya están
   * en español, y Drupal busca su traducción al español como si fueran
   * inglés: «Media» existe en el núcleo —el módulo de archivos multimedia— con
   * traducción «Multimedia», y la pantalla decía «Confianza: Multimedia». Lo
   * vio la verificación en el navegador el 12-09-2026; la prueba no, porque
   * corre sin traducciones. El contexto separa nuestra cadena de la suya.
   */
  private function confianza(string $valor): string {
    $contexto = ['context' => 'Nivel de confianza'];

    return match (mb_strtoupper(trim($valor))) {
      'HIGH', 'ALTA' => (string) $this->t('Alta', [], $contexto),
      'MEDIUM', 'MEDIA' => (string) $this->t('Media', [], $contexto),
      'LOW', 'BAJA' => (string) $this->t('Baja', [], $contexto),
      default => $valor,
    };
  }

  /**
   * Etiqueta traducible de una sección, según la clase de informe.
   *
   * Cada rama contiene un literal para que el extractor de traducciones pueda
   * encontrarlas al analizar el código.
   *
   * El diagnóstico usa el vocabulario de su FINAL REPORT; el Pack, el de su
   * contrato de salida. «Prioridades» y no «Tres prioridades»: si el agente
   * da menos, la cifra del título mentiría.
   *
   * @param string $key
   *   Clave de la sección.
   * @param string $tipo
   *   Clase de informe, según tipoDe().
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   Etiqueta lista para mostrar.
   */
  private function sectionLabel(string $key, string $tipo): TranslatableMarkup {
    return match ($tipo . ':' . $key) {
      'diagnostico:strengths' => $this->t('Fortalezas a preservar'),
      'diagnostico:opportunities' => $this->t('Principales fugas comerciales'),
      'diagnostico:risks' => $this->t('Riesgos'),
      'diagnostico:missing_evidence' => $this->t('Evidencia crítica que falta'),
      'diagnostico:recommendations' => $this->t('Prioridades'),
      'diagnostico:priority_actions' => $this->t('Primeros 30 días'),
      'pack:strengths' => $this->t('Fortalezas del territorio'),
      'pack:opportunities' => $this->t('Cuentas GOLD liberadas'),
      'pack:risks' => $this->t('Qué no afirmar y cuentas en espera'),
      'pack:missing_evidence' => $this->t('Comprobaciones pendientes'),
      'pack:recommendations' => $this->t('Routing recomendado'),
      'pack:priority_actions' => $this->t('Siguientes pasos por cuenta'),
      'generico:strengths' => $this->t('Fortalezas'),
      'generico:opportunities' => $this->t('Oportunidades de mejora'),
      'generico:risks' => $this->t('Riesgos'),
      'generico:missing_evidence' => $this->t('Evidencia que falta'),
      'generico:recommendations' => $this->t('Recomendaciones'),
      'generico:priority_actions' => $this->t('Acciones prioritarias'),
      default => $this->t('Otros hallazgos'),
    };
  }

}
