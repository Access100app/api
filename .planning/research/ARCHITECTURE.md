# Architecture Research

**Domain:** Scraper validation and data correction layer (PHP CLI scripts)
**Researched:** 2026-03-16
**Confidence:** HIGH — based on direct reading of existing codebase

## Standard Architecture

### System Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     External Sources                        │
│  ┌──────────────┐ ┌────────────────┐ ┌──────────────────┐  │
│  │ eHawaii RSS  │ │ Maui Legistar  │ │ NCO / HNL Boards │  │
│  │  (per-council│ │   JSON API     │ │    RSS feeds     │  │
│  │  RSS feeds)  │ │                │ │                  │  │
│  └──────┬───────┘ └───────┬────────┘ └────────┬─────────┘  │
└─────────┼─────────────────┼──────────────────-┼────────────┘
          │                 │                   │
┌─────────┼─────────────────┼──────────────────-┼────────────┐
│         ↓                 ↓                   ↓            │
│         [scrape.php] [scrape-maui-legistar.php] [scrape-nco.php]
│         [scrape-honolulu-boards.php]                       │
│              ↓ parse_*_item()                              │
│         ┌────────────────────────────┐                     │
│         │   Date Parsing Layer       │  ← TARGET FOR FIX   │
│         │  (inline in each scraper)  │                     │
│         └────────────┬───────────────┘                     │
│                      ↓                                     │
│         ┌────────────────────────────┐                     │
│         │   DB Upsert (meetings +    │                     │
│         │     attachments tables)    │                     │
│         └────────────────────────────┘                     │
│                                                            │
│  ┌──────────────────────────────────────────────────────┐  │
│  │                 Validation Layer (NEW)                │  │
│  │  ┌───────────────┐  ┌──────────────┐  ┌──────────┐  │  │
│  │  │ Date backfill │  │  URL checker │  │ Reports  │  │  │
│  │  │   (script)    │  │   (script)   │  │ (output) │  │  │
│  │  └───────────────┘  └──────────────┘  └──────────┘  │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                            │
│                     cron/ + scripts/                       │
└────────────────────────────────────────────────────────────┘
          ↓                                        ↓
┌──────────────────────┐               ┌──────────────────────┐
│   meetings table     │               │  attachments table   │
│   poll_state         │               │  scrape_history      │
└──────────────────────┘               └──────────────────────┘
```

### Component Responsibilities

| Component | Responsibility | Current Location |
|-----------|----------------|------------------|
| eHawaii scraper | Polls per-council RSS, parses Date/Time from description, falls back to pubDate | `cron/scrape.php` |
| Maui Legistar scraper | Polls JSON API, parses EventDate as `new DateTime()` without timezone | `cron/scrape-maui-legistar.php` |
| NCO scraper | Polls RSS, parses `F j, Y` format from description line 0, falls back to pubDate | `cron/scrape-nco.php` |
| Honolulu Boards scraper | Same feed structure as NCO, same date parsing approach | `cron/scrape-honolulu-boards.php` |
| Date parsing (inline) | Regex/DateTime extraction embedded in each scraper's `parse_*_item()` function | Inside each cron file |
| Backfill script | One-time repair script, queries DB, re-fetches from source, updates rows | `scripts/backfill-attachments.php` (pattern) |
| URL checker | Batch HTTP status checks, flags broken links | `checker/` (separate tool) |
| DB upsert | `INSERT ... ON DUPLICATE KEY UPDATE` or explicit find+update pattern | Each scraper |
| config.php | DB singleton, env vars, shared helpers | `api/config.php` |

## Recommended Project Structure

The existing codebase uses a flat structure with separation between ongoing cron scripts and one-time scripts:

```
public_html/api/
├── cron/                    # Ongoing scheduled jobs (run repeatedly)
│   ├── scrape.php           # eHawaii — fix date parsing here
│   ├── scrape-maui-legistar.php  # Maui — add DateTimeZone('HST') here
│   ├── scrape-nco.php       # NCO — audit and harden date parsing here
│   └── scrape-honolulu-boards.php  # HNL Boards — same as NCO
├── scripts/                 # One-time or manual-run scripts
│   ├── backfill-attachments.php  # Existing precedent for backfill pattern
│   ├── validate-dates.php   # NEW: re-fetch + correct recent meeting dates
│   └── check-links.php      # NEW: batch URL liveness check + report
└── tests/                   # Unit tests (currently has test-subscriptions.sh)
    └── test-date-parsing.php  # NEW: isolated date parsing assertions
