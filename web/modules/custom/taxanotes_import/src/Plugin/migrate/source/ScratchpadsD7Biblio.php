<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Database\Query\SelectInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) biblio nodes: the literature.
 *
 * One row per biblio node (the current revision: `node` joined to `biblio`
 * on node.vid), keyed on the node uuid, which is the stable id across
 * exports (the plan's `.bib` key is the nid, kept in `bibcite_citekey` and
 * in `nid`). Besides the node columns and every `biblio_*` column the row
 * carries, loaded once per import:
 * - contributors: [{cid, auth_type, auth_category, rank}] of the current
 *   revision in rank order (biblio_contributor);
 * - keywords: [{kid}] of the current revision (biblio_keyword);
 * - taxa: [{tid, guid}] from field_taxonomic_name, one per distinct term,
 *   restricted to the instance vocabulary (the bundle) so a link to a term
 *   of another vocabulary is dropped; the GUID is the same Feeds-GUID-else-
 *   uuid the taxon source uses, so the reference migration links taxa by
 *   migration_lookup on the taxon migration;
 * - files: [{fid, description, display}] from field_file in delta order.
 * The two field tables hold one row set per language (entity_translation):
 * the set in the node's own language is taken, else `und`, else the
 * alphabetically first, as the taxon source does for term fields.
 * `year` is biblio_year, with biblio's "in press" value 9999 as NULL.
 *
 * Configuration keys:
 * - bundle: the D7 vocabulary machine name of the taxa (default from
 *   $settings['taxanotes_import']['source_vocabulary'], see the base class).
 * - node_type: the D7 node type (default biblio).
 * - taxon_field: the D7 term reference field to the taxa (default
 *   field_taxonomic_name).
 * - file_field: the D7 file field (default field_file).
 */
#[MigrateSource(
  id: 'scratchpads_d7_biblio',
)]
final class ScratchpadsD7Biblio extends ScratchpadsD7Base {

  /**
   * The biblio columns copied to the row, named after the column.
   */
  protected const BIBLIO_COLUMNS = [
    'biblio_type', 'biblio_number', 'biblio_other_number',
    'biblio_secondary_title', 'biblio_tertiary_title', 'biblio_edition',
    'biblio_publisher', 'biblio_place_published', 'biblio_year',
    'biblio_volume', 'biblio_pages', 'biblio_date', 'biblio_isbn',
    'biblio_lang', 'biblio_abst_e', 'biblio_abst_f', 'biblio_url',
    'biblio_issue', 'biblio_type_of_work', 'biblio_accession_number',
    'biblio_call_number', 'biblio_notes', 'biblio_custom1', 'biblio_custom2',
    'biblio_custom3', 'biblio_custom4', 'biblio_custom5', 'biblio_custom6',
    'biblio_custom7', 'biblio_research_notes', 'biblio_number_of_volumes',
    'biblio_short_title', 'biblio_alternate_title',
    'biblio_original_publication', 'biblio_reprint_edition',
    'biblio_translated_title', 'biblio_section', 'biblio_citekey',
    'biblio_doi', 'biblio_issn', 'biblio_auth_address',
    'biblio_remote_db_name', 'biblio_remote_db_provider', 'biblio_label',
    'biblio_access_date', 'biblio_refereed',
  ];

  /**
   * The biblio "in press" year.
   */
  protected const IN_PRESS = 9999;

  /**
   * Contributors keyed by node revision id, loaded once.
   *
   * @var array<int, array<int, array<string, int>>>|null
   */
  protected ?array $contributors = NULL;

  /**
   * Keyword ids keyed by node revision id, loaded once.
   *
   * @var array<int, array<int, array{kid: int}>>|null
   */
  protected ?array $keywords = NULL;

  /**
   * Taxa keyed by nid, loaded once.
   *
   * @var array<int, array<int, array{tid: int, guid: string}>>|null
   */
  protected ?array $taxa = NULL;

