<?php
/**
 * Access100 API - AI Summarizer Service (Claude)
 *
 * Generates plain-language summaries of government meeting agendas
 * using the Anthropic Claude API (Messages endpoint).
 * No external libraries — uses cURL directly.
 *
 * Functions:
 *   summarize_meeting($meeting_id)          — summarize a single meeting by internal ID
 *   summarize_pending_meetings($limit)      — batch: find and summarize meetings without summaries
 *   generate_summary($agenda_text, $council_name, $title) — call Claude API
 *
 * Requires: config.php loaded (CLAUDE_API_KEY)
 */

// Claude API defaults
define('CLAUDE_API_URL', 'https://api.anthropic.com/v1/messages');
define('CLAUDE_MODEL', 'claude-sonnet-4-6');
define('CLAUDE_MAX_TOKENS', 1024);

// OLA languages for meeting summary translations
define('TRANSLATION_LOCALES', [
    'haw'     => 'Hawaiian',
    'tl'      => 'Tagalog',
    'ja'      => 'Japanese',
    'ilo'     => 'Ilocano',
    'zh-hans' => 'Simplified Chinese',
    'zh-hant' => 'Traditional Chinese',
    'ko'      => 'Korean',
    'es'      => 'Spanish',
    'vi'      => 'Vietnamese',
    'sm'      => 'Samoan',
    'to'      => 'Tongan',
    'mah'     => 'Marshallese',
    'chk'     => 'Chuukese',
    'th'      => 'Thai',
    'ceb'     => 'Cebuano',
]);


// =====================================================================
// Summarize a single meeting
// =====================================================================

/**
 * Generate and store an AI summary for a meeting.
 *
 * Looks up the meeting by internal ID, combines description text
 * with agenda PDF attachment (when available), sends to Claude,
 * and stores the result in meetings.summary_text.
 *
 * @param int  $meeting_id Internal meeting ID (not state_id)
 * @param bool $force      Re-generate even if a summary already exists
 * @return string|null The generated summary, or null on failure
 */