```

### Structure Rationale

- **cron/ vs scripts/:** The codebase already draws a clear line. Ongoing fixes live in `cron/` (inline in scrapers). One-time repair operations live in `scripts/`. Follow this convention — don't add a new top-level directory.
- **tests/:** The directory exists for `test-subscriptions.sh`. PHP unit tests for date parsing functions go here as PHP scripts, not a PHPUnit suite (no build tooling exists).
- **Inline date parsing:** All four scrapers embed parsing inside a `parse_*_item()` function. Fixes go inline — no separate "parser library" file needed for a 4-scraper codebase.

## Architectural Patterns

### Pattern 1: Inline Parse Function

**What:** Each scraper has a single `parse_*_item()` function that accepts raw feed data and returns a normalized array or `null`. All date extraction happens inside this function.

**When to use:** Always — this is the existing pattern. The fix targets this function in each scraper.

**Trade-offs:** Keeps each scraper self-contained. The downside is that identical fallback logic (pubDate fallback) is duplicated across scrapers — acceptable for 4 scrapers, not worth abstracting.

**Example (eHawaii current pattern to fix):**
```php
// Current: falls back silently to pubDate
if (empty($date_str)) {
    $date_str = date('Y-m-d', strtotime($item->pubDate));
}

// Fixed: explicit fallback with log warning
if (empty($date_str)) {
    error_log("eHawaii scraper: date regex failed for item '{$title}', falling back to pubDate");
    $date_str = date('Y-m-d', strtotime((string) $item->pubDate));
}
```

### Pattern 2: Backfill Script

**What:** A standalone `scripts/` PHP script that queries the database for a scoped set of meetings, re-fetches from the original source, compares, updates, and emits a text report to stdout.

**When to use:** Any one-time or repair operation. Existing model is `scripts/backfill-attachments.php`.

**Trade-offs:** CLI-only, no HTTP endpoint. Runs as `php api/scripts/validate-dates.php`. Supports `--dry-run` flag. Writes a summary to stdout captured in a log file. This is the right approach for production-safe repair work.

**Key conventions from existing backfill script:**
- `--dry-run` flag prints what would change without writing
- Throttle with `usleep(500000)` (0.5s) between external HTTP calls
- Report counts at the end: total checked, updated, skipped, errored
- Use `get_db()` singleton from `config.php`
- Date scope filter: `meeting_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)` for recent/future

### Pattern 3: Explicit Timezone in Legistar

**What:** Maui Legistar returns `"2026-03-04T00:00:00"` (no timezone indicator). PHP `new DateTime()` interprets this using the server's default timezone (UTC in Docker). The fix is to construct with explicit `DateTimeZone`:

**When to use:** Any scraper that receives a naive datetime string from a Hawaii government source.

**Trade-offs:** One-liner fix. Must confirm that Legistar always means HST midnight, not UTC midnight — re-fetch a known meeting's date to validate before applying.

**Example:**
```php
// Current (wrong when Docker is UTC):
$dt = new DateTime($event_date);

