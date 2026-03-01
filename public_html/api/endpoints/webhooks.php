<?php
/**
 * Access100 API - Webhooks Endpoint
 *
 * Handles inbound webhooks from third-party services:
 *   POST /api/v1/webhooks/twilio — Twilio inbound SMS (YES, STOP)
 *
 * These routes are public (no API key) because the services POST to them.
 *
 * Requires: $route array from index.php, config.php loaded
 */

require_once __DIR__ . '/../services/sms.php';

$sub_route = $route['resource_id'];

if ($sub_route === 'twilio' && $route['method'] === 'POST') {
    handle_twilio_webhook();
} else {
    json_error(404, 'Webhook route not found.');
}
