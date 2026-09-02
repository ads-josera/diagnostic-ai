<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\sales_leadership_diagnostic\Entity\Handler\DiagnosticAgentListBuilder;
use Drupal\sales_leadership_diagnostic\Form\DiagnosticAgentForm;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;

/**
 * Un agente de diagnóstico: su metodología, su curso y su conversación.
 *
 * Es una entidad de CONFIGURACIÓN y no de contenido.
 *
 * Un agente es una definición del producto, no un dato que produzcan los
 * alumnos: se exporta, se despliega y se versiona en Git como un tipo de
 * contenido o un rol. Como entidad de contenido habría quedado fuera de la
 * exportación de configuración y no habría forma de llevar un agente de
 * desarrollo a producción sin copiar la base de datos.
 *
 * CONSECUENCIA QUE HAY QUE CONOCER
 *
 * El gestor puede crear agentes desde la interfaz —decisión del usuario del
 * 25-08-2026, porque el cliente quiere autonomía— y eso convive mal con un
 * `drush config:import` a ciegas: una importación que no incluya un agente
 * creado en producción lo borra. Mientras el gestor pueda crearlos, el
 * despliegue debe exportar antes
 * de importar, o excluir `sales_leadership_diagnostic.agent.*` del import.
 *
 * QUÉ NO GUARDA
 *
 * El texto extraído de los documentos de conocimiento no vive aquí, sino en el
 * estado: son cientos de miles de caracteres de material propietario que no
 * deben viajar a Git en cada exportación. Aquí solo van los identificadores de
 * archivo, que sí son estructura.
 */
#[ConfigEntityType(
  id: 'sld_agent',
  label: new TranslatableMarkup('Agente de diagnóstico'),
  label_collection: new TranslatableMarkup('Agentes de diagnóstico'),
  label_singular: new TranslatableMarkup('agente de diagnóstico'),
  label_plural: new TranslatableMarkup('agentes de diagnóstico'),
  config_prefix: 'agent',
  admin_permission: SalesLeadershipDiagnostic::PERMISSION_EDIT_PROMPT,
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
    'uuid' => 'uuid',
  ],
  handlers: [
    'list_builder' => DiagnosticAgentListBuilder::class,
    // Sin proveedor de rutas, Drupal NO genera las de listar, añadir, editar
    // ni borrar: los enlaces declarados abajo se quedan apuntando a rutas que
    // no existen y la pestaña del gestor da un error de ruta desconocida.
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
    'form' => [
      'add' => DiagnosticAgentForm::class,
      'edit' => DiagnosticAgentForm::class,
      'delete' => EntityDeleteForm::class,
    ],
  ],
  links: [
    'collection' => '/admin/config/salesbumm/diagnostic/agentes',
    'add-form' => '/admin/config/salesbumm/diagnostic/agentes/anadir',
    'edit-form' => '/admin/config/salesbumm/diagnostic/agentes/{sld_agent}',
    'delete-form' => '/admin/config/salesbumm/diagnostic/agentes/{sld_agent}/borrar',
  ],
  config_export: [
    'id',
    'label',
    'version',
    'course_id',
    'description',
    'system_prompt',
    'instructions',
    'output_contract',
    'knowledge_fids',
    'welcome_icon_fid',
    'welcome_intro',
    'welcome_suggestions',
    'result_title',
    'weight',
  ],
)]
final class DiagnosticAgent extends ConfigEntityBase implements DiagnosticAgentInterface {

  /**
   * Identificador legible por máquina.
   */
  protected string $id = '';

  /**
   * Nombre visible, el que lee el alumno.
   */
  protected string $label = '';

  /**
   * Versión de la metodología, congelada en cada sesión (§57).
   */
  protected string $version = '';

  /**
   * Curso de WordPress que concede este agente.
   *
   * Es la única pieza que conecta el agente con la compra del alumno. Vive
   * aquí y no en WordPress a propósito: el plugin conoce cursos, que es su
   * dominio, y añadir un agente no debe obligar a tocar producción allí.
   */
  protected string $course_id = '';

  /**
   * Descripción para quien administra. No la ve el alumno.
   */
  protected string $description = '';

  /**
   * Prompt principal, propiedad del cliente.
   */
  protected string $system_prompt = '';

  /**
   * Instrucciones de conducción, propiedad del cliente.
   */
  protected string $instructions = '';

  /**
   * Contrato de salida. Lo aporta el módulo, no el cliente.
   */
  protected string $output_contract = '';

  /**
   * Documentos de conocimiento, por orden.
   *
   * @var int[]
   */
  protected array $knowledge_fids = [];

  /**
   * Icono de la pantalla de bienvenida.
   */
  protected int $welcome_icon_fid = 0;

  /**
   * Encabezado de la página de resultado.
   *
   * Vacío significa «el de siempre». No todos los agentes entregan un
   * diagnóstico: el de prospección cierra con un Weekly GOLD Pack, y llamarlo
   * «Resultado de tu diagnóstico» describe mal lo que la persona está leyendo.
   */
  protected string $result_title = '';

  /**
   * Texto introductorio del chat.
   */
  protected string $welcome_intro = '';

  /**
   * Sugerencias para empezar la conversación.
   *
   * @var string[]
   */
  protected array $welcome_suggestions = [];

  /**
   * Orden en los listados y en el panel del alumno.
   */
  protected int $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function getVersion(): string {
    return trim($this->version);
  }

  /**
   * {@inheritdoc}
   */
  public function getCourseId(): string {
    return trim($this->course_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return trim($this->description);
  }

  /**
   * {@inheritdoc}
   */
  public function getSystemPrompt(): string {
    return trim($this->system_prompt);
  }

  /**
   * {@inheritdoc}
   */
  public function getInstructions(): string {
    return trim($this->instructions);
  }

  /**
   * {@inheritdoc}
   */
  public function getOutputContract(): string {
    return trim($this->output_contract);
  }

  /**
   * {@inheritdoc}
   */
  public function getKnowledgeFids(): array {
    return array_values(array_filter(array_map('intval', $this->knowledge_fids)));
  }

  /**
   * {@inheritdoc}
   */
  public function setKnowledgeFids(array $fids): static {
    $this->knowledge_fids = array_values(array_unique(array_map('intval', $fids)));

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWelcomeIconFid(): int {
    return $this->welcome_icon_fid;
  }

  /**
   * {@inheritdoc}
   */
  public function getWelcomeIntro(): string {
    return trim($this->welcome_intro);
  }

  /**
   * {@inheritdoc}
   */
  public function getResultTitle(): string {
    return trim($this->result_title);
  }

  /**
   * {@inheritdoc}
   */
  public function getWelcomeSuggestions(): array {
    return array_values(array_filter(array_map(
      static fn ($s): string => trim((string) $s),
      $this->welcome_suggestions,
    )));
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   *
   * Un agente sin curso no puede concederse a nadie, y uno sin prompt no puede
   * conversar. Se comprueban juntos porque un agente a medias publicado es
   * peor que uno deshabilitado: aparece y falla.
   */
  public function isUsable(): bool {
    return $this->status()
      && $this->getCourseId() !== ''
      && $this->getSystemPrompt() !== '';
  }

}
