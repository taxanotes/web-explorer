<?php

declare(strict_types=1);

namespace Drupal\taxanotes_literature\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the Scratchpads inline citations, [bib]nid[/bib].
 *
 * Scratchpads texts (species page sections above all) cite the literature
 * with the biblio module's filter markup: "Diagnosis from Chagas-Jr. (2013:
 * 21)[bib]1446[/bib]", where 1446 is the nid of the biblio node. The biblio
 * filter replaced the tag with a numbered link, "[1]", and printed the
 * cited references under the text as a numbered list. This filter does the
 * same on the migrated text: the nid is looked up in the references'
 * field_scratchpads_nid (the migration keeps it), the tag becomes a numbered
 * link to the reference page, and the references cited by the text are
 * listed under it, in the order of their first citation. A nid that no
 * reference carries is left as it is, so the gap can be seen and fixed.
 *
 * The filter runs after the HTML restrictions (weight 10, filter_html is
 * -10), so the markup it adds is not stripped; it is a transform, not a
 * markup language, so CKEditor 5 accepts the format that carries it.
 */
#[Filter(
  id: 'taxanotes_citation',
  title: new TranslatableMarkup('Scratchpads inline citations'),
  description: new TranslatableMarkup('Replaces [bib]<em>nid</em>[/bib] (the Scratchpads biblio node id) with a numbered link to the reference and lists the cited references under the text.'),
  type: FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
  weight: 10,
)]
final class FilterCitation extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The citation markup: [bib]1446[/bib], whitespace tolerated as in D7.
   */
  private const PATTERN = '/\[bib\]\s*(\d+)\s*\[\/bib\]/';

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);
    if (!preg_match_all(self::PATTERN, $text, $matches)) {
      return $result;
    }
    $nids = array_values(array_unique(array_map('intval', $matches[1])));
    $references = $this->references($nids);
    // The rendered output depends on which references exist and on their
    // titles: any change to a reference, and a reference imported later for
    // a nid that is missing now, invalidate it.
    $result->addCacheTags(['bibcite_reference_list']);
    foreach ($references as $reference) {
      $result->addCacheableDependency($reference);
    }
    if (!$references) {
      return $result;
    }

    // Numbered in the order of first citation; a tag whose nid has no
    // reference stays as it is.
    $numbers = [];
    $text = preg_replace_callback(self::PATTERN, function (array $m) use (&$numbers, $references): string {
      $nid = (int) $m[1];
      if (!isset($references[$nid])) {
        return $m[0];
      }
      if (!isset($numbers[$nid])) {
        $numbers[$nid] = count($numbers) + 1;
      }
      $reference = $references[$nid];
      return '<a href="' . Html::escape($reference->toUrl()->toString()) . '" class="taxanotes-citation" title="' . Html::escape($reference->label()) . '">[' . $numbers[$nid] . ']</a>';
    }, $text);

    $items = '';
    foreach ($numbers as $nid => $number) {
      $reference = $references[$nid];
      $authors = [];
      if ($reference->hasField('author')) {
        foreach ($reference->get('author') as $item) {
          if ($item->entity) {
            $authors[] = $item->entity->label();
          }
        }
      }
      $year = $reference->hasField('bibcite_year') ? $reference->get('bibcite_year')->value : NULL;
      $line = '';
      if ($authors) {
        $line .= Html::escape(implode(', ', $authors)) . ' ';
      }
      if ($year) {
        $line .= '(' . Html::escape((string) $year) . ') ';
      }
      $line .= '<a href="' . Html::escape($reference->toUrl()->toString()) . '">' . Html::escape($reference->label()) . '</a>';
      $items .= '<li value="' . $number . '">' . $line . '</li>';
    }
    $text .= '<ol class="taxanotes-citations">' . $items . '</ol>';

    $result->setProcessedText($text);
    return $result;
  }

  /**
   * The references carrying these Scratchpads nids, keyed by nid.
   *
   * @param int[] $nids
   *   The Scratchpads biblio node ids.
   *
   * @return \Drupal\bibcite_entity\Entity\ReferenceInterface[]
   *   The references found, keyed by nid (the first one when a nid is on
   *   several).
   */
  private function references(array $nids): array {
    $storage = $this->entityTypeManager->getStorage('bibcite_reference');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_scratchpads_nid', $nids, 'IN')
      ->sort('id')
      ->execute();
    $references = [];
    foreach ($storage->loadMultiple($ids) as $reference) {
      $nid = (int) $reference->get('field_scratchpads_nid')->value;
      $references[$nid] ??= $reference;
    }
    return $references;
  }

  /**
   * {@inheritdoc}
   */
  public function tips($long = FALSE) {
    return $this->t('Cite a reference with [bib]<em>nid</em>[/bib], where <em>nid</em> is the Scratchpads node id of the reference: it becomes a numbered link, and the cited references are listed under the text.');
  }

}
