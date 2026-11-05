<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\process;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\taxanotes_import\Plugin\migrate\source\ScratchpadsD7Taxon;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Node status for a D7 term: 0 when it sits under an unpublished root.
 *
 * Takes the source term ID and asks the scratchpads_d7_taxon source plugin
 * whether the term is, or descends from, one of its `unpublished_roots`
 * (default "Deleted taxa"). Everything else is published.
 *
 * Example:
 * @code
 * status:
 *   plugin: scratchpads_published_status
 *   source: tid
 * @endcode
 */
#[MigrateProcess('scratchpads_published_status')]
class ScratchpadsPublishedStatus extends ProcessPluginBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ?MigrationInterface $migration = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static($configuration, $plugin_id, $plugin_definition, $migration);
  }

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $source = $this->migration?->getSourcePlugin();
    if ($source instanceof ScratchpadsD7Taxon && $source->isUnderUnpublishedRoot((int) $value)) {
      return 0;
    }
    return 1;
  }

}
