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
 *   tables; unit names are trimmed here (not in the pipeline) so NULL stays
 *   NULL;
 * - vernacular: the vernacular-name collection items of the revision (each
 *   collection item revision is joined on the field_collection_item revision
 *   id stored with the term revision).
 *
 * The hierarchy (parent) and the Feeds GUID are not revisioned in D7, so the
 * rows carry no parent; the destination keeps the node's current value.
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

    // Scalar ITIS fields from the revision tables.
    foreach (static::SCALAR_FIELDS as $alias => $field_name) {
      $table = 'field_revision_' . $field_name;
      if (!$schema->tableExists($table)) {
        continue;
      }
      $query->leftJoin($table, $alias, $this->revisionJoin($alias));
      $query->addField($alias, $field_name . '_value', $alias);
    }

    // Associated accepted name (field_aan_<vid>), as of that revision.
    $aan_field = $this->acceptedNameField();
    if ($aan_field && $schema->tableExists('field_revision_' . $aan_field)) {
      $column = $aan_field . '_tid';
      $query->leftJoin('field_revision_' . $aan_field, 'aan', $this->revisionJoin('aan'));
      $query->leftJoin('taxonomy_term_data', 'tda', "[tda].[tid] = [aan].[$column]");
      $query->addField('aan', $column, 'accepted_tid');
      $query->addField('tda', 'name', 'accepted_name');
      $this->addGuid($query, 'tda', 'accepted_guid');
    }

    // Protologue reference (node_reference to a biblio node).
    if ($schema->tableExists('field_revision_field_reference')) {
      $query->leftJoin('field_revision_field_reference', 'ref', $this->revisionJoin('ref'));
      $query->leftJoin('node', 'refn', '[refn].[nid] = [ref].[field_reference_nid]');
      $query->addField('ref', 'field_reference_nid', 'reference_nid');
      $query->addField('refn', 'title', 'reference_title');
      $query->addField('refn', 'uuid', 'reference_guid');
    }

    // Page URL (link field).
    if ($schema->tableExists('field_revision_field_page_url')) {
      $query->leftJoin('field_revision_field_page_url', 'purl', $this->revisionJoin('purl'));
      $query->addField('purl', 'field_page_url_url', 'page_url');
      $query->addField('purl', 'field_page_url_title', 'page_url_title');
    }

    return $query;
  }

  /**
   * Join condition for a field_revision_* table on the term revision row.
   */
  protected function revisionJoin(string $alias): string {
    return "[$alias].[entity_type] = 'taxonomy_term' AND [$alias].[entity_id] = [r].[tid] AND [$alias].[revision_id] = [r].[revision_id] AND [$alias].[deleted] = 0 AND [$alias].[delta] = 0";
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
      'weight' => $this->t('Weight as of this revision.'),
      'accepted_tid' => $this->t('Associated accepted name: term ID.'),
      'accepted_guid' => $this->t('Associated accepted name: GUID.'),
      'accepted_name' => $this->t('Associated accepted name: term name.'),
      'reference_nid' => $this->t('Protologue reference: biblio node ID.'),
      'reference_title' => $this->t('Protologue reference: title.'),
      'reference_guid' => $this->t('Protologue reference: node uuid.'),
      'page_url' => $this->t('Protologue page URL.'),
      'page_url_title' => $this->t('Protologue page URL title.'),
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
    $row->setSourceProperty('is_current', (int) $row->getSourceProperty('is_current'));
    $row->setSourceProperty('vernacular', $this->vernacularNames()[$revision_id] ?? []);
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
    $sub = [
      'name' => ['field_revision_field_vernacular_name', 'field_vernacular_name_value'],
      'language' => ['field_revision_field_language', 'field_language_value'],
      'area' => ['field_revision_field_vernacular_locality', 'field_vernacular_locality_value'],
      'remarks' => ['field_revision_field_vernacular_name_remarks', 'field_vernacular_name_remarks_value'],
    ];
    foreach ($sub as $alias => [$table, $column]) {
      if ($schema->tableExists($table)) {
        $query->leftJoin($table, $alias, "[$alias].[entity_type] = 'field_collection_item' AND [$alias].[entity_id] = [c].[field_vernacular_name_collection_value] AND [$alias].[revision_id] = [c].[field_vernacular_name_collection_revision_id] AND [$alias].[deleted] = 0");
        $query->addField($alias, $column, $alias);
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
