<?php
/**
 * Import meetings and councils from the production access100.app site
 * into the local Docker database.
 *
 * Usage: php api/scripts/import-from-production.php [--dry-run]
 */

$dry_run = in_array('--dry-run', $argv ?? []);
$base_url = 'https://access100.app/meetings/';

echo "=== Access100 Production Data Import ===\n";
echo $dry_run ? "[DRY RUN]\n\n" : "\n";

// ─── Database connection ─────────────────────────────────────────────
require_once __DIR__ . '/../config.php';
$pdo = get_db();

// ─── Step 1: Fetch councils from the dropdown ────────────────────────
echo "Fetching councils from production...\n";
$html = fetch_url($base_url);
if (!$html) {
    die("ERROR: Could not fetch $base_url\n");
}

// Parse council <option> tags: <option value="6"> Council Name </option>
preg_match_all('/<option\s+value="(\d+)"\s*>([^<]+)<\/option>/i', $html, $matches, PREG_SET_ORDER);

$councils = [];
foreach ($matches as $m) {
    $id = (int) $m[1];
    $name = trim(html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'));
    if ($id > 0 && $name !== '') {
        $councils[$id] = $name;
    }
}

echo "  Found " . count($councils) . " councils\n";

// ─── Step 2: Insert/update councils ──────────────────────────────────
if (!$dry_run) {
    // Clear existing test data
    $pdo->exec("DELETE FROM meetings");
    $pdo->exec("DELETE FROM councils");
    echo "  Cleared existing test data\n";

    $stmt = $pdo->prepare("INSERT INTO councils (id, name, is_active) VALUES (?, ?, 1)
                           ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1");
    foreach ($councils as $id => $name) {
        $stmt->execute([$id, $name]);
    }
    echo "  Inserted " . count($councils) . " councils\n";
} else {
    echo "  [DRY RUN] Would insert " . count($councils) . " councils\n";
    // Show first 10
    $i = 0;
    foreach ($councils as $id => $name) {
        if ($i++ >= 10) { echo "  ...\n"; break; }
        echo "    [$id] $name\n";
    }
}

// ─── Step 3: Fetch meetings from list page ───────────────────────────
echo "\nFetching meetings from production...\n";

// Get ALL meetings (no date filter to get everything)
$meetings = [];
$meeting_ids = [];

// Parse meeting cards from the HTML
// Pattern: date headers + meeting cards with detail links
preg_match_all('/detail\.php\?id=(\d+)/', $html, $id_matches);
$meeting_ids = array_unique($id_matches[1]);

echo "  Found " . count($meeting_ids) . " meeting IDs on list page\n";

// ─── Step 4: Fetch each meeting's detail page ────────────────────────
echo "\nFetching meeting details...\n";
$imported = 0;
$errors = 0;

foreach ($meeting_ids as $idx => $meeting_id) {
    $num = $idx + 1;
    $total = count($meeting_ids);

    $detail_html = fetch_url($base_url . "detail.php?id=" . $meeting_id);
    if (!$detail_html) {
        echo "  [$num/$total] ERROR: Could not fetch meeting $meeting_id\n";
        $errors++;
        continue;
    }

    $meeting = parse_meeting_detail($detail_html, $meeting_id, $councils);
    if (!$meeting) {
        echo "  [$num/$total] ERROR: Could not parse meeting $meeting_id\n";
        $errors++;
        continue;
    }

    if ($dry_run) {
        echo "  [$num/$total] {$meeting['title']} ({$meeting['meeting_date']} {$meeting['meeting_time']})\n";
    } else {
        insert_meeting($pdo, $meeting);
        $imported++;
        if ($num % 10 === 0 || $num === $total) {
            echo "  [$num/$total] imported...\n";
        }
    }

    // Be polite to the server
    usleep(100000); // 100ms
}

echo "\n=== Done ===\n";
echo "Councils: " . count($councils) . "\n";
echo "Meetings: " . ($dry_run ? count($meeting_ids) . " (dry run)" : "$imported imported") . "\n";
if ($errors) echo "Errors: $errors\n";

// ─── Helper functions ────────────────────────────────────────────────

function fetch_url(string $url): ?string {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'Access100-Importer/1.0',
        ],
    ]);
    $html = @file_get_contents($url, false, $ctx);
    return $html ?: null;
}

