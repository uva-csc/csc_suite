<?php

namespace Drupal\csc_social_feeds\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides a CSC social media feed block.
 *
 * @Block(
 *   id = "csc_social_feeds",
 *   admin_label = @Translation("CSC Social Feeds Block"),
 * )
 */
class CscSocialFeedsBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    // Retrieve the configuration for this block instance.
    $config = $this->getConfiguration();

    // Retrieve the value of the 'option' setting from the block configuration.
    $option = !empty($config['feed_type']) ? $config['feed_type'] : 'facebook';

    // Determine the text based on the selected option.
    $text = $this->getTextForOption($option);

    // Return the text to be displayed in the block.
    return [
      '#markup' => $text,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);

    // Retrieve the configuration for this block instance.
    $config = $this->getConfiguration();

    // Define the options for the select list.
    $options = [
      'facebook' => $this->t('Facebook'),
      'instagram' => $this->t('Instagram'),
      'twitter' => $this->t('Twitter'),
    ];

    // Add a form element for configuring the 'option' setting as a select list.
    $form['feed_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Select which type of feed?'),
      '#options' => $options,
      '#default_value' => isset($config['feed_type']) ? $config['feed_type'] : 'facebook',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    // Save the configuration values submitted by the form.
    $this->setConfigurationValue('feed_type', $form_state->getValue('feed_type'));
  }

  /**
   * Helper function to get the text based on the selected option.
   */
  private function getTextForOption($option): string {
    switch ($option) {
      case 'facebook':
        return $this->t('Text for Facebook');
      case 'instagram':
        return $this->t('Text for Instagram');
      case 'twitter':
        return $this->t('Text for Twitter');
      default:
        return '';
    }
  }


  /**
   * {@inheritdoc}
   */
  public function blockAccess(AccountInterface $account) {
    return AccessResult::allowed();
    // Check if the current user has access to the block.
    // You can implement your access logic here.
    // For example, you can check user roles or permissions.
    // Below does not work for anonymous users.
    if ($account->hasPermission('view published content')) {
      // Grant access if the user has the necessary permission.
      return AccessResult::allowed();
    }
    else {
      // Deny access if the user doesn't have the necessary permission.
      return AccessResult::forbidden();
    }
  }

}
