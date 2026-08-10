<?php
namespace Drupal\csc_site_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Entity\EntityInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Database\Database;

/**
 * Provides a block to display canceled dates.
 *
 * @Block(
 *   id = "csc_canceled_dates_block",
 *   admin_label = @Translation("Canceled Dates Block"),
 * )
 */
class CscCanceledDatesBlock extends BlockBase {
  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get the node.
    $node = \Drupal::routeMatch()->getParameter('node');

    if ($node instanceof EntityInterface && $node->hasField('field_date')) {
      // Iterate through the items in the Smart date field and extract the cancelled dates
      $smart_date_field = $node->get('field_date'); // Retrieve the recurring date field.
      $canceled_dates = [];
      foreach ($smart_date_field as $item) {
        $date_field_value = $item->getValue(); // Get the value for the current item.

        if (!empty($date_field_value['rrule'])) {
          $rrule = SmartDateRule::load($date_field_value['rrule']);
          if ($rrule) {
            $instances = $rrule->makeRuleInstances();
            $ovrds = $rrule->getRuleOverrides();

            foreach ($ovrds as $ind => $ovrd) {
              // Adjust index to match the correct instance.
              $adjind = ($ind > 0) ? $ind - 1 : 0;
              $instance = $instances[$adjind];
              // $canceled_dates[] = $instance->getStart()->format('M j');
              if (!empty($instance)) {
                $start_date = $instance->getStart(); // Get the start date as a DateTime object.

                // Check if the date is already in the array.
                $exists = FALSE;
                foreach ($canceled_dates as $date) {
                  if ($date->format('Y-m-d') === $start_date->format('Y-m-d')) {
                    $exists = TRUE;
                    break;
                  }
                }

                // Add to the array if it doesn't exist.
                if (!$exists) {
                  $canceled_dates[] = $start_date;
                }
              }
            }
          }
        }
      }

      // Sort the array by date.
      usort($canceled_dates, function($a, $b) {
        return $a <=> $b; // Compare DateTime objects.
      });

      /*
            \Drupal::logger('csc_site_blocks')->error('<pre>@data</pre>', [
              '@data' => print_r($canceled_dates, TRUE),
            ]);
      */

      if (count($canceled_dates) > 0) {
        $canceled_dates = groupDateRanges($canceled_dates);

        // Return the canceled dates if they exist.
        if (!empty($canceled_dates)) {
          return [
            '#theme' => 'csc_canceled_dates_block',
            '#canceled_dates' => Markup::create(
              implode(", ", array_map(fn($date) => '<span class="text-nowrap">' . $date . '</span>', $canceled_dates))
            ),
          ];
        }
      }
    }
    // Default message if no canceled dates are found.
    return [];
  }
}

function groupDateRanges(array $dates): array {
  // Sort the dates
  usort($dates, fn($a, $b) => $a <=> $b);

  $ranges = [];
  $start = $prev = $dates[0];

  for ($i = 1; $i < count($dates); $i++) {
    $current = $dates[$i];
    $interval = $prev->diff($current)->days;

    if ($interval === 1) {
      // Still in a consecutive range
      $prev = $current;
    } else {
      // Break in sequence
      $ranges[] = ($start == $prev)
        ? $start->format('M j')
        : '<span class="text-nowrap">' . $start->format('M j') . '–' . $prev->format('M j'). '</span>';

      $start = $prev = $current;
    }
  }

  // Add the last range
  $ranges[] = ($start == $prev)
    ? $start->format('M j')
    :  '<span class="text-nowrap">' . $start->format('M j') . '–' . $prev->format('M j'). '</span>';
  return $ranges;
}


