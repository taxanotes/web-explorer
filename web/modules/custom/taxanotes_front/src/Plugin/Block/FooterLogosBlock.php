<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The row of partner logos in the footer.
 *
 * The Scratchpads footer showed the logos of the partners behind the site
 * (Scratchpads, the Natural History Museum, ViBRANT ...). The platform ships
 * none (the rights are the instance's): the row is driven by
 * taxanotes_front.settings:footer_logos, a list of {file, url, alt} that the
 * instance scripts fill from the png/svg files in the instance's
 * assets/footer/ directory (copied to public://instance/footer/) and the
 * optional links and alt texts of instance.yml site.footer_logos. A file
 * that is not in the files directory is skipped; no logo, no row.
 */
#[Block(
  id: 'taxanotes_front_footer_logos',
  admin_label: new TranslatableMarkup('Footer logos'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class FooterLogosBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly ConfigFactoryInterface $configFactory, private readonly FileUrlGeneratorInterface $fileUrlGenerator) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('config.factory'), $container->get('file_url_generator'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->configFactory->get('taxanotes_front.settings');
    $metadata = (new CacheableMetadata())->addCacheableDependency($config);
    $logos = [];
    foreach ((array) $config->get('footer_logos') as $logo) {
      $file = trim((string) ($logo['file'] ?? ''));
      // A relative path under the public files directory, no traversal.
      if ($file === '' || str_contains($file, '..') || !preg_match('#^[A-Za-z0-9_./ -]+\.(png|svg)$#i', $file)) {
        continue;
      }
      $uri = 'public://' . ltrim($file, '/');
      if (!is_file($uri)) {
        continue;
      }
      $url = trim((string) ($logo['url'] ?? ''));
      $logos[] = [
        'src' => $this->fileUrlGenerator->generateString($uri),
        'alt' => trim((string) ($logo['alt'] ?? '')) ?: pathinfo($file, PATHINFO_FILENAME),
        'url' => $url !== '' ? Url::fromUri($url)->toString() : '',
      ];
    }
    $build = [];
    if ($logos) {
      $build = [
        '#theme' => 'taxanotes_front_footer_logos',
        '#logos' => $logos,
        '#attached' => ['library' => ['taxanotes_front/front']],
      ];
    }
    $metadata->applyTo($build);
    return $build;
  }

}
