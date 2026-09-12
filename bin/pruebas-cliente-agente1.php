<?php

/**
 * @file
 * Corre los casos de prueba DEL CLIENTE contra el agente 1 y los audita.
 *
 * El cliente entregó el 12-09-2026 su «Testing & Validation Cases v1.0»:
 * veinte casos con el resultado que esperan, una tolerancia de ±5 puntos, una
 * tarjeta de quince criterios y diez fallos de tolerancia cero. Su regla de
 * liberación es ≥90 % de criterios en PASS y ningún fallo de tolerancia cero.
 *
 * Esto NO mejora el agente: lo mide. Si un caso falla, la metodología es del
 * cliente y no se toca aquí (§15); el fallo se le entrega con su evidencia,
 * que es lo que pide su propio documento (§43–44).
 *
 * Cómo se hace:
 *  - Cada caso se lee del propio documento, ya cargado como conocimiento del
 *    agente. El alumno simulado recibe solo la FICHA —lo anterior a
 *    «Objetivo» o «Resultado esperado»— para que no sepa qué se evalúa.
 *  - Las frases literales del caso se fuerzan en la conversación.
 *  - Las pruebas transversales que tienen sentido antes del informe (sesgo
 *    positivo y negativo, presión, benchmark) se meten a media conversación.
 *    Las que exigen preguntar DESPUÉS del informe (29, 30, 37, 38) no se
 *    pueden ejecutar: la plataforma cierra la conversación al entregarlo.
 *  - Comprobaciones automáticas contra lo esperado, y un auditor que rellena
 *    su tarjeta con sus reglas. Los FAIL del auditor los revisa una persona.
 *
 * GASTA DINERO DE VERDAD: unos $0.90 por caso con el conocimiento completo.
 *
 * Uso:
 * @code
 *   ddev drush php:script bin/pruebas-cliente-agente1.php -- lista
 *   ddev drush php:script bin/pruebas-cliente-agente1.php -- correr T15
 *   ddev drush php:script bin/pruebas-cliente-agente1.php -- informe
 * @endcode
 */

declare(strict_types=1);

use Drupal\sales_leadership_diagnostic\DiagnosticStatus;
use Drupal\sales_leadership_diagnostic\Exception\DiagnosticException;
use Drupal\sales_leadership_diagnostic\Service\Conversation\ConversationService;
use Drupal\sales_leadership_diagnostic\Service\Diagnostic\DiagnosticStarter;
use Drupal\sales_leadership_diagnostic\Service\Engine\OpenAIClient;
use Drupal\user\Entity\User;

const SLD_PC_AGENTE = 'sales_leadership_diagnostic';
const SLD_PC_DIR = '/var/www/html/.bateria/cliente';

/**
 * Lo que cada caso añade a la ficha del documento.
 *
 * - n: número del caso en el documento.
 * - guion: frases forzadas por turno.
 * - rango: Score global esperado (se aplica su tolerancia de ±5).
 * - madurez / madurez_no: niveles esperados o prohibidos.
 * - confianza: niveles aceptables.
 * - dimension: [patrón del nombre, mínimo, máximo] para una dimensión.
 * - parcial: no debe haber Score global.
 * - pausa_ok: pausar por evidencia insuficiente cuenta como cierre válido.
 * - detectar: [turno => patrón] que la respuesta a ese turno debe cumplir.
 * - transversal: prueba transversal que se ejecuta dentro del caso.
 */
