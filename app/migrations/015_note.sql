-- Meilenstein 2, Phase 4: Notizen je Thema; jede Aenderung ist eine neue Revision, die alten bleiben.
CREATE TABLE notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    topic VARCHAR(120) NOT NULL,
    status ENUM('aktiv', 'geloescht') NOT NULL DEFAULT 'aktiv',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_notes_topic (vehicle_id, topic),
    CONSTRAINT fk_notes_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE note_revisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    note_id INT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    body MEDIUMTEXT NOT NULL,
    actor VARCHAR(160) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_note_revisions (note_id, revision),
    CONSTRAINT fk_note_revisions_note FOREIGN KEY (note_id) REFERENCES notes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
