<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Sheet;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateMapSaveEvent;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\taxanotes_import\Plugin\migrate\destination\TaxanotesEntityNode;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Counts what one migration run did: created, changed, unchanged, failed.
 *
 * Core's id map tells rows apart by status (imported, ignored, failed), not
 * by outcome: a --update run processes every row and most of them change
 * nothing. The sheet import wants to say "3,047 rows matched, 12 changed",
 * so this listens to the run:
 * - the map save events give the per-status counts for this migration;
 * - the pre-row-save event, for a pass with `overwrite_properties`
 *   (entity:node), compares each overwritten field's current value with the
 *   row's before the save, which is the only moment both exist;
 * - the destination `taxanotes_entity_node` counts created / changed /
 *   unchanged itself (it has to know anyway) and is asked afterwards.
 * Attach before the import, detach after; the listeners are removed so a
 * later run in the same process is not counted twice.
 */
final class RunCounter {

  /**
   * Rows per id-map status for this migration.
   *
   * @var array<int, int>
   */
  private array $statuses = [
    MigrateIdMapInterface::STATUS_IMPORTED => 0,
    MigrateIdMapInterface::STATUS_NEEDS_UPDATE => 0,
    MigrateIdMapInterface::STATUS_IGNORED => 0,
    MigrateIdMapInterface::STATUS_FAILED => 0,
  ];

  /**
   * Rows whose overwritten fields differed from the entity before the save.
   */
  private int $changed = 0;

  /**
   * Rows whose overwritten fields already held the row's values.
   */
  private int $unchanged = 0;

  /**
   * Rows saved so far (for the progress feedback).
   */
  private int $saved = 0;

  /**
   * The attached listeners: event name => callable.
   *
   * @var array<string, callable>
   */
  private array $listeners = [];

  /**
   * Starts counting the given migration's run.
   *
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration about to run.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, to load the entity a row will overwrite.
   * @param \Closure|null $feedback
   *   Called with the number of rows saved so far, every $every rows.
   * @param int $every
   *   The feedback interval.
   */
  public function __construct(
    private readonly MigrationInterface $migration,
    private readonly EventDispatcherInterface $dispatcher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?\Closure $feedback = NULL,
    private readonly int $every = 500,
  ) {
    $this->listeners = [
      MigrateEvents::MAP_SAVE => $this->onMapSave(...),
      MigrateEvents::PRE_ROW_SAVE => $this->onPreRowSave(...),
      MigrateEvents::POST_ROW_SAVE => $this->onPostRowSave(...),
    ];
    foreach ($this->listeners as $event => $listener) {
      $this->dispatcher->addListener($event, $listener);
    }
  }

  /**
   * Stops listening.
   */
  public function detach(): void {
    foreach ($this->listeners as $event => $listener) {
      $this->dispatcher->removeListener($event, $listener);
    }
    $this->listeners = [];
  }

  /**
   * The outcome of the run.
   *
   * @return array{processed: int, created: int, updated: int, unchanged: int, ignored: int, failed: int}
   *   created / updated / unchanged from the destination when it counts them
   *   (taxanotes_entity_node), else updated / unchanged from the field
   *   comparison of an overwrite_properties pass; processed is every row the
   *   id map saw, ignored the skipped rows, failed the failed ones.
   */
  public function counts(): array {
    $destination = $this->migration->getDestinationPlugin();
    if ($destination instanceof TaxanotesEntityNode) {
      $entity_counts = $destination->counts();
    }
    else {
      $entity_counts = ['created' => 0, 'updated' => $this->changed, 'unchanged' => $this->unchanged];
    }
    return [
      'processed' => array_sum($this->statuses),
      'created' => $entity_counts['created'],
      'updated' => $entity_counts['updated'],
      'unchanged' => $entity_counts['unchanged'],
      'ignored' => $this->statuses[MigrateIdMapInterface::STATUS_IGNORED],
      'failed' => $this->statuses[MigrateIdMapInterface::STATUS_FAILED],
    ];
  }

  /**
   * Counts a map save of this migration by status.
   */
  private function onMapSave(MigrateMapSaveEvent $event): void {
    if ($event->getMap()->getQualifiedMapTableName() !== $this->migration->getIdMap()->getQualifiedMapTableName()) {
      return;
    }
    $status = (int) ($event->getFields()['source_row_status'] ?? MigrateIdMapInterface::STATUS_IMPORTED);
    $this->statuses[$status] = ($this->statuses[$status] ?? 0) + 1;
  }

  /**
   * Compares the overwritten fields with the entity before the save.
   */
  private function onPreRowSave(MigratePreRowSaveEvent $event): void {
    if ($event->getMigration()->id() !== $this->migration->id()) {
      return;
    }
    $properties = $this->migration->getDestinationConfiguration()['overwrite_properties'] ?? [];
    if (!$properties) {
      return;
    }
    $row = $event->getRow();
    $plugin_id = (string) ($this->migration->getDestinationConfiguration()['plugin'] ?? '');
    $entity_type = str_starts_with($plugin_id, 'entity:') ? substr($plugin_id, strlen('entity:')) : 'node';
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $id_key = $storage->getEntityType()->getKey('id');
    $id = $row->hasDestinationProperty($id_key) ? $row->getDestinationProperty($id_key) : NULL;
    $entity = $id ? $storage->load($id) : NULL;
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }
    foreach ($properties as $property) {
      $current = $entity->hasField($property) && !$entity->get($property)->isEmpty()
        ? $entity->get($property)->first()->getValue()
        : NULL;
      $current = is_array($current) ? ($current['target_id'] ?? $current['value'] ?? reset($current)) : $current;
      $new = $row->hasDestinationProperty($property) ? $row->getDestinationProperty($property) : NULL;
      $new = is_array($new) ? ($new['target_id'] ?? $new['value'] ?? reset($new)) : $new;
      if ((string) ($current ?? '') !== (string) ($new ?? '')) {
        $this->changed++;
        return;
      }
    }
    $this->unchanged++;
  }

  /**
   * Reports progress.
   */
  private function onPostRowSave(MigratePostRowSaveEvent $event): void {
    if ($event->getMigration()->id() !== $this->migration->id()) {
      return;
    }
    $this->saved++;
    if ($this->feedback && $this->every > 0 && $this->saved % $this->every === 0) {
      ($this->feedback)($this->saved);
    }
  }

}
