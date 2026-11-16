CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    actor VARCHAR(160) NOT NULL,
    action VARCHAR(60) NOT NULL,
    target VARCHAR(190) NOT NULL,
    before_json JSON NULL,
    after_json JSON NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_log_garage_time (garage_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
