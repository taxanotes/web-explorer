<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) taxonomy term revisions, oldest first.
 *
 * Scratchpads' taxonomy_revision module stores every save of a term in
 * taxonomy_term_data_revision (revision_id, tid, log, timestamp, uid, name,
 * description, format, weight) and the field values of each revision in the
 * field_revision_<field> tables, keyed on (entity_id, revision_id);
 * taxonomy_term_data.revision_id points at the current revision. This source
 * yields one row per revision of the configured vocabulary's terms, ordered
 * by revision_id (which is also chronological), with:
 * - guid: the term's GUID, as the scratchpads_d7_taxon source computes it, so
 *   the node can be looked up in the taxanotes_d7_taxon id map;
 * - is_current: 1 for the revision that is the term's current one in D7;
 * - the same scalar ITIS fields, accepted name (guid/tid/name), protologue
 *   reference and page URL as the taxon source, read from the revision
 *   tables, one row per field and revision even when the revision holds a
 *   row per language (ScratchpadsD7Base::joinFieldTable()); unit names are
 *   trimmed here (not in the pipeline) so NULL stays NULL;
 * - page_url_item: the protologue page URL as one link item {uri, title},
 *   NULL when the revision has no URL. The migration maps this whole item
 *   rather than `field_page_url/uri`: a NULL sub-property does not clear the
 *   field on the loaded (current) revision, a NULL field does;
 * - changed: the value for the revision's `changed` column: the revision's
 *   own timestamp, except for the current revision, which carries the term's
 *   latest revision timestamp (`term_changed`), the same value the taxon
 *   import writes, so the two migrations agree on the default revision and a
 *   forced re-import of unchanged terms detects no change;
 * - vernacular: the vernacular-name collection items of the revision (each
 *   collection item revision is joined on the field_collection_item revision
 *   id stored with the term revision).
 *
 * The hierarchy (parent), the sibling weight (drag-and-drop writes
 * taxonomy_term_data.weight without a revision; the revision rows all carry
 * 0) and the Feeds GUID are not revisioned in D7, so the migration maps
 * neither and the destination keeps the node's current values.
 *
 * Configuration keys: bundle (see ScratchpadsD7Base); the migration id
 * `revision_id` is a real column, so the id map could be joined, but
 * track_changes is used like the sibling migrations.
 */
#[MigrateSource(
  id: 'scratchpads_d7_taxon_revision',
)]
final class ScratchpadsD7TaxonRevision extends ScratchpadsD7Base {

  /**
   * Unit-name source properties trimmed in prepareRow().
   */
  protected const TRIMMED = ['field_unit_name1', 'field_unit_name2', 'field_unit_name3', 'field_unit_name4'];

