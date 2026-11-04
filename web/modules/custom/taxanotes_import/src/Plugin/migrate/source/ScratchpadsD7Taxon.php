<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Site\Settings;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Row;
use Drupal\taxonomy\Plugin\migrate\source\d7\Term;

/**
 * Scratchpads (Drupal 7) taxonomy terms with their GUIDs and ITIS fields.
 *
 * Extends the core d7_taxonomy_term source so the vocabulary filter, the
 * database handling and the documented base columns are inherited, and adds:
 * - guid: feeds_item.guid for the term, falling back to the term's uuid for
 *   terms that were created by hand and never went through an importer (the
 *   Scratchpads exporter applies the same rule when it writes the GUID column);
 * - parent_guid / parent_name / parent_tid: the single parent from
 *   taxonomy_term_hierarchy (Scratchpads assumes one parent);
 * - accepted_guid / accepted_tid / accepted_name: field_aan_<vid>, the
 *   "associated accepted name" term reference;
 * - the scalar ITIS fields (rank, usage, unit names and indicators, authors,
 *   page number, unacceptability reason) joined in the main query rather than
 *   fetched per row, so a 30k-term vocabulary needs one SQL statement;
 * - page_url / page_url_title (link field), reference_nid / reference_title /
 *   reference_guid (node_reference to a biblio node);
 * - created / changed: min and max taxonomy_term_data_revision.timestamp;
 * - vernacular: the field_vernacular_name_collection items (name, language,
 *   area, remarks), fetched once for the whole vocabulary.
 *
 * Configuration keys:
 * - bundle: the D7 vocabulary machine name. When omitted it is read from
 *   $settings['taxanotes_import']['source_vocabulary'] so the migration YAML
 *   stays instance-agnostic.
 *
 * @MigrateSource(
 *   id = "scratchpads_d7_taxon",
 *   source_module = "taxonomy"
 * )
 */
class ScratchpadsD7Taxon extends Term {

  /**
   * Scalar D7 fields joined into the query: source alias => D7 field name.
   */
  protected const SCALAR_FIELDS = [
    'field_authors' => 'field_authors',
    'field_page_number' => 'field_page_number',
    'field_rank' => 'field_rank',
    'field_unacceptability_reason' => 'field_unacceptability_reason',
    'field_unit_indicator1' => 'field_unit_indicator1',
    'field_unit_indicator2' => 'field_unit_indicator2',
    'field_unit_indicator3' => 'field_unit_indicator3',
    'field_unit_indicator4' => 'field_unit_indicator4',
    'field_unit_name1' => 'field_unit_name1',
    'field_unit_name2' => 'field_unit_name2',
    'field_unit_name3' => 'field_unit_name3',
    'field_unit_name4' => 'field_unit_name4',
    'field_usage' => 'field_usage',
  ];

