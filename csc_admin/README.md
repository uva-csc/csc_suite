# csc_admin

A module to add some useful drush commands for csc site.
Author: Than Grove
Date: Sept 30, 2025

The command are:

* `drush scan-direct-files` — scans all entities for direct references to files
* `drush search-file example.pdf` — searches for references to “example.pdf”
* `drush list-text-long-fields` — Lists all long text fields
* `drush para-fields` — Lists all paragraph fields
* `drush page-check` — smoke-tests the homepage, one node per content type, several recurring events, and every public Views page (see below)

## page-check

Run this right after a Drupal core/module update — on dev first, then again on production after deploying — to catch pages that break silently instead of finding out from a visitor. It was added after a July 2026 core update broke recurring event pages on production without any errors showing up elsewhere.

It checks, anonymously:
- The front page
- The landing page for each top nav item (`/commons`, `/events`, `/conferences`, `/research`, `/students`, `/about`, `/contact`) — hardcoded, since these are content decisions, not something to infer from the database
- The most recently updated published node of each content type (skips `slide_image`, which intentionally redirects to the homepage) — found by querying the database
- The most recently updated recurring events (nodes with more than one `field_date` instance) — this is what missed the July 2026 regression — also found by querying the database
- Every enabled Views page display that an anonymous visitor can actually reach (admin-only, role-restricted, and deprecated `-old` displays are skipped automatically) — found by querying Views config

A page "fails" if it doesn't return HTTP 200, if the response body contains a PHP/Drupal error string, or if the body is suspiciously small (likely a blank/broken render). The command exits non-zero if anything fails, so it can gate a deploy script. A progress counter (`N/total pages checked`) prints as it works through the list.

**Content-type and recurring-event checks are database-driven, not hardcoded** — the command queries whichever database it's connected to for the most-recently-changed node/event, so the exact paths it tests will differ between dev and production if their content differs (e.g. a test event created only on dev). That's expected, not a bug — dev and production have separate databases here (see deploy notes) and each environment should be checked against its own content. If you run this with `--base-url` pointing at a *different* host than the one you're actually connected to, you'll get a warning, because in that case you'd be testing one environment's content against another's URLs, which produces false failures for anything that doesn't exist on both sides.

```bash
drush page-check
# or check a specific environment regardless of which host you're running on:
drush page-check --base-url=https://dev.csc.virginia.edu
drush page-check --base-url=https://csc.virginia.edu
```
