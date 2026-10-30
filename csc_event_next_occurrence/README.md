# csc_event_next_occurrence

Keeps two fields on `event` nodes up to date from the Smart Date values in `field_date`, so event listings can filter and sort correctly:

- `field_next_occurrence` — start of the earliest instance that has not started yet.
- `field_end_date` — date-only (`Y-m-d`) end of the latest instance.

Both fields are read-only in the node edit form.

## When it runs

- **On save** (`hook_entity_presave`): every `event` node recalculates both fields.
- **On cron** (`hook_cron`): re-saves every published event that is upcoming or ongoing, so `field_next_occurrence` moves forward as instances pass without anyone editing the event. Events whose dates have all passed are not touched.

After each cron run it writes a notice to the log (type `csc_event_next_occurrence`):

> Cron re-saved N upcoming/ongoing events.

N should be roughly the number of upcoming/ongoing events (tens), not the number of all published events.

## Past fix (11x-1.0.9)

The cron query used to compare `field_end_date` (a `Y-m-d` string) with a raw Unix timestamp. Drupal binds query values as strings, so `'2026-04-11' >= '1793350597'` was true for every event, and **every** published event, past ones included, was re-saved on each cron run (bumping `changed` when values differed, and purging each page's cache). It is now compared with `date('Y-m-d', $now)`. Keep any comparison against `field_end_date` as a date string; it is not a timestamp.

## Checking it

`drush event-cron-check` (from the `csc_admin` module) is read-only and verifies the cron query against the event dates themselves:

```bash
drush event-cron-check            # counts, plus events missed / extra (both should be 0)
drush event-cron-check --list     # the events cron will re-save
drush event-cron-check --skipped  # past events the old query would have re-saved
```

To run only this module's cron job and see its log line:

```bash
drush php:eval 'csc_event_next_occurrence_cron();' -v
```

The count in that line should equal "Re-saved by cron now" in `drush event-cron-check`. Do not use an event's `changed` time to tell whether cron saved it: Drupal only updates `changed` when a value actually changes.
