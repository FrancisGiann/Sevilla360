-- Store only a SHA-256 digest of password-reset tokens.  The legacy raw token
-- is cleared so links issued before this migration cannot be reused.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS reset_token_hash CHAR(64) NULL AFTER reset_token;

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_reset_token_hash (reset_token_hash);

UPDATE users
SET reset_token = NULL,
    reset_expires_at = NULL
WHERE reset_token IS NOT NULL;
