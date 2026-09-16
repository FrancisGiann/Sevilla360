-- Guard each table independently so custom snapshots are never coerced or lost.
SELECT COUNT(*) INTO @custom_manual_submission_method_count
FROM manual_payment_submissions
WHERE BINARY payment_method NOT IN (BINARY 'GCash', BINARY 'Maya', BINARY 'Bank Transfer');

SET @rollback_manual_submission_method_sql = IF(
    @custom_manual_submission_method_count = 0,
    'ALTER TABLE manual_payment_submissions MODIFY payment_method ENUM(''GCash'', ''Maya'', ''Bank Transfer'') NOT NULL',
    'SELECT ''Rollback blocked: custom payment method submissions exist; preserve VARCHAR(120).'' AS rollback_status'
);

PREPARE rollback_manual_submission_method_statement FROM @rollback_manual_submission_method_sql;
EXECUTE rollback_manual_submission_method_statement;
DEALLOCATE PREPARE rollback_manual_submission_method_statement;

SELECT COUNT(*) INTO @custom_payment_method_count
FROM payments
WHERE BINARY payment_method NOT IN (BINARY 'Cash', BINARY 'GCash', BINARY 'Maya', BINARY 'Bank Transfer', BINARY 'PayMongo', BINARY 'Card');

SET @rollback_payment_method_sql = IF(
    @custom_payment_method_count = 0,
    'ALTER TABLE payments MODIFY payment_method ENUM(''Cash'', ''GCash'', ''Maya'', ''Bank Transfer'', ''PayMongo'', ''Card'') NOT NULL',
    'SELECT ''Rollback blocked: custom payment method values exist; preserve VARCHAR(120).'' AS rollback_status'
);

PREPARE rollback_payment_method_statement FROM @rollback_payment_method_sql;
EXECUTE rollback_payment_method_statement;
DEALLOCATE PREPARE rollback_payment_method_statement;
