-- Preserve the contact number used when each booking was created.
ALTER TABLE bookings ADD COLUMN contact_phone VARCHAR(32) NULL AFTER guests_count;
UPDATE bookings b JOIN customers c ON c.id = b.customer_id SET b.contact_phone = c.phone WHERE b.contact_phone IS NULL AND c.phone IS NOT NULL AND c.phone <> '';
