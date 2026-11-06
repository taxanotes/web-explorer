<?php

declare(strict_types=1);

namespace Drupal\node_tree\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\node_tree\NodeTree;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands of the node tree: parent linking and a status table.
 */
final class NodeTreeCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(private readonly NodeTree $tree) {
    parent::__construct();
  }

  /**
   * Points every child at the parent its parent identifier field names.
   *
   * After an import that fills the parent identifier field (a GUID, a code)
   * but not the parent reference, this sets the parent field of every node
   * whose parent identifier matches a node of the configured types.
   */
  #[CLI\Command(name: 'node_tree:link-parents', aliases: ['node_tree:node_treelinkparent', 'node_treelnk'])]
  #[CLI\Option(name: 'bundle', description: 'Link only this node type (default: every configured one).')]
  #[CLI\Option(name: 'dry-run', description: 'Report what would change without saving anything.')]
  #[CLI\Usage(name: 'drush node_tree:link-parents', description: 'Link the children of every configured node type to their parents.')]
  #[CLI\Usage(name: 'drush node_tree:link-parents --bundle=taxon --dry-run', description: 'Count what would be linked among taxon nodes.')]
  public function linkParents(array $options = ['bundle' => NULL, 'dry-run' => FALSE]): void {
    if (!$this->tree->isConfigured()) {
      throw new \RuntimeException('node_tree is not configured: set the node types and fields at /admin/config/content/node-tree.');
    }
    $bundles = $this->bundlesOption($options['bundle']);
    $dry_run = (bool) $options['dry-run'];
    $this->io()->writeln(sprintf('Linking %s through %s -> %s%s', implode(', ', $bundles), $this->tree->parentIdField(), $this->tree->parentField(), $dry_run ? ' (dry run)' : ''));
    $result = $this->tree->linkParents($bundles, $dry_run, function (int $done, int $total): void {
      if ($done % 1000 === 0 || $done === $total) {
        $this->io()->writeln(sprintf('  %d of %d nodes', $done, $total));
      }
    });
    $this->io()->writeln(sprintf('%s: %d, already linked: %d, no parent identifier: %d, parent not found: %d', $dry_run ? 'Would link' : 'Linked', $result['linked'], $result['unchanged'], $result['empty'], count($result['missing'])));
    foreach (array_slice($result['missing'], 0, 20, TRUE) as $nid => $parent_id) {
      $this->io()->writeln(sprintf('  node %d: no node has the identifier %s', $nid, $parent_id));
    }
    if (count($result['missing']) > 20) {
      $this->io()->writeln(sprintf('  … and %d more', count($result['missing']) - 20));
    }
  }

  /**
   * Shows the tree settings and the node counts per configured type.
   */
  #[CLI\Command(name: 'node_tree:status')]
  #[CLI\FieldLabels(labels: [
    'bundle' => 'Node type',
    'nodes' => 'Nodes',
    'published' => 'Published',
    'roots' => 'Roots',
    'unresolved' => 'Parent not found',
  ])]
  #[CLI\DefaultTableFields(fields: ['bundle', 'nodes', 'published', 'roots', 'unresolved'])]
  public function status(array $options = ['format' => 'table']): RowsOfFields {
    $this->io()->writeln(sprintf('bundles: %s; parent_field: %s; id_field: %s; parent_id_field: %s; weight_field: %s; describes_field: %s; path: /%s; breadcrumb: %s',
      implode(', ', $this->tree->bundles()) ?: '(none)',
      $this->tree->parentField(),
      $this->tree->idField(),
      $this->tree->parentIdField() ?: '(none)',
      $this->tree->weightField() ?: '(none)',
      $this->tree->describesField() ?: '(none)',
      $this->tree->path(),
      $this->tree->breadcrumbEnabled() ? 'on' : 'off',
    ));
    $rows = [];
    foreach ($this->tree->status() as $bundle => $counts) {
      $rows[] = ['bundle' => $bundle] + $counts;
    }
    return new RowsOfFields($rows);
  }

  /**
   * The bundles a --bundle option selects (all configured ones when empty).
   *
   * @return string[]
   *   The bundles.
   */
  private function bundlesOption(?string $bundle): array {
    $configured = $this->tree->bundles();
    if ($bundle === NULL || $bundle === '') {
      return $configured;
    }
    if (!in_array($bundle, $configured, TRUE)) {
      throw new \RuntimeException(sprintf('%s is not a configured node type (configured: %s).', $bundle, implode(', ', $configured)));
    }
    return [$bundle];
  }

}
