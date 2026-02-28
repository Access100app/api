<?php
/**
 * Access100 API - Cleanup Cron
 *
 * Periodic maintenance tasks:
 *   1. Purge stale rate limit files from /tmp/access100_ratelimit/
 *   2. Purge old notification_queue entries (sent/failed older than 30 days)
 *
 * Crontab (once daily at 3 AM HST = 1 PM UTC):
 *   0 13 * * * php /path/to/api/cron/cleanup.php >> /var/log/access100-cleanup.log 2>&1
 *
 * Manual:
 *   php api/cron/cleanup.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../middleware/rate-limit.php';

$start = microtime(true);

echo date('[Y-m-d H:i:s]') . " Cleanup cron starting...\n";

// ─── 1. Rate limit file cleanup ─────────────────────────────────────

cleanup_rate_limit_files();
echo "  Rate limit files cleaned.\n";

// ─── 2. Purge old notification queue entries ─────────────────────────

try {
    $pdo = get_db();

    $stmt = $pdo->prepare("
        DELETE FROM notification_queue
        WHERE status IN ('sent', 'failed')
          AND sent_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $purged = $stmt->rowCount();
    echo "  Purged {$purged} old notification queue entries.\n";

} catch (PDOException $e) {
    error_log('Cleanup cron DB error: ' . $e->getMessage());
    echo "  ERROR: " . $e->getMessage() . "\n";
}

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " Cleanup done in {$elapsed}s.\n";
