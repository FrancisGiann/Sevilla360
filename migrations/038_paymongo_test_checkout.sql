-- 038: apply only to the isolated capstone application database after existing migrations.
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS quote_version INT NOT NULL DEFAULT 0, ADD COLUMN IF NOT EXISTS quote_accepted TINYINT NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS pm_capstone_identity (purpose VARCHAR(60) PRIMARY KEY);
INSERT IGNORE INTO pm_capstone_identity VALUES ('capstone-test-v1');
CREATE TABLE IF NOT EXISTS pm_attempts (
 id CHAR(32) PRIMARY KEY, booking_id INT NOT NULL, snapshot CHAR(64) NOT NULL,
 amount_cents BIGINT NOT NULL, base_paid_cents BIGINT NOT NULL, hold_token CHAR(32),
 expires_at DATETIME NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'creating',
 provider_session_id VARCHAR(100) UNIQUE, checkout_url VARCHAR(2048), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(booking_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS pm_events (id VARCHAR(100) PRIMARY KEY, digest CHAR(64) NOT NULL, outcome VARCHAR(80), created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6)) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS pm_receipts (
 payment_id VARCHAR(100) PRIMARY KEY, attempt_id CHAR(32) NOT NULL,
 amount_cents BIGINT NOT NULL, currency VARCHAR(8) NOT NULL,
 disposition VARCHAR(80) NOT NULL, created_at DATETIME(6) DEFAULT CURRENT_TIMESTAMP(6),
 INDEX(attempt_id)
) ENGINE=InnoDB;

-- Restored checkout authority must never follow a rewound event ledger.
ALTER TABLE pm_attempts ADD COLUMN IF NOT EXISTS restore_epoch VARCHAR(64) NOT NULL DEFAULT '0';
