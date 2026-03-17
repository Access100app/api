# Pitfalls Research

**Domain:** Scraper validation layer — date parsing fixes and data backfill for existing PHP scrapers
**Researched:** 2026-03-16
**Confidence:** HIGH (based on direct code inspection of all 4 scrapers + backfill precedent)

---

## Critical Pitfalls

### Pitfall 1: Backfill Overwrites Manual Corrections

**What goes wrong:**
The backfill script re-fetches dates from official sources and writes them back to the DB. If a date was manually corrected in the DB (outside the scraper), the backfill blindly overwrites it with whatever the feed currently says — which may be the wrong pubDate again if the regex still fails.

**Why it happens:**
Backfill logic is simpler to write as "fetch → compare → update" without tracking the correction provenance. The `has_meeting_changed()` functions currently compare DB value against freshly-fetched value, so any DB value that differs from the feed triggers an update.

**How to avoid:**
Log every correction with its before/after values and source. Never overwrite a field silently. Consider a `date_source` column (values: `parsed`, `pubdate_fallback`, `manual_correction`) so backfill can skip meetings that were manually corrected. At minimum, produce a diff report before writing anything.

**Warning signs:**
- Backfill reports N corrections but you can count fewer actually wrong rows in the DB
- A meeting you know was correct shows up in the "corrected" list

**Phase to address:** Backfill script design phase — before any DB writes happen

---

### Pitfall 2: pubDate Fallback Silently Persists After the Fix

**What goes wrong:**
The eHawaii scraper falls back to `pubDate` when the `Date:` regex fails (line 301-306 in `scrape.php`). If the fix improves the regex but doesn't eliminate all failure cases, meetings will continue to get wrong dates with no visible signal — the fallback path has no persistent indicator in the stored data.

**Why it happens:**
The fallback currently stores the date without any marker that it came from pubDate rather than the parsed description. After the fix ships, there is no way to tell which stored dates are pubDate-derived without re-running the parser against `raw_rss_data`.

**How to avoid:**
Add explicit logging whenever pubDate fallback fires (already partially done per PROJECT.md requirements). More importantly, store a `date_source` field or log the reason in `raw_rss_data` so future audits can distinguish parsed vs. fallback dates. Unit tests should include RSS items where the `Date:` field is missing or malformed to verify fallback behavior.

**Warning signs:**
- Scraper log shows "using pubDate fallback" for meetings you expect to have a real `Date:` field
- Meeting dates cluster around the RSS publication date rather than spreading across the calendar

**Phase to address:** eHawaii parser fix phase — when writing the improved regex

---

### Pitfall 3: Maui Legistar Timezone Shift Goes Undetected

**What goes wrong:**
`map_legistar_event()` does `new DateTime($event_date)` on `"2026-03-04T00:00:00"` with no timezone. PHP's `DateTime` constructor uses the process default timezone, which in Docker is UTC. This means `T00:00:00` midnight UTC is correct — but if the API ever returns a time component (e.g., `T10:00:00` for 10 AM HST), PHP treats it as UTC, which is 10 AM UTC = 20:00 HST the previous day. The meeting date would shift by one day.

**Why it happens:**
The API currently returns midnight timestamps, masking the bug. A future API change that includes actual meeting times would silently produce wrong dates. The fix that "works" for today's data is not future-proof.

**How to avoid:**
Explicitly specify HST timezone when constructing the DateTime: `new DateTime($event_date, new DateTimeZone('Pacific/Honolulu'))`. This is safe regardless of whether the time component is midnight or an actual time. Document that HST is the authoritative timezone for all Maui Legistar data.

**Warning signs:**
- Meetings scheduled for early morning showing up the day before
- Any Maui meeting date that is off by exactly one day
- The Legistar API response starts including non-midnight time components

**Phase to address:** Maui Legistar parser fix phase

---

### Pitfall 4: Synthetic state_id Collisions on Same-Day Meetings

**What goes wrong:**
NCO and Honolulu Boards use synthetic state IDs of the form `nco-{board}-{YYYYMMDD}` and `hnl-{slug}-{YYYYMMDD}`. If the same board has two meetings on the same date (rescheduled, special session, continued meeting posted separately), the second one gets the same state_id as the first and is silently deduplicated — never stored.

**Why it happens:**
The synthetic ID schema assumes one meeting per board per day, which holds for regular meetings but breaks for special or emergency sessions.

**How to avoid:**
During the audit phase, run a query checking for duplicate `(council_id, meeting_date)` pairs in the DB to see if any same-day meetings exist. If none exist historically, document the assumption explicitly. If they do exist, the synthetic ID scheme needs a suffix (e.g., append a sequence counter or hash of the title).

