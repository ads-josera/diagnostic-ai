<?php

declare(strict_types=1);

namespace Drupal\Tests\sales_leadership_diagnostic\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\sales_leadership_diagnostic\Exception\InvalidEngineResponseException;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Comprueba la validación de lo que devuelve el motor de IA.
 *
 * Esta clase es la frontera entre lo que dice un tercero y lo que el módulo
 * almacena. Si deja pasar una respuesta malformada, el fallo se descubre al
 * mostrarle al alumno un resultado corrupto, cuando ya es tarde.
 */
#[CoversClass(DiagnosticResponseValidator::class)]
final class DiagnosticResponseValidatorTest extends UnitTestCase {

  /**
   * Validador bajo prueba.
   *
   * @var \Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticResponseValidator
   */
  private DiagnosticResponseValidator $validator;

  /**
   * Doble del registro, para comprobar los avisos de aritmética.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface&\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerChannelInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($this->logger);

    $this->validator = new DiagnosticResponseValidator($loggerFactory);
  }

  /**
   * Un turno intermedio válido se acepta y no se marca como completado.
   */
  public function testAceptaTurnoIntermedio(): void {
    $turno = $this->validator->validate([
      'type' => 'diagnostic_response',
      'message' => '¿Cómo gestionáis el forecast?',
      'status' => 'in_progress',
      'result' => NULL,
    ]);

    $this->assertSame('¿Cómo gestionáis el forecast?', $turno->message);
    $this->assertFalse($turno->completed);
    $this->assertNull($turno->result);
  }

  /**
   * Un turno final válido se marca como completado y conserva el resultado.
   */
  public function testAceptaTurnoFinalConResultado(): void {
    $turno = $this->validator->validate([
      'type' => 'diagnostic_result',
      'message' => 'Hemos terminado.',
      'status' => 'completed',
      'result' => [
        'summary' => 'Resumen del diagnóstico.',
        'score' => 62,
        'strengths' => ['Una fortaleza'],
        'opportunities' => [],
        'recommendations' => [],
        'priority_actions' => [],
      ],
    ]);

    $this->assertTrue($turno->completed);
    $this->assertSame(62, $turno->result['score']);
    $this->assertSame('Resumen del diagnóstico.', $turno->result['summary']);
  }

  /**
   * Se admite el resultado al mismo nivel que el mensaje.
   *
   * El prompt del cliente puede formularlo de cualquiera de las dos maneras y
   * el módulo no debe imponerle una.
   */
  public function testAceptaResultadoNoAnidado(): void {
    $turno = $this->validator->validate([
      'type' => 'diagnostic_result',
      'message' => 'Listo.',
      'status' => 'completed',
      'summary' => 'Resumen suelto.',
      'score' => 40,
      'strengths' => ['x'],
    ]);

    $this->assertTrue($turno->completed);
    $this->assertSame('Resumen suelto.', $turno->result['summary']);
  }

  /**
   * Un estado "completed" basta para dar el diagnóstico por terminado.
   */
  public function testStatusCompletedCierraElDiagnostico(): void {
    $turno = $this->validator->validate([
      'type' => 'diagnostic_response',
      'message' => 'Cierre.',
      'status' => 'completed',
      'result' => ['summary' => 'Resumen.'],
    ]);

    $this->assertTrue($turno->completed);
  }

  /**
   * Un tipo de turno desconocido se rechaza.
   */
  public function testRechazaTipoDesconocido(): void {
    $this->expectException(InvalidEngineResponseException::class);

    $this->validator->validate([
      'type' => 'otra_cosa',
      'message' => 'Hola.',
      'status' => 'in_progress',
    ]);
  }

  /**
   * Un turno sin mensaje se rechaza.
   *
   * El mensaje es lo único que el alumno lee: un turno sin él dejaría la
   * conversación en blanco sin ninguna explicación.
   */
  public function testRechazaTurnoSinMensaje(): void {
    $this->expectException(InvalidEngineResponseException::class);

    $this->validator->validate([
      'type' => 'diagnostic_response',
      'message' => '   ',
      'status' => 'in_progress',
    ]);
  }

