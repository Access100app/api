<?php
/**
 * Access100 API - AI Topic Classification Cron
 *
 * Finds meetings without topic tags and classifies them using
 * the Claude API.
 *
 * Intended to run via cron every 30-60 minutes:
 *   * /30 * * * * php /path/to/api/cron/classify-topics.php >> /var/log/access100-classify-topics.log 2>&1
 *
 * Can also be run manually:
 *   php api/cron/classify-topics.php
 *   php api/cron/classify-topics.php --limit=5
 */

// Bootstrap
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../services/topic-classifier.php';

// Parse CLI args
$limit = 10;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = min((int) $m[1], 50);
    }
}

// Run
$start = microtime(true);
echo date('[Y-m-d H:i:s]') . " Topic classifier starting (limit={$limit})...\n";

$results = classify_pending_meetings($limit);

$elapsed = round(microtime(true) - $start, 2);
echo date('[Y-m-d H:i:s]') . " Done in {$elapsed}s. "
    . "Processed: {$results['processed']}, "
    . "Succeeded: {$results['succeeded']}, "
    . "Failed: {$results['failed']}\n";
