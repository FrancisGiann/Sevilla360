-- Add optional work-profile fields and an audit-friendly archive timestamp.
-- The IF NOT EXISTS clauses keep deployment/retry runs idempotent on MariaDB.
ALTER TABLE staff
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL AFTER phone,
    ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL AFTER address,
    ADD COLUMN IF NOT EXISTS job_title VARCHAR(100) NULL AFTER department,
    ADD COLUMN IF NOT EXISTS hire_date DATE NULL AFTER job_title,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER status;