function summarize_meeting(int $meeting_id, bool $force = false): ?string
{
    try {
        $pdo = get_db();

        $stmt = $pdo->prepare("
            SELECT m.id, m.title, m.description, m.summary_text,
                   c.name AS council_name
            FROM meetings m
            JOIN councils c ON m.council_id = c.id
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute([$meeting_id]);
        $meeting = $stmt->fetch();

        if (!$meeting) {
            error_log("Summarizer: meeting ID {$meeting_id} not found.");
            return null;
        }

        // Skip if already summarized (unless forced)
        if (!$force && !empty($meeting['summary_text'])) {
            return $meeting['summary_text'];
        }

        // Get description text — this is the authoritative source
        $desc_text  = trim(strip_tags(html_entity_decode($meeting['description'] ?? '')));
        $clean_text = $desc_text;

        // Always try to extract agenda PDF for additional detail
        $pdf_text = extract_agenda_attachment($pdo, $meeting_id);
        if ($pdf_text) {
            // Verify the PDF content is relevant to this meeting by checking
            // for shared keywords (title words appearing in the PDF text)
            $title_words = array_filter(
                explode(' ', strtolower($meeting['title'])),
                fn($w) => strlen($w) > 3
            );
            $pdf_lower   = strtolower($pdf_text);
            $matches     = 0;
            foreach ($title_words as $word) {
                if (str_contains($pdf_lower, $word)) {
                    $matches++;
                }
            }
            // If at least half of the significant title words appear in the PDF, it's relevant
            if (count($title_words) === 0 || $matches >= max(1, count($title_words) / 2)) {
                error_log("Summarizer: enriched meeting ID {$meeting_id} with agenda PDF (" . strlen($pdf_text) . " chars).");
                // Description is primary; PDF provides supplementary detail
                $clean_text = "--- Official notice (primary source) ---\n" . $desc_text
                            . "\n\n--- Agenda attachment (supplementary detail) ---\n" . $pdf_text;
            } else {
                error_log("Summarizer: PDF for meeting ID {$meeting_id} does not appear related — using description only.");
            }
        }

        if (strlen($clean_text) < 50) {
            error_log("Summarizer: no usable agenda text for meeting ID {$meeting_id}.");
            return null;
        }

        // Generate the summary
        $summary = generate_summary($clean_text, $meeting['council_name'], $meeting['title']);

        if ($summary === null) {
            return null;
        }

        // Store in database
        $stmt = $pdo->prepare("UPDATE meetings SET summary_text = ? WHERE id = ?");
        $stmt->execute([$summary, $meeting_id]);

        error_log("Summarizer: generated summary for meeting ID {$meeting_id} (" . strlen($summary) . " chars).");

        // Translate into OLA languages
        $translations = translate_summary($summary, $meeting['council_name'], $meeting['title']);
        if ($translations !== null) {
            $stmt = $pdo->prepare("UPDATE meetings SET summary_translations = ? WHERE id = ?");
            $stmt->execute([json_encode($translations, JSON_UNESCAPED_UNICODE), $meeting_id]);
            error_log("Summarizer: translated summary for meeting ID {$meeting_id} into " . count($translations) . " languages.");
        } else {
            error_log("Summarizer: translation failed for meeting ID {$meeting_id} — English summary saved.");
        }

        return $summary;

    } catch (PDOException $e) {
        error_log('Summarizer DB error: ' . $e->getMessage());
        return null;
    }
}


// =====================================================================
// Batch: Summarize pending meetings
// =====================================================================

/**
 * Find meetings without summaries that have agenda content, and generate
 * summaries for them.
 *
 * Intended to be called from a cron job.
 *
 * @param int $limit Max number of meetings to process in this batch
 * @return array Summary of results: ['processed' => N, 'succeeded' => N, 'failed' => N]
 */
function summarize_pending_meetings(int $limit = 10): array
{
    $results = ['processed' => 0, 'succeeded' => 0, 'failed' => 0];

    try {
        $pdo = get_db();

        // Find meetings with agenda text but no summary
        // Prioritize upcoming meetings, then recent past meetings
        $stmt = $pdo->prepare("
            SELECT id
            FROM meetings
            WHERE summary_text IS NULL
              AND description IS NOT NULL AND description != ''
            ORDER BY
                CASE WHEN meeting_date >= CURDATE() THEN 0 ELSE 1 END,
                meeting_date ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $meeting_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    } catch (PDOException $e) {
        error_log('Summarizer batch query error: ' . $e->getMessage());
        return $results;
    }

    foreach ($meeting_ids as $mid) {
        $results['processed']++;
        $summary = summarize_meeting((int) $mid);

        if ($summary !== null) {
            $results['succeeded']++;
        } else {
            $results['failed']++;
        }

        // Brief pause between API calls to avoid rate limits
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
 * Call the Claude API to generate a plain-language summary.
 *
 * @param string $agenda_text  The raw agenda/description text
 * @param string $council_name Name of the council
 * @param string $title        Meeting title
 * @return string|null The summary text, or null on failure
 */
function generate_summary(string $agenda_text, string $council_name, string $title): ?string
{
    if (CLAUDE_API_KEY === 'CHANGE_ME') {
        error_log('Summarizer: Claude API key not configured — skipping.');
        return null;
    }

    // Truncate very long agendas to avoid excessive token usage
    $max_input_chars = 12000;
    if (strlen($agenda_text) > $max_input_chars) {
        $agenda_text = substr($agenda_text, 0, $max_input_chars) . "\n\n[Agenda truncated for summarization]";
    }

    $system_prompt = "You are a civic information assistant for Hawaii residents. "
        . "Your job is to summarize government meeting agendas in plain, accessible language. "
        . "Write for a general audience who may not be familiar with government jargon. "
        . "Be neutral and factual — do not take political positions. "
        . "Output clean, semantic HTML (no markdown). "
        . "Use only these tags: <h3>, <p>, <ul>, <li>, <strong>, <em>. "
        . "Do NOT wrap output in a container div or add inline styles.";

    $user_prompt = "Summarize this government meeting agenda in plain language.\n\n"
        . "Council: {$council_name}\n"
        . "Meeting: {$title}\n\n"
        . "Agenda:\n{$agenda_text}\n\n"
        . "Structure your HTML summary with these sections:\n"
        . "<h3>What's Being Discussed</h3> — main topics in plain language\n"
        . "<h3>Decisions Expected</h3> — any votes or actions anticipated\n"
        . "<h3>Who Should Pay Attention</h3> — who this meeting affects\n\n"
        . "Use <ul>/<li> for listing multiple topics or stakeholders. "
        . "Use <strong> to highlight key items. "
        . "Keep it under 300 words. No markdown. Output only HTML.";

    $payload = [
        'model'      => CLAUDE_MODEL,
        'max_tokens' => CLAUDE_MAX_TOKENS,
        'system'     => $system_prompt,
        'messages'   => [
            ['role' => 'user', 'content' => $user_prompt],
        ],
    ];

    $ch = curl_init(CLAUDE_API_URL);
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
        error_log('Summarizer cURL error: ' . $curl_error);
        return null;
    }

    if ($http_code !== 200) {
        error_log('Summarizer Claude API error (HTTP ' . $http_code . '): ' . $response);
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log('Summarizer: unexpected Claude API response format.');
        return null;
    }

    return trim($data['content'][0]['text']);
}


// =====================================================================
// Translate summary into OLA languages
// =====================================================================

/**
 * Translate an English meeting summary into all 15 OLA languages
 * using a single Claude API call with structured output.
 *
 * @param string $english_summary The English HTML summary
 * @param string $council_name    Name of the council (for context)
 * @param string $title           Meeting title (for context)
 * @return array|null Associative array keyed by locale slug, or null on failure
 */
function translate_summary(string $english_summary, string $council_name, string $title): ?array
{
    if (CLAUDE_API_KEY === 'CHANGE_ME') {
        error_log('Summarizer: Claude API key not configured — skipping translation.');
        return null;
    }

    $locale_list = [];
    foreach (TRANSLATION_LOCALES as $slug => $name) {
        $locale_list[] = "  \"{$slug}\": \"{$name}\"";
    }
    $locale_json = "{\n" . implode(",\n", $locale_list) . "\n}";

    $system_prompt = "You are a professional translator for a civic engagement platform in Hawaii. "
        . "Translate the provided English HTML meeting summary into all requested languages. "
        . "Preserve ALL HTML tags exactly as they are — translate only the text content between tags. "
        . "Do not add, remove, or modify any HTML tags. "
        . "Use natural, accessible language appropriate for each target language. "
        . "Government terminology should use standard official translations where they exist.";

    $user_prompt = "Translate this English meeting summary into all 15 languages listed below.\n\n"
        . "Council: {$council_name}\n"
        . "Meeting: {$title}\n\n"
        . "English summary:\n{$english_summary}\n\n"
        . "Target languages (locale slug => language name):\n{$locale_json}\n\n"
        . "Return a JSON object with each locale slug as the key and the translated HTML as the value. "
        . "Output ONLY the JSON object, no other text.";

    $payload = [
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 8192,
        'system'     => $system_prompt,
        'messages'   => [
            ['role' => 'user', 'content' => $user_prompt],
        ],
    ];

    $ch = curl_init(CLAUDE_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('Summarizer translation cURL error: ' . $curl_error);
        return null;
    }

    if ($http_code !== 200) {
        error_log('Summarizer translation Claude API error (HTTP ' . $http_code . '): ' . $response);
        return null;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['content'][0]['text'])) {
        error_log('Summarizer translation: unexpected Claude API response format.');
        return null;
    }

    $text = trim($data['content'][0]['text']);

    // Strip markdown code fences if Claude wrapped the JSON
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);
    }

    $translations = json_decode($text, true);
    if (!is_array($translations)) {
        error_log('Summarizer translation: failed to parse JSON from Claude response.');
        return null;
    }

    // Keep only valid locale keys
    $valid = [];
    foreach (TRANSLATION_LOCALES as $slug => $name) {
        if (!empty($translations[$slug])) {
            $valid[$slug] = $translations[$slug];
        }
    }

    if (empty($valid)) {
        error_log('Summarizer translation: no valid translations found in response.');
        return null;
    }

    return $valid;
}


