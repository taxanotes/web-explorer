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
 * blog), keyed on the mlid. The row carries the link (mlid, plid, link_title,
 * weight, hidden, expanded, depth), the node (nid, node_uuid, node_type) and
 * menu_weight: the D7 weight + 50. Drupal 7 menu weights run from -50 to 50
 * and the Scratchpads put the pages between the standard entries
 * (Home -50, the classification -49, Literature -48 ... About us -41); the
 * D11 main menu's own entries sit at 0 to 10 (Home, Taxa, Classification,
 * Literature, Species pages, About), so the migrated links follow them in
 * their D7 order. The parent link is not migrated: every pilot link to a
 * page is at the top level (a child would land there too).
 *
 * Configuration keys:
 * - menu_name: the D7 menu (default main-menu).
 * - node_types: the D7 node types whose links are rows (default page, blog).
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
      ->condition('ml.link_path', 'node/%', 'LIKE')
      ->orderBy('ml.mlid');
    $query->innerJoin('node', 'n', "CONCAT('node/', [n].[nid]) = [ml].[link_path]");
    $query->addField('n', 'nid', 'nid');
    $query->addField('n', 'uuid', 'node_uuid');
    $query->addField('n', 'type', 'node_type');
    $query->condition('n.type', (array) $this->configuration['node_types'], 'IN');
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
    return parent::prepareRow($row);
  }

}
