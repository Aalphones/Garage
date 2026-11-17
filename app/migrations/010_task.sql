-- Meilenstein 2, Phase 3: Wartungsplan. Aufgaben je Fahrzeug und ihre Intervallregeln. Faelligkeiten werden nie gespeichert.
CREATE TABLE tasks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    task_key VARCHAR(41) NOT NULL,
    title VARCHAR(120) NOT NULL,
    status ENUM('aktiv', 'inaktiv') NOT NULL DEFAULT 'aktiv',
    actor VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_tasks_vehicle_key (vehicle_id, task_key),
    CONSTRAINT fk_tasks_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE task_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    interval_km INT UNSIGNED NULL,
    interval_months SMALLINT UNSIGNED NULL,
    first_km INT UNSIGNED NULL,
    first_months SMALLINT UNSIGNED NULL,
    mode ENUM('fest', 'flexibel') NOT NULL DEFAULT 'fest',
    valid_from DATE NOT NULL,
    rule_status ENUM('aktuell', 'abgeloest') NOT NULL,
    source ENUM('beleg', 'nutzer', 'recherche', 'agent', 'werkstatt') NOT NULL,
    source_url VARCHAR(500) NULL,
    source_document_id INT UNSIGNED NULL,
    confidence ENUM('bestaetigt', 'wahrscheinlich', 'unsicher') NOT NULL,
    actor VARCHAR(160) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_task_rules_task (task_id, rule_status),
    CONSTRAINT fk_task_rules_task FOREIGN KEY (task_id) REFERENCES tasks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
