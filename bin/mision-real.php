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
 *   ddev drush php:script bin/mision-real.php -- regresion 3
 * @endcode
 *
 * `regresion` es distinto de los otros: conduce de un tirón EL CASO QUE SE
 * PERDIÓ contra ChatGPT el 02-10-2026 —«Haz el trabajo por mí esta semana» y
 * luego Deloitte Ecuador— y dice PASA o NO PASA por cada cosa que falló ese día.
 * Va antes de desplegar cualquier cambio que toque al agente de prospección,
 * su contrato o lo que rodea al turno. Lo que mide —si investiga, si busca a
 * los compradores, si criba diez cuentas— solo existe hablando con el modelo, y
 * ninguna prueba unitaria lo ve.
 *
 * Comprobado que sirve, rompiéndolo a propósito el 02-10-2026: con el agente
 * racionado a 4 búsquedas —lo que pasó esa mañana— caen cuatro puertas (0
 * cuentas, 5 denegadas, 0 búsquedas a ejecutivos, 0 fuentes). Gasta unos 0,35-0,60
 * USD por misión y tarda dos o tres minutos; con 3 misiones basta para ver si un
 * fallo es variación o es sistemático.
 *
 * Se conversa turno a turno y no de un tirón A PROPÓSITO: el agente pregunta
 * antes de investigar —el territorio, la empresa— y sus respuestas deciden lo
 * que busca después. Un guion cerrado mediría otra cosa.
 */

declare(strict_types=1);

use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Conversation\MarkdownRenderer;
use Drupal\sales_leadership_diagnostic\Service\Research\CitationAudit;
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

