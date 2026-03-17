<?php
/**
 * Access100 API - Maui County Legistar Scraper
 *
 * Polls the Maui County Legistar API for council and committee meetings.
 * Returns JSON — no RSS parsing needed.
 *
 * API: https://webapi.legistar.com/v1/mauicounty/events
 *
 * Crontab (hourly, 6 min after eHawaii scraper):
 *   6 * * * * php /path/to/api/cron/scrape-maui-legistar.php >> /var/log/access100-scrape-maui.log 2>&1
 *
 * Manual:
 *   php api/cron/scrape-maui-legistar.php
 *   php api/cron/scrape-maui-legistar.php --dry-run
 *   php api/cron/scrape-maui-legistar.php --limit=5
 *   php api/cron/scrape-maui-legistar.php --skip-agendas
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/summarizer.php';
require_once __DIR__ . '/parse_helpers_maui.php';

// ─── Constants ──────────────────────────────────────────────────
define('MAUI_LEGISTAR_BASE', 'https://webapi.legistar.com/v1/mauicounty');
define('MAUI_USER_AGENT', 'Access100-Scraper/1.0 (+https://civi.me)');

// ─── CLI Arguments ──────────────────────────────────────────────
$dry_run      = in_array('--dry-run', $argv ?? [], true);
$audit_mode   = in_array('--audit', $argv ?? [], true);
$skip_agendas = in_array('--skip-agendas', $argv ?? [], true);
$limit        = 0;

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
}

$start = microtime(true);
$stats = [
    'events_found'     => 0,
    'meetings_new'     => 0,
    'meetings_updated' => 0,
    'agendas_fetched'  => 0,
    'summarized'       => 0,
    'skipped'          => 0,
    'errors'           => 0,
];

echo date('[Y-m-d H:i:s]') . " Maui Legistar scraper starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n";

try {
    $pdo = get_db();

    // ─── Audit Mode ─────────────────────────────────────────────────
    if ($audit_mode) {
        echo date('[Y-m-d H:i:s]') . " Maui Legistar audit mode starting...\n";
        echo "  Assessing timezone shift evidence in stored meeting_date values...\n\n";

        // First: check whether raw_rss_data is populated for Maui meetings
        $raw_check = $pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN raw_rss_data IS NOT NULL THEN 1 ELSE 0 END) AS with_raw
            FROM meetings WHERE source = 'maui_legistar'
        ");
        $raw_row = $raw_check->fetch();
        $total_maui = (int) $raw_row['total'];
        $with_raw   = (int) $raw_row['with_raw'];
        echo "  Maui meetings total: {$total_maui}\n";
        echo "  With raw_rss_data: {$with_raw}\n";

        if ($with_raw === 0) {
            echo "  Note: raw_rss_data is NULL for all Maui meetings (expected — Maui uses JSON API, not RSS).\n";
            echo "  Falling back to heuristic timezone shift check.\n\n";
        }

        // Heuristic: A UTC→HST shift would cause meetings scheduled at or after midnight UTC
        // (i.e., afternoon in Hawaii) to appear one day earlier.
        // Check: are there any meeting_date values that fall on a Saturday or Sunday?
        // Government meetings are rarely scheduled on weekends — if any exist, flag for manual review.
        $stmt = $pdo->query("
            SELECT id, title, meeting_date, meeting_time
            FROM meetings
            WHERE source = 'maui_legistar'
              AND meeting_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            ORDER BY meeting_date DESC
            LIMIT 200
        ");
        $meetings = $stmt->fetchAll();
        echo "  Recent meetings checked (last 90 days): " . count($meetings) . "\n";

        $weekend_dates = [];

        foreach ($meetings as $row) {
            $dow = date('N', strtotime($row['meeting_date'])); // 6=Sat, 7=Sun
            if ($dow >= 6) {
                $weekend_dates[] = sprintf(
                    '  [MAUI] meeting_id=%d | %s (%s) | %s',
                    $row['id'],
                    $row['meeting_date'],
                    date('l', strtotime($row['meeting_date'])),
                    substr($row['title'], 0, 60)
                );
            }
        }

        echo "\n--- Maui Legistar ---\n";
        echo "  Timezone assessment: UTC→HST shift evidence check\n";
        echo "  Meetings checked: " . count($meetings) . "\n";
        echo "  Meetings on weekend (potential UTC shift): " . count($weekend_dates) . "\n";
        foreach ($weekend_dates as $line) echo $line . "\n";

        if (count($weekend_dates) > 0) {
            echo "  Verdict: " . count($weekend_dates) . " weekend-date meetings found — manual review recommended\n";
        } else {
            echo "  Verdict: no timezone shift evidence in stored data (no weekend dates found)\n";
        }

        echo "  Note: Definitive confirmation requires live Legistar API re-fetch — see RESEARCH.md\n";

        $elapsed = round(microtime(true) - $start, 2);
        echo "\n" . date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";
        exit(0);
    }

    // ─── 1. Build body → council_id map ─────────────────────────
    $body_map = build_maui_body_map($pdo);

    if (empty($body_map)) {
        echo "  ERROR: No Maui County councils found in database.\n";
        echo "  Run seed-maui-councils.php first.\n";
        exit(1);
    }
    echo "  Body map: " . count($body_map) . " bodies mapped\n";

    // ─── 2. Fetch events from Legistar API ──────────────────────
    $events = fetch_maui_events();
    if ($events === null) {
        echo "  ERROR: Failed to fetch Maui Legistar events.\n";
        exit(1);
    }

    $stats['events_found'] = count($events);
    echo "  Events found: {$stats['events_found']}\n";

    if ($stats['events_found'] === 0) {
        echo "  No events — nothing to do.\n";
        record_maui_scrape_run($pdo, $stats);
        exit(0);
    }

    // ─── 3. Process each event ──────────────────────────────────
    $processed = 0;

    foreach ($events as $event) {
        if ($limit > 0 && $processed >= $limit) {
            echo "  Limit reached ({$limit}), stopping.\n";
            break;
        }

        $parsed = map_legistar_event($event, $body_map);
        if ($parsed === null) {
            $stats['skipped']++;
            continue;
        }

        // Check for existing meeting
        $existing = find_existing_maui_meeting($pdo, $parsed);

        if ($existing) {
            if (has_maui_meeting_changed($existing, $parsed)) {
                if ($dry_run) {
                    echo "    UPDATE: {$parsed['title']} ({$parsed['meeting_date']})\n";
                } else {
                    update_maui_meeting($pdo, $existing['id'], $parsed);
                }
                $stats['meetings_updated']++;
            }
        } else {
            // New meeting
            if ($dry_run) {
                echo "    NEW: {$parsed['title']} ({$parsed['meeting_date']})\n";
            } else {
                $meeting_id = insert_maui_meeting($pdo, $parsed);

                // Fetch agenda PDF if available
                if ($meeting_id && !$skip_agendas && !empty($event['EventAgendaFile'])) {
                    $agenda_text = fetch_agenda_pdf($event['EventAgendaFile']);
                    if ($agenda_text) {
                        apply_maui_agenda($pdo, $meeting_id, $agenda_text);
                        $stats['agendas_fetched']++;
                    }
                    usleep(500000); // 0.5s between PDF fetches
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
            usleep(1000000);
        }
    }

    // ─── 4. Record scraper run ──────────────────────────────────
    if (!$dry_run) {
        record_maui_scrape_run($pdo, $stats);
    }

} catch (PDOException $e) {
    error_log('Maui Legistar scraper fatal DB error: ' . $e->getMessage());
    echo "  FATAL ERROR: " . $e->getMessage() . "\n";
}

// ─── Done ────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $start, 2);
echo "\n  Events found:      {$stats['events_found']}\n";
echo "  Meetings new:      {$stats['meetings_new']}\n";
echo "  Meetings updated:  {$stats['meetings_updated']}\n";
echo "  Agendas fetched:   {$stats['agendas_fetched']}\n";
echo "  Summarized:        {$stats['summarized']}\n";
echo "  Skipped:           {$stats['skipped']}\n";
echo "  Errors:            {$stats['errors']}\n";
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";


// =====================================================================
// Core Functions
// =====================================================================

/**
 * Build a map of Legistar BodyName → council_id.
 *
 * Loads all child councils under "County of Maui".
 */
