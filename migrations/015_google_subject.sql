-- Store Google's stable OIDC subject without changing local roles/passwords.
-- Run after verifying the existing users.email uniqueness. NULL values allow
-- existing password accounts to link once after verified Google email login.
ALTER TABLE users ADD COLUMN IF NOT EXISTS google_subject VARCHAR(255) NULL AFTER email;
ALTER TABLE users ADD UNIQUE INDEX IF NOT EXISTS uq_users_google_subject (google_subject);
