<?php
/**
 * Access100 API - Configuration
 *
 * Centralized database credentials, API keys, service credentials,
 * and shared helper functions for the REST API.
 *
 * In production on Hostinger, this file is protected by the api/.htaccess
 * rule that routes all requests through index.php -- direct access to
 * config.php is blocked.
 */

// ─── .env file loading ───────────────────────────────────────────────
// Load .env file if present (for local dev / Docker). In production,
// environment variables should be set by the hosting platform.
$_env_file = __DIR__ . '/../.env';
if (file_exists($_env_file)) {
    $_env_lines = file($_env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($_env_lines as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') === false) continue;
        [$_key, $_val] = explode('=', $_line, 2);
        $_key = trim($_key);
        $_val = trim($_val, " \t\n\r\0\x0B\"'");
        if (!getenv($_key)) {
            putenv("{$_key}={$_val}");
        }
    }
    unset($_env_lines, $_line, $_key, $_val);
}
unset($_env_file);

// ─── Database ────────────────────────────────────────────────────────
// All credentials MUST come from environment variables. No hardcoded secrets.
define('API_DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('API_DB_NAME', getenv('DB_NAME') ?: die('FATAL: DB_NAME environment variable is required.'));
define('API_DB_USER', getenv('DB_USER') ?: die('FATAL: DB_USER environment variable is required.'));
define('API_DB_PASS', getenv('DB_PASS') ?: die('FATAL: DB_PASS environment variable is required.'));

// ─── API Keys ────────────────────────────────────────────────────────
// Keys that are allowed to call this API. Map key => label for logging.
// All keys must be provided via environment variables.
$_api_keys = [];
if (getenv('API_KEY')) {
    $_api_keys[getenv('API_KEY')] = 'primary';
}
if (getenv('API_KEY_CIVIME')) {
    $_api_keys[getenv('API_KEY_CIVIME')] = 'civi.me production';
}
if (empty($_api_keys)) {
    error_log('WARNING: No API keys configured. Set API_KEY or API_KEY_CIVIME environment variable.');
}
define('API_KEYS', $_api_keys);
unset($_api_keys);

// ─── CORS ────────────────────────────────────────────────────────────
// CORS_ALLOWED_ORIGINS env var is a comma-separated list of origins.
// Falls back to the production origin list when the env var is absent.
$_cors_origins = getenv('CORS_ALLOWED_ORIGINS')
    ? array_map('trim', explode(',', getenv('CORS_ALLOWED_ORIGINS')))
    : ['https://civi.me', 'https://www.civi.me', 'https://access100.app'];
define('CORS_ALLOWED_ORIGINS', $_cors_origins);
unset($_cors_origins);

// ─── Rate Limiting ───────────────────────────────────────────────────
// Requests per window (per IP + API key combination)
define('RATE_LIMIT_MAX_REQUESTS', (int) (getenv('RATE_LIMIT_MAX_REQUESTS') ?: 1000));
// Window duration in seconds (15 minutes)
define('RATE_LIMIT_WINDOW_SECONDS', 900);

// ─── Gmail API (OAuth2 Refresh Token) ────────────────────────────────
define('GMAIL_CLIENT_ID', getenv('GMAIL_CLIENT_ID') ?: 'CHANGE_ME');
define('GMAIL_CLIENT_SECRET', getenv('GMAIL_CLIENT_SECRET') ?: 'CHANGE_ME');
define('GMAIL_REFRESH_TOKEN', getenv('GMAIL_REFRESH_TOKEN') ?: 'CHANGE_ME');
define('GMAIL_FROM_EMAIL', getenv('GMAIL_FROM_EMAIL') ?: 'email@access100.org');
define('GMAIL_FROM_NAME', getenv('GMAIL_FROM_NAME') ?: 'civi.me');

// ─── Twilio ──────────────────────────────────────────────────────────
define('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: 'CHANGE_ME');
define('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: 'CHANGE_ME');
define('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: '+1XXXXXXXXXX');

// ─── Claude AI (Summarizer) ─────────────────────────────────────────
define('CLAUDE_API_KEY', getenv('CLAUDE_API_KEY') ?: 'CHANGE_ME');

// ─── API Version & URL ───────────────────────────────────────────────
define('API_VERSION', 'v1');
define('API_BASE_PATH', '/api/v1');
define('API_BASE_URL', getenv('API_BASE_URL') ?: 'https://access100.app/api/v1');


// =====================================================================
// Database Connection (Singleton)
// =====================================================================

/**
 * Get a shared PDO database connection.
 *
 * Uses the same pattern as checker/shared/db.php -- singleton PDO with
 * exception error mode and associative fetch default.
 */
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . API_DB_HOST . ';dbname=' . API_DB_NAME . ';charset=utf8mb4',
            API_DB_USER,
            API_DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}


// =====================================================================
// JSON Response Helper
// =====================================================================

/**
 * Send a JSON response and terminate the script.
 *
 * All API responses use the standard envelope:
 *   { "data": {...}, "meta": {...} }
 *
 * Error responses use:
 *   { "error": { "code": 404, "message": "..." } }
 *
 * @param array $data        The response payload
 * @param int   $status_code HTTP status code (default 200)
 * @param array $meta        Optional metadata (pagination, timing, etc.)
 */
function json_response(array $data, int $status_code = 200, array $meta = []): void
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');

    if ($status_code >= 400) {
        // Error envelope
        $envelope = [
            'error' => [
                'code'    => $status_code,
                'message' => $data['message'] ?? 'An error occurred',
            ],
        ];
        // Include additional error fields if provided
        if (isset($data['details'])) {
            $envelope['error']['details'] = $data['details'];
        }
    } else {
        // Success envelope
        $envelope = [
            'data' => $data,
            'meta' => array_merge([
                'api_version' => API_VERSION,
                'timestamp'   => gmdate('c'),
            ], $meta),
        ];
    }

    echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and terminate.
 *
 * Convenience wrapper around json_response for error cases.
 *
 * @param int    $status_code HTTP status code
 * @param string $message     Human-readable error message
 * @param array  $details     Optional additional error details
 */
function json_error(int $status_code, string $message, array $details = []): void
{
    $data = ['message' => $message];
    if (!empty($details)) {
        $data['details'] = $details;
    }
    json_response($data, $status_code);
}

/**
 * Get the request body parsed as JSON.
 *
 * @return array|null Parsed JSON or null if invalid/empty
 */
function get_json_body(): ?array
{
    $raw = file_get_contents('php://input');
    if (empty($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    return $decoded;
}

/**
 * Get the client IP address.
 *
 * Uses REMOTE_ADDR only to prevent spoofing via X-Forwarded-For.
 * Hostinger sets REMOTE_ADDR correctly behind their proxy.
 */
function get_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Set HTTP caching headers.
 *
 * @param int  $max_age  Cache duration in seconds
 * @param bool $public   Whether the response can be cached by shared caches
 */
function set_cache_headers(int $max_age, bool $public = true): void
{
    $visibility = $public ? 'public' : 'private';
    header("Cache-Control: {$visibility}, max-age={$max_age}");
}
