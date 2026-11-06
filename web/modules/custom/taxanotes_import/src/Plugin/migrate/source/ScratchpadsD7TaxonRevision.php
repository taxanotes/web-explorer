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
 *   row per language (ScratchpadsD7Base::joinFieldTable()), and unit_names,
 *   the unit name/indicator pairs as the items of field_unit_names
 *   (ScratchpadsD7Base::unitNames()). For the current revision (is_current)
 *   every one of these values, the name, description, unit names and
 *   vernacular names included, is taken from the *current* tables
 *   (taxonomy_term_data, field_data_*) instead of the revision tables: a
 *   Scratchpads term can be saved without a new revision (an import, a
 *   drag-and-drop, a bulk edit), after which field_data_* holds a newer
 *   value than the current revision's field_revision_* row and the D7 site
 *   renders field_data_*. The default revision this row becomes therefore
 *   carries exactly what the taxon source wrote and what Scratchpads shows
 *   (Mormyrids has 4 such terms; the weight is treated the same way, see
 *   below);
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
 *   id stored with the term revision);
 * - the nomenclature fields the vocabulary has (ScratchpadsD7Base::
 *   EXTRA_FIELDS), as of the revision (field_revision_*), the current
 *   revision's from the current tables like every other value; the two
 *   reference fields are not revisioned here (like the protologue, they are
 *   linked by the reference pass on the current revision only).
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
   * Vernacular names keyed by term revision id, loaded once.
   *
   * @var array<int, array<int, array<string, string|null>>>|null
   */
  protected ?array $vernacular = NULL;

  /**
   * The current values of every term (name, fields, vernacular names), once.
   *
   * @var array<int, array<string, mixed>>|null
   */
  protected ?array $live = NULL;

  /**
   * The nomenclature field values keyed by term revision id, loaded once.
   *
   * @var array<int, array<string, mixed>>|null
   */
  protected ?array $extraValues = NULL;

  /**
   * Source properties the current revision takes from the current tables.
   */
  protected const LIVE_PROPERTIES = [
    'name', 'description', 'format',
    'accepted_tid', 'accepted_name', 'accepted_guid',
    'reference_nid', 'reference_title', 'reference_guid',
    'page_url', 'page_url_title',
  ];

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

    // Scalar ITIS fields, accepted name, protologue reference and page URL
    // as of that revision (field_revision_*; shared with the taxon source).
    $this->joinItisFields($query, 'r', TRUE);

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
      'unit_names' => $this->t('Unit names of the revision: array of indicator, name, one per non-empty unit position (1-4), in order.'),
    ] + array_combine(array_keys(static::SCALAR_FIELDS), array_map(fn($f) => $this->t('D7 @field value as of this revision', ['@field' => $f]), static::SCALAR_FIELDS))
    + array_combine($this->extraFieldProperties(), array_map(fn($p) => $this->t('Nomenclature field @p as of this revision (see EXTRA_FIELDS).', ['@p' => $p]), $this->extraFieldProperties()));
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
    $this->setExtraFieldProperties($row, $this->extraValues()[$revision_id] ?? []);
    if ($is_current) {
      // The current revision carries what the D7 site renders now (see the
      // class comment): every revisioned value from the current tables.
      $live = $this->liveValues()[(int) $row->getSourceProperty('tid')] ?? NULL;
      if ($live !== NULL) {
        foreach ($live as $property => $value) {
          $row->setSourceProperty($property, $value);
        }
      }
    }
    $url = $row->getSourceProperty('page_url');
    $row->setSourceProperty('page_url_item', is_string($url) && $url !== '' ? [
      'uri' => $url,
      'title' => $row->getSourceProperty('page_url_title'),
    ] : NULL);
    $timestamp = (int) $row->getSourceProperty('timestamp');
    $term_changed = $row->getSourceProperty('term_changed');
    $row->setSourceProperty('changed', $is_current && $term_changed ? (int) $term_changed : $timestamp);
    // After the live override: the current revision's units are the current
    // ones.
    $row->setSourceProperty('unit_names', $this->unitNames($row));
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
   */
  protected function vernacularNames(): array {
    if ($this->vernacular === NULL) {
      $this->vernacular = $this->loadVernacularNames(TRUE);
    }
    return $this->vernacular;
  }

  /**
   * Loads the current values of every term of the vocabulary, once.
   *
   * One query over taxonomy_term_data with the same field joins as the taxon
   * source (field_data_*), plus the current vernacular names; keyed by tid.
   * Only properties the revision query also yields are kept, so the row of
   * the current revision has the same property set as every other row.
   *
   * @return array<int, array<string, mixed>>
   *   The current source properties of each term, keyed by tid.
   */
  protected function liveValues(): array {
    if ($this->live !== NULL) {
      return $this->live;
    }
    $this->live = [];
    $query = $this->select('taxonomy_term_data', 'td')
      ->fields('td', ['tid', 'name', 'description', 'format']);
    $query->innerJoin('taxonomy_vocabulary', 'tv', '[td].[vid] = [tv].[vid]');
    $this->addBundleCondition($query);
    $this->joinItisFields($query, 'td');
    $keep = array_merge(static::LIVE_PROPERTIES, array_keys(static::SCALAR_FIELDS));
    $vernacular = $this->loadVernacularNames(FALSE);
    $extra = $this->loadExtraFieldValues(FALSE);
    $extra_properties = $this->extraFieldProperties();
    foreach ($query->execute()->fetchAll() as $record) {
      $tid = (int) $record['tid'];
      $values = array_intersect_key($record, array_flip($keep));
      $values['vernacular'] = $vernacular[$tid] ?? [];
      foreach ($extra_properties as $property) {
        $values[$property] = $extra[$tid][$property] ?? NULL;
      }
      $this->live[$tid] = $values;
    }
    return $this->live;
  }

  /**
   * Loads the nomenclature field values of every term revision, once.
   */
  protected function extraValues(): array {
    if ($this->extraValues === NULL) {
      $this->extraValues = $this->loadExtraFieldValues(TRUE);
    }
    return $this->extraValues;
  }

}
