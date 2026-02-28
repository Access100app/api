<?php
/**
 * Access100 API - Health Endpoint
 *
 * Public endpoint (no API key required) that reports API status.
 *
 * Routes:
 *   GET /api/v1/health — API health check with DB stats
 *
 * Response:
 *   {
 *     "data": {
 *       "status": "ok",
 *       "version": "v1",
 *       "database": "connected",
 *       "meetings_count": 1234,
 *       "councils_count": 305,
 *       "last_meeting_date": "2026-03-15",
 *       "uptime": "ok"
 *     },
 *     "meta": {
 *       "api_version": "v1",
 *       "timestamp": "2026-02-27T19:30:00+00:00"
 *     }
 *   }
 *
 * Requires: $route array from index.php, config.php loaded
 */

// Only GET is supported for health
if ($route['method'] !== 'GET') {
    json_error(405, 'Method not allowed. Health endpoint only supports GET.');
}

$health = [
    'status'  => 'ok',
    'version' => API_VERSION,
];

// Check database connection and gather stats
try {
    $pdo = get_db();

    $health['database'] = 'connected';

    // Count meetings
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM meetings");
    $row = $stmt->fetch();
    $health['meetings_count'] = (int) $row['total'];

    // Count upcoming meetings
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM meetings WHERE meeting_date >= CURDATE()");
    $row = $stmt->fetch();
    $health['upcoming_meetings_count'] = (int) $row['total'];

    // Count councils
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM councils");
    $row = $stmt->fetch();
    $health['councils_count'] = (int) $row['total'];

    // Last meeting date in the database
    $stmt = $pdo->query("SELECT MAX(meeting_date) as last_date FROM meetings");
    $row = $stmt->fetch();
    $health['last_meeting_date'] = $row['last_date'];

    // Last scrape time (if scraper_state table exists)
    try {
        $stmt = $pdo->query("SELECT MAX(last_run) as last_scrape FROM scraper_state WHERE status = 'success'");
        $row = $stmt->fetch();
        $health['last_scrape'] = $row['last_scrape'];
    } catch (PDOException $e) {
        $health['last_scrape'] = null;
    }

    // Notification queue depth (if notification_queue table exists)
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM notification_queue WHERE status = 'pending'");
        $row = $stmt->fetch();
        $health['queue_depth'] = (int) $row['total'];
    } catch (PDOException $e) {
        $health['queue_depth'] = null;
    }

    // Recent notification delivery stats (last 24 hours)
    try {
        $stmt = $pdo->query(
            "SELECT status, COUNT(*) as total FROM notification_log
             WHERE sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY status"
        );
        $delivery = [];
        while ($row = $stmt->fetch()) {
            $delivery[$row['status']] = (int) $row['total'];
        }
        $health['notifications_24h'] = [
            'sent'    => $delivery['sent'] ?? 0,
            'failed'  => $delivery['failed'] ?? 0,
            'bounced' => $delivery['bounced'] ?? 0,
        ];
    } catch (PDOException $e) {
        $health['notifications_24h'] = null;
    }

} catch (PDOException $e) {
    $health['status'] = 'degraded';
    $health['database'] = 'disconnected';
    error_log('Health check DB error: ' . $e->getMessage());
}

set_cache_headers(30);
json_response($health);
