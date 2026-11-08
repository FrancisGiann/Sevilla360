-- Keep newly created staff accounts blocked until the invitee chooses a password.
CREATE TABLE IF NOT EXISTS staff_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_invitations_user (user_id),
    UNIQUE KEY uq_staff_invitations_token_hash (token_hash),
    KEY idx_staff_invitations_pending (purpose, expires_at, consumed_at),
    CONSTRAINT fk_staff_invitations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
