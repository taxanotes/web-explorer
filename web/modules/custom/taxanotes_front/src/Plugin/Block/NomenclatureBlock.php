<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Nomenclature section of a taxon page.
 *
 * The Scratchpads taxon Overview showed, under the ITIS fields, the
 * nomenclature of the name: its synonyms and, where the site recorded them,
 * the type data. Here the block renders, as rows that continue the taxon's
 * field group:
 * - the nomenclature fields of the taxon that have a value (type material
 *   and its notes, type locality, original combination, the free-text
 *   synonym lists, synonymy and designation with their references and
 *   pages, family-level name origins, taxonomic and biological notes: the
 *   optional fields of recipes/taxanotes_taxon and taxanotes_literature,
 *   hidden from the node display because they are shown here);
 * - "Synonyms": the published child taxa whose usage is invalid (a
 *   Scratchpads synonym is a child term of the accepted name with usage
 *   "invalid"), each linked, with its authors and the reason it is
 *   unacceptable (junior synonym, homonym, misspelling ...); labelled
 *   "Synonyms in this classification" when the taxon also carries the
 *   free-text synonym lists, which name the same names.
 * A taxon with neither renders nothing, so the block (and its tab or title
 * bar) does not appear.
 */
#[Block(
  id: 'taxanotes_taxon_nomenclature',
  admin_label: new TranslatableMarkup('Nomenclature (taxon page)'),
  category: new TranslatableMarkup('Taxanotes'),
  context_definitions: [
    'node' => new EntityContextDefinition('entity:node', new TranslatableMarkup('Taxon')),
  ],
)]
final class NomenclatureBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The nomenclature fields, in the order they are shown.
   *
   * A field an instance lacks (the two references exist only with the
   * literature recipe) or that is empty is skipped.
   */
  public const FIELDS = [
    'field_type_material',
    'field_type_material_notes',
    'field_type_locality',
    'field_original_combination',
    'field_synonyms_homotypic',
    'field_synonyms_heterotypic',
    'field_synonymy_by',
    'field_synonymy_reference',
    'field_page_number_synonymy',
    'field_designation_by',
    'field_designation_reference',
    'field_page_number_designation',
    'field_family_level_name_origins',
    'field_taxonomic_notes',
    'field_biological_notes',
  ];

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $node = $this->getContextValue('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'taxon') {
      return [];
    }
    $metadata = new CacheableMetadata();
    $metadata->addCacheableDependency($node);
    $metadata->addCacheContexts(['user.permissions', 'user.node_grants:view']);
    // The list of synonyms changes when a child taxon is added, edited or
    // deleted: the node list tag of the type covers all three.
    $metadata->addCacheTags(['node_list:taxon']);

    $rows = [];
    foreach (self::FIELDS as $name) {
      if ($node->hasField($name) && !$node->get($name)->isEmpty() && $node->get($name)->access('view')) {
        $rows[$name] = $node->get($name)->view(['label' => 'inline']);
      }
    }

    $synonyms = [];
    foreach ($this->synonyms($node) as $synonym) {
      $metadata->addCacheableDependency($synonym);
      $synonyms[] = [
        'title' => $synonym->label(),
        'url' => $synonym->toUrl(),
        'authors' => $synonym->hasField('field_authors') ? (string) $synonym->get('field_authors')->value : '',
        'reason' => $this->reason($synonym),
      ];
    }

    if (!$rows && !$synonyms) {
      $build = [];
      $metadata->applyTo($build);
      return $build;
    }
    // A vocabulary that keeps its own synonym lists as text (Mormyrids:
    // the homotypic and heterotypic rows above) names the same names
    // again in the derived list; its label then says where that list
    // comes from.
    $text_synonyms = isset($rows['field_synonyms_homotypic']) || isset($rows['field_synonyms_heterotypic']);
    $build = [
      '#theme' => 'taxanotes_nomenclature',
      '#rows' => $rows,
      '#synonyms' => $synonyms,
      '#synonyms_label' => $text_synonyms ? $this->t('Synonyms in this classification') : $this->t('Synonyms'),
      '#attached' => ['library' => ['taxanotes_front/front']],
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * The unacceptability reason of a synonym, as the list field's label.
   */
  private function reason(NodeInterface $synonym): string {
    if (!$synonym->hasField('field_unacceptability_reason') || $synonym->get('field_unacceptability_reason')->isEmpty()) {
      return '';
    }
    $items = $synonym->get('field_unacceptability_reason');
    $value = (string) $items->value;
    $provider = $items->getFieldDefinition()->getFieldStorageDefinition()->getOptionsProvider('value', $synonym);
    $options = $provider ? $provider->getPossibleOptions() : [];
    return (string) ($options[$value] ?? $value);
  }

  /**
   * The published child taxa of a taxon whose usage is invalid.
   *
   * In sibling order (weight, then title), the order of the tree.
   *
   * @return \Drupal\node\NodeInterface[]
   *   The synonyms the current user may view.
   */
  private function synonyms(NodeInterface $node): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'taxon')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_parent_taxon_reference', $node->id())
      ->condition('field_usage', 'invalid')
      ->sort('field_weight')
      ->sort('title')
      ->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

}
