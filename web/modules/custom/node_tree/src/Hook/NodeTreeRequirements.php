<?php

declare(strict_types=1);

namespace Drupal\node_tree\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node_tree\NodeTree;

/**
 * Status report entries: which jqTree is served, whether a bundle is set.
 *
 * Drupal 11.2+ collects hook_runtime_requirements() from here; earlier
 * versions call node_tree_requirements() in node_tree.install, which
 * delegates to ::runtime().
 */
final class NodeTreeRequirements {

  use StringTranslationTrait;

  public function __construct(private readonly NodeTree $tree) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];
    if (NodeTree::jqtreeIsLocal()) {
      $requirements['node_tree_jqtree'] = [
        'title' => $this->t('Node tree: jqTree library'),
        'value' => $this->t('Served from @path', ['@path' => NodeTree::JQTREE_LOCAL_JS]),
        'severity' => $this->severity('ok'),
      ];
    }
    else {
      $requirements['node_tree_jqtree'] = [
        'title' => $this->t('Node tree: jqTree library'),
        'value' => $this->t('Loaded from the jsDelivr CDN (jqtree @version)', ['@version' => NodeTree::JQTREE_VERSION]),
        'description' => $this->t('To serve it yourself, put tree.jquery.js and jqtree.css of the jqtree npm package (version @version) in @dir under the Drupal root.', [
          '@version' => NodeTree::JQTREE_VERSION,
          '@dir' => dirname(NodeTree::JQTREE_LOCAL_JS),
        ]),
        'severity' => $this->severity('warning'),
      ];
    }
    $bundles = $this->tree->bundles();
    $requirements['node_tree_settings'] = [
      'title' => $this->t('Node tree: node types'),
      'value' => $bundles
        ? implode(', ', $bundles)
        : $this->t('None configured: the tree is empty until a node type is chosen on the <a href=":url">settings page</a>.', [':url' => Url::fromRoute('node_tree.settings')->toString()]),
      'severity' => $this->severity($bundles ? 'ok' : 'warning'),
    ];
    return $requirements;
  }

  /**
   * A requirement severity for this Drupal version.
   *
   * The RequirementSeverity enum exists from Drupal 11.2; before it, the
   * REQUIREMENT_* constants.
   */
  private function severity(string $level): mixed {
    if (class_exists(RequirementSeverity::class)) {
      return $level === 'warning' ? RequirementSeverity::Warning : RequirementSeverity::OK;
    }
    return $level === 'warning' ? REQUIREMENT_WARNING : REQUIREMENT_OK;
  }

}
