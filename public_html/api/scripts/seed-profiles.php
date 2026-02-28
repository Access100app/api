<?php
/**
 * Access100 API - AI Profile Seeder
 *
 * Generates initial council_profiles rows for the top 50 councils
 * (by meeting count) using the Claude API. Outputs a CSV for human review.
 *
 * Usage:
 *   php api/scripts/seed-profiles.php [--dry-run] [--limit=50]
 *
 * Options:
 *   --dry-run   Show what would be generated, don't write to DB
 *   --limit=N   Number of councils to process (default: 50)
 *
 * Requires: CLAUDE_API_KEY in api/config.php
 */

// Bootstrap the API config for DB and Claude API key
require_once __DIR__ . '/../config.php';

// ─── Parse CLI options ──────────────────────────────────────────

$dry_run = in_array('--dry-run', $argv ?? [], true);
$limit   = 50;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(200, (int) substr($arg, 8)));
    }
}

echo "=== Access100 Council Profile Seeder ===\n";
echo "Mode: " . ($dry_run ? "DRY RUN" : "LIVE") . "\n";
echo "Limit: {$limit} councils\n\n";

// ─── Fetch top councils by meeting count ──────────────────────

$pdo = get_db();

$stmt = $pdo->prepare("
    SELECT
        c.id,
        c.name,
        p.name AS parent_name,
        COUNT(m.id) AS meeting_count
    FROM councils c
    LEFT JOIN councils p ON c.parent_id = p.id
    LEFT JOIN meetings m ON m.council_id = c.id
    GROUP BY c.id
    ORDER BY meeting_count DESC
    LIMIT ?
");
$stmt->execute([$limit]);
$councils = $stmt->fetchAll();

echo "Found " . count($councils) . " councils to process.\n\n";

// ─── Check which already have profiles ──────────────────────────

$existing = [];
$exist_stmt = $pdo->query("SELECT council_id FROM council_profiles");
foreach ($exist_stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $existing[(int) $cid] = true;
}

// ─── Process each council ──────────────────────────────────────

$results    = [];
$errors     = [];
$skipped    = 0;
$generated  = 0;

foreach ($councils as $i => $council) {
    $council_id   = (int) $council['id'];
    $council_name = $council['name'];
    $parent_name  = $council['parent_name'] ?? '';

    $progress = ($i + 1) . "/" . count($councils);

    // Skip if already has a profile
    if (isset($existing[$council_id])) {
        echo "[{$progress}] SKIP {$council_name} (already has profile)\n";
        $skipped++;
        continue;
    }

    echo "[{$progress}] Generating profile for: {$council_name}...";

    $prompt = build_prompt($council_name, $parent_name);

    $response = call_claude($prompt);

    if ($response === null) {
        echo " ERROR\n";
        $errors[] = $council_name;
        continue;
    }

    $profile = parse_response($response);

    if (empty($profile['plain_description'])) {
        echo " PARSE ERROR\n";
        $errors[] = $council_name;
        continue;
    }

    // Generate slug from council name
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $council_name));
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 100);

    $profile['council_id']   = $council_id;
    $profile['council_name'] = $council_name;
    $profile['slug']         = $slug;

    $results[] = $profile;

    if (!$dry_run) {
        insert_profile($pdo, $profile);
    }

    $generated++;
    echo " OK\n";

    // Rate limiting — avoid hitting Claude API too fast
    usleep(500000); // 0.5s between requests
}

// ─── Output summary ──────────────────────────────────────────

echo "\n=== Summary ===\n";
echo "Processed: " . count($councils) . "\n";
echo "Generated: {$generated}\n";
echo "Skipped:   {$skipped}\n";
echo "Errors:    " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nFailed councils:\n";
    foreach ($errors as $name) {
        echo "  - {$name}\n";
    }
}

// ─── Write review CSV ──────────────────────────────────────────

