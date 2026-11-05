<?php

/**
 * @file
 * Lists the Scratchpads (Drupal 7) files an instance's import would copy.
 *
 * Run with drush on the instance site (the `migrate` connection is read, the
 * files need not be present):
 *
 *   drush --uri=https://<hostname> php:script scripts/list-source-files.php
 *
 * Prints one path per line, relative to the D7 public files directory: the
 * files taxanotes_d7_file selects (the literature attachments and the images
 * placed on species pages or tagged with a taxon of the instance
 * vocabulary; the rule lives in the source plugin, which this script runs,
 * so the two never disagree). Images the image toolkit cannot render are
 * left out, remote files too. The list feeds the deploy step's rsync of the
 * referenced subset to the server's SOURCE_FILES_DIR, e.g.
 *
 *   drush ... php:script scripts/list-source-files.php > files.txt
 *   rsync -av --files-from=files.txt <d7 files>/ <server>:<SOURCE_FILES_DIR>/
 *
 * With the argument "sizes" each line is "<bytes>\t<path>" and a total is
 * printed to STDERR.
 */

$with_sizes = in_array('sizes', $extra ?? [], TRUE);
$migration = \Drupal::service('plugin.manager.migration')->createInstance('taxanotes_d7_file');
if (!$migration) {
  fwrite(STDERR, "The migration taxanotes_d7_file is not available (is taxanotes_import installed?).\n");
  return;
}
// The source plugin's own query (its selection rule), run directly: iterating
// the plugin would apply the migrate map (rows already imported are not
// returned) and clear each row's messages.
$source = $migration->getSourcePlugin();
$source->checkRequirements();
$supported = array_map('strtolower', \Drupal::service('image.toolkit.manager')->getDefaultToolkit()->getSupportedExtensions());
$total = 0;
$count = 0;
foreach ($source->query()->execute() as $record) {
  $record = (array) $record;
  $uri = (string) $record['uri'];
  if (!str_starts_with($uri, 'public://')) {
    continue;
  }
  $is_image = ($record['type'] ?? '') === 'image' || str_starts_with((string) $record['filemime'], 'image/');
  if ($is_image && !in_array(strtolower(pathinfo($uri, PATHINFO_EXTENSION)), $supported, TRUE)) {
    continue;
  }
  $path = substr($uri, strlen('public://'));
  $count++;
  if ($with_sizes) {
    $size = (int) $record['filesize'];
    $total += $size;
    echo $size, "\t", $path, "\n";
  }
  else {
    echo $path, "\n";
  }
}
if ($with_sizes) {
  fwrite(STDERR, sprintf("%d files, %d bytes (%.1f MB)\n", $count, $total, $total / 1048576));
}
