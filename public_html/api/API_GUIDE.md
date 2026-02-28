# Access100 REST API Guide

**Base URL:** `https://app.access100.app/api/v1`

The Access100 API provides programmatic access to Hawaii government meeting data, council information, AI-generated meeting summaries, topic-based discovery, and notification subscriptions.

---

## Authentication

Most endpoints require an API key sent via the `X-API-Key` header:

```
X-API-Key: your-api-key-here
```

Keys are validated using timing-safe comparison. Each key has a label used for rate-limit tracking and logging.

**Public endpoints** (no key required): `health`, `stats`, subscription confirm/unsubscribe, webhooks.

---

## Response Format

### Success

```json
{
  "data": { ... },
  "meta": {
    "api_version": "v1",
    "timestamp": "2026-02-28T09:30:00+00:00"
  }
}
```

### Error

```json
{
  "error": {
    "code": 404,
    "message": "Meeting not found."
  }
}
```

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200  | Success |
| 201  | Created (new subscription) |
| 204  | No content (CORS preflight) |
| 302  | Redirect (confirm/unsubscribe flows) |
| 400  | Invalid request |
| 401  | Missing or invalid API key |
| 404  | Resource not found |
| 405  | Method not allowed |
| 429  | Rate limit exceeded |
| 500  | Internal server error |
| 503  | Service unavailable |

---

## Rate Limiting

