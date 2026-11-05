<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Site\Settings;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Shared behaviour of the Scratchpads (Drupal 7) taxon source plugins.
 *
 * Built directly on the migrate SqlBase plugin (migrate_drupal's
 * DrupalSqlBase/FieldableEntity/d7 Term hierarchy is deprecated in Drupal
 * 11.3 and removed in 12 with no replacement). Holds what the taxon and the
 * taxon-revision sources have in common: the vocabulary ("bundle") read from
 * settings when the migration omits it, the GUID expression, the ITIS field
 * list, the unit names folded into one property and the lookup of the
 * vocabulary-specific "accepted name" field.
 *
 * Configuration keys:
 * - bundle: the D7 vocabulary machine name. When omitted it is read from
 *   $settings['taxanotes_import']['source_vocabulary'] so the migration YAML
 *   stays instance-agnostic.
 * - key / database_state_key, track_changes, ignore_map, constants: see
 *   SqlBase and SourcePluginBase.
 */
abstract class ScratchpadsD7Base extends SqlBase {

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
   * The number of unit positions Scratchpads stores (unit_name1..4).
   */
  protected const UNIT_POSITIONS = 4;

  /**
   * The nomenclature term fields: D7 field name => [D11 field name, kind].
   *
   * The term fields a Scratchpads vocabulary may carry beyond the ITIS set
   * (SCALAR_FIELDS, the accepted name, the protologue reference, the page
   * URL and the vernacular names): Mormyrids has the type material, type
   * locality, original combination, free-text synonym lists and taxonomic
   * notes; Anthophila the synonymy and designation fields, the family-level
   * name origins, the type material notes and the biological notes. Each
   * lands in the generic optional field of the taxon type named here
   * (recipes/taxanotes_taxon, the two references in
   * recipes/taxanotes_literature); three D7 names are misleading and are
   * renamed (field_description_taxa holds the type material,
   * field_type_species the type material notes, field_designation_method
   * the designation reference). The kind says how the value travels:
   * - string: one plain value (D7 text, 255 characters) as the property;
   * - text: the items as a list of {value, format} (D7 text_long, or a
   *   text field longer than 255 characters), the format mapped like the
   *   term description (NULL and plain_text -> plain_text, filtered_html ->
   *   basic_html, full_html -> full_html);
   * - reference: a node_reference to a biblio node; the property
   *   <D11 name>_guid carries the node uuid (and <D11 name>_nid the nid), to
   *   be resolved through the biblio migration by the reference pass.
   * A vocabulary's term field that is not in this table is not migrated
   * (scripts/verify-instance.php lists such fields).
   */
  protected const EXTRA_FIELDS = [
    'field_description_taxa' => ['field_type_material', 'text'],
    'field_type_species' => ['field_type_material_notes', 'text'],
    'field_type_locality' => ['field_type_locality', 'text'],
    'field_original_combination' => ['field_original_combination', 'string'],
    'field_synonyms' => ['field_synonyms_homotypic', 'text'],
    'field_synonyms_heterotypic' => ['field_synonyms_heterotypic', 'text'],
    'field_synonymy_by' => ['field_synonymy_by', 'string'],
    'field_page_number_synonymy' => ['field_page_number_synonymy', 'string'],
    'field_synonymy_reference' => ['field_synonymy_reference', 'reference'],
    'field_designation_by' => ['field_designation_by', 'string'],
    'field_page_number_designation' => ['field_page_number_designation', 'string'],
    'field_designation_method' => ['field_designation_reference', 'reference'],
    'field_family_level_name_origins' => ['field_family_level_name_origins', 'text'],
    'field_taxonomic_notes' => ['field_taxonomic_notes', 'text'],
    'field_biological_notes' => ['field_biological_notes', 'text'],
  ];

  /**
   * D7 text format => D11 text format of a nomenclature text value.
   */
  protected const TEXT_FORMATS = [
    'filtered_html' => 'basic_html',
    'full_html' => 'full_html',
    'plain_text' => 'plain_text',
  ];

