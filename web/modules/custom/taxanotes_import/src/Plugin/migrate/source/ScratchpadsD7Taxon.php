<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Site\Settings;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) taxonomy terms with their GUIDs and ITIS fields.
 *
 * Built directly on the migrate SqlBase plugin (migrate_drupal's
 * DrupalSqlBase/FieldableEntity/d7 Term hierarchy is deprecated in Drupal
 * 11.3 and removed in 12 with no replacement). The base term query of the
 * core d7_taxonomy_term source (taxonomy_term_data + vocabulary machine name,
 * filtered by bundle, ordered by tid) is inlined here and extended with:
 * - guid: feeds_item.guid for the term, falling back to the term's uuid for
 *   terms that were created by hand and never went through an importer (the
 *   Scratchpads exporter applies the same rule when it writes the GUID column);
 * - parent_guid / parent_name / parent_tid: the single parent from
 *   taxonomy_term_hierarchy (Scratchpads assumes one parent; should a term
 *   ever carry two, the highest parent tid is used so a term never yields two
 *   source rows with the same guid);
 * - accepted_guid / accepted_tid / accepted_name: field_aan_<vid>, the
 *   "associated accepted name" term reference;
 * - the scalar ITIS fields (rank, usage, unit names and indicators, authors,
 *   page number, unacceptability reason) joined in the main query rather than
 *   fetched per row, so a 30k-term vocabulary needs one SQL statement; one
 *   row per field and term even when the D7 field holds a row per language
 *   (see ScratchpadsD7Base::joinFieldTable() for which one);
 * - page_url / page_url_title (link field), reference_nid / reference_title /
 *   reference_guid (node_reference to a biblio node);
 * - created / changed: min and max taxonomy_term_data_revision.timestamp;
 * - vernacular: the field_vernacular_name_collection items (name, language,
 *   area, remarks), fetched once for the whole vocabulary;
 * - published: 0 when the term is, or descends from, one of the
 *   unpublished_roots, else 1. Derived from the hierarchy in prepareRow() and
 *   therefore part of the track_changes row hash, so moving a subtree into or
 *   out of "Deleted taxa" in D7 re-imports every affected descendant.
 *
 * Configuration keys:
 * - bundle: the D7 vocabulary machine name. When omitted it is read from
 *   $settings['taxanotes_import']['source_vocabulary'] so the migration YAML
 *   stays instance-agnostic.
 * - unpublished_roots: names of root terms whose whole subtree must be
 *   imported unpublished (Scratchpads parks removed names under a root called
 *   "Deleted taxa", which is the default). Overridable per instance with
 *   $settings['taxanotes_import']['unpublished_roots'].
 * - key / database_state_key, track_changes, ignore_map, constants: see
 *   SqlBase and SourcePluginBase.
 *
 * The migration id `guid` is a SQL expression alias (COALESCE over two
 * tables). SqlBase can only join the id map to the source query on real
 * columns, so migrations using this plugin must keep the map out of the
 * query: `track_changes: true` does that implicitly and `ignore_map: true`
 * does it explicitly; the shipped migrations set both.
 */
#[MigrateSource(
  id: 'scratchpads_d7_taxon',
)]
final class ScratchpadsD7Taxon extends ScratchpadsD7Base {

  /**
   * Root term names whose subtree is imported unpublished, unless configured.
   */
  protected const DEFAULT_UNPUBLISHED_ROOTS = ['Deleted taxa'];

  /**
   * Vernacular names keyed by tid, loaded once.
   *
   * @var array<int, array<int, array<string, string|null>>>|null
   */
  protected ?array $vernacular = NULL;

  /**
   * The tid => parent tid map for every term of the vocabulary, loaded once.
   *
   * @var array<int, int>|null
   */
  protected ?array $parents = NULL;

