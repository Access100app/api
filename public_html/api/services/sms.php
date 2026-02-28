<?php
/**
 * Access100 API - SMS Service (Twilio)
 *
 * Twilio REST API integration for transactional SMS.
 * No external libraries — uses cURL directly (no Composer in this project).
 *
 * Functions:
 *   send_confirmation_sms($phone, $confirm_token)
 *   send_meeting_sms($phone, $meeting, $manage_token)
 *   send_digest_sms($phone, $meetings, $manage_token)
 *   handle_twilio_webhook()  — inbound SMS (YES to confirm, STOP to unsubscribe)
 *
 * Requires: config.php loaded (TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, TWILIO_FROM_NUMBER)
 */


// =====================================================================
// Confirmation SMS
// =====================================================================

/**
 * Send a double opt-in confirmation SMS.
 *
 * @param string $phone         Recipient phone in E.164 format (+1XXXXXXXXXX)
 * @param string $confirm_token 64-char confirmation token
 * @return bool True on success
 */
function send_confirmation_sms(string $phone, string $confirm_token): bool
{
    $confirm_url = API_BASE_URL . '/subscriptions/confirm?token=' . urlencode($confirm_token);

    $body = "civi.me: You subscribed to meeting alerts. "
        . "Tap to confirm: {$confirm_url}\n"
        . "Reply STOP to cancel.";

    return twilio_send($phone, $body);
}


// =====================================================================
// Meeting Notification SMS
// =====================================================================

/**
 * Send a single meeting alert SMS.
 *
 * @param string $phone        Recipient phone
 * @param array  $meeting      Meeting data (council_name, title, meeting_date, meeting_time, state_id)
 * @param string $manage_token User's manage token for unsubscribe
 * @return bool
 */
function send_meeting_sms(string $phone, array $meeting, string $manage_token): bool
{
    $council = $meeting['council_name'] ?? 'Council';
    $date    = date('M j', strtotime($meeting['meeting_date']));
    $time    = !empty($meeting['meeting_time'])
        ? date('g:i A', strtotime($meeting['meeting_time']))
        : 'TBD';

    $state_id = (int) ($meeting['state_id'] ?? 0);
    $url      = 'https://civi.me/m/' . $state_id;

    // SMS must be concise — 160 chars per segment
    $body = "civi.me: New meeting — {$council}, {$date} {$time}. "
        . "Details: {$url}\n"
        . "Reply STOP to unsubscribe.";

    return twilio_send($phone, $body);
}


// =====================================================================
// Digest SMS
// =====================================================================

/**
 * Send a digest SMS summarizing multiple new meetings.
 *
 * Keeps it short — just the count and a link to view all.
 *
 * @param string $phone        Recipient phone
 * @param array  $meetings     Array of meeting data
 * @param string $manage_token User's manage token
 * @return bool
 */
function send_digest_sms(string $phone, array $meetings, string $manage_token): bool
{
    $count = count($meetings);
    $url   = 'https://civi.me/meetings';

    $body = "civi.me: {$count} new meeting" . ($count !== 1 ? 's' : '')
        . " posted for councils you follow. "
        . "View: {$url}\n"
        . "Reply STOP to unsubscribe.";

    return twilio_send($phone, $body);
}


// =====================================================================
// Twilio Inbound SMS Webhook
// =====================================================================

/**
 * Handle inbound SMS from Twilio.
 *
 * Twilio POSTs form-encoded data with From, To, Body fields.
 * We handle:
 *   - YES / CONFIRM → confirm the subscription
 *   - STOP / CANCEL / UNSUBSCRIBE → deactivate (Twilio handles STOP natively,
 *     but we also process it server-side for our database)
 *
 * Responds with TwiML (XML) to send a reply SMS.
 */
