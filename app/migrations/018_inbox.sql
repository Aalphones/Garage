-- Meilenstein 3, Phase 3: Eingang (Dokumente, die ein Agent abarbeitet) mit Reservierung auf Frist.
CREATE TABLE inbox_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    garage_id INT UNSIGNED NOT NULL,
    vehicle_id INT UNSIGNED NULL,
    note VARCHAR(200) NOT NULL DEFAULT '',
    status ENUM('neu', 'in_arbeit', 'erfasst', 'klaerfall', 'verworfen') NOT NULL DEFAULT 'neu',
    claimed_by VARCHAR(160) NULL,
    claimed_user_id INT UNSIGNED NULL,
    claimed_until DATETIME NULL,
    result VARCHAR(500) NULL,
    open_item_id INT UNSIGNED NULL,
    actor VARCHAR(160) NOT NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_inbox_items_status (garage_id, status, created_at),
    CONSTRAINT fk_inbox_items_garage FOREIGN KEY (garage_id) REFERENCES garages (id),
    CONSTRAINT fk_inbox_items_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inbox_documents (
    inbox_item_id INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (inbox_item_id, document_id),
    KEY idx_inbox_documents_position (inbox_item_id, position),
    CONSTRAINT fk_inbox_documents_item FOREIGN KEY (inbox_item_id) REFERENCES inbox_items (id),
    CONSTRAINT fk_inbox_documents_document FOREIGN KEY (document_id) REFERENCES documents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
