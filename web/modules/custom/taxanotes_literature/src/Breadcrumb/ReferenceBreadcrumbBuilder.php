<?php

declare(strict_types=1);

namespace Drupal\taxanotes_literature\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * The breadcrumb of a reference page: Home › Literature › the reference.
 *
 * "Literature" is the page display of the taxanotes_literature view
 * (/literature) when the instance has it; the title of the reference is the
 * current item (as text). Cached with the route and the reference.
 */
final class ReferenceBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function __construct(private readonly RouteProviderInterface $routeProvider) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    if ($cacheable_metadata) {
      $cacheable_metadata->addCacheContexts(['route']);
    }
    return $route_match->getRouteName() === 'entity.bibcite_reference.canonical';
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $links = [Link::createFromRoute($this->t('Home'), '<front>')];
    if ($this->routeProvider->getRoutesByNames(['view.taxanotes_literature.page'])) {
      $links[] = Link::createFromRoute($this->t('Literature'), 'view.taxanotes_literature.page');
    }
    $reference = $route_match->getParameter('bibcite_reference');
    if ($reference instanceof EntityInterface) {
      $breadcrumb->addCacheableDependency($reference);
      $links[] = Link::createFromRoute($reference->label(), '<none>');
    }
    $breadcrumb->setLinks($links);
    return $breadcrumb;
  }

}
