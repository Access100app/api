<?php
/**
 * Access100 API - Stats Endpoint
 *
 * Public endpoint (no API key required) that returns aggregate statistics
 * about the Access100 platform. Intended for public dashboards and the
 * civi.me frontend.
 *
 * Routes:
 *   GET /api/v1/stats — public platform statistics
 *
 * Requires: $route array from index.php, config.php loaded
 */

// Only GET is supported for stats
if ($route['method'] !== 'GET') {
    json_error(405, 'Method not allowed. Stats endpoint only supports GET.');
}

$stats = [];

try {
    $pdo = get_db();

    // Total meetings tracked
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM meetings");
    $row = $stmt->fetch();
    $stats['total_meetings'] = (int) $row['total'];

    // Upcoming meetings
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM meetings WHERE meeting_date >= CURDATE()");
    $row = $stmt->fetch();
    $stats['upcoming_meetings'] = (int) $row['total'];

    // Meetings with AI summaries
    $stmt = $pdo->query(
        "SELECT COUNT(*) as total FROM meetings
         WHERE summary_text IS NOT NULL AND summary_text != ''"
    );
    $row = $stmt->fetch();
    $stats['meetings_with_summaries'] = (int) $row['total'];

    // Total councils covered
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM councils WHERE is_active = 1");
    $row = $stmt->fetch();
    $stats['total_councils'] = (int) $row['total'];

    // Councils with upcoming meetings
    $stmt = $pdo->query(
        "SELECT COUNT(DISTINCT council_id) as total FROM meetings
         WHERE meeting_date >= CURDATE() AND council_id IS NOT NULL"
    );
    $row = $stmt->fetch();
    $stats['councils_with_upcoming'] = (int) $row['total'];

    // Active subscriber count (confirmed, active)
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(DISTINCT s.user_id) as total
             FROM subscriptions s
             JOIN users u ON s.user_id = u.id
             WHERE s.active = 1 AND (u.confirmed_email = 1 OR u.confirmed_phone = 1)"
        );
        $row = $stmt->fetch();
        $stats['active_subscribers'] = (int) $row['total'];
    } catch (PDOException $e) {
        $stats['active_subscribers'] = 0;
    }

    // Total notifications sent (all time)
    try {
        $stmt = $pdo->query(
            "SELECT COUNT(*) as total FROM notification_log WHERE status = 'sent'"
        );
        $row = $stmt->fetch();
        $stats['total_notifications_sent'] = (int) $row['total'];
    } catch (PDOException $e) {
        $stats['total_notifications_sent'] = 0;
    }

    // Date range coverage
    $stmt = $pdo->query(
        "SELECT MIN(meeting_date) as earliest, MAX(meeting_date) as latest FROM meetings"
    );
    $row = $stmt->fetch();
    $stats['coverage'] = [
        'earliest_meeting' => $row['earliest'],
        'latest_meeting'   => $row['latest'],
    ];

} catch (PDOException $e) {
    error_log('Stats endpoint DB error: ' . $e->getMessage());
    json_error(503, 'Unable to retrieve stats. Database unavailable.');
}

set_cache_headers(300);
json_response($stats);
