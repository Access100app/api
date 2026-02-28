<?php
/**
 * Access100 API - Subscriptions Endpoint
 *
 * Handles all /api/v1/subscriptions routes:
 *   POST   /subscriptions                     — create subscription (API key required)
 *   GET    /subscriptions/confirm?token=xxx    — confirm double opt-in (public)
 *   GET    /subscriptions/unsubscribe?token=x  — one-click unsubscribe (public)
 *   GET    /subscriptions/{id}?token=xxx       — get subscription details (manage token)
 *   PATCH  /subscriptions/{id}?token=xxx       — update channels, frequency (manage token)
 *   PUT    /subscriptions/{id}/councils?token=x — replace council list (manage token)
 *   DELETE /subscriptions/{id}?token=xxx       — unsubscribe completely (manage token)
 *
 * Requires: $route array from index.php, config.php loaded
 */

// Load services for confirmation sends
require_once __DIR__ . '/../services/email.php';
require_once __DIR__ . '/../services/sms.php';

$allowed_methods = ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'];
if (!in_array($route['method'], $allowed_methods, true)) {
    json_error(405, 'Method not allowed.');
}

// ─── Route Dispatch ───────────────────────────────────────────────────────────

$resource_id  = $route['resource_id'];
$sub_resource = $route['sub_resource'];
$method       = $route['method'];

// Public routes (no manage token needed — handled by router as public)
if ($resource_id === 'confirm' && $method === 'GET') {
    handle_confirm($route['query']);
} elseif ($resource_id === 'unsubscribe' && $method === 'GET') {
    handle_unsubscribe_link($route['query']);
}

// POST /subscriptions — create new (API key auth, handled by router)
elseif ($resource_id === null && $method === 'POST') {
    handle_create_subscription();
}

// Routes that require a manage token
elseif ($resource_id !== null && ctype_digit((string) $resource_id)) {
    $subscription_id = (int) $resource_id;

    if ($sub_resource === 'councils' && $method === 'PUT') {
        handle_replace_councils($subscription_id, $route['query']);
    } elseif ($sub_resource === null) {
        if ($method === 'GET') {
            handle_get_subscription($subscription_id, $route['query']);
        } elseif ($method === 'PATCH') {
            handle_update_subscription($subscription_id, $route['query']);
        } elseif ($method === 'DELETE') {
            handle_delete_subscription($subscription_id, $route['query']);
        } else {
            json_error(405, 'Method not allowed for this route.');
        }
    } else {
        $safe_sub = preg_replace('/[^a-zA-Z0-9_-]/', '', $sub_resource);
        json_error(404, 'Unknown sub-resource: ' . $safe_sub);
    }
}

else {
    json_error(404, 'Subscription route not found.');
}


// =====================================================================
// POST /subscriptions — Create a new subscription
// =====================================================================