- **100 requests** per **15-minute** sliding window (per IP + API key)
- Rate limit headers are always included:

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1709100000
```

When exceeded, a `429` response is returned with a `Retry-After` header.

---

## Endpoints

### API Root

```
GET /api/v1/
```

Returns a list of all available endpoints. No authentication required.

---

### Health

```
GET /api/v1/health
```

**Auth:** Public

Returns API status, database connectivity, and operational metrics.

**Response:**

```json
{
  "data": {
    "status": "ok",
    "version": "v1",
    "database": "connected",
    "meetings_count": 223,
    "upcoming_meetings_count": 46,
    "councils_count": 422,
    "last_meeting_date": "2026-03-25",
    "last_scrape": "2026-02-28 09:00:00",
    "queue_depth": 5,
    "notifications_24h": {
      "sent": 42,
      "failed": 1,
      "bounced": 0
    }
  }
}
```

If the database is unreachable, returns `"status": "degraded"` and `"database": "disconnected"`.

---

### Stats

```
GET /api/v1/stats
```

**Auth:** Public

Returns platform-wide statistics.

**Response:**

```json
{
  "data": {
    "total_meetings": 5000,
    "upcoming_meetings": 123,
    "meetings_with_summaries": 2500,
    "total_councils": 305,
    "councils_with_upcoming": 89,
    "active_subscribers": 42,
    "total_notifications_sent": 1500,
    "coverage": {
      "earliest_meeting": "2024-01-15",
      "latest_meeting": "2026-06-30"
    }
  }
}
```

---

### Meetings

#### List Meetings

```
GET /api/v1/meetings
```

**Auth:** API Key

**Query Parameters:**

| Parameter   | Type       | Default    | Description |
|-------------|------------|------------|-------------|
| `date_from` | YYYY-MM-DD | Today      | Meetings on or after this date |
| `date_to`   | YYYY-MM-DD | —          | Meetings on or before this date |
| `council_id`| integer    | —          | Filter by council |
| `q`         | string     | —          | Search title, description, council name (max 200 chars) |
| `topics`    | string     | —          | Comma-separated topic slugs, e.g. `environment,education` |
| `limit`     | integer    | 50         | Results per page (1–200) |
| `offset`    | integer    | 0          | Pagination offset |

**Response:**

```json
{
  "data": [
    {
      "state_id": "76181",
      "title": "Board of Education Regular Meeting",
      "meeting_date": "2026-03-05",
      "meeting_time": "09:00:00",
      "location": "Hawaii State Capitol, Room 312",
      "status": "scheduled",
      "detail_url": "https://calendar.ehawaii.gov/calendar/meeting/76181/details.html",
      "council": {
        "id": 36,
        "name": "Board of Education",
        "parent_name": null
      }
    }
  ],
  "meta": {
    "total": 150,
    "limit": 50,
    "offset": 0,
    "has_more": true
  }
}
```

Results are ordered by `meeting_date ASC, meeting_time ASC`.

---

#### Meeting Detail

```
GET /api/v1/meetings/{state_id}
```

**Auth:** API Key

Returns complete meeting information including agenda text, AI summary, attachments, and topic tags.

**Response:**

```json
{
  "data": {
    "state_id": "76181",
    "title": "Board of Education Regular Meeting",
    "meeting_date": "2026-03-05",
    "meeting_time": "09:00:00",
    "location": "Hawaii State Capitol, Room 312",
    "detail_url": "https://calendar.ehawaii.gov/...",
    "zoom_link": "https://zoom.us/...",
    "status": "scheduled",
    "description": "Full agenda text...",
    "summary_text": "<h3>What's Being Discussed</h3><p>...</p>",
    "council": {
      "id": 36,
      "name": "Board of Education",
      "parent_name": null
    },
    "attachments": [
      {
        "file_name": "agenda.pdf",
        "file_url": "https://calendar.ehawaii.gov/calendar/attachment/...",
        "file_type": "pdf"
      }
    ],
    "topics": {
      "direct": [
        {
          "slug": "education",
          "name": "Education",
          "icon": "📚",
          "source": "direct",
          "confidence": 0.95
        }
      ],
      "inherited": [
        {
          "slug": "budget",
          "name": "Budget & Finance",
          "icon": "📊",
          "relevance": "secondary"
        }
      ]
    }
  }
}
```

- `description` is HTML-stripped agenda text (prefers `full_agenda_text`, falls back to `description`)
- `summary_text` is AI-generated HTML (may be `null` if not yet generated)
- `topics.direct` are AI-classified tags for this specific meeting
- `topics.inherited` are topic mappings from the council level
- `state_id` must match `[a-zA-Z0-9_-]{1,50}`

---

#### Meeting Summary

```
GET /api/v1/meetings/{state_id}/summary
```

**Auth:** API Key

Returns only the AI summary for a meeting.

**Response:**

```json
{
  "data": {
    "state_id": "76181",
    "council_name": "Board of Education",
    "title": "Board of Education Regular Meeting",
    "summary_text": "<h3>What's Being Discussed</h3>..."
  }
}
```

Returns `404` if no summary is available.

---

#### iCalendar Download

```
GET /api/v1/meetings/{state_id}/ics
```

**Auth:** API Key

Returns an `.ics` file for import into calendar apps.

**Content-Type:** `text/calendar; charset=utf-8`
**Content-Disposition:** `attachment; filename="meeting-{state_id}.ics"`

This endpoint does NOT use the JSON envelope — it outputs raw iCalendar data. Timezone is `Pacific/Honolulu`. Duration defaults to 1 hour unless start/end times are detected in the agenda text.

---

### Councils

#### List Councils

```
GET /api/v1/councils
```

**Auth:** API Key

**Query Parameters:**

| Parameter      | Type    | Description |
|----------------|---------|-------------|
| `q`            | string  | Search council name (max 200 chars) |
| `parent_id`    | integer | Filter by parent council |
| `has_upcoming` | "true"  | Only councils with upcoming meetings |
| `topic`        | string  | Filter by topic slug |
| `jurisdiction` | string  | One of: `state`, `honolulu`, `maui`, `hawaii`, `kauai` |
| `type`         | string  | One of: `board`, `commission`, `council`, `committee`, `authority`, `department`, `office` |

**Response:**

```json
{
  "data": [
    {
      "id": 36,
      "name": "Board of Education",
      "parent_id": null,
      "parent_name": null,
      "upcoming_meeting_count": 5
    }
  ],
  "meta": {
    "total": 422
  }
}
```

Results are ordered by `name ASC`. No pagination — returns all matching councils.

---

#### Council Detail

```
GET /api/v1/councils/{id}
```

**Auth:** API Key

**Response:**

```json
{
  "data": {
    "id": 36,
    "name": "Board of Education",
    "parent_id": null,
    "parent_name": null,
    "upcoming_meeting_count": 5,
    "children": [
      { "id": 37, "name": "Finance Committee" }
    ]
  }
}
```

---

#### Council Meetings

```
GET /api/v1/councils/{id}/meetings
```

**Auth:** API Key

**Query Parameters:**

| Parameter | Type    | Default | Description |
|-----------|---------|---------|-------------|
| `limit`   | integer | 50      | Results per page (1–200) |
| `offset`  | integer | 0       | Pagination offset |

Returns only upcoming meetings (`meeting_date >= today`), ordered by date/time ascending.

**Response:**

```json
{
  "data": [
    {
      "state_id": "76181",
      "title": "Regular Meeting",
      "meeting_date": "2026-03-05",
      "meeting_time": "09:00:00",
      "location": "...",
      "status": "scheduled",
      "detail_url": "https://..."
    }
  ],
  "meta": {
    "council_id": 36,
    "council_name": "Board of Education",
    "total": 5,
    "limit": 50,
    "offset": 0,
    "has_more": false
  }
}
```

---

#### Council by Slug

```
GET /api/v1/councils/slug/{slug}
```

**Auth:** API Key

Looks up a council by its URL-friendly slug (from `council_profiles`). Returns the same response format as the council detail endpoint. Slug must match `[a-z0-9-]{1,100}`.

---

#### Council Profile

```
GET /api/v1/councils/{id}/profile
```

**Auth:** API Key

Returns the extended profile with plain-language descriptions, contact info, meeting logistics, and governance details.

**Response:**

```json
{
  "data": {
    "council_id": 36,
    "council_name": "Board of Education",
    "slug": "board-of-education",
    "plain_description": "The Board of Education oversees...",
    "decisions_examples": "Sets graduation requirements, approves...",
    "why_care": "If you have children in public school...",
    "appointment_method": "Elected statewide",
    "term_length": "4 years",
    "meeting_schedule": "First Thursday of each month",
    "default_location": "Queen Liliuokalani Building, Room 404",
    "virtual_option": true,
    "testimony_email": "boe@hawaii.gov",
    "testimony_instructions": "Submit written testimony 24 hours before...",
    "public_comment_info": "3-minute public comment period...",
    "official_website": "https://boe.hawaii.gov",
    "contact_phone": "(808) 586-3334",
    "contact_email": "boe@hawaii.gov",
    "jurisdiction": "state",
    "entity_type": "board",
    "member_count": 13,
    "vacancy_count": 2,
    "vacancy_info": "Two at-large seats open...",
    "last_updated": "2026-02-15"
  }
}
```

Returns `404` if no profile exists for this council.

---

#### Council Legal Authority

```
GET /api/v1/councils/{id}/authority
```

**Auth:** API Key

Returns statutory references establishing the council's authority.

**Response:**

```json
{
  "data": [
    {
      "citation": "HRS Chapter 302A",
      "description": "Establishes the Board of Education...",
      "url": "https://..."
    }
  ],
  "meta": {
    "council_id": 36,
    "total": 2
  }
}
```

---

#### Council Members

```
GET /api/v1/councils/{id}/members
```

**Auth:** API Key

Returns the board member roster, ordered by role (chair first), then display order.

**Response:**

```json
{
  "data": [
    {
      "name": "Jane Doe",
      "title": "Chairperson",
      "role": "chair",
      "appointed_by": "Governor",
      "term_start": "2024-01-01",
      "term_end": "2028-01-01",
      "status": "active"
    }
  ],
  "meta": {
    "council_id": 36,
    "total": 13
  }
}
```

---

#### Council Vacancies

```
GET /api/v1/councils/{id}/vacancies
```

**Auth:** API Key

Returns open appointment seats, ordered by application deadline.

**Response:**

```json
{
  "data": [
    {
      "seat_description": "At-large member",
      "requirements": "Must be a Hawaii resident...",
      "application_url": "https://...",
      "application_deadline": "2026-04-01",
      "appointing_authority": "Governor",
      "status": "open"
    }
  ],
  "meta": {
    "council_id": 36,
    "total": 2
  }
}
```

Only returns vacancies with `status = 'open'`.

---

### Topics

#### List Topics

```
GET /api/v1/topics
```

**Auth:** API Key

Returns all 16 topic categories with council and meeting counts.

**Response:**

```json
{
  "data": [
    {
      "id": 1,
      "slug": "environment",
      "name": "Environment & Land",
      "description": "Environmental protection, conservation...",
      "icon": "🌿",
      "display_order": 1,
      "council_count": 25,
      "meeting_count": 150
    }
  ],
  "meta": {
    "total": 16
  }
}
```

**Available topic slugs:** `environment`, `housing`, `education`, `health`, `transportation`, `public-safety`, `economy`, `culture`, `agriculture`, `energy`, `water`, `disability`, `veterans`, `technology`, `budget`, `governance`

---

#### Topic Detail

```
GET /api/v1/topics/{slug}
```

**Auth:** API Key

Returns topic details with all mapped councils and their upcoming meeting counts.

**Response:**

```json
{
  "data": {
    "id": 1,
    "slug": "environment",
    "name": "Environment & Land",
    "description": "...",
    "icon": "🌿",
    "councils": [
      {
        "id": 42,
        "name": "Environmental Council",
        "relevance": "primary",
        "upcoming_meeting_count": 3
      }
    ]
  }
}
```

---

#### Topic Meetings

```
GET /api/v1/topics/{slug}/meetings
```

**Auth:** API Key

**Query Parameters:**

| Parameter   | Type       | Default | Description |
|-------------|------------|---------|-------------|
| `date_from` | YYYY-MM-DD | Today   | Meetings on or after this date |
| `limit`     | integer    | 50      | Results per page (1–200) |
| `offset`    | integer    | 0       | Pagination offset |

Returns meetings matching the topic — either through council-level mapping or direct AI classification.

---

### Subscriptions

#### Create Subscription

```
POST /api/v1/subscriptions
```

**Auth:** API Key

**Request Body:**

```json
{
  "email": "user@example.com",
  "phone": "+18085551234",
  "channels": ["email", "sms"],
  "council_ids": [36, 42],
  "topics": ["education", "environment"],
  "frequency": "immediate",
  "source": "civime"
}
```

| Field         | Type     | Required     | Default       | Description |
|---------------|----------|--------------|---------------|-------------|
| `email`       | string   | Conditional  | —             | Required if `email` channel selected |
| `phone`       | string   | Conditional  | —             | E.164 format (`+1XXXXXXXXXX`). Required if `sms` channel selected |
| `channels`    | string[] | No           | `["email"]`   | `email`, `sms`, or both |
| `council_ids` | int[]    | Conditional  | `[]`          | At least one of `council_ids` or `topics` required |
| `topics`      | string[] | Conditional  | `[]`          | Topic slugs, resolved to council IDs |
| `frequency`   | string   | No           | `"immediate"` | `immediate`, `daily`, or `weekly` |
| `source`      | string   | No           | `"access100"` | Source identifier (max 50 chars) |

A confirmation email/SMS is sent automatically. The subscription is not active until confirmed.

**Response (201):**

```json
{
  "data": {
    "user_id": 1,
    "status": "pending_confirmation",
    "manage_token": "a1b2c3d4e5f6...",
    "councils": [36, 42, 55],
    "topics": [1, 3],
    "channels": ["email", "sms"],
    "frequency": "immediate",
    "message": "Verification sent to user@example.com"
  }
}
```

Save the `manage_token` — it's required for all subscription management operations.

---

#### Confirm Subscription

```
GET /api/v1/subscriptions/confirm?token={confirm_token}
```

**Auth:** Public

Confirms the user's opt-in. Redirects (302) to `https://civi.me/notifications/confirmed`.

