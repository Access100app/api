<?php
/**
 * Access100 API - Email Service (SendGrid)
 *
 * SendGrid HTTP API v3 integration for transactional email.
 * No external libraries — uses cURL directly (no Composer in this project).
 *
 * Functions:
 *   send_confirmation_email($email, $confirm_token, $source)
 *   send_meeting_notification($email, $meeting, $manage_token, $source)
 *   send_digest($email, $meetings, $manage_token, $frequency, $source)
 *   handle_sendgrid_webhook()  — bounce/complaint handler
 *
 * Requires: config.php loaded (SENDGRID_API_KEY, SENDGRID_FROM_*, API_BASE_URL)
 */


// =====================================================================
// Confirmation Email
// =====================================================================

/**
 * Send a double opt-in confirmation email.
 *
 * @param string $email         Recipient email address
 * @param string $confirm_token 64-char confirmation token
 * @param string $source        'civime' or 'access100' — determines sender identity
 * @return bool True on success, false on failure
 */
function send_confirmation_email(string $email, string $confirm_token, string $source = 'access100'): bool
{
    $confirm_url = API_BASE_URL . '/subscriptions/confirm?token=' . urlencode($confirm_token);
    $from_email  = ($source === 'civime') ? SENDGRID_FROM_CIVIME : SENDGRID_FROM_ACCESS100;
    $from_name   = ($source === 'civime') ? 'civi.me' : 'Access100';

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

    return sendgrid_send($from_email, $from_name, $email, $subject, $html, $text);
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
    $from_email = ($source === 'civime') ? SENDGRID_FROM_CIVIME : SENDGRID_FROM_ACCESS100;
    $from_name  = ($source === 'civime') ? 'civi.me' : 'Access100';

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

    return sendgrid_send(
        $from_email, $from_name, $email, $subject, $html, $text,
        $unsubscribe_url
    );
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
    $from_email = ($source === 'civime') ? SENDGRID_FROM_CIVIME : SENDGRID_FROM_ACCESS100;
    $from_name  = ($source === 'civime') ? 'civi.me' : 'Access100';

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

    return sendgrid_send(
        $from_email, $from_name, $email, $subject, $html, $text,
        $unsubscribe_url
    );
}


// =====================================================================
// SendGrid Webhook Handler (bounces + complaints)
// =====================================================================

/**
 * Process SendGrid event webhook to handle bounces and spam complaints.
 *
 * Call this from a dedicated webhook endpoint. SendGrid POSTs an array
 * of event objects. We look for bounce/dropped/spam_report events and
 * deactivate the affected user's subscriptions.
 *
 * @return void Outputs JSON response
 */
function handle_sendgrid_webhook(): void
{
    $raw = file_get_contents('php://input');
    $events = json_decode($raw, true);

    if (!is_array($events)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit;
    }

    $deactivate_types = ['bounce', 'dropped', 'spamreport'];
    $affected_emails  = [];

    foreach ($events as $event) {
        if (isset($event['event']) && in_array($event['event'], $deactivate_types, true)) {
            $email = $event['email'] ?? null;
            if ($email && !in_array($email, $affected_emails, true)) {
                $affected_emails[] = $email;
            }
        }
    }

    if (!empty($affected_emails)) {
        try {
            $pdo = get_db();
            $placeholders = implode(',', array_fill(0, count($affected_emails), '?'));

            // Deactivate all subscriptions for bounced/complained users
            $stmt = $pdo->prepare("
                UPDATE subscriptions SET active = FALSE
                WHERE user_id IN (
                    SELECT id FROM users WHERE email IN ({$placeholders})
                )
            ");
            $stmt->execute($affected_emails);

            error_log('SendGrid webhook: deactivated subscriptions for ' . count($affected_emails) . ' email(s)');
        } catch (PDOException $e) {
            error_log('SendGrid webhook DB error: ' . $e->getMessage());
        }
    }

    http_response_code(200);
    echo json_encode(['processed' => count($affected_emails)]);
    exit;
}


// =====================================================================
// SendGrid HTTP API v3 Transport
// =====================================================================

/**
 * Send an email via SendGrid's HTTP API v3.
 *
 * @param string      $from_email     Sender email
 * @param string      $from_name      Sender display name
 * @param string      $to_email       Recipient email
 * @param string      $subject        Email subject
 * @param string      $html_content   HTML body
 * @param string      $text_content   Plain text body
 * @param string|null $unsubscribe_url One-click unsubscribe URL (RFC 8058)
 * @return bool True on success (2xx response), false on failure
 */
function sendgrid_send(
    string $from_email,
    string $from_name,
    string $to_email,
    string $subject,
    string $html_content,
    string $text_content,
    ?string $unsubscribe_url = null
): bool {
    if (SENDGRID_API_KEY === 'CHANGE_ME') {
        error_log('SendGrid: API key not configured — skipping email to ' . $to_email);
        return false;
    }

    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $to_email]],
            ],
        ],
        'from' => [
            'email' => $from_email,
            'name'  => $from_name,
        ],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/plain', 'value' => $text_content],
            ['type' => 'text/html',  'value' => $html_content],
        ],
    ];

    // RFC 8058 List-Unsubscribe headers (CAN-SPAM compliance)
    if ($unsubscribe_url !== null) {
        $payload['headers'] = [
            'List-Unsubscribe'      => '<' . $unsubscribe_url . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . SENDGRID_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response    = curl_exec($ch);
    $http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error  = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        error_log('SendGrid cURL error: ' . $curl_error);
        return false;
    }

    // SendGrid returns 202 Accepted on success
    if ($http_code >= 200 && $http_code < 300) {
        return true;
    }

    error_log('SendGrid API error (HTTP ' . $http_code . '): ' . $response);
    return false;
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
