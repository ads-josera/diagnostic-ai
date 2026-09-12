<?php

/**
 * @file
 * Carga los prompts, los contratos de salida y los documentos de los agentes.
 *
 * Se ejecuta con:
 *   ddev drush php:script bin/cargar-agentes.php
 *   drush php:script bin/cargar-agentes.php     (en el servidor)
 *
 * Existe por una razón concreta. Los prompts del cliente rondan los 8 000
 * caracteres y llevan guiones largos, flechas y comillas tipográficas. El
 * 07-09-2026 se descubrió que una copia hecha a mano los había aplanado en
 * silencio —«0–39» quedó en «0-39»— y nadie lo habría notado nunca. Pasar el
 * prompt por un textarea en producción es la misma trampa otra vez.
 *
 * Por eso este script lee los archivos del repositorio TAL CUAL y se niega a
 * cargar uno que haya perdido sus caracteres por el camino.
 *
 * El contrato de salida —la parte NUESTRA del prompt, que dice cómo entregar la
 * respuesta a la plataforma— sale de `docs/contratos-de-salida/`. Hasta el
 * 12-09-2026 solo existía en la base de datos local: una instalación limpia
 * obligaba a pegarlo a mano, que es justo la trampa de arriba.
 *
 * Es idempotente: si nada cambió, no sube la versión. Esa versión
 * queda escrita en cada sesión, y subirla sin motivo estropea justo la
 * trazabilidad para la que existe (§57).
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\sales_leadership_diagnostic\Service\Knowledge\KnowledgeLibrary;

$raiz = dirname(__DIR__) . '/docs/knowledge-cliente/';
$contratos = dirname(__DIR__) . '/docs/contratos-de-salida/';

/**
 * Qué se carga en cada agente.
 *
 * El orden de los documentos es el del manifiesto del Documento 13, sección 8:
 * 01 a 13, luego MASTER, luego 15. No es cosmético: es el orden en que el
 * cliente ensambla su base de conocimiento.
 */
$plan = [
  'prospecting_diagnostic' => [
    'prompt' => 'GAP_Prospecting_AI_CORE_INSTRUCTIONS_v1.4.3_COMPACT.txt',
    'carpeta' => 'docs/knowledge-cliente/',
    'documentos' => [
      'GAP_Prospecting_AI_01_Orchestrator_v1.3.docx',
      'GAP_Prospecting_AI_02_Orchestrator_Control_Contract_v1.3.docx',
      'GAP_Prospecting_AI_03_Company_Intelligence_ICP_Engine_v1.1.docx',
      'GAP_Prospecting_AI_04_Weekly_Mission_Prospecting_Prescription_Engine_v1.1.docx',
      'GAP_Prospecting_AI_05_Account_Discovery_Signal_Catalyst_Intelligence_Engine_v1.3.docx',
      'GAP_Prospecting_AI_06_Buyer_Contact_Intelligence_Enrichment_Routing_Engine_v1.2.docx',
      'GAP_Prospecting_AI_07_GAP_Outreach_Message_Engine_v1.4.docx',
      'GAP_Prospecting_AI_08_Weekly_Execution_Feedback_Learning_Engine_v1.1.docx',
      'GAP_Prospecting_AI_09_Scoring_Prioritization_Executive_Gold_Pack_Engine_v1.1.docx',
      'GAP_Prospecting_AI_10_Governance_Memory_Data_Continuous_Learning_Engine_v1.1.docx',
      'GAP_Prospecting_AI_11_Quality_Assurance_Red_Team_Anti_Hallucination_Engine_v1.1.docx',
      'GAP_Prospecting_AI_12_Weekly_Advisor_Experience_Conversation_UX_Executive_Delivery_Engine_v1.5.docx',
      'GAP_Prospecting_AI_13_Implementation_GPT_Configuration_Knowledge_Base_Assembly_v1.2.docx',
      'GAP_Prospecting_AI_Master_GPT_Instructions_v1.2.docx',
      'GAP_Prospecting_AI_15_Validation_Adversarial_Stress_Tests_v1.7.docx',
    ],
  ],
  'sales_leadership_diagnostic' => [
    'prompt' => 'Sales_Leadership_Diagnostic_AI_prompt_final_aprobado.txt',
    'carpeta' => 'docs/Knowledge documents/',
    // El juego completo que mandó el cliente el 12-09-2026. Para este agente no
    // hay manifiesto como el del Documento 13, así que el orden sigue la
    // arquitectura de autoridad de su Orchestrator: qué es el agente, quién
    // gobierna la secuencia, y luego cada motor en el orden en que actúa
    // —conversación, evidencia, scoring, informe—; la capa de protección, que
    // cruza todo; y al final lo que habla de probar y montar el agente.
    //
    // NO va el «Documento Maestro Interno» del Framework, que está en
    // interno-no-se-carga/: es el marco de los seis agentes de la suite, trae
    // un formato de salida universal que choca con el FINAL REPORT de este, y
    // las propias Build Instructions (§64) lo dejan como documento interno.
    'documentos' => [
      'Agent 01 — Sales Leadership Diagnostic AI — Specification v1.0.docx',
      'Sales Leadership Diagnostic Orchestrator v1.0.docx',
      'Agent 01 — Conversation Engine v1.0.docx',
      'Evidence Handoff v1.0.docx',
      'Agent 01 — Scoring Engine v1.2.3.docx',
      'Agent 01 — Final Report Engine v1.2.docx',
      'IP Protection v1.0.docx',
      'Agent 01 — Testing & Validation Cases v1.0.docx',
      'Agent 01 — GPT Build Instructions v1.0.docx',
    ],
  ],
];