function handle_create_subscription(): void
{
    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    // --- Validate required fields ---

    $email      = isset($body['email']) ? trim($body['email']) : null;
    $phone      = isset($body['phone']) ? trim($body['phone']) : null;
    $channels   = isset($body['channels']) && is_array($body['channels']) ? $body['channels'] : ['email'];
    $council_ids = isset($body['council_ids']) && is_array($body['council_ids']) ? $body['council_ids'] : [];
    $topic_slugs = isset($body['topics']) && is_array($body['topics']) ? $body['topics'] : [];
    $frequency  = isset($body['frequency']) ? trim($body['frequency']) : 'immediate';
    $source     = isset($body['source']) ? substr(trim($body['source']), 0, 50) : 'access100';

    // Must have at least email or phone
    if (empty($email) && empty($phone)) {
        json_error(400, 'At least one of email or phone is required.');
    }

    // Validate email format
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(400, 'Invalid email address format.');
    }

    // Validate phone format (E.164: +1XXXXXXXXXX)
    if (!empty($phone) && !preg_match('/^\+1\d{10}$/', $phone)) {
        json_error(400, 'Invalid phone number. Use E.164 format: +1XXXXXXXXXX');
    }

    // Validate channels
    $valid_channels = ['email', 'sms'];
    $channels = array_intersect($channels, $valid_channels);
    if (empty($channels)) {
        json_error(400, 'At least one valid channel (email, sms) is required.');
    }
    if (in_array('email', $channels) && empty($email)) {
        json_error(400, 'Email address required when email channel is selected.');
    }
    if (in_array('sms', $channels) && empty($phone)) {
        json_error(400, 'Phone number required when sms channel is selected.');
    }

    // Sanitize topic slugs
    $topic_slugs = array_filter(array_map(function ($s) {
        $s = trim((string) $s);
        return preg_match('/^[a-z0-9-]{1,50}$/', $s) ? $s : null;
    }, $topic_slugs));

    // Validate: must have at least one council_id or topic
    $council_ids = array_filter(array_map('intval', $council_ids), function ($id) {
        return $id > 0;
    });
    if (empty($council_ids) && empty($topic_slugs)) {
        json_error(400, 'At least one council_id or topic is required.');
    }

    // Validate frequency
    $valid_frequencies = ['immediate', 'daily', 'weekly'];
    if (!in_array($frequency, $valid_frequencies, true)) {
        json_error(400, 'Invalid frequency. Must be: immediate, daily, or weekly.');
    }

    // --- Generate tokens ---

    $confirm_token = generate_token();
    $manage_token  = generate_token();

    // --- Database operations ---

    try {
        $pdo = get_db();
        $pdo->beginTransaction();

        // Resolve topic slugs to topic IDs and their mapped councils
        $valid_topic_ids = [];
        if (!empty($topic_slugs)) {
            $slug_ph = implode(',', array_fill(0, count($topic_slugs), '?'));
            $stmt = $pdo->prepare("SELECT id FROM topics WHERE slug IN ({$slug_ph})");
            $stmt->execute(array_values($topic_slugs));
            $valid_topic_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // Also resolve topic councils into the council_ids list
            if (!empty($valid_topic_ids)) {
                $tid_ph = implode(',', array_fill(0, count($valid_topic_ids), '?'));
                $stmt = $pdo->prepare("SELECT DISTINCT council_id FROM topic_council_map WHERE topic_id IN ({$tid_ph})");
                $stmt->execute(array_values($valid_topic_ids));
                $topic_council_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $council_ids = array_unique(array_merge($council_ids, array_map('intval', $topic_council_ids)));
            }
        }

        // Verify council_ids exist
        $valid_council_ids = [];
        if (!empty($council_ids)) {
            $placeholders = implode(',', array_fill(0, count($council_ids), '?'));
            $stmt = $pdo->prepare("SELECT id FROM councils WHERE id IN ({$placeholders})");
            $stmt->execute(array_values($council_ids));
            $valid_council_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($valid_council_ids)) {
            $pdo->rollBack();
            json_error(400, 'No valid councils found (directly or via topics).');
        }

        // Find or create user by email (or phone if no email)
        $lookup_field = !empty($email) ? 'email' : 'phone';
        $lookup_value = !empty($email) ? $email : $phone;

        $stmt = $pdo->prepare("SELECT id, manage_token FROM users WHERE {$lookup_field} = ? LIMIT 1");
        $stmt->execute([$lookup_value]);
        $user = $stmt->fetch();

        if ($user) {
            $user_id = (int) $user['id'];
            // Update user with new info and tokens
            $stmt = $pdo->prepare("
                UPDATE users SET
                    phone = COALESCE(?, phone),
                    email = COALESCE(?, email),
                    confirm_token = ?,
                    manage_token = ?,
                    confirmed_email = FALSE,
                    confirmed_phone = FALSE
                WHERE id = ?
            ");
            $stmt->execute([$phone, $email, $confirm_token, $manage_token, $user_id]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO users (email, phone, confirm_token, manage_token, confirmed_email, confirmed_phone)
                VALUES (?, ?, ?, ?, FALSE, FALSE)
            ");
            $stmt->execute([$email, $phone, $confirm_token, $manage_token]);
            $user_id = (int) $pdo->lastInsertId();
        }

        // Store topic_ids JSON if topics were provided
        $topic_ids_json = !empty($valid_topic_ids) ? json_encode(array_map('intval', $valid_topic_ids)) : null;

        // Insert subscriptions for each council (skip duplicates)
        $channels_value = implode(',', $channels);
        $insert_stmt = $pdo->prepare("
            INSERT IGNORE INTO subscriptions (user_id, council_id, channels, frequency, source, active, topic_ids)
            VALUES (?, ?, ?, ?, ?, TRUE, ?)
        ");
        foreach ($valid_council_ids as $cid) {
            $insert_stmt->execute([$user_id, $cid, $channels_value, $frequency, $source, $topic_ids_json]);
        }

        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Subscription create error: ' . $e->getMessage());
        json_error(500, 'Database error while creating subscription.');
    }

    // --- Send confirmation ---
    if (!empty($email) && in_array('email', $channels)) {
        send_confirmation_email($email, $confirm_token, $source);
    }
    if (!empty($phone) && in_array('sms', $channels)) {
        send_confirmation_sms($phone, $confirm_token);
    }

    json_response([
        'user_id'       => $user_id,
        'status'        => 'pending_confirmation',
        'manage_token'  => $manage_token,
        'councils'      => array_values(array_map('intval', $valid_council_ids)),
        'topics'        => array_values(array_map('intval', $valid_topic_ids)),
        'channels'      => array_values($channels),
        'frequency'     => $frequency,
        'message'       => !empty($email)
            ? 'Verification sent to ' . $email
            : 'Verification sent to your phone number.',
    ], 201);
}


// =====================================================================
// GET /subscriptions/confirm?token=xxx — Double opt-in confirmation
// =====================================================================

function handle_confirm(array $query): void
{
    $token = isset($query['token']) ? trim($query['token']) : '';

    if (empty($token) || strlen($token) !== 64) {
        json_error(400, 'Invalid or missing confirmation token.');
    }

    try {
        $pdo = get_db();

        // Find user by confirm token
        $stmt = $pdo->prepare("SELECT id, email, phone FROM users WHERE confirm_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            json_error(404, 'Invalid or expired confirmation token.');
        }

        // Mark as confirmed and clear the confirm token
        $stmt = $pdo->prepare("
            UPDATE users SET
                confirmed_email = CASE WHEN email IS NOT NULL THEN TRUE ELSE confirmed_email END,
                confirmed_phone = CASE WHEN phone IS NOT NULL THEN TRUE ELSE confirmed_phone END,
                confirm_token = NULL
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);

    } catch (PDOException $e) {
        error_log('Subscription confirm error: ' . $e->getMessage());
        json_error(500, 'Database error during confirmation.');
    }

    // Redirect to civi.me confirmation page
    $redirect_url = 'https://civi.me/notifications/confirmed';
    header('Location: ' . $redirect_url, true, 302);
    exit;
}


// =====================================================================
// GET /subscriptions/unsubscribe?token=xxx — One-click unsubscribe
// =====================================================================

function handle_unsubscribe_link(array $query): void
{
    $token = isset($query['token']) ? trim($query['token']) : '';

    if (empty($token) || strlen($token) !== 64) {
        json_error(400, 'Invalid or missing unsubscribe token.');
    }

    try {
        $pdo = get_db();

        // Find user by manage token
        $stmt = $pdo->prepare("SELECT id FROM users WHERE manage_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            json_error(404, 'Invalid or expired unsubscribe token.');
        }

        // Deactivate all subscriptions for this user
        $stmt = $pdo->prepare("UPDATE subscriptions SET active = FALSE WHERE user_id = ?");
        $stmt->execute([$user['id']]);

    } catch (PDOException $e) {
        error_log('Unsubscribe error: ' . $e->getMessage());
        json_error(500, 'Database error during unsubscribe.');
    }

    // Redirect to civi.me with unsubscribed confirmation
    $redirect_url = 'https://civi.me/notifications/unsubscribed';
    header('Location: ' . $redirect_url, true, 302);
    exit;
}


// =====================================================================
// GET /subscriptions/{id}?token=xxx — Get subscription details
// =====================================================================

function handle_get_subscription(int $user_id, array $query): void
{
    $user = validate_manage_token($user_id, $query);

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT s.id, s.council_id, s.channels, s.frequency, s.source, s.active,
                   c.name AS council_name
            FROM subscriptions s
            JOIN councils c ON s.council_id = c.id
            WHERE s.user_id = ?
            ORDER BY c.name ASC
        ");
        $stmt->execute([$user_id]);
        $subscriptions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Get subscription error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching subscription.');
    }

    $councils = array_map(function (array $row): array {
        return [
            'subscription_id' => (int) $row['id'],
            'council_id'      => (int) $row['council_id'],
            'council_name'    => $row['council_name'],
            'channels'        => explode(',', $row['channels']),
            'frequency'       => $row['frequency'],
            'active'          => (bool) $row['active'],
        ];
    }, $subscriptions);

    json_response([
        'user_id'         => $user_id,
        'email'           => $user['email'],
        'phone'           => $user['phone'],
        'confirmed_email' => (bool) $user['confirmed_email'],
        'confirmed_phone' => (bool) $user['confirmed_phone'],
        'subscriptions'   => $councils,
    ]);
}


// =====================================================================
// PATCH /subscriptions/{id}?token=xxx — Update channels, frequency
// =====================================================================

function handle_update_subscription(int $user_id, array $query): void
{
    validate_manage_token($user_id, $query);

    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    $updates = [];

    // Update email
    if (isset($body['email'])) {
        $email = trim($body['email']);
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_error(400, 'Invalid email address format.');
        }
        $updates['user_email'] = $email ?: null;
    }

    // Update phone
    if (isset($body['phone'])) {
        $phone = trim($body['phone']);
        if (!empty($phone) && !preg_match('/^\+1\d{10}$/', $phone)) {
            json_error(400, 'Invalid phone number. Use E.164 format: +1XXXXXXXXXX');
        }
        $updates['user_phone'] = $phone ?: null;
    }

    // Update channels on all subscriptions
    if (isset($body['channels']) && is_array($body['channels'])) {
        $valid_channels = array_intersect($body['channels'], ['email', 'sms']);
        if (!empty($valid_channels)) {
            $updates['channels'] = implode(',', $valid_channels);
        }
    }

    // Update frequency on all subscriptions
    if (isset($body['frequency'])) {
        $valid_frequencies = ['immediate', 'daily', 'weekly'];
        if (in_array($body['frequency'], $valid_frequencies, true)) {
            $updates['frequency'] = $body['frequency'];
        }
    }

    if (empty($updates)) {
        json_error(400, 'No valid fields to update.');
    }

    try {
        $pdo = get_db();
        $pdo->beginTransaction();

        // Update user-level fields
        if (isset($updates['user_email']) || isset($updates['user_phone'])) {
            $user_sets  = [];
            $user_params = [];
            if (array_key_exists('user_email', $updates)) {
                $user_sets[]  = 'email = ?';
                $user_params[] = $updates['user_email'];
            }
            if (array_key_exists('user_phone', $updates)) {
                $user_sets[]  = 'phone = ?';
                $user_params[] = $updates['user_phone'];
            }
            $user_params[] = $user_id;
            $pdo->prepare("UPDATE users SET " . implode(', ', $user_sets) . " WHERE id = ?")
                ->execute($user_params);
        }

        // Update subscription-level fields
        $sub_sets  = [];
        $sub_params = [];
        if (isset($updates['channels'])) {
            $sub_sets[]  = 'channels = ?';
            $sub_params[] = $updates['channels'];
        }
        if (isset($updates['frequency'])) {
            $sub_sets[]  = 'frequency = ?';
            $sub_params[] = $updates['frequency'];
        }
        if (!empty($sub_sets)) {
            $sub_params[] = $user_id;
            $pdo->prepare("UPDATE subscriptions SET " . implode(', ', $sub_sets) . " WHERE user_id = ?")
                ->execute($sub_params);
        }

        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Update subscription error: ' . $e->getMessage());
        json_error(500, 'Database error while updating subscription.');
    }

    json_response([
        'user_id' => $user_id,
        'status'  => 'updated',
        'message' => 'Subscription preferences updated.',
    ]);
}


