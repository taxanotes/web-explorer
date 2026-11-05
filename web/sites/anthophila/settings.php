<?php

// phpcs:ignoreFile

/**
 * @file
 * Settings of a Taxanotes instance site (web/sites/<subdir>/settings.php).
 *
 * Identical for every instance: scripts/new-instance.sh copies it from
 * scripts/templates/settings.php when it creates the site directory and never
 * edits it afterwards. Everything that differs between instances (database,
 * hash salt, trusted hosts, the Scratchpads source) lives in the gitignored
 * settings.local.php next to it, generated from instances/<slug>/instance.yml
 * and instances/<slug>/.env.
 */

$settings['update_free_access'] = FALSE;
$settings['file_scan_ignore_directories'] = [
  'node_modules',
  'bower_components',
];
$settings['entity_update_batch_size'] = 50;
$settings['entity_update_backup'] = TRUE;
$settings['state_cache'] = TRUE;

// Config export directory, per site and outside the docroot. Taxanotes keeps
// no per-instance config export in git (an instance is instance.yml + .env +
// data); this is a backup target only, and config/instances/ is gitignored.
$settings['config_sync_directory'] = '../config/instances/' . basename($site_path) . '/sync';

if (file_exists($app_root . '/' . $site_path . '/services.yml')) {
  $settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';
}

// Local development under ddev: development services, no permission
// hardening of the site directory (ddev's own settings.ddev.php does the same
// for sites/default).
if (getenv('IS_DDEV_PROJECT') == 'true') {
  $settings['skip_permissions_hardening'] = TRUE;
  if (file_exists($app_root . '/sites/development.services.yml')) {
    $settings['container_yamls'][] = $app_root . '/sites/development.services.yml';
  }
}

// Instance values and secrets (generated, gitignored).
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
