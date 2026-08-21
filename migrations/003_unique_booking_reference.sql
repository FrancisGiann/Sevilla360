-- Run once after confirming there are no existing duplicate reference numbers.
-- This makes payment webhook lookup unambiguous even in the extremely unlikely
-- event that a generated reference collides.
ALTER TABLE bookings
    ADD CONSTRAINT uq_bookings_reference_no UNIQUE (reference_no);
