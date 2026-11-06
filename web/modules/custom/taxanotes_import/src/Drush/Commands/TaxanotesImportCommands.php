<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Drush\Commands;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Drupal\taxanotes_import\Plugin\migrate\source\ScratchpadsSheet;
use Drupal\taxanotes_import\Sheet\IoMigrateMessage;
use Drupal\taxanotes_import\Sheet\RunCounter;
use Drupal\taxanotes_import\Sheet\SheetReport;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Drush commands of the import module: the sheet track.
 */
final class TaxanotesImportCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * The sheet migrations, in the order they run.
   */
  private const MIGRATIONS = [
    'taxanotes_sheet_taxon',
    'taxanotes_sheet_taxon_link',
    'taxanotes_sheet_taxon_reference',
  ];

  /**
   * Messages per migration the summary prints before migrate:messages.
   */
  private const SHOW_MESSAGES = 25;

  public function __construct(
    #[Autowire(service: 'plugin.manager.migration')]
    private readonly MigrationPluginManagerInterface $migrationPluginManager,
    #[Autowire(service: 'state')]
    private readonly StateInterface $state,
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'entity_field.manager')]
    private readonly EntityFieldManagerInterface $entityFieldManager,
    #[Autowire(service: 'event_dispatcher')]
    private readonly EventDispatcherInterface $eventDispatcher,
    #[Autowire(service: 'database')]
    private readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Imports a Scratchpads taxonomy importer sheet: taxa by GUID, repeatably.
   *
   * Analyses the sheet against the site (rows, GUID styles, duplicate and
   * missing GUIDs, parents and references that resolve nowhere, how many
   * rows update an existing taxon and how many create one), then runs the
   * three sheet migrations with --update semantics: taxanotes_sheet_taxon
   * (the fields), taxanotes_sheet_taxon_link (parent and accepted name),
   * taxanotes_sheet_taxon_reference (the literature links). An unchanged
   * row saves nothing; a changed row on a taxon with history becomes a new
   * revision "Imported from sheet <file> row N". Run it again whenever the
   * owner sends a new sheet.
   */
  #[CLI\Command(name: 'taxanotes:import-sheet', aliases: ['tn-sheet'])]
  #[CLI\Argument(name: 'file', description: 'The sheet: an .xls, .xlsx or .csv in the Scratchpads taxonomy importer template (absolute path, or relative to the directory drush was started in).')]
  #[CLI\Option(name: 'sheet', description: 'The worksheet to read; the first sheet when the workbook has none of that name. Ignored for CSV.')]
  #[CLI\Option(name: 'report-only', description: 'Analyse the sheet against the site and write nothing.')]
  #[CLI\Option(name: 'match-names', description: 'A row whose GUID no taxon carries updates the taxon of the same name (unique in the sheet and on the site, else unique by name and authors), which takes the row\'s GUID. For a site built from a dump older than the sheet, whose terms were re-imported in between and so carry other GUIDs; the analysis says how many rows it would match. Never folds a name that is twice in the sheet or on the site.')]
  #[CLI\Usage(name: 'drush taxanotes:import-sheet /var/www/09/example.org/source/sheets/classification-2026-01.xls --report-only', description: 'Show what the sheet would do: rows, GUIDs, duplicates, new and updated taxa, unresolved parents and references.')]
  #[CLI\Usage(name: 'drush taxanotes:import-sheet /var/www/09/example.org/source/sheets/classification-2026-01.xls', description: 'Import the sheet (the same analysis first, then the three migrations).')]
  #[CLI\Usage(name: 'drush taxanotes:import-sheet export.xlsx --sheet=Sheet1', description: 'Read another worksheet than "Template".')]
  public function importSheet(
    string $file,
    array $options = [
      'sheet' => 'Template',
      'report-only' => FALSE,
      'match-names' => FALSE,
    ],
  ): void {
    $path = $this->resolve($file);
    $report_only = (bool) $options['report-only'];
    $sheet = is_string($options['sheet']) && $options['sheet'] !== '' ? $options['sheet'] : 'Template';

    // The source plugins read the path (and the flags) from state for the
    // length of this run, so the migration YAML names no file; cleared in
    // finally.
    $this->state->set(ScratchpadsSheet::STATE_PATH, $path);
    $this->state->set(ScratchpadsSheet::STATE_SHEET, $sheet);
    $this->state->set(ScratchpadsSheet::STATE_MATCH_NAMES, (bool) $options['match-names']);
    try {
      $taxon = $this->migrationPluginManager->createInstance(self::MIGRATIONS[0]);
      $source = $taxon->getSourcePlugin();
      if (!$source instanceof ScratchpadsSheet) {
        throw new \RuntimeException(sprintf('%s does not use the scratchpads_sheet source.', $taxon->id()));
      }
      $report = new SheetReport($this->entityTypeManager, $this->entityFieldManager, $this->database);
      $analysis = $report->analyse($source);
      $this->io()->title('Sheet analysis');
      foreach ($report->lines($analysis) as $line) {
        $this->io()->writeln($line);
      }
      if ($report_only) {
        $this->io()->writeln('');
        $this->io()->writeln('Report only: nothing written. Run without --report-only to import.');
        return;
      }

      $this->io()->title('Import');
      $migrations = [self::MIGRATIONS[0] => $taxon];
      foreach (array_slice(self::MIGRATIONS, 1) as $id) {
        $migrations[$id] = $this->migrationPluginManager->createInstance($id);
      }
      foreach ($migrations as $id => $migration) {
        if ($id === 'taxanotes_sheet_taxon_reference' && !$analysis['references_available']) {
          $this->io()->writeln(sprintf('%s: skipped, the site has no literature (recipes/taxanotes_literature not applied).', $id));
          continue;
        }
        $this->run($migration);
      }

      $this->io()->section('Next');
      $this->io()->writeln('  drush cr                       rebuild caches (the tree and the taxon pages are cached)');
      $this->io()->writeln('  check /taxonomy-tree           the new and moved taxa in the classification');
      $this->io()->writeln('  check a changed taxon          its Revisions tab shows "Imported from sheet ... row N"');
      $this->io()->writeln('  drush migrate:messages <id>    every message of a pass (the identity notes are on taxanotes_sheet_taxon)');
    }
    finally {
      $this->state->delete(ScratchpadsSheet::STATE_PATH);
      $this->state->delete(ScratchpadsSheet::STATE_SHEET);
      $this->state->delete(ScratchpadsSheet::STATE_MATCH_NAMES);
    }
  }

  /**
   * Runs one migration with --update semantics and prints its outcome.
   */
  private function run(MigrationInterface $migration): void {
    $id = $migration->id();
    $this->io()->section($id);
    if ($migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
      // An interrupted earlier run (a fatal, a killed process) leaves the
      // status at "Importing" for good; nothing else can be running on a
      // drush command line, so clear it and go on.
      $this->io()->writeln(sprintf('  status was "%s" (an earlier run did not finish); reset to Idle', $migration->getStatusLabel()));
      $migration->setStatus(MigrationInterface::STATUS_IDLE);
    }
    $id_map = $migration->getIdMap();
    // The messages are the report of a run: start each run clean.
    $id_map->clearMessages();
    // --update: every row already imported is processed again, so a sheet
    // that changed a cell on a row whose hash is unchanged (it cannot) or a
    // taxon edited on the site is set back to the sheet.
    $id_map->prepareUpdate();
    $counter = new RunCounter($migration, $this->eventDispatcher, $this->entityTypeManager, function (int $saved): void {
      $this->io()->writeln(sprintf('  %d rows', $saved));
    });
    $executable = new MigrateExecutable($migration, new IoMigrateMessage($this->io()));
    $start = microtime(TRUE);
    $result = $executable->import();
    $counter->detach();
    $counts = $counter->counts();
    $this->io()->writeln(sprintf('  %s in %.0f s: %d rows processed; nodes created %d, updated %d, unchanged %d; rows skipped %d, failed %d',
      $result === MigrationInterface::RESULT_COMPLETED ? 'completed' : 'NOT completed (result ' . $result . ')',
      microtime(TRUE) - $start,
      $counts['processed'], $counts['created'], $counts['updated'], $counts['unchanged'], $counts['ignored'], $counts['failed'],
    ));
    $this->messages($migration);
  }

  /**
   * Prints the messages of a migration: counts per level, then the first few.
   */
  private function messages(MigrationInterface $migration): void {
    $labels = [
      MigrationInterface::MESSAGE_ERROR => 'error',
      MigrationInterface::MESSAGE_WARNING => 'warning',
      MigrationInterface::MESSAGE_NOTICE => 'notice',
      MigrationInterface::MESSAGE_INFORMATIONAL => 'info',
    ];
    $messages = [];
    foreach ($migration->getIdMap()->getMessages() as $message) {
      $messages[] = ['level' => (int) $message->level, 'message' => (string) $message->message];
    }
    if (!$messages) {
      $this->io()->writeln('  no messages');
      return;
    }
    $per_level = [];
    foreach ($messages as $message) {
      $label = $labels[$message['level']] ?? (string) $message['level'];
      $per_level[$label] = ($per_level[$label] ?? 0) + 1;
    }
    $this->io()->writeln(sprintf('  %d messages: %s', count($messages), implode(', ', array_map(static fn($l, $n) => "$n $l", array_keys($per_level), $per_level))));
    foreach (array_slice($messages, 0, self::SHOW_MESSAGES) as $message) {
      $this->io()->writeln(sprintf('    [%s] %s', $labels[$message['level']] ?? $message['level'], $message['message']));
    }
    if (count($messages) > self::SHOW_MESSAGES) {
      $this->io()->writeln(sprintf('    ... and %d more: drush migrate:messages %s', count($messages) - self::SHOW_MESSAGES, $migration->id()));
    }
  }

  /**
   * The absolute path of the sheet file argument.
   *
   * Drush runs from the Drupal root; a relative path is taken against the
   * directory the command was started in.
   */
  private function resolve(string $file): string {
    $candidates = [$file];
    if (!str_starts_with($file, '/')) {
      $cwd = $_SERVER['PWD'] ?? getcwd();
      if (is_string($cwd) && $cwd !== '') {
        array_unshift($candidates, rtrim($cwd, '/') . '/' . $file);
      }
    }
    foreach ($candidates as $candidate) {
      $real = realpath($candidate);
      if ($real !== FALSE && is_file($real)) {
        if (!is_readable($real)) {
          throw new \RuntimeException(sprintf('%s is not readable.', $real));
        }
        return $real;
      }
    }
    throw new \RuntimeException(sprintf('%s is not a file.', $file));
  }

}
