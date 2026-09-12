<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\BuyerTruth;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticResult;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\ExecutionState;
use Drupal\sales_leadership_diagnostic\Service\Account\AccountRegistry;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Las cuentas de cada alumno, semana tras semana.
 *
 * Existe porque el acceso dura un año: del orden de quinientas cuentas por
 * alumno, y hasta el 11-09-2026 el sistema no recordaba ninguna de una semana
 * a otra. Las pruebas fijan las reglas que salen del Documento 8 del cliente,
 * sobre todo la del §4: la evidencia nueva se añade y nunca reescribe la tesis
 * original.
 */
#[CoversClass(AccountRegistry::class)]
final class AccountRegistryTest extends KernelTestBase {

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
   * Alumno de las pruebas.
   */
  private User $alumno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    // Borrar un usuario limpia sus datos de esta tabla, y sin ella la prueba
    // del borrado en cascada revienta antes de llegar a lo que comprueba.
    $this->installSchema('user', ['users_data']);
    // Y la del módulo de acceso externo: también limpia la cuenta al borrarla.
    $this->installSchema('externalauth', ['authmap']);
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    // Cada parte del módulo que reacciona al borrado de un usuario limpia su
    // propia tabla, y la prueba del borrado en cascada necesita tenerlas todas.
    $this->installEntitySchema('sld_student_memory');
    $this->installSchema('sales_leadership_diagnostic', [
      'sld_diagnostic_message',
      'sld_account',
      'sld_account_event',
    ]);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El uid 1 es superusuario y no debe usarse como sujeto de prueba.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $this->alumno = User::create(['name' => 'alumna', 'status' => 1]);
    $this->alumno->save();
  }

  /**
   * La misma empresa escrita distinto es una sola cuenta.
   *
   * «Traxión» y «Traxion», o con dos espacios, no deben dar dos historias.
   */
  public function testLaMismaEmpresaEscritaDistintoEsUnaSolaCuenta(): void {
    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, [
      ['name' => 'Traxión', 'disposition' => 'SILVER'],
      ['name' => 'PepsiCo  Alimentos México', 'disposition' => 'SILVER'],
    ]);
    $this->registro()->ingestPack($this->uid(), 'prospeccion', 11, [
      ['name' => 'Traxion', 'disposition' => 'GOLD'],
      ['name' => 'pepsico alimentos mexico', 'disposition' => 'WATCH'],
    ]);

    $cuentas = $this->registro()->forUser($this->uid());

    $this->assertCount(2, $cuentas);
    $this->assertSame([2, 2], array_column($cuentas, 'times_in_pack'), 'Cada una salió en dos Packs.');
  }

  /**
   * Pasar el mismo Pack dos veces no duplica nada.
   *
   * Importa para la carga de los Packs que ya existían, que se puede repetir.
   */
  public function testElMismoPackDosVecesNoDuplica(): void {
    $pack = [['name' => 'Olympic Transport', 'disposition' => 'SILVER']];

    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, $pack);
    $segunda = $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, $pack);

    $this->assertSame(0, $segunda, 'La segunda vez no registra nada.');
    $this->assertSame(1, $this->registro()->forUser($this->uid())[0]['times_in_pack']);
  }

  /**
   * Registrar un resultado AÑADE; no reescribe lo que dijo el Pack.
   *
   * Es la regla del §4 de su Documento 8: «NEW EVIDENCE APPENDS TO THE
   * SNAPSHOT. IT NEVER RETROACTIVELY ALTERS THE ORIGINAL THESIS». Y si el
   * alumno se equivoca y lo corrige, se ven el error y la corrección.
   */
  public function testUnResultadoSeAnadeSinReescribirElPack(): void {
    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, [
      ['name' => 'XBorder Solutions', 'disposition' => 'SILVER', 'outreach_status' => 'PREPARED'],
    ]);
    $id = $this->registro()->forUser($this->uid())[0]['id'];

    $this->registro()->recordOutcome($this->uid(), $id, ExecutionState::Contacted);
    $this->registro()->recordOutcome($this->uid(), $id, ExecutionState::Response, BuyerTruth::WrongBuyer, 'Nos pasó con el de mantenimiento.');

    $cuenta = $this->registro()->forUser($this->uid())[0];

    $this->assertSame(ExecutionState::Response, $cuenta['state'], 'Manda lo último.');
    $this->assertSame(BuyerTruth::WrongBuyer, $cuenta['truth']);
    $this->assertSame('SILVER', $cuenta['disposition'], 'Lo que dijo el Pack sigue ahí.');

    $historial = $this->registro()->history($this->uid(), $id);

    $this->assertSame(['pack', 'outcome', 'outcome'], array_column($historial, 'kind'), 'Nada se borró: todo se añadió.');
  }

  /**
   * Una cuenta sin nada registrado no tiene estado.
   *
   * Su §6 lo prohíbe: «Never Simulate Work». Salir en el Pack no significa que
   * nadie la tomara, así que el estado se queda vacío en vez de suponerse.
   */
  public function testUnaCuentaSinRegistrarNoTieneEstado(): void {
    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, [['name' => 'Dymax']]);

    $this->assertNull($this->registro()->forUser($this->uid())[0]['state']);
  }

  /**
   * Nadie escribe en las cuentas de otra persona.
   *
   * Se comprueba en el registro y no solo en la pantalla: es la última puerta
   * antes de escribir.
   */
  public function testNadieEscribeEnCuentasAjenas(): void {
    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, [['name' => 'Werner']]);
    $id = $this->registro()->forUser($this->uid())[0]['id'];

    $otra = User::create(['name' => 'otra', 'status' => 1]);
    $otra->save();

    $this->assertFalse($this->registro()->recordOutcome((int) $otra->id(), $id, ExecutionState::Won));
    $this->assertNull($this->registro()->forUser($this->uid())[0]['state'], 'La cuenta sigue sin tocar.');
  }

  /**
   * La nota es una frase, no un informe.
   *
   * Su §11 pide «one sentence». Se acorta y se limpia de saltos de línea.
   */
  public function testLaNotaEsUnaFrase(): void {
    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, [['name' => 'Vitro']]);
    $id = $this->registro()->forUser($this->uid())[0]['id'];

    $this->registro()->recordOutcome($this->uid(), $id, ExecutionState::NoAction, NULL, "Una frase\n\n" . str_repeat('x', 400));

    $nota = $this->registro()->forUser($this->uid())[0]['note'];

    $this->assertSame(AccountRegistry::NOTE_MAX, mb_strlen($nota));
    $this->assertStringNotContainsString("\n", $nota);
  }

  /**
   * Guardar un Pack registra sus cuentas solo.
   *
   * Nadie tiene que acordarse de llamar a nada: se engancha al guardado del
   * resultado.
   */
  public function testGuardarUnPackRegistraSusCuentas(): void {
    $this->guardarPack(ensayo: FALSE);

    $this->assertSame(['Grupo MexAmerik', 'Olympic Transport'], array_column($this->registro()->forUser($this->uid()), 'name'));
  }

  /**
   * Los ensayos del gestor no son cuentas de nadie.
   */
  public function testUnEnsayoNoRegistraCuentas(): void {
    $this->guardarPack(ensayo: TRUE);

    $this->assertSame([], $this->registro()->forUser($this->uid()));
  }

  /**
   * Borrar la cuenta del alumno se lleva sus cuentas y todo su historial.
   */
  public function testBorrarLaCuentaSeLlevaElRegistro(): void {
    $this->guardarPack(ensayo: FALSE);
    $id = $this->registro()->forUser($this->uid())[0]['id'];
    $this->registro()->recordOutcome($this->uid(), $id, ExecutionState::Meeting);

    $uid = $this->uid();
    $this->alumno->delete();

    $this->assertSame([], $this->registro()->forUser($uid));
    $this->assertSame([], $this->registro()->history($uid, $id), 'Tampoco queda historial suelto.');
  }

  /**
   * Guarda un resultado con dos cuentas, como lo haría una misión.
   */
  private function guardarPack(bool $ensayo): void {
    $sesion = DiagnosticSession::create([
      'uid' => $this->uid(),
      'wp_user_id' => '1',
      'course_id' => '35884',
      'agent' => 'prospeccion',
      'diagnostic_version' => '1.2',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
      'is_sandbox' => $ensayo,
    ]);
    $sesion->setStatus(DiagnosticStatus::Completed);
    $sesion->save();

    $resultado = DiagnosticResult::create([
      'uid' => $this->uid(),
      'session_id' => $sesion->id(),
      'agent' => 'prospeccion',
      'is_sandbox' => $ensayo,
      'diagnostic_version' => '1.2',
      'summary' => 'Misión.',
    ]);
    $resultado->setPayload([
      'pool_declared' => 2,
      'accounts' => [
        ['name' => 'Grupo MexAmerik', 'disposition' => 'SILVER', 'rank' => 1, 'outreach_status' => 'BLOCKED'],
        ['name' => 'Olympic Transport', 'disposition' => 'SILVER', 'rank' => 2, 'outreach_status' => 'PREPARED'],
      ],
    ]);
    $resultado->save();
  }

  /**
   * El registro.
   */
  private function registro(): AccountRegistry {
    return $this->container->get(AccountRegistry::class);
  }

  /**
   * Uid del alumno.
   */
  private function uid(): int {
    return (int) $this->alumno->id();
  }

  /**
   * La clasificación se guarda entera aunque el agente escriba de más.
   *
   * El 10-09-2026 escribió «SILVER — HOLD FOR OWNERSHIP CHECK», 33
   * caracteres, y con un tope de 32 se guardó cortada sin que nada avisara.
   */
  public function testLaClasificacionNoSeCorta(): void {
    $larga = 'SILVER — HOLD FOR OWNERSHIP CHECK';

    $this->registro()->ingestPack($this->uid(), 'prospeccion', 10, [
      ['name' => 'Bilden', 'disposition' => $larga, 'outreach_status' => 'OUTREACH BLOCKED'],
    ]);

    $cuenta = $this->registro()->forUser($this->uid())[0];
    $this->assertSame($larga, $cuenta['disposition']);
    $this->assertSame('OUTREACH BLOCKED', $cuenta['outreach_status']);
  }

}
