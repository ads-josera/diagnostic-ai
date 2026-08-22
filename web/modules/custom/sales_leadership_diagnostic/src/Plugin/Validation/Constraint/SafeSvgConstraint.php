<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Rechaza los SVG que puedan ejecutar código.
 *
 * Un SVG no es una imagen sino un documento XML, y admite `<script>`,
 * manejadores de evento y referencias externas. Eso lo convierte en el único
 * formato de imagen que puede ejecutar JavaScript.
 *
 * El riesgo depende de CÓMO se sirva, y conviene ser preciso porque la
 * diferencia decide la política:
 *
 *  - Dentro de un `<img src="logo.svg">`, que es como lo pinta este módulo, el
 *    navegador lo renderiza en modo restringido: los scripts NO se ejecutan y
 *    los recursos externos no se cargan.
 *  - Abriendo su URL directamente, el navegador lo trata como un documento y
 *    ahí sí ejecuta lo que lleve dentro, en el dominio del diagnóstico.
 *
 * Ese segundo camino es el que justifica esta comprobación. No basta con
 * confiar en que solo lo suban administradores: si una cuenta de
 * administración se ve comprometida, un SVG cargado hoy queda como una vía de
 * ejecución permanente en el dominio donde los alumnos tienen sesión iniciada.
 *
 * Se RECHAZA en lugar de limpiar el archivo. Limpiarlo significaría alterar en
 * silencio el logotipo del cliente y devolverle algo distinto de lo que subió;
 * es preferible decirle que ese archivo no sirve y por qué, para que su
 * diseñador lo exporte sin scripts.
 */
#[Constraint(
  id: 'SldSafeSvg',
  label: new TranslatableMarkup('SVG sin código ejecutable', [], ['context' => 'Validation']),
  type: 'file',
)]
final class SafeSvgConstraint extends SymfonyConstraint {

  /**
   * Mensaje cuando el SVG contiene algo capaz de ejecutarse.
   *
   * @var string
   */
  public string $message = 'El SVG contiene código ejecutable (%hallazgo) y no se puede usar como logotipo. Pide a tu diseñador que lo exporte sin scripts ni interactividad.';

  /**
   * Mensaje cuando el archivo no es XML válido.
   *
   * @var string
   */
  public string $malformedMessage = 'El archivo tiene extensión .svg pero no es un SVG válido.';

}
