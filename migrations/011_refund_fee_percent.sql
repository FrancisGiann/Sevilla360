-- Authoritative configurable payment-processing/refund fee.
INSERT INTO system_settings (setting_key, setting_value, description)
VALUES ('refund_fee_percent', '3.0', 'Percentage deducted from paid amount when calculating customer refunds.')
ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key);
