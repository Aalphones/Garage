-- Meilenstein 5, Phase 1: Version des Kalender-Abo-Links (ADR-024) und Datum des Statuswechsels (ADR-022).
ALTER TABLE garages ADD COLUMN calendar_version INT UNSIGNED NOT NULL DEFAULT 1;
ALTER TABLE vehicles ADD COLUMN status_since DATE NULL;
