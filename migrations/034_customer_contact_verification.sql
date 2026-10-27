-- Apply before enabling PHONE_SIGNUP_ENABLED. Existing users retain legacy verification.
ALTER TABLE users ADD COLUMN phone_signup_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN recovery_email VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN recovery_email_verified_at DATETIME NULL;
ALTER TABLE users ADD COLUMN recovery_email_pending VARCHAR(255) NULL;
CREATE TABLE customer_verified_phones (
 user_id INT NOT NULL PRIMARY KEY,
 phone_e164 VARCHAR(16) NOT NULL,
 verified_at DATETIME NULL,
 verified_phone_e164 VARCHAR(16) GENERATED ALWAYS AS (CASE WHEN verified_at IS NOT NULL THEN phone_e164 ELSE NULL END) STORED,
 UNIQUE KEY uq_verified_phone (verified_phone_e164),
 CONSTRAINT fk_verified_phone_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE contact_challenges (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 purpose ENUM('signup_phone','change_phone','recovery_email') NOT NULL,
 target VARCHAR(255) NOT NULL,
 code_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 provider_reference VARCHAR(100) NULL,
 provider_status VARCHAR(16) NULL,
 failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_challenge_user (user_id,purpose,consumed_at,expires_at),
 CONSTRAINT fk_contact_challenge_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE contact_throttles (
 scope_key CHAR(64) PRIMARY KEY,
 window_start DATETIME NOT NULL,
 attempts SMALLINT UNSIGNED NOT NULL,
 last_attempt DATETIME NOT NULL
) ENGINE=InnoDB;