  /**
   * Files keyed by nid, loaded once.
   *
   * @var array<int, array<int, array{fid: int, description: string|null, display: int}>>|null
   */
  protected ?array $files = NULL;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $configuration += [
      'node_type' => 'biblio',
      'taxon_field' => 'field_taxonomic_name',
      'file_field' => 'field_file',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    foreach (['node', 'biblio', 'biblio_contributor'] as $table) {
      if (!$schema->tableExists($table)) {
        throw new RequirementsException(sprintf('The migrate database has no %s table (biblio is not installed on the Drupal 7 site).', $table));
      }
    }
    if (!$schema->fieldExists('node', 'uuid')) {
      throw new RequirementsException('The migrate database has no node.uuid column; the reference import keys on it.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('node', 'n')
      ->fields('n', ['nid', 'vid', 'uuid', 'title', 'uid', 'status', 'created', 'changed', 'language'])
      ->condition('n.type', $this->configuration['node_type'])
      ->orderBy('n.nid');
    $query->innerJoin('biblio', 'b', '[b].[vid] = [n].[vid]');
    $query->fields('b', self::BIBLIO_COLUMNS);
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [
      'nid' => $this->t('Node ID (the .bib key and biblio citekey)'),
      'vid' => $this->t('Current revision ID'),
      'uuid' => $this->t('Node uuid, the migration id'),
      'title' => $this->t('Title'),
      'uid' => $this->t('Author uid'),
      'status' => $this->t('Published'),
      'created' => $this->t('Created'),
      'changed' => $this->t('Changed'),
      'language' => $this->t('Node language'),
      'year' => $this->t('Year of publication (NULL for biblio\'s "in press" 9999)'),
      'contributors' => $this->t('Contributors: array of {cid, auth_type, auth_category, rank} in rank order'),
      'keywords' => $this->t('Keywords: array of {kid}'),
      'taxa' => $this->t('Taxa cited: array of {tid, guid}, vocabulary terms only'),
      'files' => $this->t('Attachments: array of {fid, description, display}'),
    ];
    foreach (self::BIBLIO_COLUMNS as $column) {
      $fields[$column] = $this->t('biblio column @c', ['@c' => $column]);
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
    $vid = (int) $row->getSourceProperty('vid');
    $year = (int) $row->getSourceProperty('biblio_year');
    $row->setSourceProperty('year', ($year === self::IN_PRESS || $year === 0) ? NULL : $year);
    $row->setSourceProperty('contributors', $this->contributors()[$vid] ?? []);
    $row->setSourceProperty('keywords', $this->keywords()[$vid] ?? []);
    $row->setSourceProperty('taxa', $this->taxa()[$nid] ?? []);
    $row->setSourceProperty('files', $this->files()[$nid] ?? []);
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
   * Contributors of every current biblio revision, by revision id.
   */
  protected function contributors(): array {
    if ($this->contributors !== NULL) {
      return $this->contributors;
    }
    $this->contributors = [];
    $query = $this->select('biblio_contributor', 'c')
      ->fields('c', ['vid', 'cid', 'auth_type', 'auth_category', 'rank'])
      ->orderBy('c.vid')->orderBy('c.rank')->orderBy('c.cid');
    $query->innerJoin('node', 'n', '[n].[vid] = [c].[vid]');
    $query->condition('n.type', $this->configuration['node_type']);
    foreach ($query->execute()->fetchAll() as $r) {
      $this->contributors[(int) $r['vid']][] = [
        'cid' => (int) $r['cid'],
        'auth_type' => (int) $r['auth_type'],
        'auth_category' => (int) $r['auth_category'],
        'rank' => (int) $r['rank'],
      ];
    }
    return $this->contributors;
  }

  /**
   * Keyword ids of every current biblio revision, by revision id.
   */
  protected function keywords(): array {
    if ($this->keywords !== NULL) {
      return $this->keywords;
    }
    $this->keywords = [];
    if (!$this->getDatabase()->schema()->tableExists('biblio_keyword')) {
      return $this->keywords;
    }
    $query = $this->select('biblio_keyword', 'k')
      ->fields('k', ['vid', 'kid'])
      ->orderBy('k.vid')->orderBy('k.kid');
    $query->innerJoin('node', 'n', '[n].[vid] = [k].[vid]');
    $query->condition('n.type', $this->configuration['node_type']);
    foreach ($query->execute()->fetchAll() as $r) {
      $this->keywords[(int) $r['vid']][] = ['kid' => (int) $r['kid']];
    }
    return $this->keywords;
  }

  /**
   * LEFT JOINs the derived table of the best language per node of a field.
   *
   * The node's own language first, else `und`, else the alphabetically first
   * language (what the D7 site shows on its default path), as
   * joinFieldTable() ranks term fields.
   */
  protected function joinBestNodeLanguage(SelectInterface $query, string $table, string $field_alias): void {
    $best = $this->getDatabase()->select($table, 'f2');
    $best->addField('f2', 'entity_id');
    $best->innerJoin('node', 'n2', '[n2].[nid] = [f2].[entity_id]');
    $best->addExpression("SUBSTRING(MIN(CONCAT(CASE WHEN [f2].[language] = [n2].[language] THEN '0' WHEN [f2].[language] = 'und' THEN '1' ELSE '2' END, [f2].[language])), 2)", 'language');
    $best->condition('f2.entity_type', 'node')
      ->condition('f2.bundle', $this->configuration['node_type'])
      ->condition('f2.deleted', 0)
      ->groupBy('f2.entity_id');
    $query->innerJoin($best, 'best', "[best].[entity_id] = [$field_alias].[entity_id] AND [best].[language] = [$field_alias].[language]");
  }

  /**
   * The taxa every biblio node cites, by nid: distinct vocabulary terms.
   */
  protected function taxa(): array {
    if ($this->taxa !== NULL) {
      return $this->taxa;
    }
    $this->taxa = [];
    $field = $this->configuration['taxon_field'];
    $table = 'field_data_' . $field;
    $schema = $this->getDatabase()->schema();
    if (!$schema->tableExists($table)) {
      return $this->taxa;
    }
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id', 'delta'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $this->configuration['node_type'])
      ->condition('f.deleted', 0)
      ->orderBy('f.entity_id')->orderBy('f.delta');
    $this->joinBestNodeLanguage($query, $table, 'f');
    $query->innerJoin('taxonomy_term_data', 'td', "[td].[tid] = [f].[{$field}_tid]");
    $query->addField('td', 'tid');
    $vid = $this->vocabularyId();
    if ($vid) {
      $query->condition('td.vid', $vid);
    }
    $this->addGuid($query, 'td', 'guid');
    $seen = [];
    foreach ($query->execute()->fetchAll() as $r) {
      $nid = (int) $r['entity_id'];
      $tid = (int) $r['tid'];
      if (isset($seen[$nid][$tid]) || $r['guid'] === NULL || $r['guid'] === '') {
        continue;
      }
      $seen[$nid][$tid] = TRUE;
      $this->taxa[$nid][] = ['tid' => $tid, 'guid' => (string) $r['guid']];
    }
    return $this->taxa;
  }

  /**
   * The attachments of every biblio node, by nid, in delta order.
   */
  protected function files(): array {
    if ($this->files !== NULL) {
      return $this->files;
    }
    $this->files = [];
    $field = $this->configuration['file_field'];
    $table = 'field_data_' . $field;
    if (!$this->getDatabase()->schema()->tableExists($table)) {
      return $this->files;
    }
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id', 'delta', $field . '_fid', $field . '_display', $field . '_description'])
      ->condition('f.entity_type', 'node')
      ->condition('f.bundle', $this->configuration['node_type'])
      ->condition('f.deleted', 0)
      ->orderBy('f.entity_id')->orderBy('f.delta');
    $this->joinBestNodeLanguage($query, $table, 'f');
    foreach ($query->execute()->fetchAll() as $r) {
      if (!$r[$field . '_fid']) {
        continue;
      }
      $this->files[(int) $r['entity_id']][] = [
        'fid' => (int) $r[$field . '_fid'],
        'description' => $r[$field . '_description'],
        'display' => (int) $r[$field . '_display'],
      ];
    }
    return $this->files;
  }

}
