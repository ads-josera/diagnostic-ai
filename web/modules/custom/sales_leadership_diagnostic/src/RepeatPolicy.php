<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Cuántos diagnósticos puede hacer un alumno.
 *
 * No es una decisión técnica sino comercial, y por eso es configurable en vez
 * de estar fijada en el código: el cliente puede vender el diagnóstico como
 * una herramienta de seguimiento —donde repetirlo cada trimestre es lo
 * deseable— o como un entregable único de cada compra.
 */
enum RepeatPolicy: string {

  /*
   * El alumno repite cuantas veces quiera mientras conserve el acceso.
   *
   * Tiene sentido si el diagnóstico se vende como herramienta de seguimiento:
   * repetirlo y comparar la evolución ES el producto.
   */
  case Unlimited = 'unlimited';

  /*
   * Un diagnóstico por periodo de acceso.
   *
   * El periodo lo marca el reloj de WordPress, que arranca al detectar al
   * alumno y se reinicia con una compra nueva. Así la reactivación ocurre sola
   * al comprar, sin que nadie tenga que intervenir a mano.
   */
  case OncePerPeriod = 'once_per_period';

  /**
   * Etiqueta para el formulario de configuración.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Unlimited => new TranslatableMarkup('Sin límite: puede repetirlo mientras tenga acceso'),
      self::OncePerPeriod => new TranslatableMarkup('Uno por periodo de acceso: se renueva al comprar de nuevo'),
    };
  }

  /**
   * Descripción de lo que implica cada opción.
   */
  public function description(): TranslatableMarkup {
    return match ($this) {
      self::Unlimited => new TranslatableMarkup('Adecuado si el diagnóstico se usa para seguir la evolución de la organización en el tiempo.'),
      self::OncePerPeriod => new TranslatableMarkup('Adecuado si cada diagnóstico corresponde a una compra. Requiere que WordPress informe de cuándo empezó el periodo; si no lo hace, no se aplica el límite y queda constancia en el registro.'),
    };
  }

  /**
   * Convierte un valor de configuración en política.
   *
   * Un valor desconocido cae en «sin límite», que es el comportamiento que el
   * módulo ha tenido siempre. Ante una configuración corrupta es preferible
   * dejar trabajar al alumno que dejarlo fuera de un producto que ha pagado.
   */
  public static function fromConfigValue(mixed $value): self {
    return is_string($value) ? (self::tryFrom($value) ?? self::Unlimited) : self::Unlimited;
  }

  /**
   * Opciones para un elemento de formulario.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Valor de cada caso y su etiqueta.
   */
  public static function options(): array {
    $options = [];

    foreach (self::cases() as $case) {
      $options[$case->value] = $case->label();
    }

    return $options;
  }

}
