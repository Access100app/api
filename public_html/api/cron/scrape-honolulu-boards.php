<?php
/**
 * Access100 API - Honolulu Boards & Commissions RSS Scraper
 *
 * Polls the Honolulu.gov Events Manager main RSS feed for board,
 * commission, and city council committee meetings. Filters out
 * neighborhood board meetings (handled by scrape-nco.php) and
 * non-meeting events.
 *
 * Feed URL: https://www.honolulu.gov/events/feed/?scope=future&limit=200
 *
 * Crontab (hourly, 4 min after eHawaii scraper):
 *   4 * * * * php /path/to/api/cron/scrape-honolulu-boards.php >> /var/log/access100-scrape-hnl-boards.log 2>&1
 *
 * Manual:
 *   php api/cron/scrape-honolulu-boards.php
 *   php api/cron/scrape-honolulu-boards.php --dry-run
 *   php api/cron/scrape-honolulu-boards.php --limit=5
 *   php api/cron/scrape-honolulu-boards.php --skip-details
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/summarizer.php';

// ─── Constants ──────────────────────────────────────────────────
define('HNL_BOARDS_FEED_URL', 'https://www.honolulu.gov/events/feed/?scope=future&limit=200');
define('HNL_BOARDS_USER_AGENT', 'Access100-Scraper/1.0 (+https://civi.me)');

// Keywords that identify non-meeting events (case-insensitive)
define('HNL_BOARDS_DENY_KEYWORDS', [
    'Tournament', 'Workshop', 'Festival', 'Hiring Event', 'Training',
    'Egg Hunt', 'Golf', 'Job Fair', 'Open House', 'Concert', 'Parade',
    'Celebration', 'Ceremony', 'Reception', 'Luncheon', 'Gala',
    'Fun Run', 'Walk', 'Race', 'Clinic', 'Class', 'Camp',
    'Registration', 'Orientation', 'Fair', 'Expo', 'Show',
]);

// ─── CLI Arguments ──────────────────────────────────────────────
$dry_run      = in_array('--dry-run', $argv ?? [], true);
$audit_mode   = in_array('--audit', $argv ?? [], true);
$skip_details = in_array('--skip-details', $argv ?? [], true);
$limit        = 0;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$start = microtime(true);
$stats = [
    'items_found'      => 0,
    'meetings_new'     => 0,
    'meetings_updated' => 0,
    'details_scraped'  => 0,
    'summarized'       => 0,
    'skipped_nco'      => 0,
    'skipped_non_mtg'  => 0,
    'skipped_no_match' => 0,
    'errors'           => 0,
];

echo date('[Y-m-d H:i:s]') . " Honolulu Boards scraper starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n";

try {
    $pdo = get_db();

    // ─── Audit Mode ─────────────────────────────────────────────────
    if ($audit_mode) {
        echo date('[Y-m-d H:i:s]') . " Honolulu Boards audit mode starting...\n";

        $stmt = $pdo->query("
            SELECT id, council_id, title, meeting_date, raw_rss_data
            FROM meetings
            WHERE source = 'honolulu_boards'
              AND raw_rss_data IS NOT NULL
            ORDER BY meeting_date DESC
            LIMIT 500
        ");
        $meetings = $stmt->fetchAll();
        echo "  Meetings with raw_rss_data (source=honolulu_boards): " . count($meetings) . "\n\n";

        $fallback_count = 0;
        $mismatch_count = 0;
        $mismatches = [];

        foreach ($meetings as $row) {
            $raw = json_decode($row['raw_rss_data'], true);
            $desc_html = html_entity_decode($raw['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $desc_lines = preg_split('/<br\s*\/?>/i', $desc_html);
            $desc_lines = array_map('trim', array_map('strip_tags', $desc_lines));
            $desc_lines = array_values(array_filter($desc_lines, fn($l) => $l !== ''));

            $date_time_line = $desc_lines[0] ?? '';
            if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-\s*/i', $date_time_line, $dm)) {
                $date_part = $dm[1];
            } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-/i', $date_time_line, $dm)) {
                $date_part = $dm[1];
            } else {
                $date_part = $date_time_line;
            }

            $dt = DateTime::createFromFormat('F j, Y', trim($date_part));
            $would_parse = $dt ? $dt->format('Y-m-d') : null;
            $used_fallback = ($would_parse === null);

            if ($used_fallback && !empty($raw['pubDate'])) {
                $would_parse = date('Y-m-d', strtotime($raw['pubDate']));
                $fallback_count++;
            }

            if ($would_parse !== null && $would_parse !== $row['meeting_date']) {
                $mismatch_count++;
                $mismatches[] = sprintf(
                    '  [HNL-BOARDS] meeting_id=%d | stored: %s | would_parse: %s | title: %s',
                    $row['id'], $row['meeting_date'], $would_parse, substr($row['title'], 0, 60)
                );
            }
        }

        echo "--- Honolulu Boards ---\n";
        echo "  pubDate fallbacks found: {$fallback_count}\n";
        echo "  Date mismatches: {$mismatch_count}\n";
        foreach ($mismatches as $line) echo $line . "\n";
        if ($mismatch_count > 0) {
            echo "  Verdict: {$mismatch_count} mismatches found — fix needed\n";
        } elseif ($fallback_count > 0) {
            echo "  Verdict: pubDate fallbacks present but dates matched — logging needed, parser may be OK\n";
        } else {
            echo "  Verdict: 0 mismatches — parsing correct\n";
        }

        $elapsed = round(microtime(true) - $start, 2);
        echo "\n" . date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";
        exit(0);
    }

    // ─── 1. Build council keyword map ───────────────────────────
    $council_map = build_hnl_boards_council_map($pdo);

    if (empty($council_map)) {
        echo "  ERROR: No Honolulu board/commission councils found in database.\n";
        echo "  Run seed-honolulu-boards.php first.\n";
        exit(1);
    }
    echo "  Council map: " . count($council_map) . " keyword mappings loaded\n";

    // ─── 2. Fetch RSS feed ──────────────────────────────────────
    $xml = fetch_hnl_boards_rss();
    if ($xml === null) {
        echo "  ERROR: Failed to fetch Honolulu events RSS feed.\n";
        exit(1);
    }

    $items = $xml->channel->item ?? [];
    $stats['items_found'] = count($items);
    echo "  RSS items: {$stats['items_found']}\n";

    if ($stats['items_found'] === 0) {
        echo "  No items in feed — nothing to do.\n";
        record_hnl_boards_scrape_run($pdo, $stats);
        exit(0);
    }

    // ─── 3. Process each RSS item ───────────────────────────────
    $processed = 0;

    foreach ($items as $item) {
        if ($limit > 0 && $processed >= $limit) {
            echo "  Limit reached ({$limit}), stopping.\n";
            break;
        }

        $parsed = parse_hnl_boards_rss_item($item, $council_map, $stats);
        if ($parsed === null) {
            continue;
        }

        // Check for existing meeting
        $existing = find_existing_hnl_boards_meeting($pdo, $parsed);

        if ($existing) {
            if (has_hnl_boards_meeting_changed($existing, $parsed)) {
                if ($dry_run) {
                    echo "    UPDATE: {$parsed['title']} ({$parsed['meeting_date']})\n";
                } else {
                    update_hnl_boards_meeting($pdo, $existing['id'], $parsed);
                }
                $stats['meetings_updated']++;
            }

            // Re-scrape detail page for meetings without attachments
            if (!$dry_run && !$skip_details && !empty($parsed['detail_url'])) {
                $has_att = $pdo->prepare("SELECT 1 FROM attachments WHERE meeting_id = ? LIMIT 1");
                $has_att->execute([$existing['id']]);
                if (!$has_att->fetch()) {
                    $detail = scrape_hnl_boards_detail($parsed['detail_url']);
                    if ($detail && !empty($detail['attachments'])) {
                        insert_hnl_boards_attachments($pdo, $existing['id'], $detail['attachments']);
                        apply_hnl_boards_detail($pdo, $existing['id'], $detail);
                        // Clear stale summary so it regenerates with agenda content
                        $pdo->prepare("UPDATE meetings SET summary_text = NULL WHERE id = ?")->execute([$existing['id']]);
                        $summary = summarize_meeting($existing['id']);
                        if ($summary) $stats['summarized']++;
                        echo "    ENRICHED: {$parsed['title']} (found attachments)\n";
                        $stats['details_scraped']++;
                    }
                    usleep(500000);
                }
            }
        } else {
            // New meeting
            if ($dry_run) {
                echo "    NEW: {$parsed['title']} ({$parsed['meeting_date']})\n";
            } else {
                $meeting_id = insert_hnl_boards_meeting($pdo, $parsed);

                // Scrape detail page for agenda and virtual meeting info
                if ($meeting_id && !$skip_details && !empty($parsed['detail_url'])) {
                    $detail = scrape_hnl_boards_detail($parsed['detail_url']);
                    if ($detail) {
                        apply_hnl_boards_detail($pdo, $meeting_id, $detail);
                        if (!empty($detail['attachments'])) {
                            insert_hnl_boards_attachments($pdo, $meeting_id, $detail['attachments']);
                        }
                        $stats['details_scraped']++;
                    }
                    usleep(500000); // 0.5s between detail page fetches
                }

                // Generate AI summary
                if ($meeting_id) {
                    $summary = summarize_meeting($meeting_id);
                    if ($summary) {
                        $stats['summarized']++;
                    }
                }
            }
            $stats['meetings_new']++;
        }

        $processed++;

        // Extra delay if many new meetings
        if ($stats['meetings_new'] > 10 && $stats['meetings_new'] % 10 === 0) {
            usleep(1000000); // 1s pause every 10 new meetings
        }
    }

    // ─── 4. Record scraper run ──────────────────────────────────
    if (!$dry_run) {
        record_hnl_boards_scrape_run($pdo, $stats);
    }

} catch (PDOException $e) {
    error_log('Honolulu Boards scraper fatal DB error: ' . $e->getMessage());
    echo "  FATAL ERROR: " . $e->getMessage() . "\n";
}

