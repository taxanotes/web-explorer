<?php

namespace Drupal\node_tree\Commands;

use Drush\Commands\DrushCommands; 
use Drupal\Component\Serialization\Json;
use Drupal\views\Views;
use Drupal\node\Entity\Node;

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
  public function nodetreeupdate() {
    // node_treeupdate function name is also the command name

    // may want to do some validation here

    $species = ""; // to be filled in

    $allNodeTreeInfo = $this->_getInfo($taxId);

    //$this->output()->writeln("species name is: ".$species." allNodeTreeInfo json is: ".print_r($allNodeTreeInfo));

    // create a node with the <an external id> number value
    //
    // credit for create node code: https://stackoverflow.com/a/52857507/227926
    $node = \Drupal::entityTypeManager()->getStorage('node')->create([
      'type'       => 'node_tree',
      'field_a' => $this->_getnode_treefield("a",$allnode_treeInfo),
      'field_b' => $this->_getnode_treefield("b",$allnode_treeInfo),
      'field_c' => $this->_getnode_treefield("c",$allnode_treeInfo),
      'title'      => $species,
    ]);
    $node->save();


    $species = [];
  } // end function node_treeupdate


  /**
   * Gets node_tree data using input CSV for calling parameters (from UKSI)
   *
   * @command node_tree:node_treelinkparent
   * @aliases node_treelnk
   * @usage node_tree:node_treelinkparent
   *   
   */
  public function linkparent() {
    // assume each node_tree node is a child of another node_tree

    // get all nodes
    // 
    // could also do it by making a View
    //
    // https://codimth.com/blog/web/drupal/how-get-all-nodes-given-type-drupal-8

    // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ViewExecutable.php/class/ViewExecutable/9.0.x
    //$view = Views::getView('node_tree');



    //$viewsExecutable->setOffset(1000);
    //$viewsExecutable->setItemsPerPage(1000);

    //var_dump( $view );

    $this->_link_parent_node_from_child_nodes();

    //$result = $viewsExecutable->buildRenderable('node_tree_all');

    //var_dump( $result );

    /*
    $viewsExecutable->$get_total_rows = true;
    $viewsExecutable->setDisplay('node_tree_all');
    $viewsExecutable->preExecute();
    $viewsExecutable->execute();

    $rendered = $viewsExecutable->render();
    //$output = \Drupal::service('renderer')->render($rendered);

    // based on D7 equivalent https://stackoverflow.com/a/48077232/227926
    $result = $viewsExecutable->$result;


    var_dump( $result );


    $total_num_rows = $viewsExecutable->$total_rows;

    */

    //$this->output()->writeln('total rows:'.$total_num_rows);


    /*
    $nids = \Drupal::entityQuery('node')->condition('type','node_tree')->execute();
    $node_treeNodes =  \Drupal\node\Entity\Node::loadMultiple($nids);
    */

    // for each node_tree node
    //foreach( $node_treeNode as $node_treeNodes ) {
      // programmatically:

      // get value from the node_tree node's  parent id field

      // https://drupalbook.org/faq/drupal-8-get-field-value-programmatically

      /*
      $node = \Drupal\node\Entity\Node::load($nid);
      $field__id_parent = $node->get('field__id_parent');
      $_id_parent = $field__id_parent->value;
      */


      // with that value, use it as parameter in the get_parent view
      // with node id result from view get node object which is the parent node_tree
      // put node object in child node_tree node entity reference field 
    //}

  }


 





  private function _link_parent_node_from_child_nodes() {
    $view = \Drupal\views\Views::getView('node_tree_view');
    $view->setDisplay('node_tree_all_view_display');

    // if I'm going through all of them programmatically, I don't need to deal with pages 
    //$view->setItemsPerPage(1000);
    $view->setOffset(0);

    $view->execute();
    //$view->render("node_tree_all");

    foreach ($view->result as $rid => $row) {

      // $row is https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ResultRow.php/class/ResultRow/9.3.x



      $child_node_id = $row->nid;


      // https://drupal.stackexchange.com/questions/274462/how-to-get-a-rendered-output-field-from-view-object-programatically
      //$field__id_parent_value = $row->field__id_parent;
      $field__id_parent_value = $row->_entity->get('field__id_parent')->getValue()[0]['value'];

      // https://drupal.stackexchange.com/q/308755/1082
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21Plugin%21views%21field%21FieldPluginBase.php/class/FieldPluginBase/8.2.x
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21Plugin%21views%21field%21FieldPluginBase.php/8.2.x
      // https://api.drupal.org/api/drupal/core%21modules%21views%21src%21ViewExecutable.php/property/ViewExecutable%3A%3Afield/9.3.x

      /*

      // example from above Q&A

      foreach ($view->result as $rid => $row) {
        $type[$row->nid] = $view->field['my_views_field']->advancedRender($row)->__toString();
      }
      */

      //$field__id_parent_value = $view->field['field__id_parent']->advancedRender($row)->__toString();


      //var_dump( $field__id_parent_value );

      $this->output()->writeln("child node id:".$child_node_id);
      $this->output()->writeln("field__id_parent_value:".$field__id_parent_value);

      /*

      ["field__id_parent"]=>
      array(1) {
        ["x-default"]=>
        array(1) {
          [0]=>
          array(1) {
            ["value"]=>
            string(16) "value13"



      */



      $this->_set_entity_reference_to_parent_node_from_child_node($child_node_id, $field__id_parent_value);
    }
  }

  private function _set_entity_reference_to_parent_node_from_child_node($child_node_id, $field__id_parent_value) {
    $parent_node_id = $this->_get_parent_node_id($field__id_parent_value);

    $node = Node::load($child_node_id);
    $node->set('field_parent_node_tree', ['target_id' => $parent_node_id]);
    $node->save();
  }

  private function _get_parent_node_id($field__id_parent_value) {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_parent');
    $args = [$field__id_parent_value];
    $view->setArguments($args);
    $view->execute();

    // using:
    // https://drupal.stackexchange.com/questions/280763/programmatically-get-the-results-of-a-view

    // only expecting one result
    $parent_node_id = 0;
    foreach ($view->result as $rid => $row) {
      $parent_node_id = $row->nid;
    }

    return $parent_node_id;
  }





  /* tests */

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
    $view_result = $view->result;
    var_dump( $view_result );
  }

  // works:
  private function _output_views_count_test() {
    $view = \Drupal\views\Views::getView('node_tree');
    $view->setDisplay('node_tree_all');
    $view->execute();

    // credit: https://www.drupal.org/project/drupal/issues/2797565
    $total_num_rows = $view->query->query()->countQuery()->execute()->fetchField();

    var_dump( $total_num_rows );
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
    $node->set('field_parent_node_tree', ['target_id' => $parent_id]);
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
      'field_parent_node_tree' => $parent_id,
    ]);

    var_dump($nodes);

    foreach( $nodes as $child_id => $void ) {
      var_dump($child_id);
    }
  } 

} // end class



