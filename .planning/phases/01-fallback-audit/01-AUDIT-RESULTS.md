# Phase 1 Audit Results

**Date:** 2026-03-17
**Ran by:** Claude (plan 01-02)
**Source:** Live Docker execution against production database (`appwebsite-app-1`)

## Summary

| Scraper | Meetings Checked | pubDate Fallbacks | Mismatches | Verdict |
|---------|-----------------|-------------------|------------|---------|
| eHawaii | 348 | 35 | 0 | pubDate fallbacks present but dates matched — logging needed, parser may be OK |
| NCO | 44 | 0 | 41 | 41 mismatches found — fix needed |
| Honolulu Boards | 21 | 0 | 0 | 0 mismatches — parsing correct |
| Maui Legistar | 14 | N/A | N/A | no timezone shift evidence in stored data (no weekend dates found) |

## eHawaii

```
[2026-03-17 06:22:30] Scraper starting...
[2026-03-17 06:22:30] eHawaii audit mode starting...
  Source values found: ehawaii
  Meetings with raw_rss_data (source=ehawaii): 348

--- eHawaii ---
  pubDate fallbacks found: 35
  Date mismatches: 0
  Verdict: pubDate fallbacks present but dates matched — logging needed, parser may be OK

[2026-03-17 06:22:30] Done in 0.05s.
```

**Source column check (run before audits):**
```
ehawaii: 348
nco: 44
honolulu_boards: 21
maui_legistar: 14
```

## NCO

