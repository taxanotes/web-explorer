<?php

/**
 * @file
 * Post-update functions of taxanotes_front.
 *
 * Platform changes to configuration the recipes create only once (placed
 * blocks) reach an existing instance through these functions, which
 * `drush updatedb` runs (the instance scripts run it before the recipes).
 */

declare(strict_types=1);

/**
 * The tree blocks use the generic node_tree block plugin.
 *
 * Node_tree 2.0 (the generic module) renamed its block plugin from
 * block-node-tree-hierarchicaltaxontree to node_tree; the blocks the
 * taxanotes_front and taxanotes_classic recipes placed under the old id
 * (in every theme) are pointed at the new one, keeping their region, weight,
 * label and visibility.
 */
function taxanotes_front_post_update_node_tree_block_plugin(): string {
  $factory = \Drupal::configFactory();
  $renamed = [];
  foreach ($factory->listAll('block.block.') as $name) {
    $config = $factory->getEditable($name);
    if ($config->get('plugin') !== 'block-node-tree-hierarchicaltaxontree') {
      continue;
    }
    $config->set('plugin', 'node_tree')->set('settings.id', 'node_tree')->save();
    $renamed[] = substr($name, strlen('block.block.'));
  }
  return $renamed
    ? 'Tree blocks pointed at the node_tree block plugin: ' . implode(', ', $renamed) . '.'
    : 'No block used the old tree block plugin: nothing to do.';
}
