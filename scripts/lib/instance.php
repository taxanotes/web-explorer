<?php

/**
 * @file
 * Reads an instance definition for scripts/new-instance.sh.
 *
 * An instance is described by instances/<slug>/instance.yml (committed, no
 * secrets) and instances/<slug>/.env (gitignored secrets). This helper is the
 * only YAML/dotenv parser the shell script needs; run it with the platform's
 * PHP (the vendor/ Symfony Yaml component is used).
 *
 * Usage:
 *   php scripts/lib/instance.php get <instance.yml> <dot.path> [env]
 *     Prints the value at the dot path of the *effective* definition (the
 *     defaults below applied): a scalar as-is, a list one item per line,
 *     nothing when the key is absent. A value that is a map keyed by
 *     environment (see below) prints the entry of the given environment
 *     (default ddev).
 *   php scripts/lib/instance.php settings-local <instance.yml> <.env> [env] \
 *       [migrate-file]
 *     Prints the settings.local.php of the instance site for the given
 *     environment (default ddev): its trusted hosts are that environment's
 *     hostnames, its database names that environment's. The Scratchpads
 *     `migrate` connection is inline unless a migrate-file name is given: the
 *     output then includes that sibling file (written from settings-migrate),
 *     which keeps the main file to one database block for tools that parse
 *     it (the hosting toolkit's backup_db/restore_db read the first
 *     'database'/'username'/'password' they find).
 *   php scripts/lib/instance.php settings-migrate <instance.yml> <.env> [env]
 *     Prints the file holding the `migrate` connection alone, or nothing
 *     when the instance has no Scratchpads source or MIGRATE_DB_USER is empty.
 *
 * Defaults (applied in effective_instance(), the one place that knows them):
 *   drupal.sites_subdir = slug, drupal.database = slug,
 *   drupal.profile = standard, site.colour = 047DB8 (six hex digits, the
 *   accent colour of the classic view; a leading # is dropped),
 *   site.front_first_column = page (the node type of the classic front
 *   page's first column, page or blog).
 *
 * Hostnames have an environment axis: `hostnames: {ddev: [...],
 * production: [...]}`. A plain list is accepted as the ddev list. Only the
 * environment a script runs in reaches the site (trusted_host_patterns); the
 * ddev list also drives the local multisite map (web/sites/sites.php) and
 * .ddev/config.instances.yaml, which are ddev-only artifacts. The database
 * names `drupal.database` and `source.database` take the same shape, a
 * scalar (the same name everywhere) or `{ddev: ..., production: ...}`, since
 * the hosting toolkit names databases d<slug>_1 and the Scratchpads sources
 * s_<slug>; so does `source.files`, the D7 public files directory the
 * literature attachments and the images are copied from (.env
 * SOURCE_FILES_DIR overrides it). See instances/README.md.
 */

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Prints a message to STDERR and exits.
 */
function fail(string $message): never {
  fwrite(STDERR, "instance.php: $message\n");
  exit(1);
}

/**
 * Parses instance.yml.
 */
function read_instance(string $path): array {
  if (!is_file($path)) {
    fail("$path does not exist");
  }
  $data = Yaml::parseFile($path);
  if (!is_array($data) || empty($data['slug'])) {
    fail("$path is not an instance definition (no slug)");
  }
  return effective_instance($data);
}

/**
 * Applies the defaults and normalises the hostnames to an environment map.
 */
function effective_instance(array $data): array {
  $slug = (string) $data['slug'];
  $data['drupal'] = (array) ($data['drupal'] ?? []);
  $data['drupal'] += [
    'sites_subdir' => $slug,
    'database' => $slug,
    'profile' => 'standard',
  ];
  $data['site'] = (array) ($data['site'] ?? []);
  // The first front page column of the classic view: the node type it
  // lists (the Scratchpads front_page_block_1_node_type: page or blog).
  $data['site'] += ['front_first_column' => 'page'];
  $data['site']['front_first_column'] = trim((string) $data['site']['front_first_column']);
  if (!preg_match('/^[a-z0-9_]+$/', $data['site']['front_first_column'])) {
    fail("site.front_first_column must be a node type machine name (got '{$data['site']['front_first_column']}')");
  }
  // The accent colour of the classic view (the Scratchpads default).
  $data['site'] += ['colour' => '047DB8'];
  $data['site']['colour'] = strtoupper(ltrim(trim((string) $data['site']['colour']), '#'));
  if (!preg_match('/^[0-9A-F]{6}$/', $data['site']['colour'])) {
    fail("site.colour must be six hex digits (got '{$data['site']['colour']}')");
  }
  $hosts = $data['hostnames'] ?? [];
  if (!is_array($hosts)) {
    $hosts = [$hosts];
  }
  if (array_is_list($hosts)) {
    $hosts = ['ddev' => $hosts];
  }
  $data['hostnames'] = array_map(fn($list) => array_values(array_filter((array) $list, 'is_string')), $hosts);
  return $data;
}

