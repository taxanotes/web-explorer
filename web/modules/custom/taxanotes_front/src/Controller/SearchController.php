<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The header search target: "Taxonomy" goes to the taxa list, "All" here.
 *
 * The Scratchpads header search box offered "All" (the site's Solr index)
 * and "Taxonomy" (the vocabulary). There is no search index here: "All" is
 * a results page of the names and titles that contain the terms, by kind
 * (taxa, literature, species pages, pages), each with a link to the full
 * filtered list.
 */
final class SearchController extends ControllerBase {

  /**
   * How many hits each section shows.
   */
  private const LIMIT = 10;

  public function __construct(private readonly RouteProviderInterface $routeProvider) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('router.route_provider'));
  }

  /**
   * The results page, or the redirect to the taxa list.
   */
  public function find(Request $request): array|RedirectResponse {
    $q = trim((string) $request->query->get('q', ''));
    $facet = (string) $request->query->get('facet', 'all');
    if ($facet === 'taxonomy' || $q === '') {
      return new RedirectResponse(Url::fromRoute('view.taxa.page_1', [], ['query' => $q === '' ? [] : ['name' => $q]])->toString());
    }

    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts([
      'url.query_args:q',
      'url.query_args:facet',
      'user.permissions',
      'user.node_grants:view',
    ]);
    $sections = [];

    $sections[] = $this->nodeSection($this->t('Taxa'), 'taxon', $q, $metadata, 'view.taxa.page_1', 'name');
    if ($this->entityTypeManager()->hasDefinition('bibcite_reference')) {
      $sections[] = $this->referenceSection($q, $metadata);
    }
    if ($this->entityTypeManager()->getStorage('node_type')->load('taxon_description')) {
      $sections[] = $this->nodeSection($this->t('Species pages'), 'taxon_description', $q, $metadata, 'view.taxanotes_species.page', 'title');
    }
    if ($this->entityTypeManager()->getStorage('node_type')->load('page')) {
      $sections[] = $this->nodeSection($this->t('Pages'), 'page', $q, $metadata, NULL, NULL);
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['taxanotes-search-results']],
      '#attached' => ['library' => ['taxanotes_front/front']],
    ];
    $any = FALSE;
    foreach ($sections as $i => $section) {
      if (!$section['items']) {
        continue;
      }
      $any = TRUE;
      $build['section_' . $i] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['taxanotes-search-results__section']],
        'heading' => ['#markup' => '<h2>' . $section['heading'] . '</h2>'],
        'list' => [
          '#theme' => 'item_list',
          '#items' => $section['items'],
        ],
      ];
      if ($section['more']) {
        $build['section_' . $i]['more'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['taxanotes-search-results__more']],
          'link' => $section['more']->toRenderable(),
        ];
      }
    }
    if (!$any) {
      $build['empty'] = ['#markup' => '<p>' . $this->t('Nothing matches “@q”.', ['@q' => $q]) . '</p>'];
    }
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * The page title.
   */
  public function title(Request $request): string {
    $q = trim((string) $request->query->get('q', ''));
    return (string) $this->t('Search results for “@q”', ['@q' => $q]);
  }

  /**
   * Published nodes of a type whose title contains the terms.
   */
  private function nodeSection(string|\Stringable $heading, string $type, string $q, CacheableMetadata $metadata, ?string $more_route, ?string $more_param): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $type)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('title', $q, 'CONTAINS')
      ->sort('title')
      ->range(0, self::LIMIT);
    $count_query = clone $query;
    $ids = $query->execute();
    $metadata->addCacheTags(['node_list:' . $type]);
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $metadata->addCacheableDependency($node);
      $items[] = $node->toLink()->toRenderable();
    }
    $more = NULL;
    if ($items && $more_route && $this->routeProvider->getRoutesByNames([$more_route])) {
      $total = (int) $count_query->range()->count()->execute();
      if ($total > count($items)) {
        $text = $this->t('All @count matching @kind', [
          '@count' => $total,
          '@kind' => strtolower((string) $heading),
        ]);
        $more = Link::createFromRoute($text, $more_route, [], ['query' => [$more_param => $q]]);
      }
    }
    return ['heading' => $heading, 'items' => $items, 'more' => $more];
  }

  /**
   * Published references whose title contains the terms.
   */
  private function referenceSection(string $q, CacheableMetadata $metadata): array {
    $storage = $this->entityTypeManager()->getStorage('bibcite_reference');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->condition('title', $q, 'CONTAINS')
      ->sort('title')
      ->range(0, self::LIMIT);
    $count_query = clone $query;
    $ids = $query->execute();
    $metadata->addCacheTags(['bibcite_reference_list']);
    $items = [];
    foreach ($storage->loadMultiple($ids) as $reference) {
      $metadata->addCacheableDependency($reference);
      $items[] = $reference->toLink()->toRenderable();
    }
    $more = NULL;
    if ($items && $this->routeProvider->getRoutesByNames(['view.taxanotes_literature.page'])) {
      $total = (int) $count_query->range()->count()->execute();
      if ($total > count($items)) {
        $more = Link::createFromRoute($this->t('All @count matching references', ['@count' => $total]), 'view.taxanotes_literature.page', [], ['query' => ['title' => $q]]);
      }
    }
    return ['heading' => $this->t('Literature'), 'items' => $items, 'more' => $more];
  }

}
