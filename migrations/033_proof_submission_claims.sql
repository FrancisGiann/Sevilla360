-- New online hotel/villa requests are nonexclusive until their first proof.
-- Existing Pending/Confirmed rows retain their historical reservation meaning.
ALTER TABLE bookings
    MODIFY COLUMN booking_status ENUM('Pending', 'Confirmed', 'Cancelled', 'Completed', 'Awaiting Proof') NULL DEFAULT 'Pending';

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS proof_first_submitted_at DATETIME NULL AFTER payment_due_at,
    ADD COLUMN IF NOT EXISTS proof_rejections_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER proof_first_submitted_at,
    ADD COLUMN IF NOT EXISTS proof_retry_submitted_at DATETIME NULL AFTER proof_rejections_count,
    ADD COLUMN IF NOT EXISTS proof_inventory_conflict TINYINT(1) NOT NULL DEFAULT 0 AFTER proof_retry_submitted_at;

-- Preserve every prior submission/review snapshot before an existing row is
-- replaced or reviewed. The current manual_payment_submissions row remains the
-- operational pointer so existing staff/customer views continue to work.
CREATE TABLE IF NOT EXISTS manual_payment_submission_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    booking_id INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    customer_user_id INT NOT NULL,
    reviewer_user_id INT NULL,
    payment_id INT NULL,
    payment_method VARCHAR(32) NOT NULL,
    expected_amount DECIMAL(10,2) NOT NULL,
    transaction_reference VARCHAR(64) NOT NULL,
    reference_fingerprint CHAR(64) NOT NULL,
    proof_filename VARCHAR(80) NOT NULL,
    proof_mime VARCHAR(32) NOT NULL,
    proof_size_bytes INT UNSIGNED NOT NULL,
    proof_sha256 CHAR(64) NOT NULL,
    rejection_reason VARCHAR(500) NULL,
    submitted_at DATETIME NOT NULL,
    reviewed_at DATETIME NULL,
    archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_manual_proof_history_booking (booking_id, archived_at),
    KEY idx_manual_proof_history_submission (submission_id),
    KEY idx_manual_proof_history_reference (reference_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
