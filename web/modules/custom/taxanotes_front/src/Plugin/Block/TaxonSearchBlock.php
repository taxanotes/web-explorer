<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * A one-field search that lands on the taxa list (view taxa, path /taxa).
 *
 * A plain GET form: it submits to the view's page, whose exposed "name
 * contains" filter is the `name` query argument, so it works from any page
 * and without JavaScript.
 */
#[Block(
  id: 'taxanotes_front_search',
  admin_label: new TranslatableMarkup('Taxon search'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class TaxonSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $url = Url::fromRoute('view.taxa.page_1');
    return [
      'form' => [
        '#type' => 'inline_template',
        '#template' => '<form class="taxanotes-search" method="get" action="{{ action }}" role="search">'
        . '<label for="taxanotes-search-name" class="visually-hidden">{{ "Taxon name"|t }}</label>'
        . '<input id="taxanotes-search-name" class="taxanotes-search__input form-element form-element--type-search form-element--api-textfield" type="search" name="name" placeholder="{{ "Name contains…"|t }}" value="">'
        . '<button type="submit" class="button button--primary">{{ "Search"|t }}</button>'
        . '</form>',
        '#context' => ['action' => $url->toString()],
      ],
      '#attached' => ['library' => ['taxanotes_front/front']],
      '#cache' => ['contexts' => ['url.site']],
    ];
  }

}
