<?php

/**
 * @file
 * Conduce una misión de verdad contra el agente, y la mide.
 *
 * Existe porque las tres redes habituales —PHPUnit, phpcs y la prueba de humo—
 * no ven la clase de fallo que solo aparece hablando con el modelo real, y
 * porque los números que se le dan al cliente tienen que salir de una misión
 * medida y no de una proyección. Su §9 lo pide con estas palabras: los topes se
 * calibran por benchmark, no por decreto.
 *
 * Cuanto se sabe hoy de lo que cuesta una misión salió de aquí. Está recogido
 * en `docs/decisiones/0012-topes-para-discovery.md`.
 *
 * GASTA DINERO DE VERDAD. Cada turno es una llamada al proveedor y, si el
 * agente investiga, varias búsquedas. Antes de usarlo conviene mirar el tope
 * global en la pantalla de consumo.
 *
 * Uso:
 * @code
 *   ddev drush php:script bin/mision-real.php -- arrancar
 *   ddev drush php:script bin/mision-real.php -- decir 95 "Soy director comercial de…"
 *   ddev drush php:script bin/mision-real.php -- leer 95
 *   ddev drush php:script bin/mision-real.php -- medir 95
 * @endcode
 *
 * Se conversa turno a turno y no de un tirón A PROPÓSITO: el agente pregunta
 * antes de investigar —el territorio, la empresa— y sus respuestas deciden lo
 * que busca después. Un guion cerrado mediría otra cosa.
 */

declare(strict_types=1);

use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticPromptManager;
use Drupal\user\Entity\User;

/**
 * La cuenta con la que se ensaya.
 *
 * Una cuenta aparte y no la de nadie real: la misión de investigación es una
 * por persona y semana, así que medir con la cuenta de alguien le gastaría la
 * suya. Lleva zona horaria propia porque el periodo del entitlement se calcula
 * en la de cada quien.
 */
function sld_mision_alumno(): User {
  $existentes = \Drupal::entityTypeManager()->getStorage('user')
    ->loadByProperties(['name' => 'discovery_prueba']);

  if ($existentes !== []) {
    return reset($existentes);
  }

  $cuenta = User::create([
    'name' => 'discovery_prueba',
    'status' => 1,
    'timezone' => 'America/Mexico_City',
  ]);
  $cuenta->save();

  return $cuenta;
}

/**
 * Lo gastado hasta ahora, para poder restar después.
 *
 * @return array{llamadas: int, busquedas: int, caracteres: int, usd: float}
 *   Contadores acumulados de esa persona.
 */
function sld_mision_contadores(int $uid): array {
  $bd = \Drupal::database();

  $herramientas = $bd->select('sld_tool_call', 't')
    ->condition('uid', $uid)
    ->condition('allowed', 1);
  $herramientas->addExpression('COUNT(*)', 'n');
  $herramientas->addExpression('COALESCE(SUM(retrieved_chars), 0)', 'chars');
  $fila = $herramientas->execute()->fetchAssoc() ?: [];

  return [
    'llamadas' => (int) $bd->select('sld_ai_usage', 'u')->condition('uid', $uid)->countQuery()->execute()->fetchField(),
    'busquedas' => (int) ($fila['n'] ?? 0),
    'caracteres' => (int) ($fila['chars'] ?? 0),
    'usd' => (float) $bd->query('SELECT COALESCE(SUM(cost_usd), 0) FROM {sld_ai_usage} WHERE uid = :u', [':u' => $uid])->fetchField(),
  ];
}

$accion = $extra[0] ?? 'ayuda';
$conversacion = \Drupal::service(ConversationService::class);
$almacen = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session');

if ($accion === 'arrancar') {
  $agenteId = $extra[1] ?? 'prospecting_diagnostic';
  $alumno = sld_mision_alumno();
  $uid = (int) $alumno->id();

  // Se le devuelve la semana entera. Sin esto solo se podría medir una misión
  // cada siete días, que es correcto en producción e inservible para medir.
  \Drupal::database()->delete('sld_research_entitlement')->condition('uid', $uid)->execute();

  $agente = \Drupal::entityTypeManager()->getStorage('sld_agent')->load($agenteId);

  if ($agente === NULL) {
    printf("No existe el agente «%s».\n", $agenteId);
    return;
  }

  // El prompt se compone igual que en producción, con sus documentos. Copiarlo
  // a la sesión es lo que permite saber después con qué se conversó (§57).
  $prompt = \Drupal::service(DiagnosticPromptManager::class)->composeFor($agente);

  $sesion = $almacen->create([
    'uid' => $uid,
    'wp_user_id' => '99001',
    'course_id' => $agente->getCourseId(),
    'agent' => $agenteId,
    'diagnostic_version' => $agente->getVersion(),
    'prompt_snapshot' => $prompt,
    'prompt_hash' => hash('sha256', $prompt),
    'started_at' => \Drupal::time()->getRequestTime(),
  ]);
  $sesion->setStatus(DiagnosticStatus::InProgress);
  $sesion->save();

  printf(
    "sesión %d · alumno %d · agente %s · puede buscar: %s · prompt de %s caracteres\n",
    $sesion->id(),
    $uid,
    $agenteId,
    $agente->canSearch() ? 'sí' : 'NO',
    number_format(strlen($prompt)),
  );

  return;
}

