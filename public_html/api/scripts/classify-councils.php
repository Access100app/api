<?php
/**
 * Access100 API - AI Council Topic Classifier
 *
 * Classifies all councils into topics using the Claude API.
 * Each council is mapped to 1-4 topics with primary/secondary relevance.
 * Outputs a CSV for human review.
 *
 * Usage:
 *   php api/scripts/classify-councils.php [--dry-run] [--limit=N] [--offset=N]
 *
 * Options:
 *   --dry-run    Show what would be generated, don't write to DB
 *   --limit=N    Number of councils to process (default: all)
 *   --offset=N   Skip the first N councils (default: 0)
 *
 * Requires: CLAUDE_API_KEY in api/config.php
 */

// Bootstrap the API config for DB and Claude API key
require_once __DIR__ . '/../config.php';

// ─── Parse CLI options ──────────────────────────────────────────

$dry_run = in_array('--dry-run', $argv ?? [], true);
$limit   = 0; // 0 means all
$offset  = 0;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, 8));
    }
    if (str_starts_with($arg, '--offset=')) {
        $offset = max(0, (int) substr($arg, 9));
    }
}

echo "=== Access100 Council Topic Classifier ===\n";
echo "Mode: " . ($dry_run ? "DRY RUN" : "LIVE") . "\n";
echo "Limit: " . ($limit > 0 ? $limit : "all") . "\n";
echo "Offset: {$offset}\n\n";

// ─── Fetch topics taxonomy ──────────────────────────────────────

$pdo = get_db();

$topics_stmt = $pdo->query("SELECT id, slug, name, description FROM topics ORDER BY display_order ASC");
$topics = $topics_stmt->fetchAll();

if (empty($topics)) {
    echo "ERROR: No topics found in database.\n";
    exit(1);
}

echo "Loaded " . count($topics) . " topics.\n";

// Build taxonomy text for the prompt
$taxonomy_text = "";
foreach ($topics as $t) {
    $taxonomy_text .= "- {$t['slug']}: {$t['name']} — {$t['description']}\n";
}

// Build slug-to-id map for DB inserts
$topic_slug_to_id = [];
foreach ($topics as $t) {
    $topic_slug_to_id[$t['slug']] = (int) $t['id'];
}

// ─── Fetch councils ─────────────────────────────────────────────

// OFFSET requires LIMIT in MySQL
if ($offset > 0 && $limit === 0) {
    echo "ERROR: --offset requires --limit.\n";
    exit(1);
}

// LIMIT/OFFSET are safe: cast to int above via (int) and max().
$limit_clause  = $limit > 0 ? "LIMIT {$limit}" : "";
$offset_clause = $limit > 0 && $offset > 0 ? "OFFSET {$offset}" : "";

$councils_sql = "
    SELECT
        c.id,
        c.name,
        p.name AS parent_name,
        cp.plain_description,
        cp.entity_type,
        COUNT(m.id) AS meeting_count
    FROM councils c
    LEFT JOIN councils p ON c.parent_id = p.id
    LEFT JOIN council_profiles cp ON cp.council_id = c.id
    LEFT JOIN meetings m ON m.council_id = c.id
    GROUP BY c.id
    ORDER BY meeting_count DESC
    {$limit_clause} {$offset_clause}
";
$stmt = $pdo->query($councils_sql);
$councils = $stmt->fetchAll();

echo "Found " . count($councils) . " councils to process.\n\n";

// ─── Process each council ──────────────────────────────────────

$results    = [];
$errors     = [];
$generated  = 0;

foreach ($councils as $i => $council) {
    $council_id   = (int) $council['id'];
    $council_name = $council['name'];
    $parent_name  = $council['parent_name'] ?? '';
    $description  = $council['plain_description'] ?? '';
    $entity_type  = $council['entity_type'] ?? '';

    $progress = ($i + 1) . "/" . count($councils);

    echo "[{$progress}] Classifying: {$council_name}...";

    $prompt = build_classification_prompt(
        $council_name,
        $parent_name,
        $description,
        $entity_type,
        $taxonomy_text
    );

    $response = call_claude_classify($prompt);

    if ($response === null) {
        echo " ERROR (API)\n";
        $errors[] = $council_name;
        continue;
    }

    $mappings = parse_classification_response($response, $topic_slug_to_id);

    if (empty($mappings)) {
        echo " PARSE ERROR\n";
        $errors[] = $council_name;
        continue;
    }

    $slugs = array_column($mappings, 'slug');
    $relevances = array_column($mappings, 'relevance');
    $slug_labels = [];
    foreach ($mappings as $m) {
        $slug_labels[] = $m['slug'] . '(' . $m['relevance'] . ')';
    }

    $results[] = [
        'council_id'   => $council_id,
        'council_name' => $council_name,
        'topic_slugs'  => implode(', ', $slugs),
        'relevances'   => implode(', ', $relevances),
    ];

    if (!$dry_run) {
        insert_council_topics($pdo, $council_id, $mappings);
    }

    $generated++;
    echo " OK [" . implode(', ', $slug_labels) . "]\n";

    // Rate limiting
    usleep(500000); // 0.5s between requests
}

