<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Authorization;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\sales_leadership_diagnostic\DTO\AccessDecision;
use Drupal\sales_leadership_diagnostic\Exception\WordPressUnavailableException;
use Drupal\sales_leadership_diagnostic\Service\WordPress\PluginVersionTracker;
use Drupal\sales_leadership_diagnostic\Service\WordPress\WordPressApiClient;

/**
 * Obtiene el derecho de acceso consultando a LearnDash vía WordPress.
 *
 * La clase es fina a propósito: toda la complejidad de LearnDash vive en el
 * plugin del otro lado, que es donde tiene sentido. Aquí solo se traduce una
 * respuesta HTTP a una decisión del dominio.
 */
final class LearnDashAccessProvider implements CourseAccessProviderInterface {

  public function __construct(
    private readonly WordPressApiClient $client,
    private readonly TimeInterface $time,
    private readonly PluginVersionTracker $versions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function checkAccess(string $externalUserId, string $courseId): AccessDecision {
    if (trim($externalUserId) === '' || trim($courseId) === '') {
      // Una consulta sin datos no llega a salir: no hay nada que preguntar y
      // la respuesta correcta es denegar.
      return new AccessDecision(
        granted: FALSE,
        courseId: $courseId,
        checkedAt: $this->time->getRequestTime(),
      );
    }

    $response = $this->client->requestAccess($externalUserId, $courseId);

    // Se anota antes de validar el resto: aunque la respuesta resulte
    // inservible, saber QUÉ versión la produjo es justo lo que explica por qué.
    $this->versions->record($response['plugin_version'] ?? NULL);

    if (!is_bool($response['has_access'])) {
      // El contrato dice booleano. Un valor de otro tipo significa que las dos
      // partes han dejado de entenderse, y adivinar sería peligroso: un "0"
      // como cadena es truthy en PHP y concedería acceso indebidamente.
      throw new WordPressUnavailableException('WordPress devolvió un valor de acceso que no es booleano.');
    }

    return new AccessDecision(
      granted: $response['has_access'],
      // El curso lo dice WordPress: con varios cursos autorizadores, el que
      // concedió el acceso puede no ser el que Drupal tenga configurado.
      courseId: (string) ($response['course_id'] ?? $courseId),
      checkedAt: $this->time->getRequestTime(),
      expiresAt: $this->parseTimestamp($response['expires_at'] ?? NULL),
      startedAt: $this->parseTimestamp($response['started_at'] ?? NULL),
      // Llega desde la 1.2.0 del plugin. Un plugin anterior no lo envía y la
      // lista queda vacía: AccessDecision::getOwnedCourses() cae entonces en
      // el curso único, de modo que actualizar Drupal antes que WordPress no
      // deja a nadie sin agentes.
      ownedCourses: is_array($response['owned_courses'] ?? NULL)
        ? array_map('strval', $response['owned_courses'])
        : [],
    );
  }

  /**
   * Interpreta una fecha ISO 8601 de las que devuelve WordPress.
   *
   * Una fecha ilegible o ausente se trata como «desconocida» y no como error:
   * el acceso ya se concedió, y bloquearlo por no poder interpretar un dato
   * accesorio sería desproporcionado. Un plugin de una versión anterior, que
   * todavía no envía started_at, cae por aquí y sigue funcionando.
   */
  private function parseTimestamp(mixed $value): ?int {
    if (!is_string($value) || trim($value) === '') {
      return NULL;
    }

    $timestamp = strtotime($value);

    return $timestamp === FALSE ? NULL : $timestamp;
  }

}
