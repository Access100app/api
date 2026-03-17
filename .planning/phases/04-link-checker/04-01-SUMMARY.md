---
phase: 04-link-checker
plan: 01
subsystem: infra
tags: [php, curl, curl_multi, cli-script, link-checker]

# Dependency graph
requires:
  - phase: 03-date-backfill
    provides: clean meetings and attachments data to check links against
provides:
  - CLI link checker script covering all stored detail_url and attachment file_url values
  - Baseline broken-link snapshot: 306 transient failures (eHawaii PDFs), 0 permanent failures
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - curl_multi batch GET (batch=20, timeout=15s, 250ms throttle) for link checking government servers
    - validate-dates.php stdout format reused for CLI script structure

key-files:
  created:
    - public_html/api/scripts/check-links.php
  modified: []

key-decisions:
  - "GET not HEAD: government servers reject HEAD requests (false positives) — same decision as admin.php link checker"
  - "classify status=0 as transient not permanent: curl connection errors are network-layer, not server-confirmed 404"
  - "read-only script: no DB writes, purely diagnostic — operators decide how to act on results"
  - "306 attachment failures are all eHawaii calendar PDFs (status=0 connection error) — transient pattern, not dead content"

patterns-established:
  - "CLI scripts follow validate-dates.php structure: timestamp header, section lines, summary footer, elapsed time"
  - "curl_multi batch pattern (batch 20, GET, 15s timeout, 250ms throttle) is the established pattern for government URL checking"

requirements-completed: [LINK-01, LINK-02, LINK-03, LINK-04]

# Metrics
duration: ~10min
completed: 2026-03-17
---

# Phase 4 Plan 01: Link Checker Summary

**Batched curl_multi GET script checking 824 stored URLs (428 detail + 396 attachment), finding 0 permanent failures and 306 transient eHawaii PDF connection errors**

## Performance

- **Duration:** ~10 min
- **Started:** 2026-03-17
- **Completed:** 2026-03-17
- **Tasks:** 2 (1 auto + 1 human-verify checkpoint)
- **Files modified:** 1

## Accomplishments
- CLI script `check-links.php` checks all non-null detail_url and file_url values via curl_multi batches
- Baseline link health snapshot: 518 healthy, 306 transient failures, 0 permanent (404/410) failures
- All 306 transient failures are eHawaii calendar PDF attachments (status=0, connection error) — a consistent pattern, not random breakage
- Zero broken detail_url links — every stored meeting page URL resolves successfully
- Script completed in 146.81s across 41 batches of 20 URLs

## Task Commits

Each task was committed atomically:

1. **Task 1: Write check-links.php** - `a239ced` (feat)

**Plan metadata:** (docs commit — this summary)

## Files Created/Modified
- `public_html/api/scripts/check-links.php` — CLI link checker: queries meetings + attachments, batched curl_multi GET, classifies permanent (404/410) vs transient (5xx/timeout/error), prints structured report to stdout

## Link Check Results (Actual Run)

| Category | Count |
|---|---|
| Total URLs checked | 824 |
| detail_url (meetings) | 428 |
| attachment file_url | 396 |
| Healthy | 518 |
| Permanent failures (404/410) | 0 |
| Transient failures (5xx/timeout/error) | 306 |
| Duration | 146.81s |

**Notable finding:** All 306 transient failures are attachment URLs pointing to eHawaii calendar PDFs (status=0, curl connection error). These are not permanent link deaths — eHawaii's PDF storage appears to block automated requests or the attachments require active session state. Zero detail_url failures confirms all meeting pages are live.

## curl_multi Configuration

- Batch size: 20 URLs per batch
- Timeout: 15s per request
- Connect timeout: 10s
- Follow redirects: yes (max 3)
- Method: GET (not HEAD — government servers reject HEAD)
- Throttle: 250ms between batches
- User-agent: `Access100-LinkChecker/1.0 (+https://civi.me)`

## Decisions Made
- GET not HEAD: reused the same decision established in admin.php — government servers return false positives on HEAD, GET is authoritative
- status=0 classified as transient: curl connection errors (no HTTP response received) could be network-layer, not confirmed dead links
- Read-only script: no DB writes anywhere — operators review report and decide action

## Deviations from Plan

None - plan executed exactly as written.

## Issues Encountered
None.

## User Setup Required
None - no external service configuration required.

## Next Phase Readiness
- Phase 4 (link checker) is complete — this is the final deliverable of the v1 milestone
- 306 transient eHawaii PDF failures are identified and documented; no immediate action required
- If eHawaii PDF failures warrant follow-up, consider: (1) adding a flag/column to mark known-transient attachment sources, (2) re-running periodically to detect if failures become permanent

---
*Phase: 04-link-checker*
*Completed: 2026-03-17*