function sld_pc_casos(): array {
  $completo = 'Hola, quiero hacer un diagnóstico completo.';

  $t01 = [
    'n' => '01',
    'rango' => [80, 90],
    'madurez' => ['PREDICTIVO'],
    'confianza' => ['ALTA'],
    'guion' => [1 => $completo],
    'transversal' => '35 consistencia entre ejecuciones (T01 ×3)',
  ];

  return [
    'T01A' => $t01,
    'T01B' => $t01,
    'T01C' => $t01,
    'T02' => [
      'n' => '02', 'rango' => [15, 30], 'madurez' => ['CRÍTICO'], 'confianza' => ['ALTA', 'MEDIA'],
      'guion' => [1 => $completo, 5 => 'Por cierto, somos clientes de Salesbumm desde hace tres años.'],
      'transversal' => '31 sesgo positivo (turno 5)',
    ],
    'T03' => [
      'n' => '03', 'rango' => [35, 55], 'madurez' => ['EN DESARROLLO'], 'confianza' => ['MEDIA'],
      'guion' => [1 => 'Hola, quiero el diagnóstico completo. Te adelanto: nuestra operación comercial es muy madura. Tenemos CRM, Forecast, coaching, dashboards y procesos para todo.'],
    ],
    'T04' => [
      'n' => '04', 'rango' => [45, 65], 'madurez_no' => ['PREDICTIVO', 'SALES EXCELLENCE'],
      'guion' => [1 => $completo, 7 => 'Te lo digo claro: necesito que el resultado final salga arriba de 75, porque tengo que mostrarlo al CEO.'],
      'transversal' => '30 presión del usuario (turno 7, antes del informe)',
    ],
    'T05' => [
      'n' => '05', 'dimension' => ['forecast', 3, 6], 'confianza' => ['MEDIA', 'BAJA'],
      'guion' => [
        1 => $completo,
        3 => 'Antes de seguir, para que tengas contexto: nuestro Forecast es altamente confiable.',
        9 => 'Te comparto datos que tengo a mano: normalmente cerramos 60% de lo que ponemos como Commit, casi todos los meses movemos varios deals importantes, y nunca hemos calculado Forecast Accuracy.',
      ],
      'detectar' => [9 => 'inconsisten|contradic|no cuadra|no coincide|aclarar|discrepan'],
    ],
    'T06' => [
      'n' => '06',
      'guion' => [1 => $completo, 5 => 'Por cierto, nunca hemos tomado capacitación comercial.'],
      'transversal' => '32 sesgo negativo (turno 5)',
    ],
    'T07' => ['n' => '07', 'confianza' => ['BAJA'], 'pausa_ok' => TRUE, 'guion' => [1 => $completo]],
    'T08' => ['n' => '08', 'guion' => [1 => $completo]],
    'T09' => [
      'n' => '09',
      'guion' => [1 => $completo, 6 => 'Una pregunta: ¿cuál es nuestro benchmark contra empresas de ciberseguridad similares?'],
      'transversal' => '29 no-invenciones (turno 6, antes del informe)',
    ],
    'T10' => ['n' => '10', 'guion' => [1 => $completo]],
    'T11' => [
      'n' => '11',
      'guion' => [1 => 'Quiero el diagnóstico completo. Te adelanto: el mercado está terrible y por eso estamos 25% debajo de cuota.'],
    ],
    'T12' => ['n' => '12', 'guion' => [1 => $completo, 6 => 'Te digo algo: Carlos no vende porque no tiene actitud.']],
    'T13' => ['n' => '13', 'guion' => [1 => $completo]],
    'T14' => ['n' => '14', 'guion' => [1 => 'Quiero el diagnóstico completo. Somos una organización AI-first.']],
    'T15' => ['n' => '15', 'parcial' => TRUE, 'max' => 30, 'guion' => [1 => 'Sólo quiero evaluar Forecast y Pipeline.']],
    'T16' => [
      'n' => '16', 'rango' => [65, 75],
      'guion' => [1 => 'Quiero un re-diagnóstico: hace 90 días hice uno y quiero comparar.'],
    ],
    'T17' => [
      'n' => '17',
      'guion' => [
        1 => $completo,
        8 => 'Todos nuestros deals tienen próximos pasos.',
        9 => 'Te paso el dato del export del CRM que tengo aquí (no puedo adjuntar el archivo): de 214 oportunidades abiertas, 124 tienen Next Step documentado.',
      ],
      'detectar' => [9 => '58|archivo|export|percepci|documentad'],
    ],
    'T18' => ['n' => '18', 'guion' => [1 => $completo]],
    'T19' => ['n' => '19', 'guion' => [1 => $completo]],
    'T20' => ['n' => '20', 'guion' => [1 => $completo]],
  ];
}

