<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\Hook\StudentDataHooks;
use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba que borrar una cuenta se lleva de verdad lo que guardaba.
 *
 * Entity API no borra en cascada nada de esto: `uid` es una referencia como
 * otra cualquiera. Hasta el 26-08-2026 solo se limpiaba la memoria, y las
 * conversaciones y los diagnósticos sobrevivían a su dueño.
 *
 * Fallaba por dos sitios, y el segundo es el que menos se ve:
 *
 * Privacidad: lo que quedaba no eran metadatos, era el análisis del negocio de
 * una persona y lo que escribió. Una petición de borrado no se cumplía.
 *
 * Aislamiento con retraso: Drupal REUTILIZA los identificadores de usuario. Un
 * registro cuyo dueño ya no existe se lo encuentra suyo la siguiente cuenta a
 * la que le toque ese número, porque el control de acceso compara uid contra
 * uid. Esa es la prueba que cierra el agujero de verdad.
 */
#[CoversClass(StudentDataHooks::class)]
final class UserDeletionTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installEntitySchema('sld_student_memory');
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    // Borrar una cuenta limpia sus datos de usuario y su vínculo con
    // WordPress; sin estas tablas fallaría por algo ajeno a lo que se prueba.
    $this->installSchema('user', ['users_data']);
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['sales_leadership_diagnostic']);

    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();
  }

  /**
   * Borrar la cuenta se lleva conversaciones, diagnósticos y memoria.
   */
  public function testBorrarLaCuentaSeLlevaTodoLoSuyo(): void {
    $alumno = $this->crearAlumnoConDatos();
    $uid = (int) $alumno->id();

    $this->assertSame(1, $this->contar('sld_diagnostic_session', $uid));
    $this->assertSame(1, $this->contar('sld_diagnostic_result', $uid));
    $this->assertSame(1, $this->contar('sld_student_memory', $uid));

    $alumno->delete();

    $this->assertSame(0, $this->contar('sld_diagnostic_session', $uid));
    $this->assertSame(0, $this->contar('sld_diagnostic_result', $uid));
    $this->assertSame(0, $this->contar('sld_student_memory', $uid));
  }

  /**
   * Los mensajes de la conversación también se van.
   *
   * Viven en una tabla propia, fuera de Entity API. Se limpian al borrar su
   * sesión, así que esta prueba comprueba que la cascada llega hasta el
   * final: es donde está el texto que escribió la persona.
   */
  public function testLosMensajesDeLaConversacionTambienSeVan(): void {
    $alumno = $this->crearAlumnoConDatos();
    $sesion = $this->sesionDe((int) $alumno->id());

    $this->assertSame(1, $this->contarMensajes($sesion));

    $alumno->delete();

    $this->assertSame(0, $this->contarMensajes($sesion));
  }

  /**
   * Una cuenta nueva con el mismo uid NO hereda nada.
   *
   * Es la prueba que cierra el agujero de aislamiento. Drupal reutiliza los
   * identificadores, y el control de acceso concede por comparación de uid: un
   * registro huérfano se convierte en propiedad de quien herede ese número.
   */
  public function testUnaCuentaNuevaConElMismoUidNoHeredaNada(): void {
    $alumno = $this->crearAlumnoConDatos();
    $uid = (int) $alumno->id();

    $alumno->delete();

    // Se recrea una cuenta ocupando exactamente el mismo identificador.
    $nuevo = User::create(['uid' => $uid, 'name' => 'alguien_distinto', 'status' => 1]);
    $nuevo->save();

    $this->assertSame($uid, (int) $nuevo->id(), 'La prueba necesita reutilizar el uid.');
    $this->assertSame(0, $this->contar('sld_diagnostic_session', $uid));
    $this->assertSame(0, $this->contar('sld_diagnostic_result', $uid));
    $this->assertSame(0, $this->contar('sld_student_memory', $uid));
  }

  /**
   * Borrar una cuenta no toca los datos de las demás.
   *
   * Lo contrario de lo anterior, y conviene fijarlo: una cascada demasiado
   * amplia sería peor que no tenerla.
   */
  public function testBorrarUnaCuentaNoTocaLasDemas(): void {
    $uno = $this->crearAlumnoConDatos('alumno_uno');
    $dos = $this->crearAlumnoConDatos('alumno_dos');
    $uidDos = (int) $dos->id();

    $uno->delete();

    $this->assertSame(1, $this->contar('sld_diagnostic_session', $uidDos));
    $this->assertSame(1, $this->contar('sld_diagnostic_result', $uidDos));
    $this->assertSame(1, $this->contar('sld_student_memory', $uidDos));
  }

  /**
   * Una cuenta sin nada del módulo se borra sin ruido.
   *
   * Es el caso de cualquier usuario del sitio que no sea alumno.
   */
  public function testUnaCuentaSinDatosSeBorraSinRuido(): void {
    $ajeno = User::create(['name' => 'no_es_alumno', 'status' => 1]);
    $ajeno->save();
    $uid = (int) $ajeno->id();

    $ajeno->delete();

    $this->assertNull(User::load($uid));
  }

  /**
   * Crea un alumno con una conversación, un diagnóstico y memoria.
   */
  private function crearAlumnoConDatos(string $nombre = 'alumno'): User {
    $alumno = User::create(['name' => $nombre, 'status' => 1]);
    $alumno->save();
    $uid = (int) $alumno->id();

    $sesion = DiagnosticSession::create([
      'uid' => $uid,
      'wp_user_id' => '777',
      'course_id' => '35884',
      'agent' => 'sales_leadership_diagnostic',
      'diagnostic_version' => '1.1',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
    ]);
    $sesion->setStatus(DiagnosticStatus::Completed);
    $sesion->save();

    $this->container->get(DiagnosticMessageRepository::class)->append(
      (int) $sesion->id(),
      MessageRole::User,
      'Facturamos 40 millones y el margen de la cuenta grande es del 12%.',
    );

    $resultado = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->create([
        'uid' => $uid,
        'session_id' => $sesion->id(),
        'agent' => 'sales_leadership_diagnostic',
        'diagnostic_version' => '1.1',
        'summary' => 'Análisis del negocio del alumno.',
        'score' => 30,
      ]);
    $resultado->save();

    $this->container->get(StudentMemoryStore::class)->remember(
      $uid,
      MemoryTopic::Empresa,
      'Distribuidora de material eléctrico.',
      'sales_leadership_diagnostic',
    );

    return $alumno;
  }

  /**
   * Identificador de la sesión del alumno.
   */
  private function sesionDe(int $uid): int {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->execute();

    return (int) reset($ids);
  }

  /**
   * Cuenta entidades del tipo indicado que pertenezcan a la cuenta.
   */
  private function contar(string $tipo, int $uid): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage($tipo)
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uid)
      ->count()
      ->execute();
  }

  /**
   * Cuenta los mensajes de una sesión.
   */
  private function contarMensajes(int $sesion): int {
    return (int) $this->container->get('database')
      ->select('sld_diagnostic_message', 'm')
      ->condition('session_id', $sesion)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
