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
use Drupal\taxanotes_import\Plugin\migrate\destination\TaxanotesEntityNode;
use Drupal\taxanotes_import\Plugin\migrate\source\ScratchpadsSheet;
use Drupal\taxanotes_import\Sheet\SheetReader;
use Drupal\taxanotes_import\Sheet\SheetRows;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The sheet track: a small taxonomy importer sheet, imported twice.
 *
 * The fixture tests/fixtures/sheet.csv has a root, a genus with an <em> cell, a
 * subgenus, a synonym with an accepted name and an <em> in "Synonymy by", a
 * junior homonym (usage invalid, reason junior homonym, the valid parent,
 * authors padded with no-break spaces, an <em> in "Designation by"), two rows
 * sharing one GUID under different names, a GUID-less row whose parent is
 * given by name, and a row with its own name in the GUID cell. Two of the
 * taxa exist before the first run as D7-track placeholders (one equal to its
 * row, one differing in a cell); between the runs one taxon is re-parented
 * by hand and one unpublished. The taxon type comes from
 * recipes/taxanotes_taxon/config (the node
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

    // Two taxa the D7 track left as placeholders: Apoidea equal to its row,
    // Andrena with other authors.
    $placeholder = 'Migrated from Scratchpads GUID ';
    $apoidea_before = $this->placeholder('Apoidea', '11111111111111111111111111111111', 'Latreille, 1802', 'Superfamily', $placeholder . '11111111111111111111111111111111');
    $andrena_before = $this->placeholder('Andrena', '22222222222222222222222222222222', 'Fabricius, 1775x', 'Genus', $placeholder . '22222222222222222222222222222222');
    sleep(1);

    $counts = $this->import($path);
    $this->assertSame(['created' => 7, 'updated' => 1, 'unchanged' => 1], $counts['taxanotes_sheet_taxon']);

    // Nine taxa, one per row (the duplicate pair are two).
    $nodes = $this->nodesByTitle();
    $this->assertSame([
      'Andrena',
      'Andrena (Melandrena)',
      'Andrena (Ulandrena)',
      'Anthrena',
      'Apoidea',
      'Exaerete (Exaerete)',
      'Exaerete (Hybomelissa)',
      'Leioproctus (Alokocolletes)',
      'Trigonella',
    ], array_keys($nodes));
    // One revision each: the link pass lands in the first pass's revision.
    $this->assertSame(array_fill_keys(array_keys($nodes), 1), $this->revisionCounts());

    // The unchanged placeholder is untouched (log, vid, changed); the changed
    // one is updated in place with the row's log, no new revision.
    $apoidea = $nodes['Apoidea'];
    $this->assertSame($apoidea_before['nid'], (int) $apoidea->id());
    $this->assertSame($apoidea_before['vid'], (int) $apoidea->getRevisionId());
    $this->assertSame($apoidea_before['changed'], $apoidea->getChangedTime());
    $this->assertSame($placeholder . '11111111111111111111111111111111', $apoidea->getRevisionLogMessage());
    $andrena = $nodes['Andrena'];
    $this->assertSame($andrena_before['nid'], (int) $andrena->id());
    $this->assertSame($andrena_before['vid'], (int) $andrena->getRevisionId());
    $this->assertSame('Fabricius, 1775', $andrena->get('field_authors')->value);
    $this->assertSame('Imported from sheet sheet.csv row 3', $andrena->getRevisionLogMessage());

    // The fields and the parent links.
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

    // Markup in a plain-string column is removed; no-break spaces trimmed.
    $this->assertSame('Michener (2007: 260) [as Andrena]', $anthrena->get('field_synonymy_by')->value);

    // The junior homonym: invalid, the reason, the valid parent.
    $trigonella = $nodes['Trigonella'];
    $this->assertSame('invalid', $trigonella->get('field_usage')->value);
    $this->assertSame('junior homonym', $trigonella->get('field_unacceptability_reason')->value);
    $this->assertSame($andrena->id(), $trigonella->get('field_parent_taxon_reference')->target_id);
    $this->assertTrue($trigonella->get('field_accepted_name')->isEmpty());
    $this->assertSame('Gribodo, 1893', $trigonella->get('field_authors')->value);
    $this->assertSame('Gribodo (1893: 12) [Trigona]', $trigonella->get('field_designation_by')->value);

    // The row with its own name in the GUID cell: treated as GUID-less.
    $melandrena = $nodes['Andrena (Melandrena)'];
    $this->assertSame(SheetRows::derivedId('Andrena (Melandrena)|22222222222222222222222222222222'), $melandrena->get('field_guid')->value);
    $this->assertSame($andrena->id(), $melandrena->get('field_parent_taxon_reference')->target_id);

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
    $this->assertCount(3, $messages);
    $this->assertStringContainsString('GUID dddddddd-dddd-4ddd-8ddd-dddddddddddd is shared by rows 7 and 8 (names Exaerete (Hybomelissa), Exaerete (Exaerete)); row 8 imported as derived GUID ' . $derived, implode("\n", $messages));
    $this->assertStringContainsString('row 9 has no GUID; imported as derived GUID ' . SheetRows::derivedId('Leioproctus (Alokocolletes)|Apoidea'), implode("\n", $messages));
    $this->assertStringContainsString('row 10 (Andrena (Melandrena)): the GUID cell "Andrena (Melandrena)" is not a GUID (neither md5 nor uuid), treated as missing; imported as derived GUID ' . SheetRows::derivedId('Andrena (Melandrena)|22222222222222222222222222222222'), implode("\n", $messages));

    // Edits made on the site between the runs: Trigonella re-parented by hand
    // (a revision of its own), Anthrena unpublished (in place).
    $trigonella->set('field_parent_taxon_reference', ['target_id' => $apoidea->id()]);
    $trigonella->setNewRevision(TRUE);
    $trigonella->setRevisionLogMessage('moved by hand');
    $trigonella->save();
    $anthrena->setUnpublished()->save();

    // Second run: one cell changed. That node gets a new revision, the
    // re-parented one too (the link pass restores the sheet's parent on a
    // revision that is real history); nothing else changes, the derived ids
    // are the same, so no node is created twice, and the unpublished taxon
    // stays unpublished.
    $nodes = $this->nodesByTitle();
    $revisions_before = $this->revisionCounts();
    $changed_before = array_map(static fn(NodeInterface $n) => $n->getChangedTime(), $nodes);
    sleep(1);
    file_put_contents($path, str_replace('Warncke, 1968', 'Warncke, 1968a', file_get_contents($fixture)));
    $counts = $this->import($path, TRUE);
    $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 8], $counts['taxanotes_sheet_taxon']);
    $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 8], $counts['taxanotes_sheet_taxon_link']);

    $nodes = $this->nodesByTitle();
    $this->assertCount(9, $nodes);
    $ulandrena = $nodes['Andrena (Ulandrena)'];
    $this->assertSame('Warncke, 1968a', $ulandrena->get('field_authors')->value);
    $this->assertSame('Imported from sheet sheet.csv row 4', $ulandrena->getRevisionLogMessage());
    $trigonella = $nodes['Trigonella'];
    $this->assertSame($nodes['Andrena']->id(), $trigonella->get('field_parent_taxon_reference')->target_id);
    $this->assertSame('Imported from sheet sheet.csv row 6', $trigonella->getRevisionLogMessage());
    $this->assertFalse($nodes['Anthrena']->isPublished());
    $this->assertSame($placeholder . '11111111111111111111111111111111', $nodes['Apoidea']->getRevisionLogMessage());
    $revisions_after = $this->revisionCounts();
    foreach ($revisions_before as $title => $count) {
      $this->assertSame(in_array($title, ['Andrena (Ulandrena)', 'Trigonella'], TRUE) ? $count + 1 : $count, $revisions_after[$title], "revisions of $title");
    }
    foreach ($changed_before as $title => $time) {
      if (!in_array($title, ['Andrena (Ulandrena)', 'Trigonella'], TRUE)) {
        $this->assertSame($time, $nodes[$title]->getChangedTime(), "changed time of $title");
      }
    }
    $this->assertSame($derived, $nodes['Exaerete (Exaerete)']->get('field_guid')->value);
    $this->assertSame(SheetRows::derivedId('Leioproctus (Alokocolletes)|Apoidea'), $nodes['Leioproctus (Alokocolletes)']->get('field_guid')->value);

    // A semicolon-delimited export reads the same.
    $semicolon = $dir . '/sheet-semicolon.csv';
    $in = fopen($fixture, 'rb');
    $out = fopen($semicolon, 'wb');
    while (($cells = fgetcsv($in, 0, ',', '"', '')) !== FALSE) {
      fputcsv($out, $cells, ';', '"', '');
    }
    fclose($in);
    fclose($out);
    $this->assertSame(';', SheetReader::delimiter((string) fgets(fopen($semicolon, 'rb'))));
    $this->assertSame(SheetReader::read($fixture), SheetReader::read($semicolon));
  }

  /**
   * Creates a taxon as the D7 track leaves it: a placeholder revision log.
   *
   * @return array{nid: int, vid: int, changed: int}
   *   Its ids and changed time.
   */
  private function placeholder(string $title, string $guid, string $authors, string $rank, string $log): array {
    $node = Node::create([
      'type' => 'taxon',
      'title' => $title,
      'uid' => 1,
      'status' => 1,
      'field_guid' => $guid,
      'field_authors' => $authors,
      'field_rank' => $rank,
      'field_usage' => 'valid',
      'field_unit_names' => [['indicator' => NULL, 'name' => $title]],
    ]);
    if ($title === 'Andrena') {
      $node->set('field_parent_guid', '11111111111111111111111111111111');
      $node->set('field_page_number', '376');
      $node->set('field_reference_guid', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
      $node->set('field_reference_nid', '901');
      $node->set('field_designation_by', 'Latreille, 1810');
      $node->set('field_type_material_notes', [
        'value' => 'Type species: <em>Andrena helvola</em> (Linnaeus, 1758)',
        'format' => 'basic_html',
      ]);
    }
    $node->setRevisionLogMessage($log);
    $node->save();
    return ['nid' => (int) $node->id(), 'vid' => (int) $node->getRevisionId(), 'changed' => $node->getChangedTime()];
  }

  /**
   * Runs the taxon and the link pass over a sheet, as the Drush command does.
   *
   * @return array<string, array{created: int, updated: int, unchanged: int}>
   *   The destination's counts per migration.
   */
  private function import(string $path, bool $update = FALSE): array {
    \Drupal::state()->set(ScratchpadsSheet::STATE_PATH, $path);
    $counts = [];
    foreach (['taxanotes_sheet_taxon', 'taxanotes_sheet_taxon_link'] as $id) {
      $migration = $this->migration($id);
      if ($update) {
        $migration->getIdMap()->prepareUpdate();
      }
      $result = (new MigrateExecutable($migration, $this))->import();
      $this->assertSame(MigrationInterface::RESULT_COMPLETED, $result, $id);
      $this->assertSame([], $this->displayed['error'] ?? [], $id);
      $destination = $migration->getDestinationPlugin();
      $this->assertInstanceOf(TaxanotesEntityNode::class, $destination);
      $counts[$id] = $destination->counts();
    }
    \Drupal::state()->delete(ScratchpadsSheet::STATE_PATH);
    return $counts;
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
  }

  /**
   * {@inheritdoc}
   */
  public function display($message, $type = 'status') {
    $this->displayed[$type][] = (string) $message;
  }

}
