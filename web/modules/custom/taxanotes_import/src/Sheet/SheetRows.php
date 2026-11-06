<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

/**
 * The rows of a taxonomy importer sheet as source records with stable ids.
 *
 * Turns the header and cells SheetReader gives into one record per taxon,
 * keyed by the properties the sheet migrations map (named after the D11
 * fields: `name`, `parent_guid`, `rank`, `unit_names`, ...), and settles the
 * identity of every row before any of it is imported. The sheet's GUID
 * column is the key the Scratchpads importer matched on, and this does the
 * same, with rules for what a hand-edited sheet gets wrong:
 * - a GUID carried by one row is that row's id;
 * - rows that share a GUID and are identical cell for cell are one row (the
 *   first is imported, the others noted);
 * - rows that share a GUID and a Term name but differ are a conflict: the
 *   first is imported, the others noted and dropped (the owner must merge
 *   them; a guessed merge would silently lose a cell);
 * - rows that share a GUID with *different* Term names are different taxa
 *   (rows copied on the Scratchpads site with the uuid kept): the row whose
 *   name matches the taxon that already carries the GUID on the site keeps
 *   it (else the first row), every other row gets the derived id
 *   uuid5(GUID + "|" + Term name), the same on every run;
 * - a row without a GUID is the taxon of that name on the site when exactly
 *   one exists (and its GUID is not another row's), else the derived id
 *   uuid5(Term name + "|" + Parent GUID or Parent Term Name); a GUID cell
 *   that is neither md5- nor uuid-shaped (a name typed into the column) is
 *   treated as missing, with a note;
 * - a row without a Term name is skipped;
 * - with `match_names` on, a row whose GUID no taxon on the site carries
 *   updates the taxon of the same Term name when that name is unique in the
 *   sheet and on the site (else the same name and Authors, unique on both
 *   sides), and that taxon takes the row's GUID: the rule for a site built
 *   from a dump older than the sheet, whose owner re-imported the
 *   classification on Scratchpads in between (every term then got a new
 *   Feeds GUID). Off by default, since a genuinely
 *   new homonym must not be folded into an existing name; the report says
 *   how many rows it would match.
 * The derived ids are RFC 4122 version 5 UUIDs under the namespace
 * UUID_NAMESPACE, so the same sheet (or the same taxon in a later sheet)
 * yields the same id and the import stays repeatable. Every decision is a
 * note (row, id, message) the source plugin saves as a migration message
 * and the Drush report prints.
 *
 * A parent given by name only (Parent GUID empty, Parent Term Name set)
 * resolves to the unique row of that name in the sheet, else to the unique
 * taxon of that name on the site, else stays empty with a note.
 *
 * Only the formatted-text columns (TEXT_PROPERTIES) keep markup; every other
 * cell is plain text: tags are removed and entities decoded (the exporter
 * writes the <em> of an italicised name into "Synonymy by" and "Designation
 * by" too, whose fields are plain strings that would show the tags), before
 * the identity rules compare names and the length rule counts characters.
 *
 * Pure PHP: what it needs from the site comes in as two callables, so the
 * rules can be unit-tested and the source plugin, the Drush report and the
 * tests share one implementation.
 */
final class SheetRows {

  /**
   * Namespace of the derived (version 5) UUIDs; never change it.
   */
  public const UUID_NAMESPACE = '125888c5-4c25-4c86-a677-fae57f40e37a';

