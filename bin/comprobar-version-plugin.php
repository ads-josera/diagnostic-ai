#!/usr/bin/env php
<?php

/**
 * @file
 * Comprueba que la versión del plugin de WordPress esté declarada una sola vez.
 *
 * El número vive en dos sitios que nada obliga a mantener juntos: la cabecera
 * «Version:» que lee WordPress, y la constante SLD_VERSION que lee el código y
 * que ahora viaja a Drupal en cada respuesta del endpoint.
 *
 * Si se separan, el síntoma es de los peores: WordPress muestra una versión y
 * Drupal recibe otra, así que el informe de estado dice algo distinto de lo que
 * ve quien administra el sitio. Este script lo impide fallando pronto.
 *
 * Uso:
 *   php bin/comprobar-version-plugin.php
 *
 * Devuelve 0 si todo está en orden y 1 si hay algo que corregir, de modo que
 * pueda encadenarse en un script de publicación.
 */

declare(strict_types=1);

$archivo = dirname(__DIR__) . '/wordpress-plugin/salesbumm-sld/salesbumm-sld.php';

if (!is_readable($archivo)) {
  fwrite(STDERR, sprintf("No se encuentra el archivo principal del plugin: %s\n", $archivo));
  exit(1);
}

$contenido = (string) file_get_contents($archivo);

// La cabecera solo cuenta dentro del bloque de comentario inicial, que es lo
// único que WordPress lee.
preg_match('/^\s*\*\s*Version:\s*(\S+)\s*$/m', $contenido, $cabecera);
preg_match("/const\s+SLD_VERSION\s*=\s*'([^']+)'/", $contenido, $constante);

$problemas = [];

if (($cabecera[1] ?? '') === '') {
  $problemas[] = 'No se ha encontrado la cabecera «Version:».';
}

if (($constante[1] ?? '') === '') {
  $problemas[] = 'No se ha encontrado la constante SLD_VERSION.';
}

if ($problemas === [] && $cabecera[1] !== $constante[1]) {
  $problemas[] = sprintf(
    'La cabecera dice %s y la constante dice %s. WordPress mostraría una y Drupal recibiría la otra.',
    $cabecera[1],
    $constante[1],
  );
}

// Se exige versionado semántico de tres partes. «1.2» pasaría desapercibido y
// rompería cualquier comparación posterior con version_compare().
if ($problemas === [] && preg_match('/^\d+\.\d+\.\d+$/', $cabecera[1]) !== 1) {
  $problemas[] = sprintf('«%s» no tiene la forma MAYOR.MENOR.PARCHE.', $cabecera[1]);
}

if ($problemas !== []) {
  fwrite(STDERR, "Problemas con la versión del plugin:\n");

  foreach ($problemas as $problema) {
    fwrite(STDERR, '  - ' . $problema . "\n");
  }

  exit(1);
}

printf("Versión del plugin: %s (cabecera y constante coinciden).\n", $cabecera[1]);
exit(0);
