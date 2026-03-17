---
phase: 02-parser-fixes-and-tests
plan: 03
subsystem: api
tags: [php, datetime, timezone, pacific-honolulu, legistar, maui, scraper]

# Dependency graph
requires:
  - phase: 02-parser-fixes-and-tests
    provides: RESEARCH.md with Maui fix approach and parse_helpers extraction pattern
provides:
  - parse_helpers_maui.php with standalone parse_maui_date() function (no DB/curl deps)
  - scrape-maui-legistar.php updated to use DateTimeImmutable + Pacific/Honolulu via helper
affects:
  - 02-05-PLAN (MauiParserTest.php will require_once parse_helpers_maui.php)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Extract date-parse logic into parse_helpers_*.php standalone include for testability"
    - "DateTimeImmutable::createFromFormat with explicit DateTimeZone for timezone-safe parsing"
    - "Fallback to new DateTimeImmutable($date, $tz) with try/catch when primary format fails"
    - "Return null + error_log on completely unparseable input — caller decides to skip"

key-files:
  created:
    - public_html/api/cron/parse_helpers_maui.php
  modified:
    - public_html/api/cron/scrape-maui-legistar.php

key-decisions:
  - "parse_maui_date() returns null (not false) on failure so caller can use strict null check"
  - "Fallback uses try/catch around new DateTimeImmutable to prevent fatal crash on garbage input"
  - "scrape-maui-legistar.php returns null from map_legistar_event() when date is unparseable — consistent with existing null-return pattern for unmappable events"

patterns-established:
  - "parse_helpers_*.php: standalone helper file pattern — no DB, no curl, safe to require from tests"

requirements-completed: [DATE-03]

# Metrics
duration: 3min
completed: 2026-03-17
---

# Phase 2 Plan 03: Maui Legistar Timezone Fix Summary

**DateTimeImmutable + Pacific/Honolulu date parsing extracted into standalone parse_helpers_maui.php helper, replacing bare new DateTime() in the Maui Legistar scraper**

## Performance

- **Duration:** 3 min
- **Started:** 2026-03-17T07:04:37Z
- **Completed:** 2026-03-17T07:07:00Z
- **Tasks:** 1
- **Files modified:** 2 (1 created, 1 modified)

## Accomplishments

- Created `parse_helpers_maui.php` with `parse_maui_date(string $event_date): ?string` — uses `DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $event_date, new DateTimeZone('Pacific/Honolulu'))` so UTC Docker host cannot shift meeting dates
- Removed bare `new DateTime($event_date)` from `map_legistar_event()` in the scraper; replaced with `parse_maui_date($event_date)` call with null guard
- Maui audit confirmed 0 date mismatches — no regression from the timezone change

## Task Commits

Each task was committed atomically:

1. **Task 1: Extract parse_maui_date() helper and apply timezone fix to scraper** - `f7054db` (feat)

**Plan metadata:** (docs commit — follows)

## Files Created/Modified

- `public_html/api/cron/parse_helpers_maui.php` - Standalone `parse_maui_date()` helper; no DB or curl dependencies; safe for PHPUnit require_once
- `public_html/api/cron/scrape-maui-legistar.php` - Added `require_once parse_helpers_maui.php`; replaced 3-line DateTime block with `parse_maui_date()` call + null guard

## Decisions Made

- `parse_maui_date()` returns `?string` (null on failure) rather than `false` — consistent with PHP 8 nullable return typing and allows strict null check at call site
- Fallback path wraps `new DateTimeImmutable($event_date, $tz)` in try/catch to prevent fatal crash on completely malformed input; logs error and returns null
- `map_legistar_event()` now returns `null` when date is unparseable — consistent with the existing pattern (already returns null for missing event_id, empty body_name, unmapped council_id)

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered

The container mounts the repo directly at `/var/www/html/api/` (not `/var/www/html/public_html/api/` as the plan's verify commands assumed). Used the correct path `/var/www/html/api/cron/` for `docker exec php -l` checks.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- `parse_helpers_maui.php` is ready for `require_once` from `tests/MauiParserTest.php` (plan 02-05)
- The helper satisfies the testability requirement: no DB connections, no curl, no `exit()` calls
- Audit confirms 0 date mismatches in the 14 stored Maui meetings after the timezone fix

---
*Phase: 02-parser-fixes-and-tests*
*Completed: 2026-03-17*