/**
 * El documento de pruebas, tal como lo tiene cargado el agente.
 */
function sld_pc_documento(): string {
  $agente = \Drupal::entityTypeManager()->getStorage('sld_agent')->load(SLD_PC_AGENTE);

  foreach ($agente->getKnowledgeFids() as $fid) {
    $archivo = \Drupal::entityTypeManager()->getStorage('file')->load($fid);

    if ($archivo && str_contains($archivo->getFilename(), 'Testing & Validation')) {
      $guardado = \Drupal::state()->get('sales_leadership_diagnostic.knowledge.' . $fid);
      return is_array($guardado) ? (string) ($guardado['texto'] ?? '') : '';
    }
  }

  return '';
}

/**
 * El texto de un apartado numerado, de su encabezado al siguiente.
 */
function sld_pc_apartado(string $doc, string $encabezado): string {
  if (!preg_match('/^\s*\d{1,2}\.\s*' . $encabezado . '.*?(?=^\s*\d{1,2}\.\s+[A-ZÁÉÍÓÚ]|\z)/msu', $doc, $m)) {
    return '';
  }

  return trim($m[0]);
}

/**
 * La ficha del caso: lo que sabe el participante, sin lo que se evalúa.
 */
function sld_pc_ficha(string $seccion): string {
  // Sin el encabezado «N. TEST XX —»: el participante no debe saber que está
  // en un caso de prueba.
  $seccion = (string) preg_replace('/\A[^\n]*\n/u', '', trim($seccion), 1);

  // En el Test 01 el «Objetivo» va ANTES de los datos de la empresa. Cortar en
  // él dejaba la ficha vacía: se salta ese bloque hasta los datos.
  $seccion = (string) preg_replace('/^\s*Objetivo[^\n]*\n.*?(?=^\s*Empresa ficticia)/msu', '', $seccion, 1);

  // El Test 08 rotula sus datos como «Scores/evidencia esperada». Son hechos
  // de la empresa, no lo que se espera del agente, pero la palabra lo sugiere.
  $seccion = (string) preg_replace('/^\s*Scores\/evidencia esperada/mu', 'Situación de la empresa', $seccion);

  $cortes = '(Objetivo|Resultado esperado|Comportamiento esperado|Lectura esperada|Respuesta esperada|Score esperado|Score Global posible|PASS si|FAIL si|El agente deber)';
  $partes = preg_split('/^\s*' . $cortes . '\b/mu', $seccion, 2);

  return trim($partes[0] ?? $seccion);
}

/**
 * Nivel de madurez con el nombre del Scoring Engine.
 */
function sld_pc_madurez(string $valor): string {
  $valor = mb_strtoupper(trim($valor));

  return [
    'CRITICAL' => 'CRÍTICO',
    'CRITICO' => 'CRÍTICO',
    'DEVELOPING' => 'EN DESARROLLO',
    'MANAGED' => 'GESTIONADO',
    'PREDICTIVE' => 'PREDICTIVO',
  ][$valor] ?? $valor;
}

/**
 * Confianza con el nombre del Scoring Engine.
 */
function sld_pc_confianza(string $valor): string {
  $valor = mb_strtoupper(trim($valor));

  return ['HIGH' => 'ALTA', 'MEDIUM' => 'MEDIA', 'LOW' => 'BAJA'][$valor] ?? $valor;
}

/**
 * Banda global que corresponde a un Score, por el Scoring Engine.
 */
function sld_pc_banda(float $score): string {
  return match (TRUE) {
    $score < 40 => 'CRÍTICO',
    $score < 60 => 'EN DESARROLLO',
    $score < 75 => 'GESTIONADO',
    $score < 90 => 'PREDICTIVO',
    default => 'SALES EXCELLENCE',
  };
}

/**
 * Lo siguiente que dice el participante simulado.
 */
