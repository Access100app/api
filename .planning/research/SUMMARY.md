# Project Research Summary

**Project:** Access100 Scraper Validation, Link Checking, and Date Backfill
**Domain:** PHP scraper maintenance — data quality repair for a government meeting aggregator
**Researched:** 2026-03-16
**Confidence:** HIGH

## Executive Summary

This milestone is a targeted data quality repair, not a greenfield build. The Access100 API already runs four production PHP 8.2 scrapers that ingest government meeting data from eHawaii RSS feeds, the Maui Legistar API, and two honolulu.gov RSS feeds. Two confirmed bugs exist: the eHawaii scraper silently falls back to `pubDate` (feed publication date) instead of the meeting's actual `Date:` field when the regex fails, and the Maui Legistar scraper constructs `DateTime` objects without an explicit Hawaii timezone, leaving the code vulnerable to UTC-to-HST date-shift errors on any Docker host. Two additional scrapers (NCO, Honolulu Boards) use a different date parsing approach that needs verification but is likely correct. The validated fix strategy is to repair the inline `parse_*_item()` functions in each scraper, then run a one-time backfill script to correct recent and future meetings in the live database.

The recommended approach mirrors the `backfill-attachments.php` pattern that already exists in the codebase: CLI scripts in `scripts/`, `--dry-run` support, per-request throttling with `usleep()`, stdout progress reporting, and a final summary count. No new runtime dependencies, no schema changes, and no Docker changes are needed. The only optional new tooling is PHPUnit 11 (dev-only, via Composer) for unit-testing the date parsing functions before the fixes land. The link checker deliverable is a standalone `scripts/check-links.php` using the same `curl_multi_exec` batch pattern already present in `admin.php`.

The most important risk is the backfill script overwriting dates that were manually corrected in the database, or that were parsed correctly but differ from what the live feed returns now. Every backfill run must be previewed with `--dry-run` first, must log individual before/after values (not just a summary count), and must avoid running concurrently with the production cron scrapers. The URL checker carries a secondary risk of false positives: government document servers frequently reject HEAD requests. The fix is to always use GET, matching the approach already hardened in the existing `checker/` tool.

## Key Findings

### Recommended Stack

No new runtime or framework is introduced. The entire milestone runs on the existing PHP 8.2-apache container with PDO, `curl_multi_exec`, `SimpleXMLElement`, and `DOMDocument` — all already in production. The only change to the development environment is adding Composer and PHPUnit 11 (dev-only) to enable unit tests for date parsing functions. PHPUnit 11 is the correct version for PHP 8.2; PHPUnit 12 requires PHP 8.3 and must not be used until the Dockerfile upgrades.

**Core technologies:**
- `DateTimeImmutable` + `DateTimeZone('Pacific/Honolulu')` — timezone-safe date parsing — eliminates the UTC-shift class of bugs; IANA identifier `'Pacific/Honolulu'` handles DST edge cases that `'HST'` does not
- `curl_multi_exec` (built-in) — batched parallel URL liveness checks — reuse the existing batch-of-20 pattern from `admin.php` lines 1828–1885 verbatim
- PHPUnit 11 (dev-only, not in Dockerfile) — unit tests for pure parsing functions — run locally against fixture data, no Docker dependency
- `get_db()` singleton from `config.php` — all DB access — existing pattern, no change needed

### Expected Features

**Must have (table stakes — milestone is incomplete without these):**
- eHawaii pubDate fallback logging — add `error_log()` before fallback fires so operators can see how many meetings have incorrect dates; required before backfill to understand scope
- Maui Legistar explicit HST timezone fix — one-line change; `new DateTime($event_date, new DateTimeZone('Pacific/Honolulu'))` in `map_legistar_event()`
- NCO + Honolulu Boards date parsing verification — run against live feeds and compare; may be a no-op, but must be confirmed
- Date backfill script (`scripts/validate-dates.php`) — re-fetch from all 4 sources, compare stored dates, correct mismatches for recent/future meetings, output per-correction log and summary report; follows `backfill-attachments.php` pattern exactly
- URL link checker script (`scripts/check-links.php`) — check `detail_url` and `file_url` for active meetings; output meeting_id, URL, HTTP status, URL type; flag only, never delete

**Should have (differentiators — meaningful value, not required for milestone):**
- Unit-testable date parsing functions — isolate `parse_*_item()` logic into pure functions testable with fixture data; catches future feed format regressions before they hit production
- Source-stamped correction log — log each backfill correction as `[source] [id] [old] → [new]` to a file for post-run audit trail

