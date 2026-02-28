<?php
/**
 * Access100 API - Meetings Endpoint
 *
 * Handles all /api/v1/meetings routes:
 *   GET  /meetings                    — list with filters and pagination
 *   GET  /meetings/{state_id}         — full meeting detail with attachments
 *   GET  /meetings/{state_id}/summary — AI summary only
 *   GET  /meetings/{state_id}/ics     — iCalendar file download
 *
 * Requires: $route array from index.php, config.php loaded
 */

// Only GET is supported for meetings
if ($route['method'] !== 'GET') {
    json_error(405, 'Method not allowed. Meetings endpoint only supports GET.');
}

// ─── Route Dispatch ───────────────────────────────────────────────────────────

$state_id    = $route['resource_id'];
$sub_resource = $route['sub_resource'];

if ($state_id === null) {
    // GET /meetings — list
    handle_meetings_list($route['query']);
} elseif ($sub_resource === 'summary') {
    // GET /meetings/{state_id}/summary
    handle_meeting_summary($state_id);
} elseif ($sub_resource === 'ics') {
    // GET /meetings/{state_id}/ics
    handle_meeting_ics($state_id);
} elseif ($sub_resource === null) {
    // GET /meetings/{state_id}
    handle_meeting_detail($state_id);
} else {
    $safe_sub = preg_replace('/[^a-zA-Z0-9_-]/', '', $sub_resource);
    json_error(404, 'Unknown sub-resource: ' . $safe_sub);
}


// =====================================================================
// GET /meetings — List with filters and pagination
// =====================================================================

