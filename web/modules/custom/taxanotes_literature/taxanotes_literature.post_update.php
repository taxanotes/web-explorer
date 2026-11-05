<?php

/**
 * @file
 * Post-update functions of taxanotes_literature.
 *
 * Platform changes to configuration the recipes create only once (the
 * views: "a starting point an instance may tailor, left alone once it
 * exists") reach an existing instance through these functions, which
 * `drush updatedb` runs (the instance scripts run it before the recipes).
 */

declare(strict_types=1);

use Drupal\Core\Serialization\Yaml;

/**
 * The Literature table as Year, Title, Authors, Type, with a Reset button.
 *
 * Replaces the taxanotes_literature view with the one shipped in
 * recipes/taxanotes_literature when the instance still has the 0.4 layout
 * (the Title column first): an instance that has changed the table's
 * columns keeps its view.
 */
function taxanotes_literature_post_update_literature_table_columns(): string {
  $config = \Drupal::configFactory()->getEditable('views.view.taxanotes_literature');
  if ($config->isNew()) {
    return 'The literature view does not exist: nothing to update (the recipe creates it).';
  }
  $columns = array_keys((array) $config->get('display.default.display_options.style.options.columns'));
  if (($columns[0] ?? NULL) !== 'title') {
    return 'The literature table does not start with the Title column (changed by the instance, or already updated): left alone.';
  }
  return _taxanotes_literature_reset_view($config) ?? 'The literature view now lists Year, Title, Authors, Type (year descending) with Apply and Reset.';
}

/**
 * The Literature tab of a taxon page as "Authors (Year) Title".
 *
 * Replaces the taxanotes_literature view with the shipped one when the
 * taxon block still lists the title first (the 0.4 layout, where the
 * authors and the year followed the title on a line of their own); an
 * instance that has changed the block keeps its view.
 */
function taxanotes_literature_post_update_taxon_block_author_year_title(): string {
  $config = \Drupal::configFactory()->getEditable('views.view.taxanotes_literature');
  if ($config->isNew()) {
    return 'The literature view does not exist: nothing to update (the recipe creates it).';
  }
  $fields = array_keys((array) $config->get('display.taxon.display_options.fields'));
  if (($fields[0] ?? NULL) !== 'title') {
    return 'The Literature tab does not start with the title (changed by the instance, or already updated): left alone.';
  }
  return _taxanotes_literature_reset_view($config) ?? 'The Literature tab of a taxon page now reads "Authors (Year) Title".';
}

/**
 * Replaces the installed literature view with the shipped one (uuid kept).
 *
 * @return string|null
 *   A message when the shipped file is missing, NULL when the view was
 *   replaced.
 */
function _taxanotes_literature_reset_view(\Drupal\Core\Config\Config $config): ?string {
  $file = \Drupal::root() . '/../recipes/taxanotes_literature/config/views.view.taxanotes_literature.yml';
  if (!is_file($file)) {
    return 'recipes/taxanotes_literature/config/views.view.taxanotes_literature.yml is missing: left alone.';
  }
  $shipped = Yaml::decode((string) file_get_contents($file));
  $uuid = $config->get('uuid');
  $config->setData($shipped);
  if ($uuid) {
    $config->set('uuid', $uuid);
  }
  $config->save();
  return NULL;
}
