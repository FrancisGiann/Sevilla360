-- Store the transaction reference entered when an administrator processes a refund.
ALTER TABLE cancellations
    ADD COLUMN IF NOT EXISTS refund_transaction_id VARCHAR(255) NULL AFTER refund_amount;
