<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;

/**
 * The front page and the About page: texts from taxanotes_front.settings.
 *
 * Both are placeholders for pages the instance will edit (or replace with
 * migrated Scratchpads pages); the texts are seeded by the taxanotes_front
 * recipe from instance.yml and can be changed at
 * /admin/config/system/taxanotes-front.
 */
final class FrontController extends ControllerBase {

  /**
   * The front page body: the introduction text.
   */
  public function front(): array {
    return $this->textPage('front_intro', 'taxanotes-front');
  }

  /**
   * The front page title: the configured one, or "Welcome to <site name>".
   */
  public function frontTitle(): string {
    $title = (string) $this->config('taxanotes_front.settings')->get('front_title');
    if ($title !== '') {
      return $title;
    }
    return (string) $this->t('Welcome to @site', ['@site' => $this->config('system.site')->get('name')]);
  }

  /**
   * The About page body.
   */
  public function about(): array {
    return $this->textPage('about_body', 'taxanotes-about');
  }

  /**
   * The About page title.
   */
  public function aboutTitle(): string {
    $title = (string) $this->config('taxanotes_front.settings')->get('about_title');
    return $title !== '' ? $title : (string) $this->t('About');
  }

  /**
   * Renders one configured text as a page.
   */
  private function textPage(string $key, string $class): array {
    $config = $this->config('taxanotes_front.settings');
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => [$class, 'taxanotes-text']],
      'text' => [
        // #markup is filtered with Xss::filterAdmin() by the renderer.
        '#markup' => (string) $config->get($key),
      ],
      '#attached' => ['library' => ['taxanotes_front/front']],
    ];
    $metadata = CacheableMetadata::createFromObject($config);
    // The title callbacks read system.site too.
    $metadata->addCacheableDependency($this->config('system.site'));
    $metadata->applyTo($build);
    return $build;
  }

}
