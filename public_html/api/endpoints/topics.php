<?php
/**
 * Access100 API - Topics Endpoint
 *
 * Handles all /api/v1/topics routes:
 *   GET  /topics                   — list all topics with council counts
 *   GET  /topics/{slug}            — topic detail with mapped councils
 *   GET  /topics/{slug}/meetings   — meetings for councils mapped to this topic
 *
 * Requires: $route array from index.php, config.php loaded
 */

// Only GET is supported for topics
if ($route['method'] !== 'GET') {
    json_error(405, 'Method not allowed. Topics endpoint only supports GET.');
}

// ─── Route Dispatch ───────────────────────────────────────────────────────────

$topic_slug   = $route['resource_id'];
$sub_resource = $route['sub_resource'];

if ($topic_slug === null) {
    // GET /topics — list
    handle_topics_list($route['query']);
} elseif ($sub_resource === 'meetings') {
    // GET /topics/{slug}/meetings
    handle_topic_meetings($topic_slug, $route['query']);
} elseif ($sub_resource === null) {
    // GET /topics/{slug}
    handle_topic_detail($topic_slug);
} else {
    $safe_sub = preg_replace('/[^a-zA-Z0-9_-]/', '', $sub_resource);
    json_error(404, 'Unknown sub-resource: ' . $safe_sub);
}


// =====================================================================
// GET /topics — List all topics with council counts
// =====================================================================

function handle_topics_list(array $query): void
{
    try {
        $pdo  = get_db();
        $stmt = $pdo->query("
            SELECT
                t.id,
                t.slug,
                t.name,
                t.description,
                t.icon,
                t.display_order,
                COUNT(DISTINCT tcm.council_id) AS council_count,
                COUNT(DISTINCT mt.meeting_id) AS meeting_count
            FROM topics t
            LEFT JOIN topic_council_map tcm ON t.id = tcm.topic_id
            LEFT JOIN meeting_topics mt ON t.id = mt.topic_id
            GROUP BY t.id
            ORDER BY t.display_order ASC
        ");
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Topics list error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching topics.');
    }

    $topics = array_map(function (array $row): array {
        return [
            'id'            => (int) $row['id'],
            'slug'          => $row['slug'],
            'name'          => $row['name'],
            'description'   => $row['description'],
            'icon'          => $row['icon'],
            'display_order' => (int) $row['display_order'],
            'council_count' => (int) $row['council_count'],
            'meeting_count' => (int) $row['meeting_count'],
        ];
    }, $rows);

    set_cache_headers(3600);
    json_response($topics, 200, [
        'total' => count($topics),
    ]);
}


// =====================================================================
// GET /topics/{slug} — Topic detail with mapped councils
// =====================================================================

function handle_topic_detail(string $slug): void
{
    if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
        json_error(400, 'Invalid topic slug.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("SELECT * FROM topics WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $topic = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Topic detail error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching topic.');
    }

    if (!$topic) {
        json_error(404, 'Topic not found.');
    }

    // Fetch mapped councils
    try {
        $councils_stmt = $pdo->prepare("
            SELECT
                c.id,
                c.name,
                tcm.relevance,
                (SELECT COUNT(*) FROM meetings m WHERE m.council_id = c.id AND m.meeting_date >= CURDATE()) AS upcoming_meeting_count
            FROM topic_council_map tcm
            JOIN councils c ON tcm.council_id = c.id
            WHERE tcm.topic_id = ?
            ORDER BY tcm.relevance ASC, c.name ASC
        ");
        $councils_stmt->execute([$topic['id']]);
        $councils = $councils_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Topic councils error: ' . $e->getMessage());
        $councils = [];
    }

    $data = [
        'id'          => (int) $topic['id'],
        'slug'        => $topic['slug'],
        'name'        => $topic['name'],
        'description' => $topic['description'],
        'icon'        => $topic['icon'],
        'councils'    => array_map(function (array $row): array {
            return [
                'id'                     => (int) $row['id'],
                'name'                   => $row['name'],
                'relevance'              => $row['relevance'],
                'upcoming_meeting_count' => (int) $row['upcoming_meeting_count'],
            ];
        }, $councils),
    ];

    set_cache_headers(3600);
    json_response($data);
}


// =====================================================================
// GET /topics/{slug}/meetings — Meetings for councils in this topic
// =====================================================================

function handle_topic_meetings(string $slug, array $query): void
{
    if (!preg_match('/^[a-z0-9-]{1,50}$/', $slug)) {
        json_error(400, 'Invalid topic slug.');
    }

    // Validate the topic exists
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("SELECT id, slug, name FROM topics WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $topic = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Topic meetings lookup error: ' . $e->getMessage());
        json_error(500, 'Database error.');
    }

    if (!$topic) {
        json_error(404, 'Topic not found.');
    }

    // Pagination
    $limit  = isset($query['limit'])  ? min((int) $query['limit'],  200) : 50;
    $limit  = max($limit, 1);
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0)   : 0;

    $date_from = isset($query['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $query['date_from'])
        ? $query['date_from']
        : null;

    // Build the query — meetings from councils mapped to this topic
    // OR meetings directly tagged with this topic via meeting_topics
    $where_date = $date_from !== null ? 'm.meeting_date >= ?' : 'm.meeting_date >= CURDATE()';
    $params = $date_from !== null ? [$date_from] : [];

    $topic_id = (int) $topic['id'];

    $count_sql = "
        SELECT COUNT(DISTINCT m.id) AS total
        FROM meetings m
        WHERE {$where_date}
          AND (
            m.council_id IN (SELECT council_id FROM topic_council_map WHERE topic_id = ?)
            OR m.id IN (SELECT meeting_id FROM meeting_topics WHERE topic_id = ?)
          )
    ";
    $count_params = array_merge($params, [$topic_id, $topic_id]);

    try {
        $count_stmt = $pdo->prepare($count_sql);
        $count_stmt->execute($count_params);
        $total = (int) $count_stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Topic meetings count error: ' . $e->getMessage());
        json_error(500, 'Database error while counting meetings.');
    }

    $select_sql = "
        SELECT DISTINCT
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
        WHERE {$where_date}
          AND (
            m.council_id IN (SELECT council_id FROM topic_council_map WHERE topic_id = ?)
            OR m.id IN (SELECT meeting_id FROM meeting_topics WHERE topic_id = ?)
          )
        ORDER BY m.meeting_date ASC, m.meeting_time ASC
        LIMIT ? OFFSET ?
    ";
    $select_params = array_merge($params, [$topic_id, $topic_id, $limit, $offset]);

    try {
        $stmt = $pdo->prepare($select_sql);
        $stmt->execute($select_params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Topic meetings select error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meetings.');
    }

    $meetings = array_map(function (array $row): array {
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
    }, $rows);

    set_cache_headers(300);
    json_response($meetings, 200, [
        'topic_slug' => $topic['slug'],
        'topic_name' => $topic['name'],
        'total'      => $total,
        'limit'      => $limit,
        'offset'     => $offset,
        'has_more'   => ($offset + $limit) < $total,
    ]);
}
