<?php

/**
 * @file
 * Carga los prompts y los documentos de conocimiento de los agentes.
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
 * Es idempotente: si el prompt no cambió, no sube la versión. Esa versión
 * queda escrita en cada sesión, y subirla sin motivo estropea justo la
 * trazabilidad para la que existe (§57).
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\sales_leadership_diagnostic\Service\Knowledge\KnowledgeLibrary;

$raiz = dirname(__DIR__) . '/docs/knowledge-cliente/';

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
    // Su biblioteca se administra desde la interfaz y no se toca aquí: pasarle
    // una lista vacía borraría los documentos que ya tiene.
    'documentos' => NULL,
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
$destino = 'public://knowledge';
$fs->prepareDirectory($destino, FileSystemInterface::CREATE_DIRECTORY);

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

  if (trim($texto) === trim($anterior)) {
    printf("  prompt: sin cambios (%s caracteres), sigue en v%s\n", number_format(strlen($texto)), $version);
  }
  else {
    $nueva = siguiente_version($version);
    $agente->set('system_prompt', $texto);
    $agente->set('version', $nueva);
    printf("  prompt: %s -> %s caracteres, v%s -> v%s\n",
      number_format(strlen($anterior)), number_format(strlen($texto)), $version, $nueva);
  }

  // --- Los documentos ---
  if ($datos['documentos'] === NULL) {
    printf("  documentos: no se tocan (%d ya presentes)\n", count($agente->getKnowledgeFids()));
    $agente->save();
    continue;
  }

  $fids = [];

  foreach ($datos['documentos'] as $nombre) {
    $origen = $raiz . $nombre;

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
  $agente->save();

  printf("  documentos: %d cargados, %s tokens estimados\n",
    count($fids), number_format($biblioteca->getTotalTokens($agente)));
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
