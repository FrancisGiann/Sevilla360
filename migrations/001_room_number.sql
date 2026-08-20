-- ============================================================
-- Migration 001: Add room_number to hotel_rooms
-- Safe migration — adds nullable column, backfills, preserves all rows.
-- Run once. Verify COUNT(*) = COUNT(room_number) before making NOT NULL.
-- ============================================================

-- Step 1: Add nullable column (non-breaking, existing rows unaffected)
ALTER TABLE hotel_rooms ADD COLUMN room_number VARCHAR(20) NULL AFTER room_type;

-- Step 2: Backfill deterministic sequential numbers per (building name, room_type).
-- Uses correlated subquery approach: compatible with MySQL 5.7+ and MariaDB.
-- Each physical room gets a zero-padded sequential number within its group.
UPDATE hotel_rooms hr
JOIN (
    SELECT 
        h2.venue_id,
        (
            SELECT COUNT(*) + 1
            FROM hotel_rooms h3
            JOIN venues v3 ON v3.id = h3.venue_id
            JOIN venues v4 ON v4.id = h2.venue_id
            WHERE v3.name = v4.name 
              AND h3.room_type = h2.room_type 
              AND h3.venue_id < h2.venue_id
        ) AS rn
    FROM hotel_rooms h2
) sub ON hr.venue_id = sub.venue_id
SET hr.room_number = LPAD(sub.rn, 3, '0');

-- Step 3: Verify — both counts should match before proceeding.
-- SELECT COUNT(*) AS total_rooms, COUNT(room_number) AS rooms_with_number FROM hotel_rooms;

-- Step 4 (DEFERRED): After verification, make NOT NULL:
-- ALTER TABLE hotel_rooms MODIFY room_number VARCHAR(20) NOT NULL;

-- Step 5 (OPTIONAL): Add unique constraint per (building + room_type + room_number):
-- ALTER TABLE hotel_rooms
--   ADD CONSTRAINT uq_room_identity UNIQUE (venue_id); -- already unique (PK)
-- The uniqueness is enforced at application level: same building+type+number = reject.
