<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Hook;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Page-level hooks of the front module.
 */
final class PageHooks {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * Implements hook_page_attachments().
   *
   * The module's stylesheet styles pages its blocks are not on (the taxa
   * list, the taxon page tables), so it is attached to every page; it is
   * one small file.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'taxanotes_front/front';
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'taxanotes_front_recent_columns' => [
        'variables' => ['columns' => [], 'empty' => ''],
      ],
      'taxanotes_nomenclature' => [
        'variables' => ['rows' => [], 'synonyms' => [], 'synonyms_label' => ''],
      ],
      'taxanotes_front_footer_logos' => [
        'variables' => ['logos' => []],
      ],
    ];
  }

  /**
   * Implements hook_menu_links_discovered_alter().
   *
   * The placeholder About page (/about, taxanotes_front.about) stands in
   * until an instance has its own: once the main menu holds a content link
   * titled "About ..." (the Scratchpads "About us" page, migrated with its
   * menu entry by taxanotes_d7_menu_link), the placeholder leaves the menu
   * (the page itself stays at /about). Discovered links are rebuilt on a
   * cache rebuild, which the instance scripts end with.
   */
  #[Hook('menu_links_discovered_alter')]
  public function menuLinksDiscoveredAlter(array &$links): void {
    if (!isset($links['taxanotes_front.about']) || !$this->entityTypeManager->hasDefinition('menu_link_content')) {
      return;
    }
    $about = $this->entityTypeManager->getStorage('menu_link_content')->getQuery()
      ->accessCheck(FALSE)
      ->condition('menu_name', 'main')
      ->condition('enabled', 1)
      ->condition('title', 'About', 'STARTS_WITH')
      ->range(0, 1)
      ->execute();
    if ($about) {
      unset($links['taxanotes_front.about']);
    }
  }

}
