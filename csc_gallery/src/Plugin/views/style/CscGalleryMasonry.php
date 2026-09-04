<?php

namespace Drupal\csc_gallery\Plugin\views\style;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormStateInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Displays media images as a masonry gallery with a modal image popup.
 *
 * @ViewsStyle(
 *   id = "csc_gallery_masonry",
 *   title = @Translation("Masonry gallery"),
 *   help = @Translation("Displays media images as a masonry grid; clicking a tile opens the full image in a modal."),
 *   theme = "views_view_csc_gallery_masonry",
 *   display_types = {"normal"}
 * )
 */
class CscGalleryMasonry extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * The image style used for gallery tile thumbnails.
   */
  const THUMBNAIL_IMAGE_STYLE = 'medium';

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['tile_size'] = ['default' => 'medium'];
    $options['modal_height'] = ['default' => 90];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['tile_size'] = [
      '#title' => $this->t('Tile size'),
      '#type' => 'select',
      '#options' => [
        'small' => $this->t('Small'),
        'medium' => $this->t('Medium'),
        'large' => $this->t('Large'),
      ],
      '#default_value' => $this->options['tile_size'],
      '#description' => $this->t('Relative size of the gallery tiles.'),
    ];

    $form['modal_height'] = [
      '#title' => $this->t('Modal height (% of viewport height)'),
      '#type' => 'number',
      '#min' => 30,
      '#max' => 100,
      '#default_value' => $this->options['modal_height'],
      '#description' => $this->t('Maximum height of the full-size image popup, as a percentage of the viewport height.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render() {
    $view_id = $this->view->id();
    $display_id = $this->view->current_display;
    $modal_id = Html::getUniqueId('csc-gallery-modal--' . $view_id . '--' . $display_id);

    // DisplayPluginBase::render() wires the outer display element's
    // '#attached' by reference to $view->element['#attached'] - attach
    // through that (the mechanism Views itself uses) rather than this
    // style plugin's own return value.
    $this->view->element['#attached']['library'][] = 'csc_gallery/gallery';

    return [
      '#theme' => $this->themeFunctions(),
      '#view' => $this->view,
      '#options' => $this->options,
      '#modal_id' => $modal_id,
      '#tile_size' => $this->options['tile_size'],
      '#modal_height' => $this->options['modal_height'],
      '#gallery_items' => $this->buildGalleryItems(),
    ];
  }

  /**
   * Builds the list of gallery items from the view result.
   *
   * @return array
   *   An array of gallery item arrays, each with thumb_url, full_url, alt
   *   and caption keys.
   */
  protected function buildGalleryItems() {
    $items = [];
    $file_url_generator = \Drupal::service('file_url_generator');
    $thumbnail_style = ImageStyle::load(static::THUMBNAIL_IMAGE_STYLE);
    $cacheability = new CacheableMetadata();

    foreach ($this->view->result as $row) {
      $entity = $row->_entity ?? NULL;
      if (!$entity instanceof MediaInterface
        || !$entity->hasField('field_media_image')
        || $entity->get('field_media_image')->isEmpty()) {
        continue;
      }

      $image_item = $entity->get('field_media_image')->first();
      $file = $image_item->entity;
      if (!$file) {
        continue;
      }

      $uri = $file->getFileUri();
      $alt = $image_item->alt ?? '';

      $caption = '';
      if ($entity->hasField('field_caption') && !$entity->get('field_caption')->isEmpty()) {
        $caption = $entity->get('field_caption')->value;
      }

      $edit_access = $entity->access('update', NULL, TRUE);
      $cacheability->addCacheableDependency($edit_access);

      $items[] = [
        'thumb_url' => $thumbnail_style ? $thumbnail_style->buildUrl($uri) : $file_url_generator->generateString($uri),
        'full_url' => $file_url_generator->generateString($uri),
        'alt' => $alt,
        'caption' => $caption,
        'edit_url' => $edit_access->isAllowed() ? $entity->toUrl('edit-form')->toString() : NULL,
      ];
    }

    $cacheability->applyTo($this->view->element);

    return $items;
  }

}
