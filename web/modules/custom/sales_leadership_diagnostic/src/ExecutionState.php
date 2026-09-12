<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Qué pasó con una cuenta después de salir en el Pack.
 *
 * Son los estados del §5 de su Documento 8 —«Execution State Machine
 * v1.1»—, con sus códigos tal cual. Los valores no se traducen porque el
 * agente razona con ellos: van en el historial que recibe al empezar cada
 * misión, y su metodología los reconoce por ese nombre.
 *
 * Falta RELEASED a propósito. En su metodología significa «autorizada y
 * asignada», y aquí nadie puede afirmarlo: que una cuenta salga en el Pack no
 * dice que alguien la tomara. Su §6 lo prohíbe con todas las letras —«Never
 * Simulate Work»—, así que una cuenta sin nada registrado se enseña como sin
 * registrar, nunca como RELEASED por suposición.
 */
enum ExecutionState: string {

  case NoAction = 'NO_ACTION';
  case Attempted = 'ATTEMPTED';
  case Contacted = 'CONTACTED';
  case Response = 'RESPONSE';
  case Conversation = 'CONVERSATION';
  case Meeting = 'MEETING';
  case Sql = 'SQL';
  case Opportunity = 'OPPORTUNITY';
  case Won = 'WON';
  case Lost = 'LOST';
  case Disqualified = 'DISQUALIFIED';
  case Nurture = 'NURTURE';

  /**
   * El nombre que lee el alumno.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::NoAction => new TranslatableMarkup('Sin acción todavía'),
      self::Attempted => new TranslatableMarkup('Intento de contacto'),
      self::Contacted => new TranslatableMarkup('Contactada, sin respuesta'),
      self::Response => new TranslatableMarkup('Respondió'),
      self::Conversation => new TranslatableMarkup('Conversación'),
      self::Meeting => new TranslatableMarkup('Reunión'),
      self::Sql => new TranslatableMarkup('Calificada (SQL)'),
      self::Opportunity => new TranslatableMarkup('Oportunidad'),
      self::Won => new TranslatableMarkup('Ganada'),
      self::Lost => new TranslatableMarkup('Perdida'),
      self::Disqualified => new TranslatableMarkup('Descartada'),
      self::Nurture => new TranslatableMarkup('Nutrir más adelante'),
    };
  }

  /**
   * El nombre exacto de su metodología, para el historial del agente.
   *
   * Su tabla los escribe con espacios —«NO ACTION»—; el código lleva guion
   * bajo solo porque así se guarda.
   */
  public function clientLabel(): string {
    return str_replace('_', ' ', $this->value);
  }

  /**
   * Si va como botón de un clic.
   *
   * Son exactamente las cinco de su §12, que es donde su metodología dice qué
   * preguntarle al vendedor: «¿No acción / sin respuesta / respuesta /
   * conversación / oportunidad?». El resto existe, pero en un desplegable: su
   * §11 se titula «Feedback Without Forms», y doce botones son un formulario.
   */
  public function isQuick(): bool {
    return in_array($this, [
      self::NoAction,
      self::Contacted,
      self::Response,
      self::Conversation,
      self::Opportunity,
    ], TRUE);
  }

  /**
   * El tono con que se pinta: neutro, avance, logro o cierre.
   *
   * Sirve para que el estado se lea sin leerlo. No es una puntuación: su §13
   * insiste en que la actividad no es pipeline, y por eso «respondió» y
   * «reunión» avanzan pero no se pintan como logro.
   */
  public function tone(): string {
    return match ($this) {
      self::Response, self::Conversation, self::Meeting, self::Sql => 'avance',
      self::Opportunity, self::Won => 'logro',
      self::Lost, self::Disqualified => 'cierre',
      default => 'neutro',
    };
  }

}
