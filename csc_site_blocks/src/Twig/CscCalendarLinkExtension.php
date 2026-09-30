<?php

namespace Drupal\csc_site_blocks\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig helpers for the CSC calendar link block.
 */
class CscCalendarLinkExtension extends AbstractExtension {

  public function getName() {
    return 'csc_calendar_link_extension';
  }

  public function getFilters() {
    return [
      new TwigFilter('ics_with_rrule', [$this, 'addRruleToIcs']),
    ];
  }

  /**
   * Injects an RRULE line into a Spatie calendar-links ICS data URI.
   *
   * spatie/calendar-links 1.11.1 has no support for recurrence rules, so
   * the generated .ics only ever contains the first occurrence. This
   * decodes the data URI it produces, adds the RRULE line before
   * END:VEVENT, and re-encodes it.
   *
   * @param string $url
   *   The ICS link URL as produced by the calendar_links() Twig function.
   * @param string|false $rrule
   *   An RFC 5545 RRULE line (e.g. "RRULE:FREQ=WEEKLY;BYDAY=TU"), or FALSE.
   *
   * @return string
   *   The URL, with the RRULE injected if applicable.
   */
  public function addRruleToIcs($url, $rrule) {
    $prefix = 'data:text/calendar;charset=utf8;base64,';
    if (empty($rrule) || strpos($url, $prefix) !== 0) {
      return $url;
    }

    $decoded = base64_decode(substr($url, strlen($prefix)), TRUE);
    if ($decoded === FALSE || !str_contains($decoded, 'END:VEVENT')) {
      return $url;
    }

    $decoded = str_replace("END:VEVENT", $rrule . "\r\nEND:VEVENT", $decoded);

    return $prefix . base64_encode($decoded);
  }

}