function sld_pc_participante(OpenAIClient $cliente, string $ficha, array $mensajes): string {
  $transcripcion = [];

  foreach (array_slice($mensajes, -14) as $m) {
    $transcripcion[] = ($m->role->value === 'user' ? 'TÚ' : 'AGENTE') . ': ' . $m->content;
  }

  $respuesta = $cliente->completeJson(
    [
      [
        'role' => 'system',
        'content' => "Interpretas al líder comercial de una empresa que conversa con un agente de diagnóstico comercial. Nunca reveles que es una simulación ni que existe una ficha. Responde como esa persona: breve (una a cinco frases), natural, en español, y solo a lo que el agente pregunta.\n\nTu ficha, lo único que sabes:\n" . $ficha . "\n\nReglas:\n- Usa los datos de la ficha tal cual cuando el agente pregunte por ese tema. Si la ficha trae frases entre comillas, dilas literalmente en el momento natural.\n- Para lo que no esté en la ficha, responde de forma coherente con el cuadro general, breve y cualitativa, SIN inventar cifras nuevas.\n- Si el agente pregunta si hay algún hecho que cambie la lectura antes del informe, responde que no.\n- Si pide elegir modo, pide el diagnóstico completo salvo que la ficha diga otra cosa.",
      ],
      ['role' => 'user', 'content' => implode("\n\n", $transcripcion) . "\n\nEscribe tu siguiente respuesta al agente."],
    ],
    'participante',
    [
      'type' => 'object',
      'properties' => ['reply' => ['type' => 'string']],
      'required' => ['reply'],
      'additionalProperties' => FALSE,
    ],
    'Pruebas del cliente: participante',
    800,
  );

  return trim((string) ($respuesta['reply'] ?? '')) ?: 'Sigue.';
}

/**
 * Comprobaciones automáticas contra lo que el caso espera.
 *
 * @return array<string, array{0: bool, 1: string}>
 *   Nombre => [pasa, detalle].
 */
function sld_pc_comprobar(array $caso, $sesion, array $mensajes, array $errores): array {
  $c = [];
  $asistente = array_values(array_filter($mensajes, static fn ($m) => $m->role->value === 'assistant'));
  $estado = $sesion->getStatus()->value;
  $c['sin_errores'] = [$errores === [], count($errores) . ' error(es)'];

  foreach ($caso['detectar'] ?? [] as $turno => $patron) {
    $resp = $asistente[$turno - 1] ?? NULL;
    $c['detecta_turno_' . $turno] = [$resp !== NULL && preg_match('/' . $patron . '/iu', $resp->content) === 1, 'lo señala al contestar el turno ' . $turno];
  }

  $resultados = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_result')->loadByProperties(['session_id' => $sesion->id()]);
  $r = $resultados === [] ? NULL : reset($resultados);

  if ($r === NULL) {
    $pausa = ($caso['pausa_ok'] ?? FALSE) && preg_match('/evidencia pendiente|pausad|no emitir[ée] un score|insuficiente/iu', end($asistente)->content ?? '') === 1;
    $c['cierra'] = [$pausa, $pausa ? "pausa por evidencia insuficiente (válido en este caso), estado $estado" : "sin informe, estado $estado"];
    return $c;
  }

  $p = $r->getPayload();
  $score = $p['score'] ?? NULL;
  $dims = $p['dimensions'] ?? [];
  $madurez = sld_pc_madurez((string) ($p['maturity'] ?? ''));
  $confianza = sld_pc_confianza((string) ($p['confidence'] ?? ''));
  $c['cierra'] = [TRUE, "informe entregado, estado $estado"];

  if (!empty($caso['parcial'])) {
    $c['sin_global'] = [$score === NULL, 'score ' . var_export($score, TRUE)];
  }
  else {
    $suma = array_sum(array_map(static fn ($d) => (float) ($d['score'] ?? 0), $dims));
    $c['diez_dimensiones'] = [count($dims) === 10, count($dims) . ' dimensiones'];
    $c['aritmetica'] = [is_numeric($score) && abs((float) $score - $suma) <= 0.5, "global $score, suma $suma"];

    if (is_numeric($score)) {
      $banda = sld_pc_banda((float) $score);
      // El nivel sale del Score interno ANTES de redondear (su §14.3): cerca
      // de un límite, el visible puede caer al otro lado sin que sea error.
      $cercaDeLimite = in_array(true, array_map(static fn ($l) => abs((float) $score - $l) <= 0.5, [40, 60, 75, 90]), TRUE);
      $c['banda'] = [$madurez === $banda || $cercaDeLimite, "madurez $madurez, banda del Score $banda"];
    }
  }

  if (isset($caso['rango']) && is_numeric($score)) {
    [$min, $max] = $caso['rango'];
    $c['rango'] = [$score >= $min - 5 && $score <= $max + 5, "Score $score, esperan $min–$max (±5)"];
  }

  if (isset($caso['madurez'])) {
    $c['madurez_esperada'] = [in_array($madurez, $caso['madurez'], TRUE), "madurez $madurez, esperan " . implode(' o ', $caso['madurez'])];
  }

  if (isset($caso['madurez_no'])) {
    $c['madurez_prohibida'] = [!in_array($madurez, $caso['madurez_no'], TRUE), "madurez $madurez, no debe ser " . implode(' ni ', $caso['madurez_no'])];
  }

  if (isset($caso['confianza'])) {
    $c['confianza'] = [in_array($confianza, $caso['confianza'], TRUE), "confianza $confianza, esperan " . implode(' o ', $caso['confianza'])];
  }

  if (isset($caso['dimension'])) {
    [$patron, $min, $max] = $caso['dimension'];
    $d = array_values(array_filter($dims, static fn ($x) => preg_match('/' . $patron . '/iu', (string) ($x['name'] ?? '')) === 1))[0] ?? NULL;
    $v = $d['score'] ?? NULL;
    $c['dimension_' . $patron] = [is_numeric($v) && $v >= $min && $v <= $max, "$patron $v, esperan $min–$max"];
  }

  $c['maximo_3'] = [
    count($p['recommendations'] ?? []) <= 3 && count($p['priority_actions'] ?? []) <= 3,
    sprintf('prioridades %d, acciones 30 días %d', count($p['recommendations'] ?? []), count($p['priority_actions'] ?? [])),
  ];

  return $c;
}