```
[2026-03-17 06:22:30] NCO scraper starting...
[2026-03-17 06:22:30] NCO audit mode starting...
  Meetings with raw_rss_data (source=nco): 44

--- NCO ---
  pubDate fallbacks found: 0
  Date mismatches: 41
  [NCO] meeting_id=281 | stored: 2026-04-03 | would_parse: 2026-04-02 | title: 31. Kailua NB Regular Meeting
  [NCO] meeting_id=280 | stored: 2026-04-03 | would_parse: 2026-04-02 | title: 13. Downtown-Chinatown NB Regular Meeting
  [NCO] meeting_id=279 | stored: 2026-04-02 | would_parse: 2026-04-01 | title: 07. Mānoa NB Regular Meeting
  [NCO] meeting_id=278 | stored: 2026-04-02 | would_parse: 2026-04-01 | title: 02. Kuli'ou'ou-Kalani Iki Regular Meeting
  [NCO] meeting_id=277 | stored: 2026-04-01 | would_parse: 2026-03-31 | title: 01. Hawaiʻi Kai NB Regular Meeting
  [NCO] meeting_id=276 | stored: 2026-03-31 | would_parse: 2026-03-30 | title: 08. McCully-Mōʻili'ili NB Regular Meeting
  [NCO] meeting_id=275 | stored: 2026-03-27 | would_parse: 2026-03-26 | title: 22. Waipahu NB Recess
  [NCO] meeting_id=274 | stored: 2026-03-26 | would_parse: 2026-03-25 | title: 25. Mililani-Waipiʻo NB Regular Meeting
  [NCO] meeting_id=273 | stored: 2026-03-26 | would_parse: 2026-03-25 | title: 34. Makakilo-Kapolei NB Regular Meeting
  [NCO] meeting_id=271 | stored: 2026-03-25 | would_parse: 2026-03-24 | title: 21. Pearl City NB Regular Meeting
  [NCO] meeting_id=270 | stored: 2026-03-25 | would_parse: 2026-03-24 | title: 11. Ala Moana-Kakaʻako NB Regular Meeting
  [NCO] meeting_id=272 | stored: 2026-03-25 | would_parse: 2026-03-24 | title: 27. North Shore NB Regular Meeting
  [NCO] meeting_id=269 | stored: 2026-03-20 | would_parse: 2026-03-19 | title: 03. Waiʻalae-Kāhala NB Regular Meeting
  [NCO] meeting_id=268 | stored: 2026-03-20 | would_parse: 2026-03-19 | title: 30. Kāneʻohe NB Regular Meeting
  [NCO] meeting_id=267 | stored: 2026-03-20 | would_parse: 2026-03-19 | title: 31. Kailua NB Homelessness Committee
  [NCO] meeting_id=266 | stored: 2026-03-20 | would_parse: 2026-03-19 | title: 10. Makiki-Tantalus Regular Meeting
  [NCO] meeting_id=265 | stored: 2026-03-19 | would_parse: 2026-03-18 | title: 04. Kaimukī NB Regular Meeting
  [NCO] meeting_id=264 | stored: 2026-03-19 | would_parse: 2026-03-18 | title: 15. Kalihi-Pālama NB Regular Meeting
  [NCO] meeting_id=260 | stored: 2026-03-18 | would_parse: 2026-03-17 | title: 31. Kailua NB Community and Government Engagement Committee
  [NCO] meeting_id=263 | stored: 2026-03-18 | would_parse: 2026-03-17 | title: 36. Nānākuli-Māʻili NB Regular Meeting
  [NCO] meeting_id=262 | stored: 2026-03-18 | would_parse: 2026-03-17 | title: 35. Mililani Mauka-Launani Valley NB Recess
  [NCO] meeting_id=261 | stored: 2026-03-18 | would_parse: 2026-03-17 | title: 12. Nuʻuanu-Punchbowl NB Recess
  [NCO] meeting_id=259 | stored: 2026-03-17 | would_parse: 2026-03-16 | title: 26. Wahiawā-Whitmore Village NB Regular Meeting
  [NCO] meeting_id=258 | stored: 2026-03-13 | would_parse: 2026-03-12 | title: 23. ʻEwa NB Regular CANCELLATION
  [NCO] meeting_id=257 | stored: 2026-03-13 | would_parse: 2026-03-12 | title: 28. Koʻolauloa NB CANCELLATION
  [NCO] meeting_id=256 | stored: 2026-03-13 | would_parse: 2026-03-12 | title: 18. Āliamanu-Salt Lake NB CANCELLATION
  [NCO] meeting_id=255 | stored: 2026-03-13 | would_parse: 2026-03-12 | title: 29. Kailua NB Kailua Water Quality Committee CANCELLATION
  [NCO] meeting_id=253 | stored: 2026-03-12 | would_parse: 2026-03-11 | title: 06. Pālolo NB CANCELLED
  [NCO] meeting_id=252 | stored: 2026-03-12 | would_parse: 2026-03-11 | title: 16. Kalihi Valley NB CANCELLED
  [NCO] meeting_id=251 | stored: 2026-03-12 | would_parse: 2026-03-11 | title: 29. Kahaluʻu NB Regular CANCELLATION
  [NCO] meeting_id=249 | stored: 2026-03-11 | would_parse: 2026-03-10 | title: 09. Waikīkī NB Regular Meeting
  [NCO] meeting_id=250 | stored: 2026-03-11 | would_parse: 2026-03-10 | title: 20. ʻAiea NB Regular Meeting
  [NCO] meeting_id=248 | stored: 2026-03-10 | would_parse: 2026-03-09 | title: 32. Waimānalo NB Regular Meeting
  [NCO] meeting_id=247 | stored: 2026-03-10 | would_parse: 2026-03-09 | title: 14. Liliha-ʻĀlewa NB Regular Meeting
  [NCO] meeting_id=246 | stored: 2026-03-06 | would_parse: 2026-03-05 | title: 31. Kailua NB Regular Meeting
  [NCO] meeting_id=245 | stored: 2026-03-06 | would_parse: 2026-03-05 | title: 36. Nānākuli-Māʻili NB Transportation Committee Meeting
  [NCO] meeting_id=244 | stored: 2026-03-06 | would_parse: 2026-03-05 | title: 13. Downtown-Chinatown NB Regular Meeting
  [NCO] meeting_id=243 | stored: 2026-03-05 | would_parse: 2026-03-04 | title: 07. Mānoa NB Regular Meeting
  [NCO] meeting_id=242 | stored: 2026-03-05 | would_parse: 2026-03-04 | title: 02. Kuli'ou'ou-Kalani Iki Regular Meeting
  [NCO] meeting_id=238 | stored: 2026-02-19 | would_parse: 2026-02-18 | title: 07. Mānoa NB Proactive Solutions Committee Meeting
  Verdict: 41 mismatches found — fix needed

[2026-03-17 06:22:30] Done in 0s.
```