/**
 * Resolves a value that may be a map keyed by environment.
 *
 * A scalar (or a list) is the same in every environment and is returned as
 * is; an associative array returns its entry for the environment, NULL when
 * it has none.
 */
function env_value(mixed $value, string $environment): mixed {
  if (is_array($value) && !array_is_list($value)) {
    return $value[$environment] ?? NULL;
  }
  return $value;
}

/**
 * Parses a KEY=VALUE .env file (comments and blank lines ignored).
 */
function read_env(string $path): array {
  $env = [];
  if (!is_file($path)) {
    return $env;
  }
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
      continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $value = trim($value);
    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
      $value = substr($value, 1, -1);
    }
    $env[trim($key)] = $value;
  }
  return $env;
}

/**
 * Returns the value at a dot path, or NULL.
 */
function dot_get(array $data, string $path): mixed {
  foreach (explode('.', $path) as $key) {
    if (!is_array($data) || !array_key_exists($key, $data)) {
      return NULL;
    }
    $data = $data[$key];
  }
  return $data;
}

/**
 * The Scratchpads source database name of an environment, NULL for none.
 *
 * None: the instance has no source.database, or .env leaves MIGRATE_DB_USER
 * empty (the script then writes no `migrate` connection and runs no
 * migration). A source.database map without the environment is an error.
 */
function migrate_database(array $instance, array $env, string $environment, string $yml): ?string {
  $source = $instance['source'] ?? [];
  if (empty($source['database']) || empty($env['MIGRATE_DB_USER'])) {
    return NULL;
  }
  $name = env_value($source['database'], $environment);
  if (!is_string($name) || $name === '') {
    fail("source.database has no name for environment $environment in $yml");
  }
  return $name;
}

/**
 * The database array for $databases from a prefix in .env plus a name.
 */
function database_settings(array $env, string $prefix, string $name): array {
  return [
    'driver' => 'mysql',
    'database' => $name,
    'username' => $env[$prefix . 'USER'] ?? '',
    'password' => $env[$prefix . 'PASSWORD'] ?? '',
    'host' => $env[$prefix . 'HOST'] ?? 'localhost',
    'port' => $env[$prefix . 'PORT'] ?? '3306',
    'prefix' => '',
    'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
    'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_general_ci',
  ];
}

