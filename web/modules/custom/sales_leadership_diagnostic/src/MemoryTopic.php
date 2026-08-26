<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * De qué puede acordarse el sistema sobre un alumno.
 *
 * Es una lista CERRADA, y esa es la decisión de diseño importante. La
 * alternativa —dejar que el modelo invente la clave de cada hecho que
 * extrae— produce una memoria que crece sin límite y que no se puede
 * actualizar: «sector», «sector de la empresa» y «industria» conviven como
 * tres hechos distintos, se contradicen entre sí, y nadie sabe cuál vale.
 *
 * Con la lista cerrada, la memoria de un alumno tiene como mucho tantas
 * entradas como casos hay aquí. Cada nueva extracción reemplaza el contenido
 * de su tema en vez de acumular otro más, y el alumno puede revisarla entera
 * de un vistazo, que es condición para poder borrar lo que esté mal.
 *
 * Los temas describen el NEGOCIO del alumno, no su diagnóstico. Las
 * conclusiones viven en el resultado, que es inmutable y con su propia fecha;
 * repetirlas aquí crearía dos versiones de la misma verdad que envejecerían
 * por separado.
 */
enum MemoryTopic: string {

  /*
   * Qué hace la empresa: sector, tamaño, mercado, modelo de venta.
   */
  case Empresa = 'empresa';

  /*
   * Cómo es el equipo comercial: cuántos son, cómo se reparten, quién dirige.
   */
  case Equipo = 'equipo';

  /*
   * A quién le venden: perfil de cliente ideal, segmentos, ticket.
   */
  case Icp = 'icp';

  /*
   * Cuentas y oportunidades concretas de las que el alumno haya hablado.
   */
  case Cuentas = 'cuentas';

  /*
   * Cómo venden hoy: proceso, herramientas, cadencias, CRM.
   */
  case Proceso = 'proceso';

  /*
   * Qué le preocupa y qué quiere conseguir.
   */
  case Objetivos = 'objetivos';

  /**
   * Nombre del tema para el alumno.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::Empresa => new TranslatableMarkup('Su empresa'),
      self::Equipo => new TranslatableMarkup('Su equipo comercial'),
      self::Icp => new TranslatableMarkup('A quién le vende'),
      self::Cuentas => new TranslatableMarkup('Cuentas y oportunidades'),
      self::Proceso => new TranslatableMarkup('Cómo vende hoy'),
      self::Objetivos => new TranslatableMarkup('Objetivos y preocupaciones'),
    };
  }

  /**
   * Qué se espera que contenga el tema.
   *
   * Se le da al modelo que extrae la memoria: sin esta guía cada extracción
   * reparte los hechos de una manera distinta y los temas dejan de significar
   * lo mismo entre una conversación y la siguiente.
   */
  public function guidance(): string {
    return match ($this) {
      self::Empresa => 'Sector, tamaño, mercados en los que opera y modelo de venta.',
      self::Equipo => 'Tamaño y estructura del equipo comercial, roles y quién dirige.',
      self::Icp => 'Perfil de cliente ideal, segmentos a los que vende y ticket medio.',
      self::Cuentas => 'Cuentas, clientes u oportunidades concretas que haya mencionado.',
      self::Proceso => 'Proceso comercial, herramientas, CRM, cadencias y rituales de equipo.',
      self::Objetivos => 'Objetivos declarados, presiones y problemas que dice tener.',
    };
  }

  /**
   * Convierte en tema un valor venido de fuera.
   *
   * Devuelve NULL si no lo reconoce. Los valores llegan de la respuesta de un
   * modelo, así que un tema inventado es un caso normal y no una anomalía: se
   * descarta ese hecho y la extracción sigue con los demás.
   */
  public static function tryFromValue(mixed $value): ?self {
    return is_string($value) ? self::tryFrom(trim($value)) : NULL;
  }

  /**
   * Orden en que se muestran, para que la ficha se lea siempre igual.
   *
   * @return string[]
   *   Valores de todos los temas, en orden de presentación.
   */
  public static function order(): array {
    return array_map(static fn (self $case): string => $case->value, self::cases());
  }

}
