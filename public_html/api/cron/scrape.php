<?php
/**
 * Access100 API - eHawaii Calendar RSS Scraper
 *
 * Polls RSS feeds from calendar.ehawaii.gov for each active council,
 * parses new/updated meetings, fetches detail pages for attachments
 * and full agenda text, and upserts into the database.
 *
 * Crontab (every 15 minutes):
 *   Every 15 min: php /path/to/api/cron/scrape.php >> /var/log/access100-scrape.log 2>&1
 *
 * Manual:
 *   php api/cron/scrape.php
 *   php api/cron/scrape.php --dry-run
 *   php api/cron/scrape.php --limit=10
 *   php api/cron/scrape.php --council=42
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/summarizer.php';
require_once __DIR__ . '/parse_helpers_ehawaii.php';

// ─── CLI Arguments ──────────────────────────────────────────────────
$dry_run    = in_array('--dry-run', $argv ?? [], true);
$audit_mode = in_array('--audit', $argv ?? [], true);
$limit      = 0;  // 0 = all active councils
$council_id = 0;  // 0 = all councils

foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int) $m[1];
    }
    if (preg_match('/^--council=(\d+)$/', $arg, $m)) {
        $council_id = (int) $m[1];
    }
}

$start = microtime(true);
$stats = [
    'councils_polled'  => 0,
    'councils_failed'  => 0,
    'meetings_found'   => 0,
    'meetings_new'     => 0,
    'meetings_updated' => 0,
    'attachments_new'  => 0,
    'summarized'       => 0,
];

echo date('[Y-m-d H:i:s]') . " Scraper starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n";

try {
    $pdo = get_db();

    // ─── Audit Mode ─────────────────────────────────────────────────
    if ($audit_mode) {
        echo date('[Y-m-d H:i:s]') . " eHawaii audit mode starting...\n";

        // Check what source values actually exist (pitfall: DEFAULT may not be 'ehawaii')
        $src_check = $pdo->query("SELECT DISTINCT source FROM meetings WHERE source LIKE '%hawaii%' OR source IS NULL LIMIT 20");
        $sources = array_column($src_check->fetchAll(), 'source');
        echo "  Source values found: " . implode(', ', array_map(fn($s) => $s ?? 'NULL', $sources)) . "\n";

        $stmt = $pdo->query("
            SELECT id, council_id, title, meeting_date, raw_rss_data
            FROM meetings
            WHERE source = 'ehawaii'
              AND raw_rss_data IS NOT NULL
            ORDER BY meeting_date DESC
            LIMIT 500
        ");
        $meetings = $stmt->fetchAll();
        echo "  Meetings with raw_rss_data (source=ehawaii): " . count($meetings) . "\n\n";

        $fallback_count = 0;
        $mismatch_count = 0;
        $mismatches = [];

        foreach ($meetings as $row) {
            $raw = json_decode($row['raw_rss_data'], true);
            $desc_html = html_entity_decode($raw['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Re-run the same regex from parse_rss_item()
            $date_str = '';
            if (preg_match('/Date:\s*(\d{4}\/\d{2}\/\d{2})/i', $desc_html, $dm)) {
                $date_str = str_replace('/', '-', $dm[1]);
            }

            $used_fallback = empty($date_str);
            if ($used_fallback && !empty($raw['pubDate'])) {
                $date_str = date('Y-m-d', strtotime($raw['pubDate']));
                $fallback_count++;
            }

            if (!empty($date_str) && $date_str !== $row['meeting_date']) {
                $mismatch_count++;
                $mismatches[] = sprintf(
                    '  [eHawaii] meeting_id=%d | stored: %s | would_parse: %s | title: %s',
                    $row['id'], $row['meeting_date'], $date_str, substr($row['title'], 0, 60)
                );
            }
        }

        echo "--- eHawaii ---\n";
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

    // ─── 1. Get councils to poll ────────────────────────────────────
    $sql = "SELECT id, name, rss_url FROM councils WHERE is_active = 1 AND rss_url != ''";
    $params = [];

    if ($council_id > 0) {
        $sql .= " AND id = ?";
        $params[] = $council_id;
    }

    $sql .= " ORDER BY last_polled_at ASC, id ASC";

    if ($limit > 0) {
        $sql .= " LIMIT ?";
        $params[] = $limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $councils = $stmt->fetchAll();

    echo "  Councils to poll: " . count($councils) . "\n";

    // ─── 2. Poll each council's RSS feed ────────────────────────────
    foreach ($councils as $council) {
        try {
            $result = poll_council($pdo, $council, $dry_run);
            $stats['councils_polled']++;

            if ($result === false) {
                $stats['councils_failed']++;
            } else {
                $stats['meetings_found']   += $result['found'];
                $stats['meetings_new']     += $result['new'];
                $stats['meetings_updated'] += $result['updated'];
                $stats['attachments_new']  += $result['attachments'];
                $stats['summarized']       += $result['summarized'];
            }
        } catch (Exception $e) {
            $stats['councils_polled']++;
            $stats['councils_failed']++;
            error_log("Scraper: council {$council['id']} ({$council['name']}) error: " . $e->getMessage());
            echo "    [{$council['id']}] {$council['name']}: ERROR - " . $e->getMessage() . "\n";
        }

        // Brief pause between feeds to be polite
        if ($stats['councils_polled'] < count($councils)) {
            usleep(250000); // 0.25s
        }
    }

    // ─── 3. Record scraper run ──────────────────────────────────────
    if (!$dry_run) {
        record_scrape_run($pdo, $stats);
    }

} catch (PDOException $e) {
    error_log('Scraper fatal DB error: ' . $e->getMessage());
    echo "  FATAL ERROR: " . $e->getMessage() . "\n";
}

// ─── Done ────────────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $start, 2);
echo "\n  Councils polled: {$stats['councils_polled']}\n";
echo "  Councils failed: {$stats['councils_failed']}\n";
echo "  Meetings found:  {$stats['meetings_found']}\n";
echo "  Meetings new:    {$stats['meetings_new']}\n";
echo "  Meetings updated:{$stats['meetings_updated']}\n";
echo "  Attachments new: {$stats['attachments_new']}\n";
echo "  Summarized:     {$stats['summarized']}\n";
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";


// =====================================================================
// Core Functions
// =====================================================================

/**
 * Poll a single council's RSS feed.
 *
 * @return array|false Stats array on success, false on failure
 */
