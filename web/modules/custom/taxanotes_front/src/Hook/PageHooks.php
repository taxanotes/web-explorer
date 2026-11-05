<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Page-level hooks of the front module.
 */
final class PageHooks {

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

}
