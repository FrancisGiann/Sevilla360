-- Apply after the production-applied 035_dual_channel_recovery.sql.
-- A short, token-owned hold exists only while an online customer is at payment.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS payment_step_token CHAR(32) NULL AFTER proof_inventory_conflict,
    ADD COLUMN IF NOT EXISTS payment_step_expires_at DATETIME NULL AFTER payment_step_token,
    ADD COLUMN IF NOT EXISTS payment_step_seen_at DATETIME NULL AFTER payment_step_expires_at;

ALTER TABLE bookings
    ADD INDEX IF NOT EXISTS idx_bookings_payment_step_expiry (payment_step_expires_at);

ALTER TABLE booking_locks
    ADD INDEX IF NOT EXISTS idx_payment_step_lock_owner (session_id, source, expires_at);

-- Covers a Back/close request that reaches the server before an in-flight acquire.
CREATE TABLE IF NOT EXISTS payment_step_exits (
    token CHAR(32) NOT NULL PRIMARY KEY,
    booking_id INT NOT NULL,
    exited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_step_exits_date (exited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
