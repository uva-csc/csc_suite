<?php

namespace Drupal\csc_site_blocks\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON suggestions for the Event Search Block (title contains the text).
 */
class EventSearchController extends ControllerBase {

  // Matches the Upcoming Events view: an event counts as current until 12
  // hours after it ends.
  const GRACE_SECONDS = 43200;

  const MIN_CHARS = 2;

  public function __construct(
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * GET /csc/event-search?q=<text>&past=0|1&limit=<n>
   */
  public function search(Request $request): CacheableJsonResponse {
    $q = trim((string) $request->query->get('q', ''));
    $past = (bool) $request->query->get('past', 0);
    $limit = max(1, min(15, (int) $request->query->get('limit', 8)));

    $items = [];
    if (mb_strlen($q) >= self::MIN_CHARS) {
      $cutoff = $this->time->getRequestTime() - self::GRACE_SECONDS;
      $items = $this->findEvents($q, $cutoff, FALSE, $limit);
      if ($past && count($items) < $limit) {
        $skip = array_column($items, 'nid');
        $items = array_merge($items, $this->findEvents($q, $cutoff, TRUE, $limit - count($items), $skip));
      }
    }
    foreach ($items as &$item) {
      unset($item['nid']);
    }

    $response = new CacheableJsonResponse(['results' => $items]);
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheContexts(['url.query_args'])
      ->setCacheTags(['node_list:event'])
      ->setCacheMaxAge(300));
    return $response;
  }

  /**
   * Finds events whose title contains $q.
   *
   * @return array[]
   *   Rows with nid, title, url, date, past.
   */
  protected function findEvents(string $q, int $cutoff, bool $past, int $limit, array $skip = []): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'event')
      ->condition('status', 1)
      ->condition('title', $q, 'CONTAINS');
    if ($past) {
      $query->condition('field_date.end_value', $cutoff, '<')
        ->sort('field_date.end_value', 'DESC');
    }
    else {
      $query->condition('field_date.end_value', $cutoff, '>=')
        ->sort('field_next_occurrence', 'ASC');
    }
    if ($skip) {
      $query->condition('nid', $skip, 'NOT IN');
    }
    // Multi-value date rows can repeat a node, so over-fetch then dedupe.
    $nids = array_unique(array_values($query->range(0, $limit * 3)->execute()));

    $rows = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $rows[] = [
        'nid' => (int) $node->id(),
        'title' => trim((string) $node->label()),
        'url' => $node->toUrl()->toString(),
        'date' => $this->displayDate($node, $cutoff, $past),
        'past' => $past,
      ];
      if (count($rows) >= $limit) {
        break;
      }
    }
    return $rows;
  }

  /**
   * Next upcoming occurrence (or the most recent one for past events).
   */
  protected function displayDate($node, int $cutoff, bool $past): string {
    $pick = NULL;
    foreach ($node->get('field_date') as $item) {
      $end = (int) $item->end_value;
      if ($past) {
        $pick = $item->value;
      }
      elseif ($end >= $cutoff) {
        $pick = $item->value;
        break;
      }
    }
    return $pick ? $this->dateFormatter->format((int) $pick, 'custom', 'M j, Y') : '';
  }

}
