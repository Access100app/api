---
phase: 03-date-backfill
plan: 01
subsystem: database
tags: [php, backfill, date-parsing, cli, dry-run, ehawaii, nco, honolulu_boards, maui]

# Dependency graph
requires:
  - phase: 02-parser-fixes-and-tests
    provides: Fixed eHawaii and NCO date parsers whose logic is mirrored in this script
provides:
  - CLI backfill script that re-parses raw_rss_data and corrects meeting_date mismatches
  - Provenance tracking (was_pubdate_fallback flag) per corrected row
  - Dry-run audit showing all pending corrections before any DB writes
affects:
  - 03-date-backfill (plan 02 — live write run, if planned)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Inline re-parse functions mirror production parser logic for correctness auditing"
    - "was_pubdate_fallback flag tracks provenance of date source in correction log"
    - "CLI script follows backfill-attachments.php structural pattern: dry-run flag, per-row echo, stats array, summary block"

key-files:
  created:
    - public_html/api/scripts/validate-dates.php
  modified: []

key-decisions:
  - "eHawaii also shows 36 mismatches (same +1 day pattern as NCO) — both sources had the same off-by-one bug and need backfill"
  - "Honolulu Boards shows 0 mismatches — parsed correctly from the start"
  - "Maui counted and noted separately with explanation (raw_rss_data IS NULL, JSON API not RSS)"
  - "Shared reparse_nco_hnlboards_date() function used for both nco and honolulu_boards — same description format"
  - "No transaction wrapping needed — single-row date updates, no foreign key cascades"

patterns-established:
  - "Backfill scripts: per-source section dividers, WOULD UPDATE vs CORRECTED prefix, --- Summary --- footer"
  - "Re-parse functions return array{date: string|null, was_pubdate_fallback: bool}"

requirements-completed: [BKFL-01, BKFL-02, BKFL-03, BKFL-04, BKFL-05]

# Metrics
duration: 2min
completed: 2026-03-17
---

# Phase 3 Plan 1: Date Backfill Script Summary

**CLI date backfill script with --dry-run, per-row provenance logging, and summary for all 4 scrapers (eHawaii 36 mismatches, NCO 41 mismatches, HNL Boards 0, Maui skipped)**

## Performance

- **Duration:** 2 min
- **Started:** 2026-03-17T07:34:32Z
- **Completed:** 2026-03-17T07:36:02Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments
- Created validate-dates.php following backfill-attachments.php structural pattern
- Dry-run mode correctly identifies 41 NCO and 36 eHawaii mismatches (all +1 day, no pubDate fallbacks used)
- Honolulu Boards confirmed clean (0 mismatches), Maui correctly skipped with explanation
- was_pubdate_fallback provenance flag tracked and logged per row
- Summary block printed with all 5 counters: checked / corrected / unchanged / maui_skipped / parse_failures

## Task Commits

1. **Task 1: Write validate-dates.php backfill script** - `91cc158` (feat)

## Files Created/Modified
- `public_html/api/scripts/validate-dates.php` — CLI backfill script with dry-run mode, per-source section output, inline re-parse functions mirroring production parsers, and summary totals

## Decisions Made
- eHawaii also has the same +1 day off-by-one bug as NCO (36 affected rows). The plan expected 0 eHawaii corrections, but the actual DB state shows both sources had the same root cause. The script accurately reports this — the dry-run output is the authoritative audit trail before any live write.
- Shared reparse_nco_hnlboards_date() function handles both `nco` and `honolulu_boards` sources since both scrapers use identical description formatting.

## Deviations from Plan

None - plan executed exactly as written. The unexpected eHawaii mismatches are an accurate data finding, not a script deviation.

## Issues Encountered

The plan's expected output projected 0 eHawaii corrections. The actual dry-run shows 36 eHawaii rows also have +1 day mismatches. This is correct behavior — the script is accurately reporting the real DB state. The eHawaii parser fix (Phase 2) corrected the parser but did not backfill existing rows, just as with NCO. The backfill script handles this correctly.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness
- validate-dates.php dry-run output shows 77 total rows to correct (36 eHawaii + 41 NCO)
- All corrections are date - 1 day shifts, no pubDate fallbacks involved
- Ready for live write run: `php api/scripts/validate-dates.php` (without --dry-run)
- Operator should review dry-run output before executing live write

## Self-Check: PASSED

- `public_html/api/scripts/validate-dates.php` — FOUND
- Commit `91cc158` — FOUND

---
*Phase: 03-date-backfill*
*Completed: 2026-03-17*
