# Stack Research

**Domain:** PHP scraper validation, link checking, date backfill
**Researched:** 2026-03-16
**Confidence:** HIGH

## Context: What Already Exists

This milestone adds to a functioning PHP 8.2 codebase. No new runtime or framework is being introduced. The existing stack is:

- PHP 8.2-apache (Dockerfile FROM line — confirmed)
- PDO + MariaDB 10.11
- `curl` / `curl_multi_exec` for HTTP fetching (used in all scrapers and the admin link checker)
- `SimpleXMLElement` / `DOMDocument` / `DOMXPath` for XML and HTML parsing
- No Composer, no autoloader, no test suite

The validation work stays entirely within this existing runtime. No new external runtime dependencies are needed.

---

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP 8.2 | 8.2.x (locked by Dockerfile) | Runtime for all scripts | Already the production environment. No version change needed or warranted. |
| `DateTimeImmutable` + `DateTimeZone` | PHP built-in | Explicit timezone-safe date parsing | Immutable variant prevents accidental mutation bugs common in scraper loops. Use `'Pacific/Honolulu'` (not `'HST'`) — canonical IANA identifier handles DST edge cases correctly. Confidence: HIGH (PHP.net official docs). |
| `curl_multi_exec` | PHP built-in | Batched parallel URL liveness checks | Already used in admin link checker (`admin.php` lines 1828–1885). The pattern — batch of 20, `curl_multi_select`, collect results — is the right approach. No library needed. Confidence: HIGH (existing codebase). |
| `SimpleXMLElement` | PHP built-in | RSS feed parsing in scrapers | Already used in all four scrapers. The fix is in parsing logic, not the parsing library. |
| PDO | PHP built-in | Database reads/writes | Already established. Use existing `get_db()` singleton. |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| PHPUnit | 11.x (`^11.0`) | Unit testing date-parsing functions | Use for testing `parse_rss_item()`, `map_legistar_event()`, and any extracted parsing helpers against sample RSS/API payload fixtures. PHPUnit 11 requires PHP 8.2+ — matches the Dockerfile exactly. PHPUnit 12 requires PHP 8.3, which is not the production version. Confidence: HIGH (phpunit.de/supported-versions.html). |
| Composer (dev-only) | 2.x | Install PHPUnit in dev environment | Install Composer only in the dev environment. Do NOT add it to the Dockerfile — tests run locally, not inside the container. The production container has no test dependencies. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| `php` CLI | Run backfill and validation scripts | All scripts are already designed as CLI-runnable with `--dry-run`. No changes needed. |
| `php -r` / `php -l` | Syntax check before deploying | Quick sanity check: `php -l public_html/api/cron/scrape.php` |
| PHPUnit CLI | Run unit tests for parsing functions | `./vendor/bin/phpunit tests/` from project root |
| `date -u` / `TZ=Pacific/Honolulu date` | Shell-level timezone verification | Verify Docker container sees UTC, manual conversion check |

---

## Installation

```bash
# Dev environment only — do NOT add to Dockerfile
cd "/home/patrickgartside/dev/Access100/app website"

# Bootstrap Composer if not present
curl -sS https://getcomposer.org/installer | php

# Create composer.json (dev-only, tests are never deployed)
# Then install:
php composer.phar require --dev phpunit/phpunit "^11.0"

# Run tests
./vendor/bin/phpunit tests/
```

No production installation step. The Dockerfile does not change.

---

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| PHPUnit 11 | PHPUnit 10 | If the host PHP were still 8.1 (not the case — Dockerfile uses 8.2). PHPUnit 10 support ended February 2025. |
| PHPUnit 11 | Pest | Pest is a wrapper around PHPUnit with a more expressive syntax. Good choice for new projects, but adds unnecessary friction here — the tests are a handful of data-provider cases for pure parsing functions, not a full suite. PHPUnit directly is simpler. |
| `curl_multi_exec` (built-in) | Guzzle async | Guzzle is excellent but introduces a Composer dependency for a script that currently has zero dependencies. The existing `curl_multi` pattern already works correctly in `admin.php`. Extract it, don't replace it. |
| `DateTimeImmutable` (built-in) | Carbon | Carbon is the right call for Laravel or complex date manipulation. For this project — parsing two date formats and converting one timezone — Carbon is dependency weight with no gain. |
| Inline fixture arrays / XML strings | Fixture files on disk | Either works for PHP testing. For RSS payloads (short XML fragments), inline strings in the data provider are easier to read and maintain than separate fixture files. For longer payloads, files in `tests/fixtures/` are fine. |

