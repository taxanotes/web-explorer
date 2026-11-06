<?php

declare(strict_types=1);

namespace Drupal\node_tree\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Routing\RouteBuilderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rebuilds the routes when the settings change (the tree page path).
 *
 * Covers every way the settings are saved: the settings form, a recipe's
 * config action, drush config:set, a config import.
 */
final class ConfigSubscriber implements EventSubscriberInterface {

  public function __construct(private readonly RouteBuilderInterface $routeBuilder) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigChange',
      ConfigEvents::DELETE => 'onConfigChange',
    ];
  }

  /**
   * Marks the router for a rebuild after node_tree.settings is saved.
   */
  public function onConfigChange(ConfigCrudEvent $event): void {
    if ($event->getConfig()->getName() === 'node_tree.settings') {
      $this->routeBuilder->setRebuildNeeded();
    }
  }

}
