<?php

namespace Drupal\node_tree\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;
const NODE_TREE_NODE_ROOT_UUID = ''; // TODO - fill value

/**
 * 
 * 
 */
class NodeTreeController extends ControllerBase {

  
  public function responseForImmediatechildrenOfParent() {
    return new JsonResponse($this->getImmediatechildrenOfParent());
  }
  

  /**
   * 
   * example:
   */
  public function responseForGetNodeContent( $uuid ) {
    $node = null;

    $uuidToUse = '';
    if ( !$this->IsNullOrEmptyString($uuid) ) {
      $uuidToUse = $uuid;
    }
    else {
      $uuidToUse = NODE_TREE_NODE_ROOT_UUID;
    }
    $this->getNodeFromUuid( $uuidToUse, $node );

    // https://drupal.stackexchange.com/a/194368/1082
    // and
    // https://drupal.stackexchange.com/questions/178434/how-do-i-render-nodes-now-that-node-view-has-been-deprecated#comment395002_194368
    //
    $entity_type = 'node';
    $view_mode = 'full';  
    $nodeHtml = (render(\Drupal::entityTypeManager()->getViewBuilder($entity_type)->view($node, $view_mode)))->__toString();

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
  public function getImmediatechildrenOfParent() {
    
    /*

    $anArray = $aNode->referencedEntities();

    */
    
    // parent node_tree node id
    //$parent_uuid = $aNode->id();
    // as per spec: https://mbraak.github.io/jqTree/examples/05_load_on_demand/


    
    $query = \Drupal::request()->query;

    $parent_uuid = $query->get('node');

    $child_node_ids_array = [];
    if ( !$this->IsNullOrEmptyString( $parent_uuid ) ) {



      // https://drupal.stackexchange.com/a/280924/1082

      $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
       'type' => 'node_tree',
       'field_parent_uuid' => $parent_uuid,
      ]);

      foreach ($nodes as $nid => $node) {
        $childObj = (object) [
          'name' => $node->label(),
          'id' => $node->uuid(),
          'load_on_demand' => true
        ];
        
        $child_node_ids_array[] = $childObj;
      }
    }
    else {

      // the if-then needs to decide if a node uuid has been passed in or not, and if not, set one, as in the top level parent
      // then feed this into a query that gets the children
      //
      // might want to use IsNullOrEmptyString
      // and getNodeFromTaxonkey 

      $entity = null;
      $rootTaxonKey = NODE_TREE_NODE_ROOT_UUID;

      $this->getNodeFromUuid( $rootTaxonKey, $entity );

      //$entity = \Drupal::service('entity.repository')->loadEntityByUuid('node', node_tree_NODE_ROOT_UUID);
      $name = $entity->getTitle();
      $childObj = (object) [
        'name' => $name,
        'id' => $entity->uuid(),
        'load_on_demand' => true
      ];

      $child_node_ids_array[] = $childObj;
    }
    
    return $child_node_ids_array;

    //$jsonString = json_encode($child_node_ids_array);

    // I was doing it twice
    // https://stackoverflow.com/a/57738071/227926

    // TODO: why is this not a json response?
    // answer: because there is a wrapper function that calls this function and wraps the response in JSON
    //return $child_node_ids_array;


    //return new JsonResponse($this->getImmediatechildrenOfParent());

    //return new JsonResponse($child_node_ids_array);

