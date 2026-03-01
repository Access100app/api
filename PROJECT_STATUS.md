# Access100 API — Project Status

Last updated: 2026-02-28

## What Is This?

Access100 is a REST API that aggregates Hawaii government meeting data from the state's public calendar (calendar.ehawaii.gov) and makes it accessible through a clean JSON API. It powers [civi.me](https://civi.me), a WordPress frontend that helps Hawaii residents stay informed about government meetings, subscribe to notifications, and read AI-generated plain-language summaries of meeting agendas.

**GitHub:** https://github.com/Access100app/api

---

## Architecture

```
┌──────────────┐     RSS Feeds      ┌──────────────────────┐
│  eHawaii      │ ◄──── scrape.php ──│  Access100 API       │
│  Calendar     │                    │  (PHP 8.2 / Apache)  │
└──────────────┘                    │                      │
                                    │  ┌─ endpoints/       │
┌──────────────┐   JSON API calls   │  ├─ services/        │
│  civi.me      │ ──────────────── ►│  ├─ cron/            │
│  (WordPress)  │                    │  └─ middleware/      │
└──────────────┘                    └──────────┬───────────┘
                                               │
                                    ┌──────────▼───────────┐
                                    │  MySQL 8.0            │
                                    │  13 tables             │
                                    └──────────────────────┘
```

- **Stack:** Pure PHP (no framework, no Composer), Apache with mod_rewrite, MySQL 8.0 via PDO
- **Local dev:** Docker (php:8.2-apache + mysql:8.0)
- **Production:** Hostinger shared hosting
- **API subdomain:** access100.app
- **Auth:** API key via `X-API-Key` header, timing-safe comparison

---

## What's Been Built

### API Core (29 PHP files, ~6,700 lines)

| Component | Files | Status |
|-----------|-------|--------|
| **Router** | `index.php` | Routes all requests through middleware chain |
| **Config** | `config.php` | DB, API keys, service credentials, helpers |
| **CORS** | `middleware/cors.php` | Origin whitelist, preflight handling |
| **Auth** | `middleware/auth.php` | API key validation, public route bypass |
| **Rate Limiter** | `middleware/rate-limit.php` | File-based sliding window per IP+key |

