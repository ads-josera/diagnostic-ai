<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Maintenance;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\sales_leadership_diagnostic\SalesLeadershipDiagnostic;
use Drupal\user\UserInterface;

/**
 * Borra lo que dejan las pruebas, antes de abrir el producto a alumnos reales.
 *
 * Lo pidió José Raúl el 14-09-2026: cuando el cliente dé el visto bueno en
 * producción, limpiar lo de las pruebas para no arrastrar ruido —
 * conversaciones, consumos, cuentas— en las cifras y los listados. Se
 * construyó ANTES del despliegue a propósito: así llega probado, y el día de
 * usarlo es un solo comando y no algo improvisado en producción.
 *
 * Qué borra: todo lo que genera el USO del producto. Conversaciones y sus
 * mensajes, informes, memoria del alumno, cuentas de prospección, consumo de
 * IA, evidencias, cupos de investigación, llamadas a herramientas, los avisos
 * de gasto ya enviados y los turnos pendientes en cola.
 *
 * Qué NO borra: la configuración (agentes, prompts, documentos, marca,
 * portada, topes), los usuarios que no son alumnos (administrador, gestor) y
 * los alumnos que se piden conservar (por defecto, alumno.demo). Los
 * borradores del Estudio tampoco: son trabajo del gestor, no rastro de uso.
 *
 * Los alumnos de prueba solo se borran si se pide, y solo los que llegaron
 * desde WordPress y no tienen más rol que el de alumno.
 */
final class TestDataCleaner {

  /**
   * Entidades de uso, en el orden en que se borran.
   *
   * Los informes antes que sus conversaciones: referencian a su sesión. Al
   * borrar una conversación se van sus mensajes (DiagnosticSessionHooks).
   */
  public const ENTIDADES = [
    'sld_diagnostic_result',
    'sld_diagnostic_session',
    'sld_student_memory',
  ];

  /**
   * Tablas propias sin entidad.
   *
   * Los mensajes van al final por si quedara alguno huérfano: la tabla se
   * vacía igual aunque su sesión ya no exista.
   */
  public const TABLAS = [
    'sld_account_event',
    'sld_account',
    'sld_ai_usage',
    'sld_evidence',
    'sld_research_entitlement',
    'sld_tool_call',
    'sld_diagnostic_message',
  ];

  /**
   * Marcas de estado que dependen del uso: «ya avisé a este alumno del 80 %».
   *
   * Sin borrarlas, un alumno que en las pruebas cruzó el umbral no volvería a
   * recibir el aviso ese mes.
   */
  private const PREFIJOS_DE_ESTADO = ['sld.spend_warned.'];

  /**
   * Cola de turnos en segundo plano.
   *
   * Un turno pendiente de una conversación que ya no existe fallaría al
   * procesarse.
   */
  private const COLA = 'sld_diagnostic_turn';

  /**
   * Entidades que se cargan por tanda: miles de golpe agotan la memoria.
   */
  private const TANDA = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly StateInterface $state,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly QueueFactory $queues,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Qué se borraría, sin borrar nada.
   *
   * @param bool $conAlumnos
   *   Si se incluyen los alumnos de prueba.
   * @param string[] $conservar
   *   Nombres de usuario que no se tocan nunca.
   *
   * @return array{entidades: array<string, int>, tablas: array<string, int>, estado: int, cola: int, alumnos: string[]}
   *   Cuántos elementos hay de cada cosa, y qué alumnos se borrarían.
   */
  public function inventario(bool $conAlumnos, array $conservar): array {
    $entidades = [];
    foreach (self::ENTIDADES as $tipo) {
      $entidades[$tipo] = (int) $this->entityTypeManager->getStorage($tipo)
        ->getQuery()
        ->accessCheck(FALSE)
        ->count()
        ->execute();
    }

    $tablas = [];
    foreach (self::TABLAS as $tabla) {
      $tablas[$tabla] = $this->database->schema()->tableExists($tabla)
        ? (int) $this->database->select($tabla)->countQuery()->execute()->fetchField()
        : 0;
    }

    return [
      'entidades' => $entidades,
      'tablas' => $tablas,
      'estado' => count($this->marcasDeEstado()),
      'cola' => (int) $this->queues->get(self::COLA)->numberOfItems(),
      'alumnos' => $conAlumnos
        ? array_map(static fn (UserInterface $u): string => $u->getAccountName(), $this->alumnosDePrueba($conservar))
        : [],
    ];
  }

