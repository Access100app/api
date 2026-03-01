# TCPA / SMS Compliance Plan — Access100 / civi.me

## Regulatory Context

The Telephone Consumer Protection Act (TCPA) and Cellular Telecommunications Industry Association (CTIA) guidelines govern all SMS communications. Violations can result in $500–$1,500 per unsolicited message. This plan covers what we've built, what's still needed, and operational requirements.

---

## What's Already Implemented (Code)

| Requirement | Status | Where |
|-------------|--------|-------|
| Double opt-in (confirmation SMS before any alerts) | Done | `services/sms.php:send_confirmation_sms()` |
| STOP keyword deactivates subscriptions | Done | `services/sms.php:handle_twilio_webhook()` — STOP/CANCEL/UNSUBSCRIBE/QUIT/END |
| Twilio native STOP handling (carrier-level block) | Done | Twilio does this automatically on US numbers |
| Sender identification in every message | Done | All messages start with "civi.me:" |
| Unsubscribe instruction in every message | Done | "Reply STOP to unsubscribe" on all outbound |
| Opt-in recorded in database | Done | `users.confirmed_phone`, subscription timestamps |
| One-click unsubscribe via link | Done | `GET /subscriptions/unsubscribe?token=xxx` |

---

## What Still Needs to Be Done

### 1. Consent Language on Subscribe Form (civi.me — WordPress side)

The subscribe form on civi.me must include explicit consent language **before** the submit button. This is the single most important TCPA requirement.

**Required text (at or near the phone number field):**

> By providing your phone number and checking "Text Message," you consent to receive automated meeting notification text messages from civi.me at this number. Message frequency varies. Message and data rates may apply. Reply STOP to cancel. Reply HELP for help. See our [Privacy Policy](https://civi.me/privacy) and [Terms](https://civi.me/terms).

**Requirements:**
- [ ] Must be visible before form submission (not hidden in a modal)
- [ ] Checkbox for SMS must be unchecked by default (user actively opts in)
- [ ] Consent language must be "clear and conspicuous"
- [ ] Cannot be bundled with other consents (must be separate from email opt-in)

### 2. HELP Keyword Response

Twilio webhook must respond to HELP with carrier-required information.

- [ ] Add HELP handling to `handle_twilio_webhook()` in `services/sms.php`

**Required HELP response:**
> civi.me meeting alerts. For help visit civi.me/help or email help@civi.me. Msg frequency varies. Msg & data rates may apply. Reply STOP to cancel.

### 3. Quiet Hours Enforcement

TCPA prohibits calls/texts before 8:00 AM and after 9:00 PM in the recipient's local time zone. Since all our users are in Hawaii (HST, UTC-10):

- [ ] Add time check to notification cron (`cron/notify.php`) before sending SMS
- [ ] Queue SMS notifications outside 8 AM–9 PM HST for delivery at 8:00 AM next day
- [ ] Email notifications are NOT subject to quiet hours

**Implementation:**
```php
function is_sms_quiet_hours(): bool {
    $hst = new DateTimeZone('Pacific/Honolulu');
    $hour = (int) (new DateTime('now', $hst))->format('G');
    return ($hour < 8 || $hour >= 21);
}
```

### 4. Consent Record Keeping

TCPA requires retaining proof of consent for 5 years. We need to log:

- [ ] Add `consent_log` table to track opt-in events:

```sql
CREATE TABLE consent_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    channel ENUM('email','sms') NOT NULL,
    action ENUM('opt_in','opt_out','confirm') NOT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    source VARCHAR(50) DEFAULT NULL,
    consent_text TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_consent_user (user_id),
    INDEX idx_consent_date (created_at)
);
```

- [ ] Log opt-in when `POST /subscriptions` is called with SMS channel
- [ ] Log confirmation when user replies YES
- [ ] Log opt-out when user replies STOP
- [ ] Store the IP address and user agent from the original subscribe request
- [ ] Store the exact consent language shown at time of opt-in

### 5. Privacy Policy Updates (civi.me)

The privacy policy at `civi.me/privacy` must include:

- [ ] What phone numbers are collected and why
- [ ] That automated text messages will be sent
- [ ] Message frequency disclosure ("frequency varies based on government meeting schedules")
- [ ] That message and data rates may apply
- [ ] How to opt out (reply STOP)
- [ ] That phone numbers are never shared with third parties
- [ ] Data retention period (5 years for consent records)
- [ ] Contact information for questions

### 6. Twilio Number Registration

Twilio requires registration for A2P (Application-to-Person) messaging to avoid carrier filtering:

- [ ] Register for a Twilio A2P 10DLC campaign (required for US numbers since 2023)
- [ ] Brand registration: Access100 / civi.me
- [ ] Campaign use case: "Government meeting notifications — informational alerts"
- [ ] Estimated volume: Low (under 1,000 messages/day)
- [ ] Sample messages must be submitted to Twilio for approval
- [ ] Without 10DLC registration, messages may be filtered/blocked by carriers

### 7. Terms of Service Page (civi.me)

- [ ] Create `civi.me/terms` page with SMS-specific terms:
  - Service description (government meeting alerts)
  - Message frequency (varies)
  - Data rates disclaimer
  - Opt-out instructions
  - Contact: help@civi.me
  - Compatible carriers list (or "all major US carriers")

---

## Operational Checklist (Before Sending First SMS)

1. [ ] Consent language added to civi.me subscribe form
2. [ ] HELP keyword response implemented
3. [ ] Quiet hours check added to notification cron
4. [ ] Consent logging table created and wired in
5. [ ] Privacy policy updated with SMS disclosures
6. [ ] Terms of service page created
7. [ ] Twilio A2P 10DLC campaign registered and approved
8. [ ] Twilio phone number purchased and assigned
9. [ ] Twilio webhook URL configured: `https://access100.app/api/v1/webhooks/twilio`
10. [ ] Test full flow: subscribe → confirm SMS → YES reply → meeting alert → STOP → deactivation

---

## Message Templates (for Twilio 10DLC approval)

**Confirmation:**
> civi.me: You subscribed to meeting alerts. Tap to confirm: https://access100.app/api/v1/subscriptions/confirm?token=xxx Reply STOP to cancel.

**Meeting alert:**
> civi.me: New meeting — Board of Education, Mar 15 1:30 PM. Details: https://civi.me/m/12345 Reply STOP to unsubscribe.

**Digest:**
> civi.me: 3 new meetings posted for councils you follow. View: https://civi.me/meetings Reply STOP to unsubscribe.

**HELP response:**
> civi.me meeting alerts. For help visit civi.me/help or email help@civi.me. Msg frequency varies. Msg & data rates may apply. Reply STOP to cancel.

---

## References

- [TCPA Full Text (47 USC § 227)](https://www.law.cornell.edu/uscode/text/47/227)
- [CTIA Messaging Principles and Best Practices](https://www.ctia.org/the-wireless-industry/industry-commitments/messaging-interoperability-sms-mms)
- [Twilio A2P 10DLC Registration](https://www.twilio.com/docs/messaging/guides/10dlc)
- [FCC TCPA Guidance](https://www.fcc.gov/consumers/guides/stop-unwanted-robocalls-and-texts)
