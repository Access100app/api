<?php
/**
 * Validate and backfill meeting dates for all scrapers.
 *
 * Re-parses raw_rss_data for eHawaii, NCO, and Honolulu Boards meetings
 * and corrects any rows where the stored meeting_date differs from what the
 * fixed parsers now produce. Maui meetings cannot be re-parsed (raw_rss_data
 * IS NULL) and are counted and noted separately.
 *
 * Usage:
 *   php api/scripts/validate-dates.php
 *   php api/scripts/validate-dates.php --dry-run
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../cron/parse_helpers_ehawaii.php';
require_once __DIR__ . '/../cron/parse_helpers_maui.php';

// ─── CLI Arguments ──────────────────────────────────────────────
$dry_run = in_array('--dry-run', $argv ?? [], true);

$start = microtime(true);

echo date('[Y-m-d H:i:s]') . " validate-dates starting" . ($dry_run ? ' (DRY RUN)' : '') . "...\n\n";

$pdo = get_db();

// ─── Stats ───────────────────────────────────────────────────────
$stats = [
    'total_checked'   => 0,
    'total_corrected' => 0,
    'total_unchanged' => 0,
    'maui_skipped'    => 0,
    'parse_failures'  => 0,
];

// =====================================================================
// eHawaii
// =====================================================================

$stmt = $pdo->prepare(
    "SELECT id, meeting_date, raw_rss_data, source
       FROM meetings
      WHERE source = ?
        AND raw_rss_data IS NOT NULL
      ORDER BY meeting_date DESC"
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

    $result             = reparse_ehawaii_date($raw);
    $reparsed_date      = $result['date'];
    $was_pubdate_fallback = $result['was_pubdate_fallback'];

    if ($reparsed_date === null) {
        echo "  [PARSE FAIL] ehawaii | meeting_id={$row['id']} | could not extract date\n";
        $stats['parse_failures']++;
        continue;
    }

    $stats['total_checked']++;

    if ($reparsed_date !== $row['meeting_date']) {
        $stats['total_corrected']++;
        $fallback_label = $was_pubdate_fallback ? 'yes' : 'no';
        $prefix         = $dry_run ? '  WOULD UPDATE:' : '  CORRECTED:';
        echo "{$prefix} ehawaii | meeting_id={$row['id']} | old: {$row['meeting_date']} | new: {$reparsed_date} | pubdate_fallback: {$fallback_label}\n";

        if (!$dry_run) {
            $upd = $pdo->prepare("UPDATE meetings SET meeting_date = ? WHERE id = ?");
            $upd->execute([$reparsed_date, $row['id']]);
        }
    } else {
        $stats['total_unchanged']++;
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

    $result             = reparse_nco_hnlboards_date($raw);
    $reparsed_date      = $result['date'];
    $was_pubdate_fallback = $result['was_pubdate_fallback'];

    if ($reparsed_date === null) {
        echo "  [PARSE FAIL] nco | meeting_id={$row['id']} | could not extract date\n";
        $stats['parse_failures']++;
        continue;
    }

    $stats['total_checked']++;

    if ($reparsed_date !== $row['meeting_date']) {
        $stats['total_corrected']++;
        $fallback_label = $was_pubdate_fallback ? 'yes' : 'no';
        $prefix         = $dry_run ? '  WOULD UPDATE:' : '  CORRECTED:';
        echo "{$prefix} nco | meeting_id={$row['id']} | old: {$row['meeting_date']} | new: {$reparsed_date} | pubdate_fallback: {$fallback_label}\n";

        if (!$dry_run) {
            $upd = $pdo->prepare("UPDATE meetings SET meeting_date = ? WHERE id = ?");
            $upd->execute([$reparsed_date, $row['id']]);
        }
    } else {
        $stats['total_unchanged']++;
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

    $result             = reparse_nco_hnlboards_date($raw);
    $reparsed_date      = $result['date'];
    $was_pubdate_fallback = $result['was_pubdate_fallback'];

    if ($reparsed_date === null) {
        echo "  [PARSE FAIL] honolulu_boards | meeting_id={$row['id']} | could not extract date\n";
        $stats['parse_failures']++;
        continue;
    }

    $stats['total_checked']++;

    if ($reparsed_date !== $row['meeting_date']) {
        $stats['total_corrected']++;
        $fallback_label = $was_pubdate_fallback ? 'yes' : 'no';
        $prefix         = $dry_run ? '  WOULD UPDATE:' : '  CORRECTED:';
        echo "{$prefix} honolulu_boards | meeting_id={$row['id']} | old: {$row['meeting_date']} | new: {$reparsed_date} | pubdate_fallback: {$fallback_label}\n";

        if (!$dry_run) {
            $upd = $pdo->prepare("UPDATE meetings SET meeting_date = ? WHERE id = ?");
            $upd->execute([$reparsed_date, $row['id']]);
        }
    } else {
        $stats['total_unchanged']++;
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
$corrected_label = $dry_run
    ? $stats['total_corrected'] . " (DRY RUN — no writes)"
    : (string) $stats['total_corrected'];

echo "--- Summary ---\n";
echo "  Total checked:    {$stats['total_checked']}\n";
echo "  Total corrected:  {$corrected_label}\n";
echo "  Total unchanged:  {$stats['total_unchanged']}\n";
echo "  Maui skipped:     {$stats['maui_skipped']}  (raw_rss_data NULL — cannot re-parse)\n";
echo "  Parse failures:   {$stats['parse_failures']}\n";

$elapsed = round(microtime(true) - $start, 2);
echo "\n" . date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";


// =====================================================================
// Re-parse functions
// =====================================================================

/**
 * Re-parse the meeting date from a stored eHawaii raw_rss_data record.
 *
 * Mirrors the date-extraction logic in parse_rss_item() from
 * parse_helpers_ehawaii.php — primary "Date: YYYY/MM/DD" label, secondary
 * first-line "Month D, YYYY - ..." pattern, fallback to pubDate.
 *
 * @param  array $raw  Decoded raw_rss_data JSON (keys: description, pubDate, …)
 * @return array{date: string|null, was_pubdate_fallback: bool}
 */
