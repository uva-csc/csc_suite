<?php

namespace Drupal\csc_site_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides a CSC social media feed block.
 *
 * @Block(
 *   id = "csc_skewed_block",
 *   admin_label = @Translation("CSC Skewed Block"),
 * )
 */
class CscSkewedBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {

    // Retrieve the configuration for this block instance.
    $config = $this->getConfiguration();

    // Retrieve the value of the 'option' setting from the block configuration.
    $bgcolor = !empty($config['bgcolor']) ? $config['bgcolor'] : 'blue';
    $height = !empty($config['height']) ? $config['height'] : '600';
    $angle = !empty($config['angle']) ? $config['angle'] : '-7';
    $margbot = !empty($config['marginbottom']) ? $config['marginbottom'] : '-200';
    $hgt = $height * 1;
    $skewheight = $hgt * 0.56;


    // Return the text to be displayed in the block.
    return [
      '#theme' => 'csc_skewed_block',
      '#bgcolor' => $bgcolor,
      '#divheight' => $height,
      '#skewheight' => $skewheight,
      '#angle' => $angle,
      '#marginbottom' => $margbot,
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
      'blue' => $this->t('Blue'),
      'lt-blue' => $this->t('Light Blue'),
      'orange' => $this->t('Orange'),
      'black' => $this->t('Black'),
      'white' => $this->t('White'),
    ];

    // Add a form element for configuring the 'option' setting as a select list.
    $form['bgcolor'] = [
      '#type' => 'select',
      '#title' => $this->t('Select the color for the background?'),
      '#options' => $options,
      '#default_value' => $config['bgcolor'] ?? 'blue',
    ];

    // Add a form element for configuring the 'option' setting as a select list.
    $form['height'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter the total pixel height for the skewed background'),
      '#default_value' => $config['height'] ?? '600',
    ];


    // Add a form element for configuring the 'option' setting as a select list.
    $form['angle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter skew angle (negative numbers allowed)'),
      '#default_value' => $config['angle'] ?? '-7',
    ];

    $form['marginbottom'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Enter bottom margin value (negative number to overlay elements below)'),
      '#default_value' => $config['marginbottom'] ?? '-200',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    // Save the configuration values submitted by the form.
    $this->setConfigurationValue('bgcolor', $form_state->getValue('bgcolor'));
    $this->setConfigurationValue('height', $form_state->getValue('height'));
    $this->setConfigurationValue('angle', $form_state->getValue('angle'));
    $this->setConfigurationValue('marginbottom', $form_state->getValue('marginbottom'));
  }

  /**
   * {@inheritdoc}
   */
  public function blockAccess(AccountInterface $account) {
    return AccessResult::allowed();
    // Check if the current user has access to the block.
    // You can implement your access logic here.
    // For example, you can check user roles or permissions.
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
