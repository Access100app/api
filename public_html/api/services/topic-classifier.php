<?php
/**
 * Access100 API - AI Topic Classifier Service
 *
 * Classifies government meetings into topics using the Claude API.
 * Writes results to the meeting_topics table.
 *
 * Functions:
 *   classify_meeting_topics($meeting_id)                          — classify a single meeting
 *   classify_pending_meetings($limit)                             — batch: find and classify untagged meetings
 *   generate_topic_classification($text, $council_name, $title, $council_topics) — call Claude API
 *
 * Requires: config.php loaded (CLAUDE_API_KEY)
 */

define('TOPIC_CLASSIFIER_MODEL', 'claude-sonnet-4-20250514');
define('TOPIC_CLASSIFIER_MAX_TOKENS', 512);
define('TOPIC_CLASSIFIER_MAX_INPUT', 8000);
define('TOPIC_CLASSIFIER_MIN_CONFIDENCE', 0.5);
define('TOPIC_CLASSIFIER_MAX_TOPICS', 5);


// =====================================================================
// Classify a single meeting
// =====================================================================

/**
 * Classify a meeting by topic and store results in meeting_topics.
 *
 * Looks up the meeting by internal ID, extracts text content, sends to
 * Claude for classification, and writes topic mappings.
 *
 * @param int $meeting_id Internal meeting ID (not state_id)
 * @return array|null Array of classified topics, or null on failure
 */
