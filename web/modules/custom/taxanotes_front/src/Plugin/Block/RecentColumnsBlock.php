<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The three "recent content" columns of the Scratchpads front page.
 *
 * Drupal 7 (scratchpads_front, view front_page_blocks): three blocks across
 * the page, each the newest three items of one content type as a list of
 * title, "author - date" and a trimmed body: Recent Pages (or Blog entries)
 * / Recently Added Literature / Recently Added Taxon Descriptions. Here one
 * block renders the three columns: the first column's node type is a
 * setting (page by default; a type the instance lacks, such as blog, falls
 * back to page), the second the bibcite references, the third the species
 * pages, each linking to the taxon it describes. A column without content
 * says so instead of disappearing.
 */
#[Block(
  id: 'taxanotes_front_recent',
  admin_label: new TranslatableMarkup('Recent content columns (classic front page)'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class RecentColumnsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly EntityTypeManagerInterface $entityTypeManager, private readonly DateFormatterInterface $dateFormatter) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'), $container->get('date.formatter'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['node_type' => 'page', 'items' => 3] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      if ($type->id() !== 'taxon' && $type->id() !== 'taxon_description') {
        $options[$type->id()] = $type->label();
      }
    }
    $form['node_type'] = [
      '#type' => 'select',
      '#title' => $this->t('First column'),
      '#description' => $this->t('The content type listed in the first column (the Scratchpads showed pages, or blog entries on some sites). The second column is the literature, the third the species pages.'),
      '#options' => $options,
      '#default_value' => $this->configuration['node_type'],
    ];
    $form['items'] = [
      '#type' => 'number',
      '#title' => $this->t('Items per column'),
      '#min' => 1,
      '#max' => 20,
      '#default_value' => $this->configuration['items'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['node_type'] = (string) $form_state->getValue('node_type');
    $this->configuration['items'] = (int) $form_state->getValue('items');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $metadata = new CacheableMetadata();
    $metadata->addCacheContexts(['user.permissions', 'user.node_grants:view']);
    $limit = max(1, (int) $this->configuration['items']);
    $types = $this->entityTypeManager->getStorage('node_type');

    // First column: the configured type, else page (the Scratchpads
    // default). An instance without that type (none of the pilots has pages
    // yet) still gets the column, empty.
    $type = (string) $this->configuration['node_type'];
    if ($type === '' || !$types->load($type)) {
      $type = 'page';
    }
    $columns = [];
    $type_entity = $types->load($type);
    // "Basic page" is what the Standard profile calls it; "Recent Pages"
    // is what the Scratchpads front page said.
    $label = $type_entity ? (string) $type_entity->label() : 'Page';
    $label = $label === 'Basic page' ? 'Page' : $label;
    $columns[] = [
      'heading' => $this->t('Recent @types', ['@type' => $label]),
      'items' => $type_entity ? $this->nodeItems($type, $limit, $metadata, TRUE) : [],
    ];
    $metadata->addCacheTags(['node_list:' . $type, 'config:node_type_list']);

    // Second column: the literature (bibcite references).
    if ($this->entityTypeManager->hasDefinition('bibcite_reference')) {
      $columns[] = [
        'heading' => $this->t('Recently added literature'),
        'items' => $this->referenceItems($limit, $metadata),
      ];
      $metadata->addCacheTags(['bibcite_reference_list']);
    }

    // Third column: the species pages, linked to their taxon.
    if ($types->load('taxon_description')) {
      $columns[] = [
        'heading' => $this->t('Recently added taxon descriptions'),
        'items' => $this->nodeItems('taxon_description', $limit, $metadata, FALSE),
      ];
      $metadata->addCacheTags(['node_list:taxon_description']);
    }

    $build = [
      '#theme' => 'taxanotes_front_recent_columns',
      '#columns' => $columns,
      '#empty' => $this->t('None yet.'),
      '#attached' => ['library' => ['taxanotes_front/front']],
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * The newest published nodes of a type as list items.
   */
  private function nodeItems(string $type, int $limit, CacheableMetadata $metadata, bool $with_summary): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $type)
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $metadata->addCacheableDependency($node);
      $url = $node->toUrl();
      // A species page links to the taxon it describes (its Descriptions
      // tab in the classic view), as the Scratchpads block did.
      if ($type === 'taxon_description' && $node->hasField('field_taxonomic_name') && ($taxon = $node->get('field_taxonomic_name')->entity) && $taxon->access('view')) {
        $metadata->addCacheableDependency($taxon);
        $url = Url::fromRoute('entity.node.canonical', ['node' => $taxon->id()], ['fragment' => 'descriptions']);
      }
      $items[] = [
        'title' => $node->label(),
        'url' => $url,
        'meta' => $this->meta($node),
        'summary' => $with_summary ? $this->summary($node) : '',
      ];
    }
    return $items;
  }

  /**
   * The newest published references as list items.
   */
  private function referenceItems(int $limit, CacheableMetadata $metadata): array {
    $storage = $this->entityTypeManager->getStorage('bibcite_reference');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $reference) {
      $metadata->addCacheableDependency($reference);
      $items[] = [
        'title' => $reference->label(),
        'url' => $reference->toUrl(),
        'meta' => $this->meta($reference),
        'summary' => '',
      ];
    }
    return $items;
  }

  /**
   * The "author - date" line.
   *
   * Content the import created belongs to the site's first account; its
   * Scratchpads author is not carried over, so that line is the date alone.
   */
  private function meta(EntityInterface $entity): string {
    $created = $entity->hasField('created') ? (int) $entity->get('created')->value : 0;
    $date = $created ? $this->dateFormatter->format($created, 'custom', 'Y-m-d') : '';
    $name = '';
    if ($entity instanceof EntityOwnerInterface && ($owner = $entity->getOwner()) && (int) $owner->id() > 1) {
      $name = $owner->getDisplayName();
    }
    return $name !== '' ? $name . ' - ' . $date : $date;
  }

  /**
   * The first hundred characters of a node's body, as the Scratchpads list.
   */
  private function summary(NodeInterface $node): string {
    if (!$node->hasField('body') || $node->get('body')->isEmpty()) {
      return '';
    }
    $item = $node->get('body')->first();
    $text = trim((string) ($item->summary ?: $item->value));
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    return Unicode::truncate($text, 100, TRUE, TRUE);
  }

}
