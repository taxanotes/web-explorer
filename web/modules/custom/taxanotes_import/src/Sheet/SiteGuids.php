<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

/**
 * Looks up the entities of this site by their Scratchpads GUID or label.
 *
 * The sheet identifies a taxon by GUID (`field_guid` on taxon nodes) and,
 * when the GUID is missing, by name; the literature by the D7 node uuid
 * (`field_scratchpads_uuid` on bibcite references). This answers the few
 * set-based questions the identity rules and the Drush report ask (which
 * of these GUIDs exist, under which label; which labels are unique) with
 * one query per batch over the field's SQL table, so a 4,000-row sheet
 * costs a handful of statements rather than a node load per row. The entity
 * type, bundle, GUID field and label field are parameters, so the taxon and
 * the reference lookups are the same class.
 */
final class SiteGuids {

  /**
   * Values per IN() condition.
   */
  private const CHUNK = 500;

  /**
   * The field's table and value column, resolved once.
   *
   * @var array{string, string}|null
   */
  private ?array $guidTable = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
    private readonly string $entityType = 'node',
    private readonly ?string $bundle = 'taxon',
    private readonly string $guidField = 'field_guid',
    private readonly ?string $authorsField = 'field_authors',
  ) {}

  /**
   * The SQL storage of the entity type (node, bibcite_reference: both are).
   */
  private function storage(): SqlContentEntityStorage {
    $storage = $this->entityTypeManager->getStorage($this->entityType);
    if (!$storage instanceof SqlContentEntityStorage) {
      throw new \RuntimeException(sprintf('%s is not stored in SQL tables; the sheet lookups need its field tables.', $this->entityType));
    }
    return $storage;
  }

  /**
   * Whether the entity type carries the GUID field (in any bundle).
   */
  public function available(): bool {
    return $this->guidTable() !== NULL;
  }

  /**
   * The entity ids of the given GUIDs: GUID => id (the lowest, if several).
   *
   * @param string[] $guids
   *   The GUIDs.
   *
   * @return array<string, int>
   *   Ids keyed by GUID, for the GUIDs an entity carries.
   */
  public function idsByGuid(array $guids): array {
    $ids = [];
    foreach ($this->rows($guids) as $row) {
      if (!isset($ids[$row['guid']]) || $row['id'] < $ids[$row['guid']]) {
        $ids[$row['guid']] = (int) $row['id'];
      }
    }
    return $ids;
  }

  /**
   * The labels of the entities carrying the given GUIDs: GUID => label.
   *
   * @param string[] $guids
   *   The GUIDs.
   *
   * @return array<string, string>
   *   Labels keyed by GUID (the lowest id's, if several).
   */
  public function labelsByGuid(array $guids): array {
    $labels = [];
    $ids = [];
    foreach ($this->rows($guids) as $row) {
      if (!isset($ids[$row['guid']]) || $row['id'] < $ids[$row['guid']]) {
        $ids[$row['guid']] = (int) $row['id'];
        $labels[$row['guid']] = (string) $row['label'];
      }
    }
    return $labels;
  }

  /**
   * The entities with the given labels, with their GUID and authors.
   *
   * @param string[] $labels
   *   The labels (titles).
   *
   * @return array<string, array<int, array{id: int, guid: string|null, authors: string|null}>>
   *   Per label, every entity carrying it (id order): its id, GUID and the
   *   value of the authors field (NULL without one).
   */
  public function byLabel(array $labels): array {
    $table = $this->guidTable();
    $labels = array_values(array_unique(array_filter($labels, static fn($l) => $l !== '')));
    if ($table === NULL || !$labels) {
      return [];
    }
    [$guid_table, $guid_column] = $table;
    $type = $this->storage()->getEntityType();
    $data_table = $this->storage()->getDataTable() ?: $this->storage()->getBaseTable();
    $id_key = $type->getKey('id');
    $label_key = $type->getKey('label');
    $authors = $this->fieldTable($this->authorsField);
    $found = [];
    foreach (array_chunk($labels, self::CHUNK) as $chunk) {
      $query = $this->database->select($data_table, 'd');
      $query->leftJoin($guid_table, 'g', "[g].[entity_id] = [d].[$id_key] AND [g].[deleted] = 0");
      $query->addField('d', $label_key, 'label');
      $query->addField('d', $id_key, 'id');
      $query->addField('g', $guid_column, 'guid');
      if ($authors !== NULL) {
        $query->leftJoin($authors[0], 'a', "[a].[entity_id] = [d].[$id_key] AND [a].[deleted] = 0 AND [a].[delta] = 0");
        $query->addField('a', $authors[1], 'authors');
      }
      $query->condition("d.$label_key", $chunk, 'IN');
      if ($type->isTranslatable()) {
        $query->condition('d.default_langcode', 1);
      }
      $this->addBundleCondition($query, 'd', $type->getKey('bundle'));
      $query->orderBy("d.$id_key");
      foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
        $found[(string) $row['label']][] = [
          'id' => (int) $row['id'],
          'guid' => $row['guid'] !== NULL && $row['guid'] !== '' ? (string) $row['guid'] : NULL,
          'authors' => isset($row['authors']) && $row['authors'] !== '' ? (string) $row['authors'] : NULL,
        ];
      }
    }
    return $found;
  }

  /**
   * Every GUID on the site: GUID => entity id (the lowest, if several).
   *
   * @return array<string, int>
   *   Ids keyed by GUID.
   */
  public function all(): array {
    $table = $this->guidTable();
    if ($table === NULL) {
      return [];
    }
    [$guid_table, $guid_column] = $table;
    $query = $this->database->select($guid_table, 'g');
    $query->addField('g', 'entity_id', 'id');
    $query->addField('g', $guid_column, 'guid');
    $query->condition('g.deleted', 0);
    $this->addBundleCondition($query, 'g', 'bundle');
    $query->orderBy('g.entity_id');
    $ids = [];
    foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
      if ($row['guid'] !== NULL && $row['guid'] !== '' && !isset($ids[$row['guid']])) {
        $ids[$row['guid']] = (int) $row['id'];
      }
    }
    return $ids;
  }

  /**
   * The value of a string field per entity id: id => value, for the ids.
   *
   * @param string $field
   *   The field (its first delta, main column).
   * @param int[] $ids
   *   The entity ids.
   *
   * @return array<int, string>
   *   Values keyed by id, for the ids that have a non-empty value.
   */
  public function valuesById(string $field, array $ids): array {
    $table = $this->fieldTable($field);
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if ($table === NULL || !$ids) {
      return [];
    }
    [$field_table, $column] = $table;
    $values = [];
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      $query = $this->database->select($field_table, 'f');
      $query->addField('f', 'entity_id', 'id');
      $query->addField('f', $column, 'value');
      $query->condition('f.entity_id', $chunk, 'IN')
        ->condition('f.deleted', 0)
        ->condition('f.delta', 0);
      $this->addBundleCondition($query, 'f', 'bundle');
      foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
        if ($row['value'] !== NULL && $row['value'] !== '') {
          $values[(int) $row['id']] = (string) $row['value'];
        }
      }
    }
    return $values;
  }

  /**
   * The (guid, id, label) rows of the entities carrying the given GUIDs.
   *
   * @return \Generator<array{guid: string, id: int|string, label: string|null}>
   *   The rows.
   */
  private function rows(array $guids): \Generator {
    $table = $this->guidTable();
    $guids = array_values(array_unique(array_filter($guids, static fn($g) => $g !== NULL && $g !== '')));
    if ($table === NULL || !$guids) {
      return;
    }
    [$guid_table, $guid_column] = $table;
    $type = $this->storage()->getEntityType();
    $data_table = $this->storage()->getDataTable() ?: $this->storage()->getBaseTable();
    $id_key = $type->getKey('id');
    $label_key = $type->getKey('label');
    foreach (array_chunk($guids, self::CHUNK) as $chunk) {
      $query = $this->database->select($guid_table, 'g');
      $query->innerJoin($data_table, 'd', "[d].[$id_key] = [g].[entity_id]");
      $query->addField('g', $guid_column, 'guid');
      $query->addField('g', 'entity_id', 'id');
      if ($label_key) {
        $query->addField('d', $label_key, 'label');
      }
      $query->condition("g.$guid_column", $chunk, 'IN')
        ->condition('g.deleted', 0);
      if ($type->isTranslatable()) {
        $query->condition('d.default_langcode', 1);
      }
      $this->addBundleCondition($query, 'g', 'bundle');
      foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
        yield ['guid' => (string) $row['guid'], 'id' => $row['id'], 'label' => $row['label'] ?? NULL];
      }
    }
  }

  /**
   * Restricts a query to the bundle, if one is configured.
   */
  private function addBundleCondition(object $query, string $alias, ?string $column): void {
    if ($this->bundle !== NULL && $column) {
      $query->condition("$alias.$column", $this->bundle);
    }
  }

  /**
   * The SQL table and column of the GUID field, or NULL when it is absent.
   *
   * @return array{string, string}|null
   *   Table name and value column.
   */
  private function guidTable(): ?array {
    if ($this->guidTable === NULL) {
      $this->guidTable = $this->fieldTable($this->guidField) ?? [];
    }
    return $this->guidTable ?: NULL;
  }

  /**
   * The SQL table and value column of a field of the entity type, or NULL.
   *
   * @return array{string, string}|null
   *   Table name and value column.
   */
  private function fieldTable(?string $field): ?array {
    if ($field === NULL || !$this->storage()->getEntityType()->entityClassImplements(FieldableEntityInterface::class)) {
      return NULL;
    }
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions($this->entityType);
    if (!isset($definitions[$field])) {
      return NULL;
    }
    $mapping = $this->storage()->getTableMapping();
    return [
      $mapping->getFieldTableName($field),
      $mapping->getFieldColumnName($definitions[$field], 'value'),
    ];
  }

}