/**
 * Se niega a cargar un prompt que perdió caracteres por el camino.
 *
 * Un prompt aplanado sigue pareciendo correcto —se lee igual— y por eso hace
 * falta comprobarlo aquí y no a ojo. Las dos señales son fiables: aparecen
 * sustitutos ASCII donde el original tenía tipografía, y desaparece todo
 * carácter no ASCII de un texto que sí los tenía.
 */
function prompt_sospechoso(string $texto): ?string {
  if (preg_match('/[^\x00-\x7F]/', $texto) !== 1) {
    return 'no conserva ningún carácter no ASCII; el original sí los tiene';
  }

  if (str_contains($texto, '->')) {
    return 'contiene «->», que en el original es «→»';
  }

  if (preg_match('/\b\d+-\d+\s+(CRITICAL|DEVELOPING|MANAGED|PREDICTIVE)/', $texto) === 1) {
    return 'los rangos de puntuación llevan guion corto; en el original son «–»';
  }

  return NULL;
}

/**
 * Sube el número menor de la versión: 1.1 -> 1.2.
 */
function siguiente_version(string $actual): string {
  $partes = explode('.', $actual);
  $mayor = (int) ($partes[0] ?? 1);
  $menor = (int) ($partes[1] ?? 0);

  return $mayor . '.' . ($menor + 1);
}

$etm = \Drupal::entityTypeManager();
$biblioteca = \Drupal::service(KnowledgeLibrary::class);
$fs = \Drupal::service('file_system');
// PRIVADO. Son la metodología propietaria del cliente y en `public://` se
// descargaban sin iniciar sesión con solo acertar la URL: pasó hasta el
// 12-09-2026, cuando esta línea decía `public://knowledge`. El formulario de
// documentos ya guardaba en privado; el cargador era el que no.
$destino = 'private://sales-diagnostic/knowledge';

if (!$fs->realpath('private://')) {
  print "No hay carpeta privada configurada: NO se carga ningún documento. Configure \$settings['file_private_path'].\n";
  return;
}

