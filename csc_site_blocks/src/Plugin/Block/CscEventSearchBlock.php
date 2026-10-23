<?php

namespace Drupal\csc_site_blocks\Plugin\Block;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a "Find Event" typeahead search block.
 *
 * Suggestions come from the csc_site_blocks.event_search route (title
 * contains the typed text). Not tied to any view, so it can be placed
 * anywhere in Layout Builder.
 *
 * @Block(
 *   id = "csc_event_search_block",
 *   admin_label = @Translation("Event Search Block"),
 * )
 */
class CscEventSearchBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'label_text' => 'Find Event',
      'placeholder' => 'Search events by title',
      'limit' => 8,
      'include_past' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $config = $this->getConfiguration();
    $form['label_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search box label'),
      '#default_value' => $config['label_text'],
      '#required' => TRUE,
    ];
    $form['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder text'),
      '#default_value' => $config['placeholder'],
    ];
    $form['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum suggestions'),
      '#min' => 3,
      '#max' => 15,
      '#default_value' => $config['limit'],
    ];
    $form['include_past'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('"Include past events" checkbox is checked by default'),
      '#default_value' => $config['include_past'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    foreach (['label_text', 'placeholder', 'limit', 'include_past'] as $key) {
      $this->configuration[$key] = $form_state->getValue($key);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration() + $this->defaultConfiguration();
    return [
      '#theme' => 'csc_event_search_block',
      '#uid' => Html::getUniqueId('csc-event-search'),
      '#label_text' => $config['label_text'],
      '#placeholder' => $config['placeholder'],
      '#limit' => max(3, min(15, (int) $config['limit'])),
      '#include_past' => !empty($config['include_past']),
      '#endpoint' => Url::fromRoute('csc_site_blocks.event_search')->toString(),
      '#attached' => ['library' => ['csc_site_blocks/event_search']],
    ];
  }

}