  /**
   * Sheet columns: normalised header (see normaliseHeader()) => property.
   *
   * The Scratchpads taxonomy importer template, as the exporter writes it
   * (44 columns). The unit name/indicator columns fold into `unit_names`,
   * the vernacular columns into one `vernacular` item; the "Taxonomic
   * revisions" column and the vernacular source columns have no field on
   * the taxon type and are read but not imported (UNMAPPED).
   */
  public const COLUMNS = [
    'term name' => 'name',
    'parent term name' => 'parent_name',
    'term description' => 'description',
    'guid' => 'sheet_guid',
    'parent guid' => 'parent_guid',
    'associated accepted name (name)' => 'accepted_name',
    'associated accepted name (tid)' => 'accepted_tid',
    'associated accepted name (guid)' => 'accepted_guid',
    'vernacular names: vernacular name' => 'vernacular_name',
    'vernacular names: language' => 'vernacular_language',
    'vernacular names: source (guid)' => 'vernacular_source_guid',
    'vernacular names: source (nid)' => 'vernacular_source_nid',
    'vernacular names: source (title)' => 'vernacular_source_title',
    'vernacular names: area from which the vernacular name usage originates' => 'vernacular_area',
    'vernacular names: vernacular name remarks' => 'vernacular_remarks',
    'authors' => 'authors',
    'page number' => 'page_number',
    'rank' => 'rank',
    'reference (guid)' => 'reference_guid',
    'reference (nid)' => 'reference_nid',
    'reference (title)' => 'reference_title',
    'unacceptability reason' => 'unacceptability_reason',
    'unit indicator 1' => 'unit_indicator1',
    'unit indicator 2' => 'unit_indicator2',
    'unit indicator 3' => 'unit_indicator3',
    'unit indicator 4' => 'unit_indicator4',
    'unit name 1' => 'unit_name1',
    'unit name 2' => 'unit_name2',
    'unit name 3' => 'unit_name3',
    'unit name 4' => 'unit_name4',
    'usage' => 'usage',
    'type material notes' => 'type_material_notes',
    'family level name origins' => 'family_level_name_origins',
    'designation by' => 'designation_by',
    'synonymy by' => 'synonymy_by',
    'taxonomic notes' => 'taxonomic_notes',
    'taxonomic revisions' => 'taxonomic_revisions',
    'biological notes' => 'biological_notes',
    'designation reference (guid)' => 'designation_reference_guid',
    'designation reference (nid)' => 'designation_reference_nid',
    'designation reference (title)' => 'designation_reference_title',
    'synonymy reference (guid)' => 'synonymy_reference_guid',
    'synonymy reference (nid)' => 'synonymy_reference_nid',
    'synonymy reference (title)' => 'synonymy_reference_title',
  ];

  /**
   * Sheet columns without a field on the taxon type: read, not imported.
   */
  public const UNMAPPED = [
    'taxonomic_revisions',
    'vernacular_source_guid',
    'vernacular_source_nid',
    'vernacular_source_title',
  ];

  /**
   * Properties whose D11 field is formatted text: {value, format} items.
   */
  public const TEXT_PROPERTIES = [
    'description',
    'type_material_notes',
    'family_level_name_origins',
    'taxonomic_notes',
    'biological_notes',
  ];

  /**
   * The properties of a record, in order; every record carries all of them.
   */
  public const PROPERTIES = [
    'guid',
    'match_guid',
    'sheet_guid',
    'name',
    'parent_guid',
    'parent_name',
    'description',
    'accepted_guid',
    'accepted_tid',
    'accepted_name',
    'authors',
    'page_number',
    'rank',
    'usage',
    'unacceptability_reason',
    'unit_names',
    'vernacular',
    'reference_guid',
    'reference_nid',
    'reference_title',
    'type_material_notes',
    'family_level_name_origins',
    'designation_by',
    'synonymy_by',
    'taxonomic_notes',
    'biological_notes',
    'designation_reference_guid',
    'designation_reference_nid',
    'designation_reference_title',
    'synonymy_reference_guid',
    'synonymy_reference_nid',
    'synonymy_reference_title',
    'row',
    'sheet',
    'published',
    'created',
    'changed',
  ];

  /**
   * The number of unit positions the template has (unit name 1..4).
   */
  private const UNIT_POSITIONS = 4;

  /**
   * The records, keyed by id, in sheet order.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $records = [];

  /**
   * The identity and parent notes, in row order.
   *
   * @var array<int, array{row: int, id: string|null, message: string}>
   */
  private array $notes = [];

  /**
   * Counts and lists for the report.
   *
   * @var array<string, mixed>
   */
  private array $stats = [];

