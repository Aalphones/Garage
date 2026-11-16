CREATE TABLE vehicles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    status ENUM('aktiv', 'verkauft', 'stillgelegt', 'geloescht') NOT NULL DEFAULT 'aktiv',
    status_reason VARCHAR(300) NULL,
    created_by VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_vehicles_garage_status (garage_id, status),
    CONSTRAINT fk_vehicles_garage FOREIGN KEY (garage_id) REFERENCES garages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehicle_facts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    field VARCHAR(40) NOT NULL,
    value VARCHAR(500) NOT NULL,
    source ENUM('beleg', 'nutzer', 'recherche', 'agent', 'werkstatt') NOT NULL,
    source_url VARCHAR(500) NULL,
    source_document_id INT UNSIGNED NULL,
    confidence ENUM('bestaetigt', 'wahrscheinlich', 'unsicher') NOT NULL,
    status ENUM('aktuell', 'abgeloest', 'strittig') NOT NULL,
    actor VARCHAR(160) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_vehicle_facts_vehicle_field_status (vehicle_id, field, status),
    INDEX idx_vehicle_facts_field_value (field, value(40)),
    CONSTRAINT fk_vehicle_facts_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE open_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    kind ENUM('konflikt', 'nachfragen', 'beobachten') NOT NULL,
    text TEXT NOT NULL,
    ref_table VARCHAR(40) NULL,
    ref_id INT UNSIGNED NULL,
    status ENUM('offen', 'geloest', 'verworfen') NOT NULL DEFAULT 'offen',
    resolution TEXT NULL,
    resolved_by VARCHAR(160) NULL,
    resolved_at DATETIME NULL,
    created_by VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_open_items_garage_status (garage_id, status),
    INDEX idx_open_items_ref (ref_table, ref_id),
    CONSTRAINT fk_open_items_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
