-- Record only newly submitted registration consent; existing accounts remain NULL.
ALTER TABLE users ADD COLUMN IF NOT EXISTS consented_at DATETIME NULL AFTER created_at;

-- Preserve booking policy evidence without changing historical bookings.
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS policy_accepted_at DATETIME NULL AFTER updated_at;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS policy_version VARCHAR(64) NULL AFTER policy_accepted_at;
