<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows one of the instance texts of taxanotes_front.settings.
 */
#[Block(
  id: 'taxanotes_front_text',
  admin_label: new TranslatableMarkup('Instance text'),
  category: new TranslatableMarkup('Taxanotes'),
)]
final class InstanceTextBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, string $plugin_id, mixed $plugin_definition, private readonly ConfigFactoryInterface $configFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['text_key' => 'footer_text'] + parent::defaultConfiguration();
  }

  /**
   * The texts a block may show.
   */
  private function keys(): array {
    return [
      'front_intro' => $this->t('Front page introduction'),
      'about_body' => $this->t('About page body'),
      'footer_text' => $this->t('Footer text'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['text_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Text'),
      '#options' => $this->keys(),
      '#default_value' => $this->configuration['text_key'],
      '#description' => $this->t('Edited at the Taxanotes front page settings.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['text_key'] = $form_state->getValue('text_key');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->configFactory->get('taxanotes_front.settings');
    $key = $this->configuration['text_key'];
    $build = [];
    if (array_key_exists($key, $this->keys())) {
      $build['text'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['taxanotes-text', 'taxanotes-text--' . str_replace('_', '-', $key)]],
        // #markup is filtered with Xss::filterAdmin() by the renderer.
        'content' => ['#markup' => (string) $config->get($key)],
      ];
    }
    CacheableMetadata::createFromObject($config)->applyTo($build);
    return $build;
  }

}