  /**
   * Vernacular names keyed by tid, loaded once.
   *
   * @var array<int, array<int, array<string, string|null>>>|null
   */
  protected ?array $vernacular = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_type_manager) {
    if (empty($configuration['bundle'])) {
      $instance = Settings::get('taxanotes_import', []);
      if (!empty($instance['source_vocabulary'])) {
        $configuration['bundle'] = $instance['source_vocabulary'];
      }
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state, $entity_type_manager);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = parent::query();
    $db = $this->getDatabase();

    // GUID: Feeds GUID first, term uuid as the fallback.
    $query->leftJoin('feeds_item', 'fi', "[fi].[entity_type] = 'taxonomy_term' AND [fi].[entity_id] = [td].[tid]");
    $query->addExpression("COALESCE(NULLIF([fi].[guid], ''), [td].[uuid])", 'guid');
    $query->addField('fi', 'imported', 'feeds_imported');

    // Single parent (Scratchpads never writes more than one row per term).
    $query->leftJoin('taxonomy_term_hierarchy', 'th', '[th].[tid] = [td].[tid] AND [th].[parent] <> 0');
    $query->leftJoin('taxonomy_term_data', 'tdp', '[tdp].[tid] = [th].[parent]');
    $query->leftJoin('feeds_item', 'fip', "[fip].[entity_type] = 'taxonomy_term' AND [fip].[entity_id] = [tdp].[tid]");
    $query->addField('th', 'parent', 'parent_tid');
    $query->addField('tdp', 'name', 'parent_name');
    $query->addExpression("COALESCE(NULLIF([fip].[guid], ''), [tdp].[uuid])", 'parent_guid');

    // Revision timestamps give created/changed (D7 terms have none of their own).
    $rev = $db->select('taxonomy_term_data_revision', 'r');
    $rev->addField('r', 'tid');
    $rev->addExpression('MIN([r].[timestamp])', 'created');
    $rev->addExpression('MAX([r].[timestamp])', 'changed');
    $rev->groupBy('r.tid');
    $query->leftJoin($rev, 'rev', '[rev].[tid] = [td].[tid]');
    $query->addField('rev', 'created', 'created');
    $query->addField('rev', 'changed', 'changed');

    // Scalar ITIS fields: one LEFT JOIN each on the field data table.
    foreach (static::SCALAR_FIELDS as $alias => $field_name) {
      $table = 'field_data_' . $field_name;
      if (!$db->schema()->tableExists($table)) {
        continue;
      }
      $query->leftJoin($table, $alias, "[$alias].[entity_type] = 'taxonomy_term' AND [$alias].[entity_id] = [td].[tid] AND [$alias].[deleted] = 0 AND [$alias].[delta] = 0");
      $query->addField($alias, $field_name . '_value', $alias);
    }

    // Associated accepted name: a term reference whose field name carries the
    // vocabulary id (field_aan_<vid>). Resolve it by vocabulary at query time.
    $aan_table = $this->acceptedNameTable();
    if ($aan_table) {
      $column = substr($aan_table, strlen('field_data_')) . '_tid';
      $query->leftJoin($aan_table, 'aan', "[aan].[entity_type] = 'taxonomy_term' AND [aan].[entity_id] = [td].[tid] AND [aan].[deleted] = 0 AND [aan].[delta] = 0");
      $query->leftJoin('taxonomy_term_data', 'tda', "[tda].[tid] = [aan].[$column]");
      $query->leftJoin('feeds_item', 'fia', "[fia].[entity_type] = 'taxonomy_term' AND [fia].[entity_id] = [tda].[tid]");
      $query->addField('aan', $column, 'accepted_tid');
      $query->addField('tda', 'name', 'accepted_name');
      $query->addExpression("COALESCE(NULLIF([fia].[guid], ''), [tda].[uuid])", 'accepted_guid');
    }

    // Protologue reference (node_reference to a biblio node).
    if ($db->schema()->tableExists('field_data_field_reference')) {
      $query->leftJoin('field_data_field_reference', 'ref', "[ref].[entity_type] = 'taxonomy_term' AND [ref].[entity_id] = [td].[tid] AND [ref].[deleted] = 0 AND [ref].[delta] = 0");
      $query->leftJoin('node', 'refn', '[refn].[nid] = [ref].[field_reference_nid]');
      $query->addField('ref', 'field_reference_nid', 'reference_nid');
      $query->addField('refn', 'title', 'reference_title');
      $query->addField('refn', 'uuid', 'reference_guid');
    }

    // Page URL (link field).
    if ($db->schema()->tableExists('field_data_field_page_url')) {
      $query->leftJoin('field_data_field_page_url', 'purl', "[purl].[entity_type] = 'taxonomy_term' AND [purl].[entity_id] = [td].[tid] AND [purl].[deleted] = 0 AND [purl].[delta] = 0");
      $query->addField('purl', 'field_page_url_url', 'page_url');
      $query->addField('purl', 'field_page_url_title', 'page_url_title');
    }

    return $query;
  }

  /**
   * Finds the field_data_field_aan_<vid> table for the configured vocabulary.
   */
  protected function acceptedNameTable(): ?string {
    $db = $this->getDatabase();
    if (empty($this->configuration['bundle'])) {
      return NULL;
    }
    $vid = $db->select('taxonomy_vocabulary', 'tv')
      ->fields('tv', ['vid'])
      ->condition('machine_name', (array) $this->configuration['bundle'], 'IN')
      ->execute()
      ->fetchField();
    if (!$vid) {
      return NULL;
    }
    $table = 'field_data_field_aan_' . $vid;
    return $db->schema()->tableExists($table) ? $table : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return parent::fields() + [
      'guid' => $this->t('Scratchpads GUID (feeds_item.guid, else the term uuid).'),
      'uuid' => $this->t('The term uuid (contrib uuid module).'),
      'feeds_imported' => $this->t('Timestamp of the last Feeds import of the term, if any.'),
      'parent_tid' => $this->t('Parent term ID.'),
      'parent_guid' => $this->t('Parent GUID.'),
      'parent_name' => $this->t('Parent term name.'),
      'created' => $this->t('Earliest revision timestamp.'),
      'changed' => $this->t('Latest revision timestamp.'),
      'accepted_tid' => $this->t('Associated accepted name: term ID.'),
      'accepted_guid' => $this->t('Associated accepted name: GUID.'),
      'accepted_name' => $this->t('Associated accepted name: term name.'),
      'reference_nid' => $this->t('Protologue reference: biblio node ID.'),
      'reference_title' => $this->t('Protologue reference: title.'),
      'reference_guid' => $this->t('Protologue reference: node uuid.'),
      'page_url' => $this->t('Protologue page URL.'),
      'page_url_title' => $this->t('Protologue page URL title.'),
      'vernacular' => $this->t('Vernacular names: array of name, language, area, remarks.'),
    ] + array_combine(array_keys(static::SCALAR_FIELDS), array_map(fn($f) => $this->t('D7 @field value', ['@field' => $f]), static::SCALAR_FIELDS));
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'guid' => [
        'type' => 'string',
        'max_length' => 255,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    // The field values are already in the row (joined in query()), so skip the
    // core Term::prepareRow() per-field and per-parent queries and go straight
    // to the base implementation (hook_migrate_prepare_row()).
    $tid = (int) $row->getSourceProperty('tid');
    $row->setSourceProperty('vernacular', $this->vernacularNames()[$tid] ?? []);
    $row->setSourceProperty('parent', array_filter([(int) $row->getSourceProperty('parent_tid')]));
    return SourcePluginBase::prepareRow($row);
  }

  /**
   * Loads every vernacular-name collection item of the vocabulary, once.
   */
  protected function vernacularNames(): array {
    if ($this->vernacular !== NULL) {
      return $this->vernacular;
    }
    $this->vernacular = [];
    $db = $this->getDatabase();
    if (!$db->schema()->tableExists('field_data_field_vernacular_name_collection')) {
      return $this->vernacular;
    }
    $query = $db->select('field_data_field_vernacular_name_collection', 'c')
      ->fields('c', ['entity_id', 'delta'])
      ->condition('c.entity_type', 'taxonomy_term')
      ->condition('c.deleted', 0)
      ->orderBy('c.entity_id')
      ->orderBy('c.delta');
    if (!empty($this->configuration['bundle'])) {
      $query->condition('c.bundle', (array) $this->configuration['bundle'], 'IN');
    }
    $sub = [
      'name' => ['field_data_field_vernacular_name', 'field_vernacular_name_value'],
      'language' => ['field_data_field_language', 'field_language_value'],
      'area' => ['field_data_field_vernacular_locality', 'field_vernacular_locality_value'],
      'remarks' => ['field_data_field_vernacular_name_remarks', 'field_vernacular_name_remarks_value'],
    ];
    foreach ($sub as $alias => [$table, $column]) {
      if ($db->schema()->tableExists($table)) {
        $query->leftJoin($table, $alias, "[$alias].[entity_type] = 'field_collection_item' AND [$alias].[entity_id] = [c].[field_vernacular_name_collection_value] AND [$alias].[deleted] = 0");
        $query->addField($alias, $column, $alias);
      }
    }
    foreach ($query->execute() as $record) {
      $this->vernacular[(int) $record->entity_id][] = [
        'name' => $record->name ?? NULL,
        'language' => $record->language ?? NULL,
        'area' => $record->area ?? NULL,
        'remarks' => $record->remarks ?? NULL,
      ];
    }
    return $this->vernacular;
  }

}