**Warning signs:**
- Scraper reports an item as "skipped" (duplicate key, code 23000) but the meeting is genuinely different
- Board schedule shows two meetings on same date but DB only has one

**Phase to address:** Audit/validation phase — before changing the ID scheme

---

### Pitfall 5: Backfill Rate Limiting Hits Production Scrapers

**What goes wrong:**
The backfill script re-fetches from the same external sources (eHawaii, Legistar, honolulu.gov) that the production cron scrapers are already polling. Running a backfill while cron scrapers are active can trigger implicit rate limits or temporary blocks on those sources.

**Why it happens:**
eHawaii and Legistar have no published rate limit documentation, but government servers often throttle aggressively. The existing backfill precedent (`backfill-attachments.php`) uses `usleep(500000)` (0.5s) between requests — that was for detail pages (honolulu.gov), not for eHawaii or Legistar which may have stricter limits.

**How to avoid:**
- Run the backfill during off-peak hours (not at :00, :02, :04, :06 past the hour when scrapers fire)
- Add explicit throttling per source: eHawaii RSS (1s minimum), Legistar API (2s minimum)
- Test with `--dry-run` first to confirm scope before running live
- If the backfill covers many meetings, run in batches (e.g., `--limit=20`) rather than all at once

**Warning signs:**
- HTTP 429 or 503 responses in backfill output
- Production scraper logs showing fetch failures in the same time window as a backfill run
- eHawaii site becomes unresponsive for the IP

**Phase to address:** Backfill script design phase — before running against production

---

### Pitfall 6: URL Checker False-Positives Triggering Manual Review Noise

**What goes wrong:**
The existing link checker (`checker/`) was specifically fixed for false positives caused by HEAD-only requests and concurrency. A new URL validation pass that re-uses different checking logic (or uses HEAD without the same mitigations) will produce false positives, forcing manual review of links that are actually live.

**Why it happens:**
Government document servers frequently reject HEAD requests (returning 403 or 405) even when GET succeeds. Attachment URLs on hnldoc.ehawaii.gov and honolulu.gov are particularly prone to this. The existing checker was hardened by using GET with batched sequential requests — new validation code that copies an older pattern will regress this fix.

**How to avoid:**
Reuse or extend the existing checker logic rather than writing a new HTTP check from scratch. If new code is needed, always use GET (not HEAD). Test against known-live attachment URLs before running the full batch. Treat 405 Method Not Allowed as "indeterminate, not broken."

**Warning signs:**
- Validation report flags attachments as broken but they open fine in a browser
- All attachments from a single domain are flagged (domain-level HEAD rejection vs. per-file 404)

**Phase to address:** URL validation phase — define the checking method before building the report

---

## Technical Debt Patterns

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|----------------|-----------------|
| No `date_source` column | Simpler backfill, no schema change | Cannot distinguish parsed vs. pubDate-fallback dates post-fix; future audits require re-parsing `raw_rss_data` | Only acceptable if `raw_rss_data` is always populated and audits always re-derive from it |
| Running all 4 scrapers with one backfill pass | Fewer scripts to write | Harder to isolate per-source bugs; if one source is down, the whole run stalls | Never — run per-source scripts that can be rerun independently |
| Backfill without `--dry-run` first | Faster | Irreversible changes to production DB with no preview | Never |
| Unit tests only cover the happy path | Faster to write | Fallback paths (pubDate, missing Date field, malformed time) remain untested; the bugs will recur | Never for critical parsing paths |
| Treating HTTP 404 and HTTP 503 the same as "broken" | Simpler report | Transient outages get flagged as permanent broken links; inflation of false positives | Never — at minimum distinguish permanent (404, 410) from transient (5xx, timeout) |

---

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|----------------|------------------|
| eHawaii RSS | Using `pubDate` as meeting date without checking `description` first | Always attempt `Date:` regex on description; log explicit reason when pubDate fallback fires |
| Maui Legistar API | Constructing `DateTime` without timezone on `EventDate` | Always pass `new DateTimeZone('Pacific/Honolulu')` to `DateTime` constructor |
| eHawaii `$top=200` / Legistar pagination | Assuming 200 results covers all future meetings | For backfill, widen the date filter or use `$top=500`; verify total count against DB |
| honolulu.gov detail pages | Expecting `em-event-content` class to always exist | The existing fallback to `//article` is correct; do not remove it |
| hnldoc.ehawaii.gov document downloads | Using HEAD to check liveness | Always use GET; 403 from HEAD does not mean the file is gone |

