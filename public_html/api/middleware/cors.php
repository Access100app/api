<?php
/**
 * Access100 API - CORS Middleware
 *
 * Handles Cross-Origin Resource Sharing headers.
 * Allows requests from civi.me and other configured origins.
 *
 * This middleware MUST run first in the chain so that preflight
 * OPTIONS requests are handled before auth or rate limiting.
 *
 * Requires: config.php (for CORS_ALLOWED_ORIGINS constant)
 */

/**
 * Apply CORS headers to the response.
 *
 * - Checks the Origin header against the allowed origins list.
 * - Sets appropriate Access-Control-* headers.
 * - Handles OPTIONS preflight requests immediately (returns 204 and exits).
 * - If the origin is not allowed, no CORS headers are set (browser will block).
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    // Check if the request origin is in our allowed list
    if ($origin !== '' && in_array($origin, CORS_ALLOWED_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Accept');
        header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
        header('Access-Control-Allow-Credentials: false');

        // Vary by Origin so caches don't serve wrong CORS headers
        header('Vary: Origin');
    }

    // Handle preflight OPTIONS request.
    // Always respond to OPTIONS with 204, even if the origin isn't recognized.
    // The browser will enforce CORS based on the presence/absence of Allow-Origin.
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
