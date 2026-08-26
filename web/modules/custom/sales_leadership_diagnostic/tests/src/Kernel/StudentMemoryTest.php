<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\Entity\Handler\StudentMemoryAccessControlHandler;
use Drupal\sales_leadership_diagnostic\MemoryTopic;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba la memoria que el sistema guarda de cada alumno.
 *
 * Dos cosas se prueban aquí, y la segunda es la que importa de verdad.
 *
 * La primera es que la memoria se mantenga manejable: un hecho por tema, que
 * al recordar de nuevo se reemplace en vez de acumularse, y que el texto tenga
 * tope. Sin eso deja de caber en el prompt y el alumno deja de poder
 * revisarla.
 *
 * La segunda es que la memoria de un alumno no se le aparezca a otro. El
 * cliente lo señaló como crítico, y la memoria es el sitio donde más fácil
 * sería que ocurriera: a diferencia del resultado, que tiene su ruta y su
 * dueño evidentes, la memoria la lee un proceso automático que corre sin
 * usuario en curso. Se prueba por los dos caminos: el servicio nunca devuelve
 * lo ajeno, y el control de acceso lo prohíbe aunque alguien llegue por otro
 * lado.
 */
#[CoversClass(StudentMemoryStore::class)]
#[CoversClass(StudentMemoryAccessControlHandler::class)]
final class StudentMemoryTest extends KernelTestBase {

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
   * Alumna de la que se recuerdan cosas.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $ana;

  /**
   * Otro alumno, que no debe ver nada de la primera.
   *
   * @var \Drupal\user\Entity\User
   */
  private User $bruno;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_student_memory');
    // Borrar una cuenta limpia sus datos de usuario y su vínculo con
    // WordPress. Sin estas tablas la prueba del borrado fallaría por algo que
    // no tiene que ver con lo que quiere comprobar.
    $this->installSchema('user', ['users_data']);
    $this->installSchema('externalauth', ['authmap']);
    $this->installConfig(['sales_leadership_diagnostic']);

    // El uid 1 salta toda comprobación de permisos y falsearía las pruebas de
    // aislamiento, así que se gasta en una cuenta que no se usa.
    User::create(['name' => 'uid1_no_usar', 'status' => 1])->save();

    $rol = Role::create([
      'id' => SalesLeadershipDiagnostic::STUDENT_ROLE_ID,
      'label' => 'Alumno',
    ]);
    $rol->grantPermission(SalesLeadershipDiagnostic::PERMISSION_ACCESS);
    $rol->save();

