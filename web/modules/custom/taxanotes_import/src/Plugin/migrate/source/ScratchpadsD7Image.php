<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Component\Utility\Unicode;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) image files the instance uses: the media items.
 *
 * One row per `file_managed` row of type image, keyed on the D7 fid, that is
 * placed on a node of `image_bundles` through `image_field` (default:
 * field_media on spm, the species pages) or tagged with a term of the
 * instance vocabulary through `taxon_field` (default: field_taxonomic_name
 * on the file; the vocabulary is the base class's `bundle`, from settings):
 * the same rule as the image branch of scratchpads_d7_file, whose file
 * entity the media item wraps. Besides the file_managed columns the row
 * carries, loaded once per import:
 * - taxa: [{tid, guid}] of the file's field_taxonomic_name, one per distinct
 *   vocabulary term (the und rows, else the alphabetically first language);
 * - licence: the creative_commons number of field_cc_licence (1 all rights
 *   reserved ... 9 public domain), NULL when unset;
 * - creator: field_creator (the credit);
 * - description, description_format: field_description;
 * - alt: the description as plain text (first 512 characters), else the
 *   file name.
 *
 * Configuration keys:
 * - image_field, image_bundles, taxon_field: as above.
 * - licence_field, creator_field, description_field: the D7 field names on
 *   the file (defaults field_cc_licence, field_creator, field_description).
 */
#[MigrateSource(
  id: 'scratchpads_d7_image',
)]
final class ScratchpadsD7Image extends ScratchpadsD7Base {

  /**
   * Taxa keyed by fid, loaded once.
   *
   * @var array<int, array<int, array{tid: int, guid: string}>>|null
   */
  protected ?array $taxa = NULL;