$fs->prepareDirectory($destino, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

/**
 * Huella del CONTENIDO de una lista de documentos, en su orden.
 *
 * Es lo que decide si la versión sube. No sirven los identificadores: mover un
 * documento de carpeta, o volver a escribirlo igual, no cambia lo que el agente
 * lee, y subir la versión por eso estropearía la trazabilidad (§57).
 */
function huella_documentos(array $fids): string {
  $sistema = \Drupal::service('file_system');
  $partes = [];

  foreach ($fids as $fid) {
    $archivo = \Drupal::entityTypeManager()->getStorage('file')->load($fid);
    $real = $archivo ? $sistema->realpath($archivo->getFileUri()) : FALSE;
    $partes[] = $real && is_file($real) ? sha1_file($real) : 'falta-' . $fid;
  }

  return sha1(implode('|', $partes));
}

$problemas = [];

foreach ($plan as $id => $datos) {
  $agente = $etm->getStorage('sld_agent')->load($id);

  if ($agente === NULL) {
    $problemas[] = "No existe el agente «$id». Créelo antes desde la interfaz.";
    continue;
  }

  printf("\n=== %s ===\n", $agente->label());

  // --- El prompt ---
  $ruta = $raiz . $datos['prompt'];

  if (!is_file($ruta)) {
    $problemas[] = "Falta el archivo {$datos['prompt']}.";
    continue;
  }

  $texto = file_get_contents($ruta);
  $motivo = prompt_sospechoso($texto);

  if ($motivo !== NULL) {
    $problemas[] = "{$datos['prompt']}: $motivo. NO se cargó.";
    printf("  prompt: RECHAZADO — %s\n", $motivo);
    continue;
  }

  $anterior = (string) $agente->get('system_prompt');
  $version = (string) $agente->get('version');
  $cambio = FALSE;

  if (trim($texto) === trim($anterior)) {
    printf("  prompt: sin cambios (%s caracteres)\n", number_format(strlen($texto)));
  }
  else {
    $agente->set('system_prompt', $texto);
    $cambio = TRUE;
    printf("  prompt: %s -> %s caracteres\n", number_format(strlen($anterior)), number_format(strlen($texto)));
  }

  // --- El contrato de salida ---
  $rutaContrato = $contratos . $id . '.txt';

  if (!is_file($rutaContrato)) {
    $problemas[] = "Falta el contrato de salida de «$id» en docs/contratos-de-salida/.";
  }
  else {
    $contrato = rtrim(file_get_contents($rutaContrato));
    $contratoAnterior = trim((string) $agente->get('output_contract'));

    // El carácter de sustitución delata un archivo que perdió su codificación.
    if ($contrato === '' || str_contains($contrato, "\u{FFFD}")) {
      $problemas[] = "El contrato de «$id» está vacío o dañado. NO se cargó.";
    }
    elseif (trim($contrato) === $contratoAnterior) {
      printf("  contrato: sin cambios (%s caracteres)\n", number_format(strlen($contrato)));
    }
    else {
      $agente->set('output_contract', $contrato);
      $cambio = TRUE;
      printf("  contrato: %s -> %s caracteres\n", number_format(strlen($contratoAnterior)), number_format(strlen($contrato)));
    }
  }

  // --- Los documentos ---
  if ($datos['documentos'] !== NULL) {
    $antes = huella_documentos($agente->getKnowledgeFids());
    $fids = [];

    foreach ($datos['documentos'] as $nombre) {
      $origen = dirname(__DIR__) . '/' . $datos['carpeta'] . $nombre;

      if (!is_file($origen)) {
        $problemas[] = "Falta el documento $nombre.";
        continue;
      }

      $archivo = \Drupal::service('file.repository')
        ->writeData(file_get_contents($origen), $destino . '/' . $nombre, FileExists::Replace);
      $archivo->setPermanent();
      $archivo->save();

      $resultado = $biblioteca->remember($archivo);
      $fids[] = (int) $archivo->id();

      if (!$resultado->correcto) {
        $problemas[] = "$nombre: no se pudo extraer el texto ({$resultado->motivo}).";
      }
    }

    $agente->setKnowledgeFids($fids);

    if (huella_documentos($fids) !== $antes) {
      $cambio = TRUE;
      printf("  documentos: %d cargados, CAMBIARON\n", count($fids));
    }
    else {
      printf("  documentos: %d, sin cambios de contenido\n", count($fids));
    }
  }

  // Una sola subida de versión por pasada, cambie lo que cambie: la versión
  // dice «las instrucciones de esta sesión no son las de la anterior», y eso
  // es igual de cierto si cambió el prompt, el contrato, los documentos o
  // todo a la vez.
  if ($cambio) {
    $nueva = siguiente_version($version);
    $agente->set('version', $nueva);
    printf("  versión: v%s -> v%s\n", $version, $nueva);
  }
  else {
    printf("  versión: sigue en v%s\n", $version);
  }

  $agente->save();

  printf("  conocimiento: %s tokens estimados\n", number_format($biblioteca->getTotalTokens($agente)));
}

if ($problemas !== []) {
  print "\n=== PROBLEMAS ===\n";
  foreach ($problemas as $p) {
    print " - $p\n";
  }
  print "\n";
}
else {
  print "\nTodo cargado sin incidencias.\n";
}
