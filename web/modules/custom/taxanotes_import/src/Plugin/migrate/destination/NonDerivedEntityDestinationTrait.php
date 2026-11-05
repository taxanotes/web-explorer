<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\destination;

use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared plumbing of the module's non-derived entity destinations.
 *
 * Core's entity destinations are derived ("entity:node", "entity_revision:
 * node") and the deriver hard-codes the class, so a subclass has to be its
 * own, non-derived plugin that reads the entity type from the `entity_type`
 * configuration key (default `node`). Core's EntityFieldDefinitionTrait
 * derives the entity type from the plugin id, which fails for such a plugin
 * ("The "" entity type does not exist"); getDefinitionFromEntity() asks the
 * storage instead.
 */
trait NonDerivedEntityDestinationTrait {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    $entity_type = $configuration['entity_type'] ?? 'node';
    return new static(
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
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefinitionFromEntity($key) {
    $definitions = $this->entityFieldManager->getBaseFieldDefinitions($this->storage->getEntityTypeId());
    $field_definition = $definitions[$key];
    return ['type' => $field_definition->getType()] + $field_definition->getSettings();
  }

  /**
   * The entity storage, typed as revisionable.
   *
   * Both destinations only make sense for revisionable entity types; the
   * assertion documents that and keeps static analysis honest.
   */
  protected function revisionableStorage(): RevisionableStorageInterface {
    assert($this->storage instanceof RevisionableStorageInterface);
    return $this->storage;
  }

}
