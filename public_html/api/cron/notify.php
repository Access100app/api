<?php
/**
 * Access100 API - Change Detection & Immediate Notification Cron
 *
 * Runs every 15 minutes. Detects new/changed meetings since the last run,
 * queues notifications for all affected subscribers, and immediately sends
 * notifications for subscribers with frequency='immediate'.
 *
 * Digest subscribers (daily/weekly) are queued for the digest cron scripts.
 *
 * Crontab:
 *   * /15 * * * * php /path/to/api/cron/notify.php >> /var/log/access100-notify.log 2>&1
 *
 * Manual:
 *   php api/cron/notify.php
 *   php api/cron/notify.php --dry-run
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/email.php';
require_once __DIR__ . '/../services/sms.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);
$start   = microtime(true);

echo date('[Y-m-d H:i:s]') . " Notification cron starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n";

$stats = [
    'meetings_changed' => 0,
    'notifications_queued' => 0,
    'immediate_sent' => 0,
    'immediate_failed' => 0,
];

try {
    $pdo = get_db();

    // ─── 1. Get last run time ─────────────────────────────────────────

    $stmt = $pdo->prepare("
        SELECT last_run FROM scraper_state
        WHERE source = 'notify_cron' AND status = 'success'
        ORDER BY last_run DESC LIMIT 1
    ");
    $stmt->execute();
    $last_run_row = $stmt->fetch();

    // Default to 15 minutes ago if no previous run
    $last_run = $last_run_row
        ? $last_run_row['last_run']
        : date('Y-m-d H:i:s', strtotime('-15 minutes'));

    echo "  Last successful run: {$last_run}\n";

    // ─── 2. Find new/changed meetings since last run ──────────────────

    $stmt = $pdo->prepare("
        SELECT m.id, m.state_id, m.title, m.meeting_date, m.meeting_time,
               m.location, m.council_id, c.name AS council_name
        FROM meetings m
        JOIN councils c ON m.council_id = c.id
        WHERE m.updated_at > ?
          AND m.meeting_date >= CURDATE()
        ORDER BY m.updated_at ASC
    ");
    $stmt->execute([$last_run]);
    $changed_meetings = $stmt->fetchAll();

    $stats['meetings_changed'] = count($changed_meetings);
    echo "  Meetings changed since last run: {$stats['meetings_changed']}\n";

    if (empty($changed_meetings)) {
        record_scraper_state($pdo, $stats);
        finish($start, $stats);
    }

    // ─── 3. For each changed meeting, find subscribers ────────────────

    // Collect unique council IDs
    $council_ids = array_unique(array_column($changed_meetings, 'council_id'));

    // Find all confirmed, active subscribers for these councils
    $placeholders = implode(',', array_fill(0, count($council_ids), '?'));
    $stmt = $pdo->prepare("
        SELECT s.id AS subscription_id, s.user_id, s.council_id,
               s.channels, s.frequency,
               u.email, u.phone, u.manage_token, u.confirmed_email, u.confirmed_phone
        FROM subscriptions s
        JOIN users u ON s.user_id = u.id
        WHERE s.council_id IN ({$placeholders})
          AND s.active = TRUE
          AND (u.confirmed_email = TRUE OR u.confirmed_phone = TRUE)
        ORDER BY s.user_id, s.council_id
    ");
    $stmt->execute(array_values($council_ids));
    $subscribers = $stmt->fetchAll();

    echo "  Active subscribers for affected councils: " . count($subscribers) . "\n";

    // Index subscribers by council_id for fast lookup
    $subs_by_council = [];
    foreach ($subscribers as $sub) {
        $subs_by_council[$sub['council_id']][] = $sub;
    }

    // ─── 4. Queue and send notifications ──────────────────────────────

    // Track what we've already notified to avoid duplicates
    // (a user subscribed to multiple changed meetings in one council)
    $notified = []; // "user_id:meeting_id" => true

    foreach ($changed_meetings as $meeting) {
        $council_subs = $subs_by_council[$meeting['council_id']] ?? [];

        foreach ($council_subs as $sub) {
            $dedup_key = $sub['user_id'] . ':' . $meeting['id'];
            if (isset($notified[$dedup_key])) {
                continue;
            }

            // Check if we already sent a notification for this meeting+subscription
            $exists = $pdo->prepare("
                SELECT 1 FROM notification_log
                WHERE subscription_id = ? AND meeting_id = ?
                LIMIT 1
            ");
            $exists->execute([$sub['subscription_id'], $meeting['id']]);
            if ($exists->fetch()) {
                $notified[$dedup_key] = true;
                continue;
            }

            $channels = explode(',', $sub['channels']);

            if ($sub['frequency'] === 'immediate') {
                // Send now
                foreach ($channels as $channel) {
                    $sent = false;

                    if ($channel === 'email' && $sub['confirmed_email'] && !empty($sub['email'])) {
                        if ($dry_run) {
                            echo "    [DRY] Would email {$sub['email']} about meeting {$meeting['state_id']}\n";
                            $sent = true;
                        } else {
                            // Check quiet hours — email has no restriction, send immediately
                            $sent = send_meeting_notification(
                                $sub['email'], $meeting, $sub['manage_token']
                            );
                        }
                    } elseif ($channel === 'sms' && $sub['confirmed_phone'] && !empty($sub['phone'])) {
                        if (is_sms_quiet_hours()) {
                            // Queue for 8 AM HST
                            queue_notification($pdo, $sub['subscription_id'], $meeting['id'], 'sms', next_sms_window());
                            $stats['notifications_queued']++;
                            continue;
                        }
                        if ($dry_run) {
                            echo "    [DRY] Would SMS {$sub['phone']} about meeting {$meeting['state_id']}\n";
                            $sent = true;
                        } else {
                            $sent = send_meeting_sms(
                                $sub['phone'], $meeting, $sub['manage_token']
                            );
                        }
                    }

                    // Log the send
                    if (!$dry_run) {
                        log_notification($pdo, $sub['subscription_id'], $meeting['id'], $channel, $sent);
                    }

                    if ($sent) {
                        $stats['immediate_sent']++;
                    } else {
                        $stats['immediate_failed']++;
                    }
                }
            } else {
                // daily or weekly — queue for digest
                foreach ($channels as $channel) {
                    $scheduled = ($sub['frequency'] === 'daily')
                        ? next_daily_digest_time()
                        : next_weekly_digest_time();

                    if (!$dry_run) {
                        queue_notification($pdo, $sub['subscription_id'], $meeting['id'], $channel, $scheduled);
                    } else {
                        echo "    [DRY] Would queue {$channel} digest for user {$sub['user_id']} meeting {$meeting['state_id']}\n";
                    }
                    $stats['notifications_queued']++;
                }
            }

            $notified[$dedup_key] = true;
        }
    }

    // ─── 5. Process any queued SMS that are now within send window ─────

    if (!$dry_run) {
        $sent_queued = process_pending_queue($pdo);
        $stats['immediate_sent'] += $sent_queued;
    }

    // ─── 6. Record this run ───────────────────────────────────────────

    if (!$dry_run) {
        record_scraper_state($pdo, $stats);
    }

} catch (PDOException $e) {
    error_log('Notify cron DB error: ' . $e->getMessage());
    echo "  ERROR: " . $e->getMessage() . "\n";
}

finish($start, $stats);


// =====================================================================
// Helper functions
// =====================================================================

/**
 * Queue a notification for later delivery (digest or delayed SMS).
 */
