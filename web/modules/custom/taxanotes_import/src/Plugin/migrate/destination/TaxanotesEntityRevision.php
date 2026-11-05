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
 * - a revision this migration created is deleted on rollback (and stays so
 *   when a later run updates it in place). Core records "preserve" for every
 *   entity_revision row because the entity already existed, which makes
 *   migrate:rollback leave the revisions in place.
 *
 * Process pipelines feeding this destination should map whole fields
 * (`field_x: item`), not sub-properties (`field_x/uri: value`): when a
 * sub-property's pipeline yields NULL, core records the *sub-property* as the
 * empty destination and EntityContentBase::updateEntity() then assigns NULL
 * to a property named "field_x/uri", which is not a field, so the revision
 * silently keeps the value of the revision it was loaded from (the current
 * one). A whole-field NULL clears the field.
 */
#[MigrateDestination(
  id: 'taxanotes_entity_revision',
)]
final class TaxanotesEntityRevision extends EntityRevision {

  use NonDerivedEntityDestinationTrait {
    create as traitCreate;
  }

  /**
   * The time service (for the timestamp of a reset default revision).
   */
  protected ?TimeInterface $time = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    $instance = static::traitCreate($container, $configuration, $plugin_id, $plugin_definition, $migration);
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
   * A revision this migration created is deleted on rollback, whether this
   * run created it or updates it in place (a row that reaches the update path
   * has a map row, i.e. was created by an earlier run).
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $ids = parent::import($row, $old_destination_id_values);
    if ($this->createdRevision || $old_destination_id_values) {
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
    $this->createdRevision = FALSE;

    $entity = parent::getEntity($row, $old_destination_id_values);
    if ($entity instanceof ContentEntityInterface && $entity->isNewRevision()) {
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
    $storage = $this->revisionableStorage();
    $revision = $storage->loadRevision($revision_id);
    if (!$revision instanceof RevisionLogInterface || $revision->isDefaultRevision()) {
      return;
    }
    $log = (string) $revision->getRevisionLogMessage();
    foreach ($prefixes as $prefix) {
      if (str_starts_with($log, (string) $prefix)) {
        $storage->deleteRevision($revision_id);
        return;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * A revision that no longer exists (for example after a rollback of the
   * entity migration deleted the entity) is simply skipped.
   */
  public function rollback(array $destination_identifier) {
    $revision_id = reset($destination_identifier);
    $storage = $this->revisionableStorage();
    $revision = $storage->loadRevision($revision_id);
    if (!$revision instanceof ContentEntityInterface) {
      return;
    }
    if (!$revision->isDefaultRevision()) {
      $storage->deleteRevision($revision_id);
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
