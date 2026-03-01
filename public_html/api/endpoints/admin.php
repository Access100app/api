<?php
/**
 * Access100 API - Admin Endpoint
 *
 * Handles all /api/v1/admin routes (API key required):
 *   GET    /admin/subscribers            — paginated list of subscribers
 *   POST   /admin/subscribers            — create a subscriber (skip confirmation)
 *   PATCH  /admin/subscribers/{user_id}  — update subscriber fields
 *   DELETE /admin/subscribers/{user_id}  — soft-deactivate (default) or hard delete (?hard=true)
 *
 * Requires: $route array from index.php, config.php loaded
 */

$allowed_methods = ['GET', 'POST', 'PATCH', 'DELETE'];
if (!in_array($route['method'], $allowed_methods, true)) {
    json_error(405, 'Method not allowed.');
}

// ─── Route Dispatch ───────────────────────────────────────────────────────────

$resource_id  = $route['resource_id'];
$sub_resource = $route['sub_resource'];
$method       = $route['method'];

// GET /admin/subscribers — list all subscribers
if ($resource_id === 'subscribers' && $sub_resource === null && $method === 'GET') {
    handle_list_subscribers($route['query']);
}

// POST /admin/subscribers — create a subscriber directly
elseif ($resource_id === 'subscribers' && $sub_resource === null && $method === 'POST') {
    handle_create_subscriber();
}

// PATCH /admin/subscribers/{user_id} — update subscriber fields
elseif ($resource_id === 'subscribers' && $sub_resource !== null && ctype_digit((string) $sub_resource) && $method === 'PATCH') {
    handle_update_subscriber((int) $sub_resource);
}

// DELETE /admin/subscribers/{user_id} — deactivate or hard delete
elseif ($resource_id === 'subscribers' && $sub_resource !== null && ctype_digit((string) $sub_resource) && $method === 'DELETE') {
    handle_deactivate_subscriber((int) $sub_resource, $route['query']);
}

else {
    json_error(404, 'Admin route not found.');
}


// =====================================================================
// GET /admin/subscribers — Paginated list of subscribers
// =====================================================================