function queue_notification(PDO $pdo, int $subscription_id, int $meeting_id, string $channel, string $scheduled_for): void
{
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO notification_queue (subscription_id, meeting_id, channel, scheduled_for)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$subscription_id, $meeting_id, $channel, $scheduled_for]);
}

/**
 * Log a sent or failed notification.
 */
function log_notification(PDO $pdo, int $subscription_id, int $meeting_id, string $channel, bool $success): void
{
    $stmt = $pdo->prepare("
        INSERT INTO notification_log (subscription_id, meeting_id, channel, status)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $subscription_id,
        $meeting_id,
        $channel,
        $success ? 'sent' : 'failed',
    ]);
}

/**
 * Process pending queued notifications that are due now.
 * Returns count of successfully sent items.
 */
function process_pending_queue(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        SELECT q.id, q.subscription_id, q.meeting_id, q.channel,
               u.email, u.phone, u.manage_token, u.confirmed_email, u.confirmed_phone,
               m.state_id, m.title, m.meeting_date, m.meeting_time, m.location,
               c.name AS council_name
        FROM notification_queue q
        JOIN subscriptions s ON q.subscription_id = s.id
        JOIN users u ON s.user_id = u.id
        JOIN meetings m ON q.meeting_id = m.id
        JOIN councils c ON m.council_id = c.id
        WHERE q.status = 'pending'
          AND q.scheduled_for <= NOW()
          AND s.active = TRUE
        ORDER BY q.scheduled_for ASC
        LIMIT 100
    ");
    $stmt->execute();
    $items = $stmt->fetchAll();

    $sent_count = 0;

    foreach ($items as $item) {
        $success = false;

        if ($item['channel'] === 'email' && $item['confirmed_email'] && !empty($item['email'])) {
            $success = send_meeting_notification($item['email'], $item, $item['manage_token']);
        } elseif ($item['channel'] === 'sms' && $item['confirmed_phone'] && !empty($item['phone'])) {
            if (!is_sms_quiet_hours()) {
                $success = send_meeting_sms($item['phone'], $item, $item['manage_token']);
            } else {
                // Still in quiet hours — skip, will be picked up next run
                continue;
            }
        }

        // Update queue item
        $status = $success ? 'sent' : 'failed';
        $pdo->prepare("UPDATE notification_queue SET status = ?, sent_at = NOW() WHERE id = ?")
            ->execute([$status, $item['id']]);

        // Log it
        log_notification($pdo, $item['subscription_id'], $item['meeting_id'], $item['channel'], $success);

        if ($success) {
            $sent_count++;
        }
    }

    return $sent_count;
}

