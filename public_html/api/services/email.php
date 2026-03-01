<?php
/**
 * Access100 API - Email Service (Gmail API)
 *
 * Gmail API integration via OAuth2 refresh token. No external libraries
 * — uses cURL directly (no Composer in this project).
 *
 * Functions:
 *   send_confirmation_email($email, $confirm_token, $source)
 *   send_meeting_notification($email, $meeting, $manage_token, $source)
 *   send_digest($email, $meetings, $manage_token, $frequency, $source)
 *
 * Requires: config.php loaded (GMAIL_CLIENT_ID, GMAIL_CLIENT_SECRET,
 *           GMAIL_REFRESH_TOKEN, GMAIL_FROM_EMAIL, GMAIL_FROM_NAME, API_BASE_URL)
 */


// =====================================================================
// Gmail API Transport
// =====================================================================

/**
 * Get an OAuth2 access token for the Gmail API using a refresh token.
 *
 * Exchanges the stored refresh token for a short-lived access token.
 * Caches the token in a static variable for the lifetime of the request.
 *
 * @return string|false Access token on success, false on failure
 */
function get_gmail_access_token(): string|false
{
    static $cached_token = null;
    static $cached_expiry = 0;

    if ($cached_token !== null && time() < $cached_expiry - 30) {
        return $cached_token;
    }

    if (GMAIL_CLIENT_ID === 'CHANGE_ME' || GMAIL_REFRESH_TOKEN === 'CHANGE_ME') {
        error_log('Gmail: OAuth2 credentials not configured — skipping email');
        return false;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => GMAIL_CLIENT_ID,
            'client_secret' => GMAIL_CLIENT_SECRET,
            'refresh_token' => GMAIL_REFRESH_TOKEN,
            'grant_type'    => 'refresh_token',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('Gmail: Token refresh cURL error: ' . $curl_error);
        return false;
    }

    if ($http_code !== 200) {
        error_log('Gmail: Token refresh failed (HTTP ' . $http_code . '): ' . $response);
        return false;
    }

    $token_data = json_decode($response, true);
    if (empty($token_data['access_token'])) {
        error_log('Gmail: Token refresh returned no access_token');
        return false;
    }

    $cached_token  = $token_data['access_token'];
    $cached_expiry = time() + ($token_data['expires_in'] ?? 3600);

    return $cached_token;
}

/**
 * Base64url-encode a string (URL-safe, no padding).
 *
 * @param string $data Raw data
 * @return string Base64url-encoded string
 */
function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Build an RFC 2822 MIME message (multipart/alternative: text + HTML).
 *
 * @param string      $from_email     Sender email
 * @param string      $from_name      Sender display name
 * @param string      $to_email       Recipient email
 * @param string      $subject        Email subject
 * @param string      $html_content   HTML body
 * @param string      $text_content   Plain text body
 * @param string|null $unsubscribe_url One-click unsubscribe URL (RFC 8058)
 * @return string Raw RFC 2822 message
 */
function build_mime_message(
    string $from_email,
    string $from_name,
    string $to_email,
    string $subject,
    string $html_content,
    string $text_content,
    ?string $unsubscribe_url = null
): string {
    $boundary = 'boundary_' . bin2hex(random_bytes(16));

    $headers  = "From: {$from_name} <{$from_email}>\r\n";
    $headers .= "To: {$to_email}\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

    // RFC 8058 List-Unsubscribe headers (CAN-SPAM / Gmail one-click unsubscribe)
    if ($unsubscribe_url !== null) {
        $headers .= "List-Unsubscribe: <{$unsubscribe_url}>\r\n";
        $headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
    }

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($text_content) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html_content) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    return $headers . "\r\n" . $body;
}

/**
 * Send an email via the Gmail API using a service account.
 *
 * @param string      $to_email       Recipient email
 * @param string      $subject        Email subject
 * @param string      $html_content   HTML body
 * @param string      $text_content   Plain text body
 * @param string|null $unsubscribe_url One-click unsubscribe URL (RFC 8058)
 * @return bool True on success (2xx response), false on failure
 */
function gmail_send(
    string $to_email,
    string $subject,
    string $html_content,
    string $text_content,
    ?string $unsubscribe_url = null
): bool {
    $access_token = get_gmail_access_token();
    if ($access_token === false) {
        error_log('Gmail: Cannot send — failed to get access token. Skipping email to ' . $to_email);
        return false;
    }

    $raw_message = build_mime_message(
        GMAIL_FROM_EMAIL,
        GMAIL_FROM_NAME,
        $to_email,
        $subject,
        $html_content,
        $text_content,
        $unsubscribe_url
    );

    $encoded_message = base64url_encode($raw_message);

    $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['raw' => $encoded_message]),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('Gmail: cURL error sending to ' . $to_email . ': ' . $curl_error);
        return false;
    }

    if ($http_code >= 200 && $http_code < 300) {
        return true;
    }

    error_log('Gmail: API error (HTTP ' . $http_code . ') sending to ' . $to_email . ': ' . $response);
    return false;
}


// =====================================================================
// Confirmation Email
// =====================================================================

