# Access100 API — Cron Jobs

All cron scripts live in `/api/cron/` and require `config.php` (database credentials and service keys from environment variables).

## Required Crontab

Add these entries to the server crontab (adjust paths for your deployment):

```crontab
# ─── Access100 API Cron Jobs ─────────────────────────────────────────

# Change detection & immediate notifications (every 15 minutes)
*/15 * * * * php /var/www/html/api/cron/notify.php >> /var/log/access100-notify.log 2>&1

# Daily digest (7 AM HST = 5 PM UTC)
0 17 * * * php /var/www/html/api/cron/digest.php >> /var/log/access100-digest.log 2>&1

# Weekly digest (Monday 7 AM HST = Monday 5 PM UTC)
0 17 * * 1 php /var/www/html/api/cron/weekly-digest.php >> /var/log/access100-weekly.log 2>&1

# AI topic classification (daily at 2 AM HST = 12 PM UTC)
0 12 * * * php /var/www/html/api/cron/classify-topics.php >> /var/log/access100-classify.log 2>&1

# AI meeting summaries (daily at 3 AM HST = 1 PM UTC)
0 13 * * * php /var/www/html/api/cron/summarize.php >> /var/log/access100-summarize.log 2>&1

# Cleanup: rate limit files + old queue entries (daily at 3 AM HST = 1 PM UTC)
30 13 * * * php /var/www/html/api/cron/cleanup.php >> /var/log/access100-cleanup.log 2>&1
```

## Job Details

### notify.php — Change Detection & Immediate Notifications

- **Frequency**: Every 15 minutes
- **What it does**:
  1. Checks `scraper_state` for last successful run time
  2. Finds meetings with `updated_at` after last run
  3. Looks up subscribers for affected councils
  4. Sends immediate email/SMS notifications
  5. Queues daily/weekly subscribers for digest crons
  6. Respects SMS quiet hours (8 AM–9 PM HST)
  7. Processes any queued SMS that are now in send window
  8. Records run in `scraper_state`
- **Flags**: `--dry-run` (log what would be sent without sending)

### digest.php — Daily Digest

- **Frequency**: Daily at 7 AM HST
- **What it does**:
  1. Queries `notification_queue` for pending daily items
  2. Groups by user + channel
  3. Sends one digest email/SMS per user
  4. Marks queue items as sent/failed
  5. Logs each notification in `notification_log`
- **Flags**: `--dry-run`

### weekly-digest.php — Weekly Digest

- **Frequency**: Monday at 7 AM HST
- **Same logic as daily digest**, but filters for `frequency = 'weekly'`
- **Flags**: `--dry-run`

### classify-topics.php — AI Topic Classification

- **Frequency**: Daily
- **What it does**: Uses Claude API to classify meetings into topic categories
- **Requires**: `CLAUDE_API_KEY` environment variable

### summarize.php — AI Meeting Summaries

- **Frequency**: Daily
- **What it does**: Uses Claude API to generate plain-language summaries of meeting agendas
- **Requires**: `CLAUDE_API_KEY` environment variable

### cleanup.php — Maintenance Cleanup

- **Frequency**: Daily
- **What it does**:
  1. Purges stale rate limit files from `/tmp/access100_ratelimit/`
  2. Deletes old notification queue entries (sent/failed > 30 days)

## Docker Setup

When running in Docker, add cron to the container or use a sidecar. Example Dockerfile addition:

```dockerfile
RUN apt-get update && apt-get install -y cron
COPY crontab /etc/cron.d/access100
RUN chmod 0644 /etc/cron.d/access100 && crontab /etc/cron.d/access100
```

## Monitoring

Check that crons are running by querying the `/health` endpoint:

```bash
curl -s https://access100.app/api/v1/health | jq '.data.last_scrape'
```

The `last_scrape` field shows the last successful `notify.php` run. If it's more than 30 minutes old, the cron may be stuck.

The `queue_depth` field shows pending notifications waiting to be sent.

## Log Rotation

Add to `/etc/logrotate.d/access100`:

```
/var/log/access100-*.log {
    daily
    missingok
    rotate 14
    compress
    notifempty
}
```
