<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) main-menu links to the pages and blog entries.
 *
 * One row per `menu_links` row of `menu_name` (default main-menu) whose
 * path is node/<nid> of a node of one of `node_types` (default page and
 * blog) or one of the `paths` that have a D11 page of their own (default:
 * the D7 blog listing `blog`, which is /blog here), keyed on the mlid. The
 * row carries the link (mlid, plid, link_title, weight, hidden, expanded,
 * depth), the node (nid, node_uuid, node_type; NULL for a path link),
 * path_uri (the D11 link URI of a path link, internal:/blog; NULL for a
 * node link) and menu_weight: the D7 weight + 50. Drupal 7 menu weights run
 * from -50 to 50 and the Scratchpads put the pages between the standard
 * entries (Home -50, the classification -49, Literature -48 ... About us
 * -41); the D11 main menu's own entries sit at 0 to 10 (Home, Taxa,
 * Classification, Literature, Species pages, About), so the migrated links
 * follow them in their D7 order. The parent link is not migrated: every
 * pilot link to a page is at the top level (a child would land there too).
 *
 * Configuration keys:
 * - menu_name: the D7 menu (default main-menu).
 * - node_types: the D7 node types whose links are rows (default page, blog).
 * - paths: D7 link paths whose links are rows, with the D11 path each one
 *   becomes (default {blog: /blog}).
 */
#[MigrateSource(
  id: 'scratchpads_d7_menu_link',
)]
final class ScratchpadsD7MenuLink extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $configuration += [
      'menu_name' => 'main-menu',
      'node_types' => ['page', 'blog'],
      'paths' => ['blog' => '/blog'],
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    foreach (['menu_links', 'node'] as $table) {
      if (!$schema->tableExists($table)) {
        throw new RequirementsException(sprintf('The migrate database has no %s table.', $table));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('menu_links', 'ml')
      ->fields('ml', ['mlid', 'plid', 'link_path', 'link_title', 'weight', 'hidden', 'expanded', 'depth'])
      ->condition('ml.menu_name', $this->configuration['menu_name'])
      ->orderBy('ml.mlid');
    $query->leftJoin('node', 'n', "CONCAT('node/', [n].[nid]) = [ml].[link_path]");
    $query->addField('n', 'nid', 'nid');
    $query->addField('n', 'uuid', 'node_uuid');
    $query->addField('n', 'type', 'node_type');
    // A link to a node of node_types, or one of the paths with a D11 page.
    $rows = $query->orConditionGroup()
      ->condition('n.type', (array) $this->configuration['node_types'], 'IN');
    $paths = array_keys((array) $this->configuration['paths']);
    if ($paths) {
      $rows->condition('ml.link_path', $paths, 'IN');
    }
    $query->condition($rows);
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'mlid' => $this->t('Menu link ID, the migration id'),
      'plid' => $this->t('Parent menu link ID (not migrated)'),
      'link_path' => $this->t('Link path (node/<nid>)'),
      'link_title' => $this->t('Link title'),
      'weight' => $this->t('D7 weight (-50..50)'),
      'menu_weight' => $this->t('D11 weight: the D7 weight + 50'),
      'hidden' => $this->t('1 when the link is disabled'),
      'expanded' => $this->t('Expanded'),
      'depth' => $this->t('Depth'),
      'nid' => $this->t('The node the link points at'),
      'node_uuid' => $this->t('The node uuid (for the page and blog migration lookups)'),
      'node_type' => $this->t('The node type'),
      'path_uri' => $this->t('For a link to one of the configured paths: the D11 link URI (internal:/<path>); NULL for a node link'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'mlid' => [
        'type' => 'integer',
        'alias' => 'ml',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $row->setSourceProperty('menu_weight', (int) $row->getSourceProperty('weight') + 50);
    $row->setSourceProperty('enabled', ((int) $row->getSourceProperty('hidden')) === 0 ? 1 : 0);
    $paths = (array) $this->configuration['paths'];
    $path = (string) $row->getSourceProperty('link_path');
    $row->setSourceProperty('path_uri', isset($paths[$path]) ? 'internal:' . $paths[$path] : NULL);
    return parent::prepareRow($row);
  }

}
