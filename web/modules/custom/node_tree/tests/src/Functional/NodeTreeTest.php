<?php

declare(strict_types=1);

namespace Drupal\Tests\node_tree\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\node_tree\Hook\NodeTreeRequirements;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The tree page, the JSON endpoints, the block and the breadcrumb.
 *
 * A "folder" node type with a parent reference forms the tree; a
 * "document" node type describes a folder. Three levels: Root › Branch ›
 * Leaf, with a second branch, an unpublished root and an unpublished
 * child, which must not show.
 *
 * @group node_tree
 */
#[RunTestsInSeparateProcesses]
final class NodeTreeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'block', 'node_tree'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The published tree nodes, keyed by title.
   *
   * @var array<string, \Drupal\node\NodeInterface>
   */
  private array $nodes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'folder', 'name' => 'Folder']);
    $this->drupalCreateContentType(['type' => 'document', 'name' => 'Document']);
    $this->createReferenceField('field_parent', 'folder', 'Parent');
    $this->createReferenceField('field_folder', 'document', 'Folder');
    FieldStorageConfig::create([
      'field_name' => 'field_code',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_code',
      'entity_type' => 'node',
      'bundle' => 'folder',
      'label' => 'Code',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_parent_code',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_parent_code',
      'entity_type' => 'node',
      'bundle' => 'folder',
      'label' => 'Parent code',
    ])->save();

    $this->config('node_tree.settings')
      ->set('bundles', ['folder'])
      ->set('parent_field', 'field_parent')
      ->set('id_field', 'uuid')
      ->set('describes_field', 'field_folder')
      ->set('path', 'node-tree')
      ->set('title', 'Folder tree')
      ->set('breadcrumb', TRUE)
      ->save();
    $this->container->get('router.builder')->rebuild();

    $this->drupalPlaceBlock('system_breadcrumb_block', ['id' => 'breadcrumb']);
    $this->drupalPlaceBlock('node_tree', ['id' => 'tree', 'label' => 'Tree']);

    $root = $this->createFolder('Root', NULL, 'r');
    $this->createFolder('Old root', NULL, 'old', FALSE);
    $branch = $this->createFolder('Branch', $root, 'r.b');
    $this->createFolder('Branch two', $root, 'r.b2');
    $this->createFolder('Leaf', $branch, 'r.b.l');
    $this->createFolder('Hidden leaf', $branch, 'r.b.h', FALSE);
  }

  /**
   * The tree page, its permalinks and the JSON endpoints.
   */
  public function testTreePageAndEndpoints(): void {
    $assert = $this->assertSession();
    $this->drupalGet('node-tree');
    $assert->statusCodeEquals(200);
    $assert->titleEquals('Folder tree | Drupal');
    $assert->elementExists('css', '#node_tree.node-tree-column');
    $assert->elementExists('css', '.node-tree-content');
    $assert->responseContains('"nodeTree":{"path":"node-tree"}');
    $assert->responseContains('node_tree/js/node_tree.js');

    $leaf = $this->nodes['Leaf'];
    $this->drupalGet('node-tree/node/' . $leaf->uuid());
    $assert->statusCodeEquals(200);
    $this->drupalGet('node-tree/node/no-such-node');
    $assert->statusCodeEquals(404);
    $this->drupalGet('node-tree/node/' . $this->nodes['Old root']->uuid());
    $assert->statusCodeEquals(404);

    // The roots: the published one only, expandable.
    $roots = $this->getJson('node_tree/api/getImmediateChildrenOfParent');
    $this->assertSame([
      [
        'id' => $this->nodes['Root']->uuid(),
        'name' => 'Root',
        'load_on_demand' => TRUE,
        'child_count' => 2,
      ],
    ], $roots);

    // The children of the root, in title order, with the published child
    // counts (the hidden leaf does not count).
    $children = $this->getJson('node_tree/api/getImmediateChildrenOfParent', ['node' => $this->nodes['Root']->uuid()]);
    $this->assertSame([
      [
        'id' => $this->nodes['Branch']->uuid(),
        'name' => 'Branch',
        'load_on_demand' => TRUE,
        'child_count' => 1,
      ],
      [
        'id' => $this->nodes['Branch two']->uuid(),
        'name' => 'Branch two',
        'load_on_demand' => FALSE,
        'child_count' => 0,
      ],
    ], $children);
    $this->assertSame([], $this->getJson('node_tree/api/getImmediateChildrenOfParent', ['node' => 'no-such-node']));

    // The tree opened down to the leaf, which is selected.
    $expanded = $this->getJson('node_tree/api/getExpandedTreePathToNode/' . $leaf->uuid());
    $this->assertCount(1, $expanded);
    $this->assertSame('Root', $expanded[0]['name']);
    $this->assertTrue($expanded[0]['is_open']);
    $this->assertFalse($expanded[0]['load_on_demand']);
    $this->assertSame(['Branch', 'Branch two'], array_column($expanded[0]['children'], 'name'));
    $branch = $expanded[0]['children'][0];
    $this->assertTrue($branch['is_open']);
    $this->assertSame(['Leaf'], array_column($branch['children'], 'name'));
    $this->assertTrue($branch['children'][0]['is_selected']);
    $this->assertArrayNotHasKey('is_open', $expanded[0]['children'][1]);
    $this->assertSame([], $this->getJson('node_tree/api/getExpandedTreePathToNode/no-such-node'));

    // The rendered node.
    $content = $this->getJson('node_tree/api/getNodeTreeContent/' . $leaf->uuid());
    $this->assertSame('Leaf', $content['title']);
    $this->assertSame($leaf->id(), $content['nid']);
    $this->assertStringContainsString('<article', $content['data']);
    $this->drupalGet('node_tree/api/getNodeTreeContent/' . $this->nodes['Old root']->uuid());
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * The block opens at the current node; the breadcrumb is the lineage.
   */
  public function testBlockAndBreadcrumb(): void {
    $assert = $this->assertSession();
    $leaf = $this->nodes['Leaf'];

    $this->drupalGet($leaf->toUrl());
    $assert->elementExists('css', '#node_tree.node-tree-block');
    $assert->responseContains('"nodeTree":{"path":"node-tree","selected":"' . $leaf->uuid() . '"}');
    $root_url = $this->nodes['Root']->toUrl()->toString();
    $branch_url = $this->nodes['Branch']->toUrl()->toString();
    $this->assertBreadcrumb(['Home' => $this->homeUrl(), 'Root' => $root_url, 'Branch' => $branch_url, 'Leaf' => NULL]);

    // Elsewhere the block starts at the roots.
    $this->drupalGet('<front>');
    $assert->elementExists('css', '#node_tree.node-tree-block');
    $assert->responseNotContains('"selected"');

    // A document describing the leaf gets the leaf's lineage, then itself;
    // a document titled like the leaf is left out of the trail.
    $document = $this->drupalCreateNode([
      'type' => 'document',
      'title' => 'Notes on the leaf',
      'field_folder' => $leaf->id(),
    ]);
    $this->drupalGet($document->toUrl());
    $this->assertBreadcrumb([
      'Home' => $this->homeUrl(),
      'Root' => $root_url,
      'Branch' => $branch_url,
      'Leaf' => $leaf->toUrl()->toString(),
      'Notes on the leaf' => NULL,
    ]);
    $same_name = $this->drupalCreateNode(['type' => 'document', 'title' => 'Leaf', 'field_folder' => $leaf->id()]);
    $this->drupalGet($same_name->toUrl());
    $this->assertBreadcrumb([
      'Home' => $this->homeUrl(),
      'Root' => $root_url,
      'Branch' => $branch_url,
      'Leaf' => $leaf->toUrl()->toString(),
    ]);

    // A document without a folder, and a node of another type, keep the
    // path-based breadcrumb.
    $plain = $this->drupalCreateNode(['type' => 'document', 'title' => 'Plain']);
    $this->drupalGet($plain->toUrl());
    $this->assertBreadcrumb(['Home' => $this->homeUrl()]);

    // Breadcrumb off: a tree node keeps the path-based breadcrumb too.
    $this->config('node_tree.settings')->set('breadcrumb', FALSE)->save();
    $this->drupalGet($leaf->toUrl());
    $this->assertBreadcrumb(['Home' => $this->homeUrl()]);
  }

  /**
   * A field as the identifier, the parent identifier field, parent linking.
   */
  public function testIdentifierFieldAndParentLinking(): void {
    $this->config('node_tree.settings')
      ->set('id_field', 'field_code')
      ->set('parent_id_field', 'field_parent_code')
      ->save();
    $leaf = $this->nodes['Leaf'];

    $this->assertSame(
      [['id' => 'r', 'name' => 'Root', 'load_on_demand' => TRUE, 'child_count' => 2]],
      $this->getJson('node_tree/api/getImmediateChildrenOfParent'),
    );
    $this->assertSame(['r.b', 'r.b2'], array_column($this->getJson('node_tree/api/getImmediateChildrenOfParent', ['node' => 'r']), 'id'));
    $expanded = $this->getJson('node_tree/api/getExpandedTreePathToNode/r.b.l');
    $this->assertSame('r.b.l', $expanded[0]['children'][0]['children'][0]['id']);
    $this->drupalGet('node-tree/node/r.b.l');
    $this->assertSession()->statusCodeEquals(200);
    $this->drupalGet('node-tree/node/' . $leaf->uuid());
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($leaf->toUrl());
    $this->assertSession()->responseContains('"selected":"r.b.l"');

    // Parent linking: nodes that carry a parent code but no parent
    // reference get linked; an unknown code is reported; the rest is left.
    $orphan = $this->createFolder('Orphan', NULL, 'o', TRUE, 'r.b2');
    $stray = $this->createFolder('Stray', NULL, 's', TRUE, 'nowhere');
    $leaf->set('field_parent', NULL)->save();
    /** @var \Drupal\node_tree\NodeTree $tree */
    $tree = $this->container->get('node_tree');
    $dry = $tree->linkParents(['folder'], TRUE);
    $this->assertSame(2, $dry['linked']);
    $this->assertSame([(int) $stray->id() => 'nowhere'], $dry['missing']);
    $this->assertNull(Node::load($orphan->id())->get('field_parent')->entity);

    $result = $tree->linkParents(['folder']);
    $this->assertSame(2, $result['linked']);
    $this->assertSame(3, $result['unchanged']);
    $this->assertSame(2, $result['empty']);
    $this->assertSame((int) $this->nodes['Branch two']->id(), (int) Node::load($orphan->id())->get('field_parent')->target_id);
    $this->assertSame((int) $this->nodes['Branch']->id(), (int) Node::load($leaf->id())->get('field_parent')->target_id);
    $this->assertNull(Node::load($stray->id())->get('field_parent')->entity);
    $this->assertSame(0, $tree->linkParents(['folder'])['linked']);
    $this->assertSame(['nodes' => 8, 'published' => 6, 'roots' => 3, 'unresolved' => 1], $tree->status()['folder']);
  }

  /**
   * The settings form: validation, and the tree page path.
   */
  public function testSettingsForm(): void {
    $assert = $this->assertSession();
    $this->drupalGet('admin/config/content/node-tree');
    $assert->statusCodeEquals(403);

    $this->drupalLogin($this->drupalCreateUser(['administer node_tree', 'access content']));
    $this->drupalGet('admin/config/content/node-tree');
    $assert->statusCodeEquals(200);
    $assert->checkboxChecked('bundles[folder]');
    $assert->fieldValueEquals('path', 'node-tree');

    $this->submitForm(['parent_field' => 'field_code', 'id_field' => 'field_nope'], 'Save configuration');
    $assert->pageTextContains('field_code must be an entity reference field to nodes.');
    $assert->pageTextContains('There is no node field named field_nope.');

    $this->submitForm(['bundles[document]' => TRUE, 'parent_field' => 'field_parent', 'id_field' => 'uuid'], 'Save configuration');
    $assert->pageTextContains('The node type document has no field field_parent.');

    $this->submitForm(['bundles[document]' => FALSE, 'path' => '/classification/', 'title' => 'Classification'], 'Save configuration');
    $assert->pageTextContains('The configuration options have been saved.');
    $this->assertSame('classification', $this->config('node_tree.settings')->get('path'));
    $this->drupalGet('classification');
    $assert->statusCodeEquals(200);
    $assert->titleEquals('Classification | Drupal');
    $assert->responseContains('"nodeTree":{"path":"classification"}');
    $this->drupalGet('classification/node/' . $this->nodes['Leaf']->uuid());
    $assert->statusCodeEquals(200);
    $this->drupalGet('node-tree');
    $assert->statusCodeEquals(404);

    // No bundle: the tree page says so, the endpoints are empty, the block
    // is not rendered.
    $this->drupalGet('admin/config/content/node-tree');
    $this->submitForm(['bundles[folder]' => FALSE], 'Save configuration');
    $this->drupalGet('classification');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('The node tree is not configured yet');
    $assert->elementNotExists('css', '#node_tree');
    $this->assertSame([], $this->getJson('node_tree/api/getImmediateChildrenOfParent'));
    $this->drupalGet($this->nodes['Leaf']->toUrl());
    $assert->elementNotExists('css', '#node_tree');
  }

  /**
   * The status report: the jqTree source and the configured node types.
   *
   * Drupal 11.2+ reaches NodeTreeRequirements::runtime() as a
   * hook_runtime_requirements() service; earlier versions through
   * node_tree_requirements() and the class resolver. Both must render.
   */
  public function testStatusReport(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
    $this->drupalGet('admin/reports/status');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('Node tree: jqTree library');
    $assert->pageTextContains('Node tree: node types');
    $assert->pageTextContains('folder');

    // Without a node type the report says so.
    $this->config('node_tree.settings')->set('bundles', [])->save();
    $this->drupalGet('admin/reports/status');
    $assert->pageTextContains('None configured: the tree is empty until a node type is chosen');

    // The legacy path (Drupal before 11.2) builds the object through the
    // class resolver, which must work without a service definition.
    $this->container->get('module_handler')->loadInclude('node_tree', 'install');
    $legacy = \Drupal::classResolver(NodeTreeRequirements::class)->runtime();
    $this->assertSame(['node_tree_jqtree', 'node_tree_settings'], array_keys($legacy));
    $this->assertEquals($legacy, node_tree_requirements('runtime'));
  }

  /**
   * Creates an entity reference field to nodes on a bundle.
   */
  private function createReferenceField(string $name, string $bundle, string $label): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
      'settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['folder' => 'folder']]],
    ])->save();
  }

  /**
   * Creates a folder node and remembers it by title.
   */
  private function createFolder(string $title, ?NodeInterface $parent, string $code, bool $published = TRUE, ?string $parent_code = NULL): NodeInterface {
    $node = $this->drupalCreateNode([
      'type' => 'folder',
      'title' => $title,
      'status' => $published ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED,
      'field_parent' => $parent?->id(),
      'field_code' => $code,
      'field_parent_code' => $parent_code ?? ($parent ? $parent->get('field_code')->value : NULL),
    ]);
    $this->nodes[$title] = $node;
    return $node;
  }

  /**
   * GETs a JSON endpoint and decodes it.
   */
  private function getJson(string $path, array $query = []): array {
    $this->drupalGet($path, ['query' => $query]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    return Json::decode($this->getSession()->getPage()->getContent());
  }

  /**
   * The front page's href as the breadcrumb renders it (base path aware).
   */
  private function homeUrl(): string {
    return Url::fromRoute('<front>')->toString();
  }

  /**
   * Asserts the breadcrumb's items: text => href (NULL for an unlinked item).
   *
   * @param array<string, string|null> $expected
   *   The items in order.
   */
  private function assertBreadcrumb(array $expected): void {
    $items = [];
    foreach ($this->getSession()->getPage()->findAll('css', '#block-breadcrumb ol li') as $item) {
      $link = $item->find('css', 'a');
      $items[$item->getText()] = $link ? $link->getAttribute('href') : NULL;
    }
    $this->assertSame($expected, $items);
  }

}