// =====================================================================
// Agenda PDF extraction
// =====================================================================

/**
 * Find an agenda attachment for a meeting, download the PDF, and
 * extract its text using pdftotext.
 *
 * Looks for attachments whose file_name contains "agenda" (case-insensitive)
 * and have a PDF file type. Returns extracted text or null.
 *
 * @param PDO $pdo       Database connection
 * @param int $meeting_id Internal meeting ID
 * @return string|null   Extracted text, or null if nothing usable found
 */
function extract_agenda_attachment(PDO $pdo, int $meeting_id): ?string
{
    // Find agenda PDF attachments for this meeting
    $stmt = $pdo->prepare("
        SELECT file_url, file_name
        FROM attachments
        WHERE meeting_id = ?
          AND LOWER(file_name) LIKE '%agenda%'
          AND file_type = 'pdf'
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([$meeting_id]);
    $attachment = $stmt->fetch();

    if (!$attachment) {
        return null;
    }

    // Download the PDF to a temp file
    $tmp_file = tempnam(sys_get_temp_dir(), 'agenda_') . '.pdf';

    // Encode spaces in URL path (eHawaii URLs often contain spaces in filenames)
    $url = str_replace(' ', '%20', $attachment['file_url']);

    $ch = curl_init($url);
    $fp = fopen($tmp_file, 'wb');
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'Access100-Summarizer/1.0',
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($curl_err || $http_code !== 200) {
        error_log("Summarizer: failed to download PDF {$attachment['file_name']} (HTTP {$http_code}): {$curl_err}");
        @unlink($tmp_file);
        return null;
    }

    // Extract text with pdftotext
    $txt_file = $tmp_file . '.txt';
    $cmd = sprintf('pdftotext -layout %s %s 2>&1', escapeshellarg($tmp_file), escapeshellarg($txt_file));
    exec($cmd, $output, $exit_code);

    @unlink($tmp_file);

    if ($exit_code !== 0 || !file_exists($txt_file)) {
        error_log("Summarizer: pdftotext failed for {$attachment['file_name']}: " . implode(' ', $output));
        @unlink($txt_file);
        return null;
    }

    $text = file_get_contents($txt_file);
    @unlink($txt_file);

    $text = trim($text);
    if (strlen($text) < 50) {
        error_log("Summarizer: PDF text too short for {$attachment['file_name']}.");
        return null;
    }

    return $text;
}
