# Access100 Scraper Validation

## What This Is

A validated and corrected meeting scraper system for the Access100 API. Four scrapers (eHawaii RSS, Maui Legistar API, NCO RSS, Honolulu Boards RSS) now extract dates and times correctly with 19 PHPUnit regression tests, pubDate fallback logging, and `--audit` diagnostic mode. All stored meeting dates and times have been verified against official sources and corrected where needed.

## Core Value

Meeting dates and times displayed on civi.me must match the official government calendar pages — wrong data means residents miss public meetings.

## Requirements

### Validated

- ✓ eHawaii scraper extracts meeting dates reliably with secondary regex for NCO-format descriptions — v1.0
- ✓ eHawaii scraper logs explicit warning on pubDate fallback — v1.0
- ✓ Maui Legistar uses DateTimeImmutable with explicit Pacific/Honolulu timezone — v1.0
- ✓ NCO date parsing audited against live feed and confirmed correct — v1.0
- ✓ Honolulu Boards date parsing audited against live feed and confirmed correct — v1.0
- ✓ All 4 scrapers have fixture-based PHPUnit unit tests — v1.0
- ✓ 77 meeting dates corrected (36 eHawaii + 41 NCO) with full audit trail — v1.0
- ✓ Backfill script supports --dry-run with per-meeting provenance logging — v1.0
- ✓ 824 URLs validated, 0 permanent failures, 306 transient eHawaii PDF timeouts — v1.0
- ✓ Link checker classifies permanent (404/410) vs transient (5xx/timeout) failures — v1.0
- ✓ 74 missing meeting times backfilled from raw_rss_data — v1.0
- ✓ Single-time regex added to all scrapers for descriptions without end time — v1.0

### Active

(None — next milestone not yet planned)

### Out of Scope

- Ongoing cron validation job — scrapers now parse correctly, validation is one-time
- Auto-removal of broken links — flag and report only (human decides)
- 306 transient eHawaii PDF attachment failures — likely CDN rate limiting, not dead links
- meeting_time_end column — does not exist in DB schema, end times logged but not persisted

## Context

**Current state (shipped v1.0):**
- 14,005 LOC PHP across API, scrapers, scripts, and tests
- 19 PHPUnit tests covering all 4 scraper date parsers
- 3 backfill/validation scripts: `validate-dates.php`, `backfill-times.php`, `check-links.php`
- 2 extracted parse helpers: `parse_helpers_ehawaii.php`, `parse_helpers_maui.php`
- All scrapers have `--audit` mode and pubDate fallback `error_log()` warnings
- Council 639 (NCO duplicate source) cleaned up — rss_url cleared, 37 ghost meetings removed
- WordPress timezone display fix shipped in civi.me repo (wp_date UTC passthrough)

**4 Scrapers:**

| Scraper | Source | File | Schedule | Status |
|---------|--------|------|----------|--------|
| eHawaii RSS | `calendar.ehawaii.gov` | `cron/scrape.php` + `parse_helpers_ehawaii.php` | Every 15 min | ✓ Fixed |
| Maui Legistar | `webapi.legistar.com/v1/mauicounty` | `cron/scrape-maui-legistar.php` + `parse_helpers_maui.php` | Hourly +6min | ✓ Hardened |
| NCO | `honolulu.gov/nco/events/feed/` | `cron/scrape-nco.php` | Hourly +2min | ✓ Fixed |
| Honolulu Boards | `honolulu.gov/events/feed/` | `cron/scrape-honolulu-boards.php` | Hourly +4min | ✓ Fixed |

## Constraints

- **Live database**: Changes affect civi.me immediately — must be safe to run against production
- **No schema changes**: Work within existing meetings/attachments tables
- **PHP codebase**: All scraper code is PHP, no build tooling — scripts run directly via CLI or cron
- **Docker environment**: Server timezone is UTC, Hawaii is HST (UTC-10) — timezone handling must be explicit
- **Tests run in container**: Host PHP 8.1 incompatible with PHPUnit 11 (requires 8.2)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Improve parsing inline in scrapers | Prevents future errors at the source | ✓ Good — secondary regex + timezone fix |
| Re-parse stored raw_rss_data for backfill | Deterministic, no external requests, proven correct in audit | ✓ Good — 77 dates + 74 times corrected |
| Flag broken links, don't auto-remove | Links may be temporarily down; human decides | ✓ Good — 306 transient, 0 permanent |
| Extract parse helpers for testability | Functions can be require_once'd by PHPUnit without DB side effects | ✓ Good — 19 tests, all green |
| Single-time regex fallback | Descriptions with only start time (no end) were being missed | ✓ Good — 11 more meetings filled |
| wp_date UTC passthrough for times | API stores local Hawaii time; wp_date was double-converting | ✓ Good — fixed in civi.me repo |

---
*Last updated: 2026-03-17 after v1.0 Scraper Validation milestone*