function build_maui_body_map(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT c.id, c.name
        FROM councils c
        JOIN councils p ON c.parent_id = p.id
        WHERE p.name = 'County of Maui'
          AND c.is_active = 1
    ");

    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[$row['name']] = (int) $row['id'];
    }

    return $map;
}


/**
 * Fetch future events from the Maui County Legistar API.
 *
 * @return array|null Array of event objects, or null on failure
 */
function fetch_maui_events(): ?array
{
    $today = date('Y-m-d');
    $url = MAUI_LEGISTAR_BASE . '/events'
        . '?$top=200'
        . '&$orderby=EventDate+desc'
        . '&$filter=EventDate+ge+datetime%27' . $today . '%27';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => MAUI_USER_AGENT,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200 || empty($response)) {
        error_log("Maui Legistar scraper: API fetch failed (HTTP {$http_code}): {$err}");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        error_log('Maui Legistar scraper: Invalid JSON from API');
        return null;
    }

    return $data;
}


/**
 * Map a Legistar event to our meeting format.
 *
 * @return array|null Normalized meeting data, or null if unmappable
 */
function map_legistar_event(array $event, array $body_map): ?array
{
    $event_id   = $event['EventId'] ?? null;
    $body_name  = trim($event['EventBodyName'] ?? '');
    $event_date = $event['EventDate'] ?? null;

    if ($event_id === null || empty($body_name) || empty($event_date)) {
        return null;
    }

    // Map body to council
    $council_id = $body_map[$body_name] ?? null;
    if ($council_id === null) {
        error_log("Maui Legistar scraper: unmatched body: {$body_name}");
        return null;
    }

    // Parse date (format: "2026-03-04T00:00:00") using explicit Pacific/Honolulu timezone
    $meeting_date = parse_maui_date($event_date);
    if ($meeting_date === null) {
        error_log("Maui Legistar: skipping event with unparseable date: {$event_date}");
        return null;
    }

    // Parse time (format: "10:00 AM" or empty)
    $meeting_time = null;
    if (!empty($event['EventTime'])) {
        $ts = strtotime($event['EventTime']);
        if ($ts) {
            $meeting_time = date('H:i:s', $ts);
        }
    }

    // Location
    $location = trim($event['EventLocation'] ?? '');
    $location_venue = '';
    $location_address = '';
    $location_city = '';

    if (!empty($location)) {
        // Try to split location into parts (often "Venue, Address, City")
        $loc_parts = array_map('trim', explode(',', $location));
        if (count($loc_parts) >= 1) $location_venue  = $loc_parts[0];
        if (count($loc_parts) >= 2) $location_address = $loc_parts[1];
        if (count($loc_parts) >= 3) $location_city    = $loc_parts[2];
    }

    // Virtual meeting link from location text
    $zoom_link = null;
    if (preg_match('#https?://[^\s"<]*(?:teams\.microsoft|zoom\.us|webex\.com)[^\s"<]+#i', $location, $m)) {
        $zoom_link = $m[0];
    }

    // Build title
    $title = $body_name;
    if (!empty($event['EventComment'])) {
        $title .= ' - ' . trim($event['EventComment']);
    }

    // Build description
    $desc_parts = [];
    if (!empty($event['EventAgendaStatusName'])) {
        $desc_parts[] = 'Agenda Status: ' . $event['EventAgendaStatusName'];
    }
    if (!empty($event['EventComment'])) {
        $desc_parts[] = $event['EventComment'];
    }
    $description = implode('. ', $desc_parts);

    // Detect cancellations
    $status = 'active';
    if (preg_match('/\bCANCEL(?:LED)?\b/i', $title) || preg_match('/\bCANCEL(?:LED)?\b/i', $description)) {
        $status = 'cancelled';
    }

    // Detail URL
    $detail_url = $event['EventInSiteURL'] ?? '';

    // State ID: "maui-{EventId}" — unique and stable
    $state_id = 'maui-' . $event_id;

    return [
        'state_id'          => $state_id,
        'external_id'       => $detail_url ?: (string) $event_id,
        'council_id'        => $council_id,
        'title'             => $title,
        'description'       => $description,
        'location'          => $location,
        'location_venue'    => $location_venue,
        'location_address'  => $location_address,
        'location_city'     => $location_city,
        'meeting_date'      => $meeting_date,
        'meeting_time'      => $meeting_time,
        'detail_url'        => $detail_url,
        'zoom_link'         => $zoom_link,
        'status'            => $status,
        'source'            => 'maui_legistar',
    ];
}


