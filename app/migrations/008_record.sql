-- Meilenstein 2, Phase 1: Datensatz-Abgleich. Zulassung und Ausstattung als Listen-Tabellen mit Herkunftsblock,
-- breitere Feldnamen fuer Spezifikationen (spec.<bereich>.<schluessel>), Vorschlag am Klaerfall, Fahrzeug im Verlauf.
ALTER TABLE vehicle_facts MODIFY field VARCHAR(120) NOT NULL;

ALTER TABLE open_items ADD COLUMN proposal_json JSON NULL AFTER ref_id;

ALTER TABLE audit_log ADD COLUMN vehicle_id INT UNSIGNED NULL AFTER garage_id, ADD INDEX idx_audit_vehicle (vehicle_id, created_at);

CREATE TABLE registrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    plate VARCHAR(15) NOT NULL,
    valid_from DATE NOT NULL,
    valid_to DATE NULL,
    holder VARCHAR(120) NULL,
    holder_address VARCHAR(200) NULL,
    source ENUM('beleg', 'nutzer', 'recherche', 'agent', 'werkstatt') NOT NULL,
    source_url VARCHAR(500) NULL,
    source_document_id INT UNSIGNED NULL,
    confidence ENUM('bestaetigt', 'wahrscheinlich', 'unsicher') NOT NULL,
    status ENUM('aktiv', 'geloescht') NOT NULL DEFAULT 'aktiv',
    status_reason VARCHAR(300) NULL,
    actor VARCHAR(160) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_registrations_vehicle_status (vehicle_id, status),
    CONSTRAINT fk_registrations_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE equipment (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(20) NULL,
    category ENUM('serie', 'sonder') NOT NULL,
    list_price_cents INT UNSIGNED NULL,
    source ENUM('beleg', 'nutzer', 'recherche', 'agent', 'werkstatt') NOT NULL,
    source_url VARCHAR(500) NULL,
    source_document_id INT UNSIGNED NULL,
    confidence ENUM('bestaetigt', 'wahrscheinlich', 'unsicher') NOT NULL,
    status ENUM('aktiv', 'geloescht') NOT NULL DEFAULT 'aktiv',
    status_reason VARCHAR(300) NULL,
    actor VARCHAR(160) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_equipment_vehicle_status (vehicle_id, status),
    CONSTRAINT fk_equipment_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
