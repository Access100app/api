<?php
/**
 * Access100 API - Councils Endpoint
 *
 * Handles all /api/v1/councils routes:
 *   GET  /councils                — list with filters
 *   GET  /councils/{id}           — council detail with parent info
 *   GET  /councils/{id}/meetings  — upcoming meetings for this council
 *
 * Requires: $route array from index.php, config.php loaded
 */

// Only GET is supported for councils
if ($route['method'] !== 'GET') {
    json_error(405, 'Method not allowed. Councils endpoint only supports GET.');
}

// ─── Route Dispatch ───────────────────────────────────────────────────────────

$council_id   = $route['resource_id'];
$sub_resource = $route['sub_resource'];

if ($council_id === null) {
    // GET /councils — list
    handle_councils_list($route['query']);
} elseif ($council_id === 'slug' && $sub_resource !== null) {
    // GET /councils/slug/{slug} — lookup by URL slug
    handle_council_by_slug($sub_resource);
} elseif ($sub_resource === 'meetings') {
    // GET /councils/{id}/meetings
    handle_council_meetings((int) $council_id, $route['query']);
} elseif ($sub_resource === 'profile') {
    // GET /councils/{id}/profile
    handle_council_profile((int) $council_id);
} elseif ($sub_resource === 'authority') {
    // GET /councils/{id}/authority
    handle_council_authority((int) $council_id);
} elseif ($sub_resource === 'members') {
    // GET /councils/{id}/members
    handle_council_members((int) $council_id);
} elseif ($sub_resource === 'vacancies') {
    // GET /councils/{id}/vacancies
    handle_council_vacancies((int) $council_id);
} elseif ($sub_resource === null) {
    // GET /councils/{id}
    handle_council_detail((int) $council_id);
} else {
    $safe_sub = preg_replace('/[^a-zA-Z0-9_-]/', '', $sub_resource);
    json_error(404, 'Unknown sub-resource: ' . $safe_sub);
}


// =====================================================================
// GET /councils — List with filters
// =====================================================================

function handle_councils_list(array $query): void
{
    // --- Validate and sanitize query parameters ---

    $keyword       = isset($query['q']) ? substr(trim($query['q']), 0, 200) : null;
    $parent_id     = isset($query['parent_id']) ? (int) $query['parent_id'] : null;
    $has_upcoming  = isset($query['has_upcoming']) && $query['has_upcoming'] === 'true';
    $topic_slug    = isset($query['topic']) ? trim($query['topic']) : null;
    $jurisdiction  = isset($query['jurisdiction']) ? trim($query['jurisdiction']) : null;
    $entity_type   = isset($query['type']) ? trim($query['type']) : null;

    // --- Build query ---

    $where_clauses = [];
    $params        = [];
    $joins         = '';

    if (!empty($keyword)) {
        $where_clauses[] = 'c.name LIKE ?';
        $params[]        = '%' . $keyword . '%';
    }

    if ($parent_id !== null && $parent_id > 0) {
        $where_clauses[] = 'c.parent_id = ?';
        $params[]        = $parent_id;
    }

    // Filter by topic — join through topic_council_map
    if (!empty($topic_slug) && preg_match('/^[a-z0-9-]{1,50}$/', $topic_slug)) {
        $joins .= ' JOIN topic_council_map tcm ON c.id = tcm.council_id JOIN topics t ON tcm.topic_id = t.id';
        $where_clauses[] = 't.slug = ?';
        $params[] = $topic_slug;
    }

    // Filter by jurisdiction — join council_profiles
    $valid_jurisdictions = ['state', 'honolulu', 'maui', 'hawaii', 'kauai'];
    if (!empty($jurisdiction) && in_array($jurisdiction, $valid_jurisdictions, true)) {
        $joins .= ' JOIN council_profiles cp_j ON c.id = cp_j.council_id';
        $where_clauses[] = 'cp_j.jurisdiction = ?';
        $params[] = $jurisdiction;
    }

    // Filter by entity type — join council_profiles
    $valid_types = ['board', 'commission', 'council', 'committee', 'authority', 'department', 'office'];
    if (!empty($entity_type) && in_array($entity_type, $valid_types, true)) {
        // Avoid duplicate join if jurisdiction already joined
        if (empty($jurisdiction) || !in_array($jurisdiction, $valid_jurisdictions, true)) {
            $joins .= ' JOIN council_profiles cp_t ON c.id = cp_t.council_id';
            $where_clauses[] = 'cp_t.entity_type = ?';
        } else {
            $where_clauses[] = 'cp_j.entity_type = ?';
        }
        $params[] = $entity_type;
    }

    $where_sql = !empty($where_clauses)
        ? 'WHERE ' . implode(' AND ', $where_clauses)
        : '';

    // When has_upcoming is true, only return councils that have at least
    // one meeting on or after today. Uses a correlated EXISTS subquery.
    if ($has_upcoming) {
        $upcoming_condition = 'EXISTS (SELECT 1 FROM meetings m WHERE m.council_id = c.id AND m.meeting_date >= CURDATE())';
        $where_sql = empty($where_sql)
            ? 'WHERE ' . $upcoming_condition
            : $where_sql . ' AND ' . $upcoming_condition;
    }

    // Count upcoming meetings per council as a subquery
    $sql = "
        SELECT DISTINCT
            c.id,
            c.name,
            c.parent_id,
            p.name AS parent_name,
            (SELECT COUNT(*) FROM meetings m WHERE m.council_id = c.id AND m.meeting_date >= CURDATE()) AS upcoming_meeting_count
        FROM councils c
        LEFT JOIN councils p ON c.parent_id = p.id
        {$joins}
        {$where_sql}
        ORDER BY c.name ASC
    ";

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Councils list error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching councils.');
    }

    // --- Shape response ---

    $councils = array_map(function (array $row): array {
        return [
            'id'                     => (int) $row['id'],
            'name'                   => $row['name'],
            'parent_id'              => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'parent_name'            => $row['parent_name'],
            'upcoming_meeting_count' => (int) $row['upcoming_meeting_count'],
        ];
    }, $rows);

    set_cache_headers(3600);
    json_response($councils, 200, [
        'total' => count($councils),
    ]);
}