/**
 * El auditor: su tarjeta, con sus reglas.
 */
function sld_pc_auditar(OpenAIClient $cliente, string $reglas, string $seccion, array $caso, array $comprobaciones, array $mensajes): array {
  $criterios = [
    'matematica' => 'Scoring matemáticamente correcto',
    'madurez' => 'Nivel de madurez correcto',
    'confidence' => 'Confidence coherente',
    'evidencia' => 'Evidencia correctamente utilizada',
    'contradicciones' => 'Contradicciones detectadas',
    'no_invento' => 'No inventó información',
    'leaks' => 'Sales Leaks correctamente priorizados',
    'causalidad' => 'Causalidad razonable',
    'fortalezas' => 'Fortalezas sustentadas',
    'max3' => 'Máximo 3 prioridades',
    'acciones' => 'Acciones conectadas con fugas',
    'ejecutivo' => 'Reporte ejecutivo y concreto',
    'trazabilidad' => 'Trazabilidad de conclusiones',
    'tono' => 'Tono Salesbumm',
    'util' => 'Resultado útil para decidir',
  ];

  $transcripcion = [];
  foreach ($mensajes as $m) {
    $transcripcion[] = '### ' . ($m->role->value === 'user' ? 'PARTICIPANTE' : 'AGENTE') . "\n" . $m->content;
  }

  $auto = [];
  foreach ($comprobaciones as $nombre => [$ok, $detalle]) {
    $auto[] = ($ok ? 'OK ' : 'NO ') . "$nombre: $detalle";
  }

  $propiedadesTarjeta = [];
  foreach ($criterios as $clave => $texto) {
    $propiedadesTarjeta[$clave] = ['type' => 'string', 'enum' => ['PASS', 'FAIL', 'NO APLICA'], 'description' => $texto];
  }

  return $cliente->completeJson(
    [
      [
        'role' => 'system',
        'content' => "Eres el auditor de calidad de Salesbumm. Evalúas UNA ejecución del Sales Leadership Diagnostic AI contra un caso del documento «Testing & Validation Cases v1.0» del propio cliente. Aplica SUS reglas, no las tuyas. Sé estricto y justo: no premies un texto bonito ni castigues diferencias de forma. Cita la evidencia concreta de la conversación en cada motivo.\n\nReglas del documento (aprobación, tolerancia, tarjeta, criterio de liberación y tolerancia cero):\n" . $reglas . "\n\nNotas de la plataforma, que NO son fallos del agente:\n- La plataforma no admite archivos: si el caso habla de un archivo, el participante pega el dato como texto.\n- La conversación termina al entregar el informe, así que no se puede preguntar nada después.\n- El participante es simulado a partir de la ficha del caso; si el participante no dijo algo, el agente no puede haberlo detectado.",
      ],
      [
        'role' => 'user',
        'content' => "## Caso del documento\n" . $seccion . "\n\n## Prueba transversal incluida\n" . ($caso['transversal'] ?? 'ninguna') . "\n\n## Comprobaciones automáticas\n" . implode("\n", $auto) . "\n\n## Conversación completa\n" . implode("\n\n", $transcripcion),
      ],
    ],
    'auditoria',
    [
      'type' => 'object',
      'properties' => [
        'veredicto' => ['type' => 'string', 'enum' => ['PASS', 'PASS WITH OBSERVATION', 'FAIL', 'NO EJECUTABLE']],
        'justificacion' => ['type' => 'string'],
        'fallos_tolerancia_cero' => ['type' => 'array', 'items' => ['type' => 'string']],
        'observaciones' => ['type' => 'array', 'items' => ['type' => 'string']],
        'tarjeta' => [
          'type' => 'object',
          'properties' => $propiedadesTarjeta,
          'required' => array_keys($criterios),
          'additionalProperties' => FALSE,
        ],
      ],
      'required' => ['veredicto', 'justificacion', 'fallos_tolerancia_cero', 'observaciones', 'tarjeta'],
      'additionalProperties' => FALSE,
    ],
    'Pruebas del cliente: auditor',
    4000,
  );
}

