-- Persist PayMongo checkout attempts so repeated clicks/tabs reuse one active
-- checkout session. The unique checkout_key is derived from booking + payable
-- amount + amount already paid, so a later balance payment gets a new key.
CREATE TABLE IF NOT EXISTS booking_checkout_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    checkout_key VARCHAR(160) NOT NULL,
    provider_session_id VARCHAR(120) NULL,
    checkout_url VARCHAR(2048) NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    status VARCHAR(24) NOT NULL DEFAULT 'creating',
    provider_status VARCHAR(32) NULL,
    metadata_json JSON NULL,
    attempt_token CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkout_key (checkout_key),
    UNIQUE KEY uq_provider_checkout_session (provider_session_id),
    KEY ix_checkout_booking_status (booking_id, status, expires_at),
    CONSTRAINT fk_checkout_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
) ENGINE=InnoDB;
