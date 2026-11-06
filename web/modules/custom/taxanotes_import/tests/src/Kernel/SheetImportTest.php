<?php

declare(strict_types=1);

namespace Drupal\Tests\taxanotes_import\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Entity\ConfigEntityStorageInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessageInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxanotes_import\Plugin\migrate\source\ScratchpadsSheet;
use Drupal\taxanotes_import\Sheet\SheetRows;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The sheet track: a small taxonomy importer sheet, imported twice.
 *
 * The fixture tests/fixtures/sheet.csv has a root, a genus with an <em> cell, a
 * subgenus, a synonym with an accepted name, a junior homonym (usage
 * invalid, reason junior homonym, the valid parent), two rows sharing one
 * GUID under different names, and a GUID-less row whose parent is given by
 * name. The taxon type comes from recipes/taxanotes_taxon/config (the node
 * type, the field storages and the fields; the displays and the view are
 * not needed), installed as config entities: a Kernel test cannot apply a
 * recipe, and the field set is what the migrations map.
 *
 * The literature pass (bibcite references) is not covered here: bibcite is
 * a heavy dependency for a Kernel test, and the pass is the same lookup
 * plugin as the parent pass against another entity type; the Drush command
 * exercises it on a full site.
 *
 * @group taxanotes_import
 */
