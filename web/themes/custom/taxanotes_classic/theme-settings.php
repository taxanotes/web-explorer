<?php

/**
 * @file
 * Theme settings form of Taxanotes classic: the accent colour.
 */

declare(strict_types=1);

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 *
 * The Scratchpads offered fourteen colours and a custom one
 * (scratchpads_colour module); here the colour is one hex value, with that
 * palette listed for reference.
 */
function taxanotes_classic_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $palette = [
    '353535', '1F3661', '154D8C', '047DB8', '2FA7C3', '134823', '5F8B3D',
    '67A53D', 'A5C033', '7C1227', 'B72932', 'E47C25', 'E9B61E', '57508E',
  ];
  $form['taxanotes_classic'] = [
    '#type' => 'details',
    '#title' => t('Colour'),
    '#open' => TRUE,
  ];
  $form['taxanotes_classic']['colour'] = [
    '#type' => 'textfield',
    '#title' => t('Accent colour'),
    '#description' => t('Six hex digits: the header, the links, the headings and the block title bars. The Scratchpads palette: @palette. An instance seeds this from instances/&lt;slug&gt;/instance.yml (site.colour) on every run of the instance script.', ['@palette' => implode(', ', $palette)]),
    '#default_value' => theme_get_setting('colour', 'taxanotes_classic') ?? '047DB8',
    '#field_prefix' => '#',
    '#size' => 8,
    '#maxlength' => 7,
    '#required' => TRUE,
    '#element_validate' => ['taxanotes_classic_validate_colour'],
  ];
}

/**
 * Validates the colour: six hex digits, with or without a leading #.
 */
function taxanotes_classic_validate_colour(array $element, FormStateInterface $form_state): void {
  $value = ltrim(trim((string) $element['#value']), '#');
  if (!preg_match('/^[0-9A-Fa-f]{6}$/', $value)) {
    $form_state->setError($element, t('The colour must be six hexadecimal digits, such as 047DB8.'));
    return;
  }
  $form_state->setValueForElement($element, strtoupper($value));
}