## Honolulu Boards

```
[2026-03-17 06:22:31] Honolulu Boards scraper starting...
[2026-03-17 06:22:31] Honolulu Boards audit mode starting...
  Meetings with raw_rss_data (source=honolulu_boards): 21

--- Honolulu Boards ---
  pubDate fallbacks found: 0
  Date mismatches: 0
  Verdict: 0 mismatches — parsing correct

[2026-03-17 06:22:31] Done in 0s.
```

## Maui Legistar

```
[2026-03-17 06:22:32] Maui Legistar scraper starting...
[2026-03-17 06:22:32] Maui Legistar audit mode starting...
  Assessing timezone shift evidence in stored meeting_date values...

  Maui meetings total: 14
  With raw_rss_data: 0
  Note: raw_rss_data is NULL for all Maui meetings (expected — Maui uses JSON API, not RSS).
  Falling back to heuristic timezone shift check.

  Recent meetings checked (last 90 days): 14

--- Maui Legistar ---
  Timezone assessment: UTC→HST shift evidence check
  Meetings checked: 14
  Meetings on weekend (potential UTC shift): 0
  Verdict: no timezone shift evidence in stored data (no weekend dates found)
  Note: Definitive confirmation requires live Legistar API re-fetch — see RESEARCH.md

[2026-03-17 06:22:32] Done in 0s.
```

## Phase 1 Answers

**Q: How many recent eHawaii meetings used pubDate fallback?**
A: 35 out of 348 meetings (10%) used pubDate fallback. All 35 had matching dates — the fallback produced the correct date — so no data corruption occurred. However, these 35 silent fallbacks are invisible to operators and must be logged (error_log was added in Plan 01-01).

**Q: Does NCO need a parser fix?**
A: Yes. 41 out of 44 meetings (93%) show a mismatch where stored dates are exactly 1 day later than what re-parsing would produce. This is a systematic off-by-one error affecting virtually all NCO meetings. Fix needed before any backfill.

**Q: Does Honolulu Boards need a parser fix?**
A: No. 0 mismatches across 21 meetings. Parsing is correct.

**Q: Does Maui Legistar show UTC timezone shift evidence?**
A: No weekend-date meetings found in the last 90 days (14 meetings checked). No heuristic evidence of UTC→HST shift in stored data. Definitive confirmation would require live Legistar API re-fetch for a sample — raw_rss_data is NULL for all Maui rows (Maui uses a JSON API, not RSS). Low risk based on available data.

## NCO Mismatch Pattern Analysis

The NCO mismatch is **systematic and consistent**: every affected meeting shows `stored = would_parse + 1 day`. This is not a parsing failure for individual meetings — it is a date-off-by-one that applies to 41 of 44 rows in the database.

**Likely root cause:** The NCO scraper is running on a server set to UTC. The RSS `pubDate` or description date is interpreted in UTC, but the actual meeting date is Hawaii time (HST = UTC-10). A meeting posted at, say, 10:00 AM HST on March 4 is 8:00 PM UTC on March 4 — but when the description says "March 4" and the server parses in UTC context, late-night Hawaii posts could be interpreted as next-day UTC. Alternatively, the stored date may be the scrape/publication date rather than the actual meeting date.

**Impact:** All 41 affected NCO meetings show dates that are 1 day too late. Residents checking civi.me would see a meeting listed one day after it actually occurs.

## Phase 2 Scope

Based on this audit, Phase 2 parser fixes are needed for:
- **NCO** — 41 mismatches (systematic +1 day error on 93% of stored meetings); fix required before backfill
- **eHawaii** — 0 mismatches but 35 pubDate fallbacks; parser is functionally correct, but the logging added in Plan 01-01 should be verified in production to confirm `error_log()` fires correctly on future fallbacks

No fix needed for:
- **Honolulu Boards** — 0 mismatches, 0 fallbacks; parsing is correct
- **Maui Legistar** — no timezone shift evidence; low risk based on heuristic check

Logging improvements needed for:
- **eHawaii** — 35 pubDate fallbacks already firing silently; logging added in Plan 01-01 (requires production verification)
- **NCO** and **Honolulu Boards** — logging added in Plan 01-01 (no fallbacks currently, but warning infrastructure is now in place)
