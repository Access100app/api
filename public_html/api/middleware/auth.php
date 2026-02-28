<?php
/**
 * Access100 API - Authentication Middleware
 *
 * Validates the X-API-Key header against configured API keys.
 *
 * Public endpoints (health, stats, unsubscribe confirmations) can
 * bypass auth by being listed in the $public_routes array in the router.
 * This middleware only runs on protected routes.
 *
 * Requires: config.php (for API_KEYS constant and json_error())
 */

/**
 * Validate API key authentication.
 *
 * Checks the X-API-Key header against the API_KEYS constant defined
 * in config.php. Uses timing-safe comparison to prevent timing attacks.
 *
 * On success, returns the label associated with the API key (for logging).
 * On failure, sends a 401 JSON error and exits.
 *
 * @return string The label/name associated with the authenticated API key
 */
function authenticate(): string
{
    $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';

    if ($provided_key === '') {
        json_error(401, 'Missing API key. Include an X-API-Key header.');
    }

    // Check against all configured keys using timing-safe comparison
    foreach (API_KEYS as $valid_key => $label) {
        if (hash_equals($valid_key, $provided_key)) {
            return $label;
        }
    }

    // No match found
    json_error(401, 'Invalid API key.');

    // Unreachable, but makes static analysis happy
    return '';
}
