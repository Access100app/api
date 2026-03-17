# Access100 Scraper Validation

## What This Is

A validation and correction layer for the Access100 meeting scraper system. The 4 scrapers (eHawaii RSS, Maui Legistar API, NCO RSS, Honolulu Boards RSS) currently store meeting data without verifying accuracy against official sources. This project improves date parsing to prevent future errors, validates all stored URLs, and backfills corrected dates for recent/upcoming meetings.

## Core Value

Meeting dates displayed on civi.me must match the dates on the official government calendar pages — wrong dates mean residents miss public meetings.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] eHawaii scraper extracts meeting dates from RSS description field reliably, with explicit fallback logging when pubDate is used instead
- [ ] Maui Legistar scraper handles timezone explicitly (HST) so Docker UTC doesn't shift dates
- [ ] NCO scraper date parsing is validated against source feed structure
- [ ] Honolulu Boards scraper date parsing is validated against source feed structure
- [ ] All stored URLs (detail_url, agenda links, attachment file_url) are checked for liveness (HTTP status)
- [ ] Broken links are flagged in a report (not auto-removed) with meeting ID, URL, and HTTP status
- [ ] Meetings from ~1 week ago onward have dates re-fetched from original sources and corrected where wrong
- [ ] A verification report confirms how many meetings were checked, how many corrected, and what changed
- [ ] Scraper date parsing improvements are unit-testable with sample RSS/API payloads

### Out of Scope

- New scraper sources — only fixing the existing 4
- Access100 API endpoint changes — this is scraper/cron layer only
- WordPress frontend changes — those live in the civi.me repo
- Ongoing cron validation job — this is a one-time fix + improved parsing
- Auto-removal of broken links — flag and report only
- Meetings older than ~1 week ago — only recent/future meetings get backfilled

## Context

**4 Scrapers:**

| Scraper | Source | File | Schedule |
|---------|--------|------|----------|
| eHawaii RSS | `calendar.ehawaii.gov` RSS feeds per council | `cron/scrape.php` | Every 15 min |
| Maui Legistar | `webapi.legistar.com/v1/mauicounty` JSON API | `cron/scrape-maui-legistar.php` | Hourly +6min |
| NCO | `honolulu.gov/nco/events/feed/` RSS | `cron/scrape-nco.php` | Hourly +2min |
| Honolulu Boards | `honolulu.gov/events/feed/` RSS | `cron/scrape-honolulu-boards.php` | Hourly +4min |

**Known Date Parsing Issues:**

- **eHawaii:** Uses regex `/Date:\s*(\d{4}\/\d{2}\/\d{2})/i` on RSS description. Falls back to `pubDate` (RSS publication timestamp, not meeting date) when regex fails. This is the primary source of wrong dates.
- **Maui Legistar:** Parses `EventDate` like `"2026-03-04T00:00:00"` without timezone specifier. Server runs UTC in Docker, which could shift dates relative to HST.
- **NCO/Honolulu Boards:** Not yet audited for date parsing issues but need validation.

**Database Schema (relevant tables):**

- `meetings` — `id`, `state_id`, `external_id`, `council_id`, `title`, `meeting_date`, `meeting_time`, `detail_url`, `zoom_link`, `status`, `source`
- `attachments` — `id`, `meeting_id`, `file_name`, `file_url`, `file_type`
- `poll_state` — tracks last scrape per council
- `scrape_history` — run statistics

**Existing Scripts:**
- `scripts/` directory has backfill precedent (`backfill-attachments.php`)
- All scrapers support `--dry-run` mode

## Constraints

- **Live database**: Changes affect civi.me immediately — must be safe to run against production
- **External API rate limits**: eHawaii and Legistar have implicit rate limits — must throttle re-fetch requests
- **No schema changes**: Work within existing meetings/attachments tables
- **PHP codebase**: All scraper code is PHP, no build tooling — scripts run directly via CLI or cron
- **Docker environment**: Server timezone is UTC, Hawaii is HST (UTC-10) — timezone handling must be explicit

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Improve parsing inline in scrapers | Prevents future errors at the source rather than post-hoc validation | — Pending |
| Re-fetch from original sources for backfill | No known-good reference exists; official feeds are the authority | — Pending |
| Flag broken links, don't auto-remove | Links may be temporarily down; human should decide | — Pending |
| Backfill only recent/future meetings | Older meetings are historical; residents act on upcoming ones | — Pending |

---
*Last updated: 2026-03-16 after initialization*
