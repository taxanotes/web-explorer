<?php

declare(strict_types=1);

namespace Drupal\node_tree;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * The settings of the tree and the queries behind it.
 *
 * A tree is made of the nodes of the configured bundles; each node names its
 * parent in an entity reference field (parent_field) and is identified in
 * URLs and JSON by id_field: its UUID, or a field such as a GUID. The
 * optional parent_id_field (a copy of the parent's identifier on the child)
 * makes the children query a single indexed lookup and is what
 * node_tree:link-parents reads; weight_field orders siblings before the
 * title; describes_field is a reference on other bundles to a tree node, so
 * their pages get its lineage breadcrumb.
 */
final class NodeTree {

  /**
   * The jqTree version the module is written against (and the CDN fallback).
   */
  public const JQTREE_VERSION = '1.6.2';

  /**
   * The jqTree files under the Drupal root, when installed locally.
   */
  public const JQTREE_LOCAL_JS = '/libraries/jqtree/tree.jquery.js';
  public const JQTREE_LOCAL_CSS = '/libraries/jqtree/jqtree.css';

  /**
   * Whether jqTree is installed under /libraries/jqtree.
   */
  public static function jqtreeIsLocal(): bool {
    return file_exists(DRUPAL_ROOT . self::JQTREE_LOCAL_JS);
  }

  /**
   * The settings, keyed by field name, resolved to their table and column.
   *
   * @var array<string, array{table: string, column: string, key: string, dedicated: bool}|null>
   */
  private array $columns = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
  ) {}

  /**
   * The node_tree.settings configuration.
   */
  public function settings(): ImmutableConfig {
    return $this->configFactory->get('node_tree.settings');
  }

  /**
   * The bundles that form trees.
   *
   * @return string[]
   *   The node type ids; empty when the module is not configured.
   */
  public function bundles(): array {
    return array_values(array_filter(array_map('strval', (array) $this->settings()->get('bundles'))));
  }

  /**
   * Whether at least one bundle is configured.
   */
  public function isConfigured(): bool {
    return $this->bundles() !== [] && $this->parentField() !== '';
  }

  /**
   * The entity reference field that names a node's parent.
   */
  public function parentField(): string {
    return trim((string) $this->settings()->get('parent_field'));
  }

  /**
   * The field whose value identifies a node in URLs and JSON.
   *
   * 'uuid' by default.
   */
  public function idField(): string {
    return trim((string) $this->settings()->get('id_field')) ?: 'uuid';
  }

  /**
   * The optional field holding the parent's identifier on the child.
   */
  public function parentIdField(): string {
    return trim((string) $this->settings()->get('parent_id_field'));
  }

  /**
   * The optional integer field that orders siblings before their title.
   */
  public function weightField(): string {
    return trim((string) $this->settings()->get('weight_field'));
  }

  /**
   * The optional reference field to the tree node a page describes.
   *
   * On other bundles than the tree's (a species page's taxon field).
   */
  public function describesField(): string {
    return trim((string) $this->settings()->get('describes_field'));
  }

  /**
   * The path of the tree page, without slashes around it.
   */
  public function path(): string {
    return trim((string) $this->settings()->get('path'), "/ \t\n\r") ?: 'node-tree';
  }

  /**
   * The title of the tree page.
   */
  public function title(): string {
    return trim((string) $this->settings()->get('title')) ?: 'Node tree';
  }

  /**
   * Whether tree nodes and the pages describing one get the lineage breadcrumb.
   */
  public function breadcrumbEnabled(): bool {
    return (bool) $this->settings()->get('breadcrumb');
  }

  /**
   * The cache tags of anything built from the tree.
   *
   * @return string[]
   *   The settings' tag and a node_list tag per configured bundle.
   */
  public function cacheTags(): array {
    $tags = $this->settings()->getCacheTags();
    foreach ($this->bundles() as $bundle) {
      $tags[] = 'node_list:' . $bundle;
    }
    return $tags;
  }

  /**
   * Whether a node is part of the tree (a configured bundle with the fields).
   */
  public function isTreeNode(mixed $node): bool {
    return $node instanceof NodeInterface
      && in_array($node->bundle(), $this->bundles(), TRUE)
      && $node->hasField($this->parentField())
      && ($this->idField() === 'uuid' || $node->hasField($this->idField()));
  }

  /**
   * The identifier of a tree node, as used in URLs and JSON.
   */
  public function idOf(NodeInterface $node): string {
    $field = $this->idField();
    if ($field === 'uuid') {
      return (string) $node->uuid();
    }
    return $node->hasField($field) ? (string) $node->get($field)->getString() : '';
  }

  /**
   * The parent of a tree node, when it has one that is a tree node too.
   */
  public function parentOf(NodeInterface $node): ?NodeInterface {
    if (!$this->isTreeNode($node)) {
      return NULL;
    }
    $parent = $node->get($this->parentField())->entity;
    return $this->isTreeNode($parent) ? $parent : NULL;
  }

  /**
   * The lineage of a tree node: the root first, the node itself last.
   *
   * Cycle-safe: a node met twice ends the walk.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The chain of nodes.
   */
  public function lineage(NodeInterface $node): array {
    $chain = [];
    $seen = [];
    for ($current = $node; $current instanceof NodeInterface && !isset($seen[$current->id()]); $current = $this->parentOf($current)) {
      $seen[$current->id()] = TRUE;
      $chain[] = $current;
    }
    return array_reverse($chain);
  }

  /**
   * The tree node a node stands for: itself, or the one it describes.
   *
   * A node of another bundle that points at a tree node through
   * describes_field stands for that node (a species page for its taxon).
   */
  public function treeNodeOf(mixed $node): ?NodeInterface {
    if (!$node instanceof NodeInterface) {
      return NULL;
    }
    if ($this->isTreeNode($node)) {
      return $node;
    }
    $field = $this->describesField();
    if ($field !== '' && $node->hasField($field)) {
      $target = $node->get($field)->entity;
      if ($this->isTreeNode($target)) {
        return $target;
      }
    }
    return NULL;
  }

  /**
   * Loads the published tree node with the given identifier, if any.
   *
   * @param string $id
   *   The identifier (id_field value).
   * @param bool $access_check
   *   Whether to apply node access and the published condition (TRUE for
   *   visitors; FALSE for the Drush commands, which see every node).
   */
  public function loadById(string $id, bool $access_check = TRUE): ?NodeInterface {
    if (trim($id) === '' || !$this->isConfigured()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', $this->bundles(), 'IN')
      ->condition($this->idField(), $id)
      ->range(0, 1)
      ->accessCheck($access_check);
    if ($access_check) {
      $query->condition('status', NodeInterface::PUBLISHED);
    }
    $nids = $query->execute();
    if (!$nids) {
      return NULL;
    }
    $node = $storage->load(reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * The published children of a node (or the roots) as jqTree items.
   *
   * One SQL statement: the children filtered on the parent's identifier
   * (through parent_id_field when it is set, otherwise through the parent
   * reference joined to the identifier field; a NULL parent reference for
   * the roots), joined to a GROUP BY count of published children so only
   * expandable nodes get load_on_demand. Siblings are ordered by
   * weight_field (0 when unset or empty), then title. The queries are tagged
   * node_access so grants apply when a node access module is enabled.
   *
   * @param string $parent_id
   *   The parent's identifier, or '' for the roots.
   *
   * @return array<int, array{id: string, name: string, load_on_demand: bool, child_count: int}>
   *   The items, in sibling order.
   */
  public function children(string $parent_id = ''): array {
    $id = $this->column($this->idField());
    $parent = $this->column($this->parentField());
    if (!$this->isConfigured() || !$id || !$parent) {
      return [];
    }
    $query = $this->database->select('node_field_data', 'nfd');
    $query->addField('nfd', 'title', 'name');
    $query->condition('nfd.type', $this->bundles(), 'IN')
      ->condition('nfd.status', NodeInterface::PUBLISHED)
      ->condition('nfd.default_langcode', 1);
    $this->joinField($query, 'i', $id, 'nfd.nid');
    $query->addField('i', $id['column'], 'id');
    if ($parent_id !== '') {
      $parent_id_column = $this->parentIdField() !== '' ? $this->column($this->parentIdField()) : NULL;
      if ($parent_id_column) {
        $this->joinField($query, 'pg', $parent_id_column, 'nfd.nid');
        $query->condition('pg.' . $parent_id_column['column'], $parent_id);
      }
      else {
        $this->joinField($query, 'pr', $parent, 'nfd.nid');
        $this->joinField($query, 'pi', $id, 'pr.' . $parent['column']);
        $query->condition('pi.' . $id['column'], $parent_id);
      }
    }
    else {
      $this->joinField($query, 'pr', $parent, 'nfd.nid', TRUE);
      $query->isNull('pr.' . $parent['column']);
    }
    // Published child counts for the candidate rows.
    $counts = $this->database->select($parent['table'], 'c');
    if ($parent['dedicated']) {
      $counts->condition('c.deleted', 0);
    }
    $counts->innerJoin('node_field_data', 'cn', 'cn.nid = c.' . $parent['key']);
    $counts->condition('cn.status', NodeInterface::PUBLISHED)
      ->condition('cn.type', $this->bundles(), 'IN')
      ->condition('cn.default_langcode', 1);
    $counts->addField('c', $parent['column'], 'parent_nid');
    $counts->addExpression('COUNT(*)', 'child_count');
    $counts->groupBy('c.' . $parent['column']);
    $counts->addTag('node_access');
    $query->leftJoin($counts, 'cc', 'cc.parent_nid = nfd.nid');
    $query->addExpression('COALESCE(cc.child_count, 0)', 'child_count');
    $weight = $this->weightField() !== '' ? $this->column($this->weightField()) : NULL;
    if ($weight) {
      $this->joinField($query, 'w', $weight, 'nfd.nid', TRUE);
      $query->addExpression('COALESCE(w.' . $weight['column'] . ', 0)', 'weight');
      $query->orderBy('weight');
    }
    $query->orderBy('nfd.title');
    $query->addTag('node_access');

    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = [
        'id' => (string) $row->id,
        'name' => (string) $row->name,
        'load_on_demand' => (int) $row->child_count > 0,
        'child_count' => (int) $row->child_count,
      ];
    }
    return $items;
  }

  /**
   * Points every child at the parent its parent_id_field names.
   *
   * For each node of the bundles that carries a parent identifier, the node
   * with that identifier becomes its parent (parent_field), when it is not
   * already. Unpublished nodes take part on both sides. Nodes are handled in
   * chunks so a large tree does not exhaust memory.
   *
   * @param string[] $bundles
   *   The bundles to link (a subset of the configured ones).
   * @param bool $dry_run
   *   TRUE to count without saving.
   * @param callable|null $progress
   *   Called after each chunk with the number of nodes handled so far.
   *
   * @return array{linked: int, unchanged: int, missing: array<int, string>, empty: int}
   *   How many nodes were linked (or would be), how many already pointed at
   *   the right parent, the nodes (nid => parent identifier) whose parent
   *   identifier matches no node, and how many have no parent identifier.
   */
  public function linkParents(array $bundles, bool $dry_run = FALSE, ?callable $progress = NULL): array {
    $parent_id_field = $this->parentIdField();
    if ($parent_id_field === '') {
      throw new \RuntimeException('node_tree.settings:parent_id_field is not set: there is no parent identifier to link from.');
    }
    $bundles = array_values(array_intersect($bundles, $this->bundles()));
    $result = ['linked' => 0, 'unchanged' => 0, 'missing' => [], 'empty' => 0];
    if ($bundles === []) {
      return $result;
    }
    $parent_field = $this->parentField();
    $ids = $this->idMap();
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->sort('nid')
      ->execute();
    $done = 0;
    foreach (array_chunk($nids, 100) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        if (!$node instanceof NodeInterface || !$node->hasField($parent_id_field) || !$node->hasField($parent_field)) {
          $result['empty']++;
          continue;
        }
        $parent_id = trim((string) $node->get($parent_id_field)->getString());
        if ($parent_id === '') {
          $result['empty']++;
          continue;
        }
        if (!isset($ids[$parent_id]) || $ids[$parent_id] === (int) $node->id()) {
          $result['missing'][(int) $node->id()] = $parent_id;
          continue;
        }
        if ((int) $node->get($parent_field)->target_id === $ids[$parent_id]) {
          $result['unchanged']++;
          continue;
        }
        if (!$dry_run) {
          $node->set($parent_field, ['target_id' => $ids[$parent_id]]);
          $node->save();
        }
        $result['linked']++;
      }
      $storage->resetCache($chunk);
      $done += count($chunk);
      if ($progress) {
        $progress($done, count($nids));
      }
    }
    return $result;
  }

  /**
   * Counts per bundle: nodes, published, roots, unresolved parents.
   *
   * @return array<string, array{nodes: int, published: int, roots: int, unresolved: int}>
   *   Keyed by bundle. "unresolved" is the number of nodes whose
   *   parent_id_field names no node (0 when parent_id_field is not set).
   */
  public function status(): array {
    $status = [];
    if (!$this->isConfigured()) {
      return $status;
    }
    $parent = $this->column($this->parentField());
    $ids = $this->parentIdField() !== '' ? $this->idMap() : NULL;
    foreach ($this->bundles() as $bundle) {
      $query = $this->database->select('node_field_data', 'nfd')
        ->condition('nfd.type', $bundle)
        ->condition('nfd.default_langcode', 1);
      $nodes = (int) (clone $query)->countQuery()->execute()->fetchField();
      $published = (int) (clone $query)->condition('nfd.status', NodeInterface::PUBLISHED)->countQuery()->execute()->fetchField();
      $roots = 0;
      if ($parent) {
        $root_query = clone $query;
        $this->joinField($root_query, 'pr', $parent, 'nfd.nid', TRUE);
        $root_query->isNull('pr.' . $parent['column']);
        $roots = (int) $root_query->countQuery()->execute()->fetchField();
      }
      $unresolved = 0;
      if ($ids !== NULL && ($parent_id_column = $this->column($this->parentIdField()))) {
        $id_query = $this->database->select('node_field_data', 'nfd')
          ->condition('nfd.type', $bundle)
          ->condition('nfd.default_langcode', 1);
        $this->joinField($id_query, 'pg', $parent_id_column, 'nfd.nid');
        $id_query->addField('pg', $parent_id_column['column'], 'parent_id');
        foreach ($id_query->execute()->fetchCol() as $parent_id) {
          if (trim((string) $parent_id) !== '' && !isset($ids[(string) $parent_id])) {
            $unresolved++;
          }
        }
      }
      $status[$bundle] = ['nodes' => $nodes, 'published' => $published, 'roots' => $roots, 'unresolved' => $unresolved];
    }
    return $status;
  }

  /**
   * Every tree node's identifier mapped to its nid (published or not).
   *
   * @return array<string, int>
   *   Identifier => nid.
   */
  private function idMap(): array {
    $id = $this->column($this->idField());
    if (!$id) {
      return [];
    }
    $query = $this->database->select('node_field_data', 'nfd')
      ->condition('nfd.type', $this->bundles(), 'IN')
      ->condition('nfd.default_langcode', 1);
    $this->joinField($query, 'i', $id, 'nfd.nid');
    $query->addField('nfd', 'nid');
    $query->addField('i', $id['column'], 'id');
    $map = [];
    foreach ($query->execute() as $row) {
      if ((string) $row->id !== '') {
        $map[(string) $row->id] = (int) $row->nid;
      }
    }
    return $map;
  }

  /**
   * Joins the table that stores a field, on the node id.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query.
   * @param string $alias
   *   The alias of the joined table.
   * @param array{table: string, column: string, key: string, dedicated: bool} $field
   *   The field's table (see ::column()).
   * @param string $nid
   *   The expression the table's node id column is joined to.
   * @param bool $left
   *   TRUE for a LEFT JOIN (an absent value must not drop the row).
   */
  private function joinField(SelectInterface $query, string $alias, array $field, string $nid, bool $left = FALSE): void {
    $condition = $alias . '.' . $field['key'] . ' = ' . $nid;
    if ($field['dedicated']) {
      $condition .= ' AND ' . $alias . '.deleted = 0';
    }
    if ($left) {
      $query->leftJoin($field['table'], $alias, $condition);
    }
    else {
      $query->innerJoin($field['table'], $alias, $condition);
    }
  }

  /**
   * The table and column that store a node field's main property.
   *
   * @return array{table: string, column: string, key: string, dedicated: bool}|null
   *   The table, the column, the column holding the node id (nid in a base
   *   table, entity_id in a dedicated field table) and whether the table is
   *   a dedicated field table (with deleted and langcode columns); NULL when
   *   the field does not exist.
   */
  private function column(string $field): ?array {
    if (array_key_exists($field, $this->columns)) {
      return $this->columns[$field];
    }
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions('node');
    if (!isset($definitions[$field])) {
      return $this->columns[$field] = NULL;
    }
    $definition = $definitions[$field];
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $this->entityTypeManager->getStorage('node')->getTableMapping();
    $dedicated = $mapping->requiresDedicatedTableStorage($definition);
    return $this->columns[$field] = [
      'table' => $mapping->getFieldTableName($field),
      'column' => $mapping->getFieldColumnName($definition, $definition->getMainPropertyName() ?? 'value'),
      'key' => $dedicated ? 'entity_id' : 'nid',
      'dedicated' => $dedicated,
    ];
  }

}
