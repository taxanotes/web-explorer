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

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\FieldStorageConfigInterface;

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

/**
 * The synonymy and designation "by" fields take more than 255 characters.
 *
 * field_synonymy_by and field_designation_by were plain strings (255
 * characters); the Anthophila sheet had three cells of 286, 328 and 504
 * characters that the sheet importer had to cut. From 0.8.0 the two fields
 * are string_long (recipes/taxanotes_taxon creates them so on a new
 * instance). Drupal refuses to change the type of a field that holds data,
 * so on an existing instance each field is rebuilt in place: its current and
 * revision rows are copied to backup tables, the storage deleted and created
 * again as string_long with the same settings, the rows copied back (the new
 * tables have the same names and columns), and the backups dropped. The taxon
 * form shows each as a two-row text area; the view display keeps them hidden
 * (the Nomenclature block renders them).
 *
 * The rows never exist only in memory: until the copy back is complete and
 * counted, they sit in <table>_widen tables and the field's settings in
 * state, so a run that stops anywhere (an exception, a lost connection)
 * is finished by the next `drush updatedb`, which picks up where it left
 * off. Nothing is dropped before the counts match.
 */
function taxanotes_front_post_update_widen_synonymy_designation_by(): string {
  $results = [];
  foreach (['field_synonymy_by', 'field_designation_by'] as $name) {
    $results[] = $name . ': ' . _taxanotes_front_widen_string_field('node', 'taxon', $name);
  }
  return implode(' ', $results);
}

/**
 * Rebuilds a plain string field as string_long, keeping its values.
 *
 * Resumable: the first run copies the rows to backup tables and the settings
 * to state (_taxanotes_front_widen_prepare()); every later step checks what
 * is already in place before acting, and the backups and the state entry go
 * last, once the row counts of the rebuilt tables match them.
 *
 * @param string $entity_type_id
 *   The entity type of the field.
 * @param string $bundle
 *   The bundle whose field and default displays are carried over.
 * @param string $name
 *   The field name.
 *
 * @return string
 *   What was done.
 */
function _taxanotes_front_widen_string_field(string $entity_type_id, string $bundle, string $name): string {
  $state = \Drupal::state();
  $state_key = "taxanotes_front.widen.$entity_type_id.$name";
  $connection = \Drupal::database();
  $storage = FieldStorageConfig::loadByName($entity_type_id, $name);
  $saved = $state->get($state_key);
  if ($saved === NULL) {
    if (!$storage) {
      return 'no field storage, nothing to do (the recipe creates it as string_long).';
    }
    if ($storage->getType() === 'string_long') {
      return 'already string_long.';
    }
    if ($storage->getType() !== 'string') {
      return 'is a ' . $storage->getType() . ' field, not a plain string: left alone.';
    }
    $saved = _taxanotes_front_widen_prepare($storage, $bundle);
    $state->set($state_key, $saved);
  }

  // From here the rows are in the backup tables and the settings in state.
  if ($storage && $storage->getType() === 'string') {
    // Emptied first: core drops the tables of a storage without data at
    // once, instead of renaming them for a purge on cron.
    foreach (array_keys($saved['tables']) as $table) {
      $connection->truncate($table)->execute();
    }
    $storage->delete();
    $storage = NULL;
  }
  if (!$storage) {
    $values = $saved['storage'];
    $values['type'] = 'string_long';
    $values['settings'] = ['case_sensitive' => FALSE];
    FieldStorageConfig::create($values)->save();
  }
  if ($saved['field'] && !FieldConfig::loadByName($entity_type_id, $bundle, $name)) {
    $values = $saved['field'];
    $values['field_type'] = 'string_long';
    FieldConfig::create($values)->save();
  }

  $count = 0;
  foreach ($saved['tables'] as $table => $backup) {
    $expected = _taxanotes_front_widen_count($backup);
    if (_taxanotes_front_widen_count($table) !== $expected) {
      $connection->truncate($table)->execute();
      $connection->insert($table)
        ->from($connection->select($backup, 'b')->fields('b', $saved['columns'][$table]))
        ->execute();
      $have = _taxanotes_front_widen_count($table);
      if ($have !== $expected) {
        throw new \RuntimeException(sprintf('%s holds %d rows after the copy back, its backup %s %d: stopped, nothing dropped; the next updatedb tries again.', $table, $have, $backup, $expected));
      }
    }
    $count += $expected;
  }

  if ($saved['form_component']) {
    EntityFormDisplay::load("$entity_type_id.$bundle.default")->setComponent($name, [
      'type' => 'string_textarea',
      'weight' => $saved['form_component']['weight'],
      'region' => $saved['form_component']['region'] ?? 'content',
      'settings' => ['rows' => 2, 'placeholder' => ''],
      'third_party_settings' => [],
    ])->save();
  }
  if ($saved['view_component']) {
    EntityViewDisplay::load("$entity_type_id.$bundle.default")->setComponent($name, [
      'type' => 'basic_string',
      'label' => $saved['view_component']['label'] ?? 'above',
      'weight' => $saved['view_component']['weight'],
      'region' => $saved['view_component']['region'] ?? 'content',
      'settings' => [],
      'third_party_settings' => [],
    ])->save();
  }
  foreach ($saved['tables'] as $backup) {
    $connection->schema()->dropTable($backup);
  }
  $state->delete($state_key);
  \Drupal::entityTypeManager()->getStorage($entity_type_id)->resetCache();
  return sprintf('rebuilt as string_long, %d rows kept (%s).', $count, implode(', ', array_keys($saved['tables'])));
}

