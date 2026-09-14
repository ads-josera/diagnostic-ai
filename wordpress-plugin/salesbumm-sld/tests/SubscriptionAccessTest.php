<?php
/**
 * El acceso por suscripción (1.3.0).
 *
 * El cliente pidió el 13-09-2026 que, además de por curso, se pueda entrar a
 * los dos agentes con una suscripción mensual o anual. La suscripción la cobra
 * WooCommerce Subscriptions y la traduce a un curso de LearnDash: mientras está
 * al corriente, el alumno tiene ese curso; si se cancela o deja de pagar, lo
 * pierde. El plugin solo tiene que saber qué curso es.
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

final class SubscriptionAccessTest extends TestCase {

	private const ALUMNO      = 7;
	private const CURSO       = 100;
	private const OTRO        = 200;
	private const SUSCRIPCION = 300;

	protected function setUp(): void {
		SldWp::reset();
		SldWp::$users[ self::ALUMNO ] = true;
		SldWp::course( self::CURSO );
		SldWp::course( self::OTRO );
		SldWp::course( self::SUSCRIPCION );
		SldWp::$options['sld_course_ids']              = self::CURSO . ', ' . self::OTRO;
		SldWp::$options['sld_subscription_course_ids'] = (string) self::SUSCRIPCION;
	}

	public function testUnSuscriptorEntraALosDosAgentesSinCaducidad(): void {
		$alta = strtotime( '-20 days' );
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, $alta );

		$decision = $this->evaluar();

		$this->assertTrue( $decision['has_access'] );
		$this->assertSame( array( self::CURSO, self::OTRO ), $decision['owned_courses'], 'Todos los cursos que dan agente.' );
		$this->assertSame( self::CURSO, $decision['course_id'] );
		$this->assertNull( $decision['expires_at'], 'Dura lo que dure la suscripción.' );
		$this->assertSame( $alta, $decision['started_at'], 'Desde que se suscribió.' );
		$this->assertSame( 'acceso por suscripción', $decision['reason'] );
	}

	public function testUnSuscriptorNoArrancaNingunReloj(): void {
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, time() );

		$this->evaluar();

		$this->assertArrayNotHasKey( AccessClock::META_STARTED, SldWp::$user_meta[ self::ALUMNO ] ?? array() );
	}

	public function testAlCancelarLaSuscripcionPierdeElAcceso(): void {
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, strtotime( '-2 months' ) );
		$this->assertTrue( $this->evaluar()['has_access'] );

		// Lo que hace la integración de WooCommerce al cancelar o no pagar.
		SldWp::access( self::ALUMNO, self::SUSCRIPCION );

		$decision = $this->evaluar();
		$this->assertFalse( $decision['has_access'] );
		$this->assertSame( 'no posee ningún curso autorizador', $decision['reason'] );
	}

	public function testLaSuscripcionAbreAunqueElCursoHayaCaducado(): void {
		SldWp::access( self::ALUMNO, self::CURSO, strtotime( '-2 years' ) );
		SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] = strtotime( '-13 months' );
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, time() );

		$this->assertTrue( $this->evaluar()['has_access'] );

		SldWp::access( self::ALUMNO, self::SUSCRIPCION );

		$this->assertSame( 'periodo de acceso caducado', $this->evaluar()['reason'], 'Sin suscripción vuelve a mandar el curso.' );
	}

	/**
	 * Quien compró el curso y además se suscribe no gana ni pierde nada.
	 *
	 * Su periodo de doce meses corre desde la compra, como siempre. Si no
	 * arrancara durante la suscripción, al cancelarla empezaría a contar de
	 * cero y le regalaría otro año.
	 */
	public function testConLasDosCosasElRelojDelCursoCorreIgual(): void {
		SldWp::access( self::ALUMNO, self::CURSO, strtotime( '-3 days' ) );
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, time() );

		$this->evaluar();

		$this->assertArrayHasKey( AccessClock::META_STARTED, SldWp::$user_meta[ self::ALUMNO ] );
	}

	/**
	 * Un curso de suscripción Abierto o Gratis no regala los agentes.
	 *
	 * En LearnDash lo tendría cualquiera, así que se ignora.
	 */
	public function testUnCursoDeSuscripcionAbiertoOGratisNoAbre(): void {
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, time() );

		foreach ( array( 'open', 'free' ) as $modo ) {
			SldWp::$price_types[ self::SUSCRIPCION ] = $modo;
			$this->assertFalse( $this->evaluar()['has_access'], $modo );
		}

		SldWp::$price_types[ self::SUSCRIPCION ] = 'closed';
		$this->assertTrue( $this->evaluar()['has_access'] );
	}

	public function testUnCursoDeSuscripcionSinPublicarNoAbre(): void {
		SldWp::course( self::SUSCRIPCION, 'draft' );
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, time() );

		$this->assertFalse( $this->evaluar()['has_access'] );
	}

	/**
	 * Si por error se pone el mismo curso en las dos listas, manda suscripción.
	 *
	 * Como curso normal le arrancaría el reloj y lo cerraría a los doce meses
	 * aunque el alumno siguiera pagando.
	 */
	public function testUnCursoEnLasDosListasCuentaComoSuscripcion(): void {
		SldWp::$options['sld_course_ids'] = self::CURSO . ', ' . self::SUSCRIPCION;
		SldWp::access( self::ALUMNO, self::SUSCRIPCION, strtotime( '-2 years' ) );

		$decision = $this->evaluar();

		$this->assertSame( array( self::CURSO ), ( new Settings() )->get_course_ids() );
		$this->assertSame( array( self::CURSO, self::SUSCRIPCION ), ( new Settings() )->get_configured_course_ids(), 'El campo enseña lo escrito: el aviso pide quitarlo de ahí.' );
		$this->assertTrue( $decision['has_access'] );
		$this->assertNull( $decision['expires_at'] );
	}

	public function testRenovarLaSuscripcionNoTocaElRelojDelCurso(): void {
		$vencido = strtotime( '-13 months' );
		SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] = $vencido;

		Plugin::instance()->on_course_access_granted( self::ALUMNO, self::SUSCRIPCION );

		$this->assertSame( $vencido, SldWp::$user_meta[ self::ALUMNO ][ AccessClock::META_STARTED ] );
	}

	public function testLosAjustesLimpianLaLista(): void {
		$this->assertSame( '300, 301', ( new Settings() )->sanitize_subscription_course_ids( ' 300,301 300 abc' ) );
	}

	/**
	 * La decisión para el alumno de la prueba.
	 *
	 * @return array<string, mixed>
	 */
	private function evaluar(): array {
		$ajustes = new Settings();

		return ( new CourseAccess( $ajustes, new AccessClock( $ajustes ) ) )->evaluate( self::ALUMNO );
	}
}