if ($accion === 'decir') {
  $sid = (int) ($extra[1] ?? 0);
  $texto = (string) ($extra[2] ?? '');
  $sesion = $almacen->load($sid);

  if ($sesion === NULL || $texto === '') {
    print "Hace falta una sesión existente y un mensaje.\n";
    return;
  }

  $uid = (int) $sesion->getOwnerId();
  $antes = sld_mision_contadores($uid);

  $inicio = microtime(TRUE);
  $respuesta = $conversacion->submitMessage($sesion, $texto);
  $enLaPeticion = microtime(TRUE) - $inicio;

  if (!empty($respuesta['processing'])) {
    // Se drena la cola a mano, que es lo que hace el cron cada minuto en el
    // servidor. Aquí se hace en el acto para no esperar por él.
    $cola = \Drupal::service('queue')->get('sld_diagnostic_turn');
    $trabajador = \Drupal::service('plugin.manager.queue_worker')->createInstance('sld_diagnostic_turn');

    while ($elemento = $cola->claimItem(3600)) {
      $trabajador->processItem($elemento->data);
      $cola->deleteItem($elemento);
    }
  }

  $total = microtime(TRUE) - $inicio;
  $despues = sld_mision_contadores($uid);

  $almacen->resetCache([$sid]);
  $sesion = $almacen->load($sid);
  $mensajes = $conversacion->getConversation($sid);
  $ultimo = end($mensajes);

  printf(
    "\n─── TURNO ─── espera del alumno %.2fs · trabajo total %.1fs · %d búsquedas (+%s caracteres) · $%.4f · sesión %s\n\n",
    $enLaPeticion,
    $total,
    $despues['busquedas'] - $antes['busquedas'],
    number_format($despues['caracteres'] - $antes['caracteres']),
    $despues['usd'] - $antes['usd'],
    $sesion->getStatus()->value,
  );

  print $ultimo === FALSE ? "(sin respuesta)\n" : $ultimo->content . "\n";

  return;
}

if ($accion === 'leer') {
  foreach ($conversacion->getConversation((int) ($extra[1] ?? 0)) as $mensaje) {
    printf("\n### %s\n%s\n", strtoupper($mensaje->role->value), $mensaje->content);
  }

  return;
}

if ($accion === 'medir') {
  $sid = (int) ($extra[1] ?? 0);
  $sesion = $almacen->load($sid);

  if ($sesion === NULL) {
    print "Esa sesión no existe.\n";
    return;
  }

  $bd = \Drupal::database();
  $uid = (int) $sesion->getOwnerId();

  $u = $bd->query('SELECT COUNT(*) n, SUM(input_tokens) it, SUM(cached_input_tokens) ct, SUM(output_tokens) ot, SUM(reasoning_tokens) rt, SUM(cost_usd) usd, SUM(latency_ms) ms FROM {sld_ai_usage} WHERE session_id = :s', [':s' => $sid])->fetchObject();
  // Filtrado por herramienta A PROPÓSITO. Sin el filtro, las anotaciones del
  // ledger se cuentan como búsquedas y el número sale inflado: en la primera
  // misión medida, 32 en vez de 27. Es un número que acaba en un documento
  // para el cliente, así que tiene que contar lo que dice que cuenta.
  $busca = [':s' => $sid, ':t' => 'buscar_web'];
  $h = $bd->query('SELECT COUNT(*) n, COALESCE(SUM(retrieved_chars), 0) chars, COALESCE(SUM(latency_ms), 0) ms FROM {sld_tool_call} WHERE session_id = :s AND allowed = 1 AND tool = :t', $busca)->fetchObject();
  $l = (int) $bd->query('SELECT COUNT(*) FROM {sld_tool_call} WHERE session_id = :s AND allowed = 1 AND tool <> :t', $busca)->fetchField();
  $d = $bd->query('SELECT denial_reason r, COUNT(*) n FROM {sld_tool_call} WHERE session_id = :s AND allowed = 0 GROUP BY denial_reason', [':s' => $sid]);

  printf("MISIÓN de la sesión %d (agente %s)\n\n", $sid, $sesion->getAgentId());
  printf("  llamadas al modelo   %d\n", (int) $u->n);
  printf("  entrada              %s tokens, %d %% reutilizada por la caché\n", number_format((int) $u->it), $u->it ? round(100 * $u->ct / $u->it) : 0);
  printf("  salida               %s tokens (%s de razonamiento)\n", number_format((int) $u->ot), number_format((int) $u->rt));
  printf("  búsquedas            %d, con %s caracteres traídos\n", (int) $h->n, number_format((int) $h->chars));
  printf("  uso del ledger       %d consultas y anotaciones\n", $l);
  printf("  tiempo               %.0fs de modelo, %.0fs de búsqueda\n", $u->ms / 1000, $h->ms / 1000);
  printf("  COSTE                $%.4f USD\n", (float) $u->usd);

  foreach ($d as $fila) {
    printf("  denegada             %s · %d\n", $fila->r, $fila->n);
  }

  $e = $bd->select('sld_research_entitlement', 'e')->fields('e')->condition('uid', $uid)->execute()->fetchAssoc();
  printf("\n  entitlement          %s (periodo %s)\n", $e['mission_state'] ?? '—', $e['period'] ?? '—');
  printf("  evidencia guardada   %d afirmaciones de esta persona\n", (int) $bd->select('sld_evidence', 'v')->condition('uid', $uid)->countQuery()->execute()->fetchField());

  return;
}

print <<<AYUDA
Conduce una misión real contra el agente y la mide. GASTA DINERO.

  arrancar [agente]   crea una sesión nueva y devuelve la semana de investigación
  decir <sesión> <…>  manda un mensaje, drena la cola y mide el turno
  leer <sesión>       vuelca la conversación entera
  medir <sesión>      el resumen de la misión: búsquedas, tokens, caché y coste

El agente pregunta antes de investigar, así que se conversa turno a turno.

AYUDA;
