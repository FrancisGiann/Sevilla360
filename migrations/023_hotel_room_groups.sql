-- Add canonical hotel room types and sellable commercial groups. Existing
-- room_type text is retained for reports and integrations during rollout.

CREATE TABLE IF NOT EXISTS hotel_room_types (
    type_code VARCHAR(32) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    comfort_rank TINYINT UNSIGNED NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (type_code),
    UNIQUE KEY uq_hotel_room_types_display_name (display_name),
    UNIQUE KEY uq_hotel_room_types_comfort_rank (comfort_rank),
    UNIQUE KEY uq_hotel_room_types_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO hotel_room_types (type_code, display_name, comfort_rank, active, sort_order) VALUES
    ('standard_room', 'Standard Room', 1, 1, 1),
    ('dormitory_room', 'Dormitory Room', 2, 1, 2),
    ('family_room_superior', 'Family Room / Superior', 3, 1, 3),
    ('deluxe', 'Deluxe', 4, 1, 4),
    ('vip_suite', 'VIP Suite', 5, 1, 5);

CREATE TABLE IF NOT EXISTS hotel_room_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    commercial_key CHAR(64) NOT NULL,
    building_name VARCHAR(150) NOT NULL,
    room_type_code VARCHAR(32) NULL,
    legacy_room_type VARCHAR(100) NULL,
    base_capacity INT UNSIGNED NOT NULL,
    max_capacity INT UNSIGNED NOT NULL,
    bed_count INT UNSIGNED NOT NULL,
    nightly_rate DECIMAL(12,2) NOT NULL,
    extra_pax_rate DECIMAL(12,2) NOT NULL,
    check_in_time TIME NOT NULL,
    check_out_time TIME NOT NULL,
    occupancy_mode VARCHAR(24) NULL,
    bathroom_mode VARCHAR(16) NULL,
    floor_area_sqm DECIMAL(8,2) NULL,
    recommendation_ready TINYINT(1) NOT NULL DEFAULT 0,
    media_slot_key VARCHAR(80) NULL,
    display_name VARCHAR(250) NOT NULL DEFAULT '',
    description TEXT NULL,
    amenities TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hotel_room_groups_commercial_key (commercial_key),
    KEY idx_hotel_room_groups_media_slot (media_slot_key),
    KEY idx_hotel_room_groups_recommendation (recommendation_ready, max_capacity, sort_order, id),
    KEY idx_hotel_room_groups_type (room_type_code),
    CONSTRAINT fk_hotel_room_groups_type FOREIGN KEY (room_type_code)
        REFERENCES hotel_room_types (type_code) ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hotel_rooms
    ADD COLUMN IF NOT EXISTS room_type_code VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER room_type,
    ADD COLUMN IF NOT EXISTS room_group_id BIGINT UNSIGNED NULL AFTER room_type_code;

ALTER TABLE booking_rooms
    ADD COLUMN IF NOT EXISTS room_group_id BIGINT UNSIGNED NULL AFTER venue_id;

-- Existing installations may use MariaDB's newer default collation on
-- hotel_rooms while the canonical catalogue deliberately uses unicode_ci.
-- Normalize the FK column before joining/backfilling; skip when already safe.
SET @hotel_collation_sql = IF(
    (SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'hotel_rooms'
       AND column_name = 'room_type_code' AND collation_name <> 'utf8mb4_unicode_ci') > 0,
    'ALTER TABLE hotel_rooms MODIFY room_type_code VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER room_type',
    'SELECT 1'
);
PREPARE hotel_collation_stmt FROM @hotel_collation_sql;
EXECUTE hotel_collation_stmt;
DEALLOCATE PREPARE hotel_collation_stmt;

-- Map only exact legacy room_type values. Unknown free-text legacy types remain
-- unclassified and are excluded from recommendations until an administrator
-- assigns one of the fixed types in Manage Venues.
UPDATE hotel_rooms
SET room_type_code = CASE LOWER(TRIM(room_type))
    WHEN 'standard room' THEN 'standard_room'
    WHEN 'standard' THEN 'standard_room'
    WHEN 'dormitory room' THEN 'dormitory_room'
    WHEN 'dormitory' THEN 'dormitory_room'
    WHEN 'family room / superior' THEN 'family_room_superior'
    WHEN 'family room/superior' THEN 'family_room_superior'
    WHEN 'family room' THEN 'family_room_superior'
    WHEN 'superior' THEN 'family_room_superior'
    WHEN 'deluxe' THEN 'deluxe'
    WHEN 'deluxe room' THEN 'deluxe'
    WHEN 'vip suite' THEN 'vip_suite'
    WHEN 'vip' THEN 'vip_suite'
    ELSE NULL
END
WHERE room_type_code IS NULL;

-- A commercial variant is exactly building + canonical (or retained legacy)
-- type + capacities + beds + rates + check-in/out. Privacy, bathroom mode and
-- area are deliberately left NULL because the legacy schema did not store them.
INSERT IGNORE INTO hotel_room_groups (
    commercial_key, building_name, room_type_code, legacy_room_type,
    base_capacity, max_capacity, bed_count, nightly_rate, extra_pax_rate,
    check_in_time, check_out_time, recommendation_ready, sort_order,
    display_name, description, amenities
)
SELECT variants.commercial_key, variants.building_name, variants.room_type_code, variants.legacy_room_type,
    variants.base_capacity, variants.max_capacity, variants.bed_count, variants.nightly_rate, variants.extra_pax_rate,
    variants.check_in_time, variants.check_out_time, 0, 0,
    CONCAT(variants.building_name, ' — ', COALESCE(types.display_name, variants.legacy_room_type, 'Hotel Room')),
    source_venue.description, source_venue.amenities
FROM (
    SELECT
        SHA2(CONCAT_WS(CHAR(31),
            v.name,
            COALESCE(h.room_type_code, ''),
            COALESCE(CASE WHEN h.room_type_code IS NULL THEN TRIM(h.room_type) ELSE '' END, ''),
            CAST(h.base_capacity AS CHAR),
            CAST(h.max_capacity AS CHAR),
            CAST(h.bed_count AS CHAR),
            CAST(CAST(h.nightly_rate AS DECIMAL(12,2)) AS CHAR),
            CAST(CAST(h.extra_pax_rate AS DECIMAL(12,2)) AS CHAR),
            TIME_FORMAT(h.check_in_time, '%H:%i:%s'),
            TIME_FORMAT(h.check_out_time, '%H:%i:%s')
        ), 256) AS commercial_key,
        v.name AS building_name,
        h.room_type_code,
        CASE WHEN h.room_type_code IS NULL THEN TRIM(h.room_type) ELSE NULL END AS legacy_room_type,
        h.base_capacity,
        h.max_capacity,
        h.bed_count,
        h.nightly_rate,
        h.extra_pax_rate,
        h.check_in_time,
        h.check_out_time,
        MIN(v.id) AS source_venue_id
    FROM venues v
    INNER JOIN hotel_rooms h ON h.venue_id = v.id
    WHERE v.category = 'Hotel Room'
    GROUP BY v.name, h.room_type_code,
        CASE WHEN h.room_type_code IS NULL THEN TRIM(h.room_type) ELSE NULL END,
        h.base_capacity, h.max_capacity, h.bed_count, h.nightly_rate,
        h.extra_pax_rate, h.check_in_time, h.check_out_time
) AS variants
INNER JOIN venues source_venue ON source_venue.id = variants.source_venue_id
LEFT JOIN hotel_room_types types ON types.type_code = variants.room_type_code;

UPDATE hotel_rooms h
INNER JOIN venues v ON v.id = h.venue_id AND v.category = 'Hotel Room'
INNER JOIN hotel_room_groups g ON g.commercial_key = SHA2(CONCAT_WS(CHAR(31),
    v.name,
    COALESCE(h.room_type_code, ''),
    COALESCE(CASE WHEN h.room_type_code IS NULL THEN TRIM(h.room_type) ELSE '' END, ''),
    CAST(h.base_capacity AS CHAR),
    CAST(h.max_capacity AS CHAR),
    CAST(h.bed_count AS CHAR),
    CAST(CAST(h.nightly_rate AS DECIMAL(12,2)) AS CHAR),
    CAST(CAST(h.extra_pax_rate AS DECIMAL(12,2)) AS CHAR),
    TIME_FORMAT(h.check_in_time, '%H:%i:%s'),
    TIME_FORMAT(h.check_out_time, '%H:%i:%s')
), 256)
SET h.room_group_id = g.id;

UPDATE booking_rooms br
INNER JOIN hotel_rooms h ON h.venue_id = br.venue_id
SET br.room_group_id = h.room_group_id
WHERE br.room_group_id IS NULL;

-- MySQL and MariaDB do not both support ADD CONSTRAINT IF NOT EXISTS. Check
-- information_schema so operators can safely rerun this additive migration.
SET @hotel_fk_sql = IF(
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'hotel_rooms' AND constraint_name = 'fk_hotel_rooms_type_code') = 0,
    'ALTER TABLE hotel_rooms ADD CONSTRAINT fk_hotel_rooms_type_code FOREIGN KEY (room_type_code) REFERENCES hotel_room_types (type_code) ON UPDATE RESTRICT ON DELETE RESTRICT',
    'SELECT 1'
);
PREPARE hotel_fk_stmt FROM @hotel_fk_sql;
EXECUTE hotel_fk_stmt;
DEALLOCATE PREPARE hotel_fk_stmt;

