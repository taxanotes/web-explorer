<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\destination;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\Plugin\migrate\destination\EntityRevision;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Entity revisions that can replace the current revision.
 *
 * Core's entity_revision destination always saves imported revisions as
 * non-default revisions, so a migrated history would sit *above* the revision
 * the entity import created (revision ids are chronological) while that
 * older revision stayed current. Core's deriver hard-codes the destination
 * class, so this plugin is not derived: the entity type comes from the
 * `entity_type` configuration key (default `node`). It adds:
 * - default_revision_property (default `is_default_revision`): a destination
 *   property; when it is truthy the imported revision is saved as the new
 *   default revision. The property is removed from the row before the entity
 *   is updated, so it never reaches a field. Field values not set by the
 *   row keep the entity's current values, as in core.
 * - replace_log_prefixes: when a row becomes the default revision, the
 *   revision that was default until then is deleted if its log message starts
 *   with one of these prefixes, i.e. if it was the placeholder written by the
 *   entity import rather than real history.
 * - rollback_log: a default revision cannot be deleted, so rolling such a
 *   row back resets its log to this text (author uid 1, time now) instead,
 *   which lets the next import replace it again through replace_log_prefixes.
 * - every new revision is marked revision_translation_affected, so a
 *   historic revision whose values equal the current ones still shows in the
 *   Revisions tab;
 * - a revision this migration created is deleted on rollback. Core records
 *   "preserve" for every entity_revision row because the entity already
 *   existed, which makes migrate:rollback leave the revisions in place.
 */
#[MigrateDestination(
  id: 'taxanotes_entity_revision',
)]
final class TaxanotesEntityRevision extends EntityRevision {

  /**
   * The time service (for the timestamp of a reset default revision).
   */
  protected ?TimeInterface $time = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    $entity_type = $configuration['entity_type'] ?? 'node';
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('entity_type.manager')->getStorage($entity_type),
      array_keys($container->get('entity_type.bundle.info')->getBundleInfo($entity_type)),
      $container->get('entity_field.manager'),
      $container->get('plugin.manager.field.field_type'),
      $container->get('account_switcher'),
      $container->get('entity_type.bundle.info'),
    );
    $instance->time = $container->get('datetime.time');
    return $instance;
  }

  /**
   * The revision id that was default before the entity being saved replaced it.
   */
  protected ?int $replacedRevisionId = NULL;

  /**
   * Whether the entity being imported is a revision this row creates.
   */
  protected bool $createdRevision = FALSE;

  /**
   * {@inheritdoc}
   *
   * The trait derives the entity type from the plugin id ("entity_revision:
   * node"); this plugin is not derived, so it asks the storage instead.
   */
  protected function getDefinitionFromEntity($key) {
    $definitions = $this->entityFieldManager->getBaseFieldDefinitions($this->storage->getEntityTypeId());
    $field_definition = $definitions[$key];
    return ['type' => $field_definition->getType()] + $field_definition->getSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $this->createdRevision = FALSE;
    $ids = parent::import($row, $old_destination_id_values);
    if ($this->createdRevision) {
      $this->rollbackAction = MigrateIdMapInterface::ROLLBACK_DELETE;
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    $property = $this->configuration['default_revision_property'] ?? 'is_default_revision';
    $make_default = (bool) $row->getDestinationProperty($property);
    $row->removeDestinationProperty($property);
    $this->replacedRevisionId = NULL;

    $entity = parent::getEntity($row, $old_destination_id_values);
    if (!$entity instanceof ContentEntityInterface) {
      return $entity;
    }
    if ($entity->isNewRevision()) {
      $this->createdRevision = TRUE;
      if ($make_default) {
        $entity->isDefaultRevision(TRUE);
        $this->replacedRevisionId = (int) $entity->getLoadedRevisionId();
      }
      $entity->setRevisionTranslationAffected(TRUE);
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    $ids = parent::save($entity, $old_destination_id_values);
    if ($this->replacedRevisionId && $this->replacedRevisionId != $entity->getRevisionId()) {
      $this->deletePlaceholderRevision($this->replacedRevisionId);
    }
    $this->replacedRevisionId = NULL;
    return $ids;
  }

  /**
   * Deletes a (now non-default) revision if it was only a placeholder.
   */
  protected function deletePlaceholderRevision(int $revision_id): void {
    $prefixes = array_filter((array) ($this->configuration['replace_log_prefixes'] ?? []));
    if (!$prefixes) {
      return;
    }
    $revision = $this->storage->loadRevision($revision_id);
    if (!$revision instanceof RevisionLogInterface || $revision->isDefaultRevision()) {
      return;
    }
    $log = (string) $revision->getRevisionLogMessage();
    foreach ($prefixes as $prefix) {
      if (str_starts_with($log, (string) $prefix)) {
        $this->storage->deleteRevision($revision_id);
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $revision_id = reset($destination_identifier);
    $revision = $this->storage->loadRevision($revision_id);
    if (!$revision instanceof ContentEntityInterface) {
      return;
    }
    if (!$revision->isDefaultRevision()) {
      $this->storage->deleteRevision($revision_id);
      return;
    }
    $log = $this->configuration['rollback_log'] ?? NULL;
    if ($log === NULL || !$revision instanceof RevisionLogInterface) {
      return;
    }
    $revision->setNewRevision(FALSE);
    $revision->setRevisionLogMessage((string) $log);
    $revision->setRevisionUserId(1);
    $revision->setRevisionCreationTime($this->time ? $this->time->getRequestTime() : time());
    $revision->setSyncing(TRUE);
    $revision->save();
  }

}