  /**
   * Un mensaje desmesurado se rechaza.
   *
   * El texto acaba en la base de datos y en la pantalla; una respuesta
   * desbocada no debe poder llenar ninguna de las dos.
   */
  public function testRechazaMensajeExcesivamenteLargo(): void {
    $this->expectException(InvalidEngineResponseException::class);

    $this->validator->validate([
      'type' => 'diagnostic_response',
      'message' => str_repeat('a', 20001),
      'status' => 'in_progress',
    ]);
  }

  /**
   * Declarar el diagnóstico completado sin resultado se rechaza.
   *
   * Aceptarlo produciría una sesión cerrada cuyo resultado está vacío, que es
   * peor que un error: el alumno vería un informe en blanco.
   */
  public function testRechazaCompletadoSinResultado(): void {
    $this->expectException(InvalidEngineResponseException::class);

    $this->validator->validate([
      'type' => 'diagnostic_result',
      'message' => 'Terminado.',
      'status' => 'completed',
      'result' => NULL,
    ]);
  }

  /**
   * La respuesta cruda se conserva íntegra para almacenarla.
   */
  public function testConservaLaRespuestaCruda(): void {
    $crudo = [
      'type' => 'diagnostic_response',
      'message' => 'Pregunta.',
      'status' => 'in_progress',
      'next_step' => 'dimension_2',
      'result' => NULL,
    ];

    $turno = $this->validator->validate($crudo);

    $this->assertSame($crudo, $turno->raw);
    $this->assertSame('dimension_2', $turno->raw['next_step']);
  }

  /**
   * Un global que no cuadra con la suma de dimensiones deja aviso.
   *
   * Lo pide la metodología del cliente en su control de calidad. Es el tipo de
   * error que un modelo comete sin avisar y que nadie ve leyendo el informe,
   * porque las dos cifras están en secciones distintas.
   */
  public function testAvisaSiElGlobalNoCuadraConLasDimensiones(): void {
    $this->logger->expects($this->once())->method('warning');

    $this->validator->validate($this->resultadoCon(90, [30, 20, 10]));
  }

  /**
   * Un descuadre de medio punto NO avisa.
   *
   * La rúbrica del cliente usa medios puntos por dimensión y nuestro campo de
   * puntuación es entero: un global de 20,5 se guarda como 21 sin que nadie se
   * haya equivocado. Avisar de eso sería ruido en cada diagnóstico.
   */
  public function testElRedondeoDeMedioPuntoNoAvisa(): void {
    $this->logger->expects($this->never())->method('warning');

    $this->validator->validate($this->resultadoCon(21, [1.5, 9, 10]));
  }

  /**
   * Sin dimensiones no hay nada que cuadrar.
   *
   * Es el caso de los diagnósticos anteriores al 26-08-2026 y el de un
   * diagnóstico parcial, que la metodología prohíbe puntuar.
   */
  public function testSinDimensionesNoSeComprueba(): void {
    $this->logger->expects($this->never())->method('warning');

    $this->validator->validate($this->resultadoCon(50, []));
  }

  /**
   * Turno final con la puntuación y las dimensiones indicadas.
   *
   * @param int $global
   *   Puntuación global declarada.
   * @param float[] $dimensiones
   *   Puntuación de cada dimensión.
   *
   * @return array<string, mixed>
   *   Respuesta cruda del motor.
   */
  private function resultadoCon(int $global, array $dimensiones): array {
    return [
      'type' => 'diagnostic_result',
      'status' => 'completed',
      'message' => 'Informe final.',
      'result' => [
        'summary' => 'Resumen.',
        'score' => $global,
        'dimensions' => array_map(
          static fn (float|int $puntos): array => [
            'name' => 'Dimensión',
            'score' => $puntos,
            'max' => 10,
            'level' => 'CRITICAL',
            'confidence' => 'MEDIUM',
          ],
          $dimensiones,
        ),
      ],
    ];
  }

}