---

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `new DateTime()` (mutable) | Mutable DateTime in a loop can silently hold state between iterations if you forget to clone. `setTimezone()` modifies the object in place. | `new DateTimeImmutable()` — every operation returns a new instance, so the original is never mutated. |
| `strtotime()` for timezone-sensitive parsing | `strtotime("2026-03-04T00:00:00")` with no timezone context interprets the string in the server's local timezone (UTC in Docker). This is exactly the Maui date-shift bug. | `new DateTimeImmutable('2026-03-04T00:00:00', new DateTimeZone('Pacific/Honolulu'))` — timezone is explicit at parse time. |
| `date_default_timezone_set('Pacific/Honolulu')` globally | Changing the process-level timezone is a blunt instrument that affects every date operation in the script, including DB timestamps and log entries. | Pass `DateTimeZone` objects explicitly per parse operation. Leave the process timezone as UTC. |
| CURLOPT_NOBODY (HEAD requests) for link checking | Some government servers (honolulu.gov, ehawaii.gov) reject HEAD and return 405. The existing admin link checker uses full GET — keep that pattern for the standalone link-check script. | `CURLOPT_RETURNTRANSFER => true` with GET, check HTTP status code only, discard body. |
| Auto-retrying broken links during the scan | If a link is temporarily down, an auto-retry loop can hit rate limits or cause false positives in the "fixed" count. | Flag all non-2xx/3xx as broken in the report. Let a human decide what to re-check. The PROJECT.md constraint is explicit on this. |
| Composer in the production Dockerfile | The production container is Apache + PHP + pdo_mysql + poppler-utils. Test dependencies have no place there. | Composer and PHPUnit are local dev tools only. The test directory is gitignored or excluded from Docker COPY if needed. |

---

## Stack Patterns by Variant

**For date parsing unit tests:**
- Extract the pure parsing logic (regex + date construction) into a standalone function that takes a string input and returns a `DateTimeImmutable|null`
- Test that function with PHPUnit `#[DataProvider]` pointing to an array of `[input_string, expected_Y_m_d]` pairs
- No database, no HTTP — tests run in milliseconds and need no mock infrastructure

**For the link-check script:**
- Reuse the `curl_multi` batch pattern from `admin.php` lines 1828–1885 verbatim — it already handles batching, timeout, and error collection correctly
- The new script is a standalone CLI file in `scripts/` (matching the `backfill-attachments.php` precedent)
- Output: a plain-text report to stdout (meeting ID, URL, HTTP status), no database writes

**For the date backfill script:**
- Model it on `backfill-attachments.php`: `--dry-run` flag, progress counter `[N/total]`, `usleep(500000)` throttle between external fetches
- Re-fetch from the original source (RSS feed or Legistar API) rather than trying to re-parse the stored `raw_rss_data` — the raw data may contain the same bug that caused the wrong date
- Write changed dates to the `meetings` table with a logged "changed: YYYY-MM-DD → YYYY-MM-DD" line per correction

---

## Version Compatibility

| Package | Compatible With | Notes |
|---------|-----------------|-------|
| PHPUnit `^11.0` | PHP 8.2 (Dockerfile) | Exact match. PHPUnit 11 support ends February 2026 — budget an upgrade to PHPUnit 12 when Dockerfile moves to PHP 8.3. |
| PHPUnit `^12.0` | PHP 8.3+ | NOT compatible with PHP 8.2. Do not use until Dockerfile is updated. |
| `DateTimeImmutable` | PHP 5.5+ | Has been stable for a decade. No compatibility concerns. |
| `curl_multi_exec` | PHP 5.0+ | Core PHP extension. Present in the Dockerfile's `php:8.2-apache` base image. |

---

## Sources

- https://phpunit.de/supported-versions.html — PHPUnit version/PHP compatibility matrix (HIGH confidence, official)
- https://www.php.net/manual/en/class.datetimeimmutable.php — `DateTimeImmutable` API (HIGH confidence, official)
- https://www.php.net/manual/en/class.datetimezone.php — `DateTimeZone` / IANA timezone identifiers (HIGH confidence, official)
- https://www.php.net/manual/en/function.curl-multi-exec.php — `curl_multi_exec` API (HIGH confidence, official)
- https://danielrotter.at/2025/04/12/batch-curl-requests-in-php-using-multi-handles.html — Batch curl multi pattern (MEDIUM confidence, matches existing codebase usage)
- Existing codebase: `admin.php` lines 1828–1885, `scrape.php`, `scrape-maui-legistar.php`, `backfill-attachments.php` — patterns confirmed from source (HIGH confidence, primary source)

---

*Stack research for: Access100 scraper validation, link checking, and date backfill*
*Researched: 2026-03-16*
