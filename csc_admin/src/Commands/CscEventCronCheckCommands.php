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
   * Check which events the next-occurrence cron job re-saves.
   *
   * Read-only. Runs the cron query as it is now (field_end_date compared to a
   * Y-m-d string) and as it used to be (compared to a raw timestamp), then
   * cross-checks the current query against the dates themselves: every
   * published event with a date instance that has not yet ended should be
   * re-saved, and nothing else should be.
   *
   * @command csc:event-cron-check
   * @aliases event-cron-check
   * @option list List the events the cron job WILL re-save (current/upcoming).
   * @option skipped List the past events the old query re-saved but the fix skips.
   * @usage drush csc:event-cron-check
   *   Counts plus the missed/extra verification.
   * @usage drush csc:event-cron-check --list
   *   Also lists every event cron will re-save, with its dates.
   * @usage drush csc:event-cron-check --skipped
   *   Also lists the past events no longer re-saved.
   */
  public function eventCronCheck(array $options = ['list' => FALSE, 'skipped' => FALSE]) {
    $now = $this->time->getCurrentTime();
    $storage = $this->entityTypeManager->getStorage('node');

    $old = $this->matchingNids($now, $now);
    $new = $this->matchingNids($now, date('Y-m-d', $now));

    // Independent ground truth: any date instance that has not ended yet.
    $published = $storage->loadMultiple($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->execute());
    $truth = [];
    foreach ($published as $nid => $node) {
      foreach ($node->get('field_date')->getValue() as $instance) {
        $end = !empty($instance['end_value']) ? $instance['end_value'] : ($instance['value'] ?? 0);
        if ($end >= $now) {
          $truth[] = $nid;
          break;
        }
      }
    }

    $missed = array_values(array_diff($truth, $new));
    $extra = array_values(array_diff($new, $truth));
    $skipped = array_values(array_diff($old, $new));

    $this->io()->table(['', 'Events'], [
      ['Published events', count($published)],
      ['Upcoming or ongoing (checked from the dates)', count($truth)],
      ['Re-saved by cron now (Y-m-d query)', count($new)],
      ['Re-saved by the old query (timestamp)', count($old)],
      ['Past events no longer re-saved', count($skipped)],
      ['Current events MISSED by the cron query', count($missed)],
      ['Re-saved but nothing upcoming (extra)', count($extra)],
    ]);

    if ($missed || $extra) {
      $this->io()->error('The cron query does not match the upcoming events exactly. See the lists below.');
      $this->printEvents('MISSED: upcoming but not re-saved', $missed, $published, $now);
      $this->printEvents('EXTRA: re-saved but nothing upcoming', $extra, $published, $now);
    }
    else {
      $this->io()->success('Cron re-saves exactly the upcoming/ongoing events: none missed, none extra.');
    }

    if (count($old) === count($new)) {
      $this->io()->warning('Old and current counts are equal: the Y-m-d fix is not active here, or there are no past events.');
    }

    if (!empty($options['list'])) {
      $this->printEvents('Events cron WILL re-save', $new, $published, $now);
    }
    if (!empty($options['skipped'])) {
      $this->printEvents('Past events no longer re-saved', $skipped, $published, $now);
    }
  }

  /**
   * Prints a table of events: last date instance end, next occurrence, changed.
   */
  protected function printEvents(string $heading, array $nids, array $published, int $now): void {
    if (!$nids) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $rows = [];
    foreach ($nids as $nid) {
      $node = $published[$nid] ?? $storage->load($nid);
      if (!$node) {
        continue;
      }
      $last_end = 0;
      foreach ($node->get('field_date')->getValue() as $instance) {
        $end = !empty($instance['end_value']) ? $instance['end_value'] : ($instance['value'] ?? 0);
        $last_end = max($last_end, $end);
      }
      $next = $node->get('field_next_occurrence')->value;
      $rows[] = [
        $nid,
        mb_strimwidth($node->label(), 0, 50, '…'),
        $last_end ? date('Y-m-d', $last_end) : '-',
        $next ? date('Y-m-d', (int) $next) : '-',
        date('Y-m-d H:i', $node->getChangedTime()),
      ];
    }
    usort($rows, fn($a, $b) => strcmp($a[2], $b[2]));
    $this->io()->section($heading . ' (' . count($rows) . ')');
    $this->io()->table(['NID', 'Title', 'Last date ends', 'Next occurrence', 'Changed'], $rows);
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
