<?php

namespace Drupal\node_tree\Controller;

use Drupal\Core\Entity\Query\QueryFactory;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

//const NODE_TREE_NODE_ROOT_guid = ''; // TODO - fill value
const NODE_TREE_NODE_ROOT_guid = 'a'; // TODO - fill value
// but how do I deal with when I have multiple roots?

/**
 * 
 * 
 */
class NodeTreeController extends ControllerBase
{

  protected $entityTypeManager;

  // Step 3: Your __construct receives the object
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  // Step 1: Drupal calls create() FIRST, passing in the container
  // Step 2: You ask the container for the service you need
  public static function create(ContainerInterface $container) {
    return new static(
      // The container has all registered services
      // It returns the actual EntityTypeManager object
      $container->get('entity_type.manager')
    );
  }

  public function responseForImmediatechildrenOfParent()
  {
    return new JsonResponse($this->getImmediatechildrenOfParent());
  }


  /**
   * 
   * example:
   * 
   * input:
   * 
   * output: 
   */
  public function responseForGetNodeContent($guid)
  {
    $node = null;

    $guidToUse = '';
    if (!$this->IsNullOrEmptyString($guid)) {
      $guidToUse = $guid;
    } else {
      $guidToUse = NODE_TREE_NODE_ROOT_guid;
    }
    $this->getChildNodeFromguid($guidToUse, $node);

    // https://drupal.stackexchange.com/a/194368/1082
    // and
    // https://drupal.stackexchange.com/questions/178434/how-do-i-render-nodes-now-that-node-view-has-been-deprecated#comment395002_194368
    //
    $entity_type = 'node';
    $view_mode = 'full';

    //https://www.drupal.org/node/2939099
    //$nodeHtml = (render(\Drupal::entityTypeManager()->getViewBuilder($entity_type)->view($node, $view_mode)))->__toString();
    //
    // now becomes:
    $build = \Drupal::entityTypeManager()->getViewBuilder($entity_type)->view($node, $view_mode);
    $output = \Drupal::service('renderer')->render($build);
    $nodeHtml = $output->__toString();

    return new JsonResponse([
      'data' => $nodeHtml,
      'method' => 'GET',
    ]);
  }



  /**
   * 
   */
  // https://taxanotes.ddev.site/node_tree/api/getImmediateChildrenOfParent/?node=<value>
  // very subtle /?node= works but not ?node= without the preceding slash
  //public function getImmediatechildrenOfParent() {

  /**
   * 
   * example:
   * 
   * input:
   * 
   * output: 
   */
  public function getImmediatechildrenOfParent()
  {

    // https://gemini.google.com/share/c1a91d550221

    $query = \Drupal::request()->query;

        $query = \Drupal::request()->query;

    $parent_guid = $query->get('node');


    $child_node_ids_array = [];
    if ( !$this->IsNullOrEmptyString( $parent_guid ) ) {
      // https://drupal.stackexchange.com/a/298084/1082


      // https://drupal.stackexchange.com/a/280924/1082

      // this needs rewriting like the "else" 
      //   - change it to not rely on the field_parent_guid, use the entity relationship field instead
      //   - dont have a for-loop - not efficient
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
       'type' => 'taxon',
       'field_parent_guid' => $parent_guid,
      ]);

