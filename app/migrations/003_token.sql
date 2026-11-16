CREATE TABLE tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    kind ENUM('mcp', 'oauth_access', 'oauth_refresh', 'user_refresh') NOT NULL,
    name VARCHAR(120) NOT NULL,
    client_id VARCHAR(500) NULL,
    scope VARCHAR(200) NOT NULL,
    family CHAR(32) NULL,
    expires_at DATETIME NULL,
    revoked TINYINT(1) NOT NULL DEFAULT 0,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_tokens_kind_revoked (kind, revoked),
    INDEX idx_tokens_user_kind_revoked (user_id, kind, revoked),
    INDEX idx_tokens_family (family),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_attempts_ip_time (ip_hash, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
