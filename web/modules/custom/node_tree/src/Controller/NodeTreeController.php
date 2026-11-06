<?php

declare(strict_types=1);

namespace Drupal\node_tree\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\node_tree\NodeTree;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The tree page and the JSON endpoints of the lazily loaded tree (jqTree).
 */
final class NodeTreeController extends ControllerBase {

  public function __construct(
    private readonly NodeTree $tree,
    EntityTypeManagerInterface $entity_type_manager,
    private readonly RendererInterface $renderer,
    private readonly RequestStack $requestStack,
  ) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('node_tree'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('request_stack'),
    );
  }

  /**
   * The title of the tree page (node_tree.settings:title).
   */
  public function title(): string {
    return $this->tree->title();
  }

  /**
   * The tree page: /<path> and /<path>/node/{id}.
   *
   * The JavaScript builds the jqTree inside #node_tree and renders the
   * selected node into .node-tree-content. A permalink to an identifier
   * that is not a published tree node is a real 404, not an empty tree.
   */
  public function page(string $id = ''): array {
    $cache = ['contexts' => ['url.path', 'user.permissions'], 'tags' => $this->tree->cacheTags()];
    if (!$this->tree->isConfigured()) {
      $message = $this->t('The node tree is not configured yet.');
      if ($this->currentUser()->hasPermission('administer node_tree')) {
        $message = $this->t('The node tree is not configured yet: choose the node types and fields on the <a href=":url">settings page</a>.', [
          ':url' => Url::fromRoute('node_tree.settings')->toString(),
        ]);
      }
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['node-tree-page', 'node-tree-unconfigured']],
        'message' => ['#markup' => '<p>' . $message . '</p>'],
        '#cache' => $cache,
      ];
    }
    if ($id !== '' && !$this->tree->loadById($id)) {
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
        '#attributes' => ['class' => ['node-tree-content', 'node-tree-content-column']],
      ],
      '#attached' => [
        'library' => ['node_tree/node_tree'],
        'drupalSettings' => ['nodeTree' => ['path' => $this->tree->path()]],
      ],
      '#cache' => $cache,
    ];
  }

  /**
   * GET /node_tree/api/getImmediateChildrenOfParent?node=<id>.
   *
   * Without `node` returns the roots (published tree nodes with no parent).
   * Each item: {id, name: title, load_on_demand: has children, child_count}.
   */
  public function responseForImmediateChildrenOfParent(): CacheableJsonResponse {
    $parent_id = (string) $this->requestStack->getCurrentRequest()->query->get('node', '');
    $response = new CacheableJsonResponse($this->tree->children($parent_id));
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheTags($this->tree->cacheTags())
      ->setCacheContexts(['url.query_args:node', 'user.node_grants:view']));
    return $response;
  }

  /**
   * GET /node_tree/api/getNodeTreeContent/{id}: the node rendered in full.
   */
  public function responseForGetNodeContent(string $id = ''): JsonResponse {
    $node = $this->tree->loadById($id);
    if (!$node || !$node->access('view')) {
      return new JsonResponse(['data' => '', 'error' => 'not found'], 404);
    }
    $build = $this->entityTypeManager->getViewBuilder('node')->view($node, 'full');
    $html = (string) $this->renderer->renderInIsolation($build);
    return new JsonResponse([
      'data' => $html,
      'title' => $node->label(),
      'nid' => $node->id(),
      'url' => $node->toUrl()->toString(),
    ]);
  }

  /**
   * GET /node_tree/api/getExpandedTreePathToNode/{id}.
   *
   * Returns a jqTree data array: the root ancestor, opened down to the
   * requested node, which is selected. Each level lists all siblings so the
   * tree looks the same as when browsed by hand.
   */
  public function getExpandedTreePathToNode(string $id = ''): CacheableJsonResponse {
    $data = [];
    $node = $this->tree->loadById($id);
    if ($node && $node->access('view')) {
      $chain = array_map(fn($ancestor) => $this->tree->idOf($ancestor), $this->tree->lineage($node));
      $data = $this->buildOpenedLevel('', $chain, 0);
    }
    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheTags($this->tree->cacheTags())
      ->setCacheContexts(['url.path', 'user.node_grants:view']));
    return $response;
  }

  /**
   * Builds one tree level, opening the item that lies on the path.
   *
   * @param string $parent_id
   *   The identifier of the level's parent ('' for the roots).
   * @param string[] $chain
   *   The identifiers from the root to the target node.
   * @param int $depth
   *   The index in $chain of this level's item on the path.
   */
  private function buildOpenedLevel(string $parent_id, array $chain, int $depth): array {
    $items = $this->tree->children($parent_id);
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

}