function poll_council(PDO $pdo, array $council, bool $dry_run): array|false
{
    $result = ['found' => 0, 'new' => 0, 'updated' => 0, 'attachments' => 0, 'summarized' => 0];

    // Fetch RSS feed — encode spaces in URLs (common in eHawaii URLs)
    $rss_url = str_replace(' ', '%20', $council['rss_url']);
    $xml = fetch_rss($rss_url);
    if ($xml === null) {
        echo "    [{$council['id']}] {$council['name']}: FAILED to fetch RSS\n";
        return false;
    }

    $items = $xml->channel->item ?? [];
    $item_count = count($items);
    $result['found'] = $item_count;

    if ($item_count === 0) {
        // Empty feed is normal — update last_polled_at
        if (!$dry_run) {
            update_poll_state($pdo, $council['id'], null, null, 0, 0);
            $pdo->prepare("UPDATE councils SET last_polled_at = NOW() WHERE id = ?")
                ->execute([$council['id']]);
        }
        return $result;
    }

    $last_guid = null;
    $last_date = null;

    foreach ($items as $item) {
        $parsed = parse_rss_item($item, $council['id']);
        if ($parsed === null) {
            continue;
        }

        // Track the most recent item for poll_state
        if ($last_guid === null) {
            $last_guid = $parsed['guid'];
            $last_date = $parsed['pub_date'];
        }

        // Check if this meeting already exists (by detail_url or state_id)
        $existing = find_existing_meeting($pdo, $parsed);

        if ($existing) {
            // Check if content changed
            if (has_meeting_changed($existing, $parsed)) {
                if ($dry_run) {
                    echo "    [{$council['id']}] UPDATE: {$parsed['title']} ({$parsed['meeting_date']})\n";
                } else {
                    update_meeting($pdo, $existing['id'], $parsed);
                }
                $result['updated']++;
            }
        } else {
            // New meeting
            if ($dry_run) {
                echo "    [{$council['id']}] NEW: {$parsed['title']} ({$parsed['meeting_date']})\n";
            } else {
                $meeting_id = insert_meeting($pdo, $parsed);
                // Fetch detail page for attachments
                if ($meeting_id && !empty($parsed['detail_url'])) {
                    $att_count = scrape_attachments($pdo, $meeting_id, $parsed['detail_url']);
                    $result['attachments'] += $att_count;
                }
                // Generate AI summary immediately
                if ($meeting_id) {
                    $summary = summarize_meeting($meeting_id);
                    if ($summary) {
                        $result['summarized']++;
                    }
                }
            }
            $result['new']++;
        }
    }

    // Update poll state and council timestamp
    if (!$dry_run) {
        update_poll_state($pdo, $council['id'], $last_guid, $last_date, $result['found'], $result['new']);
        $pdo->prepare("UPDATE councils SET last_polled_at = NOW(), meeting_count = (SELECT COUNT(*) FROM meetings WHERE council_id = ?) WHERE id = ?")
            ->execute([$council['id'], $council['id']]);
    }

    if ($result['new'] > 0 || $result['updated'] > 0) {
        echo "    [{$council['id']}] {$council['name']}: {$result['found']} items, {$result['new']} new, {$result['updated']} updated\n";
    }

    return $result;
}


