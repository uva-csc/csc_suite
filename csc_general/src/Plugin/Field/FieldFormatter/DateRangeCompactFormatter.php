<?php

namespace Drupal\csc_general\Plugin\Field\FieldFormatter;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Formats a date range as compactly as possible.
 *
 * Same day:      November 12, 2026
 * Same month:    November 12–20, 2026
 * Same year:     November 12–December 3, 2026
 * Different yrs: December 28, 2026–January 3, 2027
 */
#[FieldFormatter(
  id: 'csc_daterange_compact',
  label: new TranslatableMarkup('Compact date range'),
  field_types: [
    'daterange',
  ],
)]
class DateRangeCompactFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'separator' => '–',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form = parent::settingsForm($form, $form_state);
    $form['separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Date separator'),
      '#description' => $this->t('Placed between the start and end dates, e.g. an en dash.'),
      '#default_value' => $this->getSetting('separator'),
      '#size' => 5,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    return [$this->t('Separator: @separator', ['@separator' => $this->getSetting('separator')])];
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      if (empty($item->start_date) || empty($item->end_date)) {
        continue;
      }
      $start = $this->localize($item->start_date);
      $end = $this->localize($item->end_date);
      $elements[$delta] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $this->formatRange($start, $end),
        '#attributes' => ['class' => ['daterange-compact']],
      ];
    }
    return $elements;
  }

  /**
   * Returns the date in the display timezone.
   *
   * Date-only values are stored at noon UTC, so they are left alone to avoid
   * a timezone shift moving them to a neighbouring day.
   */
  protected function localize(DrupalDateTime $date): DrupalDateTime {
    $date = clone $date;
    if ($this->fieldDefinition->getFieldStorageDefinition()->getSetting('datetime_type') !== 'date') {
      $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
    return $date;
  }

  /**
   * Builds the compact string for a start and end date.
   */
  protected function formatRange(DrupalDateTime $start, DrupalDateTime $end): string {
    $sep = $this->getSetting('separator');
    if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
      return $start->format('F j, Y');
    }
    if ($start->format('Y') !== $end->format('Y')) {
      return $start->format('F j, Y') . $sep . $end->format('F j, Y');
    }
    if ($start->format('m') === $end->format('m')) {
      return $start->format('F j') . $sep . $end->format('j, Y');
    }
    return $start->format('F j') . $sep . $end->format('F j, Y');
  }

}