  /**
   * The nomenclature fields the vocabulary has, resolved once.
   *
   * @var array<string, array{string, string}>|null
   */
  protected ?array $extraFields = NULL;

  /**
   * Sub-fields of a vernacular-name collection item: source key => D7 field.
   */
  protected const VERNACULAR_FIELDS = [
    'name' => 'field_vernacular_name',
    'language' => 'field_language',
    'area' => 'field_vernacular_locality',
    'remarks' => 'field_vernacular_name_remarks',
  ];

  /**
   * The D7 vocabulary id of the configured bundle, resolved once.
   */
  protected int|false|null $vocabularyId = NULL;

  /**
   * Whether taxonomy_term_data.language exists, resolved once.
   */
  protected ?bool $termsHaveLanguage = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $instance = Settings::get('taxanotes_import', []);
    if (empty($configuration['bundle']) && !empty($instance['source_vocabulary'])) {
      $configuration['bundle'] = $instance['source_vocabulary'];
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   *
   * Besides the database connection, a Scratchpads source needs a GUID per
   * term: feeds_item (Feeds) or taxonomy_term_data.uuid (uuid module).
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    if (!$schema->tableExists('taxonomy_term_data') || !$schema->tableExists('taxonomy_vocabulary')) {
      throw new RequirementsException('The migrate database is not a Drupal 7 database (taxonomy tables are missing).');
    }
    if (!$schema->tableExists('feeds_item') && !$schema->fieldExists('taxonomy_term_data', 'uuid')) {
      throw new RequirementsException('The source needs feeds_item or taxonomy_term_data.uuid to provide a GUID per term; neither exists.');
    }
  }

  /**
   * The D7 public files directory of this instance from settings, or NULL.
   *
   * $settings['taxanotes_import']['source_files_dir'], set by the instance
   * scripts from instance.yml source.files (or the SOURCE_FILES_DIR of the
   * instance's .env); unset on an instance whose Scratchpads files are not
   * on this machine.
   */
  protected function settingsFilesDir(): ?string {
    $dir = Settings::get('taxanotes_import', [])['source_files_dir'] ?? NULL;
    return (is_string($dir) && $dir !== '') ? $dir : NULL;
  }

  /**
   * Restricts a query to the configured vocabulary, if any.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query; it must already join taxonomy_vocabulary as $alias.
   * @param string $alias
   *   The taxonomy_vocabulary alias in the query.
   */
  protected function addBundleCondition(SelectInterface $query, string $alias = 'tv'): void {
    if (isset($this->configuration['bundle'])) {
      $query->condition("$alias.machine_name", (array) $this->configuration['bundle'], 'IN');
    }
  }

  /**
   * SQL for "Feeds GUID, else the term uuid" over the given aliases.
   */
  protected function guidExpression(string $feeds_alias, string $term_alias, bool $has_uuid): string {
    return $has_uuid
      ? "COALESCE(NULLIF([$feeds_alias].[guid], ''), [$term_alias].[uuid])"
      : "NULLIF([$feeds_alias].[guid], '')";
  }

  /**
   * Adds the GUID of the term aliased $term_alias to the query as $as.
   */
  protected function addGuid(SelectInterface $query, string $term_alias, string $as): void {
    $schema = $this->getDatabase()->schema();
    $has_uuid = $schema->fieldExists('taxonomy_term_data', 'uuid');
    if ($schema->tableExists('feeds_item')) {
      $feeds_alias = 'fi_' . $as;
      $query->leftJoin('feeds_item', $feeds_alias, "[$feeds_alias].[entity_type] = 'taxonomy_term' AND [$feeds_alias].[entity_id] = [$term_alias].[tid]");
      $query->addExpression($this->guidExpression($feeds_alias, $term_alias, $has_uuid), $as);
    }
    else {
      $query->addExpression("[$term_alias].[uuid]", $as);
    }
  }

