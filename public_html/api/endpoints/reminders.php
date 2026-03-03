<?php
/**
 * Access100 API - Reminders Endpoint
 *
 * Handles one-time meeting reminder requests:
 *   POST /reminders              — create reminder (API key required)
 *   GET  /reminders/confirm?token=xxx — confirm double opt-in (public)
 *
 * Requires: $route array from index.php, config.php loaded
 */

require_once __DIR__ . '/../services/email.php';

$allowed_methods = ['GET', 'POST'];
if (!in_array($route['method'], $allowed_methods, true)) {
    json_error(405, 'Method not allowed.');
}

// ─── Route Dispatch ───────────────────────────────────────────────────────────

$resource_id = $route['resource_id'];
$method      = $route['method'];

// GET /reminders/confirm?token=xxx — public confirmation
if ($resource_id === 'confirm' && $method === 'GET') {
    handle_reminder_confirm($route['query']);
}

// POST /reminders — create new (API key auth)
elseif ($resource_id === null && $method === 'POST') {
    handle_create_reminder();
}

else {
    json_error(404, 'Reminder route not found.');
}


// =====================================================================
// POST /reminders — Create a one-time meeting reminder
// =====================================================================

function handle_create_reminder(): void
{
    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    // --- Validate required fields ---

    $email            = isset($body['email']) ? trim($body['email']) : '';
    $meeting_state_id = isset($body['meeting_state_id']) ? trim($body['meeting_state_id']) : '';
    $source           = isset($body['source']) ? substr(trim($body['source']), 0, 50) : 'access100';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(400, 'A valid email address is required.');
    }

    if (empty($meeting_state_id)) {
        json_error(400, 'meeting_state_id is required.');
    }

    // --- Look up meeting by state_id ---

    try {
        $pdo = get_db();

        $stmt = $pdo->prepare("
            SELECT m.id, m.state_id, m.title, m.meeting_date, m.meeting_time,
                   m.location, c.name AS council_name
            FROM meetings m
            JOIN councils c ON m.council_id = c.id
            WHERE m.state_id = ?
            LIMIT 1
        ");
        $stmt->execute([$meeting_state_id]);
        $meeting = $stmt->fetch();

        if (!$meeting) {
            json_error(404, 'Meeting not found.');
        }

        // Reject past meetings
        $meeting_date = $meeting['meeting_date'];
        $today = (new DateTime('now', new DateTimeZone('Pacific/Honolulu')))->format('Y-m-d');

        if ($meeting_date < $today) {
            json_error(400, 'Cannot set a reminder for a past meeting.');
        }

        // --- Check for existing confirmed reminder (dedup) ---

        $stmt = $pdo->prepare("
            SELECT id, confirmed FROM reminders
            WHERE email = ? AND meeting_id = ?
            LIMIT 1
        ");
        $stmt->execute([$email, $meeting['id']]);
        $existing = $stmt->fetch();

        if ($existing && $existing['confirmed']) {
            // Already confirmed — return success idempotently
            json_response([
                'status'  => 'already_confirmed',
                'message' => 'You already have a reminder set for this meeting.',
            ]);
        }

        // --- Create or update reminder ---

        $confirm_token = bin2hex(random_bytes(32));

        if ($existing) {
            // Re-send confirmation for unconfirmed reminder
            $stmt = $pdo->prepare("
                UPDATE reminders SET confirm_token = ?, source = ?, created_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$confirm_token, $source, $existing['id']]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO reminders (email, meeting_id, confirm_token, source)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$email, $meeting['id'], $confirm_token, $source]);
        }

    } catch (PDOException $e) {
        error_log('Reminder create error: ' . $e->getMessage());
        json_error(500, 'Database error while creating reminder.');
    }

    // --- Send confirmation email ---

    send_reminder_confirmation_email($email, $confirm_token, $meeting, $source);

    json_response([
        'status'  => 'pending_confirmation',
        'message' => 'Check your email to confirm your reminder.',
    ], 201);
}


// =====================================================================
// GET /reminders/confirm?token=xxx — Confirm reminder
// =====================================================================

function handle_reminder_confirm(array $query): void
{
    $token = isset($query['token']) ? trim($query['token']) : '';

    if (empty($token) || strlen($token) !== 64) {
        json_error(400, 'Invalid or missing confirmation token.');
    }

    try {
        $pdo = get_db();

        // Find reminder by confirm token
        $stmt = $pdo->prepare("
            SELECT r.id, r.email, r.meeting_id, r.confirmed,
                   m.state_id, m.title, m.meeting_date,
                   c.name AS council_name
            FROM reminders r
            JOIN meetings m ON r.meeting_id = m.id
            JOIN councils c ON m.council_id = c.id
            WHERE r.confirm_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $reminder = $stmt->fetch();

        if (!$reminder) {
            json_error(404, 'Invalid or expired confirmation token.');
        }

        if ($reminder['confirmed']) {
            // Already confirmed — redirect gracefully
            $redirect_url = 'https://civi.me/meetings/' . (int) $reminder['state_id'] . '?reminded=1';
            header('Location: ' . $redirect_url, true, 302);
            exit;
        }

        // Mark as confirmed and clear the confirm token
        $stmt = $pdo->prepare("
            UPDATE reminders SET confirmed = TRUE, confirm_token = ''
            WHERE id = ?
        ");
        $stmt->execute([$reminder['id']]);

    } catch (PDOException $e) {
        error_log('Reminder confirm error: ' . $e->getMessage());
        json_error(500, 'Database error during confirmation.');
    }

    // Notify admin
    $admin_body = "Meeting reminder confirmed:\n\n"
        . "Email:   {$reminder['email']}\n"
        . "Meeting: {$reminder['council_name']} — {$reminder['title']}\n"
        . "Date:    {$reminder['meeting_date']}";
    send_admin_notification('[civi.me] Reminder confirmed: ' . $reminder['email'], $admin_body);

    // Redirect to the meeting page with success flag
    $redirect_url = 'https://civi.me/meetings/' . (int) $reminder['state_id'] . '?reminded=1';
    header('Location: ' . $redirect_url, true, 302);
    exit;
}
