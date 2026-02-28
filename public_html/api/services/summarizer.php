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


// =====================================================================
// Summarize a single meeting
// =====================================================================

/**
 * Generate and store an AI summary for a meeting.
 *
 * Looks up the meeting by internal ID, extracts agenda text (from
 * full_agenda_text or description), sends to Claude, and stores
 * the result in meetings.summary_text.
 *
 * @param int $meeting_id Internal meeting ID (not state_id)
 * @return string|null The generated summary, or null on failure
 */
function summarize_meeting(int $meeting_id): ?string
{
    try {
        $pdo = get_db();

        $stmt = $pdo->prepare("
            SELECT m.id, m.title, m.description, m.full_agenda_text, m.summary_text,
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

        // Skip if already summarized
        if (!empty($meeting['summary_text'])) {
            return $meeting['summary_text'];
        }

        // Get agenda text — prefer full_agenda_text, fall back to description
        $agenda_text = !empty($meeting['full_agenda_text'])
            ? $meeting['full_agenda_text']
            : $meeting['description'];

        // Strip HTML and decode entities
        $clean_text = trim(strip_tags(html_entity_decode($agenda_text ?? '')));

        // If calendar text is thin, try to extract from an agenda PDF attachment
        if (strlen($clean_text) < 300) {
            $pdf_text = extract_agenda_attachment($pdo, $meeting_id);
            if ($pdf_text) {
                error_log("Summarizer: enriched meeting ID {$meeting_id} with agenda PDF (" . strlen($pdf_text) . " chars).");
                $clean_text = $pdf_text . "\n\n--- Calendar listing ---\n" . $clean_text;
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
              AND (full_agenda_text IS NOT NULL AND full_agenda_text != ''
                   OR description IS NOT NULL AND description != '')
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
