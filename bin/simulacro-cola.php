<?php

/**
 * @file
 * Comprueba que varios recogedores de la cola no generan el mismo turno.
 *
 * Existe por un cambio del 22-09-2026: hasta entonces solo el cron recogía la
 * cola de turnos, y pasó a haber tres procesadores para que los alumnos no
 * esperaran hasta un minuto a que empezara su investigación. Con más de uno
 * aparece un riesgo que antes no existía: que dos tomen el mismo turno. Eso
 * costaría dos llamadas al proveedor y dejaría dos respuestas seguidas del
 * agente en la misma conversación.
 *
 * Contra eso hay dos barreras —la reserva que la cola pone sobre el elemento y
 * el cerrojo de la conversación—, y las pruebas de PHPUnit cubren el caso de
 * dos procesos simulados. Esto es lo otro: procesos de verdad, a la vez, sobre
 * la base de datos de verdad.
 *
 * NO GASTA DINERO, y se niega a correr si pudiera gastarlo: exige el motor
 * simulado. Con el motor real, 200 turnos serían 200 llamadas de pago.
 *
 * Uso, en LOCAL:
 * @code
 *   # 1. Encender el motor simulado en web/sites/default/settings.local.php:
 *   #    $settings['sld_use_mock_engine'] = TRUE;
 *   ddev drush cr
 *
 *   # 2. Encolar turnos de mentira (200 por defecto):
 *   ddev drush php:script bin/simulacro-cola.php -- preparar 200
 *
 *   # 3. Lanzar los recogedores A LA VEZ, en la misma orden:
 *   for n in a b c; do (ddev drush queue:run sld_diagnostic_turn) & done; wait
 *
 *   # 4. Comprobar y limpiar, con los ids que imprimió el paso 2:
 *   ddev drush php:script bin/simulacro-cola.php -- verificar 184,185,186
 *
 *   # 5. Quitar el motor simulado de settings.local.php y `ddev drush cr`.
 * @endcode
 *
 * Qué mirar en la salida:
 *
 * - **Ningún duplicado.** Es lo que de verdad se comprueba. El 22-09-2026
 *   salieron 200 turnos y 200 respuestas.
 * - **Cuántos procesó cada recogedor.** Drush lo dice al terminar. Si dos
 *   reparten el trabajo, la concurrencia funciona; que uno se lleve todo no es
 *   un fallo, solo significa que terminó antes de que los otros arrancaran,
 *   porque el motor simulado responde en milésimas.
 *
 * Lo que este simulacro NO mide: los tiempos reales —un turno de verdad tarda
 * entre 26 segundos y 3 minutos— ni el consumo de CPU con varias
 * investigaciones simultáneas. Eso solo se ve con uso real, y para enterarse
 * está el bloque de cola de la pantalla de Consumo.
 */

declare(strict_types=1);

use Drupal\Core\Site\Settings;
use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\MessageRole;
use Drupal\sales_leadership_diagnostic\Plugin\QueueWorker\DiagnosticTurnWorker;
use Drupal\sales_leadership_diagnostic\Repository\DiagnosticMessageRepository;
use Drupal\sales_leadership_diagnostic\Service\Engine\DiagnosticEngineFactory;

/**
 * Encola turnos de mentira.
 */
function simulacro_preparar(int $cuantos, int $alumno, string $agente): void {
  $almacen = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session');
  $mensajes = \Drupal::service(DiagnosticMessageRepository::class);
  $cola = \Drupal::service('queue')->get(DiagnosticTurnWorker::QUEUE);
  $ahora = \Drupal::time()->getRequestTime();

  $ids = [];

  for ($i = 0; $i < $cuantos; $i++) {
    $sesion = $almacen->create([
      'uid' => $alumno,
      'wp_user_id' => '999000',
      'course_id' => '38125',
      'agent' => $agente,
      // Marca para reconocer estas sesiones si algo dejara restos.
      'diagnostic_version' => 'SIMULACRO-COLA',
      'prompt_snapshot' => 'PROMPT DE SIMULACRO',
      'started_at' => $ahora,
    ]);
    $sesion->setStatus(DiagnosticStatus::Processing);
    $sesion->save();

    $id = (int) $sesion->id();

    // Un mensaje del alumno, porque el turno se genera a partir de algo.
    $mensajes->append($id, MessageRole::User, 'Simulacro de concurrencia.');
    $cola->createItem(['session_id' => $id]);

    $ids[] = $id;
  }

  echo 'encoladas: ' . count($ids) . "\n";
  echo 'ids: ' . implode(',', $ids) . "\n";
}

