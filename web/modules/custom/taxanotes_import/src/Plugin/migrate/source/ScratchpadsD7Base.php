<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Site\Settings;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Shared behaviour of the Scratchpads (Drupal 7) taxon source plugins.
 *
 * Built directly on the migrate SqlBase plugin (migrate_drupal's
 * DrupalSqlBase/FieldableEntity/d7 Term hierarchy is deprecated in Drupal
 * 11.3 and removed in 12 with no replacement). Holds what the taxon and the
 * taxon-revision sources have in common: the vocabulary ("bundle") read from
 * settings when the migration omits it, the GUID expression, the ITIS field
 * list and the lookup of the vocabulary-specific "accepted name" field.
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
    if ($entity_type === 'taxonomy_term') {
      $best->innerJoin('taxonomy_term_data', 't', '[t].[tid] = [f].[entity_id]');
      $rank = "CASE WHEN [f].[language] = [t].[language] THEN '0' WHEN [f].[language] = 'und' THEN '1' ELSE '2' END";
    }
    else {
      // Other entities (field collection items) have no language of their
      // own to prefer.
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