function handle_list_subscribers(array $query): void
{
    $limit  = isset($query['limit']) ? min(max((int) $query['limit'], 1), 100) : 25;
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0) : 0;
    $search = isset($query['q']) ? trim($query['q']) : '';
    $status = isset($query['status']) ? trim($query['status']) : 'all';
    $confirmed = isset($query['confirmed']) ? trim($query['confirmed']) : '';

    try {
        $pdo = get_db();

        // Build WHERE clause
        $where_clauses = [];
        $params = [];

        if ($search !== '') {
            $where_clauses[] = '(u.email LIKE ? OR u.phone LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        if ($confirmed === 'true') {
            $where_clauses[] = '(u.confirmed_email = TRUE OR u.confirmed_phone = TRUE)';
        } elseif ($confirmed === 'false') {
            $where_clauses[] = '(u.confirmed_email = FALSE AND u.confirmed_phone = FALSE)';
        }

        // Status filter: if active/inactive, only include users who have at least
        // one subscription with that status
        if ($status === 'active') {
            $where_clauses[] = 'EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.active = TRUE)';
        } elseif ($status === 'inactive') {
            $where_clauses[] = 'NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id = u.id AND s.active = TRUE)';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count total
        $count_sql = "SELECT COUNT(*) FROM users u {$where_sql}";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch users
        $users_sql = "SELECT u.id, u.email, u.phone, u.confirmed_email, u.confirmed_phone, u.created_at
                      FROM users u {$where_sql}
                      ORDER BY u.created_at DESC
                      LIMIT ? OFFSET ?";
        $user_params = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($users_sql);
        $stmt->execute($user_params);
        $users = $stmt->fetchAll();

        if (empty($users)) {
            json_response([
                'subscribers' => [],
            ], 200, [
                'total'  => 0,
                'limit'  => $limit,
                'offset' => $offset,
            ]);
        }

        // Fetch subscriptions for these users, joined to councils
        $user_ids = array_column($users, 'id');
        $id_placeholders = implode(',', array_fill(0, count($user_ids), '?'));

        $subs_sql = "SELECT s.user_id, s.council_id, c.name AS council_name,
                            s.channels, s.frequency, s.source, s.active
                     FROM subscriptions s
                     JOIN councils c ON s.council_id = c.id
                     WHERE s.user_id IN ({$id_placeholders})
                     ORDER BY c.name ASC";
        $stmt = $pdo->prepare($subs_sql);
        $stmt->execute($user_ids);
        $all_subs = $stmt->fetchAll();

        // Group subscriptions by user_id
        $subs_by_user = [];
        foreach ($all_subs as $sub) {
            $uid = (int) $sub['user_id'];
            $subs_by_user[$uid][] = [
                'council_id'   => (int) $sub['council_id'],
                'council_name' => $sub['council_name'],
                'channels'     => explode(',', $sub['channels']),
                'frequency'    => $sub['frequency'],
                'source'       => $sub['source'],
                'active'       => (bool) $sub['active'],
            ];
        }

        // Build response
        $subscribers = [];
        foreach ($users as $user) {
            $uid = (int) $user['id'];
            $subscribers[] = [
                'user_id'         => $uid,
                'email'           => $user['email'],
                'phone'           => $user['phone'],
                'confirmed_email' => (bool) $user['confirmed_email'],
                'confirmed_phone' => (bool) $user['confirmed_phone'],
                'created_at'      => $user['created_at'],
                'subscriptions'   => $subs_by_user[$uid] ?? [],
            ];
        }

    } catch (PDOException $e) {
        error_log('Admin list subscribers error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching subscribers.');
    }

    json_response([
        'subscribers' => $subscribers,
    ], 200, [
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}


// =====================================================================
// POST /admin/subscribers — Create subscriber (skip confirmation)
// =====================================================================

function handle_create_subscriber(): void
{
    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    $email       = isset($body['email']) ? trim($body['email']) : null;
    $channels    = isset($body['channels']) && is_array($body['channels']) ? $body['channels'] : ['email'];
    $council_ids = isset($body['council_ids']) && is_array($body['council_ids']) ? $body['council_ids'] : [];
    $frequency   = isset($body['frequency']) ? trim($body['frequency']) : 'immediate';

    // Validate email
    if (empty($email)) {
        json_error(400, 'Email is required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error(400, 'Invalid email address format.');
    }

    // Validate channels
    $valid_channels = ['email', 'sms'];
    $channels = array_values(array_intersect($channels, $valid_channels));
    if (empty($channels)) {
        json_error(400, 'At least one valid channel (email, sms) is required.');
    }

    // Validate frequency
    $valid_frequencies = ['immediate', 'daily', 'weekly'];
    if (!in_array($frequency, $valid_frequencies, true)) {
        json_error(400, 'Invalid frequency. Must be: immediate, daily, or weekly.');
    }

    // Validate council_ids
    $council_ids = array_filter(array_map('intval', $council_ids), function ($id) {
        return $id > 0;
    });
    if (empty($council_ids)) {
        json_error(400, 'At least one council_id is required.');
    }

    $manage_token = bin2hex(random_bytes(32));

    try {
        $pdo = get_db();
        $pdo->beginTransaction();

        // Verify council_ids exist
        $placeholders = implode(',', array_fill(0, count($council_ids), '?'));
        $stmt = $pdo->prepare("SELECT id FROM councils WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($council_ids));
        $valid_council_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($valid_council_ids)) {
            $pdo->rollBack();
            json_error(400, 'No valid council IDs found.');
        }

        // Check if user already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $existing = $stmt->fetch();

        if ($existing) {
            $pdo->rollBack();
            json_error(409, 'A subscriber with this email already exists.');
        }

        // Create user — admin-created = confirmed automatically
        $stmt = $pdo->prepare("
            INSERT INTO users (email, manage_token, confirmed_email, confirmed_phone)
            VALUES (?, ?, TRUE, FALSE)
        ");
        $stmt->execute([$email, $manage_token]);
        $user_id = (int) $pdo->lastInsertId();

        // Insert subscriptions
        $channels_value = implode(',', $channels);
        $insert_stmt = $pdo->prepare("
            INSERT INTO subscriptions (user_id, council_id, channels, frequency, source, active)
            VALUES (?, ?, ?, ?, 'admin', TRUE)
        ");
        foreach ($valid_council_ids as $cid) {
            $insert_stmt->execute([$user_id, $cid, $channels_value, $frequency]);
        }

        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin create subscriber error: ' . $e->getMessage());
        json_error(500, 'Database error while creating subscriber.');
    }

    json_response([
        'user_id'      => $user_id,
        'email'        => $email,
        'councils'     => array_values(array_map('intval', $valid_council_ids)),
        'channels'     => $channels,
        'frequency'    => $frequency,
        'status'       => 'confirmed',
        'manage_token' => $manage_token,
        'message'      => 'Subscriber created successfully.',
    ], 201);
}


// =====================================================================
// PATCH /admin/subscribers/{user_id} — Update subscriber
// =====================================================================

function handle_update_subscriber(int $user_id): void
{
    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    try {
        $pdo = get_db();

        // Verify user exists
        $stmt = $pdo->prepare("SELECT id, email FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'User not found.');
        }

        $pdo->beginTransaction();

        // Update email if provided
        if (isset($body['email'])) {
            $email = trim($body['email']);
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $pdo->rollBack();
                json_error(400, 'Invalid email address format.');
            }
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $user_id]);
        }

        // Update channels on all active subscriptions
        if (isset($body['channels']) && is_array($body['channels'])) {
            $valid_channels = array_intersect($body['channels'], ['email', 'sms']);
            if (!empty($valid_channels)) {
                $channels_value = implode(',', $valid_channels);
                $pdo->prepare("UPDATE subscriptions SET channels = ? WHERE user_id = ? AND active = TRUE")
                    ->execute([$channels_value, $user_id]);
            }
        }

        // Update frequency on all active subscriptions
        if (isset($body['frequency'])) {
            $valid_frequencies = ['immediate', 'daily', 'weekly'];
            if (in_array($body['frequency'], $valid_frequencies, true)) {
                $pdo->prepare("UPDATE subscriptions SET frequency = ? WHERE user_id = ? AND active = TRUE")
                    ->execute([$body['frequency'], $user_id]);
            }
        }

        // Replace councils if provided (same pattern as handle_replace_councils)
        if (isset($body['council_ids']) && is_array($body['council_ids'])) {
            $council_ids = array_filter(array_map('intval', $body['council_ids']), function ($id) {
                return $id > 0;
            });

            if (!empty($council_ids)) {
                // Verify council_ids exist
                $placeholders = implode(',', array_fill(0, count($council_ids), '?'));
                $stmt = $pdo->prepare("SELECT id FROM councils WHERE id IN ({$placeholders})");
                $stmt->execute(array_values($council_ids));
                $valid_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($valid_ids)) {
                    // Get current subscription settings to preserve channels/frequency/source
                    $stmt = $pdo->prepare("SELECT channels, frequency, source FROM subscriptions WHERE user_id = ? LIMIT 1");
                    $stmt->execute([$user_id]);
                    $current = $stmt->fetch();

                    $channels  = $current ? $current['channels'] : 'email';
                    $frequency_val = $current ? $current['frequency'] : 'immediate';
                    $source    = $current ? $current['source'] : 'admin';

                    // If channels/frequency were also provided in this request, use those
                    if (isset($body['channels']) && is_array($body['channels'])) {
                        $ch = array_intersect($body['channels'], ['email', 'sms']);
                        if (!empty($ch)) {
                            $channels = implode(',', $ch);
                        }
                    }
                    if (isset($body['frequency']) && in_array($body['frequency'], ['immediate', 'daily', 'weekly'], true)) {
                        $frequency_val = $body['frequency'];
                    }

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
                        $stmt->execute([$user_id, $cid, $channels, $frequency_val, $source]);
                    }
                }
            }
        }

        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin update subscriber error: ' . $e->getMessage());
        json_error(500, 'Database error while updating subscriber.');
    }

    json_response([
        'user_id' => $user_id,
        'status'  => 'updated',
        'message' => 'Subscriber updated successfully.',
    ]);
}


// =====================================================================
// DELETE /admin/subscribers/{user_id} — Deactivate or hard delete
// =====================================================================

function handle_deactivate_subscriber(int $user_id, array $query): void
{
    $hard = isset($query['hard']) && $query['hard'] === 'true';

    try {
        $pdo = get_db();

        // Verify user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'User not found.');
        }

        if ($hard) {
            // Hard delete: remove subscriptions and user permanently
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM subscriptions WHERE user_id = ?")->execute([$user_id]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
            $pdo->commit();

            json_response([
                'user_id' => $user_id,
                'status'  => 'deleted',
                'message' => "User {$user_id} and all subscriptions permanently deleted.",
            ]);
        }

        // Soft deactivate: set subscriptions inactive
        $stmt = $pdo->prepare("UPDATE subscriptions SET active = FALSE WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $affected = $stmt->rowCount();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin deactivate subscriber error: ' . $e->getMessage());
        json_error(500, 'Database error while deactivating subscriber.');
    }

    json_response([
        'user_id'      => $user_id,
        'status'       => 'deactivated',
        'deactivated'  => $affected,
        'message'      => "Deactivated {$affected} subscription(s) for user {$user_id}.",
    ]);
}