/**
 * Record this cron run in scraper_state.
 */
function record_scraper_state(PDO $pdo, array $stats): void
{
    $stmt = $pdo->prepare("
        INSERT INTO scraper_state (source, last_run, meetings_found, meetings_new, meetings_changed, status)
        VALUES ('notify_cron', NOW(), ?, ?, ?, 'success')
    ");
    $stmt->execute([
        $stats['meetings_changed'],
        $stats['meetings_changed'],
        $stats['meetings_changed'],
    ]);
}

/**
 * Check if current time is in SMS quiet hours (before 8 AM or after 9 PM HST).
 */
function is_sms_quiet_hours(): bool
{
    $hst  = new DateTimeZone('Pacific/Honolulu');
    $hour = (int) (new DateTime('now', $hst))->format('G');
    return ($hour < 8 || $hour >= 21);
}

/**
 * Get the next valid SMS send window (8:00 AM HST today or tomorrow).
 */
function next_sms_window(): string
{
    $hst = new DateTimeZone('Pacific/Honolulu');
    $now = new DateTime('now', $hst);
    $hour = (int) $now->format('G');

    if ($hour >= 21) {
        // After 9 PM — next window is 8 AM tomorrow
        $now->modify('+1 day');
    }
    $now->setTime(8, 0, 0);

    $utc = new DateTimeZone('UTC');
    $now->setTimezone($utc);
    return $now->format('Y-m-d H:i:s');
}

/**
 * Next daily digest time: 7:00 AM HST today or tomorrow.
 */
function next_daily_digest_time(): string
{
    $hst = new DateTimeZone('Pacific/Honolulu');
    $now = new DateTime('now', $hst);
    $target = clone $now;
    $target->setTime(7, 0, 0);

    if ($now >= $target) {
        $target->modify('+1 day');
    }

    $utc = new DateTimeZone('UTC');
    $target->setTimezone($utc);
    return $target->format('Y-m-d H:i:s');
}

/**
 * Next weekly digest time: Monday 7:00 AM HST.
 */
function next_weekly_digest_time(): string
{
    $hst = new DateTimeZone('Pacific/Honolulu');
    $now = new DateTime('now', $hst);
    $target = clone $now;
    $target->setTime(7, 0, 0);

    // Find next Monday
    $day_of_week = (int) $target->format('N'); // 1=Mon, 7=Sun
    if ($day_of_week === 1 && $now < $target) {
        // It's Monday and before 7 AM — use today
    } else {
        $days_until_monday = (8 - $day_of_week) % 7;
        if ($days_until_monday === 0) $days_until_monday = 7;
        $target->modify("+{$days_until_monday} days");
    }

    $utc = new DateTimeZone('UTC');
    $target->setTimezone($utc);
    return $target->format('Y-m-d H:i:s');
}

/**
 * Print final stats and exit.
 */
function finish(float $start, array $stats): void
{
    $elapsed = round(microtime(true) - $start, 2);
    echo "  Meetings changed: {$stats['meetings_changed']}\n";
    echo "  Notifications queued (digest): {$stats['notifications_queued']}\n";
    echo "  Immediate sent: {$stats['immediate_sent']}\n";
    echo "  Immediate failed: {$stats['immediate_failed']}\n";
    echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";
    exit;
}