// =====================================================================
// PUT /subscriptions/{id}/councils?token=xxx — Replace council list
// =====================================================================

function handle_replace_councils(int $user_id, array $query): void
{
    validate_manage_token($user_id, $query);

    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    $council_ids = isset($body['council_ids']) && is_array($body['council_ids'])
        ? array_filter(array_map('intval', $body['council_ids']), function ($id) { return $id > 0; })
        : [];

    if (empty($council_ids)) {
        json_error(400, 'At least one valid council_id is required.');
    }

    try {
        $pdo = get_db();
        $pdo->beginTransaction();

        // Verify council_ids exist
        $placeholders = implode(',', array_fill(0, count($council_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM councils WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($council_ids));
        $valid_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($valid_ids)) {
            $pdo->rollBack();
            json_error(400, 'None of the provided council IDs are valid.');
        }

        // Get current subscription settings to preserve channels/frequency/source
        $stmt = $pdo->prepare("
            SELECT channels, frequency, source FROM subscriptions
            WHERE user_id = ? LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $current = $stmt->fetch();

        $channels  = $current ? $current['channels'] : 'email';
        $frequency = $current ? $current['frequency'] : 'immediate';
        $source    = $current ? $current['source'] : 'access100';

        // Deactivate all current subscriptions
        $pdo->prepare("UPDATE subscriptions SET active = FALSE WHERE user_id = ?")
            ->execute([$user_id]);

        // Insert new subscriptions (or reactivate existing ones)
        $stmt = $pdo->prepare("
            INSERT INTO subscriptions (user_id, council_id, channels, frequency, source, active)
            VALUES (?, ?, ?, ?, ?, TRUE)
            ON DUPLICATE KEY UPDATE active = TRUE, channels = VALUES(channels), frequency = VALUES(frequency)
        ");
        foreach ($valid_ids as $cid) {
            $stmt->execute([$user_id, $cid, $channels, $frequency, $source]);
        }

        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Replace councils error: ' . $e->getMessage());
        json_error(500, 'Database error while updating councils.');
    }

    json_response([
        'user_id'  => $user_id,
        'councils' => array_values(array_map('intval', $valid_ids)),
        'status'   => 'updated',
        'message'  => 'Council subscriptions replaced.',
    ]);
}


// =====================================================================
// DELETE /subscriptions/{id}?token=xxx — Unsubscribe completely
// =====================================================================

function handle_delete_subscription(int $user_id, array $query): void
{
    validate_manage_token($user_id, $query);

    try {
        $pdo = get_db();

        // Deactivate all subscriptions (soft delete — preserve history)
        $stmt = $pdo->prepare("UPDATE subscriptions SET active = FALSE WHERE user_id = ?");
        $stmt->execute([$user_id]);

    } catch (PDOException $e) {
        error_log('Delete subscription error: ' . $e->getMessage());
        json_error(500, 'Database error while unsubscribing.');
    }

    json_response([
        'user_id' => $user_id,
        'status'  => 'unsubscribed',
        'message' => 'All subscriptions have been deactivated.',
    ]);
}


// =====================================================================
// Shared helpers
// =====================================================================

/**
 * Validate the manage token from the query string.
 *
 * Returns the user row if valid, or terminates with a 401/404 error.
 */
function validate_manage_token(int $user_id, array $query): array
{
    $token = isset($query['token']) ? trim($query['token']) : '';

    if (empty($token)) {
        json_error(401, 'Manage token is required. Pass ?token=xxx in the URL.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("SELECT id, email, phone, confirmed_email, confirmed_phone, manage_token FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Token validation error: ' . $e->getMessage());
        json_error(500, 'Database error during authentication.');
    }

    if (!$user) {
        json_error(404, 'Subscription not found.');
    }

    // Timing-safe comparison
    if (!hash_equals($user['manage_token'] ?? '', $token)) {
        json_error(401, 'Invalid manage token.');
    }

    return $user;
}

/**
 * Generate a cryptographically secure 64-character hex token.
 */
function generate_token(): string
{
    return bin2hex(random_bytes(32));
}
