<?php

declare(strict_types=1);

namespace Drupal\sales_leadership_diagnostic\Service\Branding;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;

/**
 * Contenido de la portada del sitio.
 *
 * La portada no se parece al resto del módulo a propósito: ocupa la pantalla
 * entera, sin la cabecera ni el pie del tema de Drupal. Es lo primero que ve
 * quien llega por un enlace suelto, y con el marco genérico parecía una
 * instalación a medio terminar en lugar del sitio de un producto.
 *
 * Su contenido —imágenes y textos— se administra por completo. La maquetación
 * no: esa vive en la plantilla y en la hoja de estilos, que es donde puede
 * mantenerse. Abrirla también habría convertido esto en un editor de páginas
 * a medias.
 *
 * El color principal NO se configura aquí: sale de la paleta de Marca, para
 * que el azul de la marca se defina en un solo sitio. Los dos que sí guarda
 * —el acento y la banda del pie— no tienen equivalente en esa paleta y son
 * propios de esta página.
 */
final class HomePage {

  /**
   * Nombre del objeto de configuración.
   */
  public const CONFIG_NAME = 'sales_leadership_diagnostic.home';

  /**
   * Color hexadecimal de tres o seis dígitos.
   */
  public const COLOR_PATTERN = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

  /**
   * Valores por defecto de los dos colores propios de la portada.
   *
   * @var array<string, string>
   */
  public const DEFAULT_COLORS = [
    'accent_color' => '#c9fc63',
    'band_color' => '#f4f0e5',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * URL de la imagen de fondo, o NULL si no hay ninguna.
   */
  public function getBackgroundUrl(): ?string {
    return $this->fileUrl('background_fid');
  }

  /**
   * URL del logotipo de la cabecera, pensado para fondo oscuro.
   */
  public function getHeaderLogoUrl(): ?string {
    return $this->fileUrl('logo_light_fid');
  }

  /**
   * URL del logotipo del pie, pensado para fondo claro.
   */
  public function getFooterLogoUrl(): ?string {
    return $this->fileUrl('logo_color_fid');
  }

  /**
   * Titular de la tarjeta.
   */
  public function getTitle(): ?string {
    return $this->text('title');
  }

  /**
   * Texto de presentación.
   */
  public function getIntro(): ?string {
    return $this->text('intro');
  }

  /**
   * Etiqueta del botón principal.
   *
   * Nunca vacía: un botón sin texto es un rectángulo que nadie sabe para qué
   * sirve, así que se recurre a un valor de reserva en lugar de ocultarlo.
   */
  public function getButtonLabel(): string {
    return $this->text('button_label') ?? 'Ir a mi cuenta';
  }

  /**
   * Aclaración en letra pequeña, bajo el botón.
   */
  public function getHelpText(): ?string {
    return $this->text('help_text');
  }

  /**
   * Color de acento, para el botón de acceso de la cabecera.
   */
  public function getAccentColor(): string {
    return $this->color('accent_color');
  }

  /**
   * Color de la banda del pie.
   */
  public function getBandColor(): string {
    return $this->color('band_color');
  }

  /**
   * Etiquetas de cache de la configuración que consulta.
   *
   * @return string[]
   *   Etiquetas de cache.
   */
  public function getCacheTags(): array {
    return $this->config()->getCacheTags();
  }

  /**
   * Lee un color válido, o el de reserva.
   *
   * Se valida al leer y no solo al guardar porque estos valores acaban dentro
   * de un atributo `style`: la configuración también se cambia por drush y se
   * importa desde archivos, caminos que no pasan por el formulario.
   *
   * @param string $key
   *   Clave del color.
   */
  private function color(string $key): string {
    $value = trim((string) $this->config()->get($key));

    return preg_match(self::COLOR_PATTERN, $value) === 1
      ? $value
      : self::DEFAULT_COLORS[$key];
  }

  /**
   * Lee un texto, tratando el vacío como ausencia.
   *
   * @param string $key
   *   Clave del texto.
   */
  private function text(string $key): ?string {
    $value = trim((string) $this->config()->get($key));

    return $value === '' ? NULL : $value;
  }

  /**
   * Resuelve la URL de un archivo guardado por su identificador.
   *
   * @param string $key
   *   Clave que guarda el identificador del archivo.
   */
  private function fileUrl(string $key): ?string {
    $fid = $this->config()->get($key);

    if (!is_numeric($fid) || (int) $fid <= 0) {
      return NULL;
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $fid);

    // El archivo pudo borrarse desde la administración sin pasar por el
    // formulario. Una imagen rota en la portada es la peor primera impresión
    // posible, así que se prefiere no pintarla.
    if (!$file instanceof FileInterface) {
      return NULL;
    }

    return $this->fileUrlGenerator->generateString($file->getFileUri());
  }

  /**
   * Configuración de la portada.
   */
  private function config() {
    return $this->configFactory->get(self::CONFIG_NAME);
  }

}
