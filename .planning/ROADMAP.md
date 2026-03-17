# Roadmap: Access100 Scraper Validation

## Overview

Four production scrapers are storing meeting dates without verifying accuracy. This project repairs date parsing at the source, verifies all stored URLs for liveness, and backfills corrected dates for recent and future meetings. The work is linear: add fallback logging to understand scope, fix all four parsers with unit tests, run the backfill using the corrected parsers, then check all stored URLs. No schema changes, no new runtime dependencies, no WordPress changes.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Fallback Audit** - Add eHawaii pubDate fallback logging and audit NCO/Honolulu Boards feeds to establish scope before fixing (completed 2026-03-17)
- [ ] **Phase 2: Parser Fixes and Tests** - Fix date parsing in all four scrapers and cover each parser with fixture-based unit tests
- [ ] **Phase 3: Date Backfill** - Re-fetch and correct recent/future meeting dates using the fixed parsers, with dry-run and full audit log
- [ ] **Phase 4: Link Checker** - Validate all stored URLs and produce a broken-link report classifying permanent vs transient failures

## Phase Details

### Phase 1: Fallback Audit
**Goal**: Operators can see when eHawaii falls back to pubDate, and the actual parsing behavior of NCO and Honolulu Boards is confirmed against live feeds before any fix code is written
**Depends on**: Nothing (first phase)
**Requirements**: DATE-02, DATE-04, DATE-05
**Success Criteria** (what must be TRUE):
  1. Running the eHawaii scraper against a live feed produces an `error_log()` entry whenever pubDate is used as fallback, visible in the PHP error log
  2. A live sample run against the NCO feed confirms whether `DateTime::createFromFormat` extracts the correct meeting date (result documented)
  3. A live sample run against the Honolulu Boards feed confirms whether `DateTime::createFromFormat` extracts the correct meeting date (result documented)
  4. Phase 1 output document exists stating: how many recent eHawaii meetings used pubDate fallback, and whether NCO/Honolulu Boards need fixes
**Plans**: 2 plans
Plans:
- [ ] 01-01-PLAN.md — Add pubDate fallback error_log() warnings and --audit mode to eHawaii, NCO, Honolulu Boards scrapers
- [ ] 01-02-PLAN.md — Add Maui Legistar --audit mode, run all four audits in Docker, document verdicts in 01-AUDIT-RESULTS.md

### Phase 2: Parser Fixes and Tests
**Goal**: All four scrapers extract meeting dates correctly, with unit tests that will catch future feed format regressions before they reach production
**Depends on**: Phase 1
**Requirements**: DATE-01, DATE-03, TEST-01, TEST-02, TEST-03, TEST-04
**Success Criteria** (what must be TRUE):
  1. eHawaii scraper correctly extracts the meeting date from the `Date:` field in the RSS description for all format variations found in Phase 1 audit
  2. Maui Legistar scraper uses `DateTimeImmutable` with explicit `DateTimeZone('Pacific/Honolulu')` so a Docker UTC host cannot shift meeting dates
  3. PHPUnit test suite runs to green with fixture tests covering: eHawaii regex match, eHawaii pubDate fallback, Maui timezone, NCO date format, Honolulu Boards date format
  4. All four scraper parsers can be invoked against a live feed without producing a date that differs from the date shown on the official source page
**Plans**: TBD

### Phase 3: Date Backfill
**Goal**: All recent and future meetings in the database have dates that match what the official source currently reports, with a complete audit trail of every correction made
**Depends on**: Phase 2
**Requirements**: BKFL-01, BKFL-02, BKFL-03, BKFL-04, BKFL-05
**Success Criteria** (what must be TRUE):
  1. Running `scripts/validate-dates.php --dry-run` prints a report of all meetings whose stored date differs from the source, without modifying the database
  2. Running without `--dry-run` corrects every mismatch found in the dry-run and produces a per-correction log with meeting ID, old date, new date, and source scraper
  3. A summary report after execution states: total meetings checked, total corrected, total unchanged
  4. The script identifies and logs which stored dates originated from pubDate fallback vs parsed description (provenance tracking)
  5. No meeting date older than approximately one week is modified by the backfill
**Plans**: TBD

### Phase 4: Link Checker
**Goal**: Operators have a complete report of broken URLs across all stored meetings, classified by failure type, so they can decide what to act on
**Depends on**: Phase 3
**Requirements**: LINK-01, LINK-02, LINK-03, LINK-04
**Success Criteria** (what must be TRUE):
  1. Running `scripts/check-links.php` checks every `detail_url` and `file_url` value stored in the meetings and attachments tables
  2. The script uses batched GET requests (not HEAD) so government document servers that reject HEAD do not produce false positives
  3. The output report lists every broken URL with: meeting ID, URL, HTTP status code, and URL type (detail_url vs attachment)
  4. Broken URLs are classified as permanent failures (404, 410) or transient failures (5xx, timeout) in the report
**Plans**: TBD

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Fallback Audit | 2/2 | Complete   | 2026-03-17 |
| 2. Parser Fixes and Tests | 0/? | Not started | - |
| 3. Date Backfill | 0/? | Not started | - |
| 4. Link Checker | 0/? | Not started | - |
