<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Conversation;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateHelper;

/**
 * El formato de hora del servidor, para que el navegador escriba igual.
 *
 * Los mensajes que vienen del servidor llevan la hora en el formato corto de
 * Drupal —«3 Oct 2026 - 16:47»—, y los que el navegador pinta sin recargar
 * usaban `toLocaleString()`, que formatea según el idioma DEL NAVEGADOR: en
 * uno en inglés salía «10/2/2026, 8:18:14 PM» debajo de otros en español. Lo
 * vio José Raúl el 03-10-2026.
 *
 * No se imita el formato a mano en JavaScript: se le pasa el patrón que usa
 * el servidor y los nombres de mes con la misma traducción. Así, si alguien
 * cambia el formato corto en Drupal, el navegador lo sigue solo.
 */
final class ChatTimeFormat {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Lo que necesita el navegador para escribir la hora como el servidor.
   *
   * @return array{pattern: string, months: string[]}
   *   El patrón del formato corto y los doce meses abreviados, de enero a
   *   diciembre, traducidos con el mismo contexto que usa Drupal al formatear.
   */
  public function settings(): array {
    return [
      'pattern' => (string) ($this->configFactory->get('core.date_format.short')->get('pattern') ?: 'j M Y - H:i'),
      'months' => array_values(array_map('strval', DateHelper::monthNamesAbbr(TRUE))),
    ];
  }

}
