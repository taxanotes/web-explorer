<?php

declare(strict_types=1);

namespace Drupal\node_tree\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\node_tree\NodeTree;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The lazily loaded tree as a block.
 *
 * On a tree node's page the tree opens with the lineage of the current node
 * expanded and the node selected and scrolled into view, the rest still
 * loading on demand; elsewhere it starts at the roots. The identifier to
 * open at travels in drupalSettings.nodeTree.selected (js/node_tree.js);
 * the block varies by route for it. Selecting a node in the block opens its
 * permalink on the tree page.
 */
#[Block(
  id: 'node_tree',
  admin_label: new TranslatableMarkup('Node tree'),
  category: new TranslatableMarkup('Node tree'),
)]
final class NodeTreeBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected NodeTree $tree,
    protected RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('node_tree'), $container->get('current_route_match'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [
      '#cache' => [
        'contexts' => ['route'],
        'tags' => $this->tree->cacheTags(),
      ],
    ];
    if (!$this->tree->isConfigured()) {
      return $build;
    }
    $build += [
      '#theme' => 'node_tree_block',
      '#attached' => [
        'library' => ['node_tree/node_tree'],
        'drupalSettings' => ['nodeTree' => ['path' => $this->tree->path()]],
      ],
    ];
    $node = $this->routeMatch->getRouteName() === 'entity.node.canonical' ? $this->routeMatch->getParameter('node') : NULL;
    if ($node instanceof NodeInterface && $node->isPublished() && $this->tree->isTreeNode($node)) {
      $id = $this->tree->idOf($node);
      if ($id !== '') {
        $build['#attached']['drupalSettings']['nodeTree']['selected'] = $id;
      }
    }
    return $build;
  }

}