  /**
   * LEFT JOINs one row of a D7 field table per term (or per term revision).
   *
   * Scratchpads fields are translatable (entity_translation), so a term can
   * carry one row per language for the same delta: Myriatrix has `en`/`und`
   * pairs with equal values, Mormyrids a term with an `en` and a `fr` row
   * holding different authors and page numbers, and vernacular-name items
   * with two language rows. Joining the table on delta 0 alone then yields
   * one source row per combination, i.e. the same GUID (or the same
   * vernacular name) several times, and the last one wins. This joins
   * exactly one row: the one in the term's own language, else the
   * language-neutral (`und`) row, else
   * the alphabetically first language (what the D7 site shows on its default
   * path). The choice is a derived table grouped per entity (and revision),
   * so the main query stays one set-based statement.
   *
   * "The term's own language" is taxonomy_term_data.language as it is now,
   * also for the revision tables (the revision table carries no language of
   * its own): a historic revision is ranked by the term's current language,
   * not by whatever language the term had when that revision was saved. The
   * two differ only for a term whose language changed between revisions and
   * whose fields hold rows in several languages with different values; no
   * term of the two pilot vocabularies does, and the current revision takes
   * its values from the data tables anyway (ScratchpadsD7TaxonRevision).
   *
   * A Scratchpads site that never enabled entity_translation for terms has
   * no taxonomy_term_data.language column at all (the 2022 Anthophila dump;
   * its field rows are all `und`): then there is no own language to prefer
   * and the rule is `und` first, else the alphabetically first language.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query; $entity_alias must be taxonomy_term_data (or, for revisions,
   *   taxonomy_term_data_revision) with the columns tid and revision_id.
   * @param string $field_name
   *   The D7 field name (field_rank, field_aan_5, ...).
   * @param string $alias
   *   Alias of the joined field table in the query.
   * @param string $entity_alias
   *   Alias of the term (or term revision) table to join on.
   * @param bool $revision
   *   TRUE to join field_revision_<name> on (tid, revision_id) instead of
   *   field_data_<name> on tid.
   * @param string $entity_type
   *   The D7 entity type the field is attached to ('taxonomy_term', or
   *   'field_collection_item' for the vernacular-name sub-fields). Only a
   *   term has a language of its own to prefer.
   * @param string $id_column
   *   The column of $entity_alias holding the entity id.
   * @param string $revision_column
   *   The column of $entity_alias holding the entity revision id.
   */
  protected function joinFieldTable(SelectInterface $query, string $field_name, string $alias, string $entity_alias, bool $revision = FALSE, string $entity_type = 'taxonomy_term', string $id_column = 'tid', string $revision_column = 'revision_id'): void {
    $table = ($revision ? 'field_revision_' : 'field_data_') . $field_name;
    $best_alias = 'best_' . $alias;

    $best = $this->getDatabase()->select($table, 'f');
    $best->addField('f', 'entity_id');
    if ($entity_type === 'taxonomy_term' && $this->termsHaveLanguage()) {
      $best->innerJoin('taxonomy_term_data', 't', '[t].[tid] = [f].[entity_id]');
      $rank = "CASE WHEN [f].[language] = [t].[language] THEN '0' WHEN [f].[language] = 'und' THEN '1' ELSE '2' END";
    }
    else {
      // Other entities (field collection items), and terms of a site without
      // entity_translation, have no language of their own to prefer.
      $rank = "CASE WHEN [f].[language] = 'und' THEN '1' ELSE '2' END";
    }
    $best->addExpression("SUBSTRING(MIN(CONCAT($rank, [f].[language])), 2)", 'language');
    $best->condition('f.entity_type', $entity_type)
      ->condition('f.deleted', 0)
      ->condition('f.delta', 0)
      ->groupBy('f.entity_id');
    $on = "[$best_alias].[entity_id] = [$entity_alias].[$id_column]";
    $join = "[$alias].[entity_type] = '$entity_type' AND [$alias].[entity_id] = [$entity_alias].[$id_column] AND [$alias].[deleted] = 0 AND [$alias].[delta] = 0 AND [$alias].[language] = [$best_alias].[language]";
    if ($revision) {
      $best->addField('f', 'revision_id');
      $best->groupBy('f.revision_id');
      $on .= " AND [$best_alias].[revision_id] = [$entity_alias].[$revision_column]";
      $join .= " AND [$alias].[revision_id] = [$entity_alias].[$revision_column]";
    }
    $query->leftJoin($best, $best_alias, $on);
    $query->leftJoin($table, $alias, $join);
  }

