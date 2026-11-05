<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;

/**
 * Scratchpads (Drupal 7) biblio contributors (authors, editors, ...).
 *
 * One row per `biblio_contributor_data` row, keyed on the D7 cid, which the
 * migration keeps as the bibcite_contributor id so the reference import can
 * link authors by cid alone. Every row is migrated, also the ones no current
 * biblio revision references (biblio keeps merged duplicates as separate
 * rows with `aka`/`merge_cid` set); a contributor nothing points at is
 * harmless and keeps the count equal to D7.
 *
 * D7 stores the full name (`name`, "Sullivan, John P.") and its parts:
 * `lastname`, `firstname`, `initials` (the middle initials: "Régia Mayane
 * Pacheco" is firstname "Régia", initials "MP"), `prefix`, `suffix`. Only
 * the parts are migrated: bibcite computes the name it shows ("John
 * Sullivan") from them. A `literal` contributor ("Schulze, K.") has the
 * whole name in `lastname`.
 */
#[MigrateSource(
  id: 'scratchpads_d7_biblio_contributor',
)]
final class ScratchpadsD7BiblioContributor extends SqlBase {

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    if (!$this->getDatabase()->schema()->tableExists('biblio_contributor_data')) {
      throw new RequirementsException('The migrate database has no biblio_contributor_data table (biblio is not installed on the Drupal 7 site).');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    return $this->select('biblio_contributor_data', 'd')
      ->fields('d', [
        'cid', 'name', 'lastname', 'firstname', 'prefix', 'suffix', 'initials',
        'affiliation', 'literal', 'aka', 'drupal_uid',
      ])
      ->orderBy('d.cid');
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'cid' => $this->t('Contributor ID'),
      'name' => $this->t('Full name as biblio shows it'),
      'lastname' => $this->t('Last name (the whole name for a literal contributor)'),
      'firstname' => $this->t('First name'),
      'prefix' => $this->t('Prefix (van, de, ...)'),
      'suffix' => $this->t('Suffix (Jr., III, ...)'),
      'initials' => $this->t('Middle initials'),
      'affiliation' => $this->t('Affiliation'),
      'literal' => $this->t('1 when the name is not split into parts'),
      'aka' => $this->t('cid of the contributor this one is a duplicate of (0: none)'),
      'drupal_uid' => $this->t('Drupal 7 account of the contributor, if linked'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'cid' => [
        'type' => 'integer',
        'alias' => 'd',
      ],
    ];
  }

}