  /**
   * One value per fid of the scalar file fields, loaded once per field.
   *
   * @var array<string, array<int, array<string, mixed>>>
   */
  protected array $scalars = [];

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $configuration += [
      'image_field' => 'field_media',
      'image_bundles' => ['spm'],
      'taxon_field' => 'field_taxonomic_name',
      'licence_field' => 'field_cc_licence',
      'creator_field' => 'field_creator',
      'description_field' => 'field_description',
    ];
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    if (!$schema->tableExists('file_managed') || !$schema->fieldExists('file_managed', 'type')) {
      throw new RequirementsException('The migrate database has no file_managed.type column (file_entity is not installed on the Drupal 7 site).');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uid', 'filename', 'uri', 'filemime', 'filesize', 'status', 'timestamp'])
      ->condition('fm.type', 'image')
      ->orderBy('fm.fid');
    $schema = $this->getDatabase()->schema();
    $used = $query->orConditionGroup();
    $branches = 0;
    if ($schema->tableExists('field_data_' . $this->configuration['image_field'])) {
      $used->condition('fm.fid', $this->nodeFileIds($this->configuration['image_field'], (array) $this->configuration['image_bundles']), 'IN');
      $branches++;
    }
    if ($schema->tableExists('field_data_' . $this->configuration['taxon_field']) && $this->vocabularyId()) {
      $used->condition('fm.fid', $this->taggedFileIds($this->configuration['taxon_field']), 'IN');
      $branches++;
    }
    if ($branches === 0) {
      // Neither table: no image is used.
      $query->alwaysFalse();
    }
    else {
      $query->condition($used);
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'fid' => $this->t('File ID (the media id)'),
      'uid' => $this->t('Owner uid'),
      'filename' => $this->t('File name (the media name)'),
      'uri' => $this->t('D7 URI (public://...)'),
      'filemime' => $this->t('MIME type'),
      'filesize' => $this->t('Size in bytes'),
      'status' => $this->t('Status (1 permanent)'),
      'timestamp' => $this->t('Upload time'),
      'taxa' => $this->t('Taxa shown: array of {tid, guid}, vocabulary terms only'),
      'licence' => $this->t('Creative Commons licence number (Scratchpads creative_commons), NULL when unset'),
      'creator' => $this->t('Creator (credit)'),
      'description' => $this->t('Description'),
      'description_format' => $this->t('Description text format'),
      'alt' => $this->t('Alt text: the description as plain text, else the file name'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'fid' => [
        'type' => 'integer',
        'alias' => 'fm',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $fid = (int) $row->getSourceProperty('fid');
    $row->setSourceProperty('taxa', $this->taxa()[$fid] ?? []);
    $licence = $this->scalar($this->configuration['licence_field'], 'licence')[$fid]['value'] ?? NULL;
    $row->setSourceProperty('licence', $licence === NULL ? NULL : (int) $licence);
    $row->setSourceProperty('creator', $this->scalar($this->configuration['creator_field'], 'value')[$fid]['value'] ?? NULL);
    $description = $this->scalar($this->configuration['description_field'], 'value', 'format')[$fid] ?? [];
    $row->setSourceProperty('description', $description['value'] ?? NULL);
    $row->setSourceProperty('description_format', $description['format'] ?? NULL);
    $alt = trim(html_entity_decode(strip_tags((string) ($description['value'] ?? '')), ENT_QUOTES | ENT_HTML5));
    $alt = $alt === '' ? (string) $row->getSourceProperty('filename') : $alt;
    $row->setSourceProperty('alt', Unicode::truncate($alt, 512, TRUE, TRUE));
    return parent::prepareRow($row);
  }

  /**
   * One row per file of a scalar file field (the best language), by fid.
   *
   * @param string $field
   *   The D7 field name.
   * @param string $value_column
   *   The column suffix holding the value (value, licence).
   * @param string|null $format_column
   *   The column suffix holding the text format, if any.
   *
   * @return array<int, array<string, mixed>>
   *   {value, format} per fid; empty when the table does not exist.
   */
  protected function scalar(string $field, string $value_column, ?string $format_column = NULL): array {
    if (isset($this->scalars[$field])) {
      return $this->scalars[$field];
    }
    $this->scalars[$field] = [];
    $table = 'field_data_' . $field;
    if (!$this->getDatabase()->schema()->tableExists($table)) {
      return $this->scalars[$field];
    }
    $query = $this->select($table, 'f')
      ->fields('f', ['entity_id'])
      ->condition('f.entity_type', 'file')
      ->condition('f.deleted', 0)
      ->condition('f.delta', 0);
    $query->addField('f', $field . '_' . $value_column, 'value');
    if ($format_column !== NULL) {
      $query->addField('f', $field . '_' . $format_column, 'format');
    }
    $this->joinBestLanguage($query, $table, 'f', 'file', NULL, FALSE);
    foreach ($query->execute()->fetchAll() as $r) {
      $this->scalars[$field][(int) $r['entity_id']] = ['value' => $r['value'], 'format' => $r['format'] ?? NULL];
    }
    return $this->scalars[$field];
  }

  /**
   * The vocabulary taxa every image is tagged with, by fid.
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
      ->condition('f.entity_type', 'file')
      ->condition('f.deleted', 0)
      ->orderBy('f.entity_id')->orderBy('f.delta');
    $this->joinBestLanguage($query, $table, 'f', 'file', NULL, FALSE);
    $query->innerJoin('taxonomy_term_data', 'td', "[td].[tid] = [f].[{$field}_tid]");
    $query->addField('td', 'tid');
    $vid = $this->vocabularyId();
    if ($vid) {
      $query->condition('td.vid', $vid);
    }
    $this->addGuid($query, 'td', 'guid');
    $seen = [];
    foreach ($query->execute()->fetchAll() as $r) {
      $fid = (int) $r['entity_id'];
      $tid = (int) $r['tid'];
      if (isset($seen[$fid][$tid]) || $r['guid'] === NULL || $r['guid'] === '') {
        continue;
      }
      $seen[$fid][$tid] = TRUE;
      $this->taxa[$fid][] = ['tid' => $tid, 'guid' => (string) $r['guid']];
    }
    return $this->taxa;
  }

}
