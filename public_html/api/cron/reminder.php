<?php
/**
 * Access100 API - Meeting Reminder Cron
 *
 * Runs daily at 7 AM HST. Sends morning-of reminder emails for all
 * confirmed reminders whose meeting date is today.
 *
 * Crontab (7 AM HST = 5 PM UTC):
 *   0 17 * * * php /path/to/api/cron/reminder.php >> /var/log/access100-reminder.log 2>&1
 *
 * Manual:
 *   php api/cron/reminder.php
 *   php api/cron/reminder.php --dry-run
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/email.php';

$dry_run = in_array('--dry-run', $argv ?? [], true);
$start   = microtime(true);

echo date('[Y-m-d H:i:s]') . " Meeting reminder cron starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n";

$stats = ['reminders' => 0, 'sent' => 0, 'failed' => 0];

try {
    $pdo = get_db();

    // Get today's date in Hawaii time
    $today = (new DateTime('now', new DateTimeZone('Pacific/Honolulu')))->format('Y-m-d');

    echo "  Today (HST): {$today}\n";

    // Find all confirmed, unsent reminders where meeting date is today
    $stmt = $pdo->prepare("
        SELECT r.id, r.email, r.source,
               m.state_id, m.title, m.meeting_date, m.meeting_time,
               m.location, c.name AS council_name
        FROM reminders r
        JOIN meetings m ON r.meeting_id = m.id
        JOIN councils c ON m.council_id = c.id
        WHERE r.confirmed = TRUE
          AND r.sent = FALSE
          AND m.meeting_date = ?
        ORDER BY m.meeting_time ASC
    ");
    $stmt->execute([$today]);
    $reminders = $stmt->fetchAll();

    $stats['reminders'] = count($reminders);
    echo "  Reminders due today: {$stats['reminders']}\n";

    if (empty($reminders)) {
        echo date('[Y-m-d H:i:s]') . " No reminders to send.\n";
        exit;
    }

    foreach ($reminders as $reminder) {
        $meeting_data = [
            'council_name' => $reminder['council_name'],
            'title'        => $reminder['title'],
            'meeting_date' => $reminder['meeting_date'],
            'meeting_time' => $reminder['meeting_time'],
            'location'     => $reminder['location'],
            'state_id'     => $reminder['state_id'],
        ];

        if ($dry_run) {
            echo "    [DRY] Would email reminder to {$reminder['email']} for {$reminder['council_name']} — {$reminder['title']}\n";
            $success = true;
        } else {
            $success = send_meeting_reminder($reminder['email'], $meeting_data, $reminder['source']);
        }

        if ($success) {
            $stats['sent']++;

            if (!$dry_run) {
                $pdo->prepare("UPDATE reminders SET sent = TRUE, sent_at = NOW() WHERE id = ?")
                    ->execute([$reminder['id']]);
            }
        } else {
            $stats['failed']++;
            error_log("Reminder send failed for reminder #{$reminder['id']} to {$reminder['email']}");
        }
    }

} catch (PDOException $e) {
    error_log('Reminder cron error: ' . $e->getMessage());
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$elapsed = round(microtime(true) - $start, 2);
echo "  Due: {$stats['reminders']}, Sent: {$stats['sent']}, Failed: {$stats['failed']}\n";
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";
