<?php
/**
 * Access100 API - Admin Endpoint
 *
 * Handles all /api/v1/admin routes (API key required):
 *   GET    /admin/subscribers            — paginated list of subscribers
 *   POST   /admin/subscribers            — create a subscriber (skip confirmation)
 *   PATCH  /admin/subscribers/{user_id}  — update subscriber fields
 *   DELETE /admin/subscribers/{user_id}  — soft-deactivate (default) or hard delete (?hard=true)
 *   GET    /admin/reminders              — paginated list of reminders
 *   DELETE /admin/reminders/{id}         — hard delete a reminder
 *   GET    /admin/meetings               — paginated list of meetings
 *   GET    /admin/meetings/check-links   — check all meeting detail_url links
 *   PATCH  /admin/meetings/{id}          — update meeting fields (state_id, detail_url, status, title)
 *
 *   GET    /admin/scraper/runs              — recent scraper run history
 *   POST   /admin/scraper/trigger           — manually trigger eHawaii scrape
 *   POST   /admin/scraper/trigger-nco       — manually trigger NCO scrape
 *   POST   /admin/scraper/trigger-honolulu-boards — manually trigger Honolulu boards scrape
 *   POST   /admin/scraper/trigger-maui            — manually trigger Maui Legistar scrape
 *
 *   GET    /admin/councils                            — paginated list of councils
 *   GET    /admin/councils/{id}                       — full council detail
 *   PATCH  /admin/councils/{id}                       — update council + profile
 *   POST   /admin/councils/{id}/members               — add a member
 *   DELETE /admin/councils/{id}/members/{member_id}   — delete a member
 *   POST   /admin/councils/{id}/vacancies             — add a vacancy
 *   DELETE /admin/councils/{id}/vacancies/{vacancy_id} — delete a vacancy
 *   POST   /admin/councils/{id}/authority             — add a legal authority
 *   DELETE /admin/councils/{id}/authority/{auth_id}   — delete a legal authority
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

// GET /admin/reminders — list all reminders
elseif ($resource_id === 'reminders' && $sub_resource === null && $method === 'GET') {
    handle_list_reminders($route['query']);
}

// DELETE /admin/reminders/{id} — hard delete a reminder
elseif ($resource_id === 'reminders' && $sub_resource !== null && ctype_digit((string) $sub_resource) && $method === 'DELETE') {
    handle_delete_reminder((int) $sub_resource);
}

// GET /admin/meetings — list all meetings
elseif ($resource_id === 'meetings' && $sub_resource === null && $method === 'GET') {
    handle_list_admin_meetings($route['query']);
}

// GET /admin/meetings/check-links — check all meeting detail_url links
elseif ($resource_id === 'meetings' && $sub_resource === 'check-links' && $method === 'GET') {
    handle_check_meeting_links($route['query']);
}

// PATCH /admin/meetings/{id} — update meeting fields
elseif ($resource_id === 'meetings' && $sub_resource !== null && ctype_digit((string) $sub_resource) && $method === 'PATCH') {
    handle_update_meeting((int) $sub_resource);
}

// GET /admin/scraper/runs — list recent scraper runs
elseif ($resource_id === 'scraper' && $sub_resource === 'runs' && $method === 'GET') {
    handle_list_scraper_runs($route['query']);
}

// POST /admin/scraper/trigger — manually trigger a scrape
elseif ($resource_id === 'scraper' && $sub_resource === 'trigger' && $method === 'POST') {
    handle_trigger_scrape();
}

// POST /admin/scraper/trigger-nco — manually trigger NCO scrape
elseif ($resource_id === 'scraper' && $sub_resource === 'trigger-nco' && $method === 'POST') {
    handle_trigger_nco_scrape();
}

// POST /admin/scraper/trigger-honolulu-boards — manually trigger Honolulu boards scrape
elseif ($resource_id === 'scraper' && $sub_resource === 'trigger-honolulu-boards' && $method === 'POST') {
    handle_trigger_honolulu_boards_scrape();
}

// POST /admin/scraper/trigger-maui — manually trigger Maui Legistar scrape
elseif ($resource_id === 'scraper' && $sub_resource === 'trigger-maui' && $method === 'POST') {
    handle_trigger_maui_scrape();
}

// ─── Councils Admin Routes ─────────────────────────────────────────────────
// Uses $route['segments'] for nested paths:
//   /admin/councils           → segments: [admin, councils]
//   /admin/councils/5         → segments: [admin, councils, 5]
//   /admin/councils/5/members → segments: [admin, councils, 5, members]
//   /admin/councils/5/members/12 → segments: [admin, councils, 5, members, 12]

// GET /admin/councils — list all councils
elseif ($resource_id === 'councils' && $sub_resource === null && $method === 'GET') {
    handle_list_councils($route['query']);
}

// GET /admin/councils/{id} — full council detail
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && !isset($route['segments'][3]) && $method === 'GET') {
    handle_get_council((int) $sub_resource);
}

// PATCH /admin/councils/{id} — update council + profile
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && !isset($route['segments'][3]) && $method === 'PATCH') {
    handle_update_council((int) $sub_resource);
}

// POST /admin/councils/{id}/members — add a member
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && ($route['segments'][3] ?? '') === 'members' && !isset($route['segments'][4]) && $method === 'POST') {
    handle_add_member((int) $sub_resource);
}

// DELETE /admin/councils/{id}/members/{member_id} — delete a member
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && ($route['segments'][3] ?? '') === 'members' && isset($route['segments'][4]) && ctype_digit((string) $route['segments'][4]) && $method === 'DELETE') {
    handle_delete_member((int) $sub_resource, (int) $route['segments'][4]);
}

// POST /admin/councils/{id}/vacancies — add a vacancy
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && ($route['segments'][3] ?? '') === 'vacancies' && !isset($route['segments'][4]) && $method === 'POST') {
    handle_add_vacancy((int) $sub_resource);
}

// DELETE /admin/councils/{id}/vacancies/{vacancy_id} — delete a vacancy
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && ($route['segments'][3] ?? '') === 'vacancies' && isset($route['segments'][4]) && ctype_digit((string) $route['segments'][4]) && $method === 'DELETE') {
    handle_delete_vacancy((int) $sub_resource, (int) $route['segments'][4]);
}

// POST /admin/councils/{id}/authority — add a legal authority
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && ($route['segments'][3] ?? '') === 'authority' && !isset($route['segments'][4]) && $method === 'POST') {
    handle_add_authority((int) $sub_resource);
}

// DELETE /admin/councils/{id}/authority/{authority_id} — delete a legal authority
elseif ($resource_id === 'councils' && $sub_resource !== null && ctype_digit((string) $sub_resource) && ($route['segments'][3] ?? '') === 'authority' && isset($route['segments'][4]) && ctype_digit((string) $route['segments'][4]) && $method === 'DELETE') {
    handle_delete_authority((int) $sub_resource, (int) $route['segments'][4]);
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


// =====================================================================
// GET /admin/reminders — Paginated list of reminders
// =====================================================================

function handle_list_reminders(array $query): void
{
    $limit  = isset($query['limit']) ? min(max((int) $query['limit'], 1), 100) : 25;
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0) : 0;
    $search = isset($query['q']) ? trim($query['q']) : '';
    $confirmed = isset($query['confirmed']) ? trim($query['confirmed']) : '';
    $sent = isset($query['sent']) ? trim($query['sent']) : '';

    try {
        $pdo = get_db();

        // Build WHERE clause
        $where_clauses = [];
        $params = [];

        if ($search !== '') {
            $where_clauses[] = 'r.email LIKE ?';
            $params[] = '%' . $search . '%';
        }

        if ($confirmed === 'true') {
            $where_clauses[] = 'r.confirmed = TRUE';
        } elseif ($confirmed === 'false') {
            $where_clauses[] = 'r.confirmed = FALSE';
        }

        if ($sent === 'true') {
            $where_clauses[] = 'r.sent = TRUE';
        } elseif ($sent === 'false') {
            $where_clauses[] = 'r.sent = FALSE';
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count total
        $count_sql = "SELECT COUNT(*) FROM reminders r {$where_sql}";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch reminders joined to meetings and councils
        $select_sql = "SELECT r.id, r.email, r.confirmed, r.sent, r.sent_at, r.source, r.created_at,
                              m.title AS meeting_title, m.meeting_date,
                              c.name AS council_name
                       FROM reminders r
                       JOIN meetings m ON r.meeting_id = m.id
                       JOIN councils c ON m.council_id = c.id
                       {$where_sql}
                       ORDER BY r.created_at DESC
                       LIMIT ? OFFSET ?";
        $select_params = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($select_sql);
        $stmt->execute($select_params);
        $rows = $stmt->fetchAll();

        $reminders = [];
        foreach ($rows as $row) {
            $reminders[] = [
                'id'            => (int) $row['id'],
                'email'         => $row['email'],
                'meeting_title' => $row['meeting_title'],
                'council_name'  => $row['council_name'],
                'meeting_date'  => $row['meeting_date'],
                'confirmed'     => (bool) $row['confirmed'],
                'sent'          => (bool) $row['sent'],
                'sent_at'       => $row['sent_at'],
                'source'        => $row['source'],
                'created_at'    => $row['created_at'],
            ];
        }

    } catch (PDOException $e) {
        error_log('Admin list reminders error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching reminders.');
    }

    json_response([
        'reminders' => $reminders,
    ], 200, [
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}


// =====================================================================
// DELETE /admin/reminders/{id} — Hard delete a reminder
// =====================================================================

function handle_delete_reminder(int $reminder_id): void
{
    try {
        $pdo = get_db();

        $stmt = $pdo->prepare("SELECT id FROM reminders WHERE id = ? LIMIT 1");
        $stmt->execute([$reminder_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Reminder not found.');
        }

        $pdo->prepare("DELETE FROM reminders WHERE id = ?")->execute([$reminder_id]);

    } catch (PDOException $e) {
        error_log('Admin delete reminder error: ' . $e->getMessage());
        json_error(500, 'Database error while deleting reminder.');
    }

    json_response([
        'id'      => $reminder_id,
        'status'  => 'deleted',
        'message' => "Reminder {$reminder_id} permanently deleted.",
    ]);
}


// =====================================================================
// GET /admin/scraper/runs — Recent scraper run history
// =====================================================================

function handle_list_scraper_runs(array $query): void
{
    $limit  = isset($query['limit']) ? min(max((int) $query['limit'], 1), 200) : 50;
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0) : 0;

    try {
        $pdo = get_db();

        // Check if table exists before querying
        $stmt = $pdo->query("SHOW TABLES LIKE 'scraper_state'");
        if ($stmt->rowCount() === 0) {
            json_response([
                'runs' => [],
            ], 200, [
                'total'  => 0,
                'limit'  => $limit,
                'offset' => $offset,
            ]);
        }

        // Count total
        $total = (int) $pdo->query("SELECT COUNT(*) FROM scraper_state")->fetchColumn();

        // Fetch runs
        $stmt = $pdo->prepare("
            SELECT id, source, last_run, meetings_found, meetings_new, meetings_changed, status, error_message
            FROM scraper_state
            ORDER BY last_run DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $rows = $stmt->fetchAll();

        $runs = [];
        foreach ($rows as $row) {
            $runs[] = [
                'id'               => (int) $row['id'],
                'source'           => $row['source'],
                'last_run'         => $row['last_run'],
                'meetings_found'   => (int) $row['meetings_found'],
                'meetings_new'     => (int) $row['meetings_new'],
                'meetings_changed' => (int) $row['meetings_changed'],
                'status'           => $row['status'],
                'error_message'    => $row['error_message'],
            ];
        }

    } catch (PDOException $e) {
        error_log('Admin list scraper runs error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching scraper runs.');
    }

    json_response([
        'runs' => $runs,
    ], 200, [
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}


// =====================================================================
// POST /admin/scraper/trigger — Manually trigger a scrape
// =====================================================================

function handle_trigger_scrape(): void
{
    try {
        $pdo = get_db();

        // Anti-spam: check for a run within the last 60 seconds
        $stmt = $pdo->query("SHOW TABLES LIKE 'scraper_state'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id FROM scraper_state
                WHERE last_run > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                LIMIT 1
            ");
            $stmt->execute();
            if ($stmt->fetch()) {
                json_error(429, 'A scrape was triggered recently. Please wait a minute before trying again.');
            }
        }

    } catch (PDOException $e) {
        error_log('Admin trigger scrape check error: ' . $e->getMessage());
        // Non-fatal — proceed with trigger even if check fails
    }

    // Run the scraper in the background
    $php    = PHP_BINARY;
    $script = realpath(__DIR__ . '/../cron/scrape.php');

    if ($script === false) {
        json_error(500, 'Scraper script not found.');
    }

    exec(sprintf(
        'nohup %s %s >> /var/log/access100-scrape.log 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script)
    ));

    json_response([
        'status'  => 'started',
        'message' => 'Scraper started in the background. Results will appear within a minute.',
    ], 202);
}


// =====================================================================
// POST /admin/scraper/trigger-nco — Manually trigger NCO scrape
// =====================================================================

function handle_trigger_nco_scrape(): void
{
    try {
        $pdo = get_db();

        // Anti-spam: check for an NCO run within the last 60 seconds
        $stmt = $pdo->query("SHOW TABLES LIKE 'scraper_state'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id FROM scraper_state
                WHERE source = 'nco_scraper'
                  AND last_run > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                LIMIT 1
            ");
            $stmt->execute();
            if ($stmt->fetch()) {
                json_error(429, 'An NCO scrape was triggered recently. Please wait a minute before trying again.');
            }
        }

    } catch (PDOException $e) {
        error_log('Admin trigger NCO scrape check error: ' . $e->getMessage());
    }

    // Run the NCO scraper in the background
    $php    = PHP_BINARY;
    $script = realpath(__DIR__ . '/../cron/scrape-nco.php');

    if ($script === false) {
        json_error(500, 'NCO scraper script not found.');
    }

    exec(sprintf(
        'nohup %s %s >> /var/log/access100-scrape-nco.log 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script)
    ));

    json_response([
        'status'  => 'started',
        'message' => 'NCO scraper started in the background. Results will appear within a minute.',
    ], 202);
}


// =====================================================================
// POST /admin/scraper/trigger-honolulu-boards — Manually trigger Honolulu boards scrape
// =====================================================================

function handle_trigger_honolulu_boards_scrape(): void
{
    try {
        $pdo = get_db();

        // Anti-spam: check for a run within the last 60 seconds
        $stmt = $pdo->query("SHOW TABLES LIKE 'scraper_state'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id FROM scraper_state
                WHERE source = 'honolulu_boards_scraper'
                  AND last_run > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                LIMIT 1
            ");
            $stmt->execute();
            if ($stmt->fetch()) {
                json_error(429, 'A Honolulu boards scrape was triggered recently. Please wait a minute before trying again.');
            }
        }

    } catch (PDOException $e) {
        error_log('Admin trigger Honolulu boards scrape check error: ' . $e->getMessage());
    }

    $php    = PHP_BINARY;
    $script = realpath(__DIR__ . '/../cron/scrape-honolulu-boards.php');

    if ($script === false) {
        json_error(500, 'Honolulu boards scraper script not found.');
    }

    exec(sprintf(
        'nohup %s %s >> /var/log/access100-scrape-hnl-boards.log 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script)
    ));

    json_response([
        'status'  => 'started',
        'message' => 'Honolulu boards scraper started in the background. Results will appear within a minute.',
    ], 202);
}


// =====================================================================
// POST /admin/scraper/trigger-maui — Manually trigger Maui Legistar scrape
// =====================================================================

function handle_trigger_maui_scrape(): void
{
    try {
        $pdo = get_db();

        // Anti-spam: check for a run within the last 60 seconds
        $stmt = $pdo->query("SHOW TABLES LIKE 'scraper_state'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id FROM scraper_state
                WHERE source = 'maui_scraper'
                  AND last_run > DATE_SUB(NOW(), INTERVAL 60 SECOND)
                LIMIT 1
            ");
            $stmt->execute();
            if ($stmt->fetch()) {
                json_error(429, 'A Maui scrape was triggered recently. Please wait a minute before trying again.');
            }
        }

    } catch (PDOException $e) {
        error_log('Admin trigger Maui scrape check error: ' . $e->getMessage());
    }

    $php    = PHP_BINARY;
    $script = realpath(__DIR__ . '/../cron/scrape-maui-legistar.php');

    if ($script === false) {
        json_error(500, 'Maui Legistar scraper script not found.');
    }

    exec(sprintf(
        'nohup %s %s >> /var/log/access100-scrape-maui.log 2>&1 &',
        escapeshellarg($php),
        escapeshellarg($script)
    ));

    json_response([
        'status'  => 'started',
        'message' => 'Maui Legistar scraper started in the background. Results will appear within a minute.',
    ], 202);
}


// =====================================================================
// GET /admin/councils — Paginated list of councils
// =====================================================================

function handle_list_councils(array $query): void
{
    $limit  = isset($query['limit']) ? min(max((int) $query['limit'], 1), 100) : 50;
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0) : 0;
    $search = isset($query['q']) ? trim($query['q']) : '';
    $is_active = isset($query['is_active']) ? trim($query['is_active']) : '';
    $jurisdiction = isset($query['jurisdiction']) ? trim($query['jurisdiction']) : '';
    $level = isset($query['level']) ? trim($query['level']) : '';

    try {
        $pdo = get_db();

        // Check if council_profiles table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'council_profiles'");
        $has_profiles = $stmt->rowCount() > 0;

        $where_clauses = [];
        $params = [];
        $joins = $has_profiles ? 'LEFT JOIN council_profiles cp ON c.id = cp.council_id' : '';

        if ($search !== '') {
            $where_clauses[] = 'c.name LIKE ?';
            $params[] = '%' . $search . '%';
        }

        if ($is_active === 'true') {
            $where_clauses[] = 'c.is_active = TRUE';
        } elseif ($is_active === 'false') {
            $where_clauses[] = 'c.is_active = FALSE';
        }

        if ($has_profiles) {
            $valid_jurisdictions = ['state', 'honolulu', 'maui', 'hawaii', 'kauai'];
            if ($jurisdiction !== '' && in_array($jurisdiction, $valid_jurisdictions, true)) {
                $where_clauses[] = 'cp.jurisdiction = ?';
                $params[] = $jurisdiction;
            }

            $valid_levels = ['state', 'county', 'neighborhood'];
            if ($level !== '' && in_array($level, $valid_levels, true)) {
                $where_clauses[] = 'cp.level = ?';
                $params[] = $level;
            }
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count total
        $count_sql = "SELECT COUNT(*) FROM councils c {$joins} {$where_sql}";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch councils
        if ($has_profiles) {
            $select_sql = "
                SELECT c.id, c.name, c.rss_url, c.is_active,
                       cp.slug, cp.jurisdiction, cp.entity_type, cp.level,
                       (SELECT COUNT(*) FROM meetings m WHERE m.council_id = c.id) AS meeting_count
                FROM councils c
                {$joins}
                {$where_sql}
                ORDER BY c.name ASC
                LIMIT ? OFFSET ?
            ";
        } else {
            $select_sql = "
                SELECT c.id, c.name, c.rss_url, c.is_active,
                       (SELECT COUNT(*) FROM meetings m WHERE m.council_id = c.id) AS meeting_count
                FROM councils c
                {$where_sql}
                ORDER BY c.name ASC
                LIMIT ? OFFSET ?
            ";
        }
        $select_params = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($select_sql);
        $stmt->execute($select_params);
        $rows = $stmt->fetchAll();

        $councils = [];
        foreach ($rows as $row) {
            $councils[] = [
                'id'            => (int) $row['id'],
                'name'          => $row['name'],
                'rss_url'       => $row['rss_url'],
                'is_active'     => (bool) $row['is_active'],
                'slug'          => $has_profiles ? $row['slug'] : null,
                'jurisdiction'  => $has_profiles ? $row['jurisdiction'] : null,
                'entity_type'   => $has_profiles ? $row['entity_type'] : null,
                'level'         => $has_profiles ? $row['level'] : null,
                'meeting_count' => (int) $row['meeting_count'],
            ];
        }

    } catch (PDOException $e) {
        error_log('Admin list councils error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching councils.');
    }

    json_response([
        'councils' => $councils,
    ], 200, [
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}


// =====================================================================
// GET /admin/councils/{id} — Full council detail
// =====================================================================

function handle_get_council(int $id): void
{
    if ($id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    try {
        $pdo = get_db();

        // Council base row
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, c.rss_url, c.is_active, c.parent_id,
                   p.name AS parent_name
            FROM councils c
            LEFT JOIN councils p ON c.parent_id = p.id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $council = $stmt->fetch();

        if (!$council) {
            json_error(404, 'Council not found.');
        }

        // Profile
        $profile = [];
        $stmt = $pdo->query("SHOW TABLES LIKE 'council_profiles'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("SELECT * FROM council_profiles WHERE council_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $profile = $stmt->fetch() ?: [];
        }

        // Members
        $members = [];
        $stmt = $pdo->query("SHOW TABLES LIKE 'council_members'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id, name, title, role, appointed_by, term_start, term_end, status, display_order
                FROM council_members
                WHERE council_id = ?
                ORDER BY display_order ASC, name ASC
            ");
            $stmt->execute([$id]);
            $members = $stmt->fetchAll();
        }

        // Vacancies
        $vacancies = [];
        $stmt = $pdo->query("SHOW TABLES LIKE 'council_vacancies'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id, seat_description, requirements, application_url, application_deadline, appointing_authority, status
                FROM council_vacancies
                WHERE council_id = ?
                ORDER BY FIELD(status, 'open', 'closed', 'filled') ASC, application_deadline ASC
            ");
            $stmt->execute([$id]);
            $vacancies = $stmt->fetchAll();
        }

        // Legal authority
        $authority = [];
        $stmt = $pdo->query("SHOW TABLES LIKE 'council_legal_authority'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT id, citation, description, url, display_order
                FROM council_legal_authority
                WHERE council_id = ?
                ORDER BY display_order ASC
            ");
            $stmt->execute([$id]);
            $authority = $stmt->fetchAll();
        }

        // Topics via topic_council_map
        $topics = [];
        $stmt = $pdo->query("SHOW TABLES LIKE 'topic_council_map'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT t.id, t.name, t.slug
                FROM topic_council_map tcm
                JOIN topics t ON tcm.topic_id = t.id
                WHERE tcm.council_id = ?
                ORDER BY t.name ASC
            ");
            $stmt->execute([$id]);
            $topics = $stmt->fetchAll();
        }

    } catch (PDOException $e) {
        error_log('Admin get council error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching council.');
    }

    // Shape response
    $data = [
        'id'          => (int) $council['id'],
        'name'        => $council['name'],
        'rss_url'     => $council['rss_url'],
        'is_active'   => (bool) $council['is_active'],
        'parent_id'   => $council['parent_id'] !== null ? (int) $council['parent_id'] : null,
        'parent_name' => $council['parent_name'],
        'profile'     => !empty($profile) ? [
            'slug'                   => $profile['slug'] ?? null,
            'plain_description'      => $profile['plain_description'] ?? null,
            'decisions_examples'     => $profile['decisions_examples'] ?? null,
            'why_care'               => $profile['why_care'] ?? null,
            'entity_type'            => $profile['entity_type'] ?? null,
            'jurisdiction'           => $profile['jurisdiction'] ?? null,
            'meeting_schedule'       => $profile['meeting_schedule'] ?? null,
            'default_location'       => $profile['default_location'] ?? null,
            'virtual_option'         => isset($profile['virtual_option']) ? (bool) $profile['virtual_option'] : false,
            'testimony_email'        => $profile['testimony_email'] ?? null,
            'testimony_instructions' => $profile['testimony_instructions'] ?? null,
            'public_comment_info'    => $profile['public_comment_info'] ?? null,
            'contact_email'          => $profile['contact_email'] ?? null,
            'contact_phone'          => $profile['contact_phone'] ?? null,
            'official_website'       => $profile['official_website'] ?? null,
            'appointment_method'     => $profile['appointment_method'] ?? null,
            'term_length'            => $profile['term_length'] ?? null,
            'member_count'           => isset($profile['member_count']) ? (int) $profile['member_count'] : null,
            'vacancy_count'          => isset($profile['vacancy_count']) ? (int) $profile['vacancy_count'] : null,
            'vacancy_info'           => $profile['vacancy_info'] ?? null,
        ] : null,
        'members' => array_map(function (array $row): array {
            return [
                'id'           => (int) $row['id'],
                'name'         => $row['name'],
                'title'        => $row['title'],
                'role'         => $row['role'],
                'appointed_by' => $row['appointed_by'],
                'term_start'   => $row['term_start'],
                'term_end'     => $row['term_end'],
                'status'       => $row['status'],
            ];
        }, $members),
        'vacancies' => array_map(function (array $row): array {
            return [
                'id'                    => (int) $row['id'],
                'seat_description'      => $row['seat_description'],
                'requirements'          => $row['requirements'],
                'application_url'       => $row['application_url'],
                'application_deadline'  => $row['application_deadline'],
                'appointing_authority'  => $row['appointing_authority'],
                'status'                => $row['status'],
            ];
        }, $vacancies),
        'authority' => array_map(function (array $row): array {
            return [
                'id'          => (int) $row['id'],
                'citation'    => $row['citation'],
                'description' => $row['description'],
                'url'         => $row['url'],
            ];
        }, $authority),
        'topics' => array_map(function (array $row): array {
            return [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
            ];
        }, $topics),
    ];

    json_response($data);
}


// =====================================================================
// PATCH /admin/councils/{id} — Update council + profile
// =====================================================================

function handle_update_council(int $id): void
{
    if ($id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    try {
        $pdo = get_db();

        // Verify council exists
        $stmt = $pdo->prepare("SELECT id FROM councils WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Council not found.');
        }

        $pdo->beginTransaction();

        // Update council fields
        $council_fields = [];
        $council_params = [];

        if (isset($body['name'])) {
            $council_fields[] = 'name = ?';
            $council_params[] = trim($body['name']);
        }
        if (isset($body['rss_url'])) {
            $council_fields[] = 'rss_url = ?';
            $council_params[] = trim($body['rss_url']);
        }
        if (isset($body['is_active'])) {
            $council_fields[] = 'is_active = ?';
            $council_params[] = (bool) $body['is_active'] ? 1 : 0;
        }
        if (array_key_exists('parent_id', $body)) {
            $council_fields[] = 'parent_id = ?';
            $council_params[] = $body['parent_id'] !== null ? (int) $body['parent_id'] : null;
        }

        if (!empty($council_fields)) {
            $council_params[] = $id;
            $pdo->prepare("UPDATE councils SET " . implode(', ', $council_fields) . " WHERE id = ?")
                ->execute($council_params);
        }

        // Update profile fields (upsert) — only if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'council_profiles'");
        if ($stmt->rowCount() > 0) {
            $profile_columns = [
                'slug', 'plain_description', 'decisions_examples', 'why_care',
                'entity_type', 'jurisdiction', 'meeting_schedule', 'default_location',
                'virtual_option', 'testimony_email', 'testimony_instructions',
                'public_comment_info', 'contact_email', 'contact_phone',
                'official_website', 'appointment_method', 'term_length',
                'member_count', 'vacancy_count', 'vacancy_info',
            ];

            $profile_data = [];
            foreach ($profile_columns as $col) {
                if (array_key_exists($col, $body)) {
                    if ($col === 'virtual_option') {
                        $profile_data[$col] = (bool) $body[$col] ? 1 : 0;
                    } elseif (in_array($col, ['member_count', 'vacancy_count'], true)) {
                        $profile_data[$col] = $body[$col] !== null && $body[$col] !== '' ? (int) $body[$col] : null;
                    } else {
                        $profile_data[$col] = $body[$col] !== null ? trim((string) $body[$col]) : null;
                    }
                }
            }

            if (!empty($profile_data)) {
                $cols = array_keys($profile_data);
                $placeholders = array_fill(0, count($cols), '?');
                $update_parts = array_map(fn($c) => "{$c} = VALUES({$c})", $cols);

                $sql = "INSERT INTO council_profiles (council_id, " . implode(', ', $cols) . ", last_updated)"
                     . " VALUES (?, " . implode(', ', $placeholders) . ", CURDATE())"
                     . " ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts) . ", last_updated = CURDATE()";

                $params = array_merge([$id], array_values($profile_data));
                $pdo->prepare($sql)->execute($params);
            }
        }

        // Update topics if provided — only if table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'topic_council_map'");
        if ($stmt->rowCount() > 0 && isset($body['topics']) && is_array($body['topics'])) {
            // Delete existing assignments
            $pdo->prepare("DELETE FROM topic_council_map WHERE council_id = ?")->execute([$id]);

            // Insert new assignments
            $topic_ids = array_filter(array_map('intval', $body['topics']), fn($tid) => $tid > 0);
            if (!empty($topic_ids)) {
                $insert_stmt = $pdo->prepare("INSERT INTO topic_council_map (council_id, topic_id) VALUES (?, ?)");
                foreach ($topic_ids as $tid) {
                    $insert_stmt->execute([$id, $tid]);
                }
            }
        }

        $pdo->commit();

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin update council error: ' . $e->getMessage());
        json_error(500, 'Database error while updating council.');
    }

    json_response([
        'id'      => $id,
        'status'  => 'updated',
        'message' => 'Council updated successfully.',
    ]);
}


// =====================================================================
// POST /admin/councils/{id}/members — Add a member
// =====================================================================

function handle_add_member(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    $name = isset($body['name']) ? trim($body['name']) : '';
    if ($name === '') {
        json_error(400, 'Member name is required.');
    }

    try {
        $pdo = get_db();

        // Verify council exists
        $stmt = $pdo->prepare("SELECT id FROM councils WHERE id = ? LIMIT 1");
        $stmt->execute([$council_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Council not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO council_members (council_id, name, title, role, appointed_by, term_start, term_end, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $council_id,
            $name,
            isset($body['title']) ? trim($body['title']) : null,
            isset($body['role']) ? trim($body['role']) : 'member',
            isset($body['appointed_by']) ? trim($body['appointed_by']) : null,
            isset($body['term_start']) ? trim($body['term_start']) : null,
            isset($body['term_end']) ? trim($body['term_end']) : null,
            isset($body['status']) ? trim($body['status']) : 'active',
        ]);

        $member_id = (int) $pdo->lastInsertId();

    } catch (PDOException $e) {
        error_log('Admin add member error: ' . $e->getMessage());
        json_error(500, 'Database error while adding member.');
    }

    json_response([
        'id'         => $member_id,
        'council_id' => $council_id,
        'status'     => 'created',
        'message'    => 'Member added successfully.',
    ], 201);
}


// =====================================================================
// DELETE /admin/councils/{id}/members/{member_id} — Delete a member
// =====================================================================

function handle_delete_member(int $council_id, int $member_id): void
{
    if ($council_id <= 0 || $member_id <= 0) {
        json_error(400, 'Invalid council or member ID.');
    }

    try {
        $pdo = get_db();

        // Verify member belongs to council
        $stmt = $pdo->prepare("SELECT id FROM council_members WHERE id = ? AND council_id = ? LIMIT 1");
        $stmt->execute([$member_id, $council_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Member not found for this council.');
        }

        $pdo->prepare("DELETE FROM council_members WHERE id = ?")->execute([$member_id]);

    } catch (PDOException $e) {
        error_log('Admin delete member error: ' . $e->getMessage());
        json_error(500, 'Database error while deleting member.');
    }

    json_response([
        'id'         => $member_id,
        'council_id' => $council_id,
        'status'     => 'deleted',
        'message'    => 'Member deleted successfully.',
    ]);
}


// =====================================================================
// POST /admin/councils/{id}/vacancies — Add a vacancy
// =====================================================================

function handle_add_vacancy(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    try {
        $pdo = get_db();

        // Verify council exists
        $stmt = $pdo->prepare("SELECT id FROM councils WHERE id = ? LIMIT 1");
        $stmt->execute([$council_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Council not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO council_vacancies (council_id, seat_description, requirements, application_url, application_deadline, appointing_authority, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $council_id,
            isset($body['seat_description']) ? trim($body['seat_description']) : null,
            isset($body['requirements']) ? trim($body['requirements']) : null,
            isset($body['application_url']) ? trim($body['application_url']) : null,
            isset($body['application_deadline']) ? trim($body['application_deadline']) : null,
            isset($body['appointing_authority']) ? trim($body['appointing_authority']) : null,
            isset($body['status']) ? trim($body['status']) : 'open',
        ]);

        $vacancy_id = (int) $pdo->lastInsertId();

    } catch (PDOException $e) {
        error_log('Admin add vacancy error: ' . $e->getMessage());
        json_error(500, 'Database error while adding vacancy.');
    }

    json_response([
        'id'         => $vacancy_id,
        'council_id' => $council_id,
        'status'     => 'created',
        'message'    => 'Vacancy added successfully.',
    ], 201);
}


// =====================================================================
// DELETE /admin/councils/{id}/vacancies/{vacancy_id} — Delete a vacancy
// =====================================================================

function handle_delete_vacancy(int $council_id, int $vacancy_id): void
{
    if ($council_id <= 0 || $vacancy_id <= 0) {
        json_error(400, 'Invalid council or vacancy ID.');
    }

    try {
        $pdo = get_db();

        // Verify vacancy belongs to council
        $stmt = $pdo->prepare("SELECT id FROM council_vacancies WHERE id = ? AND council_id = ? LIMIT 1");
        $stmt->execute([$vacancy_id, $council_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Vacancy not found for this council.');
        }

        $pdo->prepare("DELETE FROM council_vacancies WHERE id = ?")->execute([$vacancy_id]);

    } catch (PDOException $e) {
        error_log('Admin delete vacancy error: ' . $e->getMessage());
        json_error(500, 'Database error while deleting vacancy.');
    }

    json_response([
        'id'         => $vacancy_id,
        'council_id' => $council_id,
        'status'     => 'deleted',
        'message'    => 'Vacancy deleted successfully.',
    ]);
}


// =====================================================================
// POST /admin/councils/{id}/authority — Add a legal authority
// =====================================================================

function handle_add_authority(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    $citation = isset($body['citation']) ? trim($body['citation']) : '';
    if ($citation === '') {
        json_error(400, 'Citation is required.');
    }

    try {
        $pdo = get_db();

        // Verify council exists
        $stmt = $pdo->prepare("SELECT id FROM councils WHERE id = ? LIMIT 1");
        $stmt->execute([$council_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Council not found.');
        }

        // Get the next display_order
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM council_legal_authority WHERE council_id = ?");
        $stmt->execute([$council_id]);
        $next_order = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            INSERT INTO council_legal_authority (council_id, citation, description, url, display_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $council_id,
            $citation,
            isset($body['description']) ? trim($body['description']) : null,
            isset($body['url']) ? trim($body['url']) : null,
            $next_order,
        ]);

        $authority_id = (int) $pdo->lastInsertId();

    } catch (PDOException $e) {
        error_log('Admin add authority error: ' . $e->getMessage());
        json_error(500, 'Database error while adding authority.');
    }

    json_response([
        'id'         => $authority_id,
        'council_id' => $council_id,
        'status'     => 'created',
        'message'    => 'Legal authority added successfully.',
    ], 201);
}


// =====================================================================
// DELETE /admin/councils/{id}/authority/{authority_id} — Delete authority
// =====================================================================

function handle_delete_authority(int $council_id, int $authority_id): void
{
    if ($council_id <= 0 || $authority_id <= 0) {
        json_error(400, 'Invalid council or authority ID.');
    }

    try {
        $pdo = get_db();

        // Verify authority belongs to council
        $stmt = $pdo->prepare("SELECT id FROM council_legal_authority WHERE id = ? AND council_id = ? LIMIT 1");
        $stmt->execute([$authority_id, $council_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Authority record not found for this council.');
        }

        $pdo->prepare("DELETE FROM council_legal_authority WHERE id = ?")->execute([$authority_id]);

    } catch (PDOException $e) {
        error_log('Admin delete authority error: ' . $e->getMessage());
        json_error(500, 'Database error while deleting authority.');
    }

    json_response([
        'id'         => $authority_id,
        'council_id' => $council_id,
        'status'     => 'deleted',
        'message'    => 'Legal authority deleted successfully.',
    ]);
}


// =====================================================================
// GET /admin/meetings — Paginated list of meetings
// =====================================================================

function handle_list_admin_meetings(array $query): void
{
    $limit      = isset($query['limit']) ? min(max((int) $query['limit'], 1), 100) : 25;
    $offset     = isset($query['offset']) ? max((int) $query['offset'], 0) : 0;
    $search     = isset($query['q']) ? trim($query['q']) : '';
    $council_id = isset($query['council_id']) ? (int) $query['council_id'] : 0;
    $date_from  = isset($query['date_from']) ? trim($query['date_from']) : '';
    $date_to    = isset($query['date_to']) ? trim($query['date_to']) : '';
    $status     = isset($query['status']) ? trim($query['status']) : '';
    $order      = isset($query['order']) && strtolower($query['order']) === 'desc' ? 'DESC' : 'ASC';

    try {
        $pdo = get_db();

        // Build WHERE clause
        $where_clauses = [];
        $params = [];

        if ($search !== '') {
            $where_clauses[] = 'm.title LIKE ?';
            $params[] = '%' . $search . '%';
        }

        if ($council_id > 0) {
            $where_clauses[] = 'm.council_id = ?';
            $params[] = $council_id;
        }

        if ($date_from !== '') {
            $where_clauses[] = 'm.meeting_date >= ?';
            $params[] = $date_from;
        }

        if ($date_to !== '') {
            $where_clauses[] = 'm.meeting_date <= ?';
            $params[] = $date_to;
        }

        if ($status !== '') {
            $where_clauses[] = 'm.status = ?';
            $params[] = $status;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count total
        $count_sql = "SELECT COUNT(*) FROM meetings m {$where_sql}";
        $stmt = $pdo->prepare($count_sql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch meetings joined to councils
        $select_sql = "SELECT m.id, m.state_id, m.title, m.meeting_date, m.meeting_time,
                              m.location, m.status, m.first_seen_at, m.last_updated_at,
                              c.name AS council_name
                       FROM meetings m
                       JOIN councils c ON m.council_id = c.id
                       {$where_sql}
                       ORDER BY m.meeting_date {$order}, m.meeting_time {$order}
                       LIMIT ? OFFSET ?";
        $select_params = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($select_sql);
        $stmt->execute($select_params);
        $rows = $stmt->fetchAll();

        $meetings = [];
        foreach ($rows as $row) {
            $meetings[] = [
                'id'              => (int) $row['id'],
                'state_id'        => $row['state_id'],
                'title'           => $row['title'],
                'meeting_date'    => $row['meeting_date'],
                'meeting_time'    => $row['meeting_time'],
                'location'        => $row['location'],
                'status'          => $row['status'],
                'first_seen_at'   => $row['first_seen_at'],
                'last_updated_at' => $row['last_updated_at'],
                'council_name'    => $row['council_name'],
            ];
        }

    } catch (PDOException $e) {
        error_log('Admin list meetings error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meetings.');
    }

    json_response([
        'meetings' => $meetings,
    ], 200, [
        'total'  => $total,
        'limit'  => $limit,
        'offset' => $offset,
    ]);
}


// =====================================================================
// GET /admin/meetings/check-links — Check all meeting detail_url links
// =====================================================================

function handle_check_meeting_links(array $query): void
{
    $date_from  = isset($query['date_from']) ? trim($query['date_from']) : '';
    $date_to    = isset($query['date_to']) ? trim($query['date_to']) : '';
    $council_id = isset($query['council_id']) ? (int) $query['council_id'] : 0;

    try {
        $pdo = get_db();

        // Build WHERE clause — only meetings with a detail_url
        $where_clauses = ["m.detail_url IS NOT NULL AND m.detail_url != ''"];
        $params = [];

        if ($date_from !== '') {
            $where_clauses[] = 'm.meeting_date >= ?';
            $params[] = $date_from;
        }
        if ($date_to !== '') {
            $where_clauses[] = 'm.meeting_date <= ?';
            $params[] = $date_to;
        }
        if ($council_id > 0) {
            $where_clauses[] = 'm.council_id = ?';
            $params[] = $council_id;
        }

        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);

        $sql = "SELECT m.id, m.state_id, m.detail_url, m.title, m.meeting_date,
                       m.meeting_time, m.council_id, c.name AS council_name
                FROM meetings m
                JOIN councils c ON m.council_id = c.id
                {$where_sql}
                ORDER BY m.meeting_date DESC, m.meeting_time DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $meetings = $stmt->fetchAll();

    } catch (PDOException $e) {
        error_log('Admin check meeting links error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meetings.');
    }

    if (empty($meetings)) {
        json_response([
            'broken'  => [],
            'checked' => 0,
        ]);
    }

    // Check URLs in batches to avoid overwhelming the target server
    $batch_size = 20;
    $broken = [];

    foreach (array_chunk($meetings, $batch_size, true) as $batch) {
        $multi   = curl_multi_init();
        $handles = [];

        foreach ($batch as $i => $meeting) {
            $ch = curl_init($meeting['detail_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'Access100-LinkChecker/1.0 (+https://civi.me)',
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$i] = $ch;
        }

        // Execute all requests in this batch
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($running > 0) {
            if (curl_multi_select($multi, 1) === -1) {
                usleep(100000);
            }
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
        }

        // Collect results for this batch
        foreach ($handles as $i => $ch) {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error     = curl_error($ch);

            // Treat anything outside 200-399 as broken
            if ($http_code < 200 || $http_code >= 400 || !empty($error)) {
                $meeting = $meetings[$i];
                $broken[] = [
                    'id'           => (int) $meeting['id'],
                    'state_id'     => $meeting['state_id'],
                    'detail_url'   => $meeting['detail_url'],
                    'title'        => $meeting['title'],
                    'meeting_date' => $meeting['meeting_date'],
                    'meeting_time' => $meeting['meeting_time'],
                    'council_id'   => (int) $meeting['council_id'],
                    'council_name' => $meeting['council_name'],
                    'http_status'  => $http_code,
                    'error'        => $error ?: null,
                ];
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);
    }

    json_response([
        'broken'  => $broken,
        'checked' => count($meetings),
    ]);
}


// =====================================================================
// PATCH /admin/meetings/{id} — Update meeting fields
// =====================================================================

function handle_update_meeting(int $meeting_id): void
{
    $body = get_json_body();
    if ($body === null) {
        json_error(400, 'Request body must be valid JSON.');
    }

    // Allowed fields
    $allowed = ['state_id', 'detail_url', 'status', 'title'];
    $updates = [];
    foreach ($allowed as $field) {
        if (isset($body[$field])) {
            $updates[$field] = trim($body[$field]);
        }
    }

    if (empty($updates)) {
        json_error(400, 'No valid fields to update. Allowed: ' . implode(', ', $allowed));
    }

    // Validate state_id format if provided (alphanumeric, hyphens, underscores)
    if (isset($updates['state_id']) && !preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $updates['state_id'])) {
        json_error(400, 'state_id must be alphanumeric (hyphens/underscores allowed, max 50 chars).');
    }

    // Validate detail_url is a valid URL from an allowed host if provided
    if (isset($updates['detail_url'])) {
        if (!filter_var($updates['detail_url'], FILTER_VALIDATE_URL)) {
            json_error(400, 'detail_url must be a valid URL.');
        }
        $host = parse_url($updates['detail_url'], PHP_URL_HOST);
        $allowed_hosts = ['calendar.ehawaii.gov', 'www.honolulu.gov', 'mauicounty.legistar.com', 'mauicounty.legistar1.com'];
        if (!in_array($host, $allowed_hosts, true)) {
            json_error(400, 'detail_url must be from an allowed host (' . implode(', ', $allowed_hosts) . ').');
        }
    }

    // Auto-construct detail_url from state_id if only state_id provided (eHawaii numeric IDs only)
    if (isset($updates['state_id']) && !isset($updates['detail_url']) && ctype_digit((string) $updates['state_id'])) {
        $updates['detail_url'] = 'https://calendar.ehawaii.gov/calendar/meeting/' . $updates['state_id'] . '/details.html';
    }

    // Also update external_id to keep in sync with state_id
    if (isset($updates['state_id'])) {
        $updates['external_id'] = $updates['detail_url'];
    }

    // Validate status if provided
    if (isset($updates['status'])) {
        $valid_statuses = ['scheduled', 'cancelled', 'completed'];
        if (!in_array($updates['status'], $valid_statuses, true)) {
            json_error(400, 'Invalid status. Must be: ' . implode(', ', $valid_statuses));
        }
    }

    try {
        $pdo = get_db();

        // Verify meeting exists
        $stmt = $pdo->prepare("SELECT id FROM meetings WHERE id = ? LIMIT 1");
        $stmt->execute([$meeting_id]);
        if (!$stmt->fetch()) {
            json_error(404, 'Meeting not found.');
        }

        // Build SET clause
        $set_parts = [];
        $params    = [];
        foreach ($updates as $field => $value) {
            $set_parts[] = "{$field} = ?";
            $params[]    = $value;
        }
        $set_parts[] = "last_updated_at = NOW()";
        $params[]    = $meeting_id;

        $sql = "UPDATE meetings SET " . implode(', ', $set_parts) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

    } catch (PDOException $e) {
        error_log('Admin update meeting error: ' . $e->getMessage());
        json_error(500, 'Database error while updating meeting.');
    }

    json_response([
        'id'      => $meeting_id,
        'status'  => 'updated',
        'updated' => $updates,
        'message' => 'Meeting updated successfully.',
    ]);
}
