<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\RequirementsInterface;
use Drupal\migrate\Row;
use Drupal\taxanotes_import\Sheet\SheetReader;
use Drupal\taxanotes_import\Sheet\SheetRows;
use Drupal\taxanotes_import\Sheet\SiteGuids;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The rows of a Scratchpads taxonomy importer sheet (.xls, .xlsx or .csv).
 *
 * One row per taxon, keyed by its GUID as settled by SheetRows (the sheet's
 * GUID when it is unique; a derived, stable id for duplicates and GUID-less
 * rows), with the source properties named after the D11 fields the sheet
 * migrations map. The file is read once per process (SheetReader) however
 * many migrations share it.
 *
 * Configuration keys:
 * - path: absolute path of the sheet file. When omitted it is read from the
 *   state key `taxanotes_import.sheet_path` (set by `drush
 *   taxanotes:import-sheet` for the duration of its run) and else from
 *   $settings['taxanotes_import']['sheet_path'], so the migration YAML
 *   stays instance-agnostic. Without any of the three the source has no
 *   rows (migrate:status works) and an import fails its requirements check.
 * - sheet: the worksheet to read (state key `taxanotes_import.sheet`, then
 *   the setting of the same name, default `Template`; the first sheet when
 *   the workbook has none of that name).
 * - match_names: TRUE lets a row whose GUID no taxon carries update the
 *   taxon of the same (unique) name, which then takes the row's GUID (state
 *   key `taxanotes_import.sheet_match_names`, set by the command's
 *   --match-names; else the setting of the same name; default FALSE). See
 *   SheetRows.
 * - with_reference_only: TRUE restricts the rows to those carrying a
 *   Reference, Synonymy reference or Designation reference GUID (the pass
 *   that links the literature).
 * - identity_messages: FALSE keeps the identity notes (duplicates, derived
 *   ids, unresolved parents) out of this migration's messages; the first
 *   pass reports them, the later passes over the same rows need not.
 * - entity_type, bundle, guid_field: where the taxa live on this site
 *   (default node, taxon, field_guid), for the lookups the identity rules
 *   make.
 * - track_changes, constants: see SourcePluginBase.
 *
 * String cells longer than their field allows are cut to the field's length
 * with a message (the D7 fields were 255 characters too; Scratchpads stored
 * what MySQL kept).
 */
#[MigrateSource(
  id: 'scratchpads_sheet',
)]
final class ScratchpadsSheet extends SourcePluginBase implements ContainerFactoryPluginInterface, RequirementsInterface {

  /**
   * State key of the sheet path the Drush command sets for its run.
   */
  public const STATE_PATH = 'taxanotes_import.sheet_path';

  /**
   * State key of the worksheet name the Drush command sets for its run.
   */
  public const STATE_SHEET = 'taxanotes_import.sheet';

  /**
   * State key of the match-names flag the Drush command sets for its run.
   */
  public const STATE_MATCH_NAMES = 'taxanotes_import.sheet_match_names';

  /**
   * String properties and the field (or base field) whose length bounds them.
   */
  private const STRING_FIELDS = [
    'name' => 'title',
    'parent_name' => 'field_parent_term_name',
    'accepted_name' => 'field_accepted_guid',
    'authors' => 'field_authors',
    'page_number' => 'field_page_number',
    'designation_by' => 'field_designation_by',
    'synonymy_by' => 'field_synonymy_by',
    'reference_title' => 'field_reference_title',
  ];

  /**
   * The analysed sheet, built once per instance.
   */
  protected ?SheetRows $rows = NULL;

  /**
   * The records, after the length rule, keyed by id.
   *
   * @var array<string, array<string, mixed>>|null
   */
  protected ?array $records = NULL;

  /**
   * The messages per row id, filed when the row is yielded: [message, level].
   *
   * SourcePluginBase::next() deletes a row's earlier messages before the row
   * is prepared, so a message filed before the iteration would be deleted
   * by the very row it is about; prepareRow() files them instead, and every
   * run reports afresh.
   *
   * @var array<string, array<int, array{string, int}>>
   */
  protected array $messages = [];

  /**
   * The state service (the path and flags the Drush command sets).
   */
  protected StateInterface $state;