/**
 * Copies a field's rows to backup tables and collects what to recreate.
 *
 * Each dedicated table (data, and revision when the entity type has
 * revisions) is copied to <table>_widen, created like the original (MySQL
 * and MariaDB, which the instances run on), and the copy is counted against
 * the original before anything else happens.
 *
 * @return array{tables: array<string, string>, columns: array<string, string[]>, storage: array<string, mixed>, field: array<string, mixed>|null, form_component: array<string, mixed>|null, view_component: array<string, mixed>|null}
 *   The backup table of each table, the columns of each table, the storage
 *   and field values (uuid dropped), the default form and view display
 *   components of the field.
 */
function _taxanotes_front_widen_prepare(FieldStorageConfigInterface $storage, string $bundle): array {
  $entity_type_id = $storage->getTargetEntityTypeId();
  $name = $storage->getName();
  $entity_type_manager = \Drupal::entityTypeManager();
  /** @var \Drupal\Core\Entity\Sql\SqlContentEntityStorage $entity_storage */
  $entity_storage = $entity_type_manager->getStorage($entity_type_id);
  /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
  $mapping = $entity_storage->getTableMapping();
  $tables = [$mapping->getDedicatedDataTableName($storage)];
  if ($entity_type_manager->getDefinition($entity_type_id)->isRevisionable()) {
    $tables[] = $mapping->getDedicatedRevisionTableName($storage);
  }
  $connection = \Drupal::database();
  $schema = $connection->schema();
  $saved = ['tables' => [], 'columns' => []];
  foreach ($tables as $table) {
    $backup = $table . '_widen';
    if ($schema->tableExists($backup)) {
      // Left by an attempt that stopped before its state entry was written.
      $schema->dropTable($backup);
    }
    $columns = $mapping->getAllColumns($table);
    $connection->query('CREATE TABLE {' . $backup . '} LIKE {' . $table . '}');
    $connection->insert($backup)
      ->from($connection->select($table, 't')->fields('t', $columns))
      ->execute();
    $expected = _taxanotes_front_widen_count($table);
    $got = _taxanotes_front_widen_count($backup);
    if ($got !== $expected) {
      throw new \RuntimeException(sprintf('The backup %s holds %d rows, %s %d: stopped before anything was changed.', $backup, $got, $table, $expected));
    }
    $saved['tables'][$table] = $backup;
    $saved['columns'][$table] = $columns;
  }
  $storage_values = $storage->toArray();
  unset($storage_values['uuid'], $storage_values['_core']);
  $field_values = FieldConfig::loadByName($entity_type_id, $bundle, $name)?->toArray();
  if ($field_values) {
    unset($field_values['uuid'], $field_values['_core']);
  }
  $saved['storage'] = $storage_values;
  $saved['field'] = $field_values;
  $saved['form_component'] = EntityFormDisplay::load("$entity_type_id.$bundle.default")?->getComponent($name);
  $saved['view_component'] = EntityViewDisplay::load("$entity_type_id.$bundle.default")?->getComponent($name);
  return $saved;
}

/**
 * The number of rows of a table.
 */
function _taxanotes_front_widen_count(string $table): int {
  return (int) \Drupal::database()->select($table, 't')->countQuery()->execute()->fetchField();
}
