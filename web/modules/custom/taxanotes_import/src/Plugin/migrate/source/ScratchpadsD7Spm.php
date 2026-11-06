<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) taxon descriptions (SPM nodes): the species pages.
 *
 * One row per node of `node_type` (default spm, the Species Profile Model
 * node; the current revision only, revisions are not migrated), keyed on
 * the node uuid. Besides the node columns the row carries:
 * - <field>_value and <field>_format for every text_long field instance of
 *   the node type in the D7 site (the SPM sections: field_general_description,
 *   field_distribution, ...; discovered from field_config_instance, so a
 *   section one site added, like the Mormyrids field_electric_organ_discharge,
 *   is a property too, mapped when the migration names it);
 * - taxon_tid, taxon_guid: the taxon the page describes, the lowest-delta
 *   term of the instance vocabulary in `taxon_field` (a Scratchpads page
 *   may name more terms: terms of other vocabularies, e.g. the Myriatrix
 *   morphology terms, and the odd second taxon, are left out);
 * - media: [{fid}] of `media_field` in delta order.
 * Every field takes the row set in the node's own language, else `und`,
 * else the alphabetically first language, as the other node sources do.
 *
 * Configuration keys:
 * - bundle: the D7 vocabulary machine name of the taxa (default from
 *   $settings['taxanotes_import']['source_vocabulary'], see the base class).
 * - node_type: the D7 node type (default spm).
 * - taxon_field: the term reference to the taxon (default
 *   field_taxonomic_name).
 * - media_field: the file field placing images on the page (default
 *   field_media).
 */
#[MigrateSource(
  id: 'scratchpads_d7_spm',
)]
final class ScratchpadsD7Spm extends ScratchpadsD7Base {

  /**
   * The text_long field names of the node type, resolved once.
   *
   * @var string[]|null
   */
  protected ?array $textFields = NULL;

  /**
   * One {value, format} per nid of each text field, loaded once per field.
   *
   * @var array<string, array<int, array{value: string|null, format: string|null}>>
   */
  protected array $texts = [];

  /**
   * The taxon per nid, loaded once.
   *
   * @var array<int, array{tid: int, guid: string}>|null
   */
  protected ?array $taxa = NULL;

