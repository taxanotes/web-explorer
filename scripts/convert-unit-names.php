<?php

/**
 * @file
 * Converts an instance built before 2026-11-05 to field_unit_names.
 *
 * Until 2026-11-05 the taxon type carried Scratchpads' unit fields one to
 * one: field_unit_indicator_1..4 and field_unit_name_1..4. They are now one
 * multi-value custom field, field_unit_names (recipes/taxanotes_taxon). An
 * instance built before that keeps the old fields, which no recipe or
 * migration touches any more, and its /taxa view predates the "Unit names"
 * column (a recipe never changes config that already exists). This script
 * deletes the eight fields, purges their data and reloads views.view.taxa
 * from the recipe's config directory. The rest of the conversion is the
 * ordinary tooling:
 *
 *   drush php:script scripts/convert-unit-names.php
 *   drush recipe recipes/taxanotes_taxon
 *   drush migrate:import taxanotes_d7_taxon_revision --update
 *   drush migrate:import taxanotes_d7_taxon --update
 *
 * (each with --uri=https://<hostname> of the instance).
 *
 * The recipe adds field_unit_names with its widget and display; the revision
 * import fills it on every revision (the default one included) and the taxon
 * import then finds nothing changed, so no sync revision is created and
 * node changed times stay as they are. The other order would create one
 * sync revision per node. Safe to run again: fields already gone are
 * skipped, the view is reloaded.
 */

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldStorageConfig;

$names = [];
foreach (['field_unit_indicator_', 'field_unit_name_'] as $prefix) {
  for ($i = 1; $i <= 4; $i++) {
    $names[] = $prefix . $i;
  }
}
$deleted = 0;
foreach ($names as $name) {
  $storage = FieldStorageConfig::loadByName('node', $name);
  if (!$storage) {
    echo "$name: not present\n";
    continue;
  }
  $storage->delete();
  $deleted++;
  echo "$name: deleted\n";
}

// A deleted field keeps its data in renamed tables until it is purged (cron
// does it a batch at a time); purge now, in batches, until nothing is left.
$repository = \Drupal::service('entity_field.deleted_fields_repository');
$rounds = 0;
while ($repository->getFieldStorageDefinitions() && $rounds < 1000) {
  field_purge_batch(10000);
  $rounds++;
}
$left = count($repository->getFieldStorageDefinitions());
echo "$deleted field(s) deleted, purged in $rounds round(s), $left deleted field storage(s) left\n";

// The /taxa view as the recipe ships it (with the "Unit names" column).
$source = new FileStorage(DRUPAL_ROOT . '/../recipes/taxanotes_taxon/config');
$data = $source->read('views.view.taxa');
if (!$data) {
  throw new \RuntimeException('views.view.taxa not found in recipes/taxanotes_taxon/config');
}
$storage = \Drupal::entityTypeManager()->getStorage('view');
$view = $storage->load('taxa');
if ($view) {
  $storage->updateFromStorageRecord($view, $data);
}
else {
  $view = $storage->createFromStorageRecord($data);
}
$view->save();
echo "views.view.taxa reloaded from the recipe\n";
