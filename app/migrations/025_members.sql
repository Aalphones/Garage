-- Meilenstein 6, Phase 1: Einladungen aus der Oberfläche (Ersteller, Widerruf) und Passwort-Rücksetzung (ADR-026).
ALTER TABLE invitations
    ADD COLUMN created_by INT UNSIGNED NULL,
    ADD COLUMN revoked_at DATETIME NULL,
    ADD CONSTRAINT fk_invitations_created_by FOREIGN KEY (created_by) REFERENCES users (id);

CREATE TABLE password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_password_resets_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