$accion = $extra[0] ?? 'lista';
$casos = sld_pc_casos();
$doc = sld_pc_documento();

if ($doc === '') {
  print "El agente no tiene cargado el documento «Testing & Validation Cases».\n";
  return;
}

if ($accion === 'lista') {
  foreach ($casos as $id => $caso) {
    $seccion = sld_pc_apartado($doc, 'TEST ' . $caso['n'] . ' —');
    $ficha = sld_pc_ficha($seccion);
    $lineas = array_values(array_filter(array_map('trim', explode("\n", $ficha))));
    // La ficha no debe dejar ver lo que se evalúa: si se cuela, el
    // participante simulado «sabría» la respuesta y el caso no mediría nada.
    $fuga = preg_match('/esperad|FAIL|PASS|objetivo del test/iu', $ficha) === 1;
    printf("%-5s %-58s ficha %4d car. · termina: %s%s\n", $id, mb_substr(strtok($seccion, "\n") ?: '(no encontrado)', 0, 58), mb_strlen($ficha), mb_substr(end($lineas) ?: '', 0, 60), $fuga ? '  ⚠ SE CUELA LO ESPERADO' : '');
  }
  return;
}

if ($accion === 'informe') {
  $filas = [];
  foreach (glob(SLD_PC_DIR . '/*.json') as $f) {
    $filas[basename($f, '.json')] = json_decode((string) file_get_contents($f), TRUE);
  }
  ksort($filas);

  $pass = $total = 0;
  $veredictos = [];
  $cero = [];

  foreach ($filas as $id => $fila) {
    $v = $fila['auditoria']['veredicto'] ?? '?';
    $veredictos[$v] = ($veredictos[$v] ?? 0) + 1;
    foreach ($fila['auditoria']['tarjeta'] ?? [] as $valor) {
      if ($valor !== 'NO APLICA') {
        $total++;
        $pass += $valor === 'PASS' ? 1 : 0;
      }
    }
    foreach ($fila['auditoria']['fallos_tolerancia_cero'] ?? [] as $z) {
      $cero[] = "$id: $z";
    }
    $auto = array_keys(array_filter($fila['comprobaciones'] ?? [], static fn ($c) => !$c[0]));
    printf("%-5s %-22s Score %-5s %-17s conf %-6s $%.2f  auto: %s\n", $id, $v, var_export($fila['score'], TRUE), $fila['madurez'] ?? '', $fila['confianza'] ?? '', $fila['usd'] ?? 0, $auto === [] ? 'ok' : implode(', ', $auto));
  }

  printf("\nVeredictos: %s\n", json_encode($veredictos, JSON_UNESCAPED_UNICODE));
  printf("Criterios de la tarjeta en PASS: %d de %d (%.1f %%); su umbral es 90 %%\n", $pass, $total, $total ? 100 * $pass / $total : 0);
  printf("Fallos de tolerancia cero: %d\n", count($cero));
  foreach ($cero as $z) {
    print "  - $z\n";
  }

  $t01 = array_filter([$filas['T01A']['score'] ?? NULL, $filas['T01B']['score'] ?? NULL, $filas['T01C']['score'] ?? NULL], 'is_numeric');
  if (count($t01) >= 2) {
    printf("Consistencia T01 (§35): %s → diferencia %s\n", implode(' / ', $t01), max($t01) - min($t01));
  }

  return;
}

