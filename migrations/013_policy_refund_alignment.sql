-- Sevilla360 additive policy/refund alignment (MariaDB 10.3+)
-- Safe to run repeatedly. Only the known legacy fallback text is changed;
-- administrator-authored policy content is left untouched.

INSERT INTO system_settings (setting_key, setting_value)
VALUES ('refund_fee_percent', '3.00')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

DELETE FROM system_settings
WHERE setting_key = 'paymongo_fee';

UPDATE system_settings
SET setting_value = '• Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).
• Please bring a valid Government ID matching the name on this itinerary.
• Paid customer cancellation/refund requests are subject to the configurable payment-processing fee shown at request time; the fee percentage and refund amount are snapshotted when the request is submitted.
• Admin-initiated force cancellations receive a 100% refund; the resort absorbs any processing fee.'
WHERE setting_key = 'biz_policies'
  AND setting_value = '• Standard Check-in is at 2:00 PM. Check-out is at 12:00 PM (Unless booking Day Time Stay).
• Please bring a valid Government ID matching the name on this itinerary.
• Cancellations made less than 7 days before arrival are subject to fees.';
