-- Apply after 034. Keep PASSWORD_SMS_RECOVERY_ENABLED=0 until rollout approval.
ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0;
CREATE TABLE recovery_sms_challenges (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id INT NOT NULL,
 phone_e164 VARCHAR(16) NOT NULL,
 code_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 consumed_at DATETIME NULL,
 failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 provider_reference VARCHAR(100) NULL,
 provider_status VARCHAR(16) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_recovery_user (user_id,consumed_at,expires_at),
 CONSTRAINT fk_recovery_sms_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
