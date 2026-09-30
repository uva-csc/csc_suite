<?php
namespace Drupal\csc_site_blocks\Plugin\Block;

use DateTime;
use DateTimeZone;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\NodeInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\smart_date_recur\Entity\SmartDateRule;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Block\BlockBase;

/**
 * Provides a block to display an add to calendar link that uses the
 * Twig functions proviced by the Calendar Link Module.
 *
 * @Block(
 *   id = "csc_calendar_link_block",
 *   admin_label = @Translation("CSC Calendar Link Block"),
 *   context_definitions = {
 *     "node" = @ContextDefinition("entity:node", required = TRUE, label = @Translation("Node"))
 *   }
 * )
 */
class CscCalendarLinkBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $routeMatch;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match')
    );
  }

  public function build() {
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      $date_items = $node->get('field_date');
      $dates = [];
      $all_entries = [];
      // Smart Date recur pre-materializes one field_date delta per already-
      // computed occurrence, but every occurrence in the same series shares
      // the same rrule ID. Only emit one VEVENT (with the RRULE) per series,
      // using its first occurrence as the anchor, or every recurring
      // instance would separately expand into its own infinite series.
      // Distinct, non-recurring occurrences (no rrule) each get their own.
      // $all_entries keeps every individual occurrence (uncollapsed) so we
      // can pick a "primary" one below for links that can't represent more
      // than a single date (Google/Yahoo).
      $seen_rrids = [];
      foreach ($date_items as $item) {
        // $item->start_time is  \Drupal\Core\Datetime\DrupalDateTime
        if (!isset($item->start_time)) {
          continue;
        }

        $sd_str = $item->start_time->format('Y-m-d H:i:s');
        $start_date = new DrupalDateTime($sd_str, new DateTimeZone('America/New_York'));

        $end_date = NULL;
        if (isset($item->end_time)) {
          $end_str = $item->end_time->format('Y-m-d H:i:s');
          $end_date = new DrupalDateTime($end_str, new DateTimeZone('America/New_York'));     // \Drupal\Core\Datetime\DrupalDateTime
        }

        $duration = $item->get('duration')->getValue();
        $rrid = $item->get('rrule')->getValue();

        $rrule = FALSE;
        if (!empty($rrid)) {
          $rule = SmartDateRule::load($rrid);
          $rrule = $rule ? $rule->getRule() : FALSE;
          if ($rrule && str_contains($rrule, 'UNTIL=')) {
            [$rule_bulk, $untilval] = explode('UNTIL=', $rrule);
            if (strlen($untilval) > 1) {
              // RRULE UNTIL values must be basic iCal UTC format: YYYYMMDDTHHMMSSZ.
              // SmartDateRule::getRule() emits the value without the trailing Z,
              // so try that form first, then the (correct) Z-suffixed form, then
              // a dashed/extended form as a last resort.
              $date = DateTime::createFromFormat('Ymd\THis', $untilval, new DateTimeZone('UTC'));
              if (!$date) {
                $date = DateTime::createFromFormat('Ymd\THis\Z', $untilval, new DateTimeZone('UTC'));
              }
              if (!$date) {
                $date = DateTime::createFromFormat('Y-m-d\THis', $untilval, new DateTimeZone('UTC'));
              }
              if ($date) {
                $formatted = $date->format('Ymd\THis\Z');
                $rrule = "{$rule_bulk}UNTIL={$formatted}";
              }
              // else: leave $rrule with the original (unparsed) UNTIL value rather than crashing.
            }
          }
        }

        $entry = [
          'start' => $start_date,
          'end' => $end_date,
          'rrule' => $rrule,
          'duration' => $duration,
          'all_day' => ($duration === 1440 || $duration === 86400),
        ];

        $all_entries[] = $entry;

        if (!empty($rrid)) {
          if (isset($seen_rrids[$rrid])) {
            continue;
          }
          $seen_rrids[$rrid] = TRUE;
        }
        $dates[] = $entry;
      }

      if (empty($dates)) {
        return [];
      }

      // Google/Yahoo links can only ever represent a single date, so pick
      // the next occurrence that hasn't ended yet (falling back to the most
      // recent past one if the whole series is over) rather than always
      // showing whatever the first field_date delta happens to be. This
      // uses the uncollapsed list so a recurring series' correct, already-
      // materialized future instance is used as-is (with its shared RRULE),
      // rather than an arbitrary computed date that could desync from the
      // real recurrence phase.
      $now = new DrupalDateTime('now', new DateTimeZone('America/New_York'));
      $primary_date = NULL;
      foreach ($all_entries as $entry) {
        $compare = $entry['end'] ?? $entry['start'];
        if ($compare >= $now) {
          $primary_date = $entry;
          break;
        }
      }
      if (!$primary_date) {
        $primary_date = end($all_entries);
      }

      return [
        '#theme' => 'calendar_link_block',
        '#message' => 'Add to Calendar',
        '#node' => $node,
        '#dates' => $dates,
        '#primary_date' => $primary_date,
        '#cache' => ['max-age' => 0],
      ];
    }
    return [];
  }
}
