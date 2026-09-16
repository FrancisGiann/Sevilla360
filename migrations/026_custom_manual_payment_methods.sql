-- Preserve the submitted display name while allowing administrator-managed methods.
-- Existing legacy enum values in both tables are copied unchanged.
ALTER TABLE manual_payment_submissions
    MODIFY payment_method VARCHAR(120) NOT NULL;

ALTER TABLE payments
    MODIFY payment_method VARCHAR(120) NOT NULL;
