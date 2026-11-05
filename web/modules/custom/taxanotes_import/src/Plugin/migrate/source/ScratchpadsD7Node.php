<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) free-form nodes: the pages and the blog entries.
 *
 * One row per node of `node_type` (page, blog), the current revision only
 * (revisions are not migrated), keyed on the node uuid. Besides the node
 * columns (nid, vid, uuid, title, uid, status, created, changed, language,
 * promote, sticky) the row carries:
 * - body_value, body_summary, body_format: the body (text_with_summary),
 *   the row set in the node's own language, else `und`, else the first
 *   language (the rule of the other node sources);
 * - taxa: [{tid, guid}] of `taxon_field` (field_taxonomic_name), every term
 *   of the instance vocabulary the node names, in delta order (terms of
 *   other vocabularies are left out).
 *
 * Configuration keys:
 * - node_type: the D7 node type (required: page or blog).
 * - bundle: the D7 vocabulary machine name of the taxa (default from
 *   $settings['taxanotes_import']['source_vocabulary'], see the base class).
 * - taxon_field: the term reference to the taxa (default
 *   field_taxonomic_name).
 */
#[MigrateSource(
  id: 'scratchpads_d7_node',
)]
final class ScratchpadsD7Node extends ScratchpadsD7Base {

  /**
   * One {value, summary, format} per nid, loaded once.
   *
   * @var array<int, array{value: string|null, summary: string|null, format: string|null}>|null
   */
  protected ?array $bodies = NULL;

  /**
   * The taxa per nid, loaded once.
   *
   * @var array<int, array<int, array{tid: int, guid: string}>>|null
   */
  protected ?array $taxa = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $configuration += [
      'taxon_field' => 'field_taxonomic_name',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    if (empty($this->configuration['node_type'])) {
      throw new RequirementsException('The scratchpads_d7_node source needs a node_type.');
    }
    $schema = $this->getDatabase()->schema();
    if (!$schema->tableExists('node')) {
      throw new RequirementsException('The migrate database has no node table.');
    }
    if (!$schema->fieldExists('node', 'uuid')) {
      throw new RequirementsException('The migrate database has no node.uuid column; the page import keys on it.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('node', 'n')
      ->fields('n', [
        'nid', 'vid', 'uuid', 'title', 'uid', 'status', 'created', 'changed',
        'language', 'promote', 'sticky',
      ])
      ->condition('n.type', $this->configuration['node_type'])
      ->orderBy('n.nid');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'nid' => $this->t('Node ID'),
      'vid' => $this->t('Current revision ID'),
      'uuid' => $this->t('Node uuid, the migration id'),
      'title' => $this->t('Title'),
      'uid' => $this->t('Author uid'),
      'status' => $this->t('Published'),
      'created' => $this->t('Created'),
      'changed' => $this->t('Changed'),
      'language' => $this->t('Node language'),
      'promote' => $this->t('Promoted to the front page'),
      'sticky' => $this->t('Sticky'),
      'body_value' => $this->t('Body'),
      'body_summary' => $this->t('Body summary'),
      'body_format' => $this->t('Body text format'),
      'taxa' => $this->t('The taxa the node names: array of {tid, guid}, vocabulary terms only'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'uuid' => [
        'type' => 'string',
        'max_length' => 36,
        'alias' => 'n',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $nid = (int) $row->getSourceProperty('nid');
    $body = $this->bodies()[$nid] ?? [];
    $row->setSourceProperty('body_value', $body['value'] ?? NULL);
    $row->setSourceProperty('body_summary', $body['summary'] ?? NULL);
    $row->setSourceProperty('body_format', $body['format'] ?? NULL);
    $row->setSourceProperty('taxa', $this->taxa()[$nid] ?? []);
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  protected function doCount() {
    return (int) $this->select('node', 'n')
      ->condition('n.type', $this->configuration['node_type'])
      ->countQuery()->execute()->fetchField();
  }

  /**
   * One body per node (the language rule), by nid.
   */
  protected function bodies(): array {
    if ($this->bodies !== NULL) {
      return $this->bodies;
    }
    $this->bodies = [];
    if (!$this->getDatabase()->schema()->tableExists('field_data_body')) {
      return $this->bodies;
    }
    $type = $this->configuration['node_type'];
    $query = $this->select('field_data_body', 'f')
      ->fields('f', ['entity_id', 'body_value', 'body_summary', 'body_format'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $type)
      ->condition('f.deleted', 0)
      ->condition('f.delta', 0);
    $this->joinBestLanguage($query, 'field_data_body', 'f', 'node', $type, TRUE);
    foreach ($query->execute()->fetchAll() as $r) {
      $this->bodies[(int) $r['entity_id']] = [
        'value' => $r['body_value'],
        'summary' => $r['body_summary'],
        'format' => $r['body_format'],
      ];
    }
    return $this->bodies;
  }

  /**
   * The vocabulary taxa every node names, by nid, in delta order.
   */
  protected function taxa(): array {
    if ($this->taxa !== NULL) {
      return $this->taxa;
    }
    $this->taxa = [];
    $field = $this->configuration['taxon_field'];
    $table = 'field_data_' . $field;
    $vid = $this->vocabularyId();
    if (!$vid || !$this->getDatabase()->schema()->tableExists($table)) {
      return $this->taxa;
    }
    $type = $this->configuration['node_type'];
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id', 'delta'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $type)
      ->condition('f.deleted', 0)
      ->orderBy('f.entity_id')->orderBy('f.delta');
    $this->joinBestLanguage($query, $table, 'f', 'node', $type, TRUE);
    $query->innerJoin('taxonomy_term_data', 'td', "[td].[tid] = [f].[{$field}_tid]");
    $query->addField('td', 'tid');
    $query->condition('td.vid', $vid);
    $this->addGuid($query, 'td', 'guid');
    $seen = [];
    foreach ($query->execute()->fetchAll() as $r) {
      $nid = (int) $r['entity_id'];
      $tid = (int) $r['tid'];
      if ($r['guid'] === NULL || $r['guid'] === '' || isset($seen[$nid][$tid])) {
        continue;
      }
      $seen[$nid][$tid] = TRUE;
      $this->taxa[$nid][] = ['tid' => $tid, 'guid' => (string) $r['guid']];
    }
    return $this->taxa;
  }

}
