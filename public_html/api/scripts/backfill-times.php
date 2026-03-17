<?php
/**
 * Backfill missing meeting_time (and meeting_time_end) values.
 *
 * Re-parses raw_rss_data for eHawaii, NCO, and Honolulu Boards meetings
 * and fills in meeting_time (and meeting_time_end for NCO/Honolulu Boards)
 * for any rows where those fields are currently NULL or empty and the
 * stored raw_rss_data contains a parseable time value.
 * Meetings that already have a non-null, non-empty meeting_time are never
 * modified. Maui meetings cannot be re-parsed (raw_rss_data IS NULL) and
 * are counted and noted separately.
 *
 * Usage:
 *   php api/scripts/backfill-times.php
 *   php api/scripts/backfill-times.php --dry-run
 */

require_once __DIR__ . '/../config.php';

// ─── CLI Arguments ──────────────────────────────────────────────
$dry_run = in_array('--dry-run', $argv ?? [], true);

$start = microtime(true);

echo date('[Y-m-d H:i:s]') . " backfill-times starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n\n";

$pdo = get_db();

// ─── Stats ───────────────────────────────────────────────────────
$stats = [
    'total_checked'  => 0,
    'total_filled'   => 0,
    'total_skipped'  => 0,
    'parse_failures' => 0,
    'maui_skipped'   => 0,
];

// =====================================================================
// eHawaii
// =====================================================================

$stmt = $pdo->prepare(
    "SELECT id, meeting_time, raw_rss_data, source
       FROM meetings
      WHERE source = ?
        AND raw_rss_data IS NOT NULL
      ORDER BY id DESC"
);
$stmt->execute(['ehawaii']);
$rows = $stmt->fetchAll();

echo "--- ehawaii (" . count($rows) . " rows) ---\n";

foreach ($rows as $row) {
    $raw = json_decode($row['raw_rss_data'], true);
    if (!is_array($raw)) {
        echo "  [PARSE FAIL] ehawaii | meeting_id={$row['id']} | could not decode raw_rss_data JSON\n";
        $stats['parse_failures']++;
        continue;
    }

    $result     = extract_ehawaii_time($raw);
    $time_start = $result['time_start'];

    if ($time_start === null) {
        echo "  [NO TIME] ehawaii | meeting_id={$row['id']} | no parseable time in raw_rss_data\n";
        $stats['parse_failures']++;
        continue;
    }

    // Check if meeting_time is already set — skip if so
    if (!empty($row['meeting_time'])) {
        echo "  [SKIP] ehawaii | meeting_id={$row['id']} | already has meeting_time={$row['meeting_time']}\n";
        $stats['total_skipped']++;
        continue;
    }

    $stats['total_checked']++;
    $stats['total_filled']++;

    if ($dry_run) {
        echo "  WOULD FILL: ehawaii | meeting_id={$row['id']} | time_start={$time_start} | time_end=NULL\n";
    } else {
        $upd = $pdo->prepare("UPDATE meetings SET meeting_time = ? WHERE id = ?");
        $upd->execute([$time_start, $row['id']]);
        echo "  FILLED: ehawaii | meeting_id={$row['id']} | time_start={$time_start} | time_end=NULL\n";
    }
}

echo "\n";

// =====================================================================
// NCO
// =====================================================================

$stmt->execute(['nco']);
$rows = $stmt->fetchAll();

echo "--- nco (" . count($rows) . " rows) ---\n";

foreach ($rows as $row) {
    $raw = json_decode($row['raw_rss_data'], true);
    if (!is_array($raw)) {
        echo "  [PARSE FAIL] nco | meeting_id={$row['id']} | could not decode raw_rss_data JSON\n";
        $stats['parse_failures']++;
        continue;
    }

    $result     = extract_nco_hnlboards_time($raw);
    $time_start = $result['time_start'];
    $time_end   = $result['time_end'];

    if ($time_start === null) {
        echo "  [NO TIME] nco | meeting_id={$row['id']} | no parseable time in raw_rss_data\n";
        $stats['parse_failures']++;
        continue;
    }

    // Check if meeting_time is already set — skip if so
    if (!empty($row['meeting_time'])) {
        echo "  [SKIP] nco | meeting_id={$row['id']} | already has meeting_time={$row['meeting_time']}\n";
        $stats['total_skipped']++;
        continue;
    }

    $stats['total_checked']++;
    $stats['total_filled']++;

    $time_end_label = $time_end ?? 'NULL';

    if ($dry_run) {
        echo "  WOULD FILL: nco | meeting_id={$row['id']} | time_start={$time_start} | time_end={$time_end_label}\n";
    } else {
        $upd = $pdo->prepare("UPDATE meetings SET meeting_time = ? WHERE id = ?");
        $upd->execute([$time_start, $row['id']]);
        echo "  FILLED: nco | meeting_id={$row['id']} | time_start={$time_start} | time_end={$time_end_label}\n";
    }
}

echo "\n";

// =====================================================================
// Honolulu Boards
// =====================================================================

$stmt->execute(['honolulu_boards']);
$rows = $stmt->fetchAll();

echo "--- honolulu_boards (" . count($rows) . " rows) ---\n";

