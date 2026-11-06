<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\process;

use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\MigrateLookupInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves a Scratchpads GUID to the entity that carries it on this site.
 *
 * Tries, in order: the id maps of the given migrations (no stubs), then an
 * entity query on the field that stores the GUID. The first answer wins;
 * none gives NULL (the destination property is then empty, which clears a
 * reference field in an overwrite_properties pass). With `message` set, a
 * GUID that resolves nowhere is recorded as a message of the row, so an
 * unresolved parent, accepted name or reference is visible in
 * `migrate:messages` without failing or skipping the row.
 *
 * Why not migration_lookup + entity_lookup chained: the sheet rows may be
 * taxa the D7 track imported (id map of taxanotes_d7_taxon), taxa an earlier
 * sheet created (id map of taxanotes_sheet_taxon), or taxa made by hand
 * here (only field_guid knows them); one plugin that asks all three in
 * order reads as one rule and is used the same way in every pass.
 *
 * Configuration keys:
 * - migrations: migration ids whose id maps are consulted first, in order.
 *   A migration that does not exist is skipped.
 * - entity_type: the entity type to query (default node).
 * - bundle: its bundle, or omit for any.
 * - field: the field holding the GUID (default field_guid).
 * - message: optional text recorded when nothing resolves. `@value` is the
 *   GUID; `@<property>` any source property of the row (`@row`, `@name`).
 * - level: the message level (default MigrationInterface::MESSAGE_NOTICE).
 *
 * @code
 * field_parent_taxon_reference:
 *   -
 *     plugin: skip_on_empty
 *     method: process
 *     source: parent_guid
 *   -
 *     plugin: taxanotes_guid_lookup
 *     migrations: [taxanotes_sheet_taxon, taxanotes_d7_taxon]
 *     message: 'row @row (@name): parent GUID @value is not on the site'
 * @endcode
 */
#[MigrateProcess(
  id: 'taxanotes_guid_lookup',
)]
final class TaxanotesGuidLookup extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Resolved values of this run: GUID => entity id or NULL.
   *
   * @var array<string, int|string|null>
   */
  protected array $cache = [];

  /**
   * The migrate lookup service (the id maps).
   */
  protected MigrateLookupInterface $migrateLookup;

  /**
   * The entity type manager (the field query).
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrateLookupInterface $migrate_lookup,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->migrateLookup = $migrate_lookup;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('migrate.lookup'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if ($value === NULL || $value === '' || is_array($value)) {
      return NULL;
    }
    $guid = (string) $value;
    if (!array_key_exists($guid, $this->cache)) {
      $this->cache[$guid] = $this->lookup($guid);
    }
    if ($this->cache[$guid] === NULL && !empty($this->configuration['message'])) {
      $message = preg_replace_callback('/@([a-z_]+)/', static function (array $m) use ($guid, $row): string {
        if ($m[1] === 'value') {
          return $guid;
        }
        $property = $row->getSourceProperty($m[1]);
        return is_scalar($property) ? (string) $property : $m[0];
      }, (string) $this->configuration['message']);
      $migrate_executable->saveMessage($message, (int) ($this->configuration['level'] ?? MigrationInterface::MESSAGE_NOTICE));
    }
    return $this->cache[$guid];
  }

  /**
   * The entity id a GUID resolves to, or NULL.
   */
  protected function lookup(string $guid): int|string|null {
    foreach ((array) ($this->configuration['migrations'] ?? []) as $migration_id) {
      try {
        $ids = $this->migrateLookup->lookup((string) $migration_id, [$guid]);
      }
      catch (PluginException) {
        // No such migration on this site: nothing to look up.
        continue;
      }
      if ($ids) {
        $first = reset($ids);
        $id = is_array($first) ? reset($first) : $first;
        if ($id !== FALSE && $id !== NULL && $id !== '') {
          return $id;
        }
      }
    }
    $entity_type = (string) ($this->configuration['entity_type'] ?? 'node');
    $field = (string) ($this->configuration['field'] ?? 'field_guid');
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition($field, $guid)
      ->sort($storage->getEntityType()->getKey('id'))
      ->range(0, 1);
    if (!empty($this->configuration['bundle']) && ($bundle_key = $storage->getEntityType()->getKey('bundle'))) {
      $query->condition($bundle_key, $this->configuration['bundle']);
    }
    $ids = $query->execute();
    return $ids ? reset($ids) : NULL;
  }

}