  /**
   * Whether taxonomy_term_data has a language column (entity_translation).
   *
   * The column is added by the entity_translation module (D7 core terms have
   * none); Myriatrix and Mormyrids have it, the 2022 Anthophila dump has not.
   */
  protected function termsHaveLanguage(): bool {
    if ($this->termsHaveLanguage === NULL) {
      $this->termsHaveLanguage = $this->getDatabase()->schema()->fieldExists('taxonomy_term_data', 'language');
    }
    return $this->termsHaveLanguage;
  }

  /**
   * Joins the ITIS fields of a term (or term revision) into a query.
   *
   * Adds, each only when its D7 table exists and one row per entity
   * (joinFieldTable()): the scalar fields of SCALAR_FIELDS as source
   * properties named after their alias; the associated accepted name
   * (accepted_tid, accepted_name, accepted_guid); the protologue reference
   * (reference_nid, reference_title, reference_guid); the page URL (page_url,
   * page_url_title). Both sources read the same property set through this
   * method: the taxon source from the field_data_* tables, the revision
   * source from field_revision_* for the history and from field_data_* for
   * the current revision (what the D7 site renders).
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query; $entity_alias must be taxonomy_term_data (or
   *   taxonomy_term_data_revision) with the columns tid and revision_id.
   * @param string $entity_alias
   *   Alias of the term (or term revision) table.
   * @param bool $revision
   *   TRUE to read the field_revision_* tables on (tid, revision_id).
   */
  protected function joinItisFields(SelectInterface $query, string $entity_alias, bool $revision = FALSE): void {
    $schema = $this->getDatabase()->schema();
    $prefix = $revision ? 'field_revision_' : 'field_data_';
    foreach (static::SCALAR_FIELDS as $alias => $field_name) {
      if (!$schema->tableExists($prefix . $field_name)) {
        continue;
      }
      $this->joinFieldTable($query, $field_name, $alias, $entity_alias, $revision);
      $query->addField($alias, $field_name . '_value', $alias);
    }
    // Associated accepted name: a term reference whose field name carries
    // the vocabulary id (field_aan_<vid>), resolved by vocabulary.
    $aan_field = $this->acceptedNameField();
    if ($aan_field && $schema->tableExists($prefix . $aan_field)) {
      $column = $aan_field . '_tid';
      $this->joinFieldTable($query, $aan_field, 'aan', $entity_alias, $revision);
      $query->leftJoin('taxonomy_term_data', 'tda', "[tda].[tid] = [aan].[$column]");
      $query->addField('aan', $column, 'accepted_tid');
      $query->addField('tda', 'name', 'accepted_name');
      $this->addGuid($query, 'tda', 'accepted_guid');
    }
    // Protologue reference (node_reference to a biblio node).
    if ($schema->tableExists($prefix . 'field_reference')) {
      $this->joinFieldTable($query, 'field_reference', 'ref', $entity_alias, $revision);
      $query->leftJoin('node', 'refn', '[refn].[nid] = [ref].[field_reference_nid]');
      $query->addField('ref', 'field_reference_nid', 'reference_nid');
      $query->addField('refn', 'title', 'reference_title');
      $query->addField('refn', 'uuid', 'reference_guid');
    }
    // Page URL (link field).
    if ($schema->tableExists($prefix . 'field_page_url')) {
      $this->joinFieldTable($query, 'field_page_url', 'purl', $entity_alias, $revision);
      $query->addField('purl', 'field_page_url_url', 'page_url');
      $query->addField('purl', 'field_page_url_title', 'page_url_title');
    }
  }

