-- Store customer-provided outbound refund instructions. Existing requests stay
-- nullable and are handled by the application as legacy/incomplete requests.
ALTER TABLE cancellations
    ADD COLUMN IF NOT EXISTS refund_destination_method VARCHAR(24) NULL AFTER fee_percent;

ALTER TABLE cancellations
    ADD COLUMN IF NOT EXISTS refund_destination_account_name VARCHAR(120) NULL AFTER refund_destination_method;

ALTER TABLE cancellations
    ADD COLUMN IF NOT EXISTS refund_destination_account_identifier VARCHAR(64) NULL AFTER refund_destination_account_name;

ALTER TABLE cancellations
    ADD COLUMN IF NOT EXISTS refund_destination_bank_name VARCHAR(100) NULL AFTER refund_destination_account_identifier;
