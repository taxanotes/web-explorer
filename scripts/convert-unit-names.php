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
 * ordinary tooling, in this order (each command with
 * --uri=https://<hostname> of the instance):
 *
 *   ddev export-db --database=<db> --file=<somewhere>/before.sql.gz
 *   drush recipe recipes/taxanotes_taxon
 *   drush migrate:import taxanotes_d7_taxon_revision --update
 *   drush migrate:import taxanotes_d7_taxon --update
 *   drush php:script scripts/convert-unit-names.php
 *
 * The recipe adds field_unit_names with its widget and display beside the
 * old fields (they coexist); the revision import fills it on every revision
 * (the default one included) and the taxon import then finds nothing
 * changed, so no sync revision is created and node changed times stay as
 * they are. The other order of the two imports would create one sync
 * revision per node. This script runs last, so the old values are still in
 * the database until the new field is filled: an interrupted import leaves
 * nothing lost. Safe to run again: fields already gone are skipped, the view
 * is reloaded.
 *
 * Run the imports in the foreground (or under nohup): a process killed
 * mid-import leaves the migration "Importing", with the rows it did not
 * reach marked needs-update. Recovery: drush migrate:reset-status <id>,
 * then the same migrate:import ... --update again (it resumes the rows
 * still marked, finished rows are hashed and skipped); the snapshot is the
 * fallback (ddev import-db --database=<db> --file=before.sql.gz). On a large
 * instance run the two imports one at a time and nothing else against the
 * Drupal 7 database meanwhile (the revision import holds the whole source
 * result set, and the D7 MySQL container has been OOM-killed under load).
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
