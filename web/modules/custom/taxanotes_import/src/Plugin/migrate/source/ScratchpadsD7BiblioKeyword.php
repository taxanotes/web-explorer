<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Scratchpads (Drupal 7) biblio keywords.
 *
 * One row per `biblio_keyword_data` row (kid, word), keyed on the D7 kid,
 * which the migration keeps as the bibcite_keyword id.
 */
#[MigrateSource(
  id: 'scratchpads_d7_biblio_keyword',
)]
final class ScratchpadsD7BiblioKeyword extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    if (!$this->getDatabase()->schema()->tableExists('biblio_keyword_data')) {
      throw new RequirementsException('The migrate database has no biblio_keyword_data table (biblio is not installed on the Drupal 7 site).');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('biblio_keyword_data', 'k')
      ->fields('k', ['kid', 'word'])
      ->orderBy('k.kid');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'kid' => $this->t('Keyword ID'),
      'word' => $this->t('The keyword'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'kid' => [
        'type' => 'integer',
        'alias' => 'k',
      ],
    ];
  }

}