  /**
   * Loads the vernacular-name collection items of the vocabulary.
   *
   * The collection field row stores the field_collection_item id (and, in
   * the revision table, the item revision id); the item's own values live in
   * the field tables of the field_collection_item entity type, one row per
   * sub-field and item whatever languages it holds (joinFieldTable()).
   *
   * @param bool $revision
   *   FALSE for the current items (field_data_*, keyed by term id), TRUE for
   *   the items of every term revision (field_revision_*, keyed by term
   *   revision id).
   *
   * @return array<int, array<int, array<string, string|null>>>
   *   Items (name, language, area, remarks) in delta order, keyed by term id
   *   or term revision id.
   */
  protected function loadVernacularNames(bool $revision): array {
    $items = [];
    $schema = $this->getDatabase()->schema();
    $prefix = $revision ? 'field_revision_' : 'field_data_';
    if (!$schema->tableExists($prefix . 'field_vernacular_name_collection')) {
      return $items;
    }
    $key = $revision ? 'revision_id' : 'entity_id';
    // $this->select() fetches associative arrays (SqlBase sets the fetch mode).
    $query = $this->select($prefix . 'field_vernacular_name_collection', 'c')
      ->fields('c', [$key, 'delta'])
      ->condition('c.entity_type', 'taxonomy_term')
      ->condition('c.deleted', 0)
      ->orderBy('c.' . $key)
      ->orderBy('c.delta');
    if (!empty($this->configuration['bundle'])) {
      $query->condition('c.bundle', (array) $this->configuration['bundle'], 'IN');
    }
    foreach (static::VERNACULAR_FIELDS as $alias => $field_name) {
      if ($schema->tableExists($prefix . $field_name)) {
        $this->joinFieldTable($query, $field_name, $alias, 'c', $revision, 'field_collection_item', 'field_vernacular_name_collection_value', 'field_vernacular_name_collection_revision_id');
        $query->addField($alias, $field_name . '_value', $alias);
      }
    }
    foreach ($query->execute()->fetchAll() as $record) {
      $items[(int) $record[$key]][] = [
        'name' => $record['name'] ?? NULL,
        'language' => $record['language'] ?? NULL,
        'area' => $record['area'] ?? NULL,
        'remarks' => $record['remarks'] ?? NULL,
      ];
    }
    return $items;
  }

  /**
   * LEFT JOINs the derived table of the best language per entity of a field.
   *
   * Scratchpads node fields are translatable too (entity_translation), so a
   * node can carry one row set per language. This joins exactly one set: the
   * one in the entity's own language (nodes; `own_language`), else `und`,
   * else the alphabetically first language (what the D7 site shows on its
   * default path), as joinFieldTable() ranks term fields. Entities without a
   * language of their own (files) rank `und` first.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The query; $field_alias must be the field_data_* table in it.
   * @param string $table
   *   The field_data_* table.
   * @param string $field_alias
   *   Its alias in the query.
   * @param string $entity_type
   *   The D7 entity type of the rows (node, file).
   * @param string|array|null $bundle
   *   The bundle(s) to rank, NULL for any.
   * @param bool $own_language
   *   TRUE to prefer the entity's own language (nodes only).
   */
  protected function joinBestLanguage(SelectInterface $query, string $table, string $field_alias, string $entity_type, string|array|null $bundle, bool $own_language): void {
    $best = $this->getDatabase()->select($table, 'f2');
    $best->addField('f2', 'entity_id');
    if ($own_language && $entity_type === 'node') {
      $best->innerJoin('node', 'n2', '[n2].[nid] = [f2].[entity_id]');
      $rank = "CASE WHEN [f2].[language] = [n2].[language] THEN '0' WHEN [f2].[language] = 'und' THEN '1' ELSE '2' END";
    }
    else {
      $rank = "CASE WHEN [f2].[language] = 'und' THEN '1' ELSE '2' END";
    }
    $best->addExpression("SUBSTRING(MIN(CONCAT($rank, [f2].[language])), 2)", 'language');
    $best->condition('f2.entity_type', $entity_type)
      ->condition('f2.deleted', 0)
      ->groupBy('f2.entity_id');
    if ($bundle !== NULL) {
      $best->condition('f2.bundle', (array) $bundle, 'IN');
    }
    $query->innerJoin($best, 'best_' . $field_alias, "[best_$field_alias].[entity_id] = [$field_alias].[entity_id] AND [best_$field_alias].[language] = [$field_alias].[language]");
  }