/**
 * Fetch and parse an RSS feed URL.
 *
 * @return SimpleXMLElement|null
 */
function fetch_rss(string $url): ?SimpleXMLElement
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Access100-Scraper/1.0 (+https://civi.me)',
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200 || empty($response)) {
        error_log("Scraper: RSS fetch failed for {$url} (HTTP {$http_code}): {$err}");
        return null;
    }

    // Suppress XML warnings and parse
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    libxml_clear_errors();

    if ($xml === false) {
        error_log("Scraper: Invalid XML from {$url}");
        return null;
    }

    return $xml;
}


// parse_rss_item() is defined in parse_helpers_ehawaii.php


/**
 * Find an existing meeting by state_id or detail_url.
 */
function find_existing_meeting(PDO $pdo, array $parsed): ?array
{
    // Try state_id first (most reliable)
    if (!empty($parsed['state_id'])) {
        $stmt = $pdo->prepare("SELECT id, title, description, location, meeting_date, meeting_time, zoom_link, status FROM meetings WHERE state_id = ? LIMIT 1");
        $stmt->execute([$parsed['state_id']]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    // Fall back to detail_url
    if (!empty($parsed['detail_url'])) {
        $stmt = $pdo->prepare("SELECT id, title, description, location, meeting_date, meeting_time, zoom_link, status FROM meetings WHERE detail_url = ? LIMIT 1");
        $stmt->execute([$parsed['detail_url']]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    return null;
}


/**
 * Check if meeting data has changed compared to what's in the DB.
 */
function has_meeting_changed(array $existing, array $parsed): bool
{
    // Compare key fields
    if ($existing['title'] !== $parsed['title']) return true;
    if ($existing['location'] !== $parsed['location']) return true;
    if ($existing['meeting_date'] !== $parsed['meeting_date']) return true;

    // Time comparison (handle null)
    $existing_time = $existing['meeting_time'] ? substr($existing['meeting_time'], 0, 5) : null;
    $parsed_time = $parsed['meeting_time'] ? substr($parsed['meeting_time'], 0, 5) : null;
    if ($existing_time !== $parsed_time) return true;

    // Zoom link changed
    if (($existing['zoom_link'] ?? '') !== ($parsed['zoom_link'] ?? '')) return true;

    return false;
}


/**
 * Insert a new meeting into the database.
 *
 * @return int|null The new meeting ID, or null on failure
 */
function insert_meeting(PDO $pdo, array $parsed): ?int
{
    $stmt = $pdo->prepare("
        INSERT INTO meetings (state_id, external_id, council_id, title, description, location,
                              meeting_date, meeting_time, detail_url, zoom_link, status, raw_rss_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    try {
        $stmt->execute([
            $parsed['state_id'],
            $parsed['external_id'],
            $parsed['council_id'],
            $parsed['title'],
            $parsed['description'],
            $parsed['location'],
            $parsed['meeting_date'],
            $parsed['meeting_time'],
            $parsed['detail_url'],
            $parsed['zoom_link'],
            $parsed['status'],
            $parsed['raw_rss'],
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        // Duplicate key (race condition) — not an error
        if ($e->getCode() == 23000) {
            return null;
        }
        error_log("Scraper: insert failed for state_id {$parsed['state_id']}: " . $e->getMessage());
        return null;
    }
}


/**
 * Update an existing meeting with new data from RSS.
 */
function update_meeting(PDO $pdo, int $meeting_id, array $parsed): void
{
    $stmt = $pdo->prepare("
        UPDATE meetings
        SET title = ?, description = ?, location = ?, meeting_date = ?,
            meeting_time = ?, zoom_link = ?, raw_rss_data = ?,
            status = 'updated'
        WHERE id = ?
    ");
    $stmt->execute([
        $parsed['title'],
        $parsed['description'],
        $parsed['location'],
        $parsed['meeting_date'],
        $parsed['meeting_time'],
        $parsed['zoom_link'],
        $parsed['raw_rss'],
        $meeting_id,
    ]);
}


/**
 * Scrape the eHawaii detail page for attachment links.
 *
 * @return int Number of new attachments added
 */
function scrape_attachments(PDO $pdo, int $meeting_id, string $detail_url): int
{
    $ch = curl_init($detail_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Access100-Scraper/1.0 (+https://civi.me)',
    ]);

    $html      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err       = curl_error($ch);
    curl_close($ch);

    if ($err || $http_code !== 200 || empty($html)) {
        return 0;
    }

    // Find attachment links: /calendar/attachment/{id}/{filename.ext}
    $count = 0;
    if (preg_match_all('#href="(/calendar/attachment/\d+/[^"]+)"[^>]*>([^<]+)</a>#i', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $file_path = $match[1];
            $file_name = trim($match[2]);
            $file_url  = 'https://calendar.ehawaii.gov' . $file_path;

            // Determine file type from extension
            $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $file_type = $ext ?: 'unknown';

            // Insert if not already present (unique by meeting_id + file_url)
            $exists = $pdo->prepare("SELECT 1 FROM attachments WHERE meeting_id = ? AND file_url = ? LIMIT 1");
            $exists->execute([$meeting_id, $file_url]);

            if (!$exists->fetch()) {
                $stmt = $pdo->prepare("INSERT INTO attachments (meeting_id, file_name, file_url, file_type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$meeting_id, $file_name, $file_url, $file_type]);
                $count++;
            }
        }
    }

    return $count;
}


/**
 * Update the poll_state table for a council.
 */
function update_poll_state(PDO $pdo, int $council_id, ?string $last_guid, ?string $last_date, int $items_found, int $new_items): void
{
    $last_item_date = null;
    if ($last_date) {
        $ts = strtotime($last_date);
        $last_item_date = $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    $stmt = $pdo->prepare("
        INSERT INTO poll_state (council_id, last_item_guid, last_item_date, last_poll_at, items_found, new_items_count)
        VALUES (?, ?, ?, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE
            last_item_guid = VALUES(last_item_guid),
            last_item_date = VALUES(last_item_date),
            last_poll_at   = NOW(),
            items_found    = VALUES(items_found),
            new_items_count = VALUES(new_items_count)
    ");
    $stmt->execute([$council_id, $last_guid, $last_item_date, $items_found, $new_items]);
}


/**
 * Record the scraper run in scraper_state.
 */
function record_scrape_run(PDO $pdo, array $stats): void
{
    // Create scraper_state table if it doesn't exist
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
        VALUES ('rss_scraper', NOW(), ?, ?, ?, 'success')
    ");
    $stmt->execute([
        $stats['meetings_found'],
        $stats['meetings_new'],
        $stats['meetings_updated'],
    ]);
}