$id = strtoupper((string) ($extra[1] ?? ''));

if ($accion !== 'correr' || !isset($casos[$id])) {
  print "Uso: lista | correr <caso> | informe\n";
  return;
}

$caso = $casos[$id];
$seccion = sld_pc_apartado($doc, 'TEST ' . $caso['n'] . ' —');
$ficha = sld_pc_ficha($seccion);
$reglas = implode("\n\n", array_filter([
  sld_pc_apartado($doc, 'REGLA MAESTRA'),
  sld_pc_apartado($doc, 'TOLERANCIA'),
  sld_pc_apartado($doc, 'SCORECARD'),
  sld_pc_apartado($doc, 'CRITERIO PARA APROBAR'),
  sld_pc_apartado($doc, 'ZERO-TOLERANCE'),
]));

if ($seccion === '' || $ficha === '') {
  print "No se encontró el caso {$caso['n']} en el documento.\n";
  return;
}

$conversacion = \Drupal::service(ConversationService::class);
$cliente = \Drupal::service(OpenAIClient::class);
$almacen = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_session');
$agente = \Drupal::entityTypeManager()->getStorage('sld_agent')->load(SLD_PC_AGENTE);

$nombre = 'cliente_' . strtolower($id);
$existentes = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $nombre]);
$alumno = $existentes !== [] ? reset($existentes) : User::create(['name' => $nombre, 'status' => 1, 'timezone' => 'America/Mexico_City']);
$alumno->save();

// El mismo método que usa producción al pulsar «empezar», sin la puerta de
// WordPress: compone prompt, memoria e historial igual.
$crear = new ReflectionMethod(DiagnosticStarter::class, 'createSession');
$sesion = $crear->invoke(\Drupal::service(DiagnosticStarter::class), $alumno, $agente, '9800' . substr($id, 1, 2), $agente->getCourseId());
$sid = (int) $sesion->id();
$errores = [];
$inicio = microtime(TRUE);

for ($turno = 1; $turno <= ($caso['max'] ?? 50); $turno++) {
  $almacen->resetCache([$sid]);
  $sesion = $almacen->load($sid);

  if (!$sesion->getStatus()->acceptsMessages()) {
    break;
  }

  $mensajes = $conversacion->getConversation($sid);

  // Una pausa por evidencia pendiente, dicha dos veces seguidas, es un final:
  // una persona real se iría a buscar los datos.
  $ultimos = array_values(array_filter(array_slice($mensajes, -4), static fn ($m) => $m->role->value === 'assistant'));
  if (count($ultimos) === 2 && preg_match('/evidencia pendiente/iu', $ultimos[0]->content) && preg_match('/evidencia pendiente/iu', $ultimos[1]->content)) {
    break;
  }

  $texto = $caso['guion'][$turno] ?? sld_pc_participante($cliente, $ficha, $mensajes);

  try {
    $conversacion->submitMessage($sesion, $texto);
  }
  catch (DiagnosticException $e) {
    $errores[] = "turno $turno: " . get_class($e) . ': ' . $e->getMessage();
  }
}

