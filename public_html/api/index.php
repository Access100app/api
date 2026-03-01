<?php
/**
 * Access100 REST API - Router
 *
 * Lightweight PHP router for the Access100 API. No framework dependencies.
 * Matches the existing procedural PHP style of the codebase.
 *
 * Request lifecycle:
 *   1. Parse URI to extract version prefix and path
 *   2. Apply CORS middleware (handles OPTIONS preflight)
 *   3. Apply auth middleware (unless route is public)
 *   4. Apply rate limiting
 *   5. Route to the matching endpoint handler
 *   6. Endpoint returns JSON via json_response()
 *
 * URI format: /api/v1/{resource}[/{id}[/{sub-resource}]]
 *
 * Examples:
 *   GET  /api/v1/health
 *   GET  /api/v1/meetings
 *   GET  /api/v1/meetings/12345
 *   GET  /api/v1/meetings/12345/summary
 *   GET  /api/v1/councils
 *   POST /api/v1/subscriptions
 */

// ─── Bootstrap ───────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/middleware/cors.php';
require_once __DIR__ . '/middleware/auth.php';
require_once __DIR__ . '/middleware/rate-limit.php';

// ─── Parse Request ───────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];

// Get the request URI relative to the API base
// On Hostinger: REQUEST_URI might be /api/v1/meetings?foo=bar
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip the /api/v1 prefix to get the route path
// e.g., /api/v1/meetings/12345/summary -> /meetings/12345/summary
$route_path = $request_uri;
$prefix = '/api/v1';
if (strpos($route_path, $prefix) === 0) {
    $route_path = substr($route_path, strlen($prefix));
}

// Also handle if the request comes through without /api/v1 prefix
// (when .htaccess routes directly to this file within the api/ directory)
$alt_prefix = '/api';
if (strpos($request_uri, $alt_prefix) === 0 && strpos($request_uri, '/api/v1') !== 0) {
    // Request like /api/something -- redirect to versioned endpoint
    json_error(400, 'API version required. Use /api/v1/ prefix.');
}

// Normalize: ensure leading slash, remove trailing slash
$route_path = '/' . ltrim($route_path, '/');
$route_path = rtrim($route_path, '/');
if ($route_path === '') {
    $route_path = '/';
}

// Parse route segments: /resource/id/sub-resource
$segments = array_values(array_filter(explode('/', $route_path)));
$resource = $segments[0] ?? '';
$resource_id = $segments[1] ?? null;
$sub_resource = $segments[2] ?? null;


// ─── Middleware Chain ────────────────────────────────────────────

// 1. CORS — always runs first (handles OPTIONS preflight)
apply_cors();

// 2. Determine if this route requires authentication
//    Public routes do not require an API key.
$public_routes = [
    'health',
    'stats',
];

// Some specific sub-paths under protected resources are also public
// e.g., GET /subscriptions/confirm and GET /subscriptions/unsubscribe
$is_public = in_array($resource, $public_routes, true);
if ($resource === 'subscriptions' && in_array($resource_id, ['confirm', 'unsubscribe'], true)) {
    $is_public = true;
}
if ($resource === 'webhooks') {
    $is_public = true;
}

// 3. Auth — validate API key for protected routes
$api_key_label = 'public';
if (!$is_public) {
    $api_key_label = authenticate();
}

// 4. Rate limiting — applies to all requests
check_rate_limit($api_key_label);


// ─── Route Dispatch ──────────────────────────────────────────────

// Map resource names to endpoint files
$endpoint_map = [
    'health'        => __DIR__ . '/endpoints/health.php',
    'stats'         => __DIR__ . '/endpoints/stats.php',
    'meetings'      => __DIR__ . '/endpoints/meetings.php',
    'councils'      => __DIR__ . '/endpoints/councils.php',
    'topics'        => __DIR__ . '/endpoints/topics.php',
    'subscriptions' => __DIR__ . '/endpoints/subscriptions.php',
    'webhooks'      => __DIR__ . '/endpoints/webhooks.php',
    'admin'         => __DIR__ . '/endpoints/admin.php',
];

// Check if the resource exists
if (!isset($endpoint_map[$resource])) {
    // Handle root /api/v1/ request
    if ($resource === '' || $route_path === '/') {
        json_response([
            'name'      => 'Access100 API',
            'version'   => API_VERSION,
            'endpoints' => [
                'health'        => API_BASE_PATH . '/health',
                'stats'         => API_BASE_PATH . '/stats',
                'meetings'      => API_BASE_PATH . '/meetings',
                'councils'      => API_BASE_PATH . '/councils',
                'topics'        => API_BASE_PATH . '/topics',
                'subscriptions' => API_BASE_PATH . '/subscriptions',
                'webhooks'      => API_BASE_PATH . '/webhooks/twilio',
                'admin'         => API_BASE_PATH . '/admin/subscribers',
            ],
        ]);
    }

    // Sanitize the resource name before including in error message
    $safe_resource = preg_replace('/[^a-zA-Z0-9_-]/', '', $resource);
    json_error(404, 'Endpoint not found: /' . $safe_resource);
}

// Load and execute the endpoint
$endpoint_file = $endpoint_map[$resource];

if (!file_exists($endpoint_file)) {
    json_error(501, 'Endpoint not yet implemented: /' . $resource);
}

// Make route context available to endpoint handlers
$route = [
    'method'       => $method,
    'resource'     => $resource,
    'resource_id'  => $resource_id,
    'sub_resource' => $sub_resource,
    'segments'     => $segments,
    'query'        => $_GET,
    'api_key'      => $api_key_label,
];

require_once $endpoint_file;

// If we reach here, the endpoint didn't call json_response().
// This shouldn't happen in normal operation, but handle it gracefully.
json_error(500, 'Endpoint did not produce a response.');