// Fixed:
$dt = new DateTime($event_date, new DateTimeZone('Pacific/Honolulu'));
$meeting_date = $dt->format('Y-m-d');
```

### Pattern 4: URL Liveness Check

**What:** Batch HTTP HEAD requests against stored URLs. Flag failures rather than deleting. Output a structured report.

**When to use:** `check-links.php` script. Not part of ongoing cron (out of scope per PROJECT.md).

**Trade-offs:** HEAD requests are polite (no body download) but some servers return 405 for HEAD. Use GET with a short timeout as fallback. The existing `checker/` tool in this repo already handles this pattern — look at it before writing new curl logic.

**Key conventions:**
- Groups of 20 concurrent requests max (per existing checker pattern)
- 10-second timeout per request
- Log: meeting_id, URL, HTTP status, URL type (detail_url / agenda / attachment)
- Do not auto-delete — output only

## Data Flow

### Scraper Run Flow (existing, targeted for inline fix)

```
cron trigger
    ↓
scraper.php --dry-run or live
    ↓
fetch feed (HTTP GET → external source)
    ↓
parse_*_item(raw feed data)
    ↓  ← date parsing fix lives here
normalized array [title, meeting_date, detail_url, ...]
    ↓
find_existing_meeting(pdo, parsed)
    ↓             ↓
[exists]      [new]
update        insert
    ↓
scrape_history record + stdout summary
```

### Date Backfill Flow (new script)

```
php scripts/validate-dates.php [--dry-run] [--source=ehawaii]
    ↓
SELECT meetings WHERE meeting_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    AND source IN ('ehawaii', 'maui_legistar', 'nco', 'honolulu_boards')
    ↓
for each meeting:
    re-fetch original source (RSS item or API event)
    parse date using corrected parser (same logic as fixed scraper)
    compare stored date vs re-fetched date
    if mismatch and not dry-run:
        UPDATE meetings SET meeting_date = ? WHERE id = ?
        log: "CORRECTED meeting #{id}: {old} → {new}"
    else:
        log: "OK meeting #{id}: {date}"
    usleep(500000)  ← throttle
    ↓
emit report: total checked, corrected, skipped, errors
```

### URL Check Flow (new script)

```
php scripts/check-links.php [--dry-run]
    ↓
SELECT detail_url FROM meetings WHERE meeting_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
UNION
SELECT file_url FROM attachments WHERE meeting_id IN (recent meetings)
    ↓
deduplicate URLs
    ↓
batch into groups of 20
    ↓
for each group:
    parallel curl HEAD requests (10s timeout)
    on 405: retry with GET (Range: bytes=0-0)
    collect [url, http_status, meeting_id, url_type]
    ↓
