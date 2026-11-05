<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * The Scratchpads header search: a text box with an "All / Taxonomy" choice.
 *
 * A plain GET form to the taxanotes_front.search route, which sends
 * "Taxonomy" to the taxa list (/taxa?name=…) and "All" to a results page
 * across taxa, literature, species pages and pages; no JavaScript, no
 * search index.
 */
#[Block(
  id: 'taxanotes_front_header_search',
  admin_label: new TranslatableMarkup('Header search (All / Taxonomy)'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class HeaderSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      'form' => [
        '#type' => 'inline_template',
        '#template' => '<form class="taxanotes-header-search" method="get" action="{{ action }}" role="search">'
        . '<div class="taxanotes-header-search__row">'
        . '<label for="taxanotes-header-search-q" class="visually-hidden">{{ "Search"|t }}</label>'
        . '<input id="taxanotes-header-search-q" class="taxanotes-header-search__input form-text" type="search" name="q" placeholder="{{ "Search..."|t }}" title="{{ "Enter the terms you wish to search for."|t }}" value="">'
        . '<button type="submit" class="form-submit">{{ "Search"|t }}</button>'
        . '</div>'
        . '<div class="taxanotes-header-search__facets">'
        . '<label><input type="radio" name="facet" value="all" checked> {{ "All"|t }}</label>'
        . '<label><input type="radio" name="facet" value="taxonomy"> {{ "Taxonomy"|t }}</label>'
        . '</div>'
        . '</form>',
        '#context' => ['action' => Url::fromRoute('taxanotes_front.search')->toString()],
      ],
      '#attached' => ['library' => ['taxanotes_front/front']],
      '#cache' => ['contexts' => ['url.site']],
    ];
  }

}
