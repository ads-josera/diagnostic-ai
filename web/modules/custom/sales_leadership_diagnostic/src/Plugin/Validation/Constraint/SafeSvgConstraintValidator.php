<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Plugin\Validation\Constraint;

use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Symfony\Component\Validator\Constraint;

/**
 * Comprueba que un SVG no contenga nada capaz de ejecutarse.
 *
 * El análisis se hace sobre el XML ya interpretado y no con expresiones
 * regulares sobre el texto. Es una diferencia que importa: un atacante puede
 * escribir `<script>` o partir el atributo con entidades para burlar una
 * búsqueda de texto, pero no puede engañar al analizador XML, que es el mismo
 * que usará el navegador.
 */
final class SafeSvgConstraintValidator extends BaseFileConstraintValidator {

  /**
   * Elementos que pueden ejecutar código o traer contenido de fuera.
   *
   * `foreignObject` permite incrustar HTML dentro del SVG, y con él cualquier
   * cosa que el HTML admita. `use` y `image` pueden referenciar recursos
   * externos, que es una fuga de información hacia el servidor referenciado
   * aunque no ejecute nada.
   *
   * @var string[]
   */
  private const FORBIDDEN_ELEMENTS = [
    'script',
    'foreignObject',
    'iframe',
    'embed',
    'object',
    'audio',
    'video',
    'handler',
    'set',
    'animate',
  ];

  /**
   * Atributos que ejecutan código o cargan recursos externos.
   *
   * Los manejadores de evento se detectan por su prefijo «on». Enumerarlos uno
   * a uno daría una lista que quedaría incompleta con el tiempo.
   *
   * @var string[]
   */
  private const FORBIDDEN_ATTRIBUTES = [
    'href',
    'xlink:href',
  ];

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $file = $this->assertValueIsFile($value);

    if (!$constraint instanceof SafeSvgConstraint) {
      throw new \InvalidArgumentException(sprintf('Se esperaba una restricción %s.', SafeSvgConstraint::class));
    }

    // Solo se examinan los SVG. El resto de formatos son datos de píxeles y no
    // pueden ejecutar nada, así que pasar por aquí sería trabajo inútil.
    if (!$this->isSvg($file->getFilename())) {
      return;
    }

    $contents = @file_get_contents($file->getFileUri());

    if ($contents === FALSE || trim($contents) === '') {
      $this->context->addViolation($constraint->malformedMessage);

      return;
    }

    $finding = $this->findExecutableContent($contents);

    if ($finding === NULL) {
      return;
    }

    if ($finding === '') {
      $this->context->addViolation($constraint->malformedMessage);

      return;
    }

    $this->context->addViolation($constraint->message, ['%hallazgo' => $finding]);
  }

  /**
   * Indica si el nombre corresponde a un SVG.
   */
  private function isSvg(string $filename): bool {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg';
  }

  /**
   * Busca contenido ejecutable en el SVG.
   *
   * @param string $contents
   *   Contenido del archivo.
   *
   * @return string|null
   *   Descripción de lo encontrado, cadena vacía si el XML no es válido, o
   *   NULL si el SVG está limpio.
   */
  private function findExecutableContent(string $contents): ?string {
    // Se desactivan las entidades externas antes de analizar. Sin esto, el
    // propio acto de validar el archivo podría hacer que el servidor leyera
    // ficheros locales o hiciera peticiones de red: el ataque XXE.
    $previous = libxml_use_internal_errors(TRUE);

    $document = new \DOMDocument();
    $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOENT);

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($loaded === FALSE) {
      return '';
    }

    return $this->inspect($document->documentElement);
  }

  /**
   * Recorre el árbol buscando elementos y atributos prohibidos.
   *
   * @param \DOMNode|null $node
   *   Nodo a examinar.
   *
   * @return string|null
   *   Lo encontrado, o NULL si esta rama está limpia.
   */
  private function inspect(?\DOMNode $node): ?string {
    if (!$node instanceof \DOMElement) {
      return NULL;
    }

    // La comparación es insensible a mayúsculas porque el analizador conserva
    // la caja original y «<SCRIPT>» es igual de ejecutable que «<script>».
    foreach (self::FORBIDDEN_ELEMENTS as $forbidden) {
      if (strcasecmp($node->localName, $forbidden) === 0) {
        return '<' . $node->localName . '>';
      }
    }

    foreach ($node->attributes ?? [] as $attribute) {
      $found = $this->inspectAttribute($attribute->nodeName, (string) $attribute->nodeValue);

      if ($found !== NULL) {
        return $found;
      }
    }

    foreach ($node->childNodes as $child) {
      $found = $this->inspect($child);

      if ($found !== NULL) {
        return $found;
      }
    }

    return NULL;
  }

  /**
   * Examina un atributo concreto.
   *
   * @param string $name
   *   Nombre del atributo.
   * @param string $value
   *   Su valor.
   *
   * @return string|null
   *   Lo encontrado, o NULL si el atributo es inofensivo.
   */
  private function inspectAttribute(string $name, string $value): ?string {
    $lowerName = strtolower($name);

    // Cualquier atributo que empiece por «on» es un manejador de evento:
    // onload, onclick, onmouseover y una lista larga que no conviene enumerar
    // porque quedaría desfasada.
    if (str_starts_with($lowerName, 'on')) {
      return $name;
    }

    if (!in_array($lowerName, self::FORBIDDEN_ATTRIBUTES, TRUE)) {
      return NULL;
    }

    $lowerValue = strtolower(trim($value));

    // Un enlace interno «#id» es legítimo y frecuente en SVG bien construidos:
    // así se reutilizan degradados y símbolos dentro del propio archivo.
    if ($lowerValue === '' || str_starts_with($lowerValue, '#')) {
      return NULL;
    }

    return $name . '="' . mb_substr($value, 0, 40) . '"';
  }

}