---

#### Unsubscribe (One-Click)

```
GET /api/v1/subscriptions/unsubscribe?token={manage_token}
```

**Auth:** Public

Deactivates all subscriptions for the user. Redirects (302) to `https://civi.me/notifications/unsubscribed`.

---

#### Get Subscription Details

```
GET /api/v1/subscriptions/{user_id}?token={manage_token}
```

**Auth:** Manage Token

**Response:**

```json
{
  "data": {
    "user_id": 1,
    "email": "user@example.com",
    "phone": "+18085551234",
    "confirmed_email": true,
    "confirmed_phone": false,
    "subscriptions": [
      {
        "subscription_id": 10,
        "council_id": 36,
        "council_name": "Board of Education",
        "channels": ["email", "sms"],
        "frequency": "immediate",
        "active": true
      }
    ]
  }
}
```

---

#### Update Preferences

```
PATCH /api/v1/subscriptions/{user_id}?token={manage_token}
```

**Auth:** Manage Token

**Request Body (all fields optional):**

```json
{
  "email": "new@example.com",
  "phone": "+18085559999",
  "channels": ["email"],
  "frequency": "daily"
}
```

---

#### Replace Council Subscriptions

```
PUT /api/v1/subscriptions/{user_id}/councils?token={manage_token}
```

