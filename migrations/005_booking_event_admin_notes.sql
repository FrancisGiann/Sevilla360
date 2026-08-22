-- Store staff-only preparation notes separately from customer requests.
ALTER TABLE booking_event_details
    ADD COLUMN IF NOT EXISTS admin_notes TEXT DEFAULT NULL AFTER custom_notes;