function parse_meeting_detail(string $html, int $state_id, array $councils): ?array {
    $m = [];
    $m['state_id'] = $state_id;

    // Title from <h2 class="meeting-title">
    if (preg_match('/<h2[^>]*class="meeting-title"[^>]*>(.+?)<\/h2>/s', $html, $match)) {
        $m['title'] = trim(strip_tags(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')));
    } else {
        return null;
    }

    // Council name from <p class="council-name">
    // There may be multiple — last one is the specific council
    if (preg_match_all('/<p[^>]*class="council-name"[^>]*>(.+?)<\/p>/s', $html, $matches)) {
        $council_name = trim(strip_tags(html_entity_decode(end($matches[1]), ENT_QUOTES, 'UTF-8')));
        // Find council ID
        $m['council_id'] = null;
        foreach ($councils as $cid => $cname) {
            if (strcasecmp($cname, $council_name) === 0) {
                $m['council_id'] = $cid;
                break;
            }
        }
        // Fuzzy match if exact didn't work
        if (!$m['council_id']) {
            foreach ($councils as $cid => $cname) {
                if (stripos($cname, $council_name) !== false || stripos($council_name, $cname) !== false) {
                    $m['council_id'] = $cid;
                    break;
                }
            }
        }
    }

    // Date and time from <p class="meeting-datetime">
    if (preg_match('/<p[^>]*class="meeting-datetime"[^>]*>\s*(.+?)\s*<\/p>/s', $html, $match)) {
        $datetime_text = trim(strip_tags($match[1]));
        // Format: "Monday, March 2, 2026 at 10:00 AM"
        if (preg_match('/(\w+,\s+\w+\s+\d+,\s+\d{4})\s+at\s+(\d+:\d+\s*[AP]M)/i', $datetime_text, $dt)) {
            $date = date('Y-m-d', strtotime($dt[1]));
            $time = date('H:i:s', strtotime($dt[2]));
            $m['meeting_date'] = $date;
            $m['meeting_time'] = $time;
        }
    }

    if (empty($m['meeting_date'])) return null;

    // Location from logistics box
    if (preg_match('/<strong>Location:<\/strong>\s*<span>(.+?)<\/span>/s', $html, $match)) {
        $m['location'] = trim(strip_tags(html_entity_decode($match[1], ENT_QUOTES, 'UTF-8')));
    } else {
        $m['location'] = '';
    }

    // Zoom link
    if (preg_match('/<strong>Virtual:<\/strong>\s*<span>.*?(https?:\/\/[^\s<"]+zoom[^\s<"]*)/si', $html, $match)) {
        $m['zoom_link'] = $match[1];
    } else {
        $m['zoom_link'] = null;
    }

    // Detail URL (official notice)
    if (preg_match('/href="(https?:\/\/calendar\.ehawaii\.gov[^"]+)"/', $html, $match)) {
        $m['detail_url'] = $match[1];
    } else {
        $m['detail_url'] = null;
    }

    // Full agenda text
    if (preg_match('/<div[^>]*class="agenda-content"[^>]*>(.*?)<\/div>/s', $html, $match)) {
        $agenda = trim(strip_tags(str_replace('<br />', "\n", $match[1])));
        $m['full_agenda_text'] = $agenda;
        $m['description'] = mb_substr($agenda, 0, 500);
    } else {
        $m['full_agenda_text'] = null;
        $m['description'] = null;
    }

    $m['status'] = 'scheduled';

    return $m;
}

function insert_meeting(PDO $pdo, array $m): void {
    $stmt = $pdo->prepare("
        INSERT INTO meetings (state_id, title, meeting_date, meeting_time, location, description,
                              full_agenda_text, detail_url, zoom_link, status, council_id)
        VALUES (:state_id, :title, :meeting_date, :meeting_time, :location, :description,
                :full_agenda_text, :detail_url, :zoom_link, :status, :council_id)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            meeting_date = VALUES(meeting_date),
            meeting_time = VALUES(meeting_time),
            location = VALUES(location),
            description = VALUES(description),
            full_agenda_text = VALUES(full_agenda_text),
            detail_url = VALUES(detail_url),
            zoom_link = VALUES(zoom_link),
            status = VALUES(status),
            council_id = VALUES(council_id)
    ");
    $stmt->execute([
        ':state_id'        => $m['state_id'],
        ':title'           => $m['title'],
        ':meeting_date'    => $m['meeting_date'],
        ':meeting_time'    => $m['meeting_time'],
        ':location'        => $m['location'],
        ':description'     => $m['description'],
        ':full_agenda_text'=> $m['full_agenda_text'],
        ':detail_url'      => $m['detail_url'],
        ':zoom_link'       => $m['zoom_link'],
        ':status'          => $m['status'],
        ':council_id'      => $m['council_id'],
    ]);
}