  /**
   * The entity type manager (the site lookups).
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager (field lengths, the site lookups).
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * The database (the site lookups).
   */
  protected Connection $database;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    StateInterface $state,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
    Connection $database,
  ) {
    $this->state = $state;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $database;
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
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
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('database'),
    );
  }

  /**
   * The sheet file this source reads, or NULL when none is configured.
   */
  public function path(): ?string {
    $path = $this->configuration['path']
      ?? $this->state->get(self::STATE_PATH)
      ?? (Settings::get('taxanotes_import', [])['sheet_path'] ?? NULL);
    return is_string($path) && $path !== '' ? $path : NULL;
  }

  /**
   * The worksheet to read.
   */
  public function sheet(): string {
    $sheet = $this->configuration['sheet']
      ?? $this->state->get(self::STATE_SHEET)
      ?? (Settings::get('taxanotes_import', [])['sheet'] ?? NULL);
    return is_string($sheet) && $sheet !== '' ? $sheet : 'Template';
  }

  /**
   * Whether rows with a GUID unknown to the site may match taxa by name.
   */
  public function matchNames(): bool {
    $value = $this->configuration['match_names']
      ?? $this->state->get(self::STATE_MATCH_NAMES)
      ?? (Settings::get('taxanotes_import', [])['sheet_match_names'] ?? FALSE);
    return (bool) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    $path = $this->path();
    if ($path === NULL) {
      throw new RequirementsException('No sheet to import: pass the file to drush taxanotes:import-sheet, or set $settings[\'taxanotes_import\'][\'sheet_path\'].');
    }
    if (!is_file($path) || !is_readable($path)) {
      throw new RequirementsException(sprintf('Sheet file %s does not exist or is not readable.', $path));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->path() ?? '(no sheet configured)';
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
  public function fields() {
    return [
      'guid' => $this->t('The row id: the sheet GUID, or a derived uuid for a duplicate or GUID-less row.'),
      'match_guid' => $this->t('The GUID the existing node is looked up by: the row id, or with match_names the GUID of the taxon of the same name.'),
      'sheet_guid' => $this->t('The GUID cell as written.'),
      'name' => $this->t('Term name.'),
      'parent_guid' => $this->t('Parent GUID (resolved from Parent Term Name when empty).'),
      'parent_name' => $this->t('Parent Term Name.'),
      'description' => $this->t('Term description as {value, format}.'),
      'accepted_guid' => $this->t('Associated accepted name (GUID).'),
      'accepted_tid' => $this->t('Associated accepted name (TID).'),
      'accepted_name' => $this->t('Associated accepted name (Name).'),
      'authors' => $this->t('Authors.'),
      'page_number' => $this->t('Page number.'),
      'rank' => $this->t('Rank.'),
      'usage' => $this->t('Usage.'),
      'unacceptability_reason' => $this->t('Unacceptability Reason.'),
      'unit_names' => $this->t('The unit indicator/name pairs as {indicator, name} items.'),
      'vernacular' => $this->t('The vernacular name columns as one {name, language, area, remarks} item, when the name is set.'),
      'reference_guid' => $this->t('Reference (GUID): the D7 node uuid of the protologue.'),
      'reference_nid' => $this->t('Reference (NID).'),
      'reference_title' => $this->t('Reference (Title).'),
      'type_material_notes' => $this->t('Type material notes as {value, format}.'),
      'family_level_name_origins' => $this->t('Family-level name origins as {value, format}.'),
      'designation_by' => $this->t('Designation by.'),
      'synonymy_by' => $this->t('Synonymy by.'),
      'taxonomic_notes' => $this->t('Taxonomic notes as {value, format}.'),
      'biological_notes' => $this->t('Biological notes as {value, format}.'),
      'designation_reference_guid' => $this->t('Designation reference (GUID).'),
      'designation_reference_nid' => $this->t('Designation reference (NID).'),
      'designation_reference_title' => $this->t('Designation reference (Title).'),
      'synonymy_reference_guid' => $this->t('Synonymy reference (GUID).'),
      'synonymy_reference_nid' => $this->t('Synonymy reference (NID).'),
      'synonymy_reference_title' => $this->t('Synonymy reference (Title).'),
      'row' => $this->t('The 1-based sheet row.'),
      'sheet' => $this->t('The sheet file name.'),
      'published' => $this->t('Always 1.'),
      'created' => $this->t('Always NULL (the destination keeps or sets the time).'),
      'changed' => $this->t('Always NULL.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function initializeIterator() {
    return new \ArrayIterator(array_values($this->records()));
  }

  /**
   * {@inheritdoc}
   *
   * Files the row's identity and length notes as messages (see $messages).
   */
  public function prepareRow(Row $row) {
    $id = (string) $row->getSourceProperty('guid');
    if (isset($this->messages[$id]) && ($this->configuration['identity_messages'] ?? TRUE) !== FALSE) {
      $id_map = $this->migration->getIdMap();
      foreach ($this->messages[$id] as [$message, $level]) {
        $id_map->saveMessage(['guid' => $id], $message, $level);
      }
    }
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  protected function doCount() {
    return count($this->records());
  }

  /**
   * The analysed sheet (identity rules applied), or NULL without a file.
   *
   * Public so the Drush report shows the same analysis the import acts on.
   */
  public function sheetRows(): ?SheetRows {
    if ($this->rows !== NULL) {
      return $this->rows;
    }
    $path = $this->path();
    if ($path === NULL || !is_file($path)) {
      return NULL;
    }
    $table = SheetReader::read($path, $this->sheet());
    $site = $this->siteGuids();
    $this->rows = new SheetRows(
      $table['header'],
      $table['rows'],
      basename($path),
      static fn(array $guids): array => $site->labelsByGuid($guids),
      static fn(array $titles): array => $site->byLabel($titles),
      $this->matchNames(),
    );
    return $this->rows;
  }

  /**
   * The lookup of this site's taxa by GUID and title.
   */
  public function siteGuids(): SiteGuids {
    return new SiteGuids(
      $this->entityTypeManager,
      $this->entityFieldManager,
      $this->database,
      (string) ($this->configuration['entity_type'] ?? 'node'),
      array_key_exists('bundle', $this->configuration) ? $this->configuration['bundle'] : 'taxon',
      (string) ($this->configuration['guid_field'] ?? 'field_guid'),
    );
  }

  /**
   * The records this migration iterates, keyed by id.
   *
   * Builds them once: the identity notes of rows that have an id and the
   * over-long strings (cut to the field's length) are queued as messages
   * for prepareRow(); a skipped row has no id to file a message under (the
   * Drush report lists it). with_reference_only drops the rows without a
   * reference.
   *
   * @return array<string, array<string, mixed>>
   *   The records.
   */
  private function records(): array {
    if ($this->records !== NULL) {
      return $this->records;
    }
    $this->records = [];
    $rows = $this->sheetRows();
    if ($rows === NULL) {
      return $this->records;
    }
    foreach ($rows->notes() as $note) {
      if ($note['id'] !== NULL) {
        $this->messages[$note['id']][] = [$note['message'], MigrationInterface::MESSAGE_NOTICE];
      }
    }
    $lengths = $this->stringLengths();
    $reference_only = !empty($this->configuration['with_reference_only']);
    foreach ($rows->records() as $id => $record) {
      if ($reference_only && $record['reference_guid'] === NULL && $record['synonymy_reference_guid'] === NULL && $record['designation_reference_guid'] === NULL) {
        continue;
      }
      foreach ($lengths as $property => $length) {
        if (is_string($record[$property]) && mb_strlen($record[$property]) > $length) {
          $message = sprintf('row %d: %s is %d characters, the field holds %d; cut', $record['row'], $property, mb_strlen($record[$property]), $length);
          $this->messages[$id][] = [$message, MigrationInterface::MESSAGE_WARNING];
          $record[$property] = mb_substr($record[$property], 0, $length);
        }
      }
      $this->records[$id] = $record;
    }
    return $this->records;
  }

  /**
   * The maximum length of every bounded string property, from the fields.
   *
   * @return array<string, int>
   *   Property => length, for the fields the bundle has.
   */
  private function stringLengths(): array {
    $entity_type = (string) ($this->configuration['entity_type'] ?? 'node');
    $bundle = array_key_exists('bundle', $this->configuration) ? $this->configuration['bundle'] : 'taxon';
    $definitions = $bundle === NULL
      ? $this->entityFieldManager->getBaseFieldDefinitions($entity_type)
      : $this->entityFieldManager->getFieldDefinitions($entity_type, (string) $bundle);
    $lengths = [];
    foreach (self::STRING_FIELDS as $property => $field) {
      $length = isset($definitions[$field]) ? $definitions[$field]->getFieldStorageDefinition()->getSetting('max_length') : NULL;
      if (is_numeric($length) && (int) $length > 0) {
        $lengths[$property] = (int) $length;
      }
    }
    return $lengths;
  }

}