### API Endpoints (27 routes)

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/health` | GET | Service health check |
| `/stats` | GET | Meeting counts, notification stats, queue depth |
| `/meetings` | GET | List meetings with filters (date, council, status, search) |
| `/meetings/{id}` | GET | Single meeting with council, attachments, summary |
| `/meetings/{id}/summary` | GET | AI-generated plain-language summary |
| `/meetings/{id}/ics` | GET | iCalendar file download |
| `/councils` | GET | List all councils with meeting counts |
| `/councils/{id}` | GET | Single council detail |
| `/councils/{id}/meetings` | GET | Meetings for a specific council |
| `/subscriptions` | POST | Create a new subscription |
| `/subscriptions/confirm` | GET | Confirm email/SMS via token |
| `/subscriptions/manage` | GET | View subscription preferences |
| `/subscriptions/manage` | PUT | Update subscription preferences |
| `/subscriptions/unsubscribe` | GET/POST | Unsubscribe |
| `/webhooks/sendgrid` | POST | SendGrid event webhook |
| `/webhooks/twilio` | POST | Twilio inbound SMS webhook |
| `/topics` | GET | List topic categories |
| `/topics/{id}/meetings` | GET | Meetings by topic |

### Services

| Service | File | Description |
|---------|------|-------------|
| **AI Summarizer** | `services/summarizer.php` | Claude API (Sonnet 4.6) generates HTML summaries of meeting agendas. Extracts text from agenda PDFs via `pdftotext` when calendar text is thin (<300 chars). |
| **Email** | `services/email.php` | SendGrid integration for confirmation emails, meeting alerts, and digests |
| **SMS** | `services/sms.php` | Twilio integration with quiet hours (8 AM–9 PM HST), queue for off-hours |
| **Topic Classifier** | `services/topic-classifier.php` | Categorizes meetings into topics |

### Cron Jobs

| Script | Schedule | Description |
|--------|----------|-------------|
| `cron/scrape.php` | Every 15 min | Polls 422 council RSS feeds from eHawaii, upserts meetings + attachments |
| `cron/notify.php` | Every 15 min | Detects changed meetings, sends immediate notifications, queues digests |
| `cron/summarize.php` | Every 30 min | Generates AI summaries for meetings without them |
| `cron/digest.php` | Daily 7 AM HST | Sends daily digest emails/SMS |
| `cron/weekly-digest.php` | Mon 7 AM HST | Sends weekly digest |
| `cron/classify-topics.php` | On demand | Classifies meetings into topic categories |
| `cron/cleanup.php` | On demand | Database maintenance |

All cron scripts support `--dry-run` for safe testing.

### Database (13 tables)

| Table | Records | Purpose |
|-------|---------|---------|
| `meetings` | 223 | Government meeting data (title, date, time, location, agenda, summary) |
| `councils` | 422 | Government bodies with RSS feed URLs |
| `attachments` | 291 | PDF/document attachments linked to meetings |
| `poll_state` | 422 | Per-council RSS polling state (guid dedup, last poll time) |
| `scraper_state` | 3 | Cron run history for scraper and notifier |
| `topics` | 16 | Meeting topic categories |
| `meeting_topics` | 0 | Meeting-to-topic mapping (not yet populated) |
| `topic_council_map` | — | Council-to-topic mapping |
| `users` | 1 | Subscriber accounts (email, phone, confirmation tokens) |
| `subscriptions` | 1 | Council subscriptions (channels, frequency) |
| `notifications_log` | 0 | Sent notification history |
| `keyword_subscriptions` | — | Keyword-based subscription matching |
| `google_calendar_sync` | — | Google Calendar integration tracking |

### Migrations

| Migration | Status | Description |
|-----------|--------|-------------|
| `001-extend-subscriptions.sql` | Applied | Users, subscriptions, notification_log, scraper_state tables |
| `002-topics.sql` | Applied | Topics, meeting_topics, topic_council_map tables |
| `003-council-profiles.sql` | **Not applied** | Council profiles, members, vacancies, legal authority |

### Infrastructure

- **Docker:** `docker-compose.yml` with app + db services, connected to NPM network
- **SSL:** Let's Encrypt via Nginx Proxy Manager for access100.app
- **Dockerfile:** PHP 8.2, mod_rewrite, pdo_mysql, poppler-utils (PDF extraction)
- **Git:** Daily auto-push to GitHub via cron (`scripts/daily-push.sh` at 11:30 PM)
- **.gitignore:** Excludes .env, SQL dumps, legacy code with hardcoded credentials

### AI Summaries

- **46 meetings** have AI summaries (all future meetings as of 2026-02-28)
- **177 meetings** still need summaries (past meetings)
- Output format: semantic HTML (`<h3>`, `<p>`, `<ul>`, `<li>`, `<strong>`, `<em>`)
- Three sections: "What's Being Discussed", "Decisions Expected", "Who Should Pay Attention"
- PDF enrichment: when calendar text is < 300 chars, downloads agenda PDF attachments and extracts text via `pdftotext`

### WordPress Frontend (civi.me) — Separate Repo

The WordPress frontend at civi.me is complete with:
- Custom theme with design tokens, dark mode, WCAG 2.1 AA compliance
- `civime-core` plugin: API client with transient caching
- `civime-meetings` plugin: meeting list, detail, and councils views with filters
- `civime-notifications` plugin: subscribe, manage, confirm, and unsubscribe flows
- Content pages: Home, About, Get Involved, Toolkit, Letter Kit, Testify, Privacy, Events

---

## What's Left To Do

### Critical — Blocking Notifications

| # | Task | Impact |
|---|------|--------|
| 1 | **Configure SendGrid API key** | No emails can be sent — confirmations, alerts, digests all broken |
| 2 | **Configure Twilio credentials** | No SMS can be sent — blocked until TCPA consent language added |
| 3 | **Generate secure API key** | Currently using placeholder `changeme-generate-a-64-char-random-key` |

### Database

| # | Task | Impact |
|---|------|--------|
| 4 | **Create notification_queue table** | Required for SMS quiet hours queuing and digest scheduling |
| 5 | **Apply migration 003** | Council profiles, members, vacancies tables not yet created |

### Infrastructure

| # | Task | Impact |
|---|------|--------|
| 6 | **Schedule cron jobs in Docker** | Scraper, notifier, summarizer, digests not running automatically |
| 7 | **Add TCPA consent language** | Must be on subscribe form before SMS can legally be sent |

### Data

| # | Task | Impact |
|---|------|--------|
| 8 | **Generate AI summaries for 177 past meetings** | Archive and search experience improved |
| 9 | **Run topic classification** | meeting_topics table is empty — topic-based browsing won't work |

### Validation & Launch

| # | Task | Impact |
|---|------|--------|
| 10 | **End-to-end notification test** | Subscribe → confirm → change detected → alert received (never tested) |
| 11 | **WCAG audit** | Accessibility compliance across all pages |
| 12 | **Deploy to Hostinger production** | Blocked by #1, #2, #3, #5 |
| 13 | **Configure production cron jobs** | Hostinger cPanel cron setup |
| 14 | **DNS: point civi.me to home server** | Frontend goes live |

### Recommended Order

```
#3 Generate API key
#4 Create notification_queue table
#5 Apply migration 003
#1 Configure SendGrid
#7 Add TCPA consent language
#2 Configure Twilio
#6 Schedule cron jobs
#8 AI summaries for past meetings
#9 Run topic classification
#10 End-to-end test
#11 WCAG audit
#12 Deploy to production
#13 Production cron jobs
#14 DNS cutover → Go live
```

---

## Key Files

| File | Purpose |
|------|---------|
| `public_html/api/index.php` | API router — all requests flow through here |
| `public_html/api/config.php` | Database, API keys, service credentials, helpers |
| `public_html/api/API_GUIDE.md` | Comprehensive API documentation (27 endpoints) |
| `public_html/api/TCPA_COMPLIANCE.md` | SMS compliance plan and checklist |
| `.env` | Environment variables (not in git) |
| `docker-compose.yml` | Local development setup |
| `Dockerfile` | PHP 8.2 + Apache + pdftotext |
| `scripts/daily-push.sh` | Automated daily git push |

---

## Quick Start (Local Development)

```bash
cd ~/dev/Access100/app\ website

# Copy and configure environment
cp .env.example .env
# Edit .env with your credentials

# Start containers
docker compose up -d

# Run database migrations
docker exec appwebsite-app-1 php /var/www/html/api/migrations/run.php

# Test health endpoint
curl http://localhost:8082/api/v1/health

# Run scraper (dry run)
docker exec appwebsite-app-1 php /var/www/html/api/cron/scrape.php --dry-run

# Run scraper (real)
docker exec appwebsite-app-1 php /var/www/html/api/cron/scrape.php

# Generate AI summaries
docker exec appwebsite-app-1 php /var/www/html/api/cron/summarize.php --limit=10
```