**Defer (v2+):**
- Periodic automated link checking via cron — not justified at current volume; creates recurring load on government servers with marginal value over on-demand runs

### Architecture Approach

The fix layer sits entirely inside the existing codebase structure: inline date parsing improvements go into `parse_*_item()` functions in each `cron/` scraper file; one-time repair operations go into new `scripts/` files. No new top-level directories. The URL checker and date backfill are separate scripts with independent risk profiles — they must not be combined into one file. The dependency order is: fix parsers first, then backfill (backfill re-uses the corrected parsing logic, so a broken parser will write the same wrong dates back).

**Major components:**
1. Inline parser fixes in `cron/scrape.php`, `cron/scrape-maui-legistar.php`, `cron/scrape-nco.php`, `cron/scrape-honolulu-boards.php` — correct date extraction at the source
2. `scripts/validate-dates.php` — re-fetch, compare, and correct recent/future meeting dates in the live DB
3. `scripts/check-links.php` — batch HTTP status check of all `detail_url` and `file_url` values; output report only
4. `tests/test-date-parsing.php` — fixture-based assertions for pure parsing functions; no DB or HTTP required

### Critical Pitfalls

1. **Backfill overwrites manually-corrected dates** — log every correction with before/after values; run `--dry-run` first and review the diff before any live write; never run without preview
2. **pubDate fallback persists silently after the regex fix** — add `error_log()` on fallback; write unit tests that include RSS items where `Date:` is absent or malformed to verify the fallback path explicitly
3. **Maui Legistar timezone shift undetected today** — the bug is masked because Legistar currently returns midnight timestamps; it will silently produce wrong dates if the API adds actual time components; fix proactively with `new DateTimeZone('Pacific/Honolulu')` now
4. **URL checker false positives from HEAD rejection** — government servers commonly return 405 for HEAD even when the file is live; always use GET, matching the already-hardened pattern in `checker/`; treat 405 as indeterminate, not broken
5. **Backfill rate-limiting production scrapers** — run backfill during off-cron windows; use source-specific throttle delays (eHawaii: 1s minimum, Legistar: 2s minimum); never run concurrent with production cron

## Implications for Roadmap

Based on research, the dependency chain is linear. Parser fixes must precede the backfill (the backfill re-parses from live sources using the same logic). The link checker is fully independent and can run in any phase. Suggested phase structure:

### Phase 1: Audit and Baseline

**Rationale:** Read-only first. Understand the actual scope of wrong dates before writing a single line of fix code. Prevents the pitfall of building a backfill for a problem that turns out to be smaller or larger than expected.
**Delivers:** Counts of affected meetings by source, confirmation of which scrapers need fixes vs. verification only, baseline for measuring the backfill's effectiveness
**Addresses:** eHawaii pubDate fallback, NCO/Honolulu Boards date parsing verification (audit phase of each)
**Avoids:** Backfill-overwrites-correct-dates (Pitfall 1) — you can't over-correct what you've already measured; synthetic state_id collision check (Pitfall 4) via a DB query before any writes

### Phase 2: Parser Fixes and Unit Tests

**Rationale:** Fix the source of the bug before repairing the data. The backfill re-fetches and re-parses — if parsing is still broken, the backfill writes the same wrong dates back. Unit tests written before the fixes catch misunderstandings about feed format.
**Delivers:** Corrected inline parsing logic in all 4 scrapers; PHPUnit fixture tests covering happy path and edge cases (absent `Date:` field, malformed HTML entities, non-midnight Legistar timestamps)
**Uses:** `DateTimeImmutable` + `DateTimeZone('Pacific/Honolulu')`, PHPUnit 11 (dev-only), Composer (dev-only)
**Implements:** Inline parser fix pattern in `parse_*_item()` functions; `tests/test-date-parsing.php`
**Avoids:** pubDate fallback persisting silently (Pitfall 2); Maui timezone shift (Pitfall 3)

### Phase 3: Date Backfill Script

**Rationale:** Run after parser fixes are proven correct. The backfill uses the same parsing logic as the fixed scrapers, so correctness of the fixes is a prerequisite.
**Delivers:** `scripts/validate-dates.php` with `--dry-run`, per-correction log output, and summary report; applied corrections to recent/future meetings in the live DB
**Addresses:** Date backfill script (P1 feature), verification report (part of backfill)
**Avoids:** Backfill overwrites correct dates (Pitfall 1) — dry-run required before live run; rate limiting production scrapers (Pitfall 5) — source-specific throttle delays, off-cron scheduling