SET @hotel_fk_sql = IF(
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'hotel_rooms' AND constraint_name = 'fk_hotel_rooms_room_group') = 0,
    'ALTER TABLE hotel_rooms ADD CONSTRAINT fk_hotel_rooms_room_group FOREIGN KEY (room_group_id) REFERENCES hotel_room_groups (id) ON UPDATE RESTRICT ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE hotel_fk_stmt FROM @hotel_fk_sql;
EXECUTE hotel_fk_stmt;
DEALLOCATE PREPARE hotel_fk_stmt;

SET @hotel_fk_sql = IF(
    (SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE constraint_schema = DATABASE() AND table_name = 'booking_rooms' AND constraint_name = 'fk_booking_rooms_room_group') = 0,
    'ALTER TABLE booking_rooms ADD CONSTRAINT fk_booking_rooms_room_group FOREIGN KEY (room_group_id) REFERENCES hotel_room_groups (id) ON UPDATE RESTRICT ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE hotel_fk_stmt FROM @hotel_fk_sql;
EXECUTE hotel_fk_stmt;
DEALLOCATE PREPARE hotel_fk_stmt;

-- Indexes are added through guarded DDL for rerun safety.
SET @hotel_index_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'hotel_rooms' AND index_name = 'idx_hotel_rooms_group') = 0,
    'ALTER TABLE hotel_rooms ADD INDEX idx_hotel_rooms_group (room_group_id, venue_id)',
    'SELECT 1'
);
PREPARE hotel_index_stmt FROM @hotel_index_sql;
EXECUTE hotel_index_stmt;
DEALLOCATE PREPARE hotel_index_stmt;