function reparse_ehawaii_date(array $raw): array
{
    $was_pubdate_fallback = false;
    $date_str             = '';

    $desc_html = html_entity_decode($raw['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Primary: structured "Date: YYYY/MM/DD" label
    if (preg_match('/Date:\s*(\d{4}\/\d{2}\/\d{2})/i', $desc_html, $m)) {
        $date_str = str_replace('/', '-', $m[1]);
    }

    // Secondary: NCO-style first-line date ("April 21, 2026 - ..." or range)
    if (empty($date_str)) {
        $lines = preg_split('/<br\s*\/?>/i', $desc_html);
        $lines = array_values(array_filter(array_map(fn($l) => trim(strip_tags($l)), $lines)));
        if (!empty($lines[0])) {
            // Date range: "April 21, 2026 - April 28, 2026 - 7:00 pm..."
            if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-/i', $lines[0], $m)) {
                $dt = DateTime::createFromFormat('F j, Y', trim($m[1]));
                if ($dt) {
                    $date_str = $dt->format('Y-m-d');
                }
            // Single date: "April 21, 2026 - 7:00 pm..."
            } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-/i', $lines[0], $m)) {
                $dt = DateTime::createFromFormat('F j, Y', trim($m[1]));
                if ($dt) {
                    $date_str = $dt->format('Y-m-d');
                }
            }
        }
    }

    // Fallback: pubDate
    if (empty($date_str)) {
        $pub = $raw['pubDate'] ?? '';
        if (!empty($pub)) {
            $date_str             = date('Y-m-d', strtotime($pub));
            $was_pubdate_fallback = true;
        }
    }

    return [
        'date'                => $date_str !== '' ? $date_str : null,
        'was_pubdate_fallback' => $was_pubdate_fallback,
    ];
}

/**
 * Re-parse the meeting date from a stored NCO or Honolulu Boards raw_rss_data record.
 *
 * Mirrors the date-extraction logic in parse_nco_rss_item() /
 * parse_hnl_boards_rss_item() — first line of the description is expected
 * to be "Month D, YYYY - Time" or a date range; falls back to pubDate.
 *
 * @param  array $raw  Decoded raw_rss_data JSON (keys: description, pubDate, …)
 * @return array{date: string|null, was_pubdate_fallback: bool}
 */
function reparse_nco_hnlboards_date(array $raw): array
{
    $was_pubdate_fallback = false;

    $desc_html = html_entity_decode($raw['description'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $lines = preg_split('/<br\s*\/?>/i', $desc_html);
    $lines = array_values(array_filter(array_map(fn($l) => trim(strip_tags($l)), $lines)));

    $date_time_line = $lines[0] ?? '';

    // Date range: "April 21, 2026 - April 28, 2026 - ..."
    if (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-\s*[A-Z][a-z]+ \d{1,2}, \d{4}\s*-/i', $date_time_line, $m)) {
        $date_part = $m[1];
    // Single date: "April 21, 2026 - ..."
    } elseif (preg_match('/^([A-Z][a-z]+ \d{1,2}, \d{4})\s*-/i', $date_time_line, $m)) {
        $date_part = $m[1];
    } else {
        $date_part = $date_time_line;
    }

    $dt = DateTime::createFromFormat('F j, Y', trim($date_part));

    if ($dt) {
        return [
            'date'                => $dt->format('Y-m-d'),
            'was_pubdate_fallback' => false,
        ];
    }

    // Fallback: pubDate
    $pub = $raw['pubDate'] ?? '';
    if (!empty($pub)) {
        return [
            'date'                => date('Y-m-d', strtotime($pub)),
            'was_pubdate_fallback' => true,
        ];
    }

    return [
        'date'                => null,
        'was_pubdate_fallback' => false,
    ];
}