#[RunTestsInSeparateProcesses]
final class SheetImportTest extends KernelTestBase implements MigrateMessageInterface {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'options',
    'link',
    'file',
    'image',
    'node',
    'custom_field',
    'migrate',
    'taxanotes_import',
  ];

  /**
   * The messages the executable displayed, by type.
   *
   * @var array<string, string[]>
   */
  private array $displayed = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['node', 'filter']);
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    $this->installTaxonType();
  }

  /**
   * Imports the fixture, checks the taxa, then imports a changed copy.
   */
  public function testImportTwice(): void {
    $fixture = __DIR__ . '/../../fixtures/sheet.csv';
    $dir = $this->siteDirectory . '/sheets';
    mkdir($dir, 0777, TRUE);
    $path = $dir . '/sheet.csv';
    copy($fixture, $path);

    $this->import($path);

    // Eight taxa, one per row (the duplicate pair are two).
    $nodes = $this->nodesByTitle();
    $this->assertSame([
      'Andrena',
      'Andrena (Ulandrena)',
      'Anthrena',
      'Apoidea',
      'Exaerete (Exaerete)',
      'Exaerete (Hybomelissa)',
      'Leioproctus (Alokocolletes)',
      'Trigonella',
    ], array_keys($nodes));

    // The fields and the parent links.
    $apoidea = $nodes['Apoidea'];
    $andrena = $nodes['Andrena'];
    $ulandrena = $nodes['Andrena (Ulandrena)'];
    $this->assertSame('11111111111111111111111111111111', $apoidea->get('field_guid')->value);
    $this->assertSame('Superfamily', $apoidea->get('field_rank')->value);
    $this->assertTrue($apoidea->get('field_parent_taxon_reference')->isEmpty());
    $this->assertSame($apoidea->id(), $andrena->get('field_parent_taxon_reference')->target_id);
    $this->assertSame('11111111111111111111111111111111', $andrena->get('field_parent_guid')->value);
    $this->assertSame($andrena->id(), $ulandrena->get('field_parent_taxon_reference')->target_id);
    $this->assertSame('Warncke, 1968', $ulandrena->get('field_authors')->value);
    $this->assertSame('39', $ulandrena->get('field_page_number')->value);
    $this->assertSame('valid', $ulandrena->get('field_usage')->value);
    $units = array_map(static fn(array $item) => ['indicator' => $item['indicator'] ?? NULL, 'name' => $item['name']], $ulandrena->get('field_unit_names')->getValue());
    $this->assertSame([['indicator' => NULL, 'name' => 'Andrena (Ulandrena)']], $units);
    $this->assertSame(NodeInterface::PUBLISHED, (int) $ulandrena->get('status')->value);
    $this->assertSame(1, (int) $ulandrena->getOwnerId());
    $this->assertSame('Imported from sheet sheet.csv row 4', $ulandrena->getRevisionLogMessage());

    // Markup takes basic_html, a plain cell plain_text.
    $this->assertSame('Type species: <em>Andrena helvola</em> (Linnaeus, 1758)', $andrena->get('field_type_material_notes')->value);
    $this->assertSame('basic_html', $andrena->get('field_type_material_notes')->format);
    $this->assertSame('plain_text', $ulandrena->get('field_type_material_notes')->format);
    $this->assertSame('Latreille, 1810', $andrena->get('field_designation_by')->value);
    // The reference the site does not have stays in the shadow fields.
    $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $andrena->get('field_reference_guid')->value);
    $this->assertSame('901', $andrena->get('field_reference_nid')->value);

    // The synonym points at its accepted name.
    $anthrena = $nodes['Anthrena'];
    $this->assertSame('invalid', $anthrena->get('field_usage')->value);
    $this->assertSame('unjustified emendation', $anthrena->get('field_unacceptability_reason')->value);
    $this->assertSame($andrena->id(), $anthrena->get('field_accepted_name')->target_id);
    $this->assertSame('22222222222222222222222222222222', $anthrena->get('field_accepted_guid')->value);
    $this->assertSame('5', $anthrena->get('field_accepted_tid')->value);

    // The junior homonym: invalid, the reason, the valid parent.
    $trigonella = $nodes['Trigonella'];
    $this->assertSame('invalid', $trigonella->get('field_usage')->value);
    $this->assertSame('junior homonym', $trigonella->get('field_unacceptability_reason')->value);
    $this->assertSame($andrena->id(), $trigonella->get('field_parent_taxon_reference')->target_id);
    $this->assertTrue($trigonella->get('field_accepted_name')->isEmpty());

    // The shared GUID: the first row keeps it, the second a derived id.
    $shared = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    $derived = SheetRows::derivedId($shared . '|Exaerete (Exaerete)');
    $this->assertSame($shared, $nodes['Exaerete (Hybomelissa)']->get('field_guid')->value);
    $this->assertSame($derived, $nodes['Exaerete (Exaerete)']->get('field_guid')->value);
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $derived);
    $this->assertSame($andrena->id(), $nodes['Exaerete (Exaerete)']->get('field_parent_taxon_reference')->target_id);

    // The GUID-less row: a derived id, the parent resolved by name.
    $alokocolletes = $nodes['Leioproctus (Alokocolletes)'];
    $this->assertSame(SheetRows::derivedId('Leioproctus (Alokocolletes)|Apoidea'), $alokocolletes->get('field_guid')->value);
    $this->assertSame('11111111111111111111111111111111', $alokocolletes->get('field_parent_guid')->value);
    $this->assertSame($apoidea->id(), $alokocolletes->get('field_parent_taxon_reference')->target_id);

    // The identity notes are messages of the first pass.
    $messages = [];
    foreach ($this->migration('taxanotes_sheet_taxon')->getIdMap()->getMessages() as $message) {
      $messages[] = (string) $message->message;
    }
    $this->assertCount(2, $messages);
    $this->assertStringContainsString('GUID dddddddd-dddd-4ddd-8ddd-dddddddddddd is shared by rows 7 and 8 (names Exaerete (Hybomelissa), Exaerete (Exaerete)); row 8 imported as derived GUID ' . $derived, implode("\n", $messages));
    $this->assertStringContainsString('row 9 has no GUID; imported as derived GUID ' . SheetRows::derivedId('Leioproctus (Alokocolletes)|Apoidea'), implode("\n", $messages));

    // Second run: one cell changed. Only that node gets a new revision; the
    // derived ids are the same, so no node is created twice.
    $revisions_before = $this->revisionCounts();
    $changed_before = array_map(static fn(NodeInterface $n) => $n->getChangedTime(), $nodes);
    sleep(1);
    file_put_contents($path, str_replace('Warncke, 1968', 'Warncke, 1968a', file_get_contents($fixture)));
    $this->import($path, TRUE);

    $nodes = $this->nodesByTitle();
    $this->assertCount(8, $nodes);
    $ulandrena = $nodes['Andrena (Ulandrena)'];
    $this->assertSame('Warncke, 1968a', $ulandrena->get('field_authors')->value);
    $this->assertSame('Imported from sheet sheet.csv row 4', $ulandrena->getRevisionLogMessage());
    $revisions_after = $this->revisionCounts();
    foreach ($revisions_before as $title => $count) {
      $this->assertSame($title === 'Andrena (Ulandrena)' ? $count + 1 : $count, $revisions_after[$title], "revisions of $title");
    }
    foreach ($changed_before as $title => $time) {
      if ($title !== 'Andrena (Ulandrena)') {
        $this->assertSame($time, $nodes[$title]->getChangedTime(), "changed time of $title");
      }
    }
    $this->assertSame($derived, $nodes['Exaerete (Exaerete)']->get('field_guid')->value);
    $this->assertSame(SheetRows::derivedId('Leioproctus (Alokocolletes)|Apoidea'), $nodes['Leioproctus (Alokocolletes)']->get('field_guid')->value);
  }

  /**
   * Runs the taxon and the link pass over a sheet, as the Drush command does.
   */
  private function import(string $path, bool $update = FALSE): void {
    \Drupal::state()->set(ScratchpadsSheet::STATE_PATH, $path);
    foreach (['taxanotes_sheet_taxon', 'taxanotes_sheet_taxon_link'] as $id) {
      $migration = $this->migration($id);
      if ($update) {
        $migration->getIdMap()->prepareUpdate();
      }
      $result = (new MigrateExecutable($migration, $this))->import();
      $this->assertSame(MigrationInterface::RESULT_COMPLETED, $result, $id);
      $this->assertSame([], $this->displayed['error'] ?? [], $id);
    }
    \Drupal::state()->delete(ScratchpadsSheet::STATE_PATH);
  }

  /**
   * A fresh instance of a migration.
   */
  private function migration(string $id): MigrationInterface {
    return $this->container->get('plugin.manager.migration')->createInstance($id);
  }

  /**
   * The taxon nodes keyed by title, in title order.
   *
   * @return array<string, \Drupal\node\NodeInterface>
   *   The nodes.
   */
  private function nodesByTitle(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache();
    $nodes = [];
    foreach ($storage->loadByProperties(['type' => 'taxon']) as $node) {
      $nodes[$node->label()] = $node;
    }
    ksort($nodes);
    return $nodes;
  }

  /**
   * Revisions per taxon, by title.
   *
   * @return array<string, int>
   *   The counts.
   */
  private function revisionCounts(): array {
    $counts = [];
    foreach ($this->nodesByTitle() as $title => $node) {
      $counts[$title] = (int) $this->container->get('database')->select('node_revision', 'r')
        ->condition('r.nid', $node->id())
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    return $counts;
  }

  /**
   * Installs the taxon type from the recipe's config files.
   *
   * The node type, then the field storages, then the fields of the taxon
   * bundle, each created from its exported YAML.
   */
  private function installTaxonType(): void {
    $dir = dirname($this->root) . '/recipes/taxanotes_taxon/config';
    $this->assertDirectoryExists($dir);
    $manager = $this->container->get('entity_type.manager');
    $create = function (string $entity_type, string $pattern) use ($dir, $manager): void {
      foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
        $data = Yaml::decode((string) file_get_contents($file));
        unset($data['uuid'], $data['_core']);
        // As the config installer does: the storage maps the exported
        // settings (allowed_values as value/label pairs) to the entity's.
        $storage = $manager->getStorage($entity_type);
        assert($storage instanceof ConfigEntityStorageInterface);
        $storage->createFromStorageRecord($data)->save();
      }
    };
    $create('node_type', 'node.type.taxon.yml');
    $create('field_storage_config', 'field.storage.node.*.yml');
    $create('field_config', 'field.field.node.taxon.*.yml');
    $this->assertInstanceOf(Node::class, Node::create(['type' => 'taxon', 'title' => 'probe']));
  }

  /**
   * {@inheritdoc}
   */
  public function display($message, $type = 'status') {
    $this->displayed[$type][] = (string) $message;
  }

}
