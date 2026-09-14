<?php

/**
 * @file
 * Pone en su sitio las imágenes de la portada: el fondo y los dos logotipos.
 *
 * Se ejecuta con:
 *   ddev drush php:script bin/cargar-marca.php
 *   drush php:script bin/cargar-marca.php     (en el servidor)
 *
 * Existe desde el 14-09-2026, preparando el primer despliegue. La pestaña
 * «Portada» guarda el NÚMERO de cada archivo, y ese número es del entorno
 * donde se subió: en una instalación nueva no existe, y la portada, el inicio
 * de sesión y todas las pantallas del alumno se quedaban sin logotipo.
 *
 * Por eso las imágenes viajan en `docs/marca/` y este script las sube y apunta
 * la configuración a ellas, igual que lo haría el formulario: archivo
 * permanente y con su uso registrado, para que la limpieza automática de
 * Drupal no lo borre a las seis horas.
 *
 * Es idempotente: vuelve a escribir el mismo archivo en la misma ruta y Drupal
 * reutiliza su registro, así que el número no cambia al repetirlo.
 *
 * Que un despliegue posterior no pise estos números lo impide config_ignore:
 * ver `config/sync/config_ignore.settings.yml`.
 */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\sales_leadership_diagnostic\Service\Branding\HomePage;

// Clave de configuración => archivo en docs/marca/. Mismo nombre que tienen en
// public://sales-diagnostic/, para que en local se reutilicen los que ya hay.
$imagenes = [
  'background_fid' => 'portada-fondo.webp',
  'logo_light_fid' => 'logo-claro.webp',
  'logo_color_fid' => 'logo-color.webp',
];

$destino = 'public://sales-diagnostic';
\Drupal::service('file_system')->prepareDirectory($destino, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

$config = \Drupal::configFactory()->getEditable(HomePage::CONFIG_NAME);
$uso = \Drupal::service('file.usage');
$problemas = 0;

foreach ($imagenes as $clave => $nombre) {
  $origen = dirname(__DIR__) . '/docs/marca/' . $nombre;

  if (!is_file($origen)) {
    printf("  %s: FALTA docs/marca/%s\n", $clave, $nombre);
    $problemas++;
    continue;
  }

  $archivo = \Drupal::service('file.repository')
    ->writeData(file_get_contents($origen), "$destino/$nombre", FileExists::Replace);
  $archivo->setPermanent();
  $archivo->save();

  if (!isset($uso->listUsage($archivo)['sales_leadership_diagnostic'])) {
    $uso->add($archivo, 'sales_leadership_diagnostic', 'config', (string) $archivo->id());
  }

  $antes = (int) $config->get($clave);
  $config->set($clave, (int) $archivo->id());
  printf("  %s: %s (%s)\n", $clave, $nombre, $antes === (int) $archivo->id() ? 'sin cambios' : "archivo $antes -> {$archivo->id()}");
}

$config->save();

print $problemas > 0 ? "\nFaltan imágenes: revise docs/marca/.\n" : "\nMarca cargada.\n";