  /**
   * Borra los datos de uso.
   *
   * @param bool $conAlumnos
   *   Si se borran también los alumnos de prueba.
   * @param string[] $conservar
   *   Nombres de usuario que no se tocan nunca.
   *
   * @return array{entidades: array<string, int>, tablas: array<string, int>, estado: int, cola: int, alumnos: string[]}
   *   Lo que había antes de borrar: es lo que se borró.
   */
  public function limpiar(bool $conAlumnos, array $conservar): array {
    $antes = $this->inventario($conAlumnos, $conservar);

    foreach (self::ENTIDADES as $tipo) {
      $almacen = $this->entityTypeManager->getStorage($tipo);
      do {
        $ids = $almacen->getQuery()->accessCheck(FALSE)->range(0, self::TANDA)->execute();
        if ($ids !== []) {
          $almacen->delete($almacen->loadMultiple($ids));
        }
      } while ($ids !== []);
    }

    foreach (self::TABLAS as $tabla) {
      if ($this->database->schema()->tableExists($tabla)) {
        $this->database->delete($tabla)->execute();
      }
    }

    // Por el servicio de estado y no por el almacén directo: el estado guarda
    // una copia en caché y la dejaría desfasada.
    $this->state->deleteMultiple($this->marcasDeEstado());
    $this->queues->get(self::COLA)->deleteQueue();

    if ($conAlumnos) {
      foreach ($this->alumnosDePrueba($conservar) as $alumno) {
        $alumno->delete();
      }
    }

    // Cifras, nunca contenido (§43).
    $this->loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL)->notice(
      'Limpieza de datos de prueba: @conversaciones conversación(es), @informes informe(s), @consumos registro(s) de consumo y @alumnos alumno(s) de prueba.',
      [
        '@conversaciones' => $antes['entidades']['sld_diagnostic_session'],
        '@informes' => $antes['entidades']['sld_diagnostic_result'],
        '@consumos' => $antes['tablas']['sld_ai_usage'],
        '@alumnos' => count($antes['alumnos']),
      ],
    );

    return $antes;
  }

  /**
   * Claves de estado que dependen del uso.
   *
   * @return string[]
   *   Las claves.
   */
  private function marcasDeEstado(): array {
    $claves = array_keys($this->keyValue->get('state')->getAll());

    return array_values(array_filter($claves, static function (string $clave): bool {
      foreach (self::PREFIJOS_DE_ESTADO as $prefijo) {
        if (str_starts_with($clave, $prefijo)) {
          return TRUE;
        }
      }
      return FALSE;
    }));
  }

  /**
   * Alumnos de prueba: llegaron desde WordPress y solo tienen el rol de alumno.
   *
   * La doble condición es la salvaguarda. Un gestor o un administrador nunca
   * cumplen la segunda aunque alguien los enlazara a WordPress, y quien figure
   * en $conservar queda fuera siempre.
   *
   * @param string[] $conservar
   *   Nombres de usuario que no se tocan.
   *
   * @return \Drupal\user\UserInterface[]
   *   Los alumnos que se borrarían.
   */
  private function alumnosDePrueba(array $conservar): array {
    if (!$this->database->schema()->tableExists('authmap')) {
      return [];
    }

    $uids = $this->database->select('authmap', 'a')
      ->fields('a', ['uid'])
      ->condition('provider', SalesLeadershipDiagnostic::AUTHMAP_PROVIDER)
      ->execute()
      ->fetchCol();

    $alumnos = [];
    foreach ($this->entityTypeManager->getStorage('user')->loadMultiple($uids) as $usuario) {
      if (!$usuario instanceof UserInterface) {
        continue;
      }
      // array_values: array_diff conserva las posiciones, y ['1' => rol] no es
      // igual a [rol]. Sin él la comparación de abajo no coincidía nunca y no
      // se borraba ningún alumno de prueba, sin avisar. Lo pilló la prueba.
      $roles = array_values(array_diff($usuario->getRoles(), ['authenticated']));
      if (
        (int) $usuario->id() !== 1
        && !in_array($usuario->getAccountName(), $conservar, TRUE)
        && $roles === [SalesLeadershipDiagnostic::STUDENT_ROLE_ID]
      ) {
        $alumnos[] = $usuario;
      }
    }

    return $alumnos;
  }

}