function classify_meeting_topics(int $meeting_id): ?array
{
    try {
        $pdo = get_db();

        // Fetch meeting with council info
        $stmt = $pdo->prepare("
            SELECT m.id, m.title, m.description, m.full_agenda_text, m.summary_text,
                   m.council_id, c.name AS council_name
            FROM meetings m
            JOIN councils c ON m.council_id = c.id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute([$meeting_id]);
        $meeting = $stmt->fetch();

        if (!$meeting) {
            error_log("TopicClassifier: meeting ID {$meeting_id} not found.");
            return null;
        }

        // Skip if already has AI topic tags
        $existing = $pdo->prepare("
            SELECT COUNT(*) FROM meeting_topics WHERE meeting_id = ? AND source = 'ai'
        ");
        $existing->execute([$meeting_id]);
        if ((int) $existing->fetchColumn() > 0) {
            error_log("TopicClassifier: meeting ID {$meeting_id} already classified.");
            return null;
        }

        // Get text content: prefer summary_text, then full_agenda_text, then description
        $text = '';
        if (!empty($meeting['summary_text'])) {
            $text = $meeting['summary_text'];
        } elseif (!empty($meeting['full_agenda_text'])) {
            $text = $meeting['full_agenda_text'];
        } elseif (!empty($meeting['description'])) {
            $text = $meeting['description'];
        }

        $clean_text = trim(strip_tags(html_entity_decode($text)));

        if (strlen($clean_text) < 20) {
            error_log("TopicClassifier: no usable text for meeting ID {$meeting_id}.");
            return null;
        }

        // Get council's existing topic mappings as hints
        $council_topics_stmt = $pdo->prepare("
            SELECT t.slug, t.name, tcm.relevance
            FROM topic_council_map tcm
            JOIN topics t ON tcm.topic_id = t.id
            WHERE tcm.council_id = ?
        ");
        $council_topics_stmt->execute([$meeting['council_id']]);
        $council_topics = $council_topics_stmt->fetchAll();

        // Fetch topics taxonomy once (used for slug-to-id map and passed to API call)
        $topics_stmt = $pdo->query("SELECT id, slug, name, description FROM topics ORDER BY display_order ASC");
        $all_topics = $topics_stmt->fetchAll();
        $slug_to_id = [];
        foreach ($all_topics as $t) {
            $slug_to_id[$t['slug']] = (int) $t['id'];
        }

        // Classify
        $classifications = generate_topic_classification(
            $clean_text,
            $meeting['council_name'],
            $meeting['title'],
            $council_topics,
            $all_topics
        );

        if ($classifications === null || empty($classifications)) {
            return null;
        }

        // Write to meeting_topics (IGNORE prevents duplicates on concurrent runs)
        $insert = $pdo->prepare("
            INSERT IGNORE INTO meeting_topics (meeting_id, topic_id, source, confidence)
            VALUES (?, ?, 'ai', ?)
        ");

        $written = [];
        foreach ($classifications as $c) {
            $slug = $c['slug'] ?? '';
            $confidence = $c['confidence'] ?? 0;

            if (!isset($slug_to_id[$slug])) {
                continue;
            }

            if ($confidence < TOPIC_CLASSIFIER_MIN_CONFIDENCE) {
                continue;
            }

            $insert->execute([
                $meeting_id,
                $slug_to_id[$slug],
                round($confidence, 2),
            ]);

            $written[] = $c;
        }

        error_log("TopicClassifier: classified meeting ID {$meeting_id} into " . count($written) . " topics.");
        return $written;

    } catch (PDOException $e) {
        error_log('TopicClassifier DB error: ' . $e->getMessage());
        return null;
    }
}


// =====================================================================
// Batch: Classify pending meetings
// =====================================================================

/**
 * Find meetings without topic tags and classify them.
 *
 * Prioritizes upcoming meetings, then recent past meetings.
 *
 * @param int $limit Max number of meetings to process in this batch
 * @return array Summary: ['processed' => N, 'succeeded' => N, 'failed' => N]
 */
function classify_pending_meetings(int $limit = 10): array
{
    $results = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

    try {
        $pdo = get_db();

        // Find meetings that have text content but no AI topic tags
        $stmt = $pdo->prepare("
            SELECT m.id
            FROM meetings m
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
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $meeting_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (PDOException $e) {
        error_log('TopicClassifier batch query error: ' . $e->getMessage());
        return $results;
    }

    foreach ($meeting_ids as $mid) {
        $results['processed']++;
        $classified = classify_meeting_topics((int) $mid);

        if ($classified !== null) {
            $results['succeeded']++;
        } else {
            $results['failed']++;
        }

        // Brief pause between API calls
        if ($results['processed'] < count($meeting_ids)) {
            usleep(500000); // 0.5 seconds
        }
    }

    return $results;
}


// =====================================================================
// Claude API Call
// =====================================================================

/**
 * Call the Claude API to classify meeting text into topics.
 *
 * @param string $text           The meeting text (summary, agenda, or description)
 * @param string $council_name   Name of the council
 * @param string $title          Meeting title
 * @param array  $council_topics Council's existing topic mappings as hints
 * @return array|null Array of ['slug' => ..., 'confidence' => ...], or null on failure
 */
function generate_topic_classification(
    string $text,
    string $council_name,
    string $title,
    array $council_topics = [],
    array $topics = []
): ?array {
    if (CLAUDE_API_KEY === 'CHANGE_ME') {
        error_log('TopicClassifier: Claude API key not configured — skipping.');
        return null;
    }

    // Truncate long text at a word boundary
    if (mb_strlen($text, 'UTF-8') > TOPIC_CLASSIFIER_MAX_INPUT) {
        $truncated = mb_substr($text, 0, TOPIC_CLASSIFIER_MAX_INPUT, 'UTF-8');
        $truncated = preg_replace('/\s+\S*$/', '', $truncated);
        $text = $truncated . "\n\n[Text truncated]";
    }

    // Use passed-in topics or fetch from DB as fallback
    if (empty($topics)) {
        try {
            $pdo = get_db();
            $topics_stmt = $pdo->query("SELECT id, slug, name, description FROM topics ORDER BY display_order ASC");
            $topics = $topics_stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('TopicClassifier: failed to fetch topics: ' . $e->getMessage());
            return null;
        }
    }

    $taxonomy_text = "";
    foreach ($topics as $t) {
        $taxonomy_text .= "- {$t['slug']}: {$t['name']} — {$t['description']}\n";
    }

    // Council topic hints
    $hint_text = "";
    if (!empty($council_topics)) {
        $hints = [];
        foreach ($council_topics as $ct) {
            $hints[] = "{$ct['slug']} ({$ct['relevance']})";
        }
        $hint_text = "\nThis council is already classified under: " . implode(', ', $hints)
            . "\nUse this as a hint but classify based on the specific meeting content.";
    }

    $system_prompt = "You are a civic topic classifier for Hawaii government meetings. "
        . "Classify meetings into relevant topics based on their content. "
        . "Be precise — only assign topics where the meeting content clearly relates.";

    $user_prompt = "Classify this government meeting into relevant topics.\n\n"
        . "Council: {$council_name}\n"
        . "Meeting: {$title}\n"
        . "{$hint_text}\n\n"
        . "Meeting text:\n{$text}\n\n"
        . "Available topics:\n{$taxonomy_text}\n"
        . "Rules:\n"
        . "- Assign 1-5 topics with confidence scores (0.0-1.0)\n"
        . "- Only include topics with confidence >= 0.5\n"
        . "- Confidence 0.9+ means the meeting is primarily about this topic\n"
        . "- Confidence 0.5-0.7 means tangential relevance\n"
        . "- Be conservative — fewer accurate topics are better than many weak ones\n\n"
        . "Respond in valid JSON only (no markdown, no explanation):\n"
        . '{"topics": [{"slug": "topic-slug", "confidence": 0.95}]}';

    $payload = [
        'model'      => TOPIC_CLASSIFIER_MODEL,
        'max_tokens' => TOPIC_CLASSIFIER_MAX_TOKENS,
        'system'     => $system_prompt,
        'messages'   => [
            ['role' => 'user', 'content' => $user_prompt],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('TopicClassifier cURL error: ' . $curl_error);
        return null;
    }

    if ($http_code !== 200) {
        error_log('TopicClassifier Claude API error (HTTP ' . $http_code . '): ' . $response);
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log('TopicClassifier: unexpected Claude API response format.');
        return null;
    }

    $text_response = trim($data['content'][0]['text']);

    // Parse JSON response
    if (preg_match('/\{.*\}/s', $text_response, $matches)) {
        $json = $matches[0];
    } else {
        $json = $text_response;
    }

    $parsed = json_decode($json, true);

    if (!is_array($parsed) || !isset($parsed['topics']) || !is_array($parsed['topics'])) {
        error_log('TopicClassifier: failed to parse response: ' . $text_response);
        return null;
    }

    // Validate and filter topics
    $valid_slugs = array_column($topics, 'slug');
    $classifications = [];

    foreach ($parsed['topics'] as $entry) {
        $slug = $entry['slug'] ?? '';
        $confidence = (float) ($entry['confidence'] ?? 0);

        if (!in_array($slug, $valid_slugs, true)) {
            continue;
        }

        if ($confidence < TOPIC_CLASSIFIER_MIN_CONFIDENCE) {
            continue;
        }

        $confidence = min(1.0, max(0.0, $confidence));

        $classifications[] = [
            'slug'       => $slug,
            'confidence' => $confidence,
        ];

        if (count($classifications) >= TOPIC_CLASSIFIER_MAX_TOPICS) {
            break;
        }
    }

    return $classifications;
}