if ($accion === 'regresion') {
  $veces = max(1, (int) ($extra[1] ?? 1));
  $alumno = sld_mision_alumno();
  $uid = (int) $alumno->id();
  $bd = \Drupal::database();
  $agente = \Drupal::entityTypeManager()->getStorage('sld_agent')->load('prospecting_diagnostic');
  $promptManager = \Drupal::service(DiagnosticPromptManager::class);
  $renderer = \Drupal::service(MarkdownRenderer::class);
  $auditoria = \Drupal::service(CitationAudit::class);

  // Los mismos dos mensajes que mandó José Raúl con la cuenta de Omar: el botón
  // de la bienvenida y la web con el territorio. Si el agente pide algo más, se
  // le dice que siga; necesitar ese empujón se anota, porque un alumno real
  // podría no darlo.
  $guion = [
    'Haz el trabajo por mí esta semana.',
    'https://www.deloitte.com/latam/es/about/story/nuestros-marketplaces/deloitte-ecuador.html Ecuador',
  ];
  $empujon = 'Adelante: entrega el Weekly GOLD Pack completo con lo que tengas.';

  // Una búsqueda «a un ejecutivo» nombra un cargo y no es de descubrimiento
  // general. Es una heurística —la misma con la que Jarvis contó en
  // producción— y no ve una búsqueda que solo lleve el nombre propio.
  $cargo = '/\b(CEO|CFO|CIO|CTO|COO|CDO|gerente|director|directora|presidente|presidenta|ejecutivo|vicepresidente|VP|jefe|fundador|country manager)\b|linkedin\.com\/in/iu';
  $generica = '/\b(empresas|nombramientos?|empleos?|ofertas?|vacantes?)\b|linkedin\.com\/jobs/iu';
  $prohibidas = '/l[ií]mite de investigaci[oó]n|presupuesto (de investigaci[oó]n )?(limitado|agotado)|dentro del l[ií]mite/iu';

  $resumen = [];

  for ($n = 1; $n <= $veces; $n++) {
    // La cuenta empieza cada misión como Omar en producción tras la limpieza:
    // sin misión esta semana, sin evidencia, sin memoria y sin historial de
    // cuentas. Sin esto, la segunda misión reutilizaría lo de la primera y
    // mediría otra cosa.
    $bd->delete('sld_research_entitlement')->condition('uid', $uid)->execute();
    $bd->delete('sld_evidence')->condition('uid', $uid)->execute();
    $bd->delete('sld_account_event')->condition('uid', $uid)->execute();
    $bd->delete('sld_account')->condition('uid', $uid)->execute();
    $memorias = \Drupal::entityTypeManager()->getStorage('sld_student_memory');
    $memorias->delete($memorias->loadByProperties(['uid' => $uid]));

    $prompt = $promptManager->composeFor($agente);
    $sesion = $almacen->create([
      'uid' => $uid,
      'wp_user_id' => '99001',
      'course_id' => $agente->getCourseId(),
      'agent' => 'prospecting_diagnostic',
      'diagnostic_version' => $agente->getVersion(),
      'prompt_snapshot' => $prompt,
      'prompt_hash' => hash('sha256', $prompt),
      'started_at' => \Drupal::time()->getRequestTime(),
    ]);
    $sesion->setStatus(DiagnosticStatus::InProgress);
    $sesion->save();
    $sid = (int) $sesion->id();
    $inicio = microtime(TRUE);

    $mensajes = $guion;
    $empujones = 0;

    for ($turno = 0; $turno < 4; $turno++) {
      $texto = $mensajes[$turno] ?? $empujon;

      if (!isset($mensajes[$turno])) {
        $empujones++;
      }

      $respuesta = $conversacion->submitMessage($sesion, $texto);

      if (!empty($respuesta['processing'])) {
        $cola = \Drupal::service('queue')->get('sld_diagnostic_turn');
        $trabajador = \Drupal::service('plugin.manager.queue_worker')->createInstance('sld_diagnostic_turn');

        while ($elemento = $cola->claimItem(3600)) {
          $trabajador->processItem($elemento->data);
          $cola->deleteItem($elemento);
        }
      }

      $almacen->resetCache([$sid]);
      $sesion = $almacen->load($sid);

      if ($sesion->getStatus() === DiagnosticStatus::Completed) {
        break;
      }
    }

    $completa = $sesion->getStatus() === DiagnosticStatus::Completed;
    $resultado = NULL;
    $ids = \Drupal::entityQuery('sld_diagnostic_result')->condition('session_id', $sid)->accessCheck(FALSE)->execute();

    if ($ids !== []) {
      $resultado = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_result')->load(reset($ids));
    }

    $payload = $resultado ? ($resultado->getPayload() ?? []) : [];
    $cuentas = $payload['accounts'] ?? [];
    $ultimo = $conversacion->getConversation($sid);
    $guardado = ($fin = end($ultimo)) ? $fin->content : '';

    $busquedas = $bd->query('SELECT query FROM {sld_tool_call} WHERE session_id = :s AND tool = :t AND allowed = 1', [':s' => $sid, ':t' => 'buscar_web'])->fetchCol();
    $denegadas = (int) $bd->query('SELECT COUNT(*) FROM {sld_tool_call} WHERE session_id = :s AND allowed = 0', [':s' => $sid])->fetchField();
    $aEjecutivos = count(array_filter($busquedas, fn ($q) => preg_match($cargo, $q) && !preg_match($generica, $q)));
    $nombrados = array_values(array_filter($cuentas, fn ($c) => !empty($c['buyer_verified'])));
    $fuentes = array_sum(array_map(fn ($c) => count($c['sources'] ?? []), $cuentas));
    $correos = count(array_filter($cuentas, fn ($c) => trim((string) ($c['outreach_message'] ?? '')) !== ''));
    $citas = substr_count($renderer->render($guardado), '<blockquote>');
    $revision = $auditoria->review($uid, $sid, $payload, $guardado);
    $usd = (float) $bd->query('SELECT COALESCE(SUM(cost_usd), 0) FROM {sld_ai_usage} WHERE session_id = :s', [':s' => $sid])->fetchField();

    $puertas = [
      'cierra con Pack en ≤4 turnos' => [$completa, $completa ? sprintf('%d turnos, %d empujón(es)', $turno + 1, $empujones) : 'no cerró'],
      'criba 10 cuentas' => [count($cuentas) >= 10 && (int) ($payload['pool_declared'] ?? 0) >= 10, sprintf('%d cuentas, pool declarado %d', count($cuentas), (int) ($payload['pool_declared'] ?? 0))],
      'no se queja de presupuesto' => [!preg_match($prohibidas, $guardado), preg_match($prohibidas, $guardado, $m) ? '«' . $m[0] . '»' : 'ninguna frase'],
      'investiga de verdad' => [count($busquedas) >= 8 && $denegadas === 0, sprintf('%d búsquedas, %d denegadas', count($busquedas), $denegadas)],
      'busca a los compradores' => [$aEjecutivos >= 1, sprintf('%d búsquedas a ejecutivos', $aEjecutivos)],
      'enlaza las fuentes' => [$fuentes >= 10 && str_contains($guardado, 'Fuentes por cuenta'), sprintf('%d fuentes, apéndice %s', $fuentes, str_contains($guardado, 'Fuentes por cuenta') ? 'sí' : 'NO')],
      'ninguna cita sin origen' => [!$revision[CitationAudit::SIN_REGISTRO] && $revision[CitationAudit::NO_VISTAS] === [], sprintf('%d respaldadas, %d otra página, %d no vistas', count($revision[CitationAudit::RESPALDADAS]), count($revision[CitationAudit::OTRA_PAGINA]), count($revision[CitationAudit::NO_VISTAS]))],
      'correos como cita' => [$citas >= $correos, sprintf('%d correos, %d citas en pantalla', $correos, $citas)],
    ];

    $pasa = !in_array(FALSE, array_column($puertas, 0), TRUE);
    $resumen[] = [$sid, $pasa, $usd, microtime(TRUE) - $inicio, count($nombrados)];

    printf("\n═══ MISIÓN %d de %d · sesión %d · %s · $%.3f · %.0fs\n", $n, $veces, $sid, $pasa ? 'PASA TODO' : 'NO PASA', $usd, microtime(TRUE) - $inicio);

    foreach ($puertas as $nombre => [$ok, $detalle]) {
      printf("  %s  %-28s %s\n", $ok ? 'PASA   ' : 'NO PASA', $nombre, $detalle);
    }

    // Los compradores CON NOMBRE no son puerta por misión, a propósito. La
    // metodología del cliente solo deja nombrar a alguien con empleo, cargo,
    // alcance y recencia confirmados, y el modelo aplica ese listón con
    // criterio variable: el 02-10-2026, de cuatro misiones que buscaron a las
    // personas, tres nombraron a dos o tres y una a nadie, citando incluso la
    // fuente que lo identificaba. Eso es variación, no regresión. La regresión
    // de aquel día tenía otra firma —cero búsquedas a personas— y esa sí es
    // puerta dura. El resumen falla si NINGUNA misión de la tanda nombra a
    // alguien, que ya sería sistemático.
    printf("  %s  %-28s %s\n", count($nombrados) >= 1 ? 'PASA   ' : 'AVISO  ', 'nombra compradores', count($nombrados) . ': ' . implode(', ', array_map(fn ($c) => ($c['buyer'] ?? '?') . ' (' . ($c['name'] ?? '?') . ')', $nombrados)));

    foreach ($revision[CitationAudit::OTRA_PAGINA] as $url) {
      printf("     · otra página: %s\n", $url);
    }
  }

  $conNombre = count(array_filter(array_column($resumen, 4)));
  printf("\n  %s  compradores con nombre en %d de %d misiones%s\n", $conNombre >= 1 ? 'PASA   ' : 'NO PASA', $conNombre, $veces, $conNombre === 0 ? ' — en ninguna: eso ya no es variación' : '');

  printf("\n═══ RESUMEN: %d de %d misiones pasan todas las puertas%s · $%.3f en total\n", count(array_filter(array_column($resumen, 1))), $veces, $conNombre === 0 ? ', y NINGUNA nombra compradores' : '', array_sum(array_column($resumen, 2)));

  return;
}

print <<<AYUDA
Conduce una misión real contra el agente y la mide. GASTA DINERO.

  arrancar [agente]   crea una sesión nueva y devuelve la semana de investigación
  decir <sesión> <…>  manda un mensaje, drena la cola y mide el turno
  leer <sesión>       vuelca la conversación entera
  medir <sesión>      el resumen de la misión: búsquedas, tokens, caché y coste
  regresion [veces]   el caso que se perdió contra ChatGPT, con PASA o NO PASA

El agente pregunta antes de investigar, así que se conversa turno a turno.

AYUDA;
