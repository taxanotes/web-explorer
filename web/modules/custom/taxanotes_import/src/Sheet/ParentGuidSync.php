<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Sets the parent GUID of taxa whose parent carries another GUID than it.
 *
 * A taxon carries its parent twice: the entity reference the tree and the
 * pages follow (field_parent_taxon_reference) and the parent's GUID
 * (field_parent_guid), which the node_tree children query filters on. A
 * sheet import with --match-names gives a matched taxon the sheet's GUID;
 * the taxa the sheet does not list keep pointing at that parent's old GUID
 * and vanish from the tree although their reference is intact. This finds
 * every taxon whose parent GUID differs from its parent's current GUID (or
 * is empty while the reference is set) and rewrites it from the reference,
 * the inverse of node_tree:link-parents. Taxa the sheet lists are excluded:
 * their parent GUID is the sheet's and the reference was derived from it.
 *
 * Saves follow the rule of the taxanotes_entity_node destination: a
 * revision whose log starts with one of the placeholder prefixes is updated
 * in place, any other gets a new revision with the given log and author.
 */
final class ParentGuidSync {

  /**
   * Nodes loaded per chunk.
   */
  private const CHUNK = 100;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly string $bundle = 'taxon',
    private readonly string $guidField = 'field_guid',
    private readonly string $parentGuidField = 'field_parent_guid',
    private readonly string $parentField = 'field_parent_taxon_reference',
  ) {}

  /**
   * The taxa whose parent GUID is not their parent's GUID.
   *
   * @param string[] $exclude_guids
   *   GUIDs of taxa to leave alone (the sheet's rows).
   *
   * @return array<int, array{nid: int, name: string, guid: string|null, from: string|null, to: string}>
   *   Per stale taxon: its nid, title, own GUID, the parent GUID it holds
   *   and the parent's current GUID, in nid order.
   */
  public function stale(array $exclude_guids = []): array {
    // Node storage is SQL storage (phpstan-drupal knows it as NodeStorage).
    $mapping = $this->entityTypeManager->getStorage('node')->getTableMapping();
    $fields = $this->entityFieldManager->getFieldStorageDefinitions('node');
    foreach ([$this->guidField, $this->parentGuidField, $this->parentField] as $field) {
      if (!isset($fields[$field])) {
        return [];
      }
    }
    $guid_table = $mapping->getFieldTableName($this->guidField);
    $guid_column = $mapping->getFieldColumnName($fields[$this->guidField], 'value');
    $parent_guid_table = $mapping->getFieldTableName($this->parentGuidField);
    $parent_guid_column = $mapping->getFieldColumnName($fields[$this->parentGuidField], 'value');
    $parent_table = $mapping->getFieldTableName($this->parentField);
    $parent_column = $mapping->getFieldColumnName($fields[$this->parentField], 'target_id');

    $query = $this->database->select($parent_table, 'c');
    $query->innerJoin('node_field_data', 'n', '[n].[nid] = [c].[entity_id] AND [n].[default_langcode] = 1');
    $query->innerJoin($guid_table, 'pg', "[pg].[entity_id] = [c].[$parent_column] AND [pg].[deleted] = 0 AND [pg].[delta] = 0");
    $query->leftJoin($parent_guid_table, 'h', '[h].[entity_id] = [c].[entity_id] AND [h].[deleted] = 0 AND [h].[delta] = 0');
    $query->leftJoin($guid_table, 'g', '[g].[entity_id] = [c].[entity_id] AND [g].[deleted] = 0 AND [g].[delta] = 0');
    $query->addField('c', 'entity_id', 'nid');
    $query->addField('n', 'title', 'name');
    $query->addField('g', $guid_column, 'guid');
    $query->addField('h', $parent_guid_column, 'from');
    $query->addField('pg', $guid_column, 'to');
    $query->condition('c.bundle', $this->bundle)
      ->condition('c.deleted', 0)
      ->condition('c.delta', 0);
    $query->where("[h].[$parent_guid_column] IS NULL OR [h].[$parent_guid_column] <> [pg].[$guid_column]");
    $query->orderBy('c.entity_id');
    $exclude = array_fill_keys($exclude_guids, TRUE);
    $stale = [];
    foreach ($query->execute()->fetchAll(FetchAs::Associative) as $row) {
      if ($row['to'] === NULL || $row['to'] === '' || ($row['guid'] !== NULL && isset($exclude[$row['guid']]))) {
        continue;
      }
      $stale[] = [
        'nid' => (int) $row['nid'],
        'name' => (string) $row['name'],
        'guid' => $row['guid'] !== NULL && $row['guid'] !== '' ? (string) $row['guid'] : NULL,
        'from' => $row['from'] !== NULL && $row['from'] !== '' ? (string) $row['from'] : NULL,
        'to' => (string) $row['to'],
      ];
    }
    return $stale;
  }

  /**
   * Rewrites the parent GUID of the given stale taxa from their reference.
   *
   * @param array<int, array{nid: int, name: string, guid: string|null, from: string|null, to: string}> $stale
   *   What stale() returned.
   * @param string $log
   *   The revision log of a new revision ("@from" and "@to" are replaced).
   * @param string[] $placeholder_prefixes
   *   Logs that mark a revision to update in place.
   * @param int $uid
   *   The author of a new revision.
   *
   * @return int
   *   How many nodes were saved.
   */
  public function apply(array $stale, string $log, array $placeholder_prefixes, int $uid = 1): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $saved = 0;
    foreach (array_chunk($stale, self::CHUNK) as $chunk) {
      $by_nid = array_column($chunk, NULL, 'nid');
      foreach ($storage->loadMultiple(array_keys($by_nid)) as $node) {
        if (!$node->hasField($this->parentGuidField)) {
          continue;
        }
        $item = $by_nid[(int) $node->id()];
        $node->set($this->parentGuidField, $item['to']);
        $previous = (string) $node->getRevisionLogMessage();
        $placeholder = FALSE;
        foreach ($placeholder_prefixes as $prefix) {
          if ($prefix !== '' && str_starts_with($previous, $prefix)) {
            $placeholder = TRUE;
            break;
          }
        }
        if (!$placeholder) {
          $node->setNewRevision(TRUE);
          $node->setRevisionLogMessage(strtr($log, ['@from' => $item['from'] ?? '(empty)', '@to' => $item['to']]));
          $node->setRevisionUserId($uid);
          $node->setRevisionCreationTime($this->time->getRequestTime());
          $node->setRevisionTranslationAffected(TRUE);
        }
        $node->save();
        $saved++;
      }
      $storage->resetCache(array_keys($by_nid));
    }
    return $saved;
  }

}