    $this->ana = $this->crearAlumno('ana');
    $this->bruno = $this->crearAlumno('bruno');
  }

  /**
   * Recordar algo del mismo tema reemplaza lo anterior.
   *
   * Es la regla que mantiene la memoria acotada. Si en vez de reemplazar se
   * acumulara, un alumno que repite el diagnóstico cada trimestre acabaría con
   * cuatro versiones contradictorias de su propia empresa y ninguna forma de
   * saber cuál vale.
   */
  public function testRecordarElMismoTemaReemplazaLoAnterior(): void {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    $store->remember($uid, MemoryTopic::Empresa, 'Distribuidora de material eléctrico, 40 empleados.', 'agente_uno');
    $store->remember($uid, MemoryTopic::Empresa, 'Distribuidora de material eléctrico, 60 empleados tras la compra de un competidor.', 'agente_dos');

    $memoria = $store->forUser($uid);

    $this->assertCount(1, $memoria);
    $this->assertStringContainsString('60 empleados', $memoria['empresa']->getContent());
    $this->assertSame('agente_dos', $memoria['empresa']->getSourceAgentId(), 'Debe quedar constancia del agente que lo actualizó.');
  }

  /**
   * Temas distintos conviven, y salen siempre en el mismo orden.
   *
   * El orden es de presentación, no de fecha: el alumno tiene que encontrar su
   * ficha igual cada vez, aunque un tema se haya actualizado ayer y otro hace
   * medio año.
   */
  public function testLosTemasSalenSiempreEnElMismoOrden(): void {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    // Se escriben al revés del orden de presentación a propósito.
    $store->remember($uid, MemoryTopic::Objetivos, 'Quiere duplicar facturación en dos años.', 'agente_uno');
    $store->remember($uid, MemoryTopic::Equipo, 'Seis vendedores y un jefe de ventas.', 'agente_uno');
    $store->remember($uid, MemoryTopic::Empresa, 'Distribuidora de material eléctrico.', 'agente_uno');

    $this->assertSame(
      ['empresa', 'equipo', 'objetivos'],
      array_keys($store->forUser($uid)),
    );
  }

  /**
   * Recordar algo vacío olvida el tema.
   *
   * Es como la extracción retira un hecho que dejó de ser cierto, sin
   * necesitar una operación aparte.
   */
  public function testRecordarVacioOlvidaElTema(): void {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    $store->remember($uid, MemoryTopic::Equipo, 'Seis vendedores.', 'agente_uno');
    $store->remember($uid, MemoryTopic::Equipo, '   ', 'agente_uno');

    $this->assertTrue($store->isEmpty($uid));
  }

  /**
   * El texto se recorta, y sin partir palabras.
   *
   * El tope no es de almacenamiento sino del prompt: la memoria entera viaja
   * en cada conversación nueva, y un modelo hablador desplazaría a la
   * metodología del cliente.
   */
  public function testElTextoSeRecortaSinPartirPalabras(): void {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    $largo = str_repeat('palabra ', 200);
    $store->remember($uid, MemoryTopic::Proceso, $largo, 'agente_uno');

    $guardado = $store->forUser($uid)['proceso']->getContent();

    $this->assertLessThanOrEqual(StudentMemoryStore::MAX_LONGITUD, mb_strlen($guardado));
    $this->assertStringEndsWith('palabra', $guardado, 'No debe quedar una palabra cortada por la mitad.');
  }

  /**
   * La memoria de una alumna no aparece en la de otro.
   *
   * Es el camino por el que la lee la extracción, que corre sin usuario en
   * curso y por tanto sin que el control de acceso intervenga. Aquí la
   * garantía es que ninguna consulta del servicio prescinde del uid.
   */
  public function testCadaAlumnoSoloVeLoSuyo(): void {
    $store = $this->store();

    $store->remember((int) $this->ana->id(), MemoryTopic::Empresa, 'Distribuidora de material eléctrico.', 'agente_uno');

    $this->assertCount(1, $store->forUser((int) $this->ana->id()));
    $this->assertTrue($store->isEmpty((int) $this->bruno->id()));
  }

  /**
   * Un alumno no puede leer la memoria de otro ni aunque llegue a la entidad.
   *
   * Es la comprobación que sigue valiendo si mañana alguien añade una ruta
   * nueva y se olvida de filtrar por dueño.
   */
  public function testUnAlumnoNoPuedeLeerLaMemoriaDeOtro(): void {
    $hecho = $this->recordarDeAna();

    $this->assertTrue($hecho->access('view', $this->ana), 'La dueña sí debe poder verla.');
    $this->assertFalse($hecho->access('view', $this->bruno), 'Otro alumno no.');
  }

  /**
   * Ni borrarla.
   *
   * Borrar la memoria ajena no expone datos, pero condiciona las
   * conversaciones futuras de esa persona, y eso es decisión suya.
   */
  public function testUnAlumnoNoPuedeBorrarLaMemoriaDeOtro(): void {
    $hecho = $this->recordarDeAna();

    $this->assertTrue($hecho->access('delete', $this->ana), 'La dueña sí debe poder borrarla.');
    $this->assertFalse($hecho->access('delete', $this->bruno), 'Otro alumno no.');
  }

  /**
   * Nadie edita la memoria, ni siquiera su dueña.
   *
   * Si el alumno pudiera reescribir el texto dejaría de saberse qué salió de
   * la conversación y qué escribió él, y el agente trataría igual lo uno y lo
   * otro. Su control es borrar.
   */
  public function testLaMemoriaNoSeEdita(): void {
    $hecho = $this->recordarDeAna();

    $this->assertFalse($hecho->access('update', $this->ana));
  }

  /**
   * Borrar la cuenta borra su memoria.
   *
   * Entity API no lo hace sola. Sin el hook que lo limpia, lo que el sistema
   * sabía del negocio de esa persona seguiría ahí, y un uid reutilizado podría
   * heredarlo.
   */
  public function testBorrarLaCuentaBorraSuMemoria(): void {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    $store->remember($uid, MemoryTopic::Empresa, 'Distribuidora de material eléctrico.', 'agente_uno');
    $store->remember($uid, MemoryTopic::Equipo, 'Seis vendedores.', 'agente_uno');

    $this->ana->delete();

    $this->assertTrue($store->isEmpty($uid));
  }

  /**
   * Olvidarlo todo devuelve cuántos hechos se borraron.
   */
  public function testOlvidarloTodoLimpiaSoloAlAlumnoIndicado(): void {
    $store = $this->store();

    $store->remember((int) $this->ana->id(), MemoryTopic::Empresa, 'Distribuidora.', 'agente_uno');
    $store->remember((int) $this->ana->id(), MemoryTopic::Equipo, 'Seis vendedores.', 'agente_uno');
    $store->remember((int) $this->bruno->id(), MemoryTopic::Empresa, 'Consultora de ingeniería.', 'agente_uno');

    $this->assertSame(2, $store->forgetAll((int) $this->ana->id()));
    $this->assertTrue($store->isEmpty((int) $this->ana->id()));
    $this->assertCount(1, $store->forUser((int) $this->bruno->id()), 'La memoria del otro alumno no se toca.');
  }

  /**
   * Un tema retirado del código no rompe la memoria ya escrita.
   *
   * Es el caso que aparecería al cambiar la lista de temas con memoria en
   * producción: la fila sigue en la base de datos con un valor que el código
   * ya no reconoce. Se ignora, y el resto de la ficha se lee con normalidad.
   */
  public function testUnTemaDesconocidoSeIgnora(): void {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    $store->remember($uid, MemoryTopic::Empresa, 'Distribuidora.', 'agente_uno');

    $this->container->get('entity_type.manager')
      ->getStorage('sld_student_memory')
      ->create([
        'uid' => $uid,
        'topic' => 'tema_retirado',
        'content' => 'Algo que se recordaba con una lista de temas anterior.',
        'source_agent' => 'agente_uno',
      ])
      ->save();

    $memoria = $store->forUser($uid);

    $this->assertSame(['empresa'], array_keys($memoria));
  }

  /**
   * Deja recordado algo de Ana y devuelve la entidad.
   */
  private function recordarDeAna(): object {
    $store = $this->store();
    $uid = (int) $this->ana->id();

    $store->remember($uid, MemoryTopic::Empresa, 'Distribuidora de material eléctrico.', 'agente_uno');

    return $store->forUser($uid)['empresa'];
  }

  /**
   * Crea un alumno con el permiso de acceso al diagnóstico.
   */
  private function crearAlumno(string $nombre): User {
    $usuario = User::create([
      'name' => $nombre,
      'status' => 1,
      'roles' => [SalesLeadershipDiagnostic::STUDENT_ROLE_ID],
    ]);
    $usuario->save();

    return $usuario;
  }

  /**
   * El servicio bajo prueba.
   */
  private function store(): StudentMemoryStore {
    return $this->container->get(StudentMemoryStore::class);
  }

}