function handle_twilio_webhook(): void
{
    // Twilio sends application/x-www-form-urlencoded
    $from = $_POST['From'] ?? '';
    $body = strtoupper(trim($_POST['Body'] ?? ''));

    if (empty($from)) {
        http_response_code(400);
        echo twiml_response('Invalid request.');
        exit;
    }

    // Normalize phone: ensure E.164
    $phone = $from;
    if (strpos($phone, '+') !== 0) {
        $phone = '+' . $phone;
    }

    // HELP keyword — required by CTIA/TCPA, no DB needed
    if (in_array($body, ['HELP', 'INFO'], true)) {
        echo twiml_response('civi.me meeting alerts. For help visit civi.me/help or email help@civi.me. Msg frequency varies. Msg & data rates may apply. Reply STOP to cancel.');
        exit;
    }

    try {
        $pdo = get_db();

        if (in_array($body, ['YES', 'CONFIRM', 'Y'], true)) {
            // Confirm subscription
            $stmt = $pdo->prepare("
                SELECT id FROM users WHERE phone = ? AND confirmed_phone = FALSE LIMIT 1
            ");
            $stmt->execute([$phone]);
            $user = $stmt->fetch();

            if ($user) {
                $pdo->prepare("UPDATE users SET confirmed_phone = TRUE, confirm_token = NULL WHERE id = ?")
                    ->execute([$user['id']]);
                echo twiml_response('Confirmed! You will now receive meeting alerts from civi.me.');
            } else {
                echo twiml_response('No pending subscription found for this number.');
            }
            exit;
        }

        if (in_array($body, ['STOP', 'CANCEL', 'UNSUBSCRIBE', 'QUIT', 'END'], true)) {
            // Twilio's built-in STOP handling will block future messages automatically.
            // We also deactivate in our database so we don't queue messages for this user.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
            $stmt->execute([$phone]);
            $user = $stmt->fetch();

            if ($user) {
                $pdo->prepare("UPDATE subscriptions SET active = FALSE WHERE user_id = ?")
                    ->execute([$user['id']]);
                error_log('Twilio STOP: deactivated subscriptions for user ' . $user['id']);
            }

            // Twilio handles the STOP reply automatically — don't send our own
            http_response_code(200);
            echo '<Response></Response>';
            exit;
        }

        // Unrecognized message
        echo twiml_response('civi.me meeting alerts. Reply YES to confirm, STOP to unsubscribe.');
        exit;

    } catch (PDOException $e) {
        error_log('Twilio webhook DB error: ' . $e->getMessage());
        echo twiml_response('Sorry, something went wrong. Please try again later.');
        exit;
    }
}

/**
 * Wrap a message in TwiML <Response><Message> XML.
 */
function twiml_response(string $message): string
{
    header('Content-Type: text/xml; charset=utf-8');
    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Response><Message>' . htmlspecialchars($message) . '</Message></Response>';
}


// =====================================================================
// Twilio REST API Transport
// =====================================================================

/**
 * Send an SMS via Twilio's REST API.
 *
 * @param string $to   Recipient phone in E.164 format
 * @param string $body Message body (max ~1600 chars, auto-segmented by Twilio)
 * @return bool True on success (201 response), false on failure
 */
function twilio_send(string $to, string $body): bool
{
    if (TWILIO_ACCOUNT_SID === 'CHANGE_ME' || TWILIO_AUTH_TOKEN === 'CHANGE_ME') {
        error_log('Twilio: credentials not configured — skipping SMS to ' . $to);
        return false;
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_ACCOUNT_SID . '/Messages.json';

    $post_fields = http_build_query([
        'To'   => $to,
        'From' => TWILIO_FROM_NUMBER,
        'Body' => $body,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_POSTFIELDS     => $post_fields,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('Twilio cURL error: ' . $curl_error);
        return false;
    }

    // Twilio returns 201 Created on success
    if ($http_code === 201) {
        return true;
    }

    error_log('Twilio API error (HTTP ' . $http_code . '): ' . $response);
    return false;
}
