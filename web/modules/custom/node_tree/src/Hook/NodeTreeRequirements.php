<?php

declare(strict_types=1);

namespace Drupal\node_tree\Hook;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\node_tree\NodeTree;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Status report entries: which jqTree is served, whether a bundle is set.
 *
 * Drupal 11.2+ collects hook_runtime_requirements() from here; earlier
 * versions call node_tree_requirements() in node_tree.install, which
 * delegates to ::runtime() through the class resolver. Before Drupal 11.1
 * hook classes are not services, so the class resolver builds the object
 * itself: ::create() is what makes that possible.
 */
final class NodeTreeRequirements implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(private readonly NodeTree $tree) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('node_tree'));
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirements = [];
    if (NodeTree::jqtreeIsLocal()) {
      $local = NodeTree::jqtreeLocalVersion();
      $requirements['node_tree_jqtree'] = [
        'title' => $this->t('Node tree: jqTree library'),
        'value' => $local
          ? $this->t('Served from @path (jqtree @version)', ['@path' => NodeTree::JQTREE_LOCAL_JS, '@version' => $local])
          : $this->t('Served from @path', ['@path' => NodeTree::JQTREE_LOCAL_JS]),
        'severity' => $this->severity('ok'),
      ];
      if ($local && $local !== NodeTree::JQTREE_VERSION) {
        $requirements['node_tree_jqtree']['description'] = $this->t('This release of Node tree was tested with jqtree @tested; the installed @installed may behave differently.', [
          '@tested' => NodeTree::JQTREE_VERSION,
          '@installed' => $local,
        ]);
        $requirements['node_tree_jqtree']['severity'] = $this->severity('warning');
      }
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
