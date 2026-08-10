<?php
namespace Drupal\csc_site_blocks\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides a block to display if the event is in the past.
 *
 * @Block(
 *   id = "csc_event_past_status_block",
 *   admin_label = @Translation("CSC Event Past Status Block"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", required = TRUE, label = @Translation("Node"))
 *   }
 * )
 */
class CscEventPastStatusBlock extends BlockBase {

  public function build() {
    // Get the node from the context.
    $node = \Drupal::routeMatch()->getParameter('node');

    // Initialize the output array.
    $output = [];

    if ($node instanceof NodeInterface && $node->getType() === 'event') {
      $end_timestamp = 0; // end_timestamp the greatest timestamp value of field_date if there is no field_end_date
      if (!$node->get('field_end_date')->isEmpty()) {
        $end_timestamp = strtotime($node->get('field_end_date')->value); // This field_end_date value is a date string
      } else if (!$node->get('field_date')->isEmpty()) {
        // Loop through all field_date items to find greatest end time
        foreach ($node->get('field_date')->getValue() as $date_item) {
          $item_end_timestamp = $date_item['end_value']; // This value is already a timestamp
          // Check if this end date is greater than the current greatest timestamp.
          if ($item_end_timestamp > $end_timestamp) {
            $end_timestamp = $item_end_timestamp;
          }
        }
      }

      // Get the current timestamp.
      $current_timestamp = strtotime('-1 day');

      // Check if the event is in the past.
      if ($end_timestamp < $current_timestamp ) {
        $output = [
          '#markup' => '<p class="event-past-msg">This event has passed. To see a list of our current events, ' .
            'see our <a href="/events">events page</a>.</p>',
          '#cache' => [
            'contexts' => ['url.path'],
          ],
        ];
      }
    }
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowed();
  }

}
