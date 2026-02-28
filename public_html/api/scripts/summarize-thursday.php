<?php
/**
 * One-off script: summarize all meetings for Thursday March 5, 2026.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/summarizer.php';

$pdo = get_db();
$stmt = $pdo->query("SELECT id, title FROM meetings WHERE meeting_date = '2026-03-05' ORDER BY meeting_time");
$meetings = $stmt->fetchAll();

echo "Summarizing " . count($meetings) . " meetings for Thursday March 5...\n\n";

foreach ($meetings as $i => $m) {
    $n = $i + 1;
    echo "[{$n}/" . count($meetings) . "] {$m['title']}\n";
    $summary = summarize_meeting((int) $m['id']);
    if ($summary) {
        echo "  OK (" . strlen($summary) . " chars)\n";
        echo "  ---\n";
        // Show first 200 chars of summary
        echo "  " . substr($summary, 0, 200) . "...\n\n";
    } else {
        echo "  SKIPPED (no agenda text or too short)\n\n";
    }
}
echo "Done.\n";
