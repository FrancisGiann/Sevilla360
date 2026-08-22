-- Maintenance availability is owned by the maintenance table.
-- Remove only the internal booking locks created by the old workflow.
START TRANSACTION;

DELETE b
FROM bookings b
LEFT JOIN customers c ON c.id = b.customer_id
WHERE b.source = 'Maintenance'
  AND (
      b.reference_no LIKE 'MAINT-%'
      OR (c.first_name = 'SYSTEM' AND c.last_name = 'MAINTENANCE')
  );

COMMIT;
