-- Migration 004: Reminders table
-- One-time meeting reminders (morning-of email notification)

CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    meeting_id INT NOT NULL,
    confirm_token VARCHAR(64) NOT NULL,
    confirmed BOOLEAN DEFAULT FALSE,
    sent BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP NULL,
    source VARCHAR(50) DEFAULT 'access100',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email_meeting (email, meeting_id),
    FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
    INDEX idx_confirmed_sent (confirmed, sent),
    INDEX idx_confirm_token (confirm_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