  /**
   * The tids of the configured unpublished roots, loaded once.
   *
   * @var array<int, true>|null
   */
  protected ?array $unpublishedRootTids = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    if (!isset($configuration['unpublished_roots'])) {
      $instance = Settings::get('taxanotes_import', []);
      $configuration['unpublished_roots'] = $instance['unpublished_roots'] ?? static::DEFAULT_UNPUBLISHED_ROOTS;
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $db = $this->getDatabase();
    $schema = $db->schema();
    $has_feeds = $schema->tableExists('feeds_item');
    $has_uuid = $schema->fieldExists('taxonomy_term_data', 'uuid');

    // Base term query, as core's d7_taxonomy_term source builds it.
    $query = $this->select('taxonomy_term_data', 'td')
      ->fields('td')
      ->distinct()
      ->orderBy('tid');
    $query->leftJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $query->addField('tv', 'machine_name');
    if ($schema->fieldExists('taxonomy_vocabulary', 'i18n_mode')) {
      $query->addField('tv', 'i18n_mode');
    }
    $this->addBundleCondition($query);

    // GUID: Feeds GUID first, term uuid as the fallback.
    if ($has_feeds) {
      $query->leftJoin('feeds_item', 'fi', "[fi].[entity_type] = 'taxonomy_term' AND [fi].[entity_id] = [td].[tid]");
      $query->addExpression($this->guidExpression('fi', 'td', $has_uuid), 'guid');
      $query->addField('fi', 'imported', 'feeds_imported');
    }
    else {
      $query->addExpression('[td].[uuid]', 'guid');
    }

    // Single parent. Scratchpads never writes more than one hierarchy row per
    // term; the GROUP BY keeps that true for this query even if a term ever
    // had two (highest parent tid wins), so one term is one source row.
    $th = $db->select('taxonomy_term_hierarchy', 'h');
    $th->addField('h', 'tid');
    $th->addExpression('MAX([h].[parent])', 'parent');
    $th->groupBy('h.tid');
    $query->leftJoin($th, 'th', '[th].[tid] = [td].[tid] AND [th].[parent] <> 0');
    $query->leftJoin('taxonomy_term_data', 'tdp', '[tdp].[tid] = [th].[parent]');
    $query->addField('th', 'parent', 'parent_tid');
    $query->addField('tdp', 'name', 'parent_name');
    if ($has_feeds) {
      $query->leftJoin('feeds_item', 'fip', "[fip].[entity_type] = 'taxonomy_term' AND [fip].[entity_id] = [tdp].[tid]");
      $query->addExpression($this->guidExpression('fip', 'tdp', $has_uuid), 'parent_guid');
    }
    else {
      $query->addExpression('[tdp].[uuid]', 'parent_guid');
    }

    // Revision timestamps give created/changed (D7 terms have none of their
    // own). The revision table is Scratchpads-specific; without it the
    // destination falls back to "now".
    if ($schema->tableExists('taxonomy_term_data_revision')) {
      $rev = $db->select('taxonomy_term_data_revision', 'r');
      $rev->addField('r', 'tid');
      $rev->addExpression('MIN([r].[timestamp])', 'created');
      $rev->addExpression('MAX([r].[timestamp])', 'changed');
      $rev->groupBy('r.tid');
      $query->leftJoin($rev, 'rev', '[rev].[tid] = [td].[tid]');
      $query->addField('rev', 'created', 'created');
      $query->addField('rev', 'changed', 'changed');
    }

    // Scalar ITIS fields, accepted name, protologue reference and page URL,
    // one LEFT JOIN each on the field data tables (shared with the revision
    // source).
    $this->joinItisFields($query, 'td');

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'tid' => $this->t('The term ID.'),
      'vid' => $this->t('Existing term VID'),
      'machine_name' => $this->t('Vocabulary machine name'),
      'name' => $this->t('The name of the term.'),
      'description' => $this->t('The term description.'),
      'weight' => $this->t('Weight'),
      'parent' => $this->t("The Drupal term IDs of the term's parents."),
      'format' => $this->t('Format of the term description.'),
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
      'published' => $this->t('1, or 0 when the term is, or descends from, an unpublished root.'),
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
    $tid = (int) $row->getSourceProperty('tid');
    $row->setSourceProperty('vernacular', $this->vernacularNames()[$tid] ?? []);
    $row->setSourceProperty('parent', array_filter([(int) $row->getSourceProperty('parent_tid')]));
    $row->setSourceProperty('published', $this->isUnderUnpublishedRoot($tid) ? 0 : 1);
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   *
   * The default count runs the full query() with its ~25 joins (twice per
   * `migrate:status`, once per migration); the row count is the same as the
   * number of terms in the vocabulary, which is one indexed lookup.
   */
  protected function doCount() {
    $query = $this->select('taxonomy_term_data', 'td');
    $query->innerJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $this->addBundleCondition($query);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Whether a term is one of the configured unpublished roots or below one.
   *
   * Walks tid => parent in memory (the hierarchy of the whole vocabulary is
   * read once; 32k rows is a few hundred kilobytes). Cycle-safe.
   */
  public function isUnderUnpublishedRoot(int $tid): bool {
    $roots = $this->unpublishedRootTids();
    if (!$roots) {
      return FALSE;
    }
    $parents = $this->parentMap();
    $seen = [];
    while ($tid > 0 && !isset($seen[$tid])) {
      if (isset($roots[$tid])) {
        return TRUE;
      }
      $seen[$tid] = TRUE;
      $tid = $parents[$tid] ?? 0;
    }
    return FALSE;
  }

  /**
   * The tid => parent tid map for the vocabulary (single parent, 0 for roots).
   *
   * @return array<int, int>
   *   Parent tid keyed by tid.
   */
  protected function parentMap(): array {
    if ($this->parents !== NULL) {
      return $this->parents;
    }
    $this->parents = [];
    $query = $this->select('taxonomy_term_hierarchy', 'th')->fields('th', ['tid', 'parent']);
    $query->innerJoin('taxonomy_term_data', 'td', '[td].[tid] = [th].[tid]');
    $query->innerJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $this->addBundleCondition($query);
    foreach ($query->execute()->fetchAllKeyed(0, 1) as $tid => $parent) {
      $this->parents[(int) $tid] = (int) $parent;
    }
    return $this->parents;
  }

  /**
   * The tids of the root terms (parent 0) named in `unpublished_roots`.
   *
   * @return array<int, true>
   *   Set of root tids.
   */
  protected function unpublishedRootTids(): array {
    if ($this->unpublishedRootTids !== NULL) {
      return $this->unpublishedRootTids;
    }
    $this->unpublishedRootTids = [];
    $names = array_values(array_filter((array) ($this->configuration['unpublished_roots'] ?? [])));
    if (!$names) {
      return $this->unpublishedRootTids;
    }
    $query = $this->select('taxonomy_term_data', 'td')->fields('td', ['tid']);
    $query->innerJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $query->innerJoin('taxonomy_term_hierarchy', 'th', '[th].[tid] = [td].[tid] AND [th].[parent] = 0');
    $query->condition('td.name', $names, 'IN');
    $this->addBundleCondition($query);
    foreach ($query->execute()->fetchCol() as $tid) {
      $this->unpublishedRootTids[(int) $tid] = TRUE;
    }
    return $this->unpublishedRootTids;
  }

  /**
   * Loads every vernacular-name collection item of the vocabulary, once.
   */
  protected function vernacularNames(): array {
    if ($this->vernacular === NULL) {
      $this->vernacular = $this->loadVernacularNames(FALSE);
    }
    return $this->vernacular;
  }

}
