<?php
/**
 * Summarize all future meetings that don't have summaries yet.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/summarizer.php';

$pdo = get_db();
$stmt = $pdo->query("
    SELECT m.id, m.title, m.meeting_date
    FROM meetings m
    WHERE m.meeting_date >= CURDATE()
      AND m.summary_text IS NULL
      AND (m.description IS NOT NULL AND m.description != '')
    ORDER BY m.meeting_date, m.meeting_time
");
$meetings = $stmt->fetchAll();

echo "Summarizing " . count($meetings) . " future meetings...\n\n";

$ok = 0;
$fail = 0;
foreach ($meetings as $i => $m) {
    $n = $i + 1;
    echo "[{$n}/" . count($meetings) . "] {$m['meeting_date']} - {$m['title']}\n";
    $summary = summarize_meeting((int) $m['id']);
    if ($summary) {
        $ok++;
        echo "  OK (" . strlen($summary) . " chars)\n";
    } else {
        $fail++;
        echo "  SKIPPED\n";
    }
}

echo "\nDone. Succeeded: {$ok}, Skipped: {$fail}\n";
