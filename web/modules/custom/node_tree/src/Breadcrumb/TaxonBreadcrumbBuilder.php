<?php

declare(strict_types=1);

namespace Drupal\node_tree\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Cache\CacheableMetadata;
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
 * cached with the route and the tags of every node in it, so a renamed or
 * moved ancestor refreshes the trail.
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
    return $route_match->getRouteName() === 'entity.node.canonical' && $this->taxonOf($route_match->getParameter('node')) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $node = $route_match->getParameter('node');
    $taxon = $this->taxonOf($node);
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
        $links[] = $ancestor->id() === $node->id()
          ? Link::createFromRoute($ancestor->label(), '<none>')
          : $ancestor->toLink($ancestor->label());
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
   */
  private function taxonOf(mixed $node): ?NodeInterface {
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    if ($node->bundle() === 'taxon') {
      return $node;
    }
    if ($node->hasField('field_taxonomic_name')) {
      $taxon = $node->get('field_taxonomic_name')->entity;
      if ($taxon instanceof NodeInterface && $taxon->bundle() === 'taxon' && $taxon->access('view')) {
        return $taxon;
      }
    }
    return NULL;
  }

}
