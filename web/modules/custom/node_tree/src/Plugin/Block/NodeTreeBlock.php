<?php

declare(strict_types=1);

namespace Drupal\node_tree\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The lazily loaded taxon tree as a block.
 *
 * On a taxon page the tree opens with the lineage of the current taxon
 * expanded and the taxon selected and scrolled into view (the Scratchpads
 * tinytax block), the rest still loading on demand; elsewhere it starts at
 * the roots. The GUID to open at travels in drupalSettings.nodeTree.selected
 * (js/node_tree.js); the block varies by route for it.
 */
#[Block(
  id: 'block-node-tree-hierarchicaltaxontree',
  admin_label: new TranslatableMarkup('NodeTreeBlock'),
  category: new TranslatableMarkup('Custom'),
)]
final class NodeTreeBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly RouteMatchInterface $routeMatch) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('current_route_match'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $build = [
      '#theme' => 'node-tree-hierarchy-tree-block',
      '#attached' => [
        'library' => [
          'node_tree/node_tree',
        ],
      ],
      '#data' => [
        'title' => $this->t('Hierarchy'),
      ],
      '#cache' => [
        'max-age' => 3600,
        'contexts' => ['route'],
        'tags' => ['node_list:taxon'],
      ],
    ];
    $node = $this->routeMatch->getRouteName() === 'entity.node.canonical' ? $this->routeMatch->getParameter('node') : NULL;
    if ($node instanceof NodeInterface && $node->bundle() === 'taxon' && $node->isPublished() && $node->hasField('field_guid')) {
      $guid = (string) $node->get('field_guid')->value;
      if ($guid !== '') {
        $build['#attached']['drupalSettings']['nodeTree']['selected'] = $guid;
      }
    }
    return $build;
  }

}