### Phase 4: URL Link Checker

**Rationale:** Fully independent of date work. Can be built in parallel with Phase 3, or after it — order does not matter technically. Placing it last keeps each phase's deliverables focused and makes `--dry-run` semantics unambiguous per script.
**Delivers:** `scripts/check-links.php` with batch GET checking (groups of 20), structured broken-link report (meeting_id, council name, meeting date, URL, HTTP status, URL type), distinction between permanent failures (404, 410) and transient failures (5xx, timeout)
**Addresses:** URL link checker + broken link report (P1 feature)
**Avoids:** URL checker false positives (Pitfall 6) — GET not HEAD; reuse existing `checker/` patterns

### Phase Ordering Rationale

- Audit before fixing prevents building a solution misaligned with actual scope
- Parser fixes before backfill is a hard dependency — the backfill re-parses using the same code path
- Unit tests in Phase 2 (before live fixes land) catch misunderstandings about feed format at zero cost
- Link checker independence means it could move to Phase 2 or 3 if timeline pressure exists — no risk either way
- No phase requires schema changes, Docker changes, or new runtime dependencies in production

### Research Flags

Phases with standard patterns (skip research-phase during planning):
- **Phase 1 (Audit):** SQL queries and scraper dry-runs against live data — standard, no research needed
- **Phase 2 (Parser Fixes):** PHP DateTime/DateTimeZone documentation is HIGH confidence; PHPUnit 11 setup is well-documented; patterns are already established in codebase
- **Phase 3 (Backfill):** `backfill-attachments.php` is an exact precedent; no research needed
- **Phase 4 (Link Checker):** `curl_multi_exec` batch pattern exists in `admin.php`; `checker/` tool covers the GET-vs-HEAD edge case already

No phases require a `research-phase` step. All patterns are established in the existing codebase or official PHP documentation.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | HIGH | All technologies are already in production; PHPUnit 11 version confirmed against php.net and phpunit.de official docs |
| Features | HIGH | Based on direct code inspection of all 4 scrapers and PROJECT.md explicit requirements; no ambiguity about what is in scope |
| Architecture | HIGH | Codebase structure is clear; `backfill-attachments.php` is a direct, working precedent for the repair pattern |
| Pitfalls | HIGH | Pitfalls derived from direct code reading, not inference; the pubDate bug and timezone bug are confirmed to exist in the source |

**Overall confidence:** HIGH

### Gaps to Address

- **eHawaii regex failure modes:** Research notes that the regex fails on some items due to possible HTML entity encoding in the description, but the exact failure mode was not reproduced against a live feed. During Phase 1 audit, log the raw description text for any item where the regex fails to confirm the root cause before writing the fix.
- **Legistar `EventTime` field timezone:** PITFALLS.md flags that `EventTime` (the meeting time, separate from `EventDate`) also uses `strtotime()` without a timezone context. This was not in scope for the original date-shift bug but should be confirmed during Phase 2 to avoid introducing a partial fix.
- **Legistar pagination coverage:** The backfill uses `$top=200` to fetch Maui Legistar events; it was not verified whether 200 results covers all future meetings. During Phase 3 design, verify the total count of future Maui meetings against the DB and widen to `$top=500` if needed.
- **NCO/Honolulu Boards date parsing correctness:** Research assessed these as likely correct but explicitly did not confirm against a live feed sample. Phase 1 audit must produce actual test output before Phase 2 marks them as verified.

## Sources

### Primary (HIGH confidence)
- Direct code inspection: `cron/scrape.php`, `cron/scrape-maui-legistar.php`, `cron/scrape-nco.php`, `cron/scrape-honolulu-boards.php`
- Direct code inspection: `scripts/backfill-attachments.php`, `public_html/checker/`, `api/config.php`
- `.planning/PROJECT.md` — explicit requirements and constraints (no schema changes, flag not delete, recent/future only)
- https://phpunit.de/supported-versions.html — PHPUnit version/PHP compatibility matrix
- https://www.php.net/manual/en/class.datetimeimmutable.php — `DateTimeImmutable` API
- https://www.php.net/manual/en/class.datetimezone.php — `DateTimeZone` / IANA timezone identifiers
- https://www.php.net/manual/en/function.curl-multi-exec.php — `curl_multi_exec` API

### Secondary (MEDIUM confidence)
- https://danielrotter.at/2025/04/12/batch-curl-requests-in-php-using-multi-handles.html — Batch curl multi pattern (matches existing codebase usage)

---
*Research completed: 2026-03-16*
*Ready for roadmap: yes*
