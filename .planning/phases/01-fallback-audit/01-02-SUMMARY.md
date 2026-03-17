---
phase: 01-fallback-audit
plan: 02
subsystem: database
tags: [php, scraper, audit, date-parsing, mariadb, docker]

# Dependency graph
requires:
  - phase: 01-fallback-audit/01-01
    provides: "--audit flag and error_log() instrumentation in eHawaii, NCO, Honolulu Boards scrapers"
provides:
  - "--audit flag in scrape-maui-legistar.php with timezone shift heuristic"
  - "01-AUDIT-RESULTS.md with confirmed per-scraper verdicts for all four scrapers"
  - "Phase 2 scope: NCO fix required, Honolulu Boards/Maui clear, eHawaii logging verified"
affects: [02-nco-fix, 03-backfill, phase-2-parser-fixes]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "--audit flag inline in CLI scraper (in_array argv pattern) with exit(0)"
    - "Timezone shift heuristic: check meeting_date for weekend values (government meetings rarely scheduled Sat/Sun)"
    - "raw_rss_data NULL check before heuristic fallback for JSON-API scrapers"

key-files:
  created:
    - .planning/phases/01-fallback-audit/01-AUDIT-RESULTS.md
  modified:
    - public_html/api/cron/scrape-maui-legistar.php

key-decisions:
  - "NCO scraper has systematic +1 day error on 93% (41/44) of stored meetings — root cause is date interpretation off-by-one, fix is Phase 2 priority"
  - "Honolulu Boards parsing is correct (0/21 mismatches) — no fix needed"
  - "eHawaii pubDate fallback fires on 10% of meetings (35/348) but produces correct dates — no data corruption, logging is the only needed improvement"
  - "Maui Legistar raw_rss_data is NULL for all rows (confirmed) — timezone audit used heuristic weekend-date check; 0 weekend dates found, low risk of UTC shift"
  - "Phase 2 scope limited to NCO parser fix only — three other scrapers are functionally correct"

patterns-established:
  - "Audit pattern: query meetings WHERE source=X AND raw_rss_data IS NOT NULL, re-parse description, compare to stored meeting_date"
  - "Maui audit pattern: fall back to heuristic (weekend date check) when raw_rss_data is NULL"

requirements-completed: [DATE-04, DATE-05]

# Metrics
duration: 15min
completed: 2026-03-17
---

# Phase 1 Plan 02: Maui Audit + Full Four-Scraper Verdict Documentation Summary

**NCO scraper confirmed broken (41/44 meetings off by +1 day); Maui Legistar audit added with timezone heuristic showing no UTC shift evidence; 01-AUDIT-RESULTS.md documents all verdicts scoping Phase 2 to NCO-only fix**

## Performance

- **Duration:** ~15 min
- **Started:** 2026-03-17T06:22:00Z (continuation from checkpoint)
- **Completed:** 2026-03-17
- **Tasks:** 3 (Tasks 1-2 completed prior; Task 3 completed in this session)
- **Files modified:** 2

## Accomplishments
- Added `--audit` flag with UTC→HST timezone shift heuristic to `scrape-maui-legistar.php`
- Confirmed `raw_rss_data` is NULL for all 14 Maui meetings (JSON API, not RSS) — audit correctly fell back to weekend-date heuristic
- Wrote `01-AUDIT-RESULTS.md` with full stdout from all four live Docker audit runs, per-scraper verdicts, Phase 1 answers, and Phase 2 scope

## Task Commits

Each task was committed atomically:

1. **Task 1: Add --audit flag to scrape-maui-legistar.php** - `b4dab96` (feat)
2. **Task 2: Run all four scraper audits in Docker** - checkpoint completed by user
3. **Task 3: Write 01-AUDIT-RESULTS.md with all four verdicts** - `64be68c` (docs)

## Files Created/Modified
- `public_html/api/cron/scrape-maui-legistar.php` - Added `$audit_mode` flag and timezone assessment block (weekend-date heuristic, raw_rss_data NULL check)
- `.planning/phases/01-fallback-audit/01-AUDIT-RESULTS.md` - Phase 1 deliverable: full audit output, summary table, Phase 1 answers, Phase 2 scope

## Decisions Made

- **NCO is the Phase 2 priority**: 41 of 44 stored meetings show a consistent +1 day error. Every mismatch is exactly one day — this is systematic, not random, pointing to a date parsing or timezone interpretation bug in `scrape-nco.php`.
- **Honolulu Boards no fix needed**: 0 mismatches confirmed on 21 meetings; identical parsing code to NCO but different data format means no bug exists there.
- **eHawaii no backfill needed**: 35 pubDate fallbacks but 0 mismatches — the fallback correctly parses the date. Logging added in Plan 01-01 is sufficient.
- **Maui low risk**: 14 meetings, 0 weekend dates, heuristic clear. Definitive confirmation would need live API re-fetch but no action taken; risk is low.

## Deviations from Plan

None — plan executed exactly as written. The audit findings matched expected patterns from RESEARCH.md (Maui raw_rss_data NULL confirmed as predicted; NCO mismatch found as suspected).

## Issues Encountered

- Docker container for the Access100 API is `appwebsite-app-1` (not `civime-wordpress-wordpress-1`). The checkpoint instructions referenced the wrong container. User ran the correct container. No code impact.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Phase 1 complete: all four scraper verdicts documented, Phase 2 scope clear
- Phase 2 must fix NCO scraper date parsing (the off-by-one likely comes from a `createFromFormat` issue or pubDate usage treating UTC-posted dates as local)
- Phase 2 fix must complete and be verified before Phase 3 backfill runs — backfill re-parses using the same code path
- NCO has 41 meetings needing date correction after the parser is fixed

---
*Phase: 01-fallback-audit*
*Completed: 2026-03-17*