// ─── Output summary ────────────────────────────────────────────

echo "\n=== Summary ===\n";
echo "Processed: " . count($councils) . "\n";
echo "Classified: {$generated}\n";
echo "Errors:    " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nFailed councils:\n";
    foreach ($errors as $name) {
        echo "  - {$name}\n";
    }
}

// ─── Write review CSV ──────────────────────────────────────────

if (!empty($results)) {
    $csv_path = __DIR__ . '/council-topics-review.csv';
    $fp = fopen($csv_path, 'w');
    fputcsv($fp, ['council_id', 'council_name', 'topic_slugs', 'relevances']);

    foreach ($results as $r) {
        fputcsv($fp, [
            $r['council_id'],
            $r['council_name'],
            $r['topic_slugs'],
            $r['relevances'],
        ]);
    }

    fclose($fp);
    echo "\nReview CSV written to: {$csv_path}\n";
}


// =====================================================================
// Helper functions
// =====================================================================

/**
 * Build the Claude prompt for topic classification.
 */
function build_classification_prompt(
    string $council_name,
    string $parent_name,
    string $description,
    string $entity_type,
    string $taxonomy_text
): string {
    $context_parts = [];
    if ($parent_name) {
        $context_parts[] = "Parent organization: {$parent_name}";
    }
    if ($entity_type) {
        $context_parts[] = "Entity type: {$entity_type}";
    }
    if ($description) {
        $context_parts[] = "Description: {$description}";
    }
    $context = !empty($context_parts) ? "\n" . implode("\n", $context_parts) : '';

    return <<<PROMPT
You are an expert on Hawaii state and county government. Classify the following government council/board/commission into the most relevant topics from the taxonomy below.

Council: "{$council_name}"{$context}

Available topics:
{$taxonomy_text}

Rules:
- Assign 1-4 topics that are relevant to this council's work
- Mark each as "primary" (core mission) or "secondary" (tangential/occasional relevance)
- Maximum 2 primary topics per council
- Only assign topics where there is genuine relevance — do not pad
- If a council's name or description clearly maps to a single topic, just assign that one

Respond in valid JSON only (no markdown, no explanation):
{"topics": [{"slug": "topic-slug", "relevance": "primary"}, {"slug": "topic-slug", "relevance": "secondary"}]}
PROMPT;
}

/**
 * Call the Claude API for topic classification.
 *
 * @return string|null The text response, or null on error.
 */
function call_claude_classify(string $prompt): ?string
{
    $api_key = CLAUDE_API_KEY;

    if ($api_key === 'CHANGE_ME') {
        error_log('Claude API key not configured');
        return null;
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 512,
        'messages'   => [
            ['role' => 'user', 'content' => $prompt],
        ],
    ]);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || !$response) {
        error_log("Claude API error: HTTP {$http_code}");
        return null;
    }

    $data = json_decode($response, true);
    return $data['content'][0]['text'] ?? null;
}

/**
 * Parse the Claude classification response into topic mappings.
 *
 * @param string $response Raw Claude response text
 * @param array $valid_slugs Map of valid slug => topic_id
 * @return array Array of ['slug' => ..., 'relevance' => ..., 'topic_id' => ...]
 */
function parse_classification_response(string $response, array $valid_slugs): array
{
    // Try to extract JSON from the response
    if (preg_match('/\{.*\}/s', $response, $matches)) {
        $json = $matches[0];
    } else {
        $json = $response;
    }

    $parsed = json_decode($json, true);

    if (!is_array($parsed) || !isset($parsed['topics']) || !is_array($parsed['topics'])) {
        return [];
    }

    $mappings = [];
    $primary_count = 0;

    foreach ($parsed['topics'] as $entry) {
        $slug = $entry['slug'] ?? '';
        $relevance = $entry['relevance'] ?? 'secondary';

        // Validate slug exists in our taxonomy
        if (!isset($valid_slugs[$slug])) {
            continue;
        }

        // Validate relevance
        if (!in_array($relevance, ['primary', 'secondary'], true)) {
            $relevance = 'secondary';
        }

        // Enforce max 2 primary
        if ($relevance === 'primary') {
            if ($primary_count >= 2) {
                $relevance = 'secondary';
            } else {
                $primary_count++;
            }
        }

        $mappings[] = [
            'slug'      => $slug,
            'relevance' => $relevance,
            'topic_id'  => $valid_slugs[$slug],
        ];

        // Max 4 topics
        if (count($mappings) >= 4) {
            break;
        }
    }

    return $mappings;
}

/**
 * Clear existing mappings for a council and insert new ones.
 */
function insert_council_topics(PDO $pdo, int $council_id, array $mappings): void
{
    // Clear existing mappings for this council
    $pdo->prepare("DELETE FROM topic_council_map WHERE council_id = ?")->execute([$council_id]);

    // Insert new mappings
    $stmt = $pdo->prepare("
        INSERT INTO topic_council_map (topic_id, council_id, relevance)
        VALUES (?, ?, ?)
    ");

    foreach ($mappings as $m) {
        $stmt->execute([
            $m['topic_id'],
            $council_id,
            $m['relevance'],
        ]);
    }
}
