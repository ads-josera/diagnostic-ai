<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Lo que dijo el Buyer sobre la hipótesis.
 *
 * Es la «Buyer Truth Taxonomy v1.1» del §7 de su Documento 8. Va aparte del
 * estado de ejecución porque su metodología separa las dos cosas a propósito:
 * «REPLY ≠ GAP CONFIRMED. SILENCE ≠ GAP REJECTED». Una cuenta puede haber
 * respondido y aun así haber rechazado la hipótesis.
 *
 * Es opcional. Su §11 pide «one label + optional sentence», y obligar a
 * clasificar cada contacto convertiría el registro en el formulario que su
 * metodología dice que no hay que hacer.
 */
enum BuyerTruth: string {

  case GapConfirmed = 'GAP_CONFIRMED';
  case GapReframed = 'GAP_REFRAMED';
  case GapRejected = 'GAP_REJECTED';
  case AlreadySolved = 'ALREADY_SOLVED';
  case WrongBuyer = 'WRONG_BUYER';
  case Referral = 'REFERRAL';
  case TimingLater = 'TIMING_LATER';
  case NoFit = 'NO_FIT';
  case Competitor = 'COMPETITOR';
  case DoNotContact = 'DO_NOT_CONTACT';

  /**
   * El nombre que lee el alumno.
   */
  public function label(): TranslatableMarkup {
    return match ($this) {
      self::GapConfirmed => new TranslatableMarkup('Confirmó el GAP'),
      self::GapReframed => new TranslatableMarkup('El problema es otro'),
      self::GapRejected => new TranslatableMarkup('Rechazó el GAP'),
      self::AlreadySolved => new TranslatableMarkup('Ya lo tienen resuelto'),
      self::WrongBuyer => new TranslatableMarkup('Buyer equivocado'),
      self::Referral => new TranslatableMarkup('Nos refirió a otra persona'),
      self::TimingLater => new TranslatableMarkup('Más adelante'),
      self::NoFit => new TranslatableMarkup('Sin encaje'),
      self::Competitor => new TranslatableMarkup('Competidor o solución interna'),
      self::DoNotContact => new TranslatableMarkup('No contactar'),
    };
  }

  /**
   * El nombre exacto de su metodología, para el historial del agente.
   */
  public function clientLabel(): string {
    return match ($this) {
      self::NoFit => 'NO FIT / NO NEED',
      self::Competitor => 'COMPETITOR / INTERNAL SOLUTION',
      default => str_replace('_', ' ', $this->value),
    };
  }

}
