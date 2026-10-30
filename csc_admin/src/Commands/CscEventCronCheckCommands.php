<?php

namespace Drupal\csc_admin\Commands;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks which events csc_event_next_occurrence_cron() would re-save.
 */
class CscEventCronCheckCommands extends DrushCommands {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected TimeInterface $time;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, TimeInterface $time) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
    $this->time = $time;
  }

  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('datetime.time')
    );
  }

  /**
   * Show which events the next-occurrence cron job would re-save.
   *
   * Read-only: runs the cron query two ways (the old raw-timestamp comparison
   * against field_end_date, and the current Y-m-d comparison) and reports how
   * many events each matches. Past events should only appear in the old list.
   *
   * @command csc:event-cron-check
   * @aliases event-cron-check
   * @option list Also list events matched only by the old query, with their changed time.
   * @usage drush csc:event-cron-check
   *   Prints the old vs current match counts.
   * @usage drush csc:event-cron-check --list
   *   Also lists the past events the old query would have re-saved.
   */
  public function eventCronCheck(array $options = ['list' => FALSE]) {
    $now = $this->time->getCurrentTime();
    $old = $this->matchingNids($now, $now);
    $new = $this->matchingNids($now, date('Y-m-d', $now));
    $only_old = array_values(array_diff($old, $new));

    $this->io()->table(['Cron query', 'Events re-saved'], [
      ['Old (field_end_date vs timestamp)', count($old)],
      ['Current (field_end_date vs Y-m-d)', count($new)],
      ['No longer touched', count($only_old)],
    ]);

    if (!empty($options['list']) && $only_old) {
      $rows = [];
      $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($only_old);
      foreach ($nodes as $node) {
        $rows[] = [$node->id(), $node->label(), date('Y-m-d H:i', $node->getChangedTime())];
      }
      $this->io()->table(['NID', 'Title', 'Changed'], $rows);
    }

    if (count($old) === count($new)) {
      $this->io()->warning('Old and current counts are equal: the Y-m-d fix is not active here, or there are no past events.');
    }
  }

  /**
   * Published events the cron query matches for a given end-date comparison.
   */
  protected function matchingNids(int $now, $end_date_value): array {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE);
    $or = $query->orConditionGroup()
      ->condition('field_end_date.value', $end_date_value, '>=')
      ->condition('field_date.end_value', $now, '>=');

    return array_values($query
      ->condition('type', 'event')
      ->condition($or)
      ->condition('status', 1)
      ->execute());
  }

}
