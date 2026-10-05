<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Research;

use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\CurrentTurn;
use Drupal\sales_leadership_diagnostic\Service\Engine\Tool\ToolCallRepository;

/**
 * Comprueba que cada comprador verificado tenga una fuente que lo nombre.
 *
 * El 03-10-2026, en la prueba con la cuenta del cliente, el agente dio por
 * verificado al directivo de un banco con dos fuentes que no lo demostraban:
 * una no lo mencionaba y la otra no traía ni el cargo ni la fecha. Entre sus
 * propios resultados había una página que sí lo nombraba con su cargo, y no la
 * citó. Para quien recibe el Pack, «comprador verificado» es la luz verde para
 * escribirle a esa persona: tiene que estar respaldado.
 *
 * Qué se puede comprobar sin leer como una persona, y es lo que se comprueba:
 * que el agente diga QUÉ fuente lo prueba (`buyer_source`), que esa fuente
 * salga de las búsquedas de la misión y que su texto contenga el nombre de la
 * persona. Que el cargo sea el correcto no se puede afirmar con esa certeza, y
 * por eso no se intenta: cuando el nombre está, se da por bueno.
 *
 * Lo que NO hace es acusar sin poder mirar. Si la página salió de una búsqueda
 * de otro turno, su texto ya no está en memoria: entonces solo se exige que la
 * dirección figure entre lo buscado.
 */
final class BuyerEvidenceCheck {

  /**
   * Palabras que, en el campo `buyer`, indican que no hay persona nombrada.
   */
  private const SIN_PERSONA = '/^\s*(role[\s-]*only|rol\b|solo rol|no aplica|n\/a|pendiente)/iu';

  /**
   * Lo que miró la última comprobación: cuántos verificados y cuántos leídos.
   *
   * Existe porque «ningún hueco» tiene dos lecturas opuestas —que todo está
   * respaldado, o que no había texto que leer y se dio por bueno—, y desde
   * fuera se ven igual. Con esto se puede registrar cuál de las dos fue.
   *
   * @var array{verificados: int, leidos: int}
   */
  private array $ultimo = ['verificados' => 0, 'leidos' => 0];

  public function __construct(
    private readonly CurrentTurn $turn,
    private readonly ToolCallRepository $calls,
    private readonly RetrievedPages $pages,
  ) {}

  /**
   * Las cuentas cuyo comprador verificado no está respaldado, y por qué.
   *
   * @param array<string, mixed> $result
   *   El resultado estructurado del Pack.
   *
   * @return array<int, array{cuenta: string, comprador: string, motivo: string}>
   *   Una entrada por cuenta con problema. Vacío si todo está respaldado.
   */
  public function gaps(array $result): array {
    $sesion = $this->turn->sessionId();
    $buscadas = [];

    foreach ($this->calls->retrievedUrlsInMission($sesion) as $url) {
      $buscadas[UrlKey::of($url)] = TRUE;
    }

    $huecos = [];
    $this->ultimo = ['verificados' => 0, 'leidos' => 0];

    foreach ($result['accounts'] ?? [] as $cuenta) {
      if (!is_array($cuenta) || empty($cuenta['buyer_verified'])) {
        continue;
      }

      $this->ultimo['verificados']++;

      if ($this->pages->textOf($sesion, (string) ($cuenta['buyer_source'] ?? '')) !== NULL) {
        $this->ultimo['leidos']++;
      }

      $nombre = trim((string) ($cuenta['name'] ?? ''));
      $comprador = trim((string) ($cuenta['buyer'] ?? ''));
      $fuente = trim((string) ($cuenta['buyer_source'] ?? ''));
      $motivo = $this->motivo($sesion, $comprador, $fuente, $buscadas, $cuenta['sources'] ?? []);

      if ($motivo !== NULL) {
        $huecos[] = ['cuenta' => $nombre, 'comprador' => $comprador, 'motivo' => $motivo];
      }
    }

    return $huecos;
  }

  /**
   * Cuántos verificados miró la última comprobación, y cuántos leyó.
   *
   * @return array{verificados: int, leidos: int}
   *   Leídos son los que se comprobaron con el texto de su fuente; el resto,
   *   solo por su dirección.
   */
  public function lastStats(): array {
    return $this->ultimo;
  }

  /**
   * Por qué un comprador verificado no está respaldado, o NULL si lo está.
   */
  private function motivo(int $sesion, string $comprador, string $fuente, array $buscadas, mixed $fuentes): ?string {
    if ($fuente === '') {
      return 'no indicaste qué fuente lo prueba (buyer_source vacío)';
    }

    $citadas = array_map(
      static fn ($f) => is_array($f) ? UrlKey::of((string) ($f['url'] ?? '')) : '',
      is_array($fuentes) ? $fuentes : [],
    );

    if (!in_array(UrlKey::of($fuente), $citadas, TRUE)) {
      return 'la fuente que lo prueba no está entre las fuentes de la cuenta';
    }

    $texto = $this->pages->textOf($sesion, $fuente);

    if ($texto === NULL) {
      // Sin su texto en memoria no se puede leer. Basta con que haya salido de
      // una búsqueda de la misión; si ni eso, no tiene origen.
      return isset($buscadas[UrlKey::of($fuente)])
        ? NULL
        : 'la fuente que lo prueba no salió de ninguna búsqueda de esta misión';
    }

    $partes = $this->partesDelNombre($comprador);

    if ($partes === []) {
      // Marcado como verificado sin una persona nombrada: contradictorio.
      return 'está marcado como verificado pero no nombra a ninguna persona';
    }

    $plano = $this->plano($texto);
    $presentes = count(array_filter($partes, static fn (string $p) => str_contains($plano, $p)));

    // Dos partes del nombre, o todas si solo tiene una o dos. Un apellido
    // suelto coincide con demasiada gente; pedir el nombre completo dejaría
    // fuera a quien la prensa nombra con un solo apellido.
    return $presentes >= min(2, count($partes))
      ? NULL
      : 'la fuente que lo prueba no lo nombra';
  }

  /**
   * Las partes del nombre de la persona, sin cargo ni empresa.
   *
   * El campo `buyer` llega como «Roberto Andino — CEO de Tigo Ecuador (P1)»:
   * se toma lo de antes del primer separador.
   *
   * @return string[]
   *   Las palabras del nombre, en minúsculas y sin tildes, de tres letras o
   *   más; vacío si no nombra a una persona.
   */
  private function partesDelNombre(string $comprador): array {
    if ($comprador === '' || preg_match(self::SIN_PERSONA, $comprador)) {
      return [];
    }

    $nombre = preg_split('/\s+[—–-]\s+|,|\(|:|\|/u', $comprador)[0] ?? '';
    $palabras = preg_split('/\s+/u', $this->plano($nombre)) ?: [];

    return array_values(array_filter($palabras, static fn (string $p) => mb_strlen($p) >= 3));
  }

  /**
   * Un texto en minúsculas, sin tildes y con los espacios juntos.
   */
  private function plano(string $texto): string {
    $sinTildes = strtr(mb_strtolower($texto), [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'ñ' => 'n',
      'à' => 'a',
      'è' => 'e',
      'ì' => 'i',
      'ò' => 'o',
      'ù' => 'u',
    ]);

    return trim(preg_replace('/\s+/u', ' ', $sinTildes) ?? $sinTildes);
  }

}