-- Add overlap indexes only when no existing index already has the required
-- leading-column sequence. Each named DDL is also safe to rerun.
SET @hotel_index_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'booking_rooms'
        GROUP BY index_name
        HAVING SUM(seq_in_index = 1 AND column_name = 'venue_id') > 0
           AND SUM(seq_in_index = 2 AND column_name = 'start_date') > 0
           AND SUM(seq_in_index = 3 AND column_name = 'end_date') > 0
    ) AS existing_overlap_indexes) = 0,
    'ALTER TABLE booking_rooms ADD INDEX idx_booking_rooms_overlap (venue_id, start_date, end_date)',
    'SELECT 1'
);
PREPARE hotel_index_stmt FROM @hotel_index_sql;
EXECUTE hotel_index_stmt;
DEALLOCATE PREPARE hotel_index_stmt;

SET @hotel_index_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'maintenance'
        GROUP BY index_name
        HAVING SUM(seq_in_index = 1 AND column_name = 'venue_id') > 0
           AND SUM(seq_in_index = 2 AND column_name = 'is_blocking') > 0
           AND SUM(seq_in_index = 3 AND column_name = 'status') > 0
           AND SUM(seq_in_index = 4 AND column_name = 'start_date') > 0
           AND SUM(seq_in_index = 5 AND column_name = 'end_date') > 0
    ) AS existing_overlap_indexes) = 0,
    'ALTER TABLE maintenance ADD INDEX idx_maintenance_overlap (venue_id, is_blocking, status, start_date, end_date)',
    'SELECT 1'
);
PREPARE hotel_index_stmt FROM @hotel_index_sql;
EXECUTE hotel_index_stmt;
DEALLOCATE PREPARE hotel_index_stmt;

SET @hotel_index_sql = IF(
    (SELECT COUNT(*) FROM (
        SELECT index_name FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'booking_locks'
        GROUP BY index_name
        HAVING SUM(seq_in_index = 1 AND column_name = 'venue_id') > 0
           AND SUM(seq_in_index = 2 AND column_name = 'expires_at') > 0
           AND SUM(seq_in_index = 3 AND column_name = 'start_date') > 0
           AND SUM(seq_in_index = 4 AND column_name = 'end_date') > 0
           AND SUM(seq_in_index = 5 AND column_name = 'session_id') > 0
    ) AS existing_overlap_indexes) = 0,
    'ALTER TABLE booking_locks ADD INDEX idx_booking_locks_overlap (venue_id, expires_at, start_date, end_date, session_id)',
    'SELECT 1'
);
PREPARE hotel_index_stmt FROM @hotel_index_sql;
EXECUTE hotel_index_stmt;
DEALLOCATE PREPARE hotel_index_stmt;

SET @hotel_index_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'booking_rooms' AND index_name = 'idx_booking_rooms_group') = 0,
    'ALTER TABLE booking_rooms ADD INDEX idx_booking_rooms_group (room_group_id, venue_id)',
    'SELECT 1'
);
PREPARE hotel_index_stmt FROM @hotel_index_sql;
EXECUTE hotel_index_stmt;
DEALLOCATE PREPARE hotel_index_stmt;