// =====================================================================
// GET /councils/{id} — Council detail
// =====================================================================

function handle_council_detail(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT
                c.id,
                c.name,
                c.parent_id,
                p.name AS parent_name,
                (SELECT COUNT(*) FROM meetings m WHERE m.council_id = c.id AND m.meeting_date >= CURDATE()) AS upcoming_meeting_count
            FROM councils c
            LEFT JOIN councils p ON c.parent_id = p.id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$council_id]);
        $council = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Council detail error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching council.');
    }

    if (!$council) {
        json_error(404, 'Council not found.');
    }

    // Fetch child councils (sub-committees, etc.)
    $children = [];
    try {
        $child_stmt = $pdo->prepare("
            SELECT id, name
            FROM councils
            WHERE parent_id = ?
            ORDER BY name ASC
        ");
        $child_stmt->execute([$council_id]);
        $children = $child_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Council children error: ' . $e->getMessage());
        // Non-fatal
    }

    $data = [
        'id'                     => (int) $council['id'],
        'name'                   => $council['name'],
        'parent_id'              => $council['parent_id'] !== null ? (int) $council['parent_id'] : null,
        'parent_name'            => $council['parent_name'],
        'upcoming_meeting_count' => (int) $council['upcoming_meeting_count'],
        'children'               => array_map(function (array $row): array {
            return [
                'id'   => (int) $row['id'],
                'name' => $row['name'],
            ];
        }, $children),
    ];

    set_cache_headers(3600);
    json_response($data);
}


// =====================================================================
// GET /councils/{id}/meetings — Upcoming meetings for a council
// =====================================================================

function handle_council_meetings(int $council_id, array $query): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    // Verify the council exists
    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("SELECT id, name FROM councils WHERE id = ? LIMIT 1");
        $stmt->execute([$council_id]);
        $council = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Council meetings lookup error: ' . $e->getMessage());
        json_error(500, 'Database error.');
    }

    if (!$council) {
        json_error(404, 'Council not found.');
    }

    // Pagination
    $limit  = isset($query['limit'])  ? min((int) $query['limit'],  200) : 50;
    $limit  = max($limit, 1);
    $offset = isset($query['offset']) ? max((int) $query['offset'], 0)   : 0;

    // Count total upcoming meetings for this council
    try {
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) AS total
            FROM meetings
            WHERE council_id = ? AND meeting_date >= CURDATE()
        ");
        $count_stmt->execute([$council_id]);
        $total = (int) $count_stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Council meetings count error: ' . $e->getMessage());
        json_error(500, 'Database error while counting meetings.');
    }

    // Fetch the meetings
    try {
        $stmt = $pdo->prepare("
            SELECT
                m.state_id,
                m.title,
                m.meeting_date,
                m.meeting_time,
                m.location,
                m.status,
                m.detail_url
            FROM meetings m
            WHERE m.council_id = ? AND m.meeting_date >= CURDATE()
            ORDER BY m.meeting_date ASC, m.meeting_time ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$council_id, $limit, $offset]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Council meetings select error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching meetings.');
    }

    // Shape rows
    $meetings = array_map(function (array $row): array {
        return [
            'state_id'     => $row['state_id'],
            'title'        => $row['title'],
            'meeting_date' => $row['meeting_date'],
            'meeting_time' => $row['meeting_time'],
            'location'     => strip_tags($row['location'] ?? ''),
            'status'       => $row['status'],
            'detail_url'   => $row['detail_url'],
        ];
    }, $rows);

    set_cache_headers(300);
    json_response($meetings, 200, [
        'council_id'   => (int) $council['id'],
        'council_name' => $council['name'],
        'total'        => $total,
        'limit'        => $limit,
        'offset'       => $offset,
        'has_more'     => ($offset + $limit) < $total,
    ]);
}


// =====================================================================
// GET /councils/slug/{slug} — Lookup council by URL slug
// =====================================================================

