<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\destination;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Content entities whose re-imports never overwrite real revision history.
 *
 * Core's entity:node destination updates an existing entity's default
 * revision in place, including its revision log. Once the Scratchpads
 * revision history has been imported, that default revision *is* the D7
 * current revision (its own author, time and log), and the revision
 * migration deletes a replaced default revision whose log starts with one of
 * its `replace_log_prefixes`. An in-place update would therefore turn a
 * genuine revision into a placeholder that the next revision import deletes,
 * so the history ends one revision short.
 *
 * This plugin tells the two cases apart by the log of the revision it is
 * about to update:
 * - a row that changes no field value leaves the revision exactly as it is,
 *   whatever its log: the log the row carries is discarded, so a forced
 *   --update of unchanged rows adds no revision, alters no log and keeps a
 *   placeholder a placeholder;
 * - a placeholder (log starts with one of `placeholder_log_prefixes`: the log
 *   this migration wrote on creation, the reset log of a rolled-back history,
 *   or an earlier sync revision) is updated in place, as core does;
 * - anything else is real history: the entity is saved as a *new* default
 *   revision with the row's `sync_revision_log` (destination-only property,
 *   removed before the entity is touched), author `sync_uid` (default 1) and
 *   the current time.
 *
 * A sync revision is itself a placeholder: the revision migration lists its
 * prefix in `replace_log_prefixes`, so the D7 revision that caused the change
 * replaces it when the history is next imported, and the node's history stays
 * exactly the D7 history. Rows that create a new entity behave as in core.
 *
 * `create_only_properties` names destination properties (e.g. `uid`,
 * `status`) that are set when the row creates the entity and dropped when it
 * updates one, so a source without an author or a status column (the sheet)
 * does not re-own or re-publish a taxon on every import. Works with
 * `overwrite_properties` as in core: only the listed fields are compared and
 * written.
 */
#[MigrateDestination(
  id: 'taxanotes_entity_node',
)]
final class TaxanotesEntityNode extends EntityContentBase {

  use NonDerivedEntityDestinationTrait {
    create as traitCreate;
  }

  /**
   * The time service (for the timestamp of a sync revision).
   */
  protected ?TimeInterface $time = NULL;

  /**
   * The sync revision log of the row being imported, if any.
   */
  protected ?string $syncLog = NULL;

  /**
   * What this run did to the entities: created, updated (changed), unchanged.
   *
   * Core's id map counts rows, not outcomes; a --update run processes every
   * row and most change nothing. The sheet import reports these instead.
   *
   * @var array{created: int, updated: int, unchanged: int}
   */
  protected array $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    $instance = static::traitCreate($container, $configuration, $plugin_id, $plugin_definition, $migration);
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * The entities this instance created, changed and left unchanged so far.
   *
   * @return array{created: int, updated: int, unchanged: int}
   *   The counts.
   */
  public function counts(): array {
    return $this->counts;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $property = $this->configuration['sync_log_property'] ?? 'sync_revision_log';
    $this->syncLog = $row->hasDestinationProperty($property) ? (string) $row->getDestinationProperty($property) : NULL;
    $row->removeDestinationProperty($property);
    $entity = parent::getEntity($row, $old_destination_id_values);
    if ($entity->isNew()) {
      $this->counts['created']++;
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function updateEntity(EntityInterface $entity, Row $row) {
    foreach (array_filter((array) ($this->configuration['create_only_properties'] ?? [])) as $property) {
      $row->removeDestinationProperty((string) $property);
    }
    $previous_log = $entity instanceof RevisionLogInterface ? (string) $entity->getRevisionLogMessage() : NULL;
    $entity = parent::updateEntity($entity, $row);
    if (!$entity instanceof ContentEntityInterface || !$entity instanceof RevisionLogInterface || $previous_log === NULL) {
      return $entity;
    }
    $changed = $entity->hasTranslationChanges();
    $this->counts[$changed ? 'updated' : 'unchanged']++;
    if (!$changed) {
      // Nothing changed: keep the revision exactly as it is, log included
      // (core would still write the row's log; a placeholder would then stop
      // being one and the revision import would keep it as history).
      $entity->setRevisionLogMessage($previous_log);
      return $entity;
    }
    if ($this->isPlaceholderLog($previous_log)) {
      // Not real history: core behaviour (in place, log from the row).
      return $entity;
    }
    $entity->setNewRevision(TRUE);
    $entity->setRevisionLogMessage($this->syncLog ?? (string) $row->getDestinationProperty('revision_log'));
    $entity->setRevisionUserId((int) ($this->configuration['sync_uid'] ?? 1));
    $entity->setRevisionCreationTime($this->time ? $this->time->getRequestTime() : time());
    $entity->setRevisionTranslationAffected(TRUE);
    return $entity;
  }

  /**
   * Whether a revision log marks a placeholder revision.
   */
  protected function isPlaceholderLog(string $log): bool {
    foreach (array_filter((array) ($this->configuration['placeholder_log_prefixes'] ?? [])) as $prefix) {
      if (str_starts_with($log, (string) $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
