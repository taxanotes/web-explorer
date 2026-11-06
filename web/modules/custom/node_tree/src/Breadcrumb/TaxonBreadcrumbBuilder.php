<?php

declare(strict_types=1);

namespace Drupal\node_tree\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * The lineage of a taxon as its breadcrumb.
 *
 * On a taxon page: Home › root › … › parent › the taxon (the last item is
 * the current page, as text), what the Scratchpads taxonomy_breadcrumb_lite
 * showed. On a species page (a node that names one taxon in
 * field_taxonomic_name): Home › the taxon's lineage › the page, the page
 * left out when its title is the taxon's name (the usual case). The chain is
 * read from field_parent_taxon_reference up to the root (cycle-safe) and
 * cached with the route, the tags of every node in it and the access
 * results of the links (an ancestor the visitor may not view is named, not
 * linked), so a renamed or moved ancestor refreshes the trail.
 */
final class TaxonBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    if ($cacheable_metadata) {
      $cacheable_metadata->addCacheContexts(['route']);
    }
    return $route_match->getRouteName() === 'entity.node.canonical' && $this->taxonOf($route_match->getParameter('node'), $cacheable_metadata) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $node = $route_match->getParameter('node');
    $taxon = $this->taxonOf($node, $breadcrumb);
    $links = [Link::createFromRoute($this->t('Home'), '<front>')];
    if ($node instanceof NodeInterface && $taxon instanceof NodeInterface) {
      $breadcrumb->addCacheableDependency($node);
      // The lineage, root first; the taxon itself is linked only when the
      // page is a species page (then the page is the current item).
      $chain = [];
      $seen = [];
      for ($current = $taxon; $current instanceof NodeInterface && !isset($seen[$current->id()]); $current = $current->get('field_parent_taxon_reference')->entity) {
        $seen[$current->id()] = TRUE;
        $chain[] = $current;
      }
      foreach (array_reverse($chain) as $ancestor) {
        $breadcrumb->addCacheableDependency($ancestor);
        if ($ancestor->id() === $node->id()) {
          $links[] = Link::createFromRoute($ancestor->label(), '<none>');
          continue;
        }
        // An ancestor the visitor may not view (unpublished) is named, not
        // linked; the access result is part of the cacheability, so the
        // trail is not shared between visitors who see different things.
        $access = $ancestor->access('view', NULL, TRUE);
        $breadcrumb->addCacheableDependency($access);
        $links[] = $access->isAllowed()
          ? $ancestor->toLink($ancestor->label())
          : Link::createFromRoute($ancestor->label(), '<none>');
      }
      if ($taxon->id() !== $node->id() && mb_strtolower(trim((string) $node->label())) !== mb_strtolower(trim((string) $taxon->label()))) {
        $links[] = Link::createFromRoute($node->label(), '<none>');
      }
    }
    $breadcrumb->setLinks($links);
    return $breadcrumb;
  }

  /**
   * The taxon a node stands for: itself, or the one it describes.
   *
   * The described taxon counts only when the visitor may view it; that
   * access result is added to $cacheability when given, so the breadcrumb
   * built on it varies as the access does.
   */
  private function taxonOf(mixed $node, ?RefinableCacheableDependencyInterface $cacheability = NULL): ?NodeInterface {
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    if ($node->bundle() === 'taxon') {
      return $node;
    }
    if ($node->hasField('field_taxonomic_name')) {
      $taxon = $node->get('field_taxonomic_name')->entity;
      if ($taxon instanceof NodeInterface && $taxon->bundle() === 'taxon') {
        $access = $taxon->access('view', NULL, TRUE);
        $cacheability?->addCacheableDependency($access);
        if ($access->isAllowed()) {
          return $taxon;
        }
      }
    }
    return NULL;
  }

}
