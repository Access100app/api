<?php
/**
 * Access100 API - Daily Digest Cron
 *
 * Runs daily at 7 AM HST. Batches all pending 'daily' notifications
 * per subscriber into a single digest email/SMS and sends them.
 *
 * Crontab (7 AM HST = 5 PM UTC):
 *   0 17 * * * php /path/to/api/cron/digest.php >> /var/log/access100-digest.log 2>&1
 *
 * Manual:
 *   php api/cron/digest.php
 *   php api/cron/digest.php --dry-run
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/email.php';
require_once __DIR__ . '/../services/sms.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);
$start   = microtime(true);

echo date('[Y-m-d H:i:s]') . " Daily digest starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n";

$stats = ['users' => 0, 'emails_sent' => 0, 'sms_sent' => 0, 'failed' => 0];

try {
    $pdo = get_db();

    // Find all pending daily-frequency queue items that are due
    $stmt = $pdo->prepare("
        SELECT q.id AS queue_id, q.subscription_id, q.meeting_id, q.channel,
               s.user_id, s.frequency,
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
          AND s.frequency = 'daily'
          AND s.active = TRUE
        ORDER BY u.id, q.channel, m.meeting_date ASC
    ");
    $stmt->execute();
    $items = $stmt->fetchAll();

    echo "  Pending daily queue items: " . count($items) . "\n";

    if (empty($items)) {
        echo date('[Y-m-d H:i:s]') . " No daily digests to send.\n";
        exit;
    }

    // Group by user_id + channel so we send one digest per user per channel
    $grouped = [];
    foreach ($items as $item) {
        $key = $item['user_id'] . ':' . $item['channel'];
        $grouped[$key]['user']    = $item;
        $grouped[$key]['items'][] = $item;
    }

    $stats['users'] = count($grouped);

    foreach ($grouped as $key => $group) {
        $user     = $group['user'];
        $meetings = $group['items'];
        $channel  = $user['channel'];
        $queue_ids = array_column($meetings, 'queue_id');

        $success = false;

        if ($channel === 'email' && $user['confirmed_email'] && !empty($user['email'])) {
            if ($dry_run) {
                echo "    [DRY] Would email digest to {$user['email']} with " . count($meetings) . " meetings\n";
                $success = true;
            } else {
                $success = send_digest($user['email'], $meetings, $user['manage_token'], 'daily');
            }

            if ($success) $stats['emails_sent']++;
            else $stats['failed']++;

        } elseif ($channel === 'sms' && $user['confirmed_phone'] && !empty($user['phone'])) {
            if ($dry_run) {
                echo "    [DRY] Would SMS digest to {$user['phone']} with " . count($meetings) . " meetings\n";
                $success = true;
            } else {
                $success = send_digest_sms($user['phone'], $meetings, $user['manage_token']);
            }

            if ($success) $stats['sms_sent']++;
            else $stats['failed']++;
        }

        // Mark queue items as sent/failed
        if (!$dry_run) {
            $status = $success ? 'sent' : 'failed';
            $placeholders = implode(',', array_fill(0, count($queue_ids), '?'));
            $pdo->prepare("UPDATE notification_queue SET status = ?, sent_at = NOW() WHERE id IN ({$placeholders})")
                ->execute(array_merge([$status], $queue_ids));

            // Log each meeting notification
            foreach ($meetings as $m) {
                $pdo->prepare("INSERT INTO notification_log (subscription_id, meeting_id, channel, status) VALUES (?, ?, ?, ?)")
                    ->execute([$m['subscription_id'], $m['meeting_id'], $channel, $status]);
            }
        }
    }

} catch (PDOException $e) {
    error_log('Daily digest cron error: ' . $e->getMessage());
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$elapsed = round(microtime(true) - $start, 2);
echo "  Users: {$stats['users']}, Emails: {$stats['emails_sent']}, SMS: {$stats['sms_sent']}, Failed: {$stats['failed']}\n";
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";
