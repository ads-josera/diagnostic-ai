<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\sales_leadership_diagnostic\Entity\StudentMemoryInterface;
use Drupal\sales_leadership_diagnostic\Service\Memory\StudentMemoryStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Deja que el alumno olvide lo que el sistema recuerda de él.
 *
 * Es la salida cuando la extracción se equivoca. La memoria la escribe un
 * modelo leyendo una conversación, así que puede recordar mal, y sin una
 * forma de quitarlo el alumno se encontraría al agente insistiendo en un dato
 * falso sin poder hacer nada.
 *
 * No hay pantalla de confirmación. Lo que se borra es un párrafo que el
 * sistema escribió sobre él, no su diagnóstico ni su historial: el coste de
 * equivocarse es que lo vuelva a contar en la conversación siguiente.
 */
final class StudentMemoryController extends ControllerBase {

  public function __construct(
    private readonly StudentMemoryStore $memory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(StudentMemoryStore::class));
  }

  /**
   * Olvida un hecho concreto.
   *
   * Quién puede hacerlo NO se decide aquí: la ruta exige `_entity_access` de
   * borrado sobre la entidad, y ese handler solo se lo permite a su dueño.
   * Comprobarlo también en el controlador daría dos sitios donde equivocarse.
   */
  public function forget(StudentMemoryInterface $sld_student_memory): RedirectResponse {
    $tema = $sld_student_memory->getTopic();
    $sld_student_memory->delete();

    $this->messenger()->addStatus($this->t('Se ha olvidado lo que recordábamos sobre @tema.', [
      '@tema' => $tema?->label() ?? $this->t('ese tema'),
    ]));

    return $this->volverAlPanel();
  }

  /**
   * Olvida toda la memoria del alumno en curso.
   *
   * Trabaja siempre sobre la cuenta que hace la petición, nunca sobre un
   * identificador de la URL: así no existe forma de pedir el borrado de la
   * memoria de otra persona.
   */
  public function forgetAll(): RedirectResponse {
    $borrados = $this->memory->forgetAll((int) $this->currentUser()->id());

    $this->messenger()->addStatus($this->formatPlural(
      $borrados,
      'Se ha olvidado el dato que recordábamos de ti.',
      'Se han olvidado los @count datos que recordábamos de ti.',
    ));

    return $this->volverAlPanel();
  }

  /**
   * Devuelve al alumno a su panel.
   */
  private function volverAlPanel(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('sales_leadership_diagnostic.dashboard')->toString(),
    );
  }

}