      foreach ($nodes as $nid => $node) {
        $childObj = (object) [
          'name' => $node->label(),
          'id' => $node->get('field_guid')->getValue()[0]['value'],
              //      'id' => $node->uuid(),
          'load_on_demand' => true
        ];
        
        $child_node_ids_array[] = $childObj;
      }
    }
    else {

     /*

      // the if-then needs to decide if a node uuid has been passed in or not, and if not, set one, as in the top level parent
      // then feed this into a query that gets the children
      //
      // might want to use IsNullOrEmptyString
      // and getNodeFromTaxonkey 

      $entity = null;
      $rootTaxonKey = NODE_TREE_NODE_ROOT_guid;

      // this should really be - get all nodes without a parent - i.e. the top level, as we may start with multiple nodes at the top level

      $this->getChildNodeFromguid( $rootTaxonKey, $entity );

      $node = null;
      $parent_guid = $rootTaxonKey;
      $this->getNodeFromGuid($parent_guid, $node);

      $childObj = (object) [
        'name' => $node->label(),
        'id' => $parent_guid,
        'load_on_demand' => true
      ];

      $child_node_ids_array[] = $childObj;

      */

/*

// 1. Get the node storage.
$query = \Drupal::entityTypeManager()->getStorage('node')->getQuery();

// 2. Add an OR condition group.
// We want to find nodes where the field is NULL OR it is an empty string.
$or_group = $query->orConditionGroup()
  ->notExists('field_parent_guid')      // Field is empty/never saved
  ->condition('field_parent_guid', ''); // Field exists but is an empty string

// 3. Apply the conditions and execute.
$child_node_ids_array = $query
  ->condition('type', 'taxon')
  ->accessCheck(FALSE) // Use TRUE if you want to respect the current user's permissions
  ->condition($or_group)
  ->execute();

  */

  // below was generated with help of: https://gemini.google.com/share/75697eb801d6


  /*
$database = \Drupal::database();
$query = $database->select('node_field_data', 'nfd');

// Join the entity reference field table
// Replace 'field_your_reference' with your actual field machine name
$query->leftJoin('node__field_parent_taxon_reference', 'f', 'f.entity_id = nfd.nid');

$query->condition('type', 'taxon');

$query->fields('nfd', ['title']);

// Find where the reference is missing (NULL)
$query->isNull('f.field_parent_taxon_reference_target_id');

$child_node_ids_array = $query->execute()->fetchCol();
*/




$database = \Drupal::database();
$query = $database->select('node_field_data', 'nfd');

// Join the field table
$query->leftJoin('node__field_parent_taxon_reference', 'f', 'f.entity_id = nfd.nid');
$query->leftJoin('node__field_guid', 'f_guid', 'f_guid.entity_id = nfd.nid');

$query->condition('type', 'taxon');

// Select the real columns
/*
$query->fields('nfd', ['nid', 'title']);
*/
// Select 'nid' normally, and rename 'title' to 'name'
$query->addField('nfd', 'title', 'name');
//$query->addField('nfd', 'nid', 'nid');
$query->addField('f_guid', 'field_guid_value', 'id');

/*
$query->fields('nfd', [
  'nid' => 'nid',
  'name' => 'title', 
]);
*/

// Add a "dummy" or fixed expression that is always true (1)
// We alias it as 'is_empty_reference'
$query->addExpression(true, 'load_on_demand');

// Filter for empty/unset entity reference
$query->isNull('f.field_parent_taxon_reference_target_id');

// Execute and fetch as objects
$child_node_ids_array = $query->execute()->fetchAll();

// nearly working: https://gemini.google.com/share/390755d74a24 but parents are repeatedly output
// UPDATE - now working https://gemini.google.com/share/b338f54f8898

// Title (label) 
// GUID - field_guid
// Parent GUID - field_parent_guid <-- dont use this, use field_parent_taxon_reference instead


    }
    
    return $child_node_ids_array;


          //$entity = \Drupal::service('entity.repository')->loadEntityByUuid('node', BOL_NODE_ROOT_UUID);
      /*
      $name = $entity->getTitle();
      $childObj = (object) [
        'name' => $name,
        'id' => $entity->get('field_guid'),
        'load_on_demand' => true
      ];
      */
  }

  /**
   * 
   * 
   * example:
   */
  public function getExpandedTreePathToNode($guid)
  {
    $startingGuid = '';
    $expandedTreePathAsArray = [];
    if (!$this->IsNullOrEmptyString($guid)) {
      $startingGuid = $guid;
    } else {
      $startingGuid = NODE_TREE_NODE_ROOT_guid;
    }

    // get the node that matches this taxon key
    //
    // there should be only one node for a given guid, so the array $nodes should have one element only

    // get the parent using the reference field and its parent and so on

    $startingParentGuid = '';
    $startingParentTaxonName = '';
    $node = null;
    $this->getChildNodeFromguid($startingGuid, $node);

    if ($node != null) {
      $this->addTreeNode($startingGuid, $node->getTitle(), $expandedTreePathAsArray);

      // if node has a parent then continue walking up the branch of the tree
      $this->getImmediateParentInfo($startingGuid, $startingParentTaxonName, $startingParentGuid);
      if (!$this->IsNullOrEmptyString($startingParentGuid)) {
        $this->growExpandedTreePath($expandedTreePathAsArray, $startingParentTaxonName, $startingParentGuid);
      }
      // else node doesn't have parent so we're already at the top node and don't need to construct a container for its parent to put it in
    }


    return new JsonResponse($expandedTreePathAsArray);
  }

  private function growExpandedTreePath(&$pathAsArray, $name, $guidOfCurrentNodeInPath)
  {



    //$pathAsArray[] = $taxonKeyOfCurrentNodeInPath;

    // get the node's parent guid and add it to the array

    $nextParentTaxonName = '';
    $nextParentguid = '';
    $this->getImmediateParentInfo($guidOfCurrentNodeInPath, $nextParentTaxonName, $nextParentguid);

    if ($this->IsNullOrEmptyString($nextParentguid)) {
      // stopping case for recursion
      $this->addTreeNode($guidOfCurrentNodeInPath, $name, $pathAsArray);
      return; // we are at the top of the tree
    } else {
      $this->addTreeNode($guidOfCurrentNodeInPath, $name, $pathAsArray);
      $this->growExpandedTreePath($pathAsArray, $nextParentTaxonName, $nextParentguid);
    }
  }

  private function getNodeFromGuid($guid, &$node)
  {
    /*
    $nodeQueryAsArray = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'node_tree',
      'field_guid' => $guid,
    ]);
    */


    $query = $this->entityTypeManager->getStorage('node')->getQuery();

    $nodeQueryAsArray = $query
  ->condition('type', 'taxon')
  ->condition('field_guid.value', $guid)  // Add '.value' for the column
  ->condition('status', 1)
  ->range(0, 1)
  ->accessCheck(TRUE)  // Don't forget this!
  ->execute();
  
      // Check if any nodes were found.
    if (!empty($nodeQueryAsArray)) {
      // Get the first Node ID (nid).
      $nid = reset($nodeQueryAsArray); 
  
  // Load the full node object.
      /** @var \Drupal\node\NodeInterface $node */
      $node = \Drupal\node\Entity\Node::load($nid);

      // Now you have the node object.
      // ... your logic here ...
    } else {
      // No node found matching the criteria.
    }


    // only expect one element array, because taxon key should be unique
    /*
    foreach ($nodeQueryAsArray as $nid => $aNode) {
      $node = $aNode;
    }
*/

  }


  /**
   * 
   * guid is a taxonkey, but NOT the taxonkey field!  this has made it confusing - there is a guid for the node and there is a taxonkey - why both?!
   */
  private function getChildNodeFromguid($guid, &$node)
  {
    // https://gemini.google.com/share/07f07dfe0efe



//use Drupal\Core\Entity\Query\QueryFactory;

$query = $this->entityTypeManager->getStorage('node')->getQuery();
$nids = $query
  ->condition('type', 'taxon')
  ->condition('field_parent_guid.value', $guid)  // Add '.value' for the column
  ->condition('status', 1)
  ->range(0, 1)
  ->accessCheck(TRUE)  // Don't forget this!
  ->execute();
  
    // Check if any nodes were found.
    if (!empty($nids)) {
      // Get the first Node ID (nid).
      $nid = reset($nids); 
  
  // Load the full node object.
      /** @var \Drupal\node\NodeInterface $node */
      $node = \Drupal\node\Entity\Node::load($nid);

      // Now you have the node object.
      // ... your logic here ...
    } else {
      // No node found matching the criteria.
    }


    /*
    // https://drupal.stackexchange.com/a/291869/1082
    $node = \Drupal::service('entity.repository')->loadEntityByguid('node', $guid);
    // TODO
*/
  }


  private function getImmediateParentInfo($childguid, &$parentName, &$parentguid)
  {
    $parentguid = '';
    $parentName = '';

    $childNode = null;
    $this->getChildNodeFromguid($childguid, $childNode);
    // should be just one node, but we get back an array to iterate through in any case

    // only expect one element array, because taxon key should be unique
    // foreach ($nodeQueryAsArray as $nid => $node) {
    # https://drupal.stackexchange.com/questions/144947/how-do-i-access-a-field-value-for-an-entity-e-g-node-object

    if ($childNode) {
      $parentNodeInfoAsArray = $childNode->get('field_parent_guid')->getValue();
      // target_guid isn't the guid of the parent

      $parentName = '';
      if (array_key_exists('target_id', $parentNodeInfoAsArray[0])) {

        $parentNodeId = $parentNodeInfoAsArray[0]['target_id'];

        if ($this->IsNullOrEmptyString($parentNodeId)) {
          //break; // this node has no parent, so we're at the top of the tree, which we'd reach eventually!
        } else {
          // https://drupal.stackexchange.com/a/256326/1082
          $parentNode = \Drupal\node\Entity\Node::load($parentNodeId);

          // wrong! field_taxon_key is not guid
          //$parentguid = $parentNode->get('field_taxon_key')->getValue()[0]['value'];
          //$parentguid = $parentNode->guid();
          $parentNode->get('field_guid')->getValue();

          $parentName = $parentNode->getTitle();
        }
      }
    }
    //  }
  }

  // https://stackoverflow.com/a/381275/227926
  private function IsNullOrEmptyString($str)
  {
    return ($str === null || trim($str) === '');
  }

  // tree so far 
  private function addTreeNode($guid, $name, &$tree)
  {
    if (count($tree) > 0) {

      $treePrevious = &$tree;

      $tree =
        [
          (object) [
            'id' => $guid,
            'name' => $name,
            'is_open' => true,
            "is_loading" => false,
            'children' => $treePrevious // (already in an array I think so no need for additional [] )
          ]
        ];
    } else {
      // brand new tree
      $tree = [
        (object) [
          'id' => $guid,
          'name' => $name,
          'is_selected' => true,
          'is_closed' => false,
        ]
      ];
    }
  }
}
