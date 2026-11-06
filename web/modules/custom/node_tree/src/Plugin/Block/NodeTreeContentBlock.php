<?php

declare(strict_types=1);

namespace Drupal\node_tree\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The panel the selected node is rendered into.
 *
 * An empty container that js/node_tree.js fills with the node selected in a
 * Node tree block on the same page (the tree page has its own panel). With
 * both blocks placed, a site composes a tree page of its own layout.
 */
#[Block(
  id: 'node_tree_content',
  admin_label: new TranslatableMarkup('Node tree: selected node'),
  category: new TranslatableMarkup('Node tree'),
)]
final class NodeTreeContentBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme' => 'node_tree_content_block',
      '#attached' => ['library' => ['node_tree/node_tree']],
    ];
  }

}