/**
 * Find an existing Maui meeting by state_id or detail_url.
 */
function find_existing_maui_meeting(PDO $pdo, array $parsed): ?array
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
function has_maui_meeting_changed(array $existing, array $parsed): bool
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
 * Insert a new Maui meeting.
 *
 * @return int|null The new meeting ID, or null on failure
 */
function insert_maui_meeting(PDO $pdo, array $parsed): ?int
{
    $stmt = $pdo->prepare("
        INSERT INTO meetings
            (state_id, external_id, council_id, title, description, location,
             location_venue, location_address, location_city,
             meeting_date, meeting_time, detail_url, zoom_link, status, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'maui_legistar')
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
            $parsed['zoom_link'],
            $parsed['status'],
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            return null;
        }
        error_log("Maui Legistar scraper: insert failed for {$parsed['state_id']}: " . $e->getMessage());
        return null;
    }
}


/**
 * Update an existing Maui meeting with new data.
 */
function update_maui_meeting(PDO $pdo, int $meeting_id, array $parsed): void
{
    $stmt = $pdo->prepare("
        UPDATE meetings
        SET title = ?, description = ?, location = ?,
            location_venue = ?, location_address = ?, location_city = ?,
            meeting_date = ?, meeting_time = ?, zoom_link = ?, status = ?
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
        $parsed['zoom_link'],
        $parsed['status'],
        $meeting_id,
    ]);
}