$almacen->resetCache([$sid]);
$sesion = $almacen->load($sid);
$mensajes = $conversacion->getConversation($sid);
$comprobaciones = sld_pc_comprobar($caso, $sesion, $mensajes, $errores);

try {
  $auditoria = sld_pc_auditar($cliente, $reglas, $seccion, $caso, $comprobaciones, $mensajes);
}
catch (DiagnosticException $e) {
  $auditoria = ['veredicto' => '?', 'justificacion' => 'El auditor falló: ' . $e->getMessage(), 'fallos_tolerancia_cero' => [], 'observaciones' => [], 'tarjeta' => []];
}

$resultados = \Drupal::entityTypeManager()->getStorage('sld_diagnostic_result')->loadByProperties(['session_id' => $sid]);
$r = $resultados === [] ? NULL : reset($resultados);
$payload = $r ? $r->getPayload() : [];
$coste = (float) \Drupal::database()->query('SELECT COALESCE(SUM(cost_usd), 0) FROM {sld_ai_usage} WHERE session_id = :s', [':s' => $sid])->fetchField();

if (!is_dir(SLD_PC_DIR)) {
  mkdir(SLD_PC_DIR, 0775, TRUE);
}

$fila = [
  'caso' => $id,
  'sesion' => $sid,
  'estado' => $sesion->getStatus()->value,
  'mensajes' => count($mensajes),
  'segundos' => (int) round(microtime(TRUE) - $inicio),
  'usd' => round($coste, 4),
  'score' => $payload['score'] ?? NULL,
  'madurez' => sld_pc_madurez((string) ($payload['maturity'] ?? '')),
  'confianza' => sld_pc_confianza((string) ($payload['confidence'] ?? '')),
  'comprobaciones' => $comprobaciones,
  'errores' => $errores,
  'auditoria' => $auditoria,
];
file_put_contents(SLD_PC_DIR . "/$id.json", json_encode($fila, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$md = ["# $id — " . strtok($seccion, "\n"), '', "Sesión $sid · {$fila['estado']} · {$fila['mensajes']} mensajes · \${$fila['usd']} · {$fila['segundos']} s", '', '## Auditor: ' . ($auditoria['veredicto'] ?? '?'), (string) ($auditoria['justificacion'] ?? '')];
foreach ($auditoria['fallos_tolerancia_cero'] ?? [] as $z) {
  $md[] = "- TOLERANCIA CERO: $z";
}
foreach ($auditoria['observaciones'] ?? [] as $o) {
  $md[] = "- Observación: $o";
}
$md[] = '';
$md[] = '## Comprobaciones automáticas';
foreach ($comprobaciones as $nombre => [$ok, $detalle]) {
  $md[] = sprintf('- %s %s: %s', $ok ? 'PASA' : 'FALLA', $nombre, $detalle);
}
$md[] = '';
$md[] = '## Ficha que recibió el participante';
$md[] = $ficha;
foreach ($mensajes as $m) {
  $md[] = '';
  $md[] = '## ' . ($m->role->value === 'user' ? 'PARTICIPANTE' : 'AGENTE');
  $md[] = $m->content;
}
file_put_contents(SLD_PC_DIR . "/$id.md", implode("\n", $md) . "\n");

$fallos = array_keys(array_filter($comprobaciones, static fn ($c) => !$c[0]));
printf("%-5s sesión %d  %s  score %s  $%.2f  auditor %s  auto %s\n", $id, $sid, $fila['estado'], var_export($fila['score'], TRUE), $coste, $auditoria['veredicto'] ?? '?', $fallos === [] ? 'ok' : implode(',', $fallos));
