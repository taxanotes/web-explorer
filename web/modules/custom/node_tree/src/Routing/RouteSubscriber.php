<?php

declare(strict_types=1);

namespace Drupal\node_tree\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Drupal\node_tree\NodeTree;
use Symfony\Component\Routing\RouteCollection;

/**
 * Gives the tree page routes the path of node_tree.settings:path.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  public function __construct(private readonly NodeTree $tree) {}

  /**
   * {@inheritdoc}
   */
  protected function alterRoutes(RouteCollection $collection): void {
    $path = '/' . $this->tree->path();
    $collection->get('node_tree.page')?->setPath($path);
    $collection->get('node_tree.page_node')?->setPath($path . '/node/{id}');
  }

}