// ─── Done ────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $start, 2);
echo "\n  Items found:       {$stats['items_found']}\n";
echo "  Meetings new:      {$stats['meetings_new']}\n";
echo "  Meetings updated:  {$stats['meetings_updated']}\n";
echo "  Details scraped:   {$stats['details_scraped']}\n";
echo "  Summarized:        {$stats['summarized']}\n";
echo "  Skipped (NCO):     {$stats['skipped_nco']}\n";
echo "  Skipped (non-mtg): {$stats['skipped_non_mtg']}\n";
echo "  Skipped (no match):{$stats['skipped_no_match']}\n";
echo "  Errors:            {$stats['errors']}\n";
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";


// =====================================================================
// Core Functions
// =====================================================================

/**
 * Build a map of keyword → council_id from the database.
 *
 * Loads all child councils under "City and County of Honolulu" and maps
 * keywords from their names to council IDs.
 */
function build_hnl_boards_council_map(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.slug
        FROM councils c
        JOIN councils p ON c.parent_id = p.id
        WHERE p.name = 'City and County of Honolulu'
          AND c.is_active = 1
    ");

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        // Build keyword from council name — use the distinctive part
        // e.g. "Honolulu Police Commission" → "Police Commission"
        //      "Budget Committee" → "Budget Committee"
        //      "Zoning Board of Appeals" → "Zoning Board of Appeals"
        $name = $row['name'];

        // For each council, determine the best keyword to match in RSS titles
        $keyword_map = [
            'honolulu-police-commission'                       => 'Police Commission',
            'honolulu-liquor-commission'                       => 'Liquor Commission',
            'zoning-board-of-appeals'                          => 'Zoning Board of Appeals',
            'building-board-of-appeals'                        => 'Building Board of Appeals',
            'oahumpo-citizen-advisory-committee'               => 'OahuMPO',
            'honolulu-city-council'                            => 'City Council',
            'infrastructure-transportation-technology-committee' => 'Infrastructure, Transportation',
            'international-legal-affairs-committee'             => 'International',
            'zoning-planning-committee'                        => 'Zoning & Planning',
            'public-safety-economy-committee'                  => 'Public Safety',
            'government-efficiency-customer-services-committee' => 'Government Efficiency',
            'budget-committee'                                 => 'Budget Committee',
        ];

        $slug = $row['slug'];
        if (isset($keyword_map[$slug])) {
            $map[$keyword_map[$slug]] = [
                'council_id' => (int) $row['id'],
                'slug'       => $slug,
            ];
        }
    }

    return $map;
}


