<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Site\Settings;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) managed files attached to nodes of given bundles.
 *
 * One row per `file_managed` row that a current field row of `field` on a
 * node of one of `bundles` points at (default: field_file on biblio, the
 * literature attachments), keyed on the D7 fid. The file itself must be
 * present on this machine: `source_files_dir` (configuration, else
 * $settings['taxanotes_import']['source_files_dir']) is the directory the
 * D7 `public://` scheme maps to, as reachable by PHP (under ddev a bind
 * mount of the Scratchpads files directory into the web container). Each
 * row carries:
 * - source_path: the file's absolute path when the directory is configured
 *   and the file exists there, else NULL (the migration skips the row with a
 *   message, so the count of files missing locally is in migrate:messages
 *   and nothing fails);
 * - destination_uri: public://scratchpads/<path relative to the D7 public
 *   directory>, where file_copy puts the file.
 * Only the public scheme is handled; a private:// file is reported missing.
 *
 * Configuration keys:
 * - field: the D7 file field name (default field_file).
 * - bundles: node bundles whose files are rows (default [biblio]).
 * - source_files_dir: the D7 public files directory on this machine.
 */
#[MigrateSource(
  id: 'scratchpads_d7_file',
)]
final class ScratchpadsD7File extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state) {
    $configuration += [
      'field' => 'field_file',
      'bundles' => ['biblio'],
    ];
    if (!array_key_exists('source_files_dir', $configuration)) {
      $configuration['source_files_dir'] = Settings::get('taxanotes_import', [])['source_files_dir'] ?? NULL;
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    foreach (['file_managed', 'field_data_' . $this->configuration['field']] as $table) {
      if (!$schema->tableExists($table)) {
        throw new RequirementsException(sprintf('The migrate database has no %s table.', $table));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $field = $this->configuration['field'];
    $used = $this->getDatabase()->select('field_data_' . $field, 'f');
    $used->addField('f', $field . '_fid', 'fid');
    $used->condition('f.entity_type', 'node')
      ->condition('f.bundle', (array) $this->configuration['bundles'], 'IN')
      ->condition('f.deleted', 0)
      ->distinct();
    $query = $this->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uid', 'filename', 'uri', 'filemime', 'filesize', 'status', 'timestamp'])
      ->orderBy('fm.fid');
    $query->innerJoin($used, 'used', '[used].[fid] = [fm].[fid]');
    if ($this->getDatabase()->schema()->fieldExists('file_managed', 'uuid')) {
      $query->addField('fm', 'uuid');
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'fid' => $this->t('File ID'),
      'uid' => $this->t('Owner uid'),
      'filename' => $this->t('File name'),
      'uri' => $this->t('D7 URI (public://...)'),
      'filemime' => $this->t('MIME type'),
      'filesize' => $this->t('Size in bytes'),
      'status' => $this->t('Status (1 permanent)'),
      'timestamp' => $this->t('Upload time'),
      'uuid' => $this->t('File uuid (contrib uuid module)'),
      'source_path' => $this->t('Absolute path of the file on this machine, NULL when it is not here'),
      'destination_uri' => $this->t('public://scratchpads/<relative path>'),
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
    $uri = (string) $row->getSourceProperty('uri');
    $relative = str_starts_with($uri, 'public://') ? substr($uri, strlen('public://')) : NULL;
    $source_path = NULL;
    $dir = $this->configuration['source_files_dir'];
    if ($relative !== NULL && is_string($dir) && $dir !== '') {
      $candidate = rtrim($dir, '/') . '/' . $relative;
      if (is_file($candidate)) {
        $source_path = $candidate;
      }
    }
    $row->setSourceProperty('source_path', $source_path);
    $row->setSourceProperty('destination_uri', $relative === NULL ? NULL : 'public://scratchpads/' . $relative);
    return parent::prepareRow($row);
  }

}