if (!empty($results)) {
    $csv_path = __DIR__ . '/profile-review.csv';
    $fp = fopen($csv_path, 'w');
    fputcsv($fp, ['council_id', 'council_name', 'slug', 'entity_type', 'jurisdiction', 'plain_description', 'decisions_examples', 'why_care']);

    foreach ($results as $r) {
        fputcsv($fp, [
            $r['council_id'],
            $r['council_name'],
            $r['slug'],
            $r['entity_type'] ?? '',
            $r['jurisdiction'] ?? '',
            $r['plain_description'] ?? '',
            $r['decisions_examples'] ?? '',
            $r['why_care'] ?? '',
        ]);
    }

    fclose($fp);
    echo "\nReview CSV written to: {$csv_path}\n";
}


// =====================================================================
// Helper functions
// =====================================================================

/**
 * Build the Claude prompt for profile generation.
 */
function build_prompt(string $council_name, string $parent_name): string
{
    $parent_context = $parent_name ? " (part of {$parent_name})" : '';

    return <<<PROMPT
You are an expert on Hawaii state government boards, commissions, and councils.

Generate a profile for: "{$council_name}"{$parent_context}

Respond in valid JSON with these exact keys:
{
  "plain_description": "A 2-3 sentence plain-language explanation of what this board/commission does. No jargon. Written for a regular citizen.",
  "decisions_examples": "A paragraph giving 2-3 concrete examples of decisions this board makes that affect everyday people.",
  "why_care": "A paragraph explaining why a regular Hawaii resident should care about this board's work. Make it personal and relatable.",
  "entity_type": "One of: board, commission, council, committee, authority, department, office",
  "jurisdiction": "One of: state, honolulu, maui, hawaii, kauai"
}

Guidelines:
- Write for a general audience, not government insiders
- Be specific to Hawaii where possible
- Keep each field under 200 words
- Use plain, conversational language
- If you're not certain about the entity_type or jurisdiction, make your best guess based on the name
PROMPT;
}

/**
 * Call the Claude API to generate a profile.
 *
 * @return string|null The text response, or null on error.
 */
function call_claude(string $prompt): ?string
{
    $api_key = CLAUDE_API_KEY;

    if ($api_key === 'CHANGE_ME') {
        error_log('Claude API key not configured');
        return null;
    }

    $payload = json_encode([
        'model'      => 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
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
 * Parse the Claude JSON response into a profile array.
 */
function parse_response(string $response): array
{
    // Try to extract JSON from the response (Claude sometimes wraps in markdown)
    if (preg_match('/\{[^{}]*\}/s', $response, $matches)) {
        $json = $matches[0];
    } else {
        $json = $response;
    }

    $parsed = json_decode($json, true);

    if (!is_array($parsed)) {
        return [];
    }

    // Validate entity_type
    $valid_types = ['board', 'commission', 'council', 'committee', 'authority', 'department', 'office'];
    if (!in_array($parsed['entity_type'] ?? '', $valid_types, true)) {
        $parsed['entity_type'] = 'board';
    }

    // Validate jurisdiction
    $valid_jurisdictions = ['state', 'honolulu', 'maui', 'hawaii', 'kauai'];
    if (!in_array($parsed['jurisdiction'] ?? '', $valid_jurisdictions, true)) {
        $parsed['jurisdiction'] = 'state';
    }

    return $parsed;
}

/**
 * Insert a profile row into council_profiles.
 */
function insert_profile(PDO $pdo, array $profile): void
{
    $stmt = $pdo->prepare("
        INSERT INTO council_profiles
            (council_id, slug, plain_description, decisions_examples, why_care, entity_type, jurisdiction, last_updated)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE())
        ON DUPLICATE KEY UPDATE
            plain_description = VALUES(plain_description),
            decisions_examples = VALUES(decisions_examples),
            why_care = VALUES(why_care),
            entity_type = VALUES(entity_type),
            jurisdiction = VALUES(jurisdiction),
            last_updated = CURDATE()
    ");

    $stmt->execute([
        $profile['council_id'],
        $profile['slug'],
        $profile['plain_description'] ?? null,
        $profile['decisions_examples'] ?? null,
        $profile['why_care'] ?? null,
        $profile['entity_type'] ?? 'board',
        $profile['jurisdiction'] ?? 'state',
    ]);
}