/**
 * Fetch and parse the Honolulu events RSS feed.
 */
function fetch_hnl_boards_rss(): ?SimpleXMLElement
{
    $ch = curl_init(HNL_BOARDS_FEED_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => HNL_BOARDS_USER_AGENT,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200 || empty($response)) {
        error_log("Honolulu Boards scraper: RSS fetch failed (HTTP {$http_code}): {$err}");
        return null;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    libxml_clear_errors();

    if ($xml === false) {
        error_log('Honolulu Boards scraper: Invalid XML from feed');
        return null;
    }

    return $xml;
}


/**
 * Parse an RSS <item> into a normalized meeting array.
 *
 * Applies three-stage filtering:
 *   1. Skip neighborhood board meetings (title starts with \d{2}\.)
 *   2. Skip non-meeting events (deny-list keywords)
 *   3. Match to a council via keyword map
 *
 * @return array|null Parsed meeting data, or null if filtered/unparseable
 */
function parse_hnl_boards_rss_item(SimpleXMLElement $item, array $council_map, array &$stats): ?array
{
    $title_raw = trim((string) $item->title);
    $link      = trim((string) $item->link);
    $desc_raw  = (string) $item->description;
    $pub_date  = (string) $item->pubDate;

    if (empty($link) || empty($title_raw)) {
        return null;
    }

    // ─── Filter 1: Skip NCO neighborhood board meetings ─────────
    if (preg_match('/^\d{2}\./', $title_raw)) {
        $stats['skipped_nco']++;
        return null;
    }

    // ─── Filter 2: Skip non-meeting events ──────────────────────
    foreach (HNL_BOARDS_DENY_KEYWORDS as $keyword) {
        if (stripos($title_raw, $keyword) !== false) {
            $stats['skipped_non_mtg']++;
            return null;
        }
    }

    // ─── Filter 3: Match to a council ───────────────────────────
    $matched_council = null;
    $matched_keyword = null;
    foreach ($council_map as $keyword => $council_info) {
        if (stripos($title_raw, $keyword) !== false) {
            $matched_council = $council_info;
            $matched_keyword = $keyword;
            break;
        }
    }

    if ($matched_council === null) {
        // Unrecognized item — log for manual review
        error_log("Honolulu Boards scraper: unmatched item: {$title_raw}");
        $stats['skipped_no_match']++;
        return null;
    }

    $council_id = $matched_council['council_id'];
    $council_slug = $matched_council['slug'];

    // ─── Detect cancellations ───────────────────────────────────
    $status = 'active';
    if (preg_match('/\bCANCEL(?:LED)?\b/i', $title_raw)) {
        $status = 'cancelled';
    }

    // ─── Parse description for date, time, location ─────────────
    // Same format as NCO: <br/>-delimited lines
    $desc_html = html_entity_decode($desc_raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $desc_lines = preg_split('/<br\s*\/?>/i', $desc_html);
    $desc_lines = array_map('trim', $desc_lines);
    $desc_lines = array_map('strip_tags', $desc_lines);
    $desc_lines = array_values(array_filter($desc_lines, fn($l) => $l !== ''));

    $meeting_date = null;
    $meeting_time_start = null;
    $meeting_time_end = null;
    $location_venue = '';
    $location_address = '';
    $location_city = '';

    if (!empty($desc_lines[0])) {
        $date_time_line = $desc_lines[0];

        // Handle date ranges
        if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-\s*(.+)$/i', $date_time_line, $m)) {
            $date_part = $m[1];
            $time_part = $m[2];
        } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*(.+)$/i', $date_time_line, $m)) {
            $date_part = $m[1];
            $time_part = $m[2];
        } else {
            $date_part = $date_time_line;
            $time_part = '';
        }

        $dt = DateTime::createFromFormat('F j, Y', trim($date_part));
        if ($dt) {
            $meeting_date = $dt->format('Y-m-d');
        }

        if (preg_match('/(\d{1,2}:\d{2}\s*[ap]m)\s*-\s*(\d{1,2}:\d{2}\s*[ap]m)/i', $time_part, $tm)) {
            $start_ts = strtotime($tm[1]);
            $end_ts   = strtotime($tm[2]);
            if ($start_ts) $meeting_time_start = date('H:i:s', $start_ts);
            if ($end_ts)   $meeting_time_end   = date('H:i:s', $end_ts);
        }
    }

    // Fallback: use pubDate
    if ($meeting_date === null && !empty($pub_date)) {
        $meeting_date = date('Y-m-d', strtotime($pub_date));
        error_log(sprintf(
            'Honolulu Boards scraper: pubDate fallback used — council_id=%d, title=%s, pubDate=%s, desc_snippet=%s',
            $council_id,
            $title_raw ?? '(unknown)',
            $pub_date,
            substr(strip_tags($desc_html ?? ''), 0, 120)
        ));
    }

    if ($meeting_date === null) {
        return null;
    }

    // Location fields
    if (isset($desc_lines[1])) $location_venue   = $desc_lines[1];
    if (isset($desc_lines[2])) $location_address  = $desc_lines[2];
    if (isset($desc_lines[3])) $location_city     = $desc_lines[3];

    $location_parts = array_filter([$location_venue, $location_address, $location_city]);
    $location = implode(', ', $location_parts);

    // ─── Generate synthetic state_id ────────────────────────────
    $state_id = generate_hnl_boards_state_id($council_slug, $meeting_date);

    // ─── Build raw RSS JSON ─────────────────────────────────────
    $raw_rss = json_encode([
        'title'       => $title_raw,
        'link'        => $link,
        'description' => $desc_raw,
        'pubDate'     => $pub_date,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return [
        'state_id'          => $state_id,
        'external_id'       => $link,
        'council_id'        => $council_id,
        'title'             => $title_raw,
        'description'       => trim(strip_tags($desc_html)),
        'location'          => $location,
        'location_venue'    => $location_venue,
        'location_address'  => $location_address,
        'location_city'     => $location_city,
        'meeting_date'      => $meeting_date,
        'meeting_time'      => $meeting_time_start,
        'meeting_time_end'  => $meeting_time_end,
        'detail_url'        => $link,
        'status'            => $status,
        'source'            => 'honolulu_boards',
        'raw_rss'           => $raw_rss,
    ];
}


/**
 * Generate a deterministic synthetic state_id.
 *
 * Format: "hnl-{slug}-{YYYYMMDD}"
 * Truncates slug to keep total under 50 chars.
 */
function generate_hnl_boards_state_id(string $slug, string $date): string
{
    $date_compact = str_replace('-', '', $date);
    // "hnl-" (4) + "-" (1) + date (8) = 13 chars overhead, slug max = 37
    $slug_truncated = substr($slug, 0, 37);
    return 'hnl-' . $slug_truncated . '-' . $date_compact;
}


/**
 * Find an existing meeting by state_id or detail_url.
 */
function find_existing_hnl_boards_meeting(PDO $pdo, array $parsed): ?array
{
    if (!empty($parsed['state_id'])) {
        $stmt = $pdo->prepare("
            SELECT id, title, description, location, meeting_date, meeting_time, status
            FROM meetings WHERE state_id = ? LIMIT 1
        ");
        $stmt->execute([$parsed['state_id']]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    if (!empty($parsed['detail_url'])) {
        $stmt = $pdo->prepare("
            SELECT id, title, description, location, meeting_date, meeting_time, status
            FROM meetings WHERE detail_url = ? LIMIT 1
        ");
        $stmt->execute([$parsed['detail_url']]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    return null;
}


/**
 * Check if meeting data has changed.
 */
function has_hnl_boards_meeting_changed(array $existing, array $parsed): bool
{
    if ($existing['title'] !== $parsed['title']) return true;
    if ($existing['location'] !== $parsed['location']) return true;
    if ($existing['meeting_date'] !== $parsed['meeting_date']) return true;
    if ($existing['status'] !== $parsed['status']) return true;

    $existing_time = $existing['meeting_time'] ? substr($existing['meeting_time'], 0, 5) : null;
    $parsed_time   = $parsed['meeting_time'] ? substr($parsed['meeting_time'], 0, 5) : null;
    if ($existing_time !== $parsed_time) return true;

    return false;
}


/**
 * Insert a new meeting.
 *
 * @return int|null The new meeting ID, or null on failure
 */
function insert_hnl_boards_meeting(PDO $pdo, array $parsed): ?int
{
    $stmt = $pdo->prepare("
        INSERT INTO meetings
            (state_id, external_id, council_id, title, description, location,
             location_venue, location_address, location_city,
             meeting_date, meeting_time, detail_url, status, source, raw_rss_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'honolulu_boards', ?)
    ");

    try {
        $stmt->execute([
            $parsed['state_id'],
            $parsed['external_id'],
            $parsed['council_id'],
            $parsed['title'],
            $parsed['description'],
            $parsed['location'],
            $parsed['location_venue'],
            $parsed['location_address'],
            $parsed['location_city'],
            $parsed['meeting_date'],
            $parsed['meeting_time'],
            $parsed['detail_url'],
            $parsed['status'],
            $parsed['raw_rss'],
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            return null; // Duplicate key
        }
        error_log("Honolulu Boards scraper: insert failed for {$parsed['state_id']}: " . $e->getMessage());
        return null;
    }
}


/**
 * Update an existing meeting with new data from RSS.
 */
function update_hnl_boards_meeting(PDO $pdo, int $meeting_id, array $parsed): void
{
    $stmt = $pdo->prepare("
        UPDATE meetings
        SET title = ?, description = ?, location = ?,
            location_venue = ?, location_address = ?, location_city = ?,
            meeting_date = ?, meeting_time = ?, status = ?,
            raw_rss_data = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $parsed['title'],
        $parsed['description'],
        $parsed['location'],
        $parsed['location_venue'],
        $parsed['location_address'],
        $parsed['location_city'],
        $parsed['meeting_date'],
        $parsed['meeting_time'],
        $parsed['status'],
        $parsed['raw_rss'],
        $meeting_id,
    ]);
}


/**
 * Scrape a detail page for agenda text and virtual meeting info.
 *
 * Same WordPress Events Manager format as NCO.
 *
 * @return array|null Parsed detail data, or null on failure
 */
function scrape_hnl_boards_detail(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => HNL_BOARDS_USER_AGENT,
    ]);

    $html      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200 || empty($html)) {
        error_log("Honolulu Boards scraper: detail page fetch failed for {$url} (HTTP {$http_code}): {$err}");
        return null;
    }

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);

    $content_nodes = $xpath->query("//*[contains(@class, 'em-event-content')]");
    if ($content_nodes->length === 0) {
        $content_nodes = $xpath->query("//article | //div[contains(@class, 'event')]");
    }

    if ($content_nodes->length === 0) {
        return null;
    }

    $content_node = $content_nodes->item(0);
    $content_html = $doc->saveHTML($content_node);
    $content_text = trim(strip_tags($content_html));

    $result = [
        'full_agenda_text' => $content_text,
        'zoom_link'        => null,
        'zoom_password'    => null,
    ];

    // Extract virtual meeting URLs (WebEx, Teams, Zoom)
    if (preg_match('#https?://[^\s"<]*(?:\.webex\.com|teams\.microsoft\.com|zoom\.us)/[^\s"<]+#i', $content_html, $m)) {
        $result['zoom_link'] = html_entity_decode($m[0]);
    }

    // Extract meeting password
    if (preg_match('/Password[:\s]*(\S+)/i', $content_text, $m)) {
        $result['zoom_password'] = $m[1];
    }

    // Extract document/attachment links (PDFs, DOCs, etc.)
    $result['attachments'] = extract_attachment_links($content_html);

    return $result;
}


/**
 * Apply scraped detail data to a meeting record.
 */
function apply_hnl_boards_detail(PDO $pdo, int $meeting_id, array $detail): void
{
    $sets   = [];
    $params = [];

    if (!empty($detail['full_agenda_text'])) {
        $sets[]   = 'full_agenda_text = ?';
        $params[] = $detail['full_agenda_text'];

        $sets[]   = 'description = CASE WHEN LENGTH(description) < LENGTH(?) THEN ? ELSE description END';
        $params[] = $detail['full_agenda_text'];
        $params[] = $detail['full_agenda_text'];
    }

    if (!empty($detail['zoom_link'])) {
        $sets[]   = 'zoom_link = ?';
        $params[] = $detail['zoom_link'];
    }

    if (!empty($detail['zoom_password'])) {
        $sets[]   = 'zoom_password = ?';
        $params[] = $detail['zoom_password'];
    }

    if (empty($sets)) {
        return;
    }

    $params[] = $meeting_id;
    $sql = "UPDATE meetings SET " . implode(', ', $sets) . " WHERE id = ?";
    $pdo->prepare($sql)->execute($params);
}


/**
 * Insert scraped attachment links into the attachments table.
 *
 * Skips duplicates (same meeting_id + file_url).
 */
function insert_hnl_boards_attachments(PDO $pdo, int $meeting_id, array $attachments): void
{
    foreach ($attachments as $att) {
        $exists = $pdo->prepare("SELECT 1 FROM attachments WHERE meeting_id = ? AND file_url = ? LIMIT 1");
        $exists->execute([$meeting_id, $att['file_url']]);

        if (!$exists->fetch()) {
            $stmt = $pdo->prepare("INSERT INTO attachments (meeting_id, file_name, file_url, file_type) VALUES (?, ?, ?, ?)");
            $stmt->execute([$meeting_id, $att['file_name'], $att['file_url'], $att['file_type']]);
        }
    }
}


/**
 * Extract document/attachment links from event page HTML.
 *
 * Matches three patterns:
 *   1. eHawaii document downloads (hnldoc.ehawaii.gov/hnldoc/document-download)
 *   2. Absolute URLs on honolulu.gov ending in document extensions
 *   3. Relative URLs ending in document extensions
 *
 * @param string $html HTML content of the event detail container
 * @return array List of attachment arrays with file_url, file_type, file_name
 */
function extract_attachment_links(string $html): array
{
    $attachments = [];
    $seen_urls   = [];

    // Pattern 1: eHawaii document download links (agenda PDFs hosted on hnldoc.ehawaii.gov)
    // Uses (.*?) instead of ([^<]+) because anchor content often contains <span>/<strong> wrappers
    if (preg_match_all('#href="(https?://hnldoc\.ehawaii\.gov/hnldoc/document-download[^"]*)"[^>]*>(.*?)</a>#si', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $url  = html_entity_decode($m[1]);
            $name = trim(strip_tags($m[2]));
            if ($name !== '' && !isset($seen_urls[$url])) {
                $seen_urls[$url] = true;
                $attachments[]   = [
                    'file_url'  => $url,
                    'file_type' => 'pdf',
                    'file_name' => $name,
                ];
            }
        }
    }

    // Pattern 2: Absolute URLs on honolulu.gov with document extensions
    if (preg_match_all('#href="(https?://[^"]*\.honolulu\.gov[^"]*\.(pdf|doc|docx|xls|xlsx))"[^>]*>(.*?)</a>#si', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $url  = html_entity_decode($m[1]);
            $name = trim(strip_tags($m[3]));
            if ($name !== '' && !isset($seen_urls[$url])) {
                $seen_urls[$url] = true;
                $attachments[]   = [
                    'file_url'  => $url,
                    'file_type' => strtolower($m[2]),
                    'file_name' => $name,
                ];
            }
        }
    }

    // Pattern 3: Relative URLs with document extensions
    if (preg_match_all('#href="(/[^"]*\.(pdf|doc|docx|xls|xlsx))"[^>]*>(.*?)</a>#si', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $url  = 'https://www.honolulu.gov' . html_entity_decode($m[1]);
            $name = trim(strip_tags($m[3]));
            if ($name !== '' && !isset($seen_urls[$url])) {
                $seen_urls[$url] = true;
                $attachments[]   = [
                    'file_url'  => $url,
                    'file_type' => strtolower($m[2]),
                    'file_name' => $name,
                ];
            }
        }
    }

    return $attachments;
}


/**
 * Record the scraper run in scraper_state.
 */
function record_hnl_boards_scrape_run(PDO $pdo, array $stats): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scraper_state (
            id INT AUTO_INCREMENT PRIMARY KEY,
            source VARCHAR(50) NOT NULL,
            last_run DATETIME NOT NULL,
            meetings_found INT DEFAULT 0,
            meetings_new INT DEFAULT 0,
            meetings_changed INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'success',
            error_message TEXT NULL,
            INDEX idx_source_status (source, status)
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO scraper_state (source, last_run, meetings_found, meetings_new, meetings_changed, status)
        VALUES ('honolulu_boards_scraper', NOW(), ?, ?, ?, 'success')
    ");
    $stmt->execute([
        $stats['items_found'],
        $stats['meetings_new'],
        $stats['meetings_updated'],
    ]);
}