/**
 * Send a double opt-in confirmation email.
 *
 * @param string $email         Recipient email address
 * @param string $confirm_token 64-char confirmation token
 * @param string $source        'civime' or 'access100' — determines branding
 * @return bool True on success, false on failure
 */
function send_confirmation_email(string $email, string $confirm_token, string $source = 'access100'): bool
{
    $confirm_url = API_BASE_URL . '/subscriptions/confirm?token=' . urlencode($confirm_token);

    $subject = 'Confirm your meeting notification subscription';

    $html = email_layout('Confirm Your Subscription', '
        <p>You (or someone using your email) subscribed to government meeting notifications.</p>
        <p>Click the button below to confirm your subscription:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 24px 0;">
            <tr>
                <td style="background-color: #0d6efd; border-radius: 6px;">
                    <a href="' . htmlspecialchars($confirm_url) . '" style="display: inline-block; padding: 12px 24px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 16px;">Confirm Subscription</a>
                </td>
            </tr>
        </table>
        <p style="font-size: 13px; color: #6c757d;">If the button doesn\'t work, copy and paste this link into your browser:</p>
        <p style="font-size: 13px; color: #6c757d; word-break: break-all;">' . htmlspecialchars($confirm_url) . '</p>
        <p style="font-size: 13px; color: #6c757d;">If you didn\'t request this, you can safely ignore this email.</p>
    ', $source);

    $text = "Confirm your meeting notification subscription.\n\n"
        . "Click this link to confirm: {$confirm_url}\n\n"
        . "If you didn't request this, you can safely ignore this email.";

    return gmail_send($email, $subject, $html, $text);
}


// =====================================================================
// Meeting Notification Email
// =====================================================================

/**
 * Send a single meeting notification email.
 *
 * @param string $email        Recipient email
 * @param array  $meeting      Meeting data (title, meeting_date, meeting_time, location, council_name, state_id)
 * @param string $manage_token User's manage token for unsubscribe link
 * @param string $source       'civime' or 'access100'
 * @return bool
 */
function send_meeting_notification(string $email, array $meeting, string $manage_token, string $source = 'access100'): bool
{
    $date_formatted = date('l, F j, Y', strtotime($meeting['meeting_date']));
    $time_formatted = !empty($meeting['meeting_time'])
        ? date('g:i A', strtotime($meeting['meeting_time']))
        : 'Time TBD';

    $council_name = htmlspecialchars($meeting['council_name'] ?? 'Government Council');
    $title        = htmlspecialchars($meeting['title'] ?? 'Meeting');
    $location     = htmlspecialchars(strip_tags($meeting['location'] ?? 'Location TBD'));
    $state_id     = (int) ($meeting['state_id'] ?? 0);

    $meeting_url     = 'https://civi.me/meetings/' . $state_id;
    $unsubscribe_url = API_BASE_URL . '/subscriptions/unsubscribe?token=' . urlencode($manage_token);

    $subject = "New Meeting: {$meeting['council_name']} — " . date('M j', strtotime($meeting['meeting_date']));

    $html = email_layout('New Meeting Posted', '
        <p>A new meeting has been posted for a council you follow.</p>
        <div style="background-color: #f8f9fa; border-left: 4px solid #0d6efd; padding: 16px; margin: 16px 0; border-radius: 4px;">
            <p style="font-weight: 600; font-size: 16px; margin: 0 0 4px 0; color: #212529;">' . $council_name . '</p>
            <p style="font-size: 15px; margin: 0 0 8px 0; color: #212529;">' . $title . '</p>
            <p style="margin: 0 0 4px 0; color: #495057;">' . $date_formatted . ' at ' . $time_formatted . '</p>
            <p style="margin: 0; color: #495057;">' . $location . '</p>
        </div>
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 16px 0;">
            <tr>
                <td style="background-color: #0d6efd; border-radius: 6px;">
                    <a href="' . htmlspecialchars($meeting_url) . '" style="display: inline-block; padding: 10px 20px; color: #ffffff; text-decoration: none; font-weight: 600;">View Details &amp; Agenda</a>
                </td>
            </tr>
        </table>
    ', $source, $unsubscribe_url);

    $text = "New Meeting: {$meeting['council_name']}\n"
        . "{$meeting['title']}\n"
        . "{$date_formatted} at {$time_formatted}\n"
        . "Location: " . strip_tags($meeting['location'] ?? 'TBD') . "\n\n"
        . "View details: {$meeting_url}\n\n"
        . "Unsubscribe: {$unsubscribe_url}";

    return gmail_send($email, $subject, $html, $text, $unsubscribe_url);
}


// =====================================================================
// Digest Email (daily/weekly)
// =====================================================================

/**
 * Send a digest email with multiple meetings.
 *
 * @param string $email        Recipient email
 * @param array  $meetings     Array of meeting data arrays
 * @param string $manage_token User's manage token
 * @param string $frequency    'daily' or 'weekly'
 * @param string $source       'civime' or 'access100'
 * @return bool
 */
function send_digest(string $email, array $meetings, string $manage_token, string $frequency = 'daily', string $source = 'access100'): bool
{
    $count = count($meetings);
    $label = ($frequency === 'weekly') ? 'Weekly' : 'Daily';
    $subject = "{$label} Digest: {$count} new meeting" . ($count !== 1 ? 's' : '') . ' posted';

    $unsubscribe_url = API_BASE_URL . '/subscriptions/unsubscribe?token=' . urlencode($manage_token);

    // Build meeting list HTML
    $meetings_html = '';
    $meetings_text = '';

    foreach ($meetings as $m) {
        $date_fmt = date('D, M j', strtotime($m['meeting_date']));
        $time_fmt = !empty($m['meeting_time']) ? date('g:i A', strtotime($m['meeting_time'])) : 'TBD';
        $council  = htmlspecialchars($m['council_name'] ?? '');
        $title    = htmlspecialchars($m['title'] ?? '');
        $url      = 'https://civi.me/meetings/' . (int) ($m['state_id'] ?? 0);

        $meetings_html .= '
            <tr>
                <td style="padding: 12px 0; border-bottom: 1px solid #e9ecef;">
                    <p style="font-weight: 600; margin: 0 0 2px 0; color: #212529;">' . $council . '</p>
                    <p style="margin: 0 0 4px 0;"><a href="' . htmlspecialchars($url) . '" style="color: #0d6efd; text-decoration: none;">' . $title . '</a></p>
                    <p style="font-size: 13px; color: #6c757d; margin: 0;">' . $date_fmt . ' at ' . $time_fmt . '</p>
                </td>
            </tr>';

        $meetings_text .= "- {$m['council_name']}: {$m['title']}\n"
            . "  {$date_fmt} at {$time_fmt}\n"
            . "  {$url}\n\n";
    }

    $html = email_layout("{$label} Meeting Digest", '
        <p>' . $count . ' new meeting' . ($count !== 1 ? 's have' : ' has') . ' been posted for councils you follow.</p>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin: 16px 0;">
            ' . $meetings_html . '
        </table>
    ', $source, $unsubscribe_url);

    $text = "{$label} Meeting Digest — {$count} new meeting(s)\n\n"
        . $meetings_text
        . "Unsubscribe: {$unsubscribe_url}";

    return gmail_send($email, $subject, $html, $text, $unsubscribe_url);
}


// =====================================================================
// Admin Notification Email
// =====================================================================

/**
 * Send a plain-text admin notification email.
 *
 * Delivers to GMAIL_FROM_EMAIL (the admin address). Used to alert
 * the admin when someone subscribes or confirms.
 *
 * @param string $subject Email subject line
 * @param string $body    Plain-text email body
 * @return bool True on success, false on failure
 */
function send_admin_notification(string $subject, string $body): bool
{
    $to = GMAIL_FROM_EMAIL;
    $html = '<pre style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 14px; line-height: 1.6; white-space: pre-wrap;">'
        . htmlspecialchars($body) . '</pre>';

    return gmail_send($to, $subject, $html, $body);
}


// =====================================================================
// HTML Email Template
// =====================================================================

/**
 * Wrap email content in a responsive, accessible HTML layout.
 *
 * Branded per source (civi.me or Access100).
 *
 * @param string      $heading         Email heading text
 * @param string      $body_html       Inner HTML content
 * @param string      $source          'civime' or 'access100'
 * @param string|null $unsubscribe_url Unsubscribe link for footer
 * @return string Complete HTML email
 */
function email_layout(string $heading, string $body_html, string $source = 'access100', ?string $unsubscribe_url = null): string
{
    $brand_name  = ($source === 'civime') ? 'civi.me' : 'Access100';
    $brand_url   = ($source === 'civime') ? 'https://civi.me' : 'https://access100.app';
    $brand_color = ($source === 'civime') ? '#0d6efd' : '#198754';

    $footer_links = '';
    if ($unsubscribe_url) {
        $manage_url = 'https://civi.me/notifications/manage';
        $footer_links = '
            <p style="font-size: 13px; color: #6c757d; margin: 4px 0;">
                <a href="' . htmlspecialchars($manage_url) . '" style="color: #6c757d;">Manage preferences</a>
                &nbsp;&middot;&nbsp;
                <a href="' . htmlspecialchars($unsubscribe_url) . '" style="color: #6c757d;">Unsubscribe</a>
            </p>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($heading) . '</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: #212529; line-height: 1.6;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 24px 16px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: ' . $brand_color . '; padding: 20px 24px;">
                            <a href="' . htmlspecialchars($brand_url) . '" style="color: #ffffff; text-decoration: none; font-size: 18px; font-weight: 600;">' . htmlspecialchars($brand_name) . '</a>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding: 24px;">
                            <h1 style="font-size: 20px; font-weight: 600; margin: 0 0 16px 0; color: #212529;">' . htmlspecialchars($heading) . '</h1>
                            ' . $body_html . '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 16px 24px; border-top: 1px solid #e9ecef; text-align: center;">
                            <p style="font-size: 13px; color: #6c757d; margin: 4px 0;">
                                You\'re receiving this because you subscribed at ' . htmlspecialchars($brand_name) . '.
                            </p>
                            ' . $footer_links . '
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}
