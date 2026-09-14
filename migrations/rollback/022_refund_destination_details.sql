-- DESTRUCTIVE ROLLBACK: permanently deletes refund destinations collected by
-- migration 022. Export those operational details before running this script.
ALTER TABLE cancellations
    DROP COLUMN IF EXISTS refund_destination_bank_name,
    DROP COLUMN IF EXISTS refund_destination_account_identifier,
    DROP COLUMN IF EXISTS refund_destination_account_name,
    DROP COLUMN IF EXISTS refund_destination_method;