foreach ($rows as $row) {
    $raw = json_decode($row['raw_rss_data'], true);
    if (!is_array($raw)) {
        echo "  [PARSE FAIL] honolulu_boards | meeting_id={$row['id']} | could not decode raw_rss_data JSON\n";
        $stats['parse_failures']++;
        continue;
    }

    $result     = extract_nco_hnlboards_time($raw);
    $time_start = $result['time_start'];
    $time_end   = $result['time_end'];

    if ($time_start === null) {
        echo "  [NO TIME] honolulu_boards | meeting_id={$row['id']} | no parseable time in raw_rss_data\n";
        $stats['parse_failures']++;
        continue;
    }

    // Check if meeting_time is already set — skip if so
    if (!empty($row['meeting_time'])) {
        echo "  [SKIP] honolulu_boards | meeting_id={$row['id']} | already has meeting_time={$row['meeting_time']}\n";
        $stats['total_skipped']++;
        continue;
    }

    $stats['total_checked']++;
    $stats['total_filled']++;

    $time_end_label = $time_end ?? 'NULL';

    if ($dry_run) {
        echo "  WOULD FILL: honolulu_boards | meeting_id={$row['id']} | time_start={$time_start} | time_end={$time_end_label}\n";
    } else {
        $upd = $pdo->prepare("UPDATE meetings SET meeting_time = ? WHERE id = ?");
        $upd->execute([$time_start, $row['id']]);
        echo "  FILLED: honolulu_boards | meeting_id={$row['id']} | time_start={$time_start} | time_end={$time_end_label}\n";
    }
}

echo "\n";

// =====================================================================
// Maui Legistar (cannot re-parse — raw_rss_data IS NULL for all rows)
// =====================================================================

$maui_stmt = $pdo->query("SELECT COUNT(*) FROM meetings WHERE source = 'maui_legistar'");
$maui_count = (int) $maui_stmt->fetchColumn();

echo "--- maui_legistar ({$maui_count} meetings) ---\n";
echo "  [maui_legistar] {$maui_count} meetings — raw_rss_data is NULL for all rows (Maui uses JSON API, not RSS). Skipping re-parse.\n";
$stats['maui_skipped'] += $maui_count;

echo "\n";

// ─── Summary ─────────────────────────────────────────────────────
$filled_label = $dry_run
    ? $stats['total_filled'] . " (DRY RUN — no writes)"
    : (string) $stats['total_filled'];

echo "--- Summary ---\n";
echo "  Total checked:      {$stats['total_checked']}\n";
echo "  Total filled:       {$filled_label}\n";
echo "  Total skipped:      {$stats['total_skipped']}  (already had meeting_time)\n";
echo "  Parse failures:     {$stats['parse_failures']}\n";
echo "  Maui skipped:       {$stats['maui_skipped']}  (raw_rss_data NULL)\n";

$elapsed = round(microtime(true) - $start, 2);
echo "\n" . date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";


// =====================================================================
// Time extraction functions
// =====================================================================

/**
 * Extract meeting_time_start and meeting_time_end from a stored NCO or
 * Honolulu Boards raw_rss_data record.
 *
 * Mirrors the time-extraction logic from parse_nco_rss_item() /
 * parse_hnl_boards_rss_item() — first line of the description is expected
 * to be "Month D, YYYY - H:MM am/pm - H:MM am/pm" (single date) or a
 * date range; time range regex applied to the time_part segment.
 *
 * @param  array $raw  Decoded raw_rss_data JSON (keys: description, pubDate, …)
 * @return array{time_start: string|null, time_end: string|null}
 */
function extract_nco_hnlboards_time(array $raw): array
{
    $desc_html  = html_entity_decode($raw['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $desc_lines = preg_split('/<br\s*\/?>/i', $desc_html);
    $desc_lines = array_values(array_filter(array_map('trim', array_map('strip_tags', $desc_lines))));

    $date_time_line = $desc_lines[0] ?? '';
    $time_part      = '';

    // Date range: "February 18, 2026 - March 18, 2026 - 6:30 pm - 8:30 pm"
    if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-\s*(.+)$/i', $date_time_line, $m)) {
        $time_part = $m[2];
    // Single date: "April 21, 2026 - 6:30 pm - 8:30 pm"
    } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*(.+)$/i', $date_time_line, $m)) {
        $time_part = $m[2];
    }

    $time_start = null;
    $time_end   = null;

    // Time range: "6:30 pm - 8:30 pm"
    if (preg_match('/(\d{1,2}:\d{2}\s*[ap]m)\s*-\s*(\d{1,2}:\d{2}\s*[ap]m)/i', $time_part, $tm)) {
        $start_ts = strtotime($tm[1]);
        $end_ts   = strtotime($tm[2]);
        if ($start_ts) $time_start = date('H:i:s', $start_ts);
        if ($end_ts)   $time_end   = date('H:i:s', $end_ts);
    }

    return [
        'time_start' => $time_start,
        'time_end'   => $time_end,
    ];
}

/**
 * Extract meeting_time_start from a stored eHawaii raw_rss_data record.
 *
 * Mirrors the time-extraction logic in parse_rss_item() from
 * parse_helpers_ehawaii.php — "Time: H:MM AM/PM" label in description.
 * eHawaii does not provide an end time.
 *
 * @param  array $raw  Decoded raw_rss_data JSON (keys: description, pubDate, …)
 * @return array{time_start: string|null}
 */
function extract_ehawaii_time(array $raw): array
{
    $desc_html = html_entity_decode($raw['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $time_start = null;

    if (preg_match('/Time:\s*(\d{1,2}:\d{2}\s*[AP]M)/i', $desc_html, $m)) {
        $ts = strtotime($m[1]);
        if ($ts) {
            $time_start = date('H:i:s', $ts);
        }
    }

    return [
        'time_start' => $time_start,
    ];
}