  /**
   * The ids of the files referenced from a file field of given node bundles.
   *
   * A subquery for an IN condition: the distinct fids of the current rows of
   * `field_data_<field>` on nodes of `bundles` (any language).
   */
  protected function nodeFileIds(string $field, array $bundles): SelectInterface {
    $used = $this->getDatabase()->select('field_data_' . $field, 'nf');
    $used->addField('nf', $field . '_fid');
    $used->condition('nf.entity_type', 'node')
      ->condition('nf.bundle', $bundles, 'IN')
      ->condition('nf.deleted', 0)
      ->isNotNull('nf.' . $field . '_fid')
      ->distinct();
    return $used;
  }

  /**
   * The ids of the files tagged with a term of the instance vocabulary.
   *
   * A subquery for an IN condition: the distinct file ids carrying, in
   * `field_data_<field>` (entity type file, any language), a term of the
   * configured vocabulary; none when the vocabulary is unknown.
   */
  protected function taggedFileIds(string $field): SelectInterface {
    $tagged = $this->getDatabase()->select('field_data_' . $field, 'ft');
    $tagged->addField('ft', 'entity_id');
    $tagged->innerJoin('taxonomy_term_data', 'ftd', "[ftd].[tid] = [ft].[{$field}_tid]");
    $tagged->condition('ft.entity_type', 'file')
      ->condition('ft.deleted', 0)
      ->condition('ftd.vid', $this->vocabularyId() ?: -1)
      ->distinct();
    return $tagged;
  }

  /**
   * The unit names of a row as the items of field_unit_names.
   *
   * Scratchpads keeps the parts of a scientific name in four field pairs,
   * field_unit_indicator1..4 / field_unit_name1..4 (read as they are, see
   * SCALAR_FIELDS). The taxon type holds them in one multi-value field: one
   * item {indicator, name} per position whose name is non-empty once trimmed,
   * in position order, so the delta is the unit's order within the name
   * (both pilot vocabularies fill the positions contiguously; a gap would
   * close up). A blank or missing indicator is NULL, a blank name drops the
   * position with its indicator.
   *
   * @param \Drupal\migrate\Row $row
   *   A source row carrying the SCALAR_FIELDS properties.
   *
   * @return array<int, array{indicator: string|null, name: string}>
   *   The unit items, in order; empty when the term has no unit name.
   */
  protected function unitNames(Row $row): array {
    $items = [];
    for ($i = 1; $i <= static::UNIT_POSITIONS; $i++) {
      $name = $row->getSourceProperty("field_unit_name$i");
      $name = is_string($name) ? trim($name) : '';
      if ($name === '') {
        continue;
      }
      $indicator = $row->getSourceProperty("field_unit_indicator$i");
      $indicator = is_string($indicator) ? trim($indicator) : '';
      $items[] = [
        'indicator' => $indicator === '' ? NULL : $indicator,
        'name' => $name,
      ];
    }
    return $items;
  }

