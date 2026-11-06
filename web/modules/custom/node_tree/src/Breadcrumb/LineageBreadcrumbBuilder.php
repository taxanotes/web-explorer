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
use Drupal\node_tree\NodeTree;

/**
 * The lineage of a tree node as its breadcrumb.
 *
 * On a tree node's page: Home › root › … › parent › the node (the last
 * item is the current page, as text). On the page of a node that describes
 * a tree node (node_tree.settings:describes_field): Home › the tree node's
 * lineage › the page, the page left out when its title is the tree node's
 * name. The chain is read from the parent field up to the root (cycle-safe)
 * and cached with the route, the settings, the tags of every node in it and
 * the access results of the links (an ancestor the visitor may not view is
 * named, not linked), so a renamed or moved ancestor refreshes the trail.
 */
final class LineageBreadcrumbBuilder implements BreadcrumbBuilderInterface {

  use StringTranslationTrait;

  public function __construct(private readonly NodeTree $tree) {}

  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match, ?CacheableMetadata $cacheable_metadata = NULL): bool {
    if ($cacheable_metadata) {
      $cacheable_metadata->addCacheContexts(['route']);
      $cacheable_metadata->addCacheableDependency($this->tree->settings());
    }
    return $this->tree->breadcrumbEnabled()
      && $route_match->getRouteName() === 'entity.node.canonical'
      && $this->treeNodeOf($route_match->getParameter('node'), $cacheable_metadata) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addCacheContexts(['route']);
    $breadcrumb->addCacheableDependency($this->tree->settings());
    $node = $route_match->getParameter('node');
    $tree_node = $this->treeNodeOf($node, $breadcrumb);
    $links = [Link::createFromRoute($this->t('Home'), '<front>')];
    if ($node instanceof NodeInterface && $tree_node instanceof NodeInterface) {
      $breadcrumb->addCacheableDependency($node);
      // The lineage, root first; the tree node itself is linked only when
      // the page describes it (then the page is the current item).
      foreach ($this->tree->lineage($tree_node) as $ancestor) {
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
      if ($tree_node->id() !== $node->id() && mb_strtolower(trim((string) $node->label())) !== mb_strtolower(trim((string) $tree_node->label()))) {
        $links[] = Link::createFromRoute($node->label(), '<none>');
      }
    }
    $breadcrumb->setLinks($links);
    return $breadcrumb;
  }

  /**
   * The tree node a page stands for: itself, or the one it describes.
   *
   * The described node counts only when the visitor may view it; that
   * access result is added to $cacheability when given, so the breadcrumb
   * built on it varies as the access does.
   */
  private function treeNodeOf(mixed $node, ?RefinableCacheableDependencyInterface $cacheability = NULL): ?NodeInterface {
    $tree_node = $this->tree->treeNodeOf($node);
    if ($tree_node === NULL || $tree_node === $node) {
      return $tree_node;
    }
    $access = $tree_node->access('view', NULL, TRUE);
    $cacheability?->addCacheableDependency($access);
    return $access->isAllowed() ? $tree_node : NULL;
  }

}
