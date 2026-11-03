<?php

namespace Drupal\node_tree\Drush\Commands;

use Drush\Commands\DrushCommands; 
use Drupal\Component\Serialization\Json;
use Drupal\views\Views;
use Drupal\views\ViewExecutable;
use Drupal\node\Entity\Node;

// THIS:
// https://drupal.stackexchange.com/questions/310697/programmatically-get-child-nodes-referencing-parent-by-entity-reference-uuid-us

/* what do we need in this file?

- link child to parent
  - what do we need for that?
     - child node
     - parent identifier value in field in that child node
     - ability to look up parent node based on that value
        - Drupal view?
          - View: node_tree_all
            - Display: node_tree_all
*/

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 */
class NodeTreeCommands extends DrushCommands {
  /**
   * Gets node_tree data using input CSV for calling parameters (from UKSI)
   *
   * @command node_tree:node_treeupdate
   * @aliases node_treeupd
   * @usage node_tree:node_treeupdate file-or-http-address
   *   
   */

  // https://mglaman.dev/blog/writing-drush-commands-php-attributes#:%7E:text=For%20Drush%2C%20this%20allows%20specifying,more%20without%20writing%20PHP%20code.&text=Our%20command%20and%20alias%20annotations,and%20wrapped%20in%20brackets%20%5B%5D%20.

  /**
   * Gets node_tree data using input CSV for calling parameters (from UKSI)
   *
   * @command node_tree:node_treelinkparent
   * @aliases node_treelnk
   * @usage node_tree:node_treelinkparent
   *   
   */
  public function linkparent() {
    $this->_link_parent_node_from_child_nodes();
  }


  private function _link_parent_node_from_child_nodes() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_all_view_display');

    // if I'm going through all of them programmatically, 
    //I don't need to deal with pages 
    //$view->setItemsPerPage(1000);
    $view->setItemsPerPage(0);
    $view->setOffset(0);

    $view->execute();
    //$view->render("node_tree_all");