  /**
   * The nomenclature term fields of the vocabulary: D7 name => [D11, kind].
   *
   * The subset of EXTRA_FIELDS that has an instance on the configured
   * vocabulary (field_config_instance) and a data table. Nothing for a
   * vocabulary without them (Myriatrix): its rows carry no extra property,
   * so their hashes do not change.
   *
   * @return array<string, array{string, string}>
   *   D7 field name => [D11 field name, kind], in EXTRA_FIELDS order.
   */
  protected function extraFields(): array {
    if ($this->extraFields !== NULL) {
      return $this->extraFields;
    }
    $this->extraFields = [];
    $schema = $this->getDatabase()->schema();
    if (empty($this->configuration['bundle']) || !$schema->tableExists('field_config_instance')) {
      return $this->extraFields;
    }
    $names = $this->select('field_config_instance', 'fci')
      ->fields('fci', ['field_name'])
      ->condition('fci.entity_type', 'taxonomy_term')
      ->condition('fci.bundle', (array) $this->configuration['bundle'], 'IN')
      ->condition('fci.deleted', 0)
      ->execute()
      ->fetchCol();
    foreach (static::EXTRA_FIELDS as $d7_name => $target) {
      if (in_array($d7_name, $names, TRUE) && $schema->tableExists('field_data_' . $d7_name)) {
        $this->extraFields[$d7_name] = $target;
      }
    }
    return $this->extraFields;
  }

  /**
   * Loads the values of every nomenclature field of the vocabulary, once.
   *
   * One query per field over field_data_<name> (keyed by tid) or
   * field_revision_<name> (keyed by term revision id), every delta of the
   * row set in the term's own language, else `und`, else the first
   * language (the rule of joinFieldTable()), shaped per kind (EXTRA_FIELDS):
   * a string, a list of {value, format}, or for a reference the uuid and
   * nid of the biblio node under <D11 name>_guid / <D11 name>_nid. A term
   * (or revision) without a value for a field gets NULL for that property,
   * so every row of the vocabulary carries the same property set.
   *
   * @param bool $revision
   *   TRUE for the field_revision_* tables, keyed by term revision id.
   *
   * @return array<int, array<string, mixed>>
   *   Source properties keyed by tid or revision id.
   */
  protected function loadExtraFieldValues(bool $revision): array {
    $values = [];
    $fields = $this->extraFields();
    if (!$fields) {
      return $values;
    }
    $schema = $this->getDatabase()->schema();
    $prefix = $revision ? 'field_revision_' : 'field_data_';
    $key = $revision ? 'revision_id' : 'entity_id';
    foreach ($fields as $d7_name => [$name, $kind]) {
      $table = $prefix . $d7_name;
      if (!$schema->tableExists($table)) {
        continue;
      }
      // The best language per term (and revision), as joinFieldTable() ranks
      // it, over every delta of the field.
      $best = $this->getDatabase()->select($table, 'f');
      $best->addField('f', 'entity_id');
      if ($this->termsHaveLanguage()) {
        $best->innerJoin('taxonomy_term_data', 't', '[t].[tid] = [f].[entity_id]');
        $rank = "CASE WHEN [f].[language] = [t].[language] THEN '0' WHEN [f].[language] = 'und' THEN '1' ELSE '2' END";
      }
      else {
        $rank = "CASE WHEN [f].[language] = 'und' THEN '1' ELSE '2' END";
      }
      $best->addExpression("SUBSTRING(MIN(CONCAT($rank, [f].[language])), 2)", 'language');
      $best->condition('f.entity_type', 'taxonomy_term')
        ->condition('f.deleted', 0)
        ->condition('f.bundle', (array) $this->configuration['bundle'], 'IN')
        ->groupBy('f.entity_id');
      $on = '[best].[entity_id] = [d].[entity_id] AND [best].[language] = [d].[language]';
      if ($revision) {
        $best->addField('f', 'revision_id');
        $best->groupBy('f.revision_id');
        $on .= ' AND [best].[revision_id] = [d].[revision_id]';
      }
      $query = $this->select($table, 'd')
        ->fields('d', ['entity_id', 'revision_id', 'delta'])
        ->condition('d.entity_type', 'taxonomy_term')
        ->condition('d.deleted', 0)
        ->condition('d.bundle', (array) $this->configuration['bundle'], 'IN')
        ->orderBy('d.' . $key)
        ->orderBy('d.delta');
      $query->innerJoin($best, 'best', $on);
      if ($kind === 'reference') {
        $query->addField('d', $d7_name . '_nid', 'nid');
        $query->leftJoin('node', 'n', "[n].[nid] = [d].[{$d7_name}_nid]");
        $query->addField('n', 'uuid', 'uuid');
      }
      else {
        $query->addField('d', $d7_name . '_value', 'value');
        if ($schema->fieldExists($table, $d7_name . '_format')) {
          $query->addField('d', $d7_name . '_format', 'format');
        }
      }
      foreach ($query->execute()->fetchAll() as $record) {
        $id = (int) $record[$key];
        if ($kind === 'reference') {
          if (!isset($values[$id][$name . '_guid']) && $record['nid']) {
            $values[$id][$name . '_nid'] = (int) $record['nid'];
            $values[$id][$name . '_guid'] = $record['uuid'] ?: NULL;
          }
          continue;
        }
        $value = $record['value'];
        if ($value === NULL || trim((string) $value) === '') {
          continue;
        }
        if ($kind === 'string') {
          if (!isset($values[$id][$name])) {
            $values[$id][$name] = (string) $value;
          }
          continue;
        }
        $values[$id][$name][] = [
          'value' => (string) $value,
          'format' => static::TEXT_FORMATS[$record['format'] ?? ''] ?? 'plain_text',
        ];
      }
    }
    return $values;
  }

  /**
   * The source property names of the nomenclature fields of the vocabulary.
   *
   * @return string[]
   *   One name per non-reference field, two (<name>_guid, <name>_nid) per
   *   reference field.
   */
  protected function extraFieldProperties(): array {
    $properties = [];
    foreach ($this->extraFields() as [$name, $kind]) {
      if ($kind === 'reference') {
        $properties[] = $name . '_guid';
        $properties[] = $name . '_nid';
      }
      else {
        $properties[] = $name;
      }
    }
    return $properties;
  }

  /**
   * Sets the nomenclature properties of a row (NULL where the term has none).
   *
   * @param \Drupal\migrate\Row $row
   *   The row.
   * @param array<string, mixed> $values
   *   The term's (or revision's) values from loadExtraFieldValues().
   */
  protected function setExtraFieldProperties(Row $row, array $values): void {
    foreach ($this->extraFieldProperties() as $property) {
      $row->setSourceProperty($property, $values[$property] ?? NULL);
    }
  }

  /**
   * The D7 vocabulary id of the configured bundle, or FALSE.
   */
  protected function vocabularyId(): int|false {
    if ($this->vocabularyId === NULL) {
      $this->vocabularyId = FALSE;
      if (!empty($this->configuration['bundle'])) {
        $vid = $this->select('taxonomy_vocabulary', 'tv')
          ->fields('tv', ['vid'])
          ->condition('machine_name', (array) $this->configuration['bundle'], 'IN')
          ->execute()
          ->fetchField();
        $this->vocabularyId = $vid ? (int) $vid : FALSE;
      }
    }
    return $this->vocabularyId;
  }

  /**
   * The "associated accepted name" field of the vocabulary (field_aan_<vid>).
   *
   * Scratchpads names the term-reference field after the vocabulary id, so it
   * is resolved at query time; NULL when the vocabulary has none.
   */
  protected function acceptedNameField(): ?string {
    $vid = $this->vocabularyId();
    if (!$vid) {
      return NULL;
    }
    $field = 'field_aan_' . $vid;
    return $this->getDatabase()->schema()->tableExists('field_data_' . $field) ? $field : NULL;
  }

}
