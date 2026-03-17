# Feature Research

**Domain:** Scraper validation, link checking, and data backfill for a government meeting aggregator
**Researched:** 2026-03-16
**Confidence:** HIGH — based on direct code inspection of all 4 scrapers and the existing backfill-attachments.php precedent

---

## Feature Landscape

### Table Stakes (Users Expect These)

These are the features implied by the PROJECT.md requirements. A system operator
running this validation milestone expects each of them to exist. Missing any of
them means the milestone is incomplete.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| eHawaii date extraction improvement | Known bug: pubDate fallback produces wrong meeting dates | MEDIUM | Regex `/Date:\s*(\d{4}\/\d{2}\/\d{2})/i` is correct; the fix is explicit logging when falling back, and investigating why the regex fails on some items (malformed HTML entity encoding in description) |
| Maui Legistar explicit HST timezone | `EventDate` parsed via `new DateTime($event_date)` with no timezone — UTC Docker server could shift dates | LOW | One-line fix: pass `new DateTimeZone('Pacific/Honolulu')` to DateTime constructor; needs a test with a known event |
| NCO date parsing audit | Scraper uses `DateTime::createFromFormat('F j, Y', ...)` on description line 0 — correctness must be verified against live feed | LOW | Code looks correct for the documented format; validation means: run against a sample, compare against honolulu.gov web page |
| Honolulu Boards date parsing audit | Same parsing logic as NCO — verify it handles the same edge cases | LOW | Shares identical date-parsing code with NCO; one audit covers both |
| URL link checker for meetings | All `detail_url` and attachment `file_url` values need HTTP status checked | MEDIUM | Already have a link-checker pattern in `public_html/checker/` — that's a WCAG checker, but the HTTP-status-check logic is reusable |
| Broken link report (flag, not delete) | Human needs to decide whether a 404 is permanent or transient | LOW | Output: meeting_id, URL, HTTP status, URL type (detail vs attachment); matches PROJECT.md decision to not auto-remove |
| Date backfill script for recent/future meetings | Re-fetch eHawaii/Legistar/NCO/Honolulu Boards sources, compare stored dates, correct where wrong | HIGH | Highest complexity in the milestone — requires re-parsing each source's live data and handling rate limits; mirrors backfill-attachments.php pattern |
| Verification report | Operator needs counts: meetings checked, corrected, unchanged | LOW | Follows existing scraper stats output pattern (`echo "  Checked: N, Corrected: N\n"`) |
| --dry-run mode on all new scripts | Every existing script supports it; any new script must too | LOW | Pattern is already established; skip it and operators won't trust the script |

### Differentiators (Competitive Advantage)

These go beyond the PROJECT.md requirements. They have meaningful value but are not
required for this milestone to succeed.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Unit-testable date parsing functions | Isolates parsing logic so future feed format changes get caught before hitting production | MEDIUM | Means extracting parse logic from `parse_rss_item()` / `parse_nco_rss_item()` into pure functions that accept a string and return a date; PHP has no built-in test runner — use a simple assertion-based test script or PHPUnit |
| Per-scraper fallback logging | When eHawaii pubDate fallback fires, log the meeting title + council so the pattern of failures is visible | LOW | Currently the fallback happens silently; adding `error_log()` or `echo "  FALLBACK pubDate: {$council['name']} / {$title}"` costs nothing and aids future debugging |
| Throttle-aware backfill | eHawaii and Legistar have implicit rate limits; explicit configurable delay between re-fetch requests | LOW | backfill-attachments.php already uses `usleep(500000)` — the pattern exists, just needs to carry over deliberately with documentation |
| Source-stamped correction records | Log each date correction as `[source] [state_id] [old_date] -> [new_date]` to a file | LOW | Provides an audit trail without schema changes; operators can review what was changed after the fact |

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|-----------------|-------------|
| Auto-removal of broken links | Seems clean — why keep dead URLs? | Government sites go down temporarily; Cloudflare blocks, server maintenance, etc. Auto-delete would remove valid attachments during transient outages | Flag with HTTP status in report; let a human decide what to do |
| Ongoing cron validation job | Seems like continuous quality assurance | Creates a recurring HTTP load on government servers; most broken links stay broken once broken; adds cron complexity for marginal value | Run the link check on-demand or monthly; the backfill is a one-time fix not a cron |
| Schema changes to store validation metadata | Adding `last_validated_at`, `link_status` columns seems tidy | PROJECT.md explicitly says no schema changes; adds migration risk against live DB | Use flat-file or stdout reports for validation results |
| Backfill of meetings older than ~1 week | Comprehensive coverage | Residents act on upcoming meetings, not historical ones; older meetings are already done; rate-limit cost outweighs value | Restrict backfill to `meeting_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)` |
| Interactive correction UI | Easier for non-technical operators to review | Entirely out of scope — this is a scraper/cron layer fix, not a UI project; WordPress admin already exists for corrections | CLI scripts with clear stdout reports; feed output to existing WP admin dashboard if needed |