    foreach ($view->result as $rid => $row) {

      // $row is https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ResultRow.php/class/ResultRow/9.3.x
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ResultRow.php/class/ResultRow/11.x

      $child_node_id = $row->nid;

      // https://drupal.stackexchange.com/questions/274462/how-to-get-a-rendered-output-field-from-view-object-programatically
      //$field__id_parent_value = $row->field__id_parent;
      $row_entity = $row->_entity;
      //$field_parent_guid_value = $$row_entity->get('field_parent_guid')->getValue()[0]['value'];
      $field_obj = $row_entity->get('field_parent_guid');
      if ( $field_obj ) {
      //$field_parent_guid_value = $row_entity->get('field_parent_guid')->getValue()[0]['value'];
        //$field_parent_guid_value = $field_obj->getValue()[0]['value'];

        //$field_parent_guid_value = $field_obj->getValue()[0]['value'];
        $value_list = $field_obj->getValue(); // should be only one for childrem and empty for top node
        if (count($value_list) > 0 ) {
          $field_parent_guid_value = $value_list[0]['value'];
          $this->_set_entity_reference_to_parent_node_from_child_node($child_node_id, $field_parent_guid_value);
        }

              // https://drupal.stackexchange.com/q/308755/1082
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21Plugin%21views%21field%21FieldPluginBase.php/class/FieldPluginBase/8.2.x
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21Plugin%21views%21field%21FieldPluginBase.php/8.2.x
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ViewExecutable.php/property/ViewExecutable%3A%3Afield/9.3.x
      }
      
    }
  }

  // GUID
  private function _set_entity_reference_to_parent_node_from_child_node($child_node_id, $parent_value) {
    $parent_node_id = $this->_get_parent_node_id($parent_value);

    if ( $parent_node_id > 0 ) {
      $node = Node::load($child_node_id);
      $node->set('field_parent_taxon_reference', ['target_id' => $parent_node_id]);
      $node->save();
    }
  }

  // TO DO: need another view that returns a single result - node_tree_parent
  private function _get_parent_node_id($parent_guid_value_as_string) {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_parent');
    $args = [$parent_guid_value_as_string];
    $view->setArguments($args);
    $view->execute();

    // using:
    // https://drupal.stackexchange.com/questions/280763/programmatically-get-the-results-of-a-view

    // only expecting one result
    $parent_node_id = 0;
    if ($view->total_rows > 0) {
      foreach ($view->result as $rid => $row) {
        $parent_node_id = $row->nid;
      }
    }

    return $parent_node_id;
  }

  private function _isNullOrEmptyString(string|null $str){
     return $str === null || trim($str) === '';
  }


  /**********************************************************************************************************************************************************/
  /**********************************************************************************************************************************************************/
  /**********************************************************************************************************************************************************/
  /**********************************************************************************************************************************************************/
  
  /* tests */

  /**********************************************************************************************************************************************************/
  /**********************************************************************************************************************************************************/
  /**********************************************************************************************************************************************************/
  /**********************************************************************************************************************************************************/

  // incremental development functions

  // works:
  private function _output_views_default_result_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('default');
    $view->execute();
    $view_result = $view->result;
    var_dump( $view_result );
  }

  // works:
  private function _output_views_result_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_all');
    $view->execute();
    // https://gemini.google.com/share/2679636c6c8b
    $view_result = $view->result;
    var_dump( $view_result );
  }

  // works:
  private function _output_views_count_test() {
    /*
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_all');
    $view->execute();

    // credit: https://www.drupal.org/project/drupal/issues/2797565
    $view_query = $view->query;
    $total_num_rows = $view_query->countQuery()->execute()->fetchField();

    var_dump( $total_num_rows );
    */



// 1. Get the view executable object.
/** @var \Drupal\views\ViewExecutable $view */
$view = Views::getView('node_tree');

if ($view instanceof ViewExecutable) {
    // 2. Set the display.
    $view->setDisplay('node_tree_all');
    
    // 3. Build the view (prepares the query object).
    $view->build(); 

    // 4. Get the total row count using the countQuery() method on the built query.
    // The query object is available at $view->query after $view->build().
    // We get the internal query object first, which is an instance of 
    // \Drupal\Core\Database\Query\Select, and then call countQuery().
    // The query() method on the Views query plugin is necessary to get the 
    // underlying SelectInterface object.
    // https://gemini.google.com/share/2679636c6c8b
    $total_num_rows = $view->query->query()->countQuery()->execute()->fetchField();
} else {
    $total_num_rows = 0; // Handle case where view doesn't exist
}

// 1. Get the view executable object.
/** @var \Drupal\views\ViewExecutable $view */
$view = Views::getView('node_tree');

if ($view instanceof ViewExecutable) {
    // 2. Set the display.
    $view->setDisplay('node_tree_all');
    
    // 3. Build the view (prepares the query object).
    $view->build(); 

    // 4. Get the total row count using the countQuery() method on the built query.
    // The query object is available at $view->query after $view->build().
    // We get the internal query object first, which is an instance of 
    // \Drupal\Core\Database\Query\Select, and then call countQuery().
    // The query() method on the Views query plugin is necessary to get the 
    // underlying SelectInterface object.
    // https://gemini.google.com/share/2679636c6c8b
    $total_num_rows = $view->query->query()->countQuery()->execute()->fetchField();
} else {
    $total_num_rows = 0; // Handle case where view doesn't exist
}
  }

  // works:
  private function _output_views_field_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_all');
    $view->execute();

    // https://drupal.stackexchange.com/questions/308755/how-to-get-a-rendered-views-field-value-programmatically

    foreach ($view->result as $rid => $row) {
      $this->output()->writeln($row->nid);
    }

    /* output seen

    // this is the paged output I think - there are methods on ViewsExecutable to set the number of paged items and the offset

104584
104583
104582
104581
104580
104579
104578
104577
104576
104575


    */
  }

  // works:
  private function _get_parent_id_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_parent');
    $args = ["value15"];
    $view->setArguments($args);

    $view->execute();

    // using:
    // https://drupal.stackexchange.com/questions/280763/programmatically-get-the-results-of-a-view


    foreach ($view->result as $rid => $row) {
      $this->output()->writeln($row->nid);
    }
  }


  // works:
  private function _set_parent_id_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_parent');
    $args = ["value14"];
    $view->setArguments($args);

    $view->execute();

    // using:
    // https://drupal.stackexchange.com/questions/280763/programmatically-get-the-results-of-a-view


    $parent_id = 0; // only expecting one result
    foreach ($view->result as $rid => $row) {
      $parent_id = $row->nid;
    }

    $child_node_id = 104584;
    $node = Node::load($child_node_id);
    $node->set('field_parent_taxon_reference', ['target_id' => $parent_id]);
    $node->save();

  }

  // works:
  private function _output_pages_of_views_results_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_all');

    $view->setItemsPerPage(10);
    $view->setOffset(0);

    $view->execute();

    foreach ($view->result as $rid => $row) {
      $this->output()->writeln($row->nid);
      // and also field__id_parent
    }

    $view->setItemsPerPage(10);
    $view->setOffset(10);

    $view->execute();

    // https://drupal.stackexchange.com/questions/308755/how-to-get-a-rendered-views-field-value-programmatically

    foreach ($view->result as $rid => $row) {
      $this->output()->writeln($row->nid);
    }
  }


  private function _get_child_nodes_of_parent_test() {
    // https://drupal.stackexchange.com/a/298084/1082
    // https://drupal.stackexchange.com/questions/298082/reverse-entity-reference-lookup

    $parent_id = '104548';

    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'node_tree',
      'field_parent_taxon_reference' => $parent_id,
    ]);

    var_dump($nodes);

    foreach( $nodes as $child_id => $void ) {
      var_dump($child_id);
    }
  } 

} // end class


// https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ViewExecutable.php/class/ViewExecutable/11.x
// https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ResultRow.php/class/ResultRow/11.x
// https://api.drupal.org/api/drupal/core%21modules%21node%21src%21Entity%21Node.php/class/Node/11.x
// https://drupal.stackexchange.com/questions/144947/how-do-i-access-a-field-value-for-an-entity-e-g-node-object
// https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21Field%21FieldItemListInterface.php/interface/FieldItemListInterface/11.x
// https://gemini.google.com/share/80a71b8151e8