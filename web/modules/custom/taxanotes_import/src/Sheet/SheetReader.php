<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads a Scratchpads taxonomy importer sheet into a header and its rows.
 *
 * The Scratchpads "taxonomy importer" template is an `.xls` workbook with a
 * data sheet (`Template`) and a lookup sheet (`PermittedValues`); owners
 * send it back filled in, sometimes saved as `.xlsx` or exported as CSV. This
 * reads any of the three the same way: the first non-empty row is the
 * header, every later row is a data row keyed by its 1-based sheet row
 * number (what the owner sees in the spreadsheet, and what the messages
 * quote), every cell trimmed (no-break spaces included) and an empty cell
 * NULL. Spreadsheet files go through PhpSpreadsheet with formulas left
 * uncalculated and numbers in their displayed form (a page number 39 stays
 * "39"); CSV through PHP's own parser (UTF-8, a BOM dropped, the delimiter
 * sniffed from the header line: comma, semicolon or tab, so a European
 * export reads too), so the two readers agree on the same data.
 *
 * Reads are cached per process on path, size and mtime: the three sheet
 * migrations and the Drush report all open the same file in one run.
 */
final class SheetReader {

  /**
   * The parsed files of this process: cache key => [header, rows].
   *
   * @var array<string, array{header: string[], rows: array<int, array<int, string|null>>}>
   */
  private static array $cache = [];

  /**
   * The spreadsheet extensions PhpSpreadsheet reads; anything else is CSV.
   */
  private const SPREADSHEET_EXTENSIONS = ['xls', 'xlsx', 'xlsm', 'ods'];

  /**
   * Reads a sheet file.
   *
   * @param string $path
   *   Absolute path of an .xls, .xlsx, .ods or .csv file.
   * @param string|null $sheet
   *   The worksheet to read (default `Template`); the first sheet when the
   *   workbook has no sheet of that name. Ignored for CSV.
   *
   * @return array{header: string[], rows: array<int, array<int, string|null>>}
   *   The header cells (trimmed, as written) and the data rows keyed by
   *   their 1-based sheet row number, each a list of cells in header order
   *   (NULL for an empty cell; short rows are padded, long rows kept).
   *
   * @throws \RuntimeException
   *   When the file cannot be read or has no header row.
   */
  public static function read(string $path, ?string $sheet = NULL): array {
    if (!is_file($path) || !is_readable($path)) {
      throw new \RuntimeException(sprintf('Sheet file %s does not exist or is not readable.', $path));
    }
    // The stat cache would hide a file rewritten in this process (the tests).
    clearstatcache(TRUE, $path);
    $key = implode('|', [realpath($path), filesize($path), filemtime($path), $sheet ?? '']);
    if (!isset(self::$cache[$key])) {
      $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
      $table = in_array($extension, self::SPREADSHEET_EXTENSIONS, TRUE)
        ? self::readSpreadsheet($path, $sheet ?? 'Template')
        : self::readCsv($path);
      self::$cache[$key] = self::split($table, $path);
    }
    return self::$cache[$key];
  }

  /**
   * Whether a path has a spreadsheet extension (else it is read as CSV).
   */
  public static function isSpreadsheet(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::SPREADSHEET_EXTENSIONS, TRUE);
  }

  /**
   * Reads a workbook sheet as rows of cell strings.
   *
   * @return array<int, array<int, string|null>>
   *   Rows keyed by 1-based sheet row.
   */
  private static function readSpreadsheet(string $path, string $sheet): array {
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(TRUE);
    $names = $reader->listWorksheetNames($path);
    if (in_array($sheet, $names, TRUE)) {
      $reader->setLoadSheetsOnly([$sheet]);
    }
    $spreadsheet = $reader->load($path);
    $worksheet = in_array($sheet, $names, TRUE) ? $spreadsheet->getSheetByName($sheet) : $spreadsheet->getSheet(0);
    if ($worksheet === NULL) {
      throw new \RuntimeException(sprintf('Sheet file %s has no worksheet to read.', $path));
    }
    $rows = [];
    // Formulas are not calculated (the template holds none; a stray one
    // stays visible as text), numbers come formatted as the cell shows them.
    foreach ($worksheet->toArray(NULL, FALSE, TRUE, TRUE) as $number => $cells) {
      $rows[(int) $number] = array_map(self::cell(...), array_values($cells));
    }
    $spreadsheet->disconnectWorksheets();
    return $rows;
  }

  /**
   * Reads a CSV file as rows of cell strings.
   *
   * @return array<int, array<int, string|null>>
   *   Rows keyed by 1-based record number (a record may span several lines
   *   when a cell holds a line break, as the sheet's row does).
   */
  private static function readCsv(string $path): array {
    $handle = fopen($path, 'rb');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Sheet file %s cannot be opened.', $path));
    }
    $delimiter = self::delimiter((string) fgets($handle));
    rewind($handle);
    $rows = [];
    $number = 0;
    while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== FALSE) {
      $number++;
      if ($number === 1 && isset($cells[0]) && str_starts_with((string) $cells[0], "\u{FEFF}")) {
        $cells[0] = substr((string) $cells[0], 3);
      }
      $rows[$number] = array_map(self::cell(...), $cells);
    }
    fclose($handle);
    return $rows;
  }

  /**
   * The delimiter a CSV header line uses: the commonest of , ; and tab.
   *
   * Counted outside quoted cells (a quoted "Latreille, 1802" is not a
   * column). Comma when the line has none of the three.
   */
  public static function delimiter(string $line): string {
    $outside = preg_replace('/"[^"]*"/', '', $line) ?? $line;
    $counts = [
      ',' => substr_count($outside, ','),
      ';' => substr_count($outside, ';'),
      "\t" => substr_count($outside, "\t"),
    ];
    arsort($counts);
    return (int) reset($counts) > 0 ? (string) array_key_first($counts) : ',';
  }

  /**
   * Normalises one cell: trimmed (no-break spaces too), NULL when empty.
   */
  public static function cell(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }
    $value = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $value) ?? trim((string) $value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Splits the raw rows into the header and the data rows.
   *
   * The header is the first row with a non-empty cell; rows above it and
   * rows that are entirely empty (the trailing blank lines of an export)
   * are dropped. Data rows keep their sheet row numbers.
   *
   * @param array<int, array<int, string|null>> $rows
   *   Raw rows keyed by sheet row.
   * @param string $path
   *   The file, for the exception message.
   *
   * @return array{header: string[], rows: array<int, array<int, string|null>>}
   *   The header and data rows.
   */
  private static function split(array $rows, string $path): array {
    $header = NULL;
    $data = [];
    foreach ($rows as $number => $cells) {
      if (array_filter($cells, static fn($c) => $c !== NULL) === []) {
        continue;
      }
      if ($header === NULL) {
        $header = array_map(static fn($c) => $c ?? '', $cells);
        continue;
      }
      $data[$number] = array_pad($cells, count($header), NULL);
    }
    if ($header === NULL) {
      throw new \RuntimeException(sprintf('Sheet file %s has no header row.', $path));
    }
    return ['header' => $header, 'rows' => $data];
  }

}
