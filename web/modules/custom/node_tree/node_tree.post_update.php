<?php

/**
 * @file
 * Post-update functions of the node_tree module.
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;

/**
 * Adds node_tree.settings to a site that enabled the module before 2.0.
 *
 * The 1.x module had no settings (it hard-coded its content type and
 * fields); 2.0 reads everything from node_tree.settings, which a fresh
 * install gets from config/install. A site that already had the module
 * enabled gets the default settings here, and then configures them at
 * /admin/config/content/node-tree (the tree is empty until a node type is
 * chosen).
 */
function node_tree_post_update_install_settings(): string {
  $config = \Drupal::configFactory()->getEditable('node_tree.settings');
  if (!$config->isNew()) {
    return 'node_tree.settings exists: nothing to do.';
  }
  $path = \Drupal::service('extension.list.module')->getPath('node_tree') . '/config/install';
  $data = (new FileStorage($path))->read('node_tree.settings');
  if (!is_array($data)) {
    return 'The default node_tree.settings could not be read from ' . $path . '.';
  }
  $config->setData($data)->save();
  return 'node_tree.settings created with the defaults: choose the node types and fields at /admin/config/content/node-tree.';
}