$command = $argv[1] ?? '';
switch ($command) {
  case 'get':
    $instance = read_instance($argv[2] ?? '');
    $value = env_value(dot_get($instance, $argv[3] ?? ''), $argv[4] ?? 'ddev');
    if (is_array($value)) {
      foreach ($value as $item) {
        echo is_scalar($item) ? $item : json_encode($item), "\n";
      }
    }
    elseif ($value !== NULL) {
      echo is_bool($value) ? ($value ? '1' : '0') : $value, "\n";
    }
    break;

  case 'settings-local':
    $instance = read_instance($argv[2] ?? '');
    $env = read_env($argv[3] ?? '');
    $environment = $argv[4] ?? 'ddev';
    $slug = $instance['slug'];
    $hosts = $instance['hostnames'][$environment] ?? [];
    if (!$hosts) {
      fail("hostnames.$environment is empty in {$argv[2]}");
    }
    if (empty($env['HASH_SALT'])) {
      fail("HASH_SALT is empty in {$argv[3]}");
    }
    $migrate_file = $argv[5] ?? '';
    $drupal_db = env_value($instance['drupal']['database'], $environment);
    if (!is_string($drupal_db) || $drupal_db === '') {
      fail("drupal.database has no name for environment $environment in {$argv[2]}");
    }
    $lines = [
      '<?php',
      '',
      '// Generated by scripts/new-instance.sh from instances/' . $slug . '/instance.yml',
      '// and instances/' . $slug . '/.env (environment: ' . $environment . '). Gitignored; edit',
      '// those two files and re-run the script instead of editing this one.',
      '',
      '$settings[\'hash_salt\'] = ' . var_export($env['HASH_SALT'], TRUE) . ';',
      '$settings[\'trusted_host_patterns\'] = ' . var_export(array_map(fn(string $h) => '^' . preg_quote($h) . '$', $hosts), TRUE) . ';',
      '',
      '$databases[\'default\'][\'default\'] = ' . var_export(database_settings($env, 'DB_', $drupal_db), TRUE) . ';',
    ];
    $source = $instance['source'] ?? [];
    $source_db = migrate_database($instance, $env, $environment, $argv[2]);
    if ($source_db !== NULL && $migrate_file !== '') {
      $lines[] = '';
      $lines[] = '// Scratchpads (Drupal 7) source database, read by taxanotes_import: in a';
      $lines[] = '// file of its own (instance.php settings-migrate) next to this one.';
      $lines[] = 'if (file_exists(__DIR__ . ' . var_export('/' . $migrate_file, TRUE) . ')) {';
      $lines[] = '  include __DIR__ . ' . var_export('/' . $migrate_file, TRUE) . ';';
      $lines[] = '}';
    }
    elseif ($source_db !== NULL) {
      $lines[] = '';
      $lines[] = '// Scratchpads (Drupal 7) source database, read by taxanotes_import.';
      $lines[] = '$databases[\'migrate\'][\'default\'] = ' . var_export(database_settings($env, 'MIGRATE_DB_', $source_db), TRUE) . ';';
    }
    $import = [];
    if (!empty($source['vocabulary'])) {
      $import['source_vocabulary'] = $source['vocabulary'];
    }
    if (array_key_exists('unpublished_roots', $source)) {
      $import['unpublished_roots'] = array_values((array) $source['unpublished_roots']);
    }
    // The D7 public files directory as this environment's PHP reaches it:
    // .env SOURCE_FILES_DIR (the server's copy of the referenced subset),
    // else source.files of instance.yml (per environment). Absent, the
    // scripts skip the file and media migrations and the references and
    // species pages import without attachments and images.
    $files_dir = $env['SOURCE_FILES_DIR'] ?? '';
    if (!is_string($files_dir) || $files_dir === '') {
      $files_dir = env_value($source['files'] ?? NULL, $environment);
    }
    if (is_string($files_dir) && $files_dir !== '') {
      $import['source_files_dir'] = $files_dir;
    }
    if ($import) {
      $lines[] = '';
      $lines[] = '// Instance values the import module reads (see taxanotes_import/README.md).';
      $lines[] = '$settings[\'taxanotes_import\'] = ' . var_export($import, TRUE) . ';';
    }
    echo implode("\n", $lines), "\n";
    break;

  case 'settings-migrate':
    $instance = read_instance($argv[2] ?? '');
    $env = read_env($argv[3] ?? '');
    $environment = $argv[4] ?? 'ddev';
    $source_db = migrate_database($instance, $env, $environment, $argv[2]);
    if ($source_db === NULL) {
      break;
    }
    $lines = [
      '<?php',
      '',
      '// Generated by scripts/new-instance.sh from instances/' . $instance['slug'] . '/instance.yml',
      '// and its .env (environment: ' . $environment . '): the Scratchpads (Drupal 7)',
      '// source database, read by taxanotes_import. Included by settings.local.php.',
      '',
      '$databases[\'migrate\'][\'default\'] = ' . var_export(database_settings($env, 'MIGRATE_DB_', $source_db), TRUE) . ';',
    ];
    echo implode("\n", $lines), "\n";
    break;

  default:
    fail("usage: instance.php get <instance.yml> <dot.path> [environment] | settings-local <instance.yml> <.env> [environment] [migrate-file] | settings-migrate <instance.yml> <.env> [environment]");
}
