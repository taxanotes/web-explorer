<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The instance in numbers: published taxa in all, and per main rank.
 */
#[Block(
  id: 'taxanotes_front_summary',
  admin_label: new TranslatableMarkup('Taxon summary'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class TaxonSummaryBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Ranks shown, in this order, when the instance has taxa of that rank.
   */
  private const RANKS = ['Order', 'Family', 'Genus', 'Species', 'Subspecies'];

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly Connection $database) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('database'));
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $query = $this->database->select('node_field_data', 'n');
    // One row per node (the default translation), the rank of that
    // translation, deleted field rows (a field being purged) left out.
    $query->leftJoin('node__field_rank', 'r', 'r.entity_id = n.nid AND r.langcode = n.langcode AND r.deleted = 0');
    $query->addExpression('COUNT(n.nid)', 'c');
    $query->addField('r', 'field_rank_value', 'rank');
    $query->condition('n.type', 'taxon')
      ->condition('n.status', NodeInterface::PUBLISHED)
      ->condition('n.default_langcode', 1)
      ->groupBy('r.field_rank_value')
      ->addTag('node_access');
    $counts = [];
    $total = 0;
    foreach ($query->execute() as $row) {
      $counts[(string) $row->rank] = (int) $row->c;
      $total += (int) $row->c;
    }

    $items = [];
    $items[] = $this->item($total, $this->formatPlural($total, 'taxon', 'taxa'));
    foreach (self::RANKS as $rank) {
      if (!empty($counts[$rank])) {
        $items[] = $this->item($counts[$rank], $this->rankLabel($rank, $counts[$rank]));
      }
    }

    return [
      'counts' => [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => ['class' => ['taxanotes-summary']],
      ],
      'links' => [
        '#theme' => 'item_list',
        '#items' => [
          Link::fromTextAndUrl($this->t('Browse the classification'), Url::fromRoute('node_tree.page'))->toRenderable(),
          Link::fromTextAndUrl($this->t('Search all taxa'), Url::fromRoute('view.taxa.page_1'))->toRenderable(),
        ],
        '#attributes' => ['class' => ['taxanotes-summary__links']],
      ],
      '#attached' => ['library' => ['taxanotes_front/front']],
      '#cache' => ['tags' => ['node_list:taxon'], 'contexts' => ['user.node_grants:view']],
    ];
  }

  /**
   * One tile: the count and its label.
   */
  private function item(int $count, TranslatableMarkup|string $label): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<span class="taxanotes-summary__count">{{ count }}</span> {{ label }}',
      '#context' => ['count' => number_format($count), 'label' => $label],
    ];
  }

  /**
   * The plural name of a rank.
   */
  private function rankLabel(string $rank, int $count): TranslatableMarkup {
    return match ($rank) {
      'Order' => $this->formatPlural($count, 'order', 'orders'),
      'Family' => $this->formatPlural($count, 'family', 'families'),
      'Genus' => $this->formatPlural($count, 'genus', 'genera'),
      'Species' => $this->formatPlural($count, 'species', 'species'),
      'Subspecies' => $this->formatPlural($count, 'subspecies', 'subspecies'),
      default => $this->t('@rank', ['@rank' => strtolower($rank)]),
    };
  }

}