    // e.g. https://taxanotes.ddev.site/node_tree/api/getImmediateChildrenOfParent?parentid=104548
    // returned:
    // {"data":"[104575,104576,104577,104578]","method":"GET"}
  }

  /**
   * 
   * 
   * example:
   */
  public function getExpandedTreePathToNode( $uuid ) {
    $startingUuid = '';
    $expandedTreePathAsArray = [];
    if ( !$this->IsNullOrEmptyString( $uuid ) ) {
      $startingUuid = $uuid;
    }
    else {
      $startingUuid = NODE_TREE_NODE_ROOT_UUID;
    }

    // get the node that matches this taxon key
    //
    // there should be only one node for a given uuid, so the array $nodes should have one element only

    // get the parent using the reference field and its parent and so on

    $startingParentUuid = '';
    $startingParentTaxonName = '';
    $node = null;
    $this->getNodeFromUuid( $startingUuid, $node );

    if ($node != null ) {
      $this->addTreeNode( $startingUuid, $node->getTitle(), $expandedTreePathAsArray );

      // if node has a parent then continue walking up the branch of the tree
      $this->getImmediateParentInfo( $startingUuid, $startingParentTaxonName, $startingParentUuid );
      if ( !$this->IsNullOrEmptyString($startingParentUuid ) ) {
        $this->growExpandedTreePath( $expandedTreePathAsArray, $startingParentTaxonName, $startingParentUuid );
      }
      // else node doesn't have parent so we're already at the top node and don't need to construct a container for its parent to put it in
    }


    return new JsonResponse($expandedTreePathAsArray);
  }

  private function growExpandedTreePath( &$pathAsArray, $name, $uuidOfCurrentNodeInPath ) {



    //$pathAsArray[] = $taxonKeyOfCurrentNodeInPath;

    // get the node's parent uuid and add it to the array

    $nextParentTaxonName = '';
    $nextParentUuid = '';
    $this->getImmediateParentInfo( $uuidOfCurrentNodeInPath, $nextParentTaxonName, $nextParentUuid );

    if ( $this->IsNullOrEmptyString(  $nextParentUuid ) ) {
      // stopping case for recursion
      $this->addTreeNode( $uuidOfCurrentNodeInPath, $name, $pathAsArray );
      return; // we are at the top of the tree
    }
    else {
      $this->addTreeNode( $uuidOfCurrentNodeInPath, $name, $pathAsArray );
      $this->growExpandedTreePath( $pathAsArray, $nextParentTaxonName, $nextParentUuid );
    }
  }

  private function getNodeFromTaxonKey( $taxonkey, &$node ) {
    $nodeQueryAsArray = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'node_tree',
      'field_taxon_key' => $taxonkey,
    ]);

    // only expect one element array, because taxon key should be unique
    foreach ($nodeQueryAsArray as $nid => $aNode) {
      $node = $aNode; 
    }
  }


  /**
   * 
   * UUID is a taxonkey, but NOT the taxonkey field!  this has made it confusing - there is a uuid for the node and there is a taxonkey - why both?!
   */
  private function getNodeFromUuid( $uuid, &$node ) {
    // https://drupal.stackexchange.com/a/291869/1082
    $node = \Drupal::service('entity.repository')->loadEntityByUuid('node', $uuid);
  }


  private function getImmediateParentInfo( $childUuid, &$parentName, &$parentUuid ) {
    $parentUuid = '';
    $parentName = '';

    $childNode = null;
    $this->getNodeFromUuid( $childUuid, $childNode );
    /*
    $nodeQueryAsArray = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'type' => 'node_tree',
      'field_taxon_key' => $taxonkey,
    ]);
    */
 
    // should be just one node, but we get back an array to iterate through in any case

    // only expect one element array, because taxon key should be unique
   // foreach ($nodeQueryAsArray as $nid => $node) {
      # https://drupal.stackexchange.com/questions/144947/how-do-i-access-a-field-value-for-an-entity-e-g-node-object

    if ( $childNode ) {
      $parentNodeInfoAsArray = $childNode->get('field_parent_uuid')->getValue();
      // target_uuid isn't the uuid of the parent

      $parentName = '';
      if ( array_key_exists( 'target_id', $parentNodeInfoAsArray[0] ) ) {
      
        $parentNodeId = $parentNodeInfoAsArray[0]['target_id'];

        if ( $this->IsNullOrEmptyString( $parentNodeId ) ) {
          //break; // this node has no parent, so we're at the top of the tree, which we'd reach eventually!
        }
        else {
          // https://drupal.stackexchange.com/a/256326/1082
          $parentNode = \Drupal\node\Entity\Node::load($parentNodeId);

          // wrong! field_taxon_key is not uuid
          //$parentUuid = $parentNode->get('field_taxon_key')->getValue()[0]['value'];
          $parentUuid = $parentNode->uuid();

          $parentName = $parentNode->getTitle();
        }


      }
    }
  //  }
  }

  // https://stackoverflow.com/a/381275/227926
  private function IsNullOrEmptyString($str){
    return ($str === null || trim($str) === '');
  }

  // tree so far 
  private function addTreeNode( $uuid, $name, &$tree ) {
    if ( count( $tree ) > 0 ) {

      $treePrevious = &$tree;
 
      $tree = 
        [
          (object) [
            'id' => $uuid,
            'name' => $name,
            'is_open' => true, 
            "is_loading" => false,
            'children' => $treePrevious // (already in an array I think so no need for additional [] )
          ]
        ];
    }
    else {
      // brand new tree
      $tree = [
          (object) [
            'id' => $uuid,
           'name' => $name,
           'is_selected' => true,
           'is_closed' => false, 
          ]
      ];
    }
  }
}




/*

[
    {
        "name": "speciesname",
        "id": 104575,
        "load_on_demand": true
    },
    {
        "name": "speciesname",
        "id": 104576,
        "load_on_demand": true
    },
    {
        "name": "speciesname",
        "id": 104577,
        "load_on_demand": true
    },
    {
        "name": "speciesname",
        "id": 104578,
        "load_on_demand": true
    }
]

https://www.simonholywell.com/post/2016/11/quick-way-to-create-php-stdclass/

$x = (object) [
    'a' => 'test',
    'b' => 'test2',
    'c' => 'test3'
];


*/



/*


[
  {
    "id": "value8",
    "name": "value9",
    "is_open": true,
    "is_loading": false,
    "children": [
      { "id": "value6", "name": "speciesname6" },
      {
        "id": "value7",
        "name": "Bacteria",
        "is_open": true,
        "is_loading": false,
        "children": [
          { "id": "value1", "name": "speciesname1" },
          { "id": "value2", "name": "speciesname2" },
          { "id": "value3", "name": "speciesname3" },
          { "id": "value4", "name": "speciesname4" }
        ]
      },
      { "id": "value5", "name": "speciesname5" }
    ]
  }
]



[
    {
        "id": "guidvalue1",
        "name": "speciesname1",
        "children": [
            {
                "id": "value2",
                "name": "speciesname2",
                "children": [
                    {
                        "id": "value3",
                        "name": "speciesname3",
                        "children": [
                            {
                                "id": "value4",
                                "name": "speciesname4"
                            }
                        ]
                    }
                ]
            }
        ]
    }
]


*/
