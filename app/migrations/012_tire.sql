-- Meilenstein 2, Phase 4: Reifensaetze (Listen-Tabelle mit Herkunftsblock) und ihre Montagen.
CREATE TABLE tire_sets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    label VARCHAR(80) NOT NULL,
    brand VARCHAR(60) NULL,
    model VARCHAR(80) NULL,
    size VARCHAR(30) NOT NULL,
    season ENUM('sommer', 'winter', 'ganzjahr') NOT NULL,
    dot CHAR(4) NULL,
    storage_location VARCHAR(120) NULL,
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
    INDEX idx_tire_sets_vehicle_status (vehicle_id, status),
    CONSTRAINT fk_tire_sets_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tire_mounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NOT NULL,
    tire_set_id INT UNSIGNED NOT NULL,
    mounted_on DATE NOT NULL,
    mounted_km INT UNSIGNED NULL,
    removed_on DATE NULL,
    removed_km INT UNSIGNED NULL,
    actor VARCHAR(160) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_tire_mounts_vehicle (vehicle_id, removed_on),
    INDEX idx_tire_mounts_set (tire_set_id, mounted_on),
    CONSTRAINT fk_tire_mounts_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id),
    CONSTRAINT fk_tire_mounts_set FOREIGN KEY (tire_set_id) REFERENCES tire_sets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