  /**
   * Vernacular names keyed by term revision id, loaded once.
   *
   * @var array<int, array<int, array<string, string|null>>>|null
   */
  protected ?array $vernacular = NULL;

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    if (!$schema->tableExists('taxonomy_term_data_revision') || !$schema->fieldExists('taxonomy_term_data', 'revision_id')) {
      throw new RequirementsException('The migrate database has no term revisions (the Scratchpads taxonomy_revision tables are missing).');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $schema = $this->getDatabase()->schema();

    $query = $this->select('taxonomy_term_data_revision', 'r')
      ->fields('r', ['revision_id', 'tid', 'log', 'timestamp', 'uid', 'name', 'description', 'format', 'weight'])
      ->distinct()
      ->orderBy('r.revision_id');
    $query->innerJoin('taxonomy_term_data', 'td', '[td].[tid] = [r].[tid]');
    $query->innerJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $this->addBundleCondition($query);
    $query->addExpression('CASE WHEN [td].[revision_id] = [r].[revision_id] THEN 1 ELSE 0 END', 'is_current');
    $this->addGuid($query, 'td', 'guid');

    // The term's latest revision timestamp (what the taxon import writes as
    // the node's `changed`); see prepareRow().
    $latest = $this->select('taxonomy_term_data_revision', 'rl');
    $latest->addField('rl', 'tid');
    $latest->addExpression('MAX([rl].[timestamp])', 'term_changed');
    $latest->groupBy('rl.tid');
    $query->leftJoin($latest, 'latest', '[latest].[tid] = [r].[tid]');
    $query->addField('latest', 'term_changed', 'term_changed');

    // Scalar ITIS fields from the revision tables.
    foreach (static::SCALAR_FIELDS as $alias => $field_name) {
      $table = 'field_revision_' . $field_name;
      if (!$schema->tableExists($table)) {
        continue;
      }
      $this->joinFieldTable($query, $field_name, $alias, 'r', TRUE);
      $query->addField($alias, $field_name . '_value', $alias);
    }

    // Associated accepted name (field_aan_<vid>), as of that revision.
    $aan_field = $this->acceptedNameField();
    if ($aan_field && $schema->tableExists('field_revision_' . $aan_field)) {
      $column = $aan_field . '_tid';
      $this->joinFieldTable($query, $aan_field, 'aan', 'r', TRUE);
      $query->leftJoin('taxonomy_term_data', 'tda', "[tda].[tid] = [aan].[$column]");
      $query->addField('aan', $column, 'accepted_tid');
      $query->addField('tda', 'name', 'accepted_name');
      $this->addGuid($query, 'tda', 'accepted_guid');
    }

    // Protologue reference (node_reference to a biblio node).
    if ($schema->tableExists('field_revision_field_reference')) {
      $this->joinFieldTable($query, 'field_reference', 'ref', 'r', TRUE);
      $query->leftJoin('node', 'refn', '[refn].[nid] = [ref].[field_reference_nid]');
      $query->addField('ref', 'field_reference_nid', 'reference_nid');
      $query->addField('refn', 'title', 'reference_title');
      $query->addField('refn', 'uuid', 'reference_guid');
    }

    // Page URL (link field).
    if ($schema->tableExists('field_revision_field_page_url')) {
      $this->joinFieldTable($query, 'field_page_url', 'purl', 'r', TRUE);
      $query->addField('purl', 'field_page_url_url', 'page_url');
      $query->addField('purl', 'field_page_url_title', 'page_url_title');
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'revision_id' => $this->t('The term revision ID (chronological).'),
      'tid' => $this->t('The term ID.'),
      'guid' => $this->t('Scratchpads GUID of the term (feeds_item.guid, else the term uuid).'),
      'is_current' => $this->t('1 when this is the current revision of the term in Drupal 7.'),
      'log' => $this->t('Revision log message.'),
      'timestamp' => $this->t('Revision timestamp.'),
      'uid' => $this->t('Drupal 7 uid of the revision author.'),
      'name' => $this->t('The term name as of this revision.'),
      'description' => $this->t('The term description as of this revision.'),
      'format' => $this->t('Format of the description.'),
      'weight' => $this->t('Weight column of the revision row (always 0 in Scratchpads: the sibling order is not revisioned; do not map it).'),
      'accepted_tid' => $this->t('Associated accepted name: term ID.'),
      'accepted_guid' => $this->t('Associated accepted name: GUID.'),
      'accepted_name' => $this->t('Associated accepted name: term name.'),
      'reference_nid' => $this->t('Protologue reference: biblio node ID.'),
      'reference_title' => $this->t('Protologue reference: title.'),
      'reference_guid' => $this->t('Protologue reference: node uuid.'),
      'page_url' => $this->t('Protologue page URL.'),
      'page_url_title' => $this->t('Protologue page URL title.'),
      'page_url_item' => $this->t('Protologue page URL as one link item {uri, title}, or NULL.'),
      'term_changed' => $this->t('Timestamp of the latest revision of the term.'),
      'changed' => $this->t('The revision timestamp, or term_changed for the current revision (what the node revision stores as changed).'),
      'vernacular' => $this->t('Vernacular names of the revision: array of name, language, area, remarks.'),
    ] + array_combine(array_keys(static::SCALAR_FIELDS), array_map(fn($f) => $this->t('D7 @field value as of this revision', ['@field' => $f]), static::SCALAR_FIELDS));
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'revision_id' => [
        'type' => 'integer',
        'alias' => 'r',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $revision_id = (int) $row->getSourceProperty('revision_id');
    $is_current = (int) $row->getSourceProperty('is_current');
    $row->setSourceProperty('is_current', $is_current);
    $row->setSourceProperty('vernacular', $this->vernacularNames()[$revision_id] ?? []);
    $url = $row->getSourceProperty('page_url');
    $row->setSourceProperty('page_url_item', is_string($url) && $url !== '' ? [
      'uri' => $url,
      'title' => $row->getSourceProperty('page_url_title'),
    ] : NULL);
    $timestamp = (int) $row->getSourceProperty('timestamp');
    $term_changed = $row->getSourceProperty('term_changed');
    $row->setSourceProperty('changed', $is_current && $term_changed ? (int) $term_changed : $timestamp);
    foreach (static::TRIMMED as $property) {
      $value = $row->getSourceProperty($property);
      if (is_string($value)) {
        $row->setSourceProperty($property, trim($value));
      }
    }
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   *
   * One indexed count instead of the joined query (migrate:status runs it).
   */
  protected function doCount() {
    $query = $this->select('taxonomy_term_data_revision', 'r');
    $query->innerJoin('taxonomy_term_data', 'td', '[td].[tid] = [r].[tid]');
    $query->innerJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $this->addBundleCondition($query);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Loads the vernacular-name items of every term revision, once.
   *
   * The collection field's revision row stores the field_collection_item id
   * and its revision id; the item's own field values live in the
   * field_revision_* tables of the field_collection_item entity type.
   */
  protected function vernacularNames(): array {
    if ($this->vernacular !== NULL) {
      return $this->vernacular;
    }
    $this->vernacular = [];
    $schema = $this->getDatabase()->schema();
    if (!$schema->tableExists('field_revision_field_vernacular_name_collection')) {
      return $this->vernacular;
    }
    $query = $this->select('field_revision_field_vernacular_name_collection', 'c')
      ->fields('c', ['revision_id', 'delta'])
      ->condition('c.entity_type', 'taxonomy_term')
      ->condition('c.deleted', 0)
      ->orderBy('c.revision_id')
      ->orderBy('c.delta');
    if (!empty($this->configuration['bundle'])) {
      $query->condition('c.bundle', (array) $this->configuration['bundle'], 'IN');
    }
    // One row per sub-field and item revision, whatever languages it holds
    // (see joinFieldTable()).
    foreach (static::VERNACULAR_FIELDS as $alias => $field_name) {
      if ($schema->tableExists('field_revision_' . $field_name)) {
        $this->joinFieldTable($query, $field_name, $alias, 'c', TRUE, 'field_collection_item', 'field_vernacular_name_collection_value', 'field_vernacular_name_collection_revision_id');
        $query->addField($alias, $field_name . '_value', $alias);
      }
    }
    foreach ($query->execute()->fetchAll() as $record) {
      $this->vernacular[(int) $record['revision_id']][] = [
        'name' => $record['name'] ?? NULL,
        'language' => $record['language'] ?? NULL,
        'area' => $record['area'] ?? NULL,
        'remarks' => $record['remarks'] ?? NULL,
      ];
    }
    return $this->vernacular;
  }

}
