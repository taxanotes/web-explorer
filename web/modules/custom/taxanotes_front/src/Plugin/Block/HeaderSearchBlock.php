<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * The Scratchpads header search: a text box with an "All / Taxonomy" choice.
 *
 * A plain GET form to the taxanotes_front.search route, which sends
 * "Taxonomy" to the taxa list (/taxa?name=…) and "All" to a results page
 * across taxa, literature, species pages and pages; no JavaScript, no
 * search index. On a results page (/find?q=, /taxa?name=) the box keeps
 * the terms, as the Scratchpads box did.
 */
#[Block(
  id: 'taxanotes_front_header_search',
  admin_label: new TranslatableMarkup('Header search (All / Taxonomy)'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class HeaderSearchBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly RequestStack $requestStack) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('request_stack'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $q = trim((string) ($request?->query->get('q') ?? $request?->query->get('name') ?? ''));
    $taxonomy = $request && ($request->query->has('name') || $request->query->get('facet') === 'taxonomy');
    return [
      'form' => [
        '#type' => 'inline_template',
        '#template' => '<form class="taxanotes-header-search" method="get" action="{{ action }}" role="search">'
        . '<div class="taxanotes-header-search__row">'
        . '<label for="taxanotes-header-search-q" class="visually-hidden">{{ "Search"|t }}</label>'
        . '<input id="taxanotes-header-search-q" class="taxanotes-header-search__input form-text" type="search" name="q" placeholder="{{ "Search..."|t }}" title="{{ "Enter the terms you wish to search for."|t }}" value="{{ q }}">'
        . '<button type="submit" class="form-submit">{{ "Search"|t }}</button>'
        . '</div>'
        . '<div class="taxanotes-header-search__facets">'
        . '<label><input type="radio" name="facet" value="all"{{ taxonomy ? "" : " checked" }}> {{ "All"|t }}</label>'
        . '<label><input type="radio" name="facet" value="taxonomy"{{ taxonomy ? " checked" : "" }}> {{ "Taxonomy"|t }}</label>'
        . '</div>'
        . '</form>',
        '#context' => [
          'action' => Url::fromRoute('taxanotes_front.search')->toString(),
          'q' => $q,
          'taxonomy' => $taxonomy,
        ],
      ],
      '#attached' => ['library' => ['taxanotes_front/front']],
      '#cache' => ['contexts' => ['url.site', 'url.query_args:q', 'url.query_args:name', 'url.query_args:facet']],
    ];
  }

}