  /**
   * Builds the records of a sheet.
   *
   * @param string[] $header
   *   The header cells.
   * @param array<int, array<int, string|null>> $rows
   *   The data rows keyed by sheet row (SheetReader::read()).
   * @param string $sheet
   *   The sheet's file name, for the `sheet` property (the revision logs).
   * @param callable(string[]): array<string, string> $titles_by_guid
   *   Returns, for the given GUIDs, the title of the taxon on the site that
   *   carries each (absent when none does).
   * @param callable(string[]): array<string, array<int, array{id: int, guid: string|null, authors: string|null}>> $taxa_by_title
   *   Returns, for the given titles, every taxon on the site carrying each
   *   (its id, GUID and authors).
   * @param bool $match_names
   *   Whether a row whose GUID is unknown to the site may update the taxon
   *   of the same (unique) name, re-keying it to the row's GUID.
   */
  public function __construct(array $header, array $rows, string $sheet, callable $titles_by_guid, callable $taxa_by_title, bool $match_names = FALSE) {
    $this->build($header, $rows, $sheet, $titles_by_guid, $taxa_by_title, $match_names);
  }

  /**
   * The records keyed by id, in sheet order.
   *
   * @return array<string, array<string, mixed>>
   *   One record per imported row with every PROPERTIES key.
   */
  public function records(): array {
    return $this->records;
  }

  /**
   * The notes: skipped rows, duplicate and derived ids, unresolved parents.
   *
   * @return array<int, array{row: int, id: string|null, message: string}>
   *   In row order.
   */
  public function notes(): array {
    return $this->notes;
  }

  /**
   * The counts and lists of the analysis (see build()).
   *
   * @return array<string, mixed>
   *   Keys: rows, records, skipped, guid_styles, duplicates, collapsed,
   *   conflicts, derived, guidless, invalid_guids, parents_by_name,
   *   parents_unresolved, unknown_columns, missing_columns,
   *   unmapped_columns, text_cells, stripped_cells, match_names,
   *   name_matches, name_ambiguous.
   */
  public function stats(): array {
    return $this->stats;
  }

  /**
   * Normalises a header cell for matching against COLUMNS.
   *
   * Case, surrounding space, runs of space, underscores and hyphens, and a
   * missing space before a digit ("Unit name1") do not matter.
   */
  public static function normaliseHeader(string $header): string {
    $key = mb_strtolower(trim($header));
    $key = preg_replace('/[\s_\-]+/u', ' ', $key) ?? $key;
    $key = preg_replace('/([a-z])(\d)/u', '$1 $2', $key) ?? $key;
    return trim($key);
  }

  /**
   * A version 5 UUID of a name under UUID_NAMESPACE.
   */
  public static function derivedId(string $name): string {
    $hash = sha1(hex2bin(str_replace('-', '', self::UUID_NAMESPACE)) . $name);
    return sprintf(
      '%s-%s-%04x-%04x-%s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
      substr($hash, 20, 12),
    );
  }

