<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Maintenance;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
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
   * Los alumnos que se piden por nombre de usuario o por correo.
   *
   * Solo alumnos: quien tenga cualquier otro rol (gestor, administrador) se
   * rechaza aunque se nombre, igual que en alumnosDePrueba().
   *
   * @param string[] $identificadores
   *   Nombres de usuario o correos.
   *
   * @return \Drupal\user\UserInterface[]
   *   Los alumnos encontrados, por uid.
   *
   * @throws \InvalidArgumentException
   *   Si alguno no existe o no es alumno: mejor no borrar nada que borrar a
   *   medias lo que se pidió.
   */
  public function alumnosPorNombre(array $identificadores): array {
    $almacen = $this->entityTypeManager->getStorage('user');
    $alumnos = [];

    foreach ($identificadores as $identificador) {
      $encontrados = $almacen->loadByProperties(['name' => $identificador])
        ?: $almacen->loadByProperties(['mail' => $identificador]);
      $usuario = reset($encontrados);

      if (!$usuario instanceof UserInterface) {
        throw new \InvalidArgumentException(sprintf('No existe ninguna cuenta «%s».', $identificador));
      }

      $roles = array_values(array_diff($usuario->getRoles(), ['authenticated']));
      if ((int) $usuario->id() === 1 || $roles !== [SalesLeadershipDiagnostic::STUDENT_ROLE_ID]) {
        throw new \InvalidArgumentException(sprintf('«%s» no es solo alumno: no se limpia.', $identificador));
      }

      $alumnos[(int) $usuario->id()] = $usuario;
    }

    return $alumnos;
  }

  /**
   * Qué se borraría de unos alumnos concretos, sin borrar nada.
   *
   * @param \Drupal\user\UserInterface[] $alumnos
   *   Los de alumnosPorNombre().
   *
   * @return array{entidades: array<string, int>, tablas: array<string, int>, estado: int, procesando: int}
   *   Cuántos elementos tiene cada uno, y cuántas conversaciones están
   *   generando un turno en este momento.
   */
  public function inventarioDe(array $alumnos): array {
    $uids = array_keys($alumnos);
    $sesiones = $this->sesionesDe($uids);

    $entidades = [];
    foreach (self::ENTIDADES as $tipo) {
      $entidades[$tipo] = $uids === [] ? 0 : (int) $this->entityTypeManager->getStorage($tipo)
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uids, 'IN')
        ->count()
        ->execute();
    }

    $tablas = [];
    foreach (self::TABLAS as $tabla) {
      $tablas[$tabla] = $this->database->schema()->tableExists($tabla)
        ? (int) $this->consultaDe($tabla, $uids, $sesiones)->countQuery()->execute()->fetchField()
        : 0;
    }

    return [
      'entidades' => $entidades,
      'tablas' => $tablas,
      'estado' => count($this->marcasDeEstado($uids)),
      'procesando' => $uids === [] ? 0 : (int) $this->entityTypeManager->getStorage('sld_diagnostic_session')
        ->getQuery()
        ->accessCheck(FALSE)
        ->condition('uid', $uids, 'IN')
        ->condition('status', DiagnosticStatus::Processing->value)
        ->count()
        ->execute(),
    ];
  }

  /**
   * Borra el uso de unos alumnos concretos, y nada de nadie más.
   *
   * Pensado para dejar una cuenta de demostración como nueva sin tocar el
   * historial de los demás (José Raúl, 19-10-2026: presentar los dos agentes
   * desde cero con la cuenta de pruebas). Los usuarios se conservan: vuelven
   * a entrar desde WordPress igual que siempre, y lo encuentran todo vacío.
   *
   * @param \Drupal\user\UserInterface[] $alumnos
   *   Los de alumnosPorNombre().
   *
   * @return array{entidades: array<string, int>, tablas: array<string, int>, estado: int, procesando: int}
   *   Lo que había antes de borrar: es lo que se borró.
   *
   * @throws \RuntimeException
   *   Si alguna de sus conversaciones está generando un turno: su elemento de
   *   la cola quedaría apuntando a una conversación borrada.
   */
  public function limpiarAlumnos(array $alumnos): array {
    $antes = $this->inventarioDe($alumnos);

    if ($antes['procesando'] > 0) {
      throw new \RuntimeException('Hay una conversación generando un turno en este momento. Espera a que termine y repite.');
    }

    $uids = array_keys($alumnos);
    $sesiones = $this->sesionesDe($uids);

    foreach (self::ENTIDADES as $tipo) {
      $almacen = $this->entityTypeManager->getStorage($tipo);
      do {
        $ids = $almacen->getQuery()
          ->accessCheck(FALSE)
          ->condition('uid', $uids, 'IN')
          ->range(0, self::TANDA)
          ->execute();
        if ($ids !== []) {
          $almacen->delete($almacen->loadMultiple($ids));
        }
      } while ($ids !== []);
    }

    foreach (self::TABLAS as $tabla) {
      if ($this->database->schema()->tableExists($tabla)) {
        $this->filtrar($this->database->delete($tabla), $tabla, $uids, $sesiones)->execute();
      }
    }

    $this->state->deleteMultiple($this->marcasDeEstado($uids));

    // Cifras e identificadores internos, nunca contenido ni correos (§43).
    $this->loggerFactory->get(SalesLeadershipDiagnostic::LOGGER_CHANNEL)->notice(
      'Limpieza de @n alumno(s) (uid @uids): @conversaciones conversación(es), @informes informe(s), @consumos registro(s) de consumo.',
      [
        '@n' => count($uids),
        '@uids' => implode(', ', $uids),
        '@conversaciones' => $antes['entidades']['sld_diagnostic_session'],
        '@informes' => $antes['entidades']['sld_diagnostic_result'],
        '@consumos' => $antes['tablas']['sld_ai_usage'],
      ],
    );

    return $antes;
  }

  /**
   * Las conversaciones de unos alumnos, incluidas las ya borradas a medias.
   *
   * @param int[] $uids
   *   Alumnos.
   *
   * @return int[]
   *   Identificadores de sus conversaciones.
   */
  private function sesionesDe(array $uids): array {
    if ($uids === []) {
      return [];
    }

    return array_map('intval', array_values($this->entityTypeManager->getStorage('sld_diagnostic_session')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', $uids, 'IN')
      ->execute()));
  }

  /**
   * Una consulta de recuento de una tabla, acotada a esos alumnos.
   *
   * @param string $tabla
   *   La tabla.
   * @param int[] $uids
   *   Alumnos.
   * @param int[] $sesiones
   *   Sus conversaciones.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   La consulta.
   */
  private function consultaDe(string $tabla, array $uids, array $sesiones): SelectInterface {
    return $this->filtrar($this->database->select($tabla, 't'), $tabla, $uids, $sesiones);
  }

  /**
   * Acota una consulta a los alumnos: por uid, o por conversación.
   *
   * Los mensajes son la única tabla sin uid: cuelgan de su conversación. Si no
   * hay nada que acotar, la condición es imposible a propósito —una consulta
   * sin condición borraría la tabla entera—.
   *
   * @param \Drupal\Core\Database\Query\ConditionInterface $consulta
   *   La consulta: un select o un delete.
   * @param string $tabla
   *   La tabla.
   * @param int[] $uids
   *   Alumnos.
   * @param int[] $sesiones
   *   Sus conversaciones.
   *
   * @return \Drupal\Core\Database\Query\ConditionInterface
   *   La misma, acotada.
   */
  private function filtrar(ConditionInterface $consulta, string $tabla, array $uids, array $sesiones): ConditionInterface {
    if ($tabla === 'sld_diagnostic_message') {
      $consulta->condition('session_id', $sesiones === [] ? [-1] : $sesiones, 'IN');
      return $consulta;
    }

    $consulta->condition('uid', $uids === [] ? [-1] : $uids, 'IN');
    return $consulta;
  }

  /**
   * Claves de estado que dependen del uso.
   *
   * @param int[]|null $uids
   *   Solo las de estos alumnos; NULL, las de todos.
   *
   * @return string[]
   *   Las claves.
   */
  private function marcasDeEstado(?array $uids = NULL): array {
    $claves = array_keys($this->keyValue->get('state')->getAll());

    return array_values(array_filter($claves, static function (string $clave) use ($uids): bool {
      foreach (self::PREFIJOS_DE_ESTADO as $prefijo) {
        if (!str_starts_with($clave, $prefijo)) {
          continue;
        }
        if ($uids === NULL) {
          return TRUE;
        }
        // La clave es «prefijo.uid.periodo» (SpendGuard).
        $uid = (int) strtok(substr($clave, strlen($prefijo)), '.');
        return in_array($uid, $uids, TRUE);
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
