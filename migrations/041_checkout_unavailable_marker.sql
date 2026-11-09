-- Persists the reason for an unpaid booking closed after checkout inventory recheck.
-- Apply to the application database before enabling the customer-facing status label.
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS checkout_unavailable_at DATETIME NULL AFTER payment_step_expires_at;