---

## Feature Dependencies

```
[Improved date parsers (eHawaii + Maui timezone)]
    └──tested by──> [Unit-testable parse functions]
    └──validated by──> [Date backfill script]
                           └──depends on──> [Rate-throttling]
                           └──outputs to──> [Verification report]

[URL link checker]
    └──outputs to──> [Broken link report]
    └──covers──> [detail_url, attachment file_url]

[NCO/Honolulu Boards date audit]
    └──may trigger──> [Date backfill script] (if errors found)
    └──produces──> [Verification report]
```

### Dependency Notes

- **Date backfill requires improved parsers:** The backfill re-fetches from live sources and re-parses. If the parsers are still broken, the backfill will write the same wrong dates back. Parser fixes must land first.
- **NCO and Honolulu Boards share the same date parsing code:** They use identical `DateTime::createFromFormat('F j, Y', ...)` logic on description line 0. A single audit and fix covers both. They can be treated as one task.
- **Unit tests enhance but do not block:** Testable parse functions are a differentiator. The backfill can run without them, but they reduce risk of regression when eHawaii changes its feed format.
- **Link checker is independent:** It has no dependency on date parsing work. Can run in parallel or as a separate phase.
- **Verification report depends on backfill:** The report is the output of the backfill run; it is produced by the same script, not a separate tool.

---

## MVP Definition

### Launch With (v1)

Minimum needed to satisfy the milestone — meeting dates match official sources, broken links are identified.

- [ ] **eHawaii pubDate fallback logging** — without this, operators can't tell how many meetings have incorrect dates; required before backfill to understand scope
- [ ] **Maui Legistar HST timezone fix** — simple, high-confidence fix for a known UTC-shift risk
- [ ] **NCO + Honolulu Boards date parsing verification** — run against live feeds, confirm or fix; may be a no-op if code is already correct
- [ ] **Date backfill script** — re-fetch from all 4 sources, compare, correct recent/future meetings, print verification report; follows backfill-attachments.php pattern exactly
- [ ] **URL link checker script** — check `detail_url` + `file_url` for all active meetings; print broken link report with meeting_id, URL, HTTP status, URL type

### Add After Validation (v1.x)

- [ ] **Unit-testable parse functions** — add when a feed format change breaks dates again; not needed for the one-time fix
- [ ] **Source-stamped correction log file** — useful if operators want to review changes days later; trivial to add, but not urgent

### Future Consideration (v2+)

- [ ] **Periodic automated link checking** — only worthwhile if broken links become a recurring support issue; current volume doesn't justify cron overhead

---

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| eHawaii pubDate fallback logging | HIGH | LOW | P1 |
| Maui Legistar HST fix | HIGH | LOW | P1 |
| NCO/Honolulu Boards date audit | HIGH | LOW | P1 |
| Date backfill script | HIGH | HIGH | P1 |
| Verification report (part of backfill) | HIGH | LOW | P1 |
| URL link checker + broken link report | MEDIUM | MEDIUM | P1 |
| Unit-testable parse functions | MEDIUM | MEDIUM | P2 |
| Source-stamped correction log | LOW | LOW | P2 |
| Ongoing cron validation | LOW | MEDIUM | P3 |

**Priority key:**
- P1: Must have for launch
- P2: Should have, add when possible
- P3: Nice to have, future consideration

---

## Competitor Feature Analysis

Not applicable — this is an internal data quality system, not a product competing
in a market. The relevant comparison is against the existing backfill-attachments.php
pattern in the same codebase, which this milestone should mirror closely for
consistency.

| Pattern | backfill-attachments.php | This milestone |
|---------|--------------------------|----------------|
| --dry-run support | Yes | Required for all scripts |
| Rate throttling | usleep(500000) between fetches | Same pattern |
| stdout progress reporting | [N/total] prefix, stats at end | Same pattern |
| Live DB safety | Filters to upcoming meetings, skips on fetch error | Same — restrict to recent/future, never delete |
| Record keeping | Inline echo + error_log | Same |

---

## Sources

- Direct code inspection: `cron/scrape.php`, `cron/scrape-maui-legistar.php`, `cron/scrape-nco.php`, `cron/scrape-honolulu-boards.php`
- Existing backfill precedent: `scripts/backfill-attachments.php`
- PROJECT.md requirements and constraints (no schema changes, flag not delete, recent/future only, PHP CLI, Docker UTC)

---
*Feature research for: Access100 scraper validation and data backfill*
*Researched: 2026-03-16*
