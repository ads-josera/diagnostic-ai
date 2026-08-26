<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticAgentInterface;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticPromptManager;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\PromptDraft;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\SandboxSessionManager;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba que el estudio ensaya y publica sobre el agente correcto.
 *
 * Hasta el 26-08-2026 el estudio trabajaba sobre un objeto de configuración
 * único que dejó de gobernar nada al pasar a varios agentes: el gestor
 * ensayaba un prompt que no usaba ningún alumno y al publicar escribía donde
 * nadie leía. Estas pruebas fijan lo contrario.
 *
 * Lo que más importa es que el ensayo sea IDÉNTICO a lo que recibe el alumno.
 * Un banco de pruebas que prueba otra cosa es peor que no tenerlo: da
 * confianza sin fundamento.
 */
#[CoversClass(SandboxSessionManager::class)]
#[CoversClass(PromptDraft::class)]
final class PromptStudioTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'options',
    'externalauth',
    'sales_leadership_diagnostic',
  ];

  /**
   * Gestor que ensaya.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $gestor;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installConfig(['sales_leadership_diagnostic']);

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->gestor = User::create(['name' => 'gestor', 'status' => 1]);
    $this->gestor->save();
  }

  /**
   * El ensayo usa EXACTAMENTE el prompt que recibiría el alumno.
   *
   * Es la razón de ser del estudio. Si compusiera de otra manera —como hacía
   * antes, desde la configuración antigua y sin los documentos— probaría algo
   * que no existe en ninguna parte.
   */
  public function testElEnsayoUsaElMismoPromptQueElAlumno(): void {
    $agente = $this->crearAgente('uno');

    $sesion = $this->manager()->getOrCreate($this->gestor, $agente);

    $this->assertSame(
      $this->prompts()->composeFor($agente),
      $sesion->getPromptSnapshot(),
    );
    $this->assertSame('uno', $sesion->getAgentId());
    $this->assertSame($agente->getVersion(), $sesion->getDiagnosticVersion());
  }

  /**
   * Cada agente tiene su propia conversación de prueba.
   *
   * Sin esto, ensayar el segundo agente devolvía la conversación del primero y
   * el gestor daba por bueno un prompt que no había llegado a probar.
   */
  public function testCadaAgenteTieneSuPropiaConversacionDePrueba(): void {
    $uno = $this->crearAgente('uno');
    $dos = $this->crearAgente('dos');

    $sesionUno = $this->manager()->getOrCreate($this->gestor, $uno);
    $sesionDos = $this->manager()->getOrCreate($this->gestor, $dos);

    $this->assertNotSame($sesionUno->id(), $sesionDos->id());
    $this->assertSame('uno', $sesionUno->getAgentId());
    $this->assertSame('dos', $sesionDos->getAgentId());
  }

  /**
   * Reiniciar el ensayo de un agente no toca el del otro.
   */
  public function testReiniciarUnEnsayoNoTocaElDelOtroAgente(): void {
    $uno = $this->crearAgente('uno');
    $dos = $this->crearAgente('dos');

    $this->manager()->getOrCreate($this->gestor, $uno);
    $sesionDos = $this->manager()->getOrCreate($this->gestor, $dos);

    $this->manager()->reset($this->gestor, $uno);

    $this->assertSame(
      (int) $sesionDos->id(),
      (int) $this->manager()->getOrCreate($this->gestor, $dos)->id(),
      'La conversación del segundo agente debe seguir siendo la misma.',
    );
  }

  /**
   * Si el prompt cambió por otra vía, el ensayo caducado se reemplaza.
   *
   * Ocurre al publicar desde el formulario del agente o al desplegar una
   * versión nueva. Un ensayo congela su copia igual que la sesión de un
   * alumno, así que sin esto el gestor editaría un texto y conversaría con
   * otro sin que nada se lo indicara.
   */
  public function testUnEnsayoCaducadoSeReemplaza(): void {
    $agente = $this->crearAgente('uno');
    $vieja = $this->manager()->getOrCreate($this->gestor, $agente);

    $agente->set('system_prompt', 'Una metodología completamente distinta.')->save();

    $nueva = $this->manager()->getOrCreate($this->gestor, $agente);

    $this->assertNotSame((int) $vieja->id(), (int) $nueva->id());
    $this->assertStringContainsString(
      'completamente distinta',
      $nueva->getPromptSnapshot(),
    );
  }

  /**
   * Un ensayo vigente SÍ se reutiliza.
   *
   * La otra mitad de la regla: si se recreara en cada visita, el gestor
   * perdería la conversación cada vez que recargara la página.
   */
  public function testUnEnsayoVigenteSeReutiliza(): void {
    $agente = $this->crearAgente('uno');

    $primera = $this->manager()->getOrCreate($this->gestor, $agente);
    $segunda = $this->manager()->getOrCreate($this->gestor, $agente);

    $this->assertSame((int) $primera->id(), (int) $segunda->id());
  }

  /**
   * El borrador de un agente no se mezcla con el de otro.
   *
   * Con un borrador compartido, ensayar un cambio en un agente pisaba el
   * trabajo a medias en el otro, y publicar escribía en el equivocado.
   */
  public function testElBorradorDeUnAgenteNoPisaElDelOtro(): void {
    $draft = $this->container->get(PromptDraft::class);

    $draft->save('uno', ['version' => '2.0', 'system_prompt' => 'Prompt del primero.']);

    $this->assertTrue($draft->exists('uno'));
    $this->assertFalse($draft->exists('dos'));
    $this->assertSame('Prompt del primero.', $draft->get('uno')['system_prompt']);
    // Sin borrador se devuelve un array vacío, no uno con campos en blanco:
    // así quien lo consume distingue «no hay borrador» de «hay uno vacío».
    $this->assertSame([], $draft->get('dos'));

    $draft->discard('uno');

    $this->assertFalse($draft->exists('uno'));
  }

  /**
   * Con borrador, el ensayo usa el borrador y no lo publicado.
   *
   * Es lo que permite probar un cambio sin que lo sufra ningún alumno.
   */
  public function testConBorradorSeEnsayaElBorrador(): void {
    $agente = $this->crearAgente('uno');

    $this->container->get(PromptDraft::class)->save('uno', [
      'version' => '9.9',
      'system_prompt' => 'Texto que todavía no ha visto ningún alumno.',
    ]);

    $sesion = $this->manager()->getOrCreate($this->gestor, $agente);

    $this->assertStringContainsString('todavía no ha visto', $sesion->getPromptSnapshot());
    $this->assertSame('9.9', $sesion->getDiagnosticVersion());
    $this->assertSame(
      'Prompt publicado del agente uno.',
      $agente->getSystemPrompt(),
      'Lo publicado no se toca al ensayar.',
    );
  }

  /**
   * Crea un agente utilizable.
   */
  private function crearAgente(string $id): DiagnosticAgentInterface {
    $agente = $this->container->get('entity_type.manager')
      ->getStorage('sld_agent')
      ->create([
        'id' => $id,
        'label' => 'Agente ' . $id,
        'status' => TRUE,
        'version' => '1.0-' . $id,
        'course_id' => '35884',
        'system_prompt' => 'Prompt publicado del agente ' . $id . '.',
      ]);
    $agente->save();

    return $agente;
  }

  /**
   * El gestor de ensayos.
   */
  private function manager(): SandboxSessionManager {
    return $this->container->get(SandboxSessionManager::class);
  }

  /**
   * El compositor de prompts.
   */
  private function prompts(): DiagnosticPromptManager {
    return $this->container->get(DiagnosticPromptManager::class);
  }

}
