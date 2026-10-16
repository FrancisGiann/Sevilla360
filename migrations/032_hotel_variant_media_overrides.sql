-- Independent, reversible standard and 360 media overrides per commercial room option.
-- Existing explicit and legacy media slot assignments remain the shared fallback.
ALTER TABLE hotel_room_groups
    ADD COLUMN IF NOT EXISTS standard_media_override_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS tour_media_override_enabled TINYINT(1) NOT NULL DEFAULT 0;