emit report: total checked, broken (non-2xx), 404s specifically, other errors
```

## Scaling Considerations

This is a cron + scripts system, not a web service. Scaling concerns are different:

| Concern | Current Scale | Consideration |
|---------|--------------|---------------|
| External HTTP rate limits | ~100 meetings / backfill run | Throttle at 0.5s per request; eHawaii and Legistar have implicit limits |
| DB write safety | Live production DB | Always use `--dry-run` first; UPDATE is scoped to recent/future meetings only |
| Re-fetch coverage | ~1 week of meetings per scraper | Don't expand scope — older meetings are historical, not actionable |
| Script runtime | Expected < 5 minutes | No timeout concern; run manually or via one-off cron |

## Anti-Patterns

### Anti-Pattern 1: Silent pubDate Fallback

**What people do:** Fall back to `pubDate` when date regex fails, with no log entry.

**Why it's wrong:** The pubDate on eHawaii RSS is when the record was published to the feed, not when the meeting occurs. A meeting published on March 10 for a March 25 meeting date gets stored as March 10. No log entry means this is invisible.

**Do this instead:** Log a warning with `error_log()` before the fallback so it's visible in cron logs. Better yet, fix the regex to be more robust so fallback is rare.

### Anti-Pattern 2: Naive DateTime Construction for Hawaii Sources

**What people do:** `new DateTime($event_date)` where `$event_date` has no timezone suffix.

**Why it's wrong:** PHP uses the server's default timezone. Docker containers default to UTC. Hawaii is UTC-10 (HST). A meeting at midnight HST becomes 10:00 AM UTC, which shifts the Y-m-d date by a day when formatted.

**Do this instead:** `new DateTime($event_date, new DateTimeZone('Pacific/Honolulu'))` whenever the source is a Hawaii government system that omits timezone markers.

### Anti-Pattern 3: Auto-Deleting Broken Links

**What people do:** Remove records with broken links automatically.

**Why it's wrong:** Links can be temporarily down (server maintenance, SSL renewal, CMS migration). Auto-deletion of meeting records causes data loss that is not reversible without re-scraping.

**Do this instead:** Flag and report only. Let a human decide whether a link is permanently dead.

### Anti-Pattern 4: Single Script for Both Fix and Validation

**What people do:** Build one script that fixes scraper parsing AND runs the backfill AND checks links.

**Why it's wrong:** These operations have different risk profiles. Date parsing fixes are permanent code changes; backfill touches live data; URL checking is read-only. Mixing them makes `--dry-run` ambiguous and makes it harder to run only part of the operation.

**Do this instead:** Three separate deliverables — inline fixes in each scraper, a `validate-dates.php` script, and a `check-links.php` script. Run them independently and sequentially.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| calendar.ehawaii.gov | HTTP GET RSS feed per council RSS URL stored in `councils.rss_url` | No auth; per-council URL; throttle between councils |
| webapi.legistar.com/v1/mauicounty | HTTP GET JSON API with `$filter` and `$orderby` OData params | No auth required; returns EventDate without timezone |
| honolulu.gov/nco/events/feed/ | HTTP GET RSS; single feed for all NCO boards | No auth; board identified by `board_number` in title |
| honolulu.gov/events/feed/ | HTTP GET RSS; single feed for all Honolulu Boards | Same structure as NCO feed |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| cron scripts ↔ database | Direct PDO via `get_db()` singleton in `config.php` | No ORM, no repository abstraction |
| scripts/ ↔ cron/ | No direct coupling; scripts re-use same source fetch functions | Best approach: copy/inline the fetch function or require the cron file and call the parse function directly |
| backfill script ↔ summarizer | `require_once services/summarizer.php` then call `summarize_meeting($id)` | Only needed if date correction triggers re-summarization (probably not needed for this milestone) |
| test scripts ↔ parse functions | PHP test file requires the cron file, calls `parse_*_item()` with fixture data | No PHPUnit; plain PHP with `assert()` or manual pass/fail echo |

## Suggested Build Order

Dependencies flow as follows:

```
1. Audit date parsing in all 4 scrapers  (read-only, no risk)
    ↓
2. Write test fixtures + test-date-parsing.php  (validates understanding before changes)
    ↓
3. Fix parse_*_item() in each scraper  (inline fix, can be verified by re-running scraper --dry-run)
    ↓
4. Build validate-dates.php  (depends on corrected parsing logic being proven)
    ↓
5. Run validate-dates.php --dry-run  (verify what would change before touching production)
    ↓
6. Run validate-dates.php live  (apply corrections)
    ↓
7. Build check-links.php  (independent of date work, can be done in parallel with steps 4-6)
    ↓
8. Run check-links.php and review output
```

**Why this order:**
- Tests before fixes catches misunderstandings early
- Scraper fixes must precede backfill so the re-fetch uses the same corrected logic
- URL checking is independent — no dependency on date correctness

## Sources

- Direct reading of `cron/scrape.php`, `cron/scrape-maui-legistar.php`, `cron/scrape-nco.php`, `cron/scrape-honolulu-boards.php` (HIGH confidence)
- Direct reading of `scripts/backfill-attachments.php` for backfill pattern (HIGH confidence)
- Direct reading of `api/config.php` for shared infrastructure (HIGH confidence)
- `.planning/PROJECT.md` for requirements and constraints (HIGH confidence)
- PHP DateTime/DateTimeZone documentation (HIGH confidence — standard library behavior)

---
*Architecture research for: Scraper validation and data correction layer*
*Researched: 2026-03-16*
