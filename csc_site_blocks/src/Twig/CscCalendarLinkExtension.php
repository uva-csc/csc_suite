<?php

namespace Drupal\csc_site_blocks\Twig;

use Drupal\Core\Datetime\DrupalDateTime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for the CSC calendar link block.
 */
class CscCalendarLinkExtension extends AbstractExtension {

  const DATA_URI_PREFIX = 'data:text/calendar;charset=utf8;base64,';

  public function getName() {
    return 'csc_calendar_link_extension';
  }

  public function getFunctions() {
    return [
      new TwigFunction('ics_calendar_link', [$this, 'buildIcsLink']),
    ];
  }

  /**
   * Builds an ICS "data:" URI covering every date instance on the event.
   *
   * Events can have multiple entries in field_date for two different
   * reasons: a single recurring series (one entry carrying an RRULE), or
   * several distinct, non-recurring occurrences entered by hand (no RRULE
   * on any of them). spatie/calendar-links only ever generates a single
   * VEVENT, so instead of using it here, this builds the ICS directly with
   * one VEVENT per date entry, so both cases (and any mix of the two) come
   * through correctly in the download.
   *
   * @param string $title
   *   The event title.
   * @param array $dates
   *   Array of date info as built by CscCalendarLinkBlock::build(), each
   *   with 'start' and 'end' (DrupalDateTime), 'rrule' (string|false) and
   *   'all_day' (bool).
   * @param string $description
   *   Plain-text (HTML will be stripped) event description.
   * @param string $address
   *   Event location.
   *
   * @return string
   *   A "data:text/calendar;...;base64,..." URI.
   */
  public function buildIcsLink(string $title, array $dates, string $description = '', string $address = ''): string {
    $lines = [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:CSC calendar-links',
    ];

    foreach ($dates as $delta => $date) {
      if (empty($date['start'])) {
        continue;
      }
      $lines = array_merge($lines, $this->buildEvent($delta, $title, $date, $description, $address));
    }

    $lines[] = 'END:VCALENDAR';

    return self::DATA_URI_PREFIX . base64_encode(implode("\r\n", $lines));
  }

  /**
   * @return list<string>
   */
  private function buildEvent(int $delta, string $title, array $date, string $description, string $address): array {
    /** @var \Drupal\Core\Datetime\DrupalDateTime $start */
    $start = $date['start'];
    /** @var \Drupal\Core\Datetime\DrupalDateTime|null $end */
    $end = $date['end'] ?? $start;
    $all_day = !empty($date['all_day']);

    $lines = [
      'BEGIN:VEVENT',
      'UID:' . $this->generateUid($title, $start, $end, $delta),
      'SUMMARY:' . $this->escape($title),
    ];

    if ($all_day) {
      $lines[] = 'DTSTAMP:' . $start->format('Ymd');
      $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');
      $lines[] = 'DTEND;VALUE=DATE:' . $end->format('Ymd');
    }
    else {
      $lines[] = 'DTSTAMP:' . $this->toUtc($start);
      $lines[] = 'DTSTART:' . $this->toUtc($start);
      $lines[] = 'DTEND:' . $this->toUtc($end);
    }

    if ($description !== '') {
      $lines[] = 'DESCRIPTION:' . $this->escape(strip_tags($description));
    }
    if ($address !== '') {
      $lines[] = 'LOCATION:' . $this->escape($address);
    }
    if (!empty($date['rrule'])) {
      $lines[] = $date['rrule'];
    }

    $lines[] = 'END:VEVENT';

    return $lines;
  }

  private function toUtc(DrupalDateTime $date): string {
    return gmdate('Ymd\THis\Z', $date->getTimestamp());
  }

  private function generateUid(string $title, DrupalDateTime $start, DrupalDateTime $end, int $delta): string {
    return md5(sprintf('%s%s%s%d', $title, $start->format(\DateTimeInterface::ATOM), $end->format(\DateTimeInterface::ATOM), $delta));
  }

  /** @see https://tools.ietf.org/html/rfc5545.html#section-3.3.11 */
  private function escape(string $field): string {
    return addcslashes($field, "\r\n,;");
  }

}
