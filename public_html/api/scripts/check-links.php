<?php
/**
 * Check all stored URLs for liveness.
 * Reports broken links classified as permanent (404/410) or transient (5xx/timeout).
 * Read-only — never modifies the database.
 *
 * Usage:
 *   php api/scripts/check-links.php
 */

require_once __DIR__ . '/../config.php';

$start = microtime(true);

echo date('[Y-m-d H:i:s]') . " check-links starting...\n\n";

$pdo = get_db();

// =====================================================================
// Step 1 — Query both URL sets
// =====================================================================

$stmt = $pdo->query(
    "SELECT id, detail_url, title, meeting_date, source
       FROM meetings
      WHERE detail_url IS NOT NULL AND detail_url != ''
      ORDER BY meeting_date DESC"
);
$meetings = $stmt->fetchAll();

$stmt = $pdo->query(
    "SELECT a.id AS attachment_id, a.meeting_id, a.file_url, a.file_name
       FROM attachments a
      WHERE a.file_url IS NOT NULL AND a.file_url != ''
      ORDER BY a.meeting_id DESC"
);
$attachments = $stmt->fetchAll();

echo "--- Checking " . count($meetings) . " detail URLs + " . count($attachments) . " attachment URLs ---\n\n";

// =====================================================================
// Step 2 — Build unified URL list
// =====================================================================

$url_items = [];

foreach ($meetings as $row) {
    $url_items[] = [
        'url'         => $row['detail_url'],
        'meeting_id'  => (int) $row['id'],
        'url_type'    => 'detail_url',
        'http_status' => null,
        'curl_error'  => '',
    ];
}

foreach ($attachments as $row) {
    $url_items[] = [
        'url'         => $row['file_url'],
        'meeting_id'  => (int) $row['meeting_id'],
        'url_type'    => 'attachment',
        'http_status' => null,
        'curl_error'  => '',
    ];
}

// =====================================================================
// Step 3 — curl_multi batching
// =====================================================================

$batch_size    = 20;
$batches       = array_chunk($url_items, $batch_size, true);
$total_batches = count($batches);
$batch_num     = 0;

foreach ($batches as $batch) {
    $batch_num++;
    $multi   = curl_multi_init();
    $handles = [];

    foreach ($batch as $index => $item) {
        $ch = curl_init($item['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Access100-LinkChecker/1.0 (+https://civi.me)',
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$index] = $ch;
    }

    // Execute all requests in this batch
    do {
        $status = curl_multi_exec($multi, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);

    while ($running > 0) {
        if (curl_multi_select($multi, 1) === -1) {
            usleep(100000);
        }
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
    }

    // Collect results for this batch
    foreach ($handles as $index => $ch) {
        $url_items[$index]['http_status'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $url_items[$index]['curl_error']  = curl_error($ch);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }

    curl_multi_close($multi);

    echo "  Checked batch " . $batch_num . "/" . $total_batches . " (" . count($batch) . " URLs)...\n";

    // Throttle between batches to avoid hammering government servers
    if ($batch_num < $total_batches) {
        usleep(250000);
    }
}

// =====================================================================
// Step 4 — Classify results
// =====================================================================

$permanent = [];
$transient = [];
$stats     = [
    'total'     => count($url_items),
    'healthy'   => 0,
    'permanent' => 0,
    'transient' => 0,
];

foreach ($url_items as $item) {
    $http_code  = (int) $item['http_status'];
    $curl_error = $item['curl_error'];

    if ($http_code >= 200 && $http_code < 400 && empty($curl_error)) {
        // Healthy
        $stats['healthy']++;
    } elseif ($http_code === 404 || $http_code === 410) {
        // Permanent failure
        $permanent[] = $item;
        $stats['permanent']++;
    } else {
        // Transient: connection error (0), 5xx, 403, 429, or any other broken status
        $transient[] = $item;
        $stats['transient']++;
    }
}

// =====================================================================
// Step 5 — Print report
// =====================================================================

echo "\n\n=== BROKEN LINKS REPORT ===\n\n";

echo "--- Permanent Failures (404/410) --- " . count($permanent) . " URLs\n";
if (empty($permanent)) {
    echo "  (none)\n";
} else {
    foreach ($permanent as $item) {
        echo "  [PERMANENT] meeting_id={$item['meeting_id']} | status={$item['http_status']} | type={$item['url_type']} | url={$item['url']}\n";
    }
}

echo "\n--- Transient Failures (5xx / timeout / connection error) --- " . count($transient) . " URLs\n";
if (empty($transient)) {
    echo "  (none)\n";
} else {
    foreach ($transient as $item) {
        $error_note = !empty($item['curl_error']) ? " | error={$item['curl_error']}" : '';
        echo "  [TRANSIENT] meeting_id={$item['meeting_id']} | status={$item['http_status']} | type={$item['url_type']} | url={$item['url']}{$error_note}\n";
    }
}

echo "\n--- Summary ---\n";
echo "  Total checked:                    {$stats['total']}\n";
echo "  Healthy:                          {$stats['healthy']}\n";
echo "  Permanent (404/410):              {$stats['permanent']}\n";
echo "  Transient (5xx/timeout/error):    {$stats['transient']}\n";

$elapsed = round(microtime(true) - $start, 2);
echo "\n" . date('[Y-m-d H:i:s]') . " Done in {$elapsed}s.\n";
