-- Add optional structured context to new audit events. Historical rows remain valid.
ALTER TABLE audit_logs
    ADD COLUMN IF NOT EXISTS event_type VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS entity_type VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS entity_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS details_json JSON NULL;
