<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\ImageToolkit\ImageToolkitManager;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scratchpads (Drupal 7) managed files the instance uses: attachments, images.
 *
 * One row per `file_managed` row, keyed on the D7 fid, that is
 * - attached to a node of `bundles` through `field` (default: field_file on
 *   biblio, the literature attachments), or
 * - an image (file_managed.type = image) that is placed on a node of
 *   `image_bundles` through `image_field` (default: field_media on spm, the
 *   species pages) or tagged with a term of the instance vocabulary through
 *   `image_taxon_field` (default: field_taxonomic_name on the file; the
 *   vocabulary is the `bundle` of the base class, from settings), or
 * - pointed at by an inline <img> in a text of a node of `inline_bundles`
 *   (default: the pages, blog entries and species pages; see
 *   ScratchpadsD7Base::inlineImages()), so the texts can show it.
 * The file itself must be present on this machine: `source_files_dir`
 * (configuration, else $settings['taxanotes_import']['source_files_dir']) is
 * the directory the D7 `public://` scheme maps to, as reachable by PHP (under
 * ddev a bind mount of the Scratchpads files directory into the web
 * container). Each row carries:
 * - kind: 'image' for an image file, 'file' otherwise;
 * - image_supported: 0 for an image whose type the site's image toolkit
 *   cannot render (TIFF on the pilot sites), 1 otherwise; the migration
 *   skips such a row with a message, since an image style cannot be derived
 *   from it and the browser would show a broken image;
 * - source_path: the file's absolute path when the directory is configured
 *   and the file exists there, else NULL (the migration skips the row with a
 *   message, so the count of files missing locally is in migrate:messages
 *   and nothing fails);
 * - destination_uri: public://scratchpads/<path relative to the D7 public
 *   directory>, where file_copy puts the file.
 * Only the public scheme is handled; a private:// or remote file is reported
 * missing.
 *
 * Configuration keys:
 * - field: the D7 file field name on nodes (default field_file).
 * - bundles: node bundles whose attachments are rows (default [biblio]).
 * - image_field: the D7 file field that places images on nodes (default
 *   field_media).
 * - image_bundles: node bundles whose placed images are rows (default
 *   [spm]).
 * - image_taxon_field: the term reference on files (default
 *   field_taxonomic_name).
 * - images: FALSE to leave images out (default TRUE).
 * - inline_bundles: node bundles whose texts are scanned for inline images
 *   (default [page, blog, spm]); [] scans none.
 * - source_files_dir: the D7 public files directory on this machine.
 */
#[MigrateSource(
  id: 'scratchpads_d7_file',
)]
final class ScratchpadsD7File extends ScratchpadsD7Base {

  /**
   * The file extensions the site's image toolkit renders, lower case.
   *
   * @var string[]
   */
  protected array $supportedExtensions;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, $migration, $state, ImageToolkitManager $toolkit_manager) {
    $configuration += [
      'field' => 'field_file',
      'bundles' => ['biblio'],
      'image_field' => 'field_media',
      'image_bundles' => ['spm'],
      'image_taxon_field' => 'field_taxonomic_name',
      'images' => TRUE,
      'inline_bundles' => ['page', 'blog', 'spm'],
    ];
    if (!array_key_exists('source_files_dir', $configuration)) {
      $configuration['source_files_dir'] = $this->settingsFilesDir();
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
    $this->supportedExtensions = array_map('strtolower', $toolkit_manager->getDefaultToolkit()->getSupportedExtensions());
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get('image.toolkit.manager'),
    );
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
    $query = $this->select('file_managed', 'fm')
      ->fields('fm', ['fid', 'uid', 'filename', 'uri', 'filemime', 'filesize', 'status', 'timestamp'])
      ->orderBy('fm.fid');
    $schema = $this->getDatabase()->schema();
    foreach (['uuid', 'type'] as $column) {
      if ($schema->fieldExists('file_managed', $column)) {
        $query->addField('fm', $column);
      }
    }
    $used = $query->orConditionGroup();
    $used->condition('fm.fid', $this->nodeFileIds($this->configuration['field'], (array) $this->configuration['bundles']), 'IN');
    if ($this->configuration['images'] && $schema->fieldExists('file_managed', 'type')) {
      foreach ($this->imageFileIds() as $ids) {
        $used->condition($query->andConditionGroup()
          ->condition('fm.type', 'image')
          ->condition('fm.fid', $ids, 'IN'));
      }
    }
    $inline = $this->inlineImageFileIds((array) $this->configuration['inline_bundles']);
    if ($inline) {
      $used->condition('fm.fid', $inline, 'IN');
    }
    $query->condition($used);
    return $query;
  }

  /**
   * The subqueries of the image files the instance uses (see the class).
   *
   * @return \Drupal\Core\Database\Query\SelectInterface[]
   *   One per existing D7 table: images placed on nodes of image_bundles,
   *   images tagged with a vocabulary term.
   */
  protected function imageFileIds(): array {
    $schema = $this->getDatabase()->schema();
    $ids = [];
    if ($schema->tableExists('field_data_' . $this->configuration['image_field'])) {
      $ids[] = $this->nodeFileIds($this->configuration['image_field'], (array) $this->configuration['image_bundles']);
    }
    if ($schema->tableExists('field_data_' . $this->configuration['image_taxon_field']) && $this->vocabularyId()) {
      $ids[] = $this->taggedFileIds($this->configuration['image_taxon_field']);
    }
    return $ids;
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
      'type' => $this->t('File entity type (file_entity module): image, application, ...'),
      'kind' => $this->t('"image" for an image file, "file" otherwise'),
      'image_supported' => $this->t('0 for an image the image toolkit cannot render, 1 otherwise'),
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
    $is_image = $row->getSourceProperty('type') === 'image' || str_starts_with((string) $row->getSourceProperty('filemime'), 'image/');
    $row->setSourceProperty('kind', $is_image ? 'image' : 'file');
    $extension = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
    $row->setSourceProperty('image_supported', (!$is_image || in_array($extension, $this->supportedExtensions, TRUE)) ? 1 : 0);
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
