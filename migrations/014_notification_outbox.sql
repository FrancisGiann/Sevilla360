-- Transactional outbox for optional WebSocket/Redis delivery. Existing
-- polling remains the fallback when the gateway or Redis is unavailable.
CREATE TABLE IF NOT EXISTS notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id CHAR(48) NOT NULL,
    channel VARCHAR(80) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload_json JSON NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    claimed_at DATETIME NULL,
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_outbox_event (event_id),
    KEY ix_notification_outbox_pending (published_at, claimed_at, id),
    KEY ix_notification_outbox_channel (channel, id)
) ENGINE=InnoDB;
