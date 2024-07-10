<?php

namespace Drupal\node_tree\Plugin\Block;

use Drupal\Core\Block\BlockBase;


/**
 * Provides hierarchical node_tree taxon tree block.
 *
 * @Block(
 *  id = "node_treeTaxonTreeBlock",
 *  admin_label = @Translation("node_tree hierarchical taxon tree"),
 * )
 */
class node_treeTaxonTreeBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [] + parent::defaultConfiguration();
  }


  /**
   * {@inheritdoc}
   */
  public function build() {
    // https://www.drupal.org/docs/8/creating-custom-modules/creating-custom-blocks/create-a-custom-block#s-note-using-twig-templates-with-custom-blocks

    $node_treeoutput = '';

    $renderable = [
      '#theme' => 'node-tree-hierarchy-tree-block',
      '#attached' => array(
        'library' => array(
          'node_tree/node_treetree',
        ),
      ),
    ];



    /*
    $renderable = [
      '#theme' => 'node-tree-hierarchy-tree-block',
      '#taxanotesoutput' => $taxanotesoutput,
    ];
    */

    // where corresponding theme file would be:
// web/modules/custom/taxanotes/templates/node-tree-hierarchy-tree-block.html.twig

    /*

        // https://stackoverflow.com/a/48787759/227926
        //
        // also: https://www.drupal.org/forum/support/module-development-and-code-questions/2016-08-08/adding-js-to-the-block-through-module
        // and: https://www.drupal.org/docs/theming-drupal/adding-stylesheets-css-and-javascript-js-to-a-drupal-theme

        // could be useful: https://drupal.stackexchange.com/questions/197007/how-to-properly-add-inline-javascript
        // https://drupal.stackexchange.com/questions/95635/adding-js-to-a-drupal-8-theme-replacement-for-drupal-add-js
      
    return [
  '#theme' => 'your_module_theme_id',
  '#someVariable' => $some_variable,
  '#attached' => array(
    'library' => array(
      'your_module/library_name',
    ),
  ),
];

    */

    return $renderable;
  }



  // taxon equivalent
}