-- Establish a database-level idempotency guard for payment webhooks.
--
-- PRE-FLIGHT: the first query reports every duplicate non-NULL transaction ID.
-- Do not delete rows automatically. Reconcile duplicate records and rerun this
-- migration if the status below reports BLOCKED. MySQL UNIQUE indexes permit
-- multiple NULL values, which is required for existing reservation payments
-- that do not have a gateway transaction ID yet.
SELECT transaction_id, COUNT(*) AS duplicate_count
FROM payments
WHERE transaction_id IS NOT NULL
GROUP BY transaction_id
HAVING COUNT(*) > 1;

-- ADD UNIQUE KEY is conditionally prepared because supported MySQL versions do
-- not all accept "ADD UNIQUE KEY IF NOT EXISTS". DDL is intentionally not
-- wrapped in a transaction: MySQL implicitly commits ALTER TABLE statements.
SET @duplicate_transaction_groups = (
    SELECT COUNT(*)
    FROM (
        SELECT transaction_id
        FROM payments
        WHERE transaction_id IS NOT NULL
        GROUP BY transaction_id
        HAVING COUNT(*) > 1
    ) AS duplicate_transactions
);

SET @payment_transaction_index_exists = (
    SELECT COUNT(*)
    FROM (
        SELECT index_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'payments'
        GROUP BY index_name
        HAVING MAX(non_unique) = 0
           AND COUNT(*) = 1
           AND MAX(column_name = 'transaction_id') = 1
    ) AS unique_transaction_indexes
);

SET @payment_transaction_migration_sql = IF(
    @duplicate_transaction_groups > 0,
    'SELECT ''BLOCKED: reconcile duplicate non-NULL payments.transaction_id values before adding uq_payments_transaction_id'' AS migration_status',
    IF(
        @payment_transaction_index_exists = 0,
        'ALTER TABLE payments ADD UNIQUE KEY uq_payments_transaction_id (transaction_id)',
        'SELECT ''OK: a single-column UNIQUE index on payments.transaction_id already exists'' AS migration_status'
    )
);

PREPARE payment_transaction_migration_stmt FROM @payment_transaction_migration_sql;
EXECUTE payment_transaction_migration_stmt;
DEALLOCATE PREPARE payment_transaction_migration_stmt;
