<?php

declare(strict_types=1);

namespace Drupal\taxanotes_literature\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Views hooks of the literature module.
 */
final class ViewsHooks {

  /**
   * Implements hook_views_data_alter().
   *
   * Views gives the target id column of an entity reference field a numeric
   * filter. The Literature page filters references by taxon and by author
   * through the two multi-value fields; a relationship to the taxon or
   * contributor would do, but Views then selects the related entity's id in
   * every row and a reference with three taxa is listed three times (DISTINCT
   * cannot collapse rows that differ in that column). The core
   * entity_reference filter works on the field table alone, with an
   * autocomplete on the referenced entity's label, and leaves one row per
   * reference.
   */
  #[Hook('views_data_alter')]
  public function viewsDataAlter(array &$data): void {
    $columns = [
      'bibcite_reference__field_taxonomic_name' => 'field_taxonomic_name_target_id',
      'bibcite_reference__author' => 'author_target_id',
    ];
    foreach ($columns as $table => $column) {
      if (isset($data[$table][$column]['filter'])) {
        $data[$table][$column]['filter']['id'] = 'entity_reference';
      }
    }
  }

}
