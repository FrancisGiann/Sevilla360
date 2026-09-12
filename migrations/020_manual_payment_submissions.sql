-- Manual proof-of-payment workflow. All DDL is repeat-safe on MariaDB.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_due_at DATETIME NULL AFTER payment_status;

ALTER TABLE bookings
    ADD INDEX IF NOT EXISTS idx_bookings_payment_due_at (payment_due_at);

CREATE TABLE IF NOT EXISTS manual_payment_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    customer_user_id INT NOT NULL,
    reviewer_user_id INT NULL,
    payment_id INT NULL,
    payment_method ENUM('GCash', 'Maya', 'Bank Transfer') NOT NULL,
    expected_amount DECIMAL(10,2) NOT NULL,
    transaction_reference VARCHAR(64) NOT NULL,
    reference_fingerprint CHAR(64) NOT NULL,
    proof_filename VARCHAR(80) NOT NULL,
    proof_mime VARCHAR(32) NOT NULL,
    proof_size_bytes INT UNSIGNED NOT NULL,
    proof_sha256 CHAR(64) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(500) NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_manual_payment_reference_fingerprint (reference_fingerprint),
    UNIQUE KEY uq_manual_payment_proof_filename (proof_filename),
    UNIQUE KEY uq_manual_payment_payment_id (payment_id),
    KEY idx_manual_payment_booking_status (booking_id, status, submitted_at),
    KEY idx_manual_payment_status_submitted (status, submitted_at),
    KEY idx_manual_payment_customer_submitted (customer_user_id, submitted_at),
    CONSTRAINT fk_manual_payment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),
    CONSTRAINT fk_manual_payment_customer FOREIGN KEY (customer_user_id) REFERENCES users(id),
    CONSTRAINT fk_manual_payment_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_manual_payment_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL,
    CONSTRAINT chk_manual_payment_amount CHECK (expected_amount > 0),
    CONSTRAINT chk_manual_payment_proof_size CHECK (proof_size_bytes BETWEEN 1 AND 5242880),
    CONSTRAINT chk_manual_payment_rejection CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES ('manual_payment_deadline_hours', '24', 'Hours to submit or resubmit manual payment proof')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

INSERT INTO system_settings (setting_key, setting_value, description)
VALUES ('manual_payment_instructions', '{"gcash":{"enabled":false,"account_name":"","account_number":"","details":"","qr_path":""},"maya":{"enabled":false,"account_name":"","account_number":"","details":"","qr_path":""},"bank_transfer":{"enabled":false,"account_name":"","account_number":"","details":"","qr_path":""}}', 'Customer payment instructions for GCash, Maya, and bank transfer')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);

-- Give already-open wholly-unpaid online holds one full configuration window
-- after cutover. An Event Hall inquiry stays deadline-free until quoted.
SET @manual_payment_hours = (
    SELECT IF(setting_value REGEXP '^[1-9][0-9]{0,2}$' AND CAST(setting_value AS UNSIGNED) <= 168,
              CAST(setting_value AS UNSIGNED), 24)
    FROM system_settings WHERE setting_key = 'manual_payment_deadline_hours' LIMIT 1
);

UPDATE bookings b
JOIN venues v ON v.id = b.venue_id
SET b.payment_due_at = DATE_ADD(NOW(), INTERVAL @manual_payment_hours HOUR)
WHERE b.payment_due_at IS NULL
  AND b.source = 'Online'
  AND b.amount_paid = 0
  AND b.payment_status = 'Unpaid'
  AND b.booking_status IN ('Pending', 'Confirmed')
  AND NOT (v.category = 'Event Hall' AND b.booking_status = 'Pending');
