<?php

declare(strict_types=1);

namespace Drupal\node_tree\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The node types, fields and path the tree is built from.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected RouteBuilderInterface $routeBuilder,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager'),
      $container->get('router.builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'node_tree_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['node_tree.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('node_tree.settings');
    $bundle_options = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $bundle => $info) {
      $bundle_options[$bundle] = $info['label'];
    }
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Node types'),
      '#description' => $this->t('The node types whose nodes form the tree. Each must carry the parent field (and the identifier, parent identifier and weight fields when they are named below).'),
      '#options' => $bundle_options,
      '#default_value' => (array) $config->get('bundles'),
    ];
    $form['parent_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent field'),
      '#description' => $this->t('The entity reference field (to a node) that names the parent of a node. A node without a value is a root.'),
      '#default_value' => $config->get('parent_field'),
      '#required' => TRUE,
      '#pattern' => '[a-z0-9_]+',
    ];
    $form['id_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Identifier field'),
      '#description' => $this->t('What identifies a node in the tree URLs and JSON: <code>uuid</code>, or a field with one stable value per node (a GUID, a code).'),
      '#default_value' => $config->get('id_field') ?: 'uuid',
      '#required' => TRUE,
      '#pattern' => '[a-z0-9_]+',
    ];
    $form['parent_id_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Parent identifier field'),
      '#description' => $this->t('Optional: a field on the child that holds the identifier of its parent. The children of a node are then found through this field alone, and <code>drush node_tree:link-parents</code> fills the parent field from it (after an import that carries identifiers but no references).'),
      '#default_value' => $config->get('parent_id_field'),
      '#pattern' => '[a-z0-9_]*',
    ];
    $form['weight_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Weight field'),
      '#description' => $this->t('Optional: an integer field that orders siblings (lowest first, then by title).'),
      '#default_value' => $config->get('weight_field'),
      '#pattern' => '[a-z0-9_]*',
    ];
    $form['describes_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Describes field'),
      '#description' => $this->t('Optional: an entity reference field on other node types that points at the tree node a page is about, so the page gets that node’s lineage as its breadcrumb.'),
      '#default_value' => $config->get('describes_field'),
      '#pattern' => '[a-z0-9_]*',
    ];
    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tree page path'),
      '#description' => $this->t('The path of the tree page, without a leading slash; a node’s permalink is <code>&lt;path&gt;/node/&lt;identifier&gt;</code>.'),
      '#default_value' => $config->get('path'),
      '#required' => TRUE,
      '#field_prefix' => '/',
    ];
    $form['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tree page title'),
      '#default_value' => $config->get('title'),
      '#required' => TRUE,
    ];
    $form['breadcrumb'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Lineage breadcrumb'),
      '#description' => $this->t('Replace the breadcrumb of a tree node’s page (and of the pages that describe one) with Home › root › … › parent › the node.'),
      '#default_value' => (bool) $config->get('breadcrumb'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $bundles = array_values(array_filter($form_state->getValue('bundles')));
    $storages = $this->entityFieldManager->getFieldStorageDefinitions('node');
    foreach (['parent_field', 'id_field', 'parent_id_field', 'weight_field'] as $key) {
      $field = trim((string) $form_state->getValue($key));
      $form_state->setValue($key, $field);
      if ($field === '') {
        continue;
      }
      if ($key === 'id_field' && $field === 'uuid') {
        continue;
      }
      if (!isset($storages[$field])) {
        $form_state->setErrorByName($key, $this->t('There is no node field named %field.', ['%field' => $field]));
        continue;
      }
      foreach ($bundles as $bundle) {
        if (!isset($this->entityFieldManager->getFieldDefinitions('node', $bundle)[$field])) {
          $form_state->setErrorByName($key, $this->t('The node type %bundle has no field %field.', [
            '%bundle' => $bundle,
            '%field' => $field,
          ]));
        }
      }
    }
    $parent = trim((string) $form_state->getValue('parent_field'));
    if (isset($storages[$parent]) && ($storages[$parent]->getType() !== 'entity_reference' || $storages[$parent]->getSetting('target_type') !== 'node')) {
      $form_state->setErrorByName('parent_field', $this->t('%field must be an entity reference field to nodes.', ['%field' => $parent]));
    }
    $describes = trim((string) $form_state->getValue('describes_field'));
    $form_state->setValue('describes_field', $describes);
    if ($describes !== '' && (!isset($storages[$describes]) || $storages[$describes]->getType() !== 'entity_reference' || $storages[$describes]->getSetting('target_type') !== 'node')) {
      $form_state->setErrorByName('describes_field', $this->t('%field must be an entity reference field to nodes.', ['%field' => $describes]));
    }
    $path = trim((string) $form_state->getValue('path'), "/ \t\n\r");
    $form_state->setValue('path', $path);
    if ($path === '' || preg_match('/\s|[?#]/', $path)) {
      $form_state->setErrorByName('path', $this->t('The path must be one or more path segments without spaces, ? or #.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('node_tree.settings')
      ->set('bundles', array_values(array_filter($form_state->getValue('bundles'))))
      ->set('parent_field', $form_state->getValue('parent_field'))
      ->set('id_field', $form_state->getValue('id_field') ?: 'uuid')
      ->set('parent_id_field', $form_state->getValue('parent_id_field'))
      ->set('weight_field', $form_state->getValue('weight_field'))
      ->set('describes_field', $form_state->getValue('describes_field'))
      ->set('path', $form_state->getValue('path'))
      ->set('title', trim((string) $form_state->getValue('title')))
      ->set('breadcrumb', (bool) $form_state->getValue('breadcrumb'))
      ->save();
    // The tree page's path is part of the routes: rebuild them now, so the
    // new path works on the next request rather than after the next cache
    // rebuild.
    $this->routeBuilder->rebuild();
    parent::submitForm($form, $form_state);
  }

}
