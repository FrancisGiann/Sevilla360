-- Staff-entered seminar receipts. Voids retain the original row and audit trail.
CREATE TABLE IF NOT EXISTS seminar_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seminar_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(120) NOT NULL,
    transaction_reference VARCHAR(64) NULL,
    reference_fingerprint CHAR(64) NULL,
    idempotency_key CHAR(64) NOT NULL,
    status ENUM('posted','voided') NOT NULL DEFAULT 'posted',
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_by INT NULL,
    voided_at DATETIME NULL,
    void_reason VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seminar_payment_reference (reference_fingerprint),
    UNIQUE KEY uq_seminar_payment_idempotency (idempotency_key),
    KEY idx_seminar_payment_history (seminar_id, status, created_at, id),
    CONSTRAINT fk_seminar_payment_seminar FOREIGN KEY (seminar_id) REFERENCES seminars(id) ON DELETE RESTRICT,
    CONSTRAINT fk_seminar_payment_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_seminar_payment_voider FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT chk_seminar_payment_amount CHECK (amount > 0),
    CONSTRAINT chk_seminar_payment_reference CHECK (payment_method = 'Cash' OR transaction_reference IS NOT NULL),
    CONSTRAINT chk_seminar_payment_void CHECK ((status = 'posted' AND voided_at IS NULL AND void_reason IS NULL)
        OR (status = 'voided' AND voided_at IS NOT NULL AND void_reason IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
