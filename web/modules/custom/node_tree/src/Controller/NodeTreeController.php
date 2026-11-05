<?php

namespace Drupal\node_tree\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * JSON endpoints and page for the lazily loaded taxon tree (jqTree).
 *
 * Taxa are `taxon` nodes. A node is identified in the tree by its GUID
 * (field_guid); the hierarchy is the single-parent entity reference
 * field_parent_taxon_reference, with field_parent_guid as the denormalised
 * copy that the children lookup uses (both are indexed).
 */
class NodeTreeController extends ControllerBase {

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    protected Connection $database,
    protected RendererInterface $renderer,
    protected RequestStack $requestStack,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('renderer'),
      $container->get('request_stack'),
    );
  }

  /**
   * The tree page: /taxonomy-tree and /taxonomy-tree/node/{guid}.
   *
   * The JavaScript builds the jqTree inside #node_tree and renders the
   * selected taxon into .node_treecontentdisplay. A permalink to a GUID that
   * is not a published taxon is a real 404, not an empty tree.
   */
  public function page(string $guid = ''): array {
    if ($guid !== '' && !$this->loadNodeByGuid($guid)) {
      throw new NotFoundHttpException();
    }
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['node-tree-page']],
      'tree' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['id' => 'node_tree', 'class' => ['node-tree-column']],
      ],
      'content' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['node_treecontentdisplay', 'node-tree-content-column']],
      ],
      '#attached' => ['library' => ['node_tree/node_tree']],
      '#cache' => ['contexts' => ['url.path'], 'tags' => ['node_list:taxon']],
    ];
  }

  /**
   * GET /node_tree/api/getImmediateChildrenOfParent/?node=<guid>.
   *
   * Without `node` returns the roots (published taxa with no parent).
   * Each item: {id: guid, name: title, load_on_demand: has children}.
   */
  public function responseForImmediateChildrenOfParent(): CacheableJsonResponse {
    $parent_guid = (string) $this->requestStack->getCurrentRequest()->query->get('node', '');
    $response = new CacheableJsonResponse($this->getImmediateChildrenOfParent($parent_guid));
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheTags(['node_list:taxon'])
      ->setCacheContexts(['url.query_args:node']));
    return $response;
  }

  /**
   * Children of a parent GUID (or the roots) with a has-children flag.
   *
   * One SQL statement: children filtered on the indexed field_parent_guid
   * column (or on a NULL parent reference for the roots), joined to a
   * GROUP BY child count so only expandable nodes get load_on_demand.
   */
  public function getImmediateChildrenOfParent(string $parent_guid = ''): array {
    $query = $this->database->select('node_field_data', 'nfd');
    $query->innerJoin('node__field_guid', 'g', 'g.entity_id = nfd.nid');
    $query->addField('g', 'field_guid_value', 'id');
    $query->addField('nfd', 'title', 'name');
    $query->condition('nfd.type', 'taxon')
      ->condition('nfd.status', NodeInterface::PUBLISHED);
    if ($parent_guid !== '') {
      $query->innerJoin('node__field_parent_guid', 'pg', 'pg.entity_id = nfd.nid');
      $query->condition('pg.field_parent_guid_value', $parent_guid);
    }
    else {
      $query->leftJoin('node__field_parent_taxon_reference', 'pr', 'pr.entity_id = nfd.nid');
      $query->isNull('pr.field_parent_taxon_reference_target_id');
    }
    // Child counts for the candidate rows.
    $counts = $this->database->select('node__field_parent_taxon_reference', 'c');
    $counts->addField('c', 'field_parent_taxon_reference_target_id', 'parent_nid');
    $counts->addExpression('COUNT(*)', 'child_count');
    $counts->groupBy('c.field_parent_taxon_reference_target_id');
    $query->leftJoin($counts, 'cc', 'cc.parent_nid = nfd.nid');
    $query->addExpression('COALESCE(cc.child_count, 0)', 'child_count');
    $query->orderBy('nfd.title');

    $items = [];
    foreach ($query->execute() as $row) {
      $items[] = [
        'id' => $row->id,
        'name' => $row->name,
        'load_on_demand' => (int) $row->child_count > 0,
        'child_count' => (int) $row->child_count,
      ];
    }
    return $items;
  }

  /**
   * GET /node_tree/api/getNodeTreeContent/{uuid}: rendered taxon by GUID.
   */
  public function responseForGetNodeContent(string $uuid = ''): JsonResponse {
    $node = $this->loadNodeByGuid($uuid);
    if (!$node || !$node->access('view')) {
      return new JsonResponse(['data' => '', 'error' => 'not found'], 404);
    }
    $build = $this->entityTypeManager->getViewBuilder('node')->view($node, 'full');
    $html = (string) $this->renderer->renderInIsolation($build);
    return new JsonResponse(['data' => $html, 'nid' => $node->id(), 'url' => $node->toUrl()->toString(), 'method' => 'GET']);
  }

  /**
   * GET /node_tree/api/getExpandedTreePathToNode/{uuid}.
   *
   * Returns a jqTree data array: the root ancestor, opened down to the
   * requested taxon, which is selected. Each level lists all siblings so the
   * tree looks the same as when browsed by hand.
   */
  public function getExpandedTreePathToNode(string $uuid = ''): CacheableJsonResponse {
    $data = [];
    $node = $this->loadNodeByGuid($uuid);
    if ($node && $node->access('view')) {
      // Walk up to the root, collecting GUIDs (target first).
      $chain = [];
      $current = $node;
      $seen = [];
      while ($current && !isset($seen[$current->id()])) {
        $seen[$current->id()] = TRUE;
        $chain[] = $current->get('field_guid')->value;
        $parent = $current->get('field_parent_taxon_reference')->entity;
        $current = $parent instanceof NodeInterface ? $parent : NULL;
      }
      $chain = array_reverse($chain);
      $data = $this->buildOpenedLevel('', $chain, 0);
    }
    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheTags(['node_list:taxon'])->setCacheContexts(['url.path']));
    return $response;
  }

  /**
   * Builds one tree level, opening the item that lies on the path.
   */
  protected function buildOpenedLevel(string $parent_guid, array $chain, int $depth): array {
    $items = $this->getImmediateChildrenOfParent($parent_guid);
    $on_path = $chain[$depth] ?? NULL;
    $last = $depth === count($chain) - 1;
    foreach ($items as &$item) {
      if ($item['id'] === $on_path) {
        if ($last) {
          $item['is_selected'] = TRUE;
        }
        elseif ($item['load_on_demand']) {
          $item['is_open'] = TRUE;
          $item['load_on_demand'] = FALSE;
          $item['children'] = $this->buildOpenedLevel($item['id'], $chain, $depth + 1);
        }
      }
    }
    return $items;
  }

  /**
   * Loads the published taxon node with the given GUID, if any.
   */
  protected function loadNodeByGuid(string $guid): ?NodeInterface {
    if (trim($guid) === '') {
      return NULL;
    }
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'taxon')
      ->condition('field_guid.value', $guid)
      ->condition('status', NodeInterface::PUBLISHED)
      ->range(0, 1)
      ->accessCheck(TRUE)
      ->execute();
    if (!$nids) {
      return NULL;
    }
    $node = $this->entityTypeManager->getStorage('node')->load(reset($nids));
    return $node instanceof NodeInterface ? $node : NULL;
  }

}
