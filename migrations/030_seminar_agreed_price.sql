-- Optional manually entered contract price for seminar plans.
ALTER TABLE seminars
    ADD COLUMN IF NOT EXISTS agreed_price DECIMAL(12,2) NULL DEFAULT NULL AFTER name;
