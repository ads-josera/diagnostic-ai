<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Entity\DiagnosticSession;
use Drupal\sales_leadership_diagnostic\Hook\DiagnosticSessionHooks;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba que borrar una sesión se lleva todo lo que colgaba de ella.
 *
 * Entity API no limpia nada de esto por su cuenta. Los mensajes viven en una
 * tabla propia que no conoce, y el resultado apunta a la sesión y no al revés,
 * así que borrar la sesión lo deja señalando a algo que ya no existe.
 *
 * Lo de los mensajes estaba resuelto desde el principio. **El resultado no**, y
 * se descubrió el 31-08-2026 borrando una sesión de pruebas y mirando la base
 * de datos después: el diagnóstico seguía ahí.
 *
 * Falla por dos sitios a la vez, y el segundo es el que decide:
 *
 *  - **Privacidad.** Lo que quedaba es el análisis del negocio de una persona,
 *    en una sesión que el sistema da por eliminada (§43).
 *  - **Es INALCANZABLE.** El historial del alumno se construye a partir de sus
 *    sesiones, de modo que un resultado sin la suya no aparece en ninguna
 *    pantalla: nadie puede verlo, y nadie puede borrarlo tampoco. Se queda ahí
 *    para siempre.
 *
 * No confundir con la retención de conversaciones, que borra mensajes y NUNCA
 * resultados. Allí la sesión sigue viva y el diagnóstico sigue alcanzable, que
 * es justamente lo que aquí no ocurre.
 */
#[CoversClass(DiagnosticSessionHooks::class)]
final class SessionDeletionTest extends KernelTestBase {

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

  private const ALUMNO = 9;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('sld_diagnostic_session');
    $this->installEntitySchema('sld_diagnostic_result');
    $this->installSchema('sales_leadership_diagnostic', ['sld_diagnostic_message']);
    $this->installConfig(['sales_leadership_diagnostic']);
  }

  /**
   * Borrar la sesión se lleva su resultado.
   *
   * Es la prueba nueva, y la que cierra el agujero.
   */
  public function testBorrarLaSesionSeLlevaSuResultado(): void {
    $sesion = $this->crearSesionConDatos();

    $sesion->delete();

    $this->assertSame(0, $this->contarResultados(), 'El diagnóstico no puede sobrevivir a su sesión.');
  }

  /**
   * Borrar la sesión se lleva sus mensajes.
   */
  public function testBorrarLaSesionSeLlevaSusMensajes(): void {
    $sesion = $this->crearSesionConDatos();
    $id = (int) $sesion->id();

    $sesion->delete();

    $this->assertSame(0, $this->contarMensajes($id));
  }

  /**
   * No se lleva por delante lo de OTRA sesión.
   *
   * Es el límite. Una cascada demasiado ancha aquí borraría diagnósticos que
   * nadie pidió eliminar, y son el entregable del producto.
   */
  public function testNoSeLlevaLoDeOtraSesion(): void {
    $primera = $this->crearSesionConDatos();
    $this->crearSesionConDatos();

    $primera->delete();

    $this->assertSame(1, $this->contarResultados(), 'Solo debe desaparecer el resultado de la sesión borrada.');
  }

  /**
   * Una sesión sin resultado se borra sin quejarse.
   *
   * Es el caso más común: la mayoría de las sesiones que se borran son las que
   * nunca llegaron a producir nada.
   */
  public function testUnaSesionSinResultadoSeBorraSinRomper(): void {
    $sesion = DiagnosticSession::create([
      'uid' => self::ALUMNO,
      'wp_user_id' => '777',
      'course_id' => '35884',
      'agent' => 'sales_leadership_diagnostic',
      'diagnostic_version' => '1.1',
      'prompt_snapshot' => 'PROMPT',
      'prompt_hash' => hash('sha256', 'PROMPT'),
    ]);
    $sesion->setStatus(DiagnosticStatus::Draft);
    $sesion->save();

    $sesion->delete();

    $this->assertSame(0, $this->contarSesiones());
  }

  /**
   * Crea una sesión completada con su mensaje y su resultado.
   */
  private function crearSesionConDatos(): DiagnosticSession {
    $sesion = DiagnosticSession::create([
      'uid' => self::ALUMNO,
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

    $this->container->get('entity_type.manager')
      ->getStorage('sld_diagnostic_result')
      ->create([
        'uid' => self::ALUMNO,
        'session_id' => $sesion->id(),
        'agent' => 'sales_leadership_diagnostic',
        'diagnostic_version' => '1.1',
        'summary' => 'Análisis del negocio del alumno.',
        'score' => 30,
      ])->save();

    return $sesion;
  }

  /**
   * Cuántos resultados quedan.
   */
  private function contarResultados(): int {
    return $this->contarEntidades('sld_diagnostic_result');
  }

  /**
   * Cuántas sesiones quedan.
   */
  private function contarSesiones(): int {
    return $this->contarEntidades('sld_diagnostic_session');
  }

  /**
   * Cuántas entidades del tipo indicado quedan.
   */
  private function contarEntidades(string $tipo): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage($tipo)
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

  /**
   * Cuántos mensajes quedan de una sesión.
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
