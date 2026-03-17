# Requirements: Access100 Scraper Validation

**Defined:** 2026-03-16
**Core Value:** Meeting dates displayed on civi.me must match the dates on the official government calendar pages.

## v1 Requirements

Requirements for this milestone. Each maps to roadmap phases.

### Date Parsing

- [x] **DATE-01**: eHawaii scraper extracts meeting dates from RSS description field reliably, with improved regex handling for format variations
- [x] **DATE-02**: eHawaii scraper logs an explicit warning when pubDate fallback is used instead of extracting from description
- [x] **DATE-03**: Maui Legistar scraper uses DateTimeImmutable with explicit Pacific/Honolulu timezone so UTC Docker environment doesn't shift dates
- [x] **DATE-04**: NCO scraper date parsing is audited against live feed samples and confirmed correct or fixed
- [x] **DATE-05**: Honolulu Boards scraper date parsing is audited against live feed samples and confirmed correct or fixed

### Testing

- [x] **TEST-01**: eHawaii date parser has fixture-based unit tests covering regex match, pubDate fallback, and edge cases
- [x] **TEST-02**: Maui Legistar date parser has fixture-based unit tests covering timezone handling
- [x] **TEST-03**: NCO date parser has fixture-based unit tests covering the DateTime::createFromFormat pattern
- [x] **TEST-04**: Honolulu Boards date parser has fixture-based unit tests covering the DateTime::createFromFormat pattern

### Backfill

- [ ] **BKFL-01**: A backfill script re-fetches dates from original sources for meetings from ~1 week ago onward and corrects mismatches
- [ ] **BKFL-02**: Backfill script supports --dry-run mode that reports what would change without modifying the database
- [ ] **BKFL-03**: Backfill script produces a verification report showing total meetings checked, corrected, and unchanged
- [ ] **BKFL-04**: Backfill script logs before/after values for every corrected meeting (meeting ID, old date, new date, source)
- [ ] **BKFL-05**: Backfill script tracks provenance — identifies which stored dates originated from pubDate fallback vs parsed description

### Link Validation

- [ ] **LINK-01**: A link checker script validates all stored URLs: detail_url, agenda links, and attachment file_url
- [ ] **LINK-02**: Link checker uses batched GET requests (curl_multi, not HEAD) to avoid false positives from government servers
- [ ] **LINK-03**: Link checker produces a report with meeting ID, URL, HTTP status code for every broken link
- [ ] **LINK-04**: Link checker report classifies failures as permanent (404/410) or transient (5xx/timeout)

## v2 Requirements

### Ongoing Validation

- **ONGV-01**: Recurring cron job validates dates after each scrape run
- **ONGV-02**: Automated alerting when date mismatches are detected
- **ONGV-03**: Dashboard showing scraper health and data quality metrics

## Out of Scope

| Feature | Reason |
|---------|--------|
| New scraper sources | Only fixing the existing 4 scrapers |
| API endpoint changes | This is scraper/cron layer only |
| WordPress frontend changes | Those live in the civi.me repo |
| Auto-removal of broken links | Flag and report only — human decides |
| Meetings older than ~1 week | Only recent/future meetings get backfilled |
| Schema changes | Work within existing tables |

## Traceability

Which phases cover which requirements. Updated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| DATE-01 | Phase 2 | Complete |
| DATE-02 | Phase 1 | Complete |
| DATE-03 | Phase 2 | Complete |
| DATE-04 | Phase 1 | Complete |
| DATE-05 | Phase 1 | Complete |
| TEST-01 | Phase 2 | Complete |
| TEST-02 | Phase 2 | Complete |
| TEST-03 | Phase 2 | Complete |
| TEST-04 | Phase 2 | Complete |
| BKFL-01 | Phase 3 | Pending |
| BKFL-02 | Phase 3 | Pending |
| BKFL-03 | Phase 3 | Pending |
| BKFL-04 | Phase 3 | Pending |
| BKFL-05 | Phase 3 | Pending |
| LINK-01 | Phase 4 | Pending |
| LINK-02 | Phase 4 | Pending |
| LINK-03 | Phase 4 | Pending |
| LINK-04 | Phase 4 | Pending |

**Coverage:**
- v1 requirements: 18 total
- Mapped to phases: 18
- Unmapped: 0

---
*Requirements defined: 2026-03-16*
*Last updated: 2026-03-16 after roadmap creation*