/**
 * Comprueba que cada turno se generó una sola vez, y borra lo creado.
 *
 * @param int[] $ids
 *   Sesiones del simulacro.
 */
function simulacro_verificar(array $ids): void {
  $bd = \Drupal::database();
  $almacen = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session');

  $respuestas = $bd->select('sld_diagnostic_message', 'm')
    ->fields('m', ['session_id'])
    ->condition('session_id', $ids, 'IN')
    ->condition('role', MessageRole::Assistant->value)
    ->execute()
    ->fetchCol();

  $porSesion = array_count_values(array_map('intval', $respuestas));

  $sinRespuesta = [];
  $duplicadas = [];

  foreach ($ids as $id) {
    $cuantas = $porSesion[$id] ?? 0;

    if ($cuantas === 0) {
      $sinRespuesta[] = $id;
    }
    elseif ($cuantas > 1) {
      $duplicadas[] = $id . ' (' . $cuantas . ')';
    }
  }

  echo 'sesiones del simulacro: ' . count($ids) . "\n";
  echo 'con UNA respuesta: ' . (count($ids) - count($sinRespuesta) - count($duplicadas)) . "\n";
  echo 'sin respuesta: ' . ($sinRespuesta === [] ? 'ninguna' : implode(',', $sinRespuesta)) . "\n";
  echo 'CON DUPLICADO: ' . ($duplicadas === [] ? 'ninguna' : implode(' · ', $duplicadas)) . "\n";
  echo 'quedan en cola: ' . \Drupal::service('queue')->get(DiagnosticTurnWorker::QUEUE)->numberOfItems() . "\n";

  // Limpieza. Se borra todo lo que creó el simulacro: si quedara, ensuciaría
  // el consumo y el panel del alumno con conversaciones que nunca existieron.
  $bd->delete('sld_diagnostic_message')->condition('session_id', $ids, 'IN')->execute();
  $bd->delete('sld_ai_usage')->condition('session_id', $ids, 'IN')->execute();
  $almacen->delete($almacen->loadMultiple($ids));
  $bd->delete('queue')->condition('name', DiagnosticTurnWorker::QUEUE)->execute();

  echo 'limpiado. Sesiones del simulacro que quedan: ' . count($almacen->loadMultiple($ids)) . "\n";
}

// El guion empieza aquí.
if (Settings::get(DiagnosticEngineFactory::MOCK_SETTING) !== TRUE) {
  echo "ABORTADO: el motor simulado no está activo.\n";
  echo "Con el motor real esto llamaría al proveedor una vez por turno, y se paga.\n";
  echo "Enciéndelo en web/sites/default/settings.local.php y vuelve a intentarlo.\n";
  return;
}

$accion = (string) ($extra[0] ?? 'ayuda');

if ($accion === 'preparar') {
  simulacro_preparar(
    max(1, (int) ($extra[1] ?? 200)),
    (int) ($extra[2] ?? 25),
    (string) ($extra[3] ?? 'prospecting_diagnostic'),
  );

  return;
}

if ($accion === 'verificar') {
  $ids = array_values(array_filter(array_map(
    'intval',
    explode(',', (string) ($extra[1] ?? '')),
  )));

  if ($ids === []) {
    echo "Hacen falta los ids que imprimió «preparar».\n";

    return;
  }

  simulacro_verificar($ids);

  return;
}

echo "Uso: preparar [cuantos] [uid] [agente] · verificar <ids separados por comas>\n";
echo "El detalle está en la cabecera de este archivo.\n";