**Auth:** Manage Token

Replaces all council subscriptions with a new list. Existing subscriptions not in the new list are deactivated.

**Request Body:**

```json
{
  "council_ids": [36, 42, 55]
}
```

---

#### Unsubscribe

```
DELETE /api/v1/subscriptions/{user_id}?token={manage_token}
```

**Auth:** Manage Token

Soft-deletes all subscriptions (sets `active = false`).

---

### Webhooks

#### SendGrid Event Webhook

```
POST /api/v1/webhooks/sendgrid
```

**Auth:** Public

Receives SendGrid bounce, dropped, and spam report events. Automatically deactivates subscriptions for affected email addresses.

---

#### Twilio Inbound SMS

```
POST /api/v1/webhooks/twilio
```

**Auth:** Public

Receives inbound SMS from subscribers. Responds with TwiML XML.

**Supported keywords:**

| Keyword                              | Action |
|--------------------------------------|--------|
| `YES`, `CONFIRM`, `Y`               | Confirms phone subscription |
| `STOP`, `CANCEL`, `UNSUBSCRIBE`, `QUIT`, `END` | Unsubscribes (TCPA compliant) |
| `HELP`, `INFO`                       | Returns help message |

---

## CORS

The API accepts cross-origin requests from configured origins:

- `https://civi.me`
- `https://www.civi.me`
- `https://app.access100.app`