function handle_meetings_list(array $query): void
{
    // --- Validate and sanitize query parameters ---

    $date_from  = isset($query['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['date_from'])
                    ? $query['date_from']
                    : null; // null means default to CURDATE() in SQL

    $date_to    = isset($query['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['date_to'])
                    ? $query['date_to']
                    : null;

    $council_id = isset($query['council_id']) ? (int) $query['council_id'] : null;

    // Trim and cap keyword search to prevent abuse
    $keyword    = isset($query['q']) ? substr(trim($query['q']), 0, 200) : null;

    // Topic filter: comma-separated topic slugs (e.g., ?topics=environment,education)
    $topic_slugs = [];
    if (!empty($query['topics'])) {
        $raw_topics = explode(',', substr(trim($query['topics']), 0, 500));
        foreach ($raw_topics as $ts) {
            $ts = trim($ts);
            if (preg_match('/^[a-z0-9-]{1,50}$/', $ts)) {
                $topic_slugs[] = $ts;
            }
        }
    }

    // county is reserved for future use — accepted but ignored
    // $county = $query['county'] ?? null;

    $limit  = isset($query['limit'])  ? min((int) $query['limit'],  200) : 50;
    $limit  = max($limit, 1);
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0)   : 0;

    // --- Build WHERE clauses ---

    $where_clauses = [];
    $params        = [];

    // Default: meetings on or after date_from (or today)
    if ($date_from !== null) {
        $where_clauses[] = 'm.meeting_date >= ?';
        $params[]        = $date_from;
    } else {
        $where_clauses[] = 'm.meeting_date >= CURDATE()';
    }

    if ($date_to !== null) {
        $where_clauses[] = 'm.meeting_date <= ?';
        $params[]        = $date_to;
    }

    if ($council_id !== null && $council_id > 0) {
        $where_clauses[] = 'm.council_id = ?';
        $params[]        = $council_id;
    }

    if (!empty($keyword)) {
        $where_clauses[] = '(m.title LIKE ? OR m.description LIKE ? OR c.name LIKE ?)';
        $search_term     = '%' . $keyword . '%';
        $params[]        = $search_term;
        $params[]        = $search_term;
        $params[]        = $search_term;
    }

    // Topic filter: include meetings from councils mapped to these topics
    // OR meetings directly tagged with these topics via meeting_topics
    if (!empty($topic_slugs)) {
        $slug_placeholders = implode(',', array_fill(0, count($topic_slugs), '?'));
        $where_clauses[] = "(
            m.council_id IN (
                SELECT tcm.council_id FROM topic_council_map tcm
                JOIN topics t ON tcm.topic_id = t.id
                WHERE t.slug IN ({$slug_placeholders})
            )
            OR m.id IN (
                SELECT mt.meeting_id FROM meeting_topics mt
                JOIN topics t ON mt.topic_id = t.id
                WHERE t.slug IN ({$slug_placeholders})
            )
        )";
        // Parameters appear twice — once for each subquery
        foreach ($topic_slugs as $ts) {
            $params[] = $ts;
        }
        foreach ($topic_slugs as $ts) {
            $params[] = $ts;
        }
    }

    $where_sql = implode(' AND ', $where_clauses);

    // --- Run COUNT query for pagination metadata ---

    $count_sql = "
        SELECT COUNT(*) as total
        FROM meetings m
        JOIN councils c ON m.council_id = c.id
        LEFT JOIN councils p ON c.parent_id = p.id
        WHERE {$where_sql}
    ";

    try {
        $pdo        = get_db();
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Meetings list count error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meetings.');
    }

    // --- Run main SELECT query ---

    $select_sql = "
        SELECT
            m.state_id,
            m.title,
            m.meeting_date,
            m.meeting_time,
            m.location,
            m.status,
            m.detail_url,
            c.id   AS council_id,
            c.name AS council_name,
            p.name AS parent_council_name
        FROM meetings m
        JOIN councils c ON m.council_id = c.id
        LEFT JOIN councils p ON c.parent_id = p.id
        WHERE {$where_sql}
        ORDER BY m.meeting_date ASC, m.meeting_time ASC
        LIMIT ? OFFSET ?
    ";

    // LIMIT and OFFSET are integers — appended after the WHERE params
    $select_params   = $params;
    $select_params[] = $limit;
    $select_params[] = $offset;

    try {
        $stmt = $pdo->prepare($select_sql);
        $stmt->execute($select_params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Meetings list select error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meetings.');
    }

    // --- Shape each row into the API response structure ---

    $meetings = array_map('shape_meeting_list_row', $rows);

    set_cache_headers(300);
    json_response($meetings, 200, [
        'total'    => $total,
        'limit'    => $limit,
        'offset'   => $offset,
        'has_more' => ($offset + $limit) < $total,
    ]);
}


// =====================================================================
// GET /meetings/{state_id} — Full meeting detail
// =====================================================================

function handle_meeting_detail(string $state_id): void
{
    if ($state_id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $state_id)) {
        json_error(400, 'Invalid meeting ID.');
    }

    try {
        $pdo = get_db();

        // Fetch the meeting row (use internal id for attachment join)
        $stmt = $pdo->prepare("
            SELECT
                m.id,
                m.state_id,
                m.title,
                m.meeting_date,
                m.meeting_time,
                m.location,
                m.detail_url,
                m.zoom_link,
                m.status,
                m.description,
                m.full_agenda_text,
                m.summary_text,
                c.id   AS council_id,
                c.name AS council_name,
                p.name AS parent_council_name
            FROM meetings m
            JOIN councils c ON m.council_id = c.id
            LEFT JOIN councils p ON c.parent_id = p.id
            WHERE m.state_id = ?
            LIMIT 1
        ");
        $stmt->execute([$state_id]);
        $meeting = $stmt->fetch();

    } catch (PDOException $e) {
        error_log('Meeting detail error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meeting.');
    }

    if (!$meeting) {
        json_error(404, 'Meeting not found.');
    }

    // Fetch attachments using the internal meeting id
    try {
        $att_stmt = $pdo->prepare("
            SELECT file_name, file_url, file_type
            FROM attachments
            WHERE meeting_id = ?
            ORDER BY id ASC
        ");
        $att_stmt->execute([$meeting['id']]);
        $attachments = $att_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Meeting attachments error: ' . $e->getMessage());
        // Non-fatal: return empty attachments rather than a 500
        $attachments = [];
    }

    // Prefer full_agenda_text when available, fall back to description
    $description_text = !empty($meeting['full_agenda_text'])
        ? $meeting['full_agenda_text']
        : $meeting['description'];

    // Fetch direct topic tags (from meeting_topics)
    $direct_topics = [];
    try {
        $dt_stmt = $pdo->prepare("
            SELECT t.slug, t.name, t.icon, mt.confidence
            FROM meeting_topics mt
            JOIN topics t ON mt.topic_id = t.id
            WHERE mt.meeting_id = ?
            ORDER BY mt.confidence DESC
        ");
        $dt_stmt->execute([$meeting['id']]);
        $direct_topics = array_map(function (array $row): array {
            return [
                'slug'       => $row['slug'],
                'name'       => $row['name'],
                'icon'       => $row['icon'],
                'source'     => 'direct',
                'confidence' => (float) $row['confidence'],
            ];
        }, $dt_stmt->fetchAll());
    } catch (PDOException $e) {
        error_log('Meeting topics (direct) error: ' . $e->getMessage());
    }

    // Fetch inherited topic tags (from council's topic_council_map)
    $inherited_topics = [];
    try {
        $it_stmt = $pdo->prepare("
            SELECT t.slug, t.name, t.icon, tcm.relevance
            FROM topic_council_map tcm
            JOIN topics t ON tcm.topic_id = t.id
            WHERE tcm.council_id = ?
            ORDER BY tcm.relevance ASC, t.display_order ASC
        ");
        $it_stmt->execute([$meeting['council_id']]);
        $inherited_topics = array_map(function (array $row): array {
            return [
                'slug'      => $row['slug'],
                'name'      => $row['name'],
                'icon'      => $row['icon'],
                'relevance' => $row['relevance'],
            ];
        }, $it_stmt->fetchAll());
    } catch (PDOException $e) {
        error_log('Meeting topics (inherited) error: ' . $e->getMessage());
    }

    $data = [
        'state_id'     => $meeting['state_id'],
        'title'        => $meeting['title'],
        'meeting_date' => $meeting['meeting_date'],
        'meeting_time' => $meeting['meeting_time'],
        'location'     => strip_tags($meeting['location'] ?? ''),
        'detail_url'   => $meeting['detail_url'],
        'zoom_link'    => $meeting['zoom_link'],
        'status'       => $meeting['status'],
        'description'  => strip_tags($description_text ?? ''),
        'summary_text' => $meeting['summary_text'],
        'council'      => [
            'id'          => (int) $meeting['council_id'],
            'name'        => $meeting['council_name'],
            'parent_name' => $meeting['parent_council_name'],
        ],
        'attachments'  => $attachments,
        'topics'       => [
            'direct'    => $direct_topics,
            'inherited' => $inherited_topics,
        ],
    ];

    set_cache_headers(300);
    json_response($data);
}


// =====================================================================
// GET /meetings/{state_id}/summary — AI summary only
// =====================================================================

function handle_meeting_summary(string $state_id): void
{
    if ($state_id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $state_id)) {
        json_error(400, 'Invalid meeting ID.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT m.state_id, m.title, m.summary_text, c.name AS council_name
            FROM meetings m
            JOIN councils c ON m.council_id = c.id
            WHERE m.state_id = ?
            LIMIT 1
        ");
        $stmt->execute([$state_id]);
        $meeting = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Meeting summary error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching summary.');
    }

    if (!$meeting) {
        json_error(404, 'Meeting not found.');
    }

    if (empty($meeting['summary_text'])) {
        json_error(404, 'No AI summary available for this meeting.');
    }

    set_cache_headers(300);
    json_response([
        'state_id'     => $meeting['state_id'],
        'council_name' => $meeting['council_name'],
        'title'        => $meeting['title'],
        'summary_text' => $meeting['summary_text'],
    ]);
}


// =====================================================================
// GET /meetings/{state_id}/ics — iCalendar download
// =====================================================================

function handle_meeting_ics(string $state_id): void
{
    if ($state_id === '' || !preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $state_id)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid meeting ID.';
        exit;
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT m.state_id, m.title, m.meeting_date, m.meeting_time,
                   m.location, m.description, m.full_agenda_text, m.detail_url,
                   c.name AS council_name
            FROM meetings m
            JOIN councils c ON m.council_id = c.id
            WHERE m.state_id = ?
            LIMIT 1
        ");
        $stmt->execute([$state_id]);
        $meeting = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Meeting ICS error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Database error.';
        exit;
    }

    if (!$meeting) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Meeting not found.';
        exit;
    }

    // --- Build iCal timestamps ---

    $tz    = new DateTimeZone('Pacific/Honolulu');
    $start = new DateTime($meeting['meeting_date'] . ' ' . $meeting['meeting_time'], $tz);

    // Try to parse an end time from the description text (e.g. "9:00 AM - 11:00 AM")
    $duration_hours  = 1;
    $description_raw = !empty($meeting['full_agenda_text'])
        ? $meeting['full_agenda_text']
        : ($meeting['description'] ?? '');

    if (preg_match(
        '/(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)/i',
        $description_raw,
        $time_matches
    )) {
        $parsed_start = strtotime($time_matches[1]);
        $parsed_end   = strtotime($time_matches[2]);
        if ($parsed_end > $parsed_start) {
            $duration_hours = ($parsed_end - $parsed_start) / 3600;
        }
    }

    $end = clone $start;
    $end->add(new DateInterval('PT' . round($duration_hours) . 'H'));

    // Convert to UTC for the iCal DTSTART/DTEND fields
    $utc = new DateTimeZone('UTC');
    $start->setTimezone($utc);
    $end->setTimezone($utc);

    $dt_start = $start->format('Ymd\THis\Z');
    $dt_end   = $end->format('Ymd\THis\Z');
    $dt_stamp = gmdate('Ymd\THis\Z');

    // --- Clean and format text fields ---

    $uid     = $meeting['state_id'] . '@access100.app';
    $summary = ical_escape($meeting['title']);

    // Strip HTML, decode entities, remove redundant header lines
    $description_clean = strip_tags(html_entity_decode($description_raw ?? ''));
    $description_clean = preg_replace('/^Location:.*\n/im', '', $description_clean);
    $description_clean = preg_replace('/^Date:.*\n/im',     '', $description_clean);
    $description_clean = preg_replace('/^Time:.*\n/im',     '', $description_clean);
    $description_clean = trim($description_clean);

    $description = 'Council: ' . $meeting['council_name']
        . '\\n\\nOfficial Notice: ' . $meeting['detail_url']
        . '\\n\\n' . ical_escape($description_clean);

    $location = ical_escape(strip_tags($meeting['location'] ?? ''));

    // --- Send iCal response ---

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="meeting-' . $state_id . '.ics"');

    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//Access100//Hawaii Public Meetings//EN\r\n";
    echo "BEGIN:VEVENT\r\n";
    echo "UID:{$uid}\r\n";
    echo "DTSTAMP:{$dt_stamp}\r\n";
    echo "DTSTART:{$dt_start}\r\n";
    echo "DTEND:{$dt_end}\r\n";
    echo "SUMMARY:{$summary}\r\n";
    echo "DESCRIPTION:{$description}\r\n";
    echo "LOCATION:{$location}\r\n";
    echo "END:VEVENT\r\n";
    echo "END:VCALENDAR\r\n";
    exit;
}


// =====================================================================
// Shared helpers
// =====================================================================

/**
 * Shape a raw database row from the list query into the API meeting object.
 */
function shape_meeting_list_row(array $row): array
{
    return [
        'state_id'     => $row['state_id'],
        'title'        => $row['title'],
        'meeting_date' => $row['meeting_date'],
        'meeting_time' => $row['meeting_time'],
        'location'     => strip_tags($row['location'] ?? ''),
        'status'       => $row['status'],
        'detail_url'   => $row['detail_url'],
        'council'      => [
            'id'          => (int) $row['council_id'],
            'name'        => $row['council_name'],
            'parent_name' => $row['parent_council_name'],
        ],
    ];
}

/**
 * Escape a string for safe inclusion in an iCal property value.
 *
 * Per RFC 5545, commas and semicolons must be backslash-escaped,
 * and newlines are represented as the literal two-character sequence \n.
 */
function ical_escape(string $value): string
{
    $value = preg_replace('/([,;])/', '\\\\$1', $value);
    $value = str_replace("\n", '\\n', $value);
    return $value;
}
