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
 * - a placeholder (log starts with one of `placeholder_log_prefixes`: the log
 *   this migration wrote on creation, the reset log of a rolled-back history,
 *   or an earlier sync revision) is updated in place, as core does;
 * - anything else is real history: when the row changes a field value the
 *   entity is saved as a *new* default revision with the row's
 *   `sync_revision_log` (destination-only property, removed before the entity
 *   is touched), author `sync_uid` (default 1) and the current time; when
 *   nothing changed the entity is saved in place with its log kept, so a
 *   forced --update of unchanged rows adds no revision and alters no log.
 *
 * A sync revision is itself a placeholder: the revision migration lists its
 * prefix in `replace_log_prefixes`, so the D7 revision that caused the change
 * replaces it when the history is next imported, and the node's history stays
 * exactly the D7 history. Rows that create a new entity behave as in core.
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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    $instance = static::traitCreate($container, $configuration, $plugin_id, $plugin_definition, $migration);
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $property = $this->configuration['sync_log_property'] ?? 'sync_revision_log';
    $this->syncLog = $row->hasDestinationProperty($property) ? (string) $row->getDestinationProperty($property) : NULL;
    $row->removeDestinationProperty($property);
    return parent::getEntity($row, $old_destination_id_values);
  }

  /**
   * {@inheritdoc}
   */
  protected function updateEntity(EntityInterface $entity, Row $row) {
    $previous_log = $entity instanceof RevisionLogInterface ? (string) $entity->getRevisionLogMessage() : NULL;
    $entity = parent::updateEntity($entity, $row);
    if (!$entity instanceof ContentEntityInterface || !$entity instanceof RevisionLogInterface || $previous_log === NULL) {
      return $entity;
    }
    if ($this->isPlaceholderLog($previous_log)) {
      // Not real history: core behaviour (in place, log from the row).
      return $entity;
    }
    if (!$entity->hasTranslationChanges()) {
      // Nothing changed: keep the revision exactly as it is.
      $entity->setRevisionLogMessage($previous_log);
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
