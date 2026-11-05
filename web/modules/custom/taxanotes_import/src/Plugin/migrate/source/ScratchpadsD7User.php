<?php

declare(strict_types=1);

namespace Drupal\taxanotes_import\Plugin\migrate\source;

use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;

/**
 * Scratchpads (Drupal 7) user accounts with their role names.
 *
 * One row per account from the D7 `users` table, keyed on the D7 uid (which
 * the migration keeps as the Drupal 11 uid). The anonymous account (uid 0) is
 * never a row. The root account (uid 1) is skipped by default: on every
 * Taxanotes instance uid 1 is the local administrator whose name, mail,
 * password and roles must not be replaced by the D7 site owner's (core's
 * entity:user destination only protects the password). Set `include_root:
 * true` to migrate it anyway.
 *
 * `roles` is the list of the account's D7 role names (excluding the implicit
 * anonymous/authenticated roles) as [{name: ...}, ...], for sub_process.
 *
 * Only the columns Drupal 11 keeps are selected; `pass` is the D7 phpass
 * hash, which the entity:user destination stores pre-hashed and core's
 * Password Compatibility (phpass) module verifies on login.
 */
#[MigrateSource(
  id: 'scratchpads_d7_user',
)]
final class ScratchpadsD7User extends SqlBase {

  /**
   * Role names keyed by uid, loaded once.
   *
   * @var array<int, string[]>|null
   */
  protected ?array $roles = NULL;

  /**
   * {@inheritdoc}
   */
  public function checkRequirements() {
    parent::checkRequirements();
    $schema = $this->getDatabase()->schema();
    foreach (['users', 'users_roles', 'role'] as $table) {
      if (!$schema->tableExists($table)) {
        throw new RequirementsException(sprintf('The migrate database is not a Drupal 7 database (table %s is missing).', $table));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $query = $this->select('users', 'u')
      ->fields('u', [
        'uid', 'name', 'pass', 'mail', 'created', 'access', 'login', 'status',
        'timezone', 'language', 'init',
      ])
      ->condition('u.uid', 0, '>')
      ->orderBy('u.uid');
    if ($this->getDatabase()->schema()->fieldExists('users', 'changed')) {
      $query->addField('u', 'changed');
    }
    if (empty($this->configuration['include_root'])) {
      $query->condition('u.uid', 1, '>');
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'uid' => $this->t('User ID'),
      'name' => $this->t('Username'),
      'pass' => $this->t('Password hash (phpass)'),
      'mail' => $this->t('Email address'),
      'created' => $this->t('Registered on'),
      'changed' => $this->t('Last changed (Scratchpads column)'),
      'access' => $this->t('Last access'),
      'login' => $this->t('Last login'),
      'status' => $this->t('Status (0 blocked, 1 active)'),
      'timezone' => $this->t('Time zone'),
      'language' => $this->t('Language code'),
      'init' => $this->t('Email address used at registration'),
      'roles' => $this->t('Role names: array of {name}'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    return [
      'uid' => [
        'type' => 'integer',
        'alias' => 'u',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $uid = (int) $row->getSourceProperty('uid');
    $row->setSourceProperty('roles', array_map(fn(string $name) => ['name' => $name], $this->roleNames()[$uid] ?? []));
    return parent::prepareRow($row);
  }

  /**
   * Loads the role names of every account, once.
   *
   * @return array<int, string[]>
   *   Role names keyed by uid.
   */
  protected function roleNames(): array {
    if ($this->roles !== NULL) {
      return $this->roles;
    }
    $this->roles = [];
    $query = $this->select('users_roles', 'ur')->fields('ur', ['uid']);
    $query->innerJoin('role', 'r', '[r].[rid] = [ur].[rid]');
    $query->addField('r', 'name');
    $query->orderBy('ur.uid')->orderBy('r.rid');
    foreach ($query->execute()->fetchAll() as $record) {
      $this->roles[(int) $record['uid']][] = $record['name'];
    }
    return $this->roles;
  }

}
