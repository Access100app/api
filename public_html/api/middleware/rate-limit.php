<?php
/**
 * Access100 API - Rate Limiting Middleware
 *
 * Implements a sliding-window rate limiter using the filesystem.
 * Tracks requests per IP + API key combination.
 *
 * Uses file-based storage (not DB) to avoid adding load to MySQL
 * on every request. Rate limit state is stored in /tmp/access100_ratelimit/.
 *
 * Requires: config.php (for RATE_LIMIT_MAX_REQUESTS, RATE_LIMIT_WINDOW_SECONDS,
 *           get_client_ip(), json_error())
 */

// Directory for rate limit state files
define('RATE_LIMIT_DIR', sys_get_temp_dir() . '/access100_ratelimit');

/**
 * Check and enforce rate limiting.
 *
 * Tracks request timestamps per client identity (IP + API key hash).
 * If the client exceeds RATE_LIMIT_MAX_REQUESTS within RATE_LIMIT_WINDOW_SECONDS,
 * a 429 Too Many Requests response is sent and the script exits.
 *
 * Adds standard rate limit headers to every response:
 *   X-RateLimit-Limit: max requests per window
 *   X-RateLimit-Remaining: requests remaining
 *   X-RateLimit-Reset: Unix timestamp when the window resets
 *
 * @param string $api_key_label The label of the authenticated API key (or 'public')
 */
function check_rate_limit(string $api_key_label = 'public'): void
{
    $ip = get_client_ip();

    // Create a unique identifier for this client
    $client_id = hash('sha256', $ip . '|' . $api_key_label);
    $state_file = RATE_LIMIT_DIR . '/' . $client_id . '.json';

    // Ensure the rate limit directory exists
    if (!is_dir(RATE_LIMIT_DIR)) {
        @mkdir(RATE_LIMIT_DIR, 0700, true);
    }

    $now = time();
    $window_start = $now - RATE_LIMIT_WINDOW_SECONDS;

    // Load existing timestamps
    $timestamps = [];
    if (file_exists($state_file)) {
        $raw = @file_get_contents($state_file);
        if ($raw !== false) {
            $timestamps = json_decode($raw, true) ?: [];
        }
    }

    // Prune timestamps outside the current window
    $timestamps = array_values(array_filter($timestamps, function (int $ts) use ($window_start) {
        return $ts >= $window_start;
    }));

    $request_count = count($timestamps);
    $remaining = max(0, RATE_LIMIT_MAX_REQUESTS - $request_count);

    // Calculate when the oldest request in the window will expire
    $reset_at = empty($timestamps) ? $now + RATE_LIMIT_WINDOW_SECONDS : $timestamps[0] + RATE_LIMIT_WINDOW_SECONDS;

    // Always set rate limit headers
    header('X-RateLimit-Limit: ' . RATE_LIMIT_MAX_REQUESTS);
    header('X-RateLimit-Remaining: ' . $remaining);
    header('X-RateLimit-Reset: ' . $reset_at);

    // Check if over limit
    if ($request_count >= RATE_LIMIT_MAX_REQUESTS) {
        $retry_after = $reset_at - $now;
        header('Retry-After: ' . max(1, $retry_after));
        json_error(429, 'Rate limit exceeded. Try again in ' . max(1, $retry_after) . ' seconds.');
    }

    // Record this request
    $timestamps[] = $now;
    @file_put_contents($state_file, json_encode($timestamps), LOCK_EX);
}

/**
 * Cleanup old rate limit state files.
 *
 * Call this periodically (e.g., from a cron job) to remove stale files.
 * Files older than 2x the window duration are considered stale.
 */
function cleanup_rate_limit_files(): void
{
    if (!is_dir(RATE_LIMIT_DIR)) {
        return;
    }

    $cutoff = time() - (RATE_LIMIT_WINDOW_SECONDS * 2);
    $files = glob(RATE_LIMIT_DIR . '/*.json');

    if ($files === false) {
        return;
    }

    foreach ($files as $file) {
        if (filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