/**
 * Fetch and extract text from an agenda PDF.
 *
 * @return string|null Extracted text, or null on failure
 */
function fetch_agenda_pdf(string $pdf_url): ?string
{
    $ch = curl_init($pdf_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => MAUI_USER_AGENT,
    ]);

    $pdf_data  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200 || empty($pdf_data)) {
        error_log("Maui Legistar scraper: PDF fetch failed for {$pdf_url} (HTTP {$http_code}): {$err}");
        return null;
    }

    // Write to temp file and extract with pdftotext
    $tmp = tempnam(sys_get_temp_dir(), 'maui_agenda_');
    file_put_contents($tmp, $pdf_data);

    $text = '';
    $output = [];
    $return_code = 0;
    exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($tmp)), $output, $return_code);
    unlink($tmp);

    if ($return_code === 0 && !empty($output)) {
        $text = implode("\n", $output);
    }

    return !empty(trim($text)) ? trim($text) : null;
}


/**
 * Apply agenda text to a meeting record.
 */
function apply_maui_agenda(PDO $pdo, int $meeting_id, string $agenda_text): void
{
    $stmt = $pdo->prepare("
        UPDATE meetings
        SET full_agenda_text = ?,
            description = CASE WHEN LENGTH(description) < LENGTH(?) THEN ? ELSE description END
        WHERE id = ?
    ");
    $stmt->execute([$agenda_text, $agenda_text, $agenda_text, $meeting_id]);
}


/**
 * Record the scraper run in scraper_state.
 */
function record_maui_scrape_run(PDO $pdo, array $stats): void
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
        VALUES ('maui_scraper', NOW(), ?, ?, ?, 'success')
    ");
    $stmt->execute([
        $stats['events_found'],
        $stats['meetings_new'],
        $stats['meetings_updated'],
    ]);
}
