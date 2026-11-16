CREATE TABLE garages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE garage_members (
    garage_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('inhaber', 'mitglied', 'lesend') NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (garage_id, user_id),
    INDEX idx_garage_members_user (user_id),
    CONSTRAINT fk_garage_members_garage FOREIGN KEY (garage_id) REFERENCES garages (id),
    CONSTRAINT fk_garage_members_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    garage_id INT UNSIGNED NULL,
    garage_name VARCHAR(120) NULL,
    role ENUM('inhaber', 'mitglied', 'lesend') NOT NULL,
    email VARCHAR(190) NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    accepted_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_invitations_garage FOREIGN KEY (garage_id) REFERENCES garages (id),
    CONSTRAINT fk_invitations_accepted_by FOREIGN KEY (accepted_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
