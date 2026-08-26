-- Store operational stay details in the venue model used by Manage Venues.
-- The ALTER statements are MariaDB-safe to rerun; the legacy Villa backfill
-- is guarded by the exact pre-migration value so administrator edits survive.
ALTER TABLE venues MODIFY COLUMN amenities TEXT NULL;

ALTER TABLE hotel_rooms
    ADD COLUMN IF NOT EXISTS check_in_time TIME NOT NULL DEFAULT '14:00:00' AFTER extra_pax_rate,
    ADD COLUMN IF NOT EXISTS check_out_time TIME NOT NULL DEFAULT '12:00:00' AFTER check_in_time;

ALTER TABLE villas
    ADD COLUMN IF NOT EXISTS day_check_in_time TIME NOT NULL DEFAULT '07:00:00' AFTER has_private_pool,
    ADD COLUMN IF NOT EXISTS day_check_out_time TIME NOT NULL DEFAULT '17:00:00' AFTER day_check_in_time,
    ADD COLUMN IF NOT EXISTS overnight_check_in_time TIME NOT NULL DEFAULT '14:00:00' AFTER day_check_out_time,
    ADD COLUMN IF NOT EXISTS overnight_check_out_time TIME NOT NULL DEFAULT '12:00:00' AFTER overnight_check_in_time,
    ADD COLUMN IF NOT EXISTS day_stay_inclusions TEXT NULL AFTER overnight_check_out_time,
    ADD COLUMN IF NOT EXISTS overnight_stay_inclusions TEXT NULL AFTER day_stay_inclusions;

-- Preserve the existing managed amenities while migrating the legacy online
-- Villa details into the data model. This exact-value guard is idempotent and
-- leaves any administrator-edited amenity list untouched.
UPDATE venues
SET amenities = 'Free wifi, access to pool, netflix, breakfast of 2, TV, Bed, Airconditioner, Hot and cold shower, Refrigerator, Toiletry items (Toothbrush, toothpaste, soap), Small private swimming pool, Garden'
WHERE id = 3
  AND category = 'Resort Villa'
  AND amenities = 'Free wifi, access to pool, netflix, breakfast of 2';

UPDATE villas
SET overnight_stay_inclusions = 'Complimentary breakfast for 4 persons'
WHERE venue_id = 3
  AND (overnight_stay_inclusions IS NULL OR TRIM(overnight_stay_inclusions) = '');

-- Normalize only active, future Villa bookings whose persisted stay type gives
-- an unambiguous date shape. Historical/completed/cancelled rows are retained
-- exactly as recorded. The mismatch guards make this safe to rerun.
UPDATE bookings b
INNER JOIN venues v ON v.id = b.venue_id AND v.category = 'Resort Villa'
INNER JOIN booking_villa_details d ON d.booking_id = b.id
SET b.end_date = CASE
    WHEN d.stay_type = 'Day Time Stay' THEN b.start_date
    WHEN d.stay_type = 'Overnight' THEN DATE_ADD(b.start_date, INTERVAL 1 DAY)
    ELSE b.end_date
END
WHERE b.booking_status IN ('Pending', 'Confirmed')
  AND (b.source IS NULL OR b.source <> 'Maintenance')
  AND b.start_date >= CURRENT_DATE()
  AND (
      (d.stay_type = 'Day Time Stay' AND b.end_date <> b.start_date)
      OR (d.stay_type = 'Overnight' AND b.end_date <> DATE_ADD(b.start_date, INTERVAL 1 DAY))
  );
