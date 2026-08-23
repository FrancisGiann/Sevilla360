-- Preserve one current cancellation row per booking; snapshots live in history.
ALTER TABLE cancellations ADD COLUMN IF NOT EXISTS fee_percent DECIMAL(6,3) NOT NULL DEFAULT 3.000 AFTER fee_deducted;
-- The current row remains one-per-booking.  This is a no-op when the existing
-- UNIQUE cancellations.booking_id index is already present.
ALTER TABLE cancellations ADD UNIQUE INDEX IF NOT EXISTS booking_id (booking_id);

CREATE TABLE IF NOT EXISTS cancellation_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id INT NOT NULL,
    cancellation_id INT NULL,
    action VARCHAR(24) NOT NULL,
    reason TEXT NULL,
    refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fee_deducted DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    fee_percent DECIMAL(6,3) NOT NULL DEFAULT 3.000,
    admin_reply VARCHAR(500) NULL,
    actor_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cancellation_history_booking (booking_id, id),
    KEY idx_cancellation_history_current (cancellation_id, id)
) ENGINE=InnoDB;

-- Give pre-migration current rows one immutable baseline snapshot.  The
-- actor is intentionally NULL because the original actor is unavailable.
INSERT INTO cancellation_history (booking_id, cancellation_id, action, reason, refund_amount, fee_deducted, fee_percent, admin_reply)
SELECT c.booking_id, c.id,
       CASE c.status WHEN 'Rejected' THEN 'rejected' WHEN 'Processed' THEN 'processed' ELSE 'requested' END,
       c.reason, c.refund_amount, c.fee_deducted, c.fee_percent, c.admin_reply
FROM cancellations c
WHERE NOT EXISTS (
    SELECT 1 FROM cancellation_history h WHERE h.cancellation_id = c.id
);

-- Stable panorama targets survive deletion of an earlier panorama.
ALTER TABLE showroom_hotspots ADD COLUMN IF NOT EXISTS target_media_id INT NULL AFTER target_pano_index;
ALTER TABLE showroom_hotspots ADD INDEX IF NOT EXISTS idx_hotspots_target_media (target_media_id);

-- Backfill legacy positional targets using the historical id-ascending order
-- before any later panorama deletion can change their meaning.  MariaDB does
-- not accept a CTE directly before UPDATE, so join the ranked media as a
-- derived mapping table instead.  The NULL guard makes this safe to rerun
-- after a partial migration.
UPDATE showroom_hotspots h
JOIN (
    SELECT source_media.id AS source_media_id,
           target_media.id AS target_media_id,
           target_media.pano_index AS target_pano_index
    FROM (
        SELECT id, slot_assignment,
               ROW_NUMBER() OVER (PARTITION BY slot_assignment ORDER BY id ASC) - 1 AS pano_index
        FROM media_cms
        WHERE media_type = '360'
    ) source_media
    JOIN (
        SELECT id, slot_assignment,
               ROW_NUMBER() OVER (PARTITION BY slot_assignment ORDER BY id ASC) - 1 AS pano_index
        FROM media_cms
        WHERE media_type = '360'
    ) target_media
      ON target_media.slot_assignment = source_media.slot_assignment
) ranked_targets
  ON ranked_targets.source_media_id = h.media_id
 AND ranked_targets.target_pano_index = h.target_pano_index
SET h.target_media_id = ranked_targets.target_media_id
WHERE h.type = 'nav' AND h.target_media_id IS NULL AND h.target_pano_index IS NOT NULL;