  /**
   * The placed images per nid, loaded once.
   *
   * @var array<int, array<int, array{fid: int}>>|null
   */
  protected ?array $media = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $configuration += [
      'node_type' => 'spm',
      'taxon_field' => 'field_taxonomic_name',
      'media_field' => 'field_media',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    foreach (['node', 'field_config_instance', 'field_config'] as $table) {
      if (!$schema->tableExists($table)) {
        throw new RequirementsException(sprintf('The migrate database has no %s table.', $table));
      }
    }
    if (!$schema->fieldExists('node', 'uuid')) {
      throw new RequirementsException('The migrate database has no node.uuid column; the species page import keys on it.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('node', 'n')
      ->fields('n', ['nid', 'vid', 'uuid', 'title', 'uid', 'status', 'created', 'changed', 'language'])
      ->condition('n.type', $this->configuration['node_type'])
      ->orderBy('n.nid');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'nid' => $this->t('Node ID'),
      'vid' => $this->t('Current revision ID'),
      'uuid' => $this->t('Node uuid, the migration id'),
      'title' => $this->t('Title'),
      'uid' => $this->t('Author uid'),
      'status' => $this->t('Published'),
      'created' => $this->t('Created'),
      'changed' => $this->t('Changed'),
      'language' => $this->t('Node language'),
      'taxon_tid' => $this->t('The taxon described: D7 tid'),
      'taxon_guid' => $this->t('The taxon described: GUID (for the taxon migration lookup)'),
      'media' => $this->t('Images placed on the page: array of {fid}'),
    ];
    foreach ($this->textFields() as $field) {
      $fields[$field . '_value'] = $this->t('@f text', ['@f' => $field]);
      $fields[$field . '_format'] = $this->t('@f text format', ['@f' => $field]);
    }
    return $fields;
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
    foreach ($this->textFields() as $field) {
      $text = $this->text($field)[$nid] ?? [];
      $row->setSourceProperty($field . '_value', $this->rewriteInlineImages($text['value'] ?? NULL));
      $row->setSourceProperty($field . '_format', $text['format'] ?? NULL);
    }
    $taxon = $this->taxa()[$nid] ?? NULL;
    $row->setSourceProperty('taxon_tid', $taxon['tid'] ?? NULL);
    $row->setSourceProperty('taxon_guid', $taxon['guid'] ?? NULL);
    $row->setSourceProperty('media', $this->media()[$nid] ?? []);
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
   * The text_long fields of the node type, in field name order.
   *
   * @return string[]
   *   D7 field names.
   */
  protected function textFields(): array {
    if ($this->textFields === NULL) {
      $query = $this->select('field_config_instance', 'fci')
        ->fields('fci', ['field_name'])
        ->condition('fci.entity_type', 'node')
        ->condition('fci.bundle', $this->configuration['node_type'])
        ->condition('fci.deleted', 0)
        ->orderBy('fci.field_name');
      $query->innerJoin('field_config', 'fc', '[fc].[id] = [fci].[field_id]');
      $query->condition('fc.type', 'text_long')
        ->condition('fc.deleted', 0);
      $this->textFields = array_values(array_unique($query->execute()->fetchCol()));
    }
    return $this->textFields;
  }

  /**
   * One {value, format} per node of a text field (the language rule).
   */
  protected function text(string $field): array {
    if (isset($this->texts[$field])) {
      return $this->texts[$field];
    }
    $this->texts[$field] = [];
    $table = 'field_data_' . $field;
    if (!$this->getDatabase()->schema()->tableExists($table)) {
      return $this->texts[$field];
    }
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $this->configuration['node_type'])
      ->condition('f.deleted', 0)
      ->condition('f.delta', 0);
    $query->addField('f', $field . '_value', 'value');
    $query->addField('f', $field . '_format', 'format');
    $this->joinBestLanguage($query, $table, 'f', 'node', $this->configuration['node_type'], TRUE);
    foreach ($query->execute()->fetchAll() as $r) {
      $this->texts[$field][(int) $r['entity_id']] = ['value' => $r['value'], 'format' => $r['format']];
    }
    return $this->texts[$field];
  }

  /**
   * The taxon every page describes, by nid: the first vocabulary term.
   */
  protected function taxa(): array {
    if ($this->taxa !== NULL) {
      return $this->taxa;
    }
    $this->taxa = [];
    $field = $this->configuration['taxon_field'];
    $table = 'field_data_' . $field;
    if (!$this->getDatabase()->schema()->tableExists($table)) {
      return $this->taxa;
    }
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id', 'delta'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $this->configuration['node_type'])
      ->condition('f.deleted', 0)
      ->orderBy('f.entity_id')->orderBy('f.delta');
    $this->joinBestLanguage($query, $table, 'f', 'node', $this->configuration['node_type'], TRUE);
    $query->innerJoin('taxonomy_term_data', 'td', "[td].[tid] = [f].[{$field}_tid]");
    $query->addField('td', 'tid');
    $vid = $this->vocabularyId();
    if ($vid) {
      $query->condition('td.vid', $vid);
    }
    $this->addGuid($query, 'td', 'guid');
    foreach ($query->execute()->fetchAll() as $r) {
      $nid = (int) $r['entity_id'];
      if (isset($this->taxa[$nid]) || $r['guid'] === NULL || $r['guid'] === '') {
        continue;
      }
      $this->taxa[$nid] = ['tid' => (int) $r['tid'], 'guid' => (string) $r['guid']];
    }
    return $this->taxa;
  }

  /**
   * The images placed on every page, by nid, in delta order.
   */
  protected function media(): array {
    if ($this->media !== NULL) {
      return $this->media;
    }
    $this->media = [];
    $field = $this->configuration['media_field'];
    $table = 'field_data_' . $field;
    if (!$this->getDatabase()->schema()->tableExists($table)) {
      return $this->media;
    }
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id', 'delta', $field . '_fid'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $this->configuration['node_type'])
      ->condition('f.deleted', 0)
      ->orderBy('f.entity_id')->orderBy('f.delta');
    $this->joinBestLanguage($query, $table, 'f', 'node', $this->configuration['node_type'], TRUE);
    foreach ($query->execute()->fetchAll() as $r) {
      if ($r[$field . '_fid']) {
        $this->media[(int) $r['entity_id']][] = ['fid' => (int) $r[$field . '_fid']];
      }
    }
    return $this->media;
  }

}
