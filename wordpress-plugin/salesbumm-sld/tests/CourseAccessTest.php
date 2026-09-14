<?php
/**
 * Lo que el acceso por CURSO ya hacía antes de la suscripción.
 *
 * Estas pruebas se escribieron el 13-09-2026 contra el código de la 1.2.0,
 * ANTES de tocarlo, y pasaron así. Son la garantía de que añadir el acceso por
 * suscripción no cambió nada de lo que ya funcionaba para quien compró el
 * curso.
 *
 * @package Salesbumm\SLD
 */

declare( strict_types=1 );

namespace Salesbumm\SLD\Tests;

use PHPUnit\Framework\TestCase;
use Salesbumm\SLD\AccessClock;
use Salesbumm\SLD\CourseAccess;
use Salesbumm\SLD\Plugin;
use Salesbumm\SLD\Settings;
use SldWp;

final class CourseAccessTest extends TestCase {

	private const ALUMNO = 7;
	private const CURSO  = 100;
	private const OTRO   = 200;

	protected function setUp(): void {
		SldWp::reset();
		SldWp::$users[ self::ALUMNO ] = true;
		SldWp::course( self::CURSO );
		SldWp::course( self::OTRO );
		SldWp::$options['sld_course_ids'] = self::CURSO . ', ' . self::OTRO;
	}

	public function testSinCursosConfiguradosSeDeniega(): void {
		SldWp::$options['sld_course_ids'] = '';

		$decision = $this->evaluar();

		$this->assertFalse( $decision['has_access'] );
		$this->assertSame( 'sin cursos autorizadores configurados', $decision['reason'] );
	}

	public function testUnUsuarioQueNoExisteSeDeniega(): void {
		$decision = $this->acceso()->evaluate( 999 );

		$this->assertFalse( $decision['has_access'] );
	}

	public function testSinElCursoSeDeniega(): void {
		$decision = $this->evaluar();

		$this->assertFalse( $decision['has_access'] );
		$this->assertSame( 'no posee ningún curso autorizador', $decision['reason'] );
	}

	public function testConElCursoSeConcedeYArrancaElReloj(): void {
		SldWp::access( self::ALUMNO, self::CURSO, strtotime( '-3 days' ) );

		$decision = $this->evaluar();

		$this->assertTrue( $decision['has_access'] );
		$this->assertSame( array( self::CURSO ), $decision['owned_courses'] );
		$this->assertSame( self::CURSO, $decision['course_id'] );
		$this->assertNotNull( $decision['started_at'], 'Arranca el reloj.' );
		$this->assertSame( strtotime( '+12 months', $decision['started_at'] ), $decision['expires_at'], 'Doce meses por defecto.' );
	}

	public function testAlVencerElPeriodoSeCierraAunqueTengaElCurso(): void {
		SldWp::access( self::ALUMNO, self::CURSO, strtotime( '-2 years' ) );
		SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] = strtotime( '-13 months' );

		$decision = $this->evaluar();

		$this->assertFalse( $decision['has_access'] );
		$this->assertSame( 'periodo de acceso caducado', $decision['reason'] );
	}

	public function testConCeroMesesNoCaduca(): void {
		SldWp::$options['sld_access_months'] = 0;
		SldWp::access( self::ALUMNO, self::CURSO, strtotime( '-5 years' ) );
		SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] = strtotime( '-5 years' );

		$decision = $this->evaluar();

		$this->assertTrue( $decision['has_access'] );
		$this->assertNull( $decision['expires_at'] );
	}

	public function testVariosCursosLleganEnSuOrden(): void {
		SldWp::access( self::ALUMNO, self::OTRO, time() );
		SldWp::access( self::ALUMNO, self::CURSO, time() );

		$decision = $this->evaluar();

		$this->assertSame( array( self::CURSO, self::OTRO ), $decision['owned_courses'], 'En el orden configurado.' );
	}

	public function testUnCursoSinPublicarNoCuenta(): void {
		SldWp::course( self::CURSO, 'draft' );
		SldWp::access( self::ALUMNO, self::CURSO, time() );

		$this->assertFalse( $this->evaluar()['has_access'] );
	}

	public function testDesdeElAltaUsaLaFechaDeLearnDash(): void {
		SldWp::$options['sld_access_start_origin'] = 'enrollment';
		$alta                                      = strtotime( '-40 days' );
		SldWp::access( self::ALUMNO, self::CURSO, $alta );

		$this->assertSame( $alta, $this->evaluar()['started_at'] );
	}

	public function testVolverAComprarReiniciaElPeriodo(): void {
		SldWp::access( self::ALUMNO, self::CURSO, strtotime( '-2 years' ) );
		SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] = strtotime( '-13 months' );
		$this->assertFalse( $this->evaluar()['has_access'] );

		$this->acceso()->reactivate( self::ALUMNO, 'compra de prueba' );

		$this->assertTrue( $this->evaluar()['has_access'] );
	}

	public function testElEngancheSoloReiniciaConUnCursoAutorizador(): void {
		$vencido = strtotime( '-13 months' );
		SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] = $vencido;
		$plugin = Plugin::instance();

		$plugin->on_course_access_granted( self::ALUMNO, 555 );
		$this->assertSame( $vencido, SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ], 'Otro curso del catálogo no reinicia.' );

		$plugin->on_course_access_granted( self::ALUMNO, self::CURSO, array(), true );
		$this->assertSame( $vencido, SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ], 'Retirar el acceso no reinicia.' );

		$plugin->on_course_access_granted( self::ALUMNO, self::CURSO );
		$this->assertGreaterThan( $vencido, SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ], 'Dar el curso autorizador reinicia.' );
	}

	/**
	 * La decisión para el alumno de la prueba.
	 *
	 * @return array<string, mixed>
	 */
	private function evaluar(): array {
		return $this->acceso()->evaluate( self::ALUMNO );
	}

	private function acceso(): CourseAccess {
		$ajustes = new Settings();

		return new CourseAccess( $ajustes, new AccessClock( $ajustes ) );
	}
}