function handle_council_by_slug(string $slug): void
{
    if (!preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
        json_error(400, 'Invalid council slug.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT cp.council_id
            FROM council_profiles cp
            WHERE cp.slug = ?
            LIMIT 1
        ");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Council slug lookup error: ' . $e->getMessage());
        json_error(500, 'Database error.');
    }

    if (!$row) {
        json_error(404, 'Council not found.');
    }

    // Delegate to the detail handler
    handle_council_detail((int) $row['council_id']);
}


// =====================================================================
// GET /councils/{id}/profile — Full profile data
// =====================================================================

function handle_council_profile(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT cp.*, c.name AS council_name
            FROM council_profiles cp
            JOIN councils c ON cp.council_id = c.id
            WHERE cp.council_id = ?
            LIMIT 1
        ");
        $stmt->execute([$council_id]);
        $profile = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Council profile error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching profile.');
    }

    if (!$profile) {
        json_error(404, 'Profile not found for this council.');
    }

    $data = [
        'council_id'             => (int) $profile['council_id'],
        'council_name'           => $profile['council_name'],
        'slug'                   => $profile['slug'],
        'plain_description'      => $profile['plain_description'],
        'decisions_examples'     => $profile['decisions_examples'],
        'why_care'               => $profile['why_care'],
        'appointment_method'     => $profile['appointment_method'],
        'term_length'            => $profile['term_length'],
        'meeting_schedule'       => $profile['meeting_schedule'],
        'default_location'       => $profile['default_location'],
        'virtual_option'         => (bool) $profile['virtual_option'],
        'testimony_email'        => $profile['testimony_email'],
        'testimony_instructions' => $profile['testimony_instructions'],
        'public_comment_info'    => $profile['public_comment_info'],
        'official_website'       => $profile['official_website'],
        'contact_phone'          => $profile['contact_phone'],
        'contact_email'          => $profile['contact_email'],
        'jurisdiction'           => $profile['jurisdiction'],
        'entity_type'            => $profile['entity_type'],
        'member_count'           => $profile['member_count'] !== null ? (int) $profile['member_count'] : null,
        'vacancy_count'          => (int) $profile['vacancy_count'],
        'vacancy_info'           => $profile['vacancy_info'],
        'last_updated'           => $profile['last_updated'],
    ];

    set_cache_headers(3600);
    json_response($data);
}


// =====================================================================
// GET /councils/{id}/authority — Legal authority references
// =====================================================================

function handle_council_authority(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT citation, description, url, display_order
            FROM council_legal_authority
            WHERE council_id = ?
            ORDER BY display_order ASC
        ");
        $stmt->execute([$council_id]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Council authority error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching authority records.');
    }

    $authority = array_map(function (array $row): array {
        return [
            'citation'    => $row['citation'],
            'description' => $row['description'],
            'url'         => $row['url'],
        ];
    }, $rows);

    set_cache_headers(3600);
    json_response($authority, 200, [
        'council_id' => $council_id,
        'total'      => count($authority),
    ]);
}


// =====================================================================
// GET /councils/{id}/members — Board member list
// =====================================================================

function handle_council_members(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT name, title, role, appointed_by, term_start, term_end, status
            FROM council_members
            WHERE council_id = ?
            ORDER BY
                FIELD(role, 'chair', 'vice-chair', 'member', 'ex-officio'),
                display_order ASC,
                name ASC
        ");
        $stmt->execute([$council_id]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Council members error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching members.');
    }

    $members = array_map(function (array $row): array {
        return [
            'name'         => $row['name'],
            'title'        => $row['title'],
            'role'         => $row['role'],
            'appointed_by' => $row['appointed_by'],
            'term_start'   => $row['term_start'],
            'term_end'     => $row['term_end'],
            'status'       => $row['status'],
        ];
    }, $rows);

    set_cache_headers(3600);
    json_response($members, 200, [
        'council_id' => $council_id,
        'total'      => count($members),
    ]);
}


// =====================================================================
// GET /councils/{id}/vacancies — Open appointment seats
// =====================================================================

function handle_council_vacancies(int $council_id): void
{
    if ($council_id <= 0) {
        json_error(400, 'Invalid council ID.');
    }

    try {
        $pdo  = get_db();
        $stmt = $pdo->prepare("
            SELECT seat_description, requirements, application_url,
                   application_deadline, appointing_authority, status
            FROM council_vacancies
            WHERE council_id = ? AND status = 'open'
            ORDER BY application_deadline ASC
        ");
        $stmt->execute([$council_id]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Council vacancies error: ' . $e->getMessage());
        json_error(500, 'Database error while fetching vacancies.');
    }

    $vacancies = array_map(function (array $row): array {
        return [
            'seat_description'      => $row['seat_description'],
            'requirements'          => $row['requirements'],
            'application_url'       => $row['application_url'],
            'application_deadline'  => $row['application_deadline'],
            'appointing_authority'  => $row['appointing_authority'],
            'status'                => $row['status'],
        ];
    }, $rows);

    set_cache_headers(3600);
    json_response($vacancies, 200, [
        'council_id' => $council_id,
        'total'      => count($vacancies),
    ]);
}
