<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxanotes_import\Plugin\migrate\source\ScratchpadsSheet;

/**
 * The analysis of a sheet against this site, before (or instead of) a run.
 *
 * Reads the sheet through the very source plugin the migrations use, so the
 * report describes exactly what an import would act on: the row and GUID
 * counts, the identity decisions (duplicates, derived ids, GUID-less rows,
 * parents by name), the columns the template has that the taxon type has
 * not, and, against the site: which rows match an existing taxon by GUID,
 * which are new, how many existing taxa the sheet does not mention, which
 * parent and accepted-name GUIDs resolve nowhere, and which of the
 * literature GUIDs are references this site has.
 */
final class SheetReport {

  /**
   * How many of a long list the console shows.
   */
  private const SHOW = 20;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
  ) {}

  /**
   * Analyses the sheet the source plugin is configured to read.
   *
   * @param \Drupal\taxanotes_import\Plugin\migrate\source\ScratchpadsSheet $source
   *   The source plugin of the sheet taxon migration.
   *
   * @return array<string, mixed>
   *   The analysis; see lines() for what each key means.
   */
  public function analyse(ScratchpadsSheet $source): array {
    $rows = $source->sheetRows();
    if ($rows === NULL) {
      throw new \RuntimeException('No sheet to analyse: ' . (string) $source);
    }
    $records = $rows->records();
    $analysis = [
      'file' => $source->path(),
      'sheet' => $source->sheet(),
    ] + $rows->stats();
    $analysis['notes'] = $rows->notes();

    // Against the site's taxa.
    $taxa = $source->siteGuids();
    $site = $taxa->all();
    $ids = array_keys($records);
    $existing = array_intersect_key($records, $site);
    $matched = [];
    foreach ($records as $id => $record) {
      if ($record['match_guid'] !== $id) {
        $matched[$record['match_guid']] = $id;
      }
    }
    $analysis['site_taxa'] = count($site);
    $analysis['existing'] = count($existing);
    $analysis['name_matched'] = count($matched);
    $analysis['new'] = count($records) - count($existing) - count($matched);
    $analysis['site_not_in_sheet'] = count(array_diff_key($site, $records, $matched));
    $analysis['site_not_in_sheet_list'] = [];
    if ($analysis['site_not_in_sheet']) {
      $labels = $taxa->labelsByGuid(array_keys(array_diff_key($site, $records, $matched)));
      foreach ($labels as $guid => $label) {
        $analysis['site_not_in_sheet_list'][] = ['guid' => $guid, 'name' => $label];
      }
    }
    $analysis['renamed'] = [];
    if ($existing) {
      $labels = $taxa->labelsByGuid(array_keys($existing));
      foreach ($existing as $id => $record) {
        if (isset($labels[$id]) && $labels[$id] !== $record['name']) {
          $analysis['renamed'][] = [
            'row' => $record['row'],
            'id' => $id,
            'from' => $labels[$id],
            'to' => $record['name'],
          ];
        }
      }
    }
    $known = $site + array_fill_keys($ids, TRUE);
    $analysis['parents_unknown'] = [];
    $analysis['accepted_unknown'] = [];
    foreach ($records as $record) {
      if ($record['parent_guid'] !== NULL && !isset($known[$record['parent_guid']])) {
        $analysis['parents_unknown'][] = [
          'row' => $record['row'],
          'name' => $record['name'],
          'guid' => $record['parent_guid'],
        ];
      }
      if ($record['accepted_guid'] !== NULL && !isset($known[$record['accepted_guid']])) {
        $analysis['accepted_unknown'][] = [
          'row' => $record['row'],
          'name' => $record['name'],
          'guid' => $record['accepted_guid'],
        ];
      }
    }

    // The literature.
    $pairs = [];
    $rows_with_reference = 0;
    foreach ($records as $record) {
      $any = FALSE;
      foreach (['reference', 'synonymy_reference', 'designation_reference'] as $column) {
        $guid = $record[$column . '_guid'];
        if ($guid !== NULL) {
          $any = TRUE;
          $pairs[$guid] ??= ['guid' => $guid, 'nid' => $record[$column . '_nid'], 'rows' => 0];
          $pairs[$guid]['rows']++;
        }
      }
      $rows_with_reference += (int) $any;
    }
    $analysis['reference_rows'] = $rows_with_reference;
    $analysis['reference_guids'] = count($pairs);
    $analysis['references_available'] = FALSE;
    $analysis['references_resolved'] = 0;
    $analysis['references_unresolved'] = [];
    if ($pairs && $this->entityTypeManager->hasDefinition('bibcite_reference')) {
      $references = new SiteGuids($this->entityTypeManager, $this->entityFieldManager, $this->database, 'bibcite_reference', NULL, 'field_scratchpads_uuid');
      if ($references->available()) {
        $analysis['references_available'] = TRUE;
        $resolved = $references->idsByGuid(array_keys($pairs));
        $analysis['references_resolved'] = count($resolved);
        $analysis['references_unresolved'] = array_values(array_diff_key($pairs, $resolved));
      }
    }
    return $analysis;
  }

  /**
   * The analysis as console lines.
   *
   * @param array<string, mixed> $a
   *   An analysis from analyse().
   * @param bool $everything
   *   TRUE to list every item of the long lists (the report file), FALSE
   *   to cut them at SHOW with an "and N more" line.
   *
   * @return string[]
   *   The lines.
   */
  public function lines(array $a, bool $everything = FALSE): array {
    $lines = [];
    $lines[] = sprintf('Sheet: %s (worksheet %s)', $a['file'], $a['sheet']);
    $lines[] = sprintf('Rows: %d data rows; %d taxa to import; %d skipped (no Term name); %d dropped (identical or conflicting duplicates)',
      $a['rows'], $a['records'], $a['skipped'], $a['collapsed'] + $a['conflicts']);
    $styles = $a['guid_styles'];
    $lines[] = sprintf('GUIDs: %d md5-style (Scratchpads/Feeds), %d uuid-style (made by hand on the site), %d other, %d missing',
      $styles['md5'], $styles['uuid'], $styles['other'], $styles['none']);
    if ($a['unknown_columns']) {
      $lines[] = 'Columns not in the template (ignored): ' . implode(' | ', $a['unknown_columns']);
    }
    if ($a['missing_columns']) {
      $lines[] = 'Template columns missing from the sheet (their fields are cleared): ' . implode(', ', $a['missing_columns']);
    }
    if ($a['unmapped_columns']) {
      $lines[] = 'Columns the taxon type has no field for (read, not imported): ' . implode(', ', $a['unmapped_columns']);
    }
    $lines[] = sprintf('Cells with markup (imported as basic_html): %d', $a['text_cells']);

    $lines[] = sprintf('Duplicate GUIDs (shared by rows with different names): %d', count($a['duplicates']));
    foreach ($a['duplicates'] as $duplicate) {
      $parts = [];
      foreach ($duplicate['rows'] as $row) {
        $parts[] = sprintf('row %d "%s"%s', $row['row'], $row['name'], $row['id'] === $duplicate['guid'] ? ' (keeps the GUID)' : ' -> ' . $row['id']);
      }
      $lines[] = sprintf('  %s: %s', $duplicate['guid'], implode('; ', $parts));
    }
    $lines[] = sprintf('Rows without a GUID: %d', count($a['guidless']));
    foreach ($a['guidless'] as $row) {
      $lines[] = sprintf('  row %d "%s": %s %s', $row['row'], $row['name'], $row['matched'] ? 'the existing taxon' : 'derived GUID', $row['id']);
    }
    $lines[] = sprintf('Parents given by name only: %d, of which unresolved: %d', $a['parents_by_name'], count($a['parents_unresolved']));
    foreach ($a['parents_unresolved'] as $row) {
      $lines[] = sprintf('  row %d "%s": parent "%s"', $row['row'], $row['name'], $row['parent']);
    }

    $lines[] = sprintf('Against the site: %d taxa on the site; %d rows match an existing taxon by GUID, %d by name (match-names), %d are new; %d existing taxa are not in the sheet',
      $a['site_taxa'], $a['existing'], $a['name_matched'], $a['new'], $a['site_not_in_sheet']);
    $this->list($lines, $a['site_not_in_sheet_list'], $everything, static fn(array $r) => sprintf('  %s (%s)', $r['name'], $r['guid']));
    if ($a['match_names']) {
      $lines[] = sprintf('Rows whose GUID is not on the site matched to a taxon of the same name (re-keyed to the sheet GUID): %d; same name but ambiguous (imported as new): %d', count($a['name_matches']), count($a['name_ambiguous']));
    }
    else {
      $lines[] = sprintf('Rows whose GUID is not on the site but whose name a taxon carries: %d would update that taxon with --match-names (%d more share the name with another row or taxon and stay new)', count($a['name_matches']), count($a['name_ambiguous']));
    }
    $this->list($lines, $a['name_matches'], $everything, static fn(array $r) => sprintf('  row %d "%s": %s -> taxon %s (by %s)', $r['row'], $r['name'], $r['id'], $r['site_guid'], $r['by']));
    $this->list($lines, $a['name_ambiguous'], $everything, static fn(array $r) => sprintf('  row %d "%s": %d taxa of that name on the site, none unique by name and authors', $r['row'], $r['name'], $r['candidates']));
    $lines[] = sprintf('Rows that rename an existing taxon: %d', count($a['renamed']));
    $this->list($lines, $a['renamed'], $everything, static fn(array $r) => sprintf('  row %d: "%s" -> "%s" (%s)', $r['row'], $r['from'], $r['to'], $r['id']));
    $lines[] = sprintf('Parent GUIDs neither in the sheet nor on the site (parent left empty): %d', count($a['parents_unknown']));
    $this->list($lines, $a['parents_unknown'], $everything, static fn(array $r) => sprintf('  row %d "%s": %s', $r['row'], $r['name'], $r['guid']));
    $lines[] = sprintf('Accepted-name GUIDs neither in the sheet nor on the site (left empty): %d', count($a['accepted_unknown']));
    $this->list($lines, $a['accepted_unknown'], $everything, static fn(array $r) => sprintf('  row %d "%s": %s', $r['row'], $r['name'], $r['guid']));

    $lines[] = sprintf('Literature: %d rows name a reference (protologue, synonymy or designation), %d distinct reference GUIDs', $a['reference_rows'], $a['reference_guids']);
    if (!$a['references_available']) {
      $lines[] = '  the site has no literature (recipes/taxanotes_literature not applied): the reference pass is skipped';
    }
    else {
      $lines[] = sprintf('  %d resolve to a reference on this site, %d do not (the sheet carries no titles, so they cannot be created here; the shadow fields keep the GUID and NID)',
        $a['references_resolved'], count($a['references_unresolved']));
      $this->list($lines, $a['references_unresolved'], $everything, static fn(array $r) => sprintf('  %s (NID %s) on %d row(s)', $r['guid'], $r['nid'] ?? '-', $r['rows']));
    }
    return $lines;
  }

  /**
   * Appends a list, cut at SHOW unless everything is wanted.
   */
  private function list(array &$lines, array $items, bool $everything, callable $format): void {
    $shown = $everything ? $items : array_slice($items, 0, self::SHOW);
    foreach ($shown as $item) {
      $lines[] = $format($item);
    }
    if (count($items) > count($shown)) {
      $lines[] = sprintf('  ... and %d more', count($items) - count($shown));
    }
  }

}