---

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|----------------|
| Per-row SELECT before INSERT in attachment de-dupe | Slow on large backfills; N selects for N attachments | Use `INSERT IGNORE` or `ON DUPLICATE KEY UPDATE` with a unique index on `(meeting_id, file_url)` | At ~500 attachments per backfill run |
| Re-fetching Legistar API for every backfill meeting individually | API rate limiting; slow run | Fetch all events once, build in-memory map keyed by `EventId`, do DB updates from the map | Immediately if >20 meetings |
| Running backfill and production cron simultaneously | Source rate limits; duplicate partial writes | Stagger: run backfill off-cron-schedule, or temporarily pause cron | Any time both run within the same 15-minute window |

---

## Security Mistakes

Not a primary concern for this project (no new attack surface from validation scripts). Standard risks:

| Mistake | Risk | Prevention |
|---------|------|------------|
| Logging full meeting content to disk during backfill | Log files on public-accessible path could expose agenda text | Write logs to `/var/log/` not `public_html/`; existing cron scripts do this correctly |
| Running backfill scripts via web request | Direct web execution of scripts in `public_html/api/scripts/` | Scripts already require CLI (no `$_GET`/`$_POST` handling); keep them CLI-only |

---

## "Looks Done But Isn't" Checklist

- [ ] **eHawaii date fix:** Parser improved for the known format, but not tested against RSS items where `Date:` is absent entirely, or where the description contains multiple `Date:` patterns — verify the regex handles both edge cases
- [ ] **Maui timezone fix:** `new DateTimeZone('Pacific/Honolulu')` added to DateTime constructor, but `EventTime` field (the meeting time, separate from date) also uses `strtotime()` without timezone — verify time parsing is also HST-aware
- [ ] **Backfill report:** Script produces a summary count ("X corrected"), but the report must also log the individual changes (meeting ID, old date, new date, source) for manual review — counts alone are not verifiable
- [ ] **URL validation report:** Reports meetings with broken links, but the report needs to include the meeting date and council name, not just `meeting_id` and URL — otherwise the report is not actionable without a second DB lookup
- [ ] **NCO/Honolulu Boards date audit:** Assumed to be correct because they parse from `DateTime::createFromFormat('F j, Y', ...)` rather than pubDate fallback, but the fallback to pubDate is still present if the `date_time_line` pattern doesn't match — needs explicit test coverage
- [ ] **Unit tests run in isolation:** Tests must not require a live DB connection — use fixture arrays for `parse_rss_item()` tests so CI can run them without Docker

---

## Recovery Strategies

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|----------------|
| Backfill overwrites correct dates | MEDIUM | `raw_rss_data` column preserves original feed content; re-run the corrected parser against `raw_rss_data` to restore correct dates |
| Wrong dates stored for recent meetings | LOW | Run backfill with `--dry-run` first to identify scope; run live with meeting-ID filter to fix only affected rows |
| Rate limited by eHawaii or Legistar | LOW | Wait 30-60 minutes; reduce batch size to `--limit=10`; add longer delays between requests |
| Synthetic state_id collision discovered post-backfill | HIGH | Requires manual identification of which meeting record is correct; may need to create new rows with corrected state_ids and delete duplicates |
| URL validation report has false positives | LOW | Re-check flagged URLs with browser; filter 5xx/timeout from 404/410 in the report; no DB changes needed |

---

## Pitfall-to-Phase Mapping

| Pitfall | Prevention Phase | Verification |
|---------|------------------|--------------|
| Backfill overwrites manual corrections | Backfill script design | Dry-run output shows only genuinely wrong dates being corrected |
| pubDate fallback persists silently | eHawaii parser fix | Unit test with fixture item missing `Date:` field; log output shows fallback reason |
| Maui timezone shift undetected | Maui Legistar parser fix | Unit test with non-midnight `EventDate` confirms date is same in UTC and HST |
| Synthetic state_id collisions | Audit phase (before writing) | Query `SELECT council_id, meeting_date, COUNT(*) FROM meetings GROUP BY council_id, meeting_date HAVING COUNT(*) > 1` |
| Backfill rate limiting production | Backfill script design | Run at off-cron time; monitor scraper logs during backfill execution |
| URL checker false positives | URL validation phase | Test checker against 5 known-live attachment URLs before full run |
| Backfill without preview | Backfill script design | `--dry-run` is required before any live run (enforce via documentation or a `--confirm` flag) |

---

## Sources

- Direct code inspection: `cron/scrape.php`, `cron/scrape-maui-legistar.php`, `cron/scrape-nco.php`, `cron/scrape-honolulu-boards.php`
- Backfill precedent: `scripts/backfill-attachments.php`
- PROJECT.md — known issues documented by project owner
- Existing link checker behavior (false positive mitigation already implemented)

---
*Pitfalls research for: scraper validation and date correction layer*
*Researched: 2026-03-16*