  /**
   * The style of a GUID: md5 (Feeds), uuid (a hand-made term), other.
   */
  public static function guidStyle(string $guid): string {
    if (preg_match('/^[0-9a-f]{32}$/i', $guid)) {
      return 'md5';
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $guid)) {
      return 'uuid';
    }
    return 'other';
  }

  /**
   * Maps the header onto properties and records which columns are missing.
   *
   * @return array<int, string>
   *   Column index => property, for the columns COLUMNS knows.
   */
  private function columns(array $header): array {
    $columns = [];
    $seen = [];
    $unknown = [];
    foreach ($header as $index => $cell) {
      $key = self::normaliseHeader((string) $cell);
      if ($key === '') {
        continue;
      }
      if (isset(self::COLUMNS[$key]) && !isset($seen[self::COLUMNS[$key]])) {
        $columns[(int) $index] = self::COLUMNS[$key];
        $seen[self::COLUMNS[$key]] = TRUE;
      }
      else {
        $unknown[] = trim((string) $cell);
      }
    }
    $this->stats['unknown_columns'] = $unknown;
    $this->stats['missing_columns'] = array_values(array_diff(array_values(self::COLUMNS), array_keys($seen)));
    $this->stats['unmapped_columns'] = array_values(array_intersect(self::UNMAPPED, array_keys($seen)));
    return $columns;
  }

  /**
   * Builds records, notes and stats (see the class comment for the rules).
   */
  private function build(array $header, array $rows, string $sheet, callable $titles_by_guid, callable $taxa_by_title, bool $match_names): void {
    $columns = $this->columns($header);
    $this->stats += [
      'rows' => count($rows),
      'skipped' => 0,
      'guid_styles' => ['md5' => 0, 'uuid' => 0, 'other' => 0, 'none' => 0],
      'duplicates' => [],
      'collapsed' => 0,
      'conflicts' => 0,
      'derived' => 0,
      'guidless' => [],
      'invalid_guids' => [],
      'parents_by_name' => 0,
      'parents_unresolved' => [],
      'text_cells' => 0,
      'stripped_cells' => 0,
      'match_names' => $match_names,
      'name_matches' => [],
      'name_ambiguous' => [],
    ];

    // The raw cells per row, by property; plain text except the formatted
    // texts.
    $raw = [];
    foreach ($rows as $number => $cells) {
      $record = array_fill_keys(array_values(self::COLUMNS), NULL);
      foreach ($columns as $index => $property) {
        $record[$property] = in_array($property, self::TEXT_PROPERTIES, TRUE) ? ($cells[$index] ?? NULL) : $this->plain($cells[$index] ?? NULL);
      }
      if ($record['name'] === NULL) {
        $this->note($number, NULL, sprintf('row %d has no Term name; skipped', $number));
        $this->stats['skipped']++;
        continue;
      }
      $style = $record['sheet_guid'] === NULL ? 'none' : self::guidStyle($record['sheet_guid']);
      $this->stats['guid_styles'][$style]++;
      if ($style === 'other') {
        // A name or a note in the GUID column: not an identifier the
        // Scratchpads importer would have written; the GUID-less rule
        // applies (identities() words the note).
        $this->stats['invalid_guids'][] = ['row' => $number, 'name' => $record['name'], 'guid' => $record['sheet_guid']];
        $record['invalid_guid'] = $record['sheet_guid'];
        $record['sheet_guid'] = NULL;
      }
      $raw[$number] = $record;
    }

    // Identity: the id of every row.
    $ids = $this->identities($raw, $titles_by_guid, $taxa_by_title);

    // Rows whose GUID the site does not know but whose name it does.
    $match = $this->matchNames($raw, $ids, $titles_by_guid, $taxa_by_title, $match_names);

    // Parents given by name only.
    $this->resolveParentNames($raw, $ids, $taxa_by_title);

    // The records in sheet order, shaped for the migrations.
    foreach ($raw as $number => $record) {
      if (!isset($ids[$number])) {
        continue;
      }
      $this->records[$ids[$number]] = $this->shape($record, $ids[$number], $match[$number] ?? $ids[$number], $number, $sheet);
    }
    $this->stats['records'] = count($this->records);
  }

  /**
   * Settles the id of every row (NULL = not imported) and notes why.
   *
   * @param array<int, array<string, string|null>> $raw
   *   The rows that have a Term name.
   * @param callable $titles_by_guid
   *   The site lookup of titles by GUID (see the constructor).
   * @param callable $taxa_by_title
   *   The site lookup of taxa by title (see the constructor).
   *
   * @return array<int, string>
   *   Row => id, for the rows that are imported.
   */
  private function identities(array $raw, callable $titles_by_guid, callable $taxa_by_title): array {
    $ids = [];
    $groups = [];
    foreach ($raw as $number => $record) {
      if ($record['sheet_guid'] !== NULL) {
        $groups[$record['sheet_guid']][] = $number;
      }
    }
    $shared = array_filter($groups, static fn(array $rows) => count($rows) > 1);
    $site_titles = $shared ? $titles_by_guid(array_keys($shared)) : [];

    foreach ($groups as $guid => $rows) {
      if (count($rows) === 1) {
        $ids[$rows[0]] = (string) $guid;
        continue;
      }
      // Identical rows are one row.
      $kept = [];
      $signatures = [];
      foreach ($rows as $number) {
        $signature = serialize($raw[$number]);
        if (isset($signatures[$signature])) {
          $this->note($number, (string) $guid, sprintf('rows %d and %d are identical (GUID %s, name %s); row %d is not imported again', $signatures[$signature], $number, $guid, $raw[$number]['name'], $number));
          $this->stats['collapsed']++;
          continue;
        }
        $signatures[$signature] = $number;
        $kept[] = $number;
      }
      // Rows of the same name that still differ are a conflict for the
      // owner to resolve: the first is imported, the others dropped.
      $by_name = [];
      foreach ($kept as $number) {
        $name = $raw[$number]['name'];
        if (isset($by_name[$name])) {
          $this->note($number, (string) $guid, sprintf('rows %d and %d share GUID %s and the name %s but differ; row %d is imported, row %d is not: merge them into one row', $by_name[$name], $number, $guid, $name, $by_name[$name], $number));
          $this->stats['conflicts']++;
          continue;
        }
        $by_name[$name] = $number;
      }
      if (count($by_name) === 1) {
        $ids[reset($by_name)] = (string) $guid;
        continue;
      }
      // Different names: the one the site knows under this GUID keeps it.
      $owner = isset($site_titles[$guid], $by_name[$site_titles[$guid]]) ? $by_name[$site_titles[$guid]] : reset($by_name);
      $ids[$owner] = (string) $guid;
      $entry = ['guid' => (string) $guid, 'rows' => []];
      foreach ($by_name as $name => $number) {
        $id = $number === $owner ? (string) $guid : self::derivedId($guid . '|' . $name);
        $entry['rows'][] = ['row' => $number, 'name' => $name, 'id' => $id];
        if ($number === $owner) {
          continue;
        }
        $ids[$number] = $id;
        $this->stats['derived']++;
        $this->note($number, $id, sprintf('GUID %s is shared by rows %s (names %s); row %d imported as derived GUID %s',
          $guid,
          implode(' and ', array_values($by_name)),
          implode(', ', array_keys($by_name)),
          $number,
          $id,
        ));
      }
      $this->stats['duplicates'][] = $entry;
    }

    // Rows without a GUID: the taxon of that name on the site, else derived.
    $guidless = array_keys(array_filter($raw, static fn(array $r) => $r['sheet_guid'] === NULL));
    $site_guids = $guidless ? $this->uniqueGuids($taxa_by_title(array_unique(array_map(static fn(int $n) => $raw[$n]['name'], $guidless)))) : [];
    $taken = array_flip($ids);
    foreach ($guidless as $number) {
      $name = $raw[$number]['name'];
      $why = isset($raw[$number]['invalid_guid'])
        ? sprintf('row %d (%s): the GUID cell "%s" is not a GUID (neither md5 nor uuid), treated as missing;', $number, $name, $raw[$number]['invalid_guid'])
        : sprintf('row %d has no GUID;', $number);
      if (isset($site_guids[$name]) && !isset($taken[$site_guids[$name]])) {
        $id = $site_guids[$name];
        $this->note($number, $id, sprintf('%s it is the existing taxon %s (GUID %s)', $why, $name, $id));
        $this->stats['guidless'][] = ['row' => $number, 'name' => $name, 'id' => $id, 'matched' => TRUE];
      }
      else {
        $id = self::derivedId($name . '|' . ($raw[$number]['parent_guid'] ?? $raw[$number]['parent_name'] ?? ''));
        $this->note($number, $id, sprintf('%s imported as derived GUID %s', $why, $id));
        $this->stats['guidless'][] = ['row' => $number, 'name' => $name, 'id' => $id, 'matched' => FALSE];
        $this->stats['derived']++;
      }
      if (isset($taken[$id])) {
        $this->note($number, $id, sprintf('row %d resolves to GUID %s, which row %d already has; row %d is not imported', $number, $id, $taken[$id], $number));
        $this->stats['conflicts']++;
        continue;
      }
      $ids[$number] = $id;
      $taken[$id] = $number;
    }
    ksort($ids);
    return $ids;
  }

  /**
   * Fills `parent_guid` of the rows whose parent is given by name only.
   *
   * @param array<int, array<string, string|null>> $raw
   *   The rows, by reference: `parent_guid` is set where resolved.
   * @param array<int, string> $ids
   *   Row => id.
   * @param callable $taxa_by_title
   *   The site lookup of taxa by title (see the constructor).
   */
  private function resolveParentNames(array &$raw, array $ids, callable $taxa_by_title): void {
    $by_name = [];
    foreach ($ids as $number => $id) {
      $by_name[$raw[$number]['name']][] = $id;
    }
    $pending = [];
    foreach ($ids as $number => $id) {
      $record = $raw[$number];
      if ($record['parent_guid'] !== NULL || $record['parent_name'] === NULL) {
        continue;
      }
      $this->stats['parents_by_name']++;
      if (isset($by_name[$record['parent_name']]) && count($by_name[$record['parent_name']]) === 1) {
        $raw[$number]['parent_guid'] = $by_name[$record['parent_name']][0];
        continue;
      }
      $pending[$number] = $record['parent_name'];
    }
    if (!$pending) {
      return;
    }
    $site_guids = $this->uniqueGuids($taxa_by_title(array_values(array_unique($pending))));
    foreach ($pending as $number => $name) {
      if (isset($site_guids[$name])) {
        $raw[$number]['parent_guid'] = $site_guids[$name];
        continue;
      }
      $this->note($number, $ids[$number], sprintf('row %d: parent "%s" is not a unique Term name in the sheet nor a unique taxon on the site; parent left empty', $number, $name));
      $this->stats['parents_unresolved'][] = ['row' => $number, 'name' => $raw[$number]['name'], 'parent' => $name];
    }
  }

  /**
   * Matches rows with a GUID unknown to the site to taxa of the same name.
   *
   * Computed whether or not `match_names` is on (the report says what the
   * rule would do); applied, with notes, only when it is. A site taxon is
   * matched at most once. See the class comment for the rule.
   *
   * @param array<int, array<string, string|null>> $raw
   *   The rows.
   * @param array<int, string> $ids
   *   Row => id.
   * @param callable $titles_by_guid
   *   The site lookup of titles by GUID (see the constructor).
   * @param callable $taxa_by_title
   *   The site lookup of taxa by title (see the constructor).
   * @param bool $apply
   *   Whether to apply the matches (match_names) or only record them.
   *
   * @return array<int, string>
   *   Row => the GUID of the site taxon the row updates (the node is looked
   *   up by it; the row's id replaces it), for the matched rows only.
   */
  private function matchNames(array $raw, array $ids, callable $titles_by_guid, callable $taxa_by_title, bool $apply): array {
    $on_site = $titles_by_guid(array_values($ids));
    $unknown = array_filter($ids, static fn(string $id) => !isset($on_site[$id]));
    if (!$unknown) {
      return [];
    }
    $in_sheet = array_flip($ids);
    $names = [];
    $name_authors = [];
    foreach ($ids as $number => $id) {
      $names[$raw[$number]['name']] = ($names[$raw[$number]['name']] ?? 0) + 1;
      $key = $raw[$number]['name'] . "\0" . ($raw[$number]['authors'] ?? '');
      $name_authors[$key] = ($name_authors[$key] ?? 0) + 1;
    }
    $candidates = $taxa_by_title(array_values(array_unique(array_map(static fn(int $n) => $raw[$n]['name'], array_keys($unknown)))));
    $match = [];
    $used = [];
    foreach ($unknown as $number => $id) {
      $name = $raw[$number]['name'];
      $authors = $raw[$number]['authors'] ?? '';
      // Taxa of that name whose GUID is not some row's own.
      $free = array_values(array_filter($candidates[$name] ?? [], static fn(array $c) => $c['guid'] !== NULL && !isset($in_sheet[$c['guid']]) && !isset($used[$c['guid']])));
      if (!$free) {
        continue;
      }
      $by = NULL;
      $taxon = NULL;
      if ($names[$name] === 1 && count($free) === 1) {
        $by = 'name';
        $taxon = $free[0];
      }
      elseif ($name_authors[$name . "\0" . $authors] === 1) {
        $same = array_values(array_filter($free, static fn(array $c) => ($c['authors'] ?? '') === $authors));
        if (count($same) === 1) {
          $by = 'name and authors';
          $taxon = $same[0];
        }
      }
      if ($taxon === NULL) {
        $this->stats['name_ambiguous'][] = [
          'row' => $number,
          'name' => $name,
          'id' => $id,
          'candidates' => count($free),
        ];
        continue;
      }
      $this->stats['name_matches'][] = [
        'row' => $number,
        'name' => $name,
        'id' => $id,
        'site_guid' => $taxon['guid'],
        'by' => $by,
      ];
      if ($apply) {
        $used[$taxon['guid']] = TRUE;
        $match[$number] = $taxon['guid'];
        $this->note($number, $id, sprintf('row %d (%s): GUID %s is not on the site; updates the existing taxon of the same %s (GUID %s), which takes GUID %s', $number, $name, $id, $by, $taxon['guid'], $id));
      }
    }
    return $match;
  }

  /**
   * The GUID per title of the titles exactly one taxon carries.
   *
   * @param array<string, array<int, array{id: int, guid: string|null, authors: string|null}>> $candidates
   *   Taxa per title, as the site lookup returns them.
   *
   * @return array<string, string>
   *   Title => GUID, for the unique ones with a GUID.
   */
  private function uniqueGuids(array $candidates): array {
    $unique = [];
    foreach ($candidates as $title => $taxa) {
      if (count($taxa) === 1 && $taxa[0]['guid'] !== NULL) {
        $unique[$title] = $taxa[0]['guid'];
      }
    }
    return $unique;
  }

  /**
   * Shapes a raw row into a record with every property of PROPERTIES.
   *
   * @param array<string, string|null> $record
   *   The raw cells by column property.
   * @param string $id
   *   The row id.
   * @param string $match_guid
   *   The GUID the existing node is looked up by (the id, or the GUID of
   *   the taxon of the same name).
   * @param int $number
   *   The sheet row.
   * @param string $sheet
   *   The sheet file name.
   *
   * @return array<string, mixed>
   *   The record.
   */
  private function shape(array $record, string $id, string $match_guid, int $number, string $sheet): array {
    $shaped = array_fill_keys(self::PROPERTIES, NULL);
    foreach (self::PROPERTIES as $property) {
      if (array_key_exists($property, $record)) {
        $shaped[$property] = $record[$property];
      }
    }
    $shaped['guid'] = $id;
    $shaped['match_guid'] = $match_guid;
    $shaped['row'] = $number;
    $shaped['sheet'] = $sheet;
    $shaped['published'] = 1;
    $shaped['unit_names'] = $this->unitNames($record);
    $shaped['vernacular'] = $record['vernacular_name'] === NULL ? [] : [
      [
        'name' => $record['vernacular_name'],
        'language' => $record['vernacular_language'],
        'area' => $record['vernacular_area'],
        'remarks' => $record['vernacular_remarks'],
      ],
    ];
    foreach (self::TEXT_PROPERTIES as $property) {
      $shaped[$property] = $this->text($record[$property]);
    }
    return $shaped;
  }

  /**
   * A plain-text cell: tags removed, entities decoded, NULL when empty.
   *
   * Counted in stats['stripped_cells'] when the cell changed.
   */
  private function plain(?string $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $plain = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($plain !== $value) {
      $this->stats['stripped_cells']++;
    }
    $plain = SheetReader::cell($plain);
    return $plain;
  }

  /**
   * A formatted text value: {value, format}, or NULL for an empty cell.
   *
   * A cell with markup (the exporter keeps the <em> of italicised names)
   * takes basic_html, a plain cell plain_text, as the D7 track maps
   * filtered_html and plain fields.
   *
   * @return array{value: string, format: string}|null
   *   The item.
   */
  private function text(?string $value): ?array {
    if ($value === NULL) {
      return NULL;
    }
    $html = (bool) preg_match('/<[a-z][^>]*>/i', $value);
    if ($html) {
      $this->stats['text_cells']++;
    }
    return ['value' => $value, 'format' => $html ? 'basic_html' : 'plain_text'];
  }

  /**
   * The unit name/indicator columns as the items of field_unit_names.
   *
   * The rule of ScratchpadsD7Base::unitNames(): one item per position
   * whose name is non-empty, in position order, a blank indicator NULL.
   *
   * @return array<int, array{indicator: string|null, name: string}>
   *   The items.
   */
  private function unitNames(array $record): array {
    $items = [];
    for ($i = 1; $i <= self::UNIT_POSITIONS; $i++) {
      $name = $record["unit_name$i"] ?? NULL;
      if ($name === NULL) {
        continue;
      }
      $items[] = ['indicator' => $record["unit_indicator$i"] ?? NULL, 'name' => $name];
    }
    return $items;
  }

  /**
   * Records a note.
   */
  private function note(int $row, ?string $id, string $message): void {
    $this->notes[] = ['row' => $row, 'id' => $id, 'message' => $message];
  }

}
