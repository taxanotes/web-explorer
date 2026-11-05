<?php

declare(strict_types=1);

namespace Drupal\taxanotes_front\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Edits the front page, About page and footer texts of this instance.
 *
 * The file instances/<slug>/instance.yml seeds these through the
 * taxanotes_front recipe; a value set there is written again each time
 * scripts/new-instance.sh runs, a key left out of instance.yml keeps what is
 * saved here.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'taxanotes_front_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['taxanotes_front.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('taxanotes_front.settings');
    $form['front_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Front page title'),
      '#default_value' => $config->get('front_title'),
      '#description' => $this->t('Leave empty for "Welcome to <site name>".'),
    ];
    $form['front_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Front page introduction'),
      '#default_value' => $config->get('front_intro'),
      '#rows' => 8,
      '#description' => $this->t('HTML; shown as the front page content.'),
    ];
    $form['about_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('About page title'),
      '#default_value' => $config->get('about_title'),
    ];
    $form['about_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('About page body'),
      '#default_value' => $config->get('about_body'),
      '#rows' => 12,
      '#description' => $this->t('HTML; shown at /about.'),
    ];
    $form['footer_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Footer text'),
      '#default_value' => $config->get('footer_text'),
      '#rows' => 3,
      '#description' => $this->t('HTML; shown by the "Instance text" block placed in the footer (licence, credits).'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('taxanotes_front.settings')
      ->set('front_title', trim((string) $form_state->getValue('front_title')))
      ->set('front_intro', (string) $form_state->getValue('front_intro'))
      ->set('about_title', trim((string) $form_state->getValue('about_title')))
      ->set('about_body', (string) $form_state->getValue('about_body'))
      ->set('footer_text', (string) $form_state->getValue('footer_text'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
