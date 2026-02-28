<?php
/**
 * Classify all meetings that don't have topic tags yet.
 *
 * One-off bulk classification with progress output.
 *
 * Usage:
 *   php api/scripts/classify-all-meetings.php
 */
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/topic-classifier.php';

$pdo = get_db();
$stmt = $pdo->query("
    SELECT m.id, m.title, m.meeting_date, c.name AS council_name
    FROM meetings m
    JOIN councils c ON m.council_id = c.id
    WHERE NOT EXISTS (
        SELECT 1 FROM meeting_topics mt WHERE mt.meeting_id = m.id AND mt.source = 'ai'
    )
    AND (
        m.summary_text IS NOT NULL AND m.summary_text != ''
        OR m.full_agenda_text IS NOT NULL AND m.full_agenda_text != ''
        OR m.description IS NOT NULL AND m.description != ''
    )
    ORDER BY
        CASE WHEN m.meeting_date >= CURDATE() THEN 0 ELSE 1 END,
        m.meeting_date ASC
");
$meetings = $stmt->fetchAll();

echo "Classifying " . count($meetings) . " meetings by topic...\n\n";

$ok = 0;
$fail = 0;
foreach ($meetings as $i => $m) {
    $n = $i + 1;
    echo "[{$n}/" . count($meetings) . "] {$m['meeting_date']} - {$m['council_name']} - {$m['title']}\n";
    $result = classify_meeting_topics((int) $m['id']);
    if ($result) {
        $ok++;
        $slugs = array_column($result, 'slug');
        echo "  OK [" . implode(', ', $slugs) . "]\n";
    } else {
        $fail++;
        echo "  SKIPPED\n";
    }

    // Rate limiting
    if ($n < count($meetings)) {
        usleep(500000);
    }
}

echo "\nDone. Classified: {$ok}, Skipped: {$fail}\n";
