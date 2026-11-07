-- PayMongo original-payment refunds. Apply only after 038 in the isolated
-- PayMongo application database; do not run against production until reviewed.

ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS fee_cents BIGINT UNSIGNED NULL AFTER amount,
    ADD COLUMN IF NOT EXISTS net_amount_cents BIGINT UNSIGNED NULL AFTER fee_cents,
    ADD COLUMN IF NOT EXISTS fee_verified_at DATETIME(6) NULL AFTER net_amount_cents;

ALTER TABLE cancellations
    ADD COLUMN IF NOT EXISTS refund_gross_amount DECIMAL(10,2) NULL AFTER refund_amount,
    ADD COLUMN IF NOT EXISTS refund_provider VARCHAR(24) NULL AFTER refund_gross_amount,
    ADD COLUMN IF NOT EXISTS refund_provider_status VARCHAR(30) NULL AFTER refund_provider,
    ADD COLUMN IF NOT EXISTS refund_policy_version VARCHAR(48) NULL AFTER refund_provider_status,
    ADD COLUMN IF NOT EXISTS refund_snapshot_id CHAR(32) NULL AFTER refund_policy_version;

CREATE TABLE IF NOT EXISTS pm_refund_snapshots (
    id CHAR(32) NOT NULL PRIMARY KEY,
    cancellation_id INT NOT NULL,
    booking_id INT NOT NULL,
    gross_cents BIGINT UNSIGNED NOT NULL,
    fee_cents BIGINT UNSIGNED NOT NULL,
    prior_refund_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refund_cents BIGINT UNSIGNED NOT NULL,
    policy_version VARCHAR(48) NOT NULL,
    created_by INT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    INDEX ix_pm_refund_snapshot_booking (booking_id, created_at),
    INDEX ix_pm_refund_snapshot_cancellation (cancellation_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pm_refund_allocations (
    id CHAR(32) NOT NULL PRIMARY KEY,
    snapshot_id CHAR(32) NOT NULL,
    payment_id VARCHAR(100) NOT NULL,
    gross_cents BIGINT UNSIGNED NOT NULL,
    fee_cents BIGINT UNSIGNED NOT NULL,
    prior_refund_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
    refund_cents BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_pm_refund_allocation_payment (snapshot_id, payment_id),
    INDEX ix_pm_refund_allocation_payment (payment_id),
    INDEX ix_pm_refund_allocation_snapshot (snapshot_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pm_refund_attempts (
    id CHAR(32) NOT NULL PRIMARY KEY,
    allocation_id CHAR(32) NOT NULL,
    correlation_note VARCHAR(255) NOT NULL,
    provider_refund_id VARCHAR(100) NULL,
    status ENUM('ready','creating','uncertain','pending','processing','succeeded','failed','review') NOT NULL DEFAULT 'ready',
    error_code VARCHAR(100) NULL,
    create_started_at DATETIME(6) NULL,
    reconciled_at DATETIME(6) NULL,
    provider_updated_at BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_pm_refund_attempt_correlation (correlation_note),
    UNIQUE KEY uq_pm_refund_attempt_provider_id (provider_refund_id),
    INDEX ix_pm_refund_attempt_allocation (allocation_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pm_refund_events (
    id VARCHAR(100) NOT NULL PRIMARY KEY,
    digest CHAR(64) NOT NULL,
    outcome VARCHAR(80) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;