Preflight `OPTIONS` requests are handled automatically with a `204` response. The `Access-Control-Max-Age` is set to 24 hours.

---

## AI Summaries

Meetings are automatically summarized using the Claude API. Summaries are structured HTML with three sections:

- **What's Being Discussed** — main topics in plain language
- **Decisions Expected** — any votes or actions anticipated
- **Who Should Pay Attention** — who this meeting affects

The summarizer uses agenda text from two sources:
1. The state calendar listing (`description` / `full_agenda_text`)
2. PDF agenda attachments (downloaded and text-extracted when calendar text is thin)

Summaries are generated on a cron schedule (every 30 minutes) and stored in the `summary_text` column.

---

## Notification System

### Channels
- **Email** — via SendGrid (HTML + plain text)
- **SMS** — via Twilio

### Frequency Options
- **Immediate** — sent when a meeting is created or updated
- **Daily digest** — batched and sent at 7:00 AM HST
- **Weekly digest** — batched and sent Monday at 7:00 AM HST

### SMS Quiet Hours
No SMS messages are sent before 8:00 AM or after 9:00 PM HST. Messages queued during quiet hours are delivered at the next 8:00 AM window.

### Compliance
- Double opt-in (email confirmation + SMS `YES` confirmation)
- RFC 8058 `List-Unsubscribe` headers on all notification emails
- TCPA-compliant SMS with `STOP` keyword support
- Automatic deactivation on bounces and spam reports

---

## Cron Schedule

| Schedule           | Job              | Description |
|--------------------|------------------|-------------|
| Every 15 minutes   | `notify.php`     | Change detection and immediate notifications |
| Every 30 minutes   | `summarize.php`  | AI summary generation for new meetings |
| Every 30 minutes   | `classify-topics.php` | AI topic classification for new meetings |
| Daily 5:00 PM UTC  | `digest.php`     | Daily digest delivery (7 AM HST) |
| Monday 5:00 PM UTC | `weekly-digest.php` | Weekly digest delivery (7 AM HST) |

All cron jobs support a `--dry-run` flag for testing.

---

## Quick Reference: Route Table

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/` | Public | API root |
| GET | `/api/v1/health` | Public | Health check |
| GET | `/api/v1/stats` | Public | Platform statistics |
| GET | `/api/v1/meetings` | API Key | List meetings |
| GET | `/api/v1/meetings/{state_id}` | API Key | Meeting detail |
| GET | `/api/v1/meetings/{state_id}/summary` | API Key | AI summary |
| GET | `/api/v1/meetings/{state_id}/ics` | API Key | iCalendar download |
| GET | `/api/v1/councils` | API Key | List councils |
| GET | `/api/v1/councils/{id}` | API Key | Council detail |
| GET | `/api/v1/councils/{id}/meetings` | API Key | Council meetings |
| GET | `/api/v1/councils/{id}/profile` | API Key | Council profile |
| GET | `/api/v1/councils/{id}/authority` | API Key | Legal authority |
| GET | `/api/v1/councils/{id}/members` | API Key | Board members |
| GET | `/api/v1/councils/{id}/vacancies` | API Key | Open seats |
| GET | `/api/v1/councils/slug/{slug}` | API Key | Lookup by slug |
| GET | `/api/v1/topics` | API Key | List topics |
| GET | `/api/v1/topics/{slug}` | API Key | Topic detail |
| GET | `/api/v1/topics/{slug}/meetings` | API Key | Topic meetings |
| POST | `/api/v1/subscriptions` | API Key | Create subscription |
| GET | `/api/v1/subscriptions/confirm` | Public | Confirm opt-in |
| GET | `/api/v1/subscriptions/unsubscribe` | Public | One-click unsubscribe |
| GET | `/api/v1/subscriptions/{id}` | Token | Subscription details |
| PATCH | `/api/v1/subscriptions/{id}` | Token | Update preferences |
| PUT | `/api/v1/subscriptions/{id}/councils` | Token | Replace councils |
| DELETE | `/api/v1/subscriptions/{id}` | Token | Unsubscribe |
| POST | `/api/v1/webhooks/sendgrid` | Public | Email event webhook |
| POST | `/api/v1/webhooks/twilio` | Public | Inbound SMS webhook |
