# Datenmodell — GARAGE

Quelle der Wahrheit sind die Migrationen in `app/migrations/`. Geplantes Modell (44 Tabellen in sechs Bereichen): [`design/konzept-garage.md`](design/konzept-garage.md) → „Datenmodell".

`007_testkonten_sperren.sql` legt keine Tabelle an: sie setzt Nutzer mit Adresse `test-…@example.com` auf `gesperrt` und widerruft deren Tokens (Aufräumen der Handprüfungen aus Meilenstein 1).

`008_record.sql` legt `registrations` und `equipment` an und erweitert drei Tabellen: `vehicle_facts.field` auf VARCHAR(120) (Spezifikationen `spec.<bereich>.<schluessel>`), `open_items.proposal_json` (Vorschlag am Klärfall), `audit_log.vehicle_id` mit Index `(vehicle_id, created_at)` (Verlauf je Fahrzeug).

## Bestehende Tabellen

| Tabelle | Spalten | Angelegt von |
|---|---|---|
| `schema_migrations` | `filename` VARCHAR(190) PK, `applied_at` DATETIME (UTC) | `Db\Migrator` selbst |
| `users` | `id` PK, `email` UNIQUE (klein geschrieben), `name`, `password_hash`, `status` ENUM(`aktiv`,`gesperrt`), `created_at`, `last_login_at` | `001_user.sql` |
| `garages` | `id` PK, `name`, `created_at` | `002_garage.sql` |
| `garage_members` | PK (`garage_id` FK, `user_id` FK), `role` ENUM(`inhaber`,`mitglied`,`lesend`), `created_at`; Index auf `user_id` | `002_garage.sql` |
| `invitations` | `id` PK, `token_hash` CHAR(64) UNIQUE, `garage_id` FK NULL **oder** `garage_name` NULL (genau eins), `role`, `email` NULL (nur Notiz), `expires_at`, `accepted_at`, `accepted_by` FK, `created_at` | `002_garage.sql` |
| `tokens` | `id` PK, `user_id` FK, `token_hash` CHAR(64) UNIQUE, `kind` ENUM(`mcp`,`oauth_access`,`oauth_refresh`,`user_refresh`), `name`, `client_id` NULL, `scope`, `family` CHAR(32) NULL, `expires_at` NULL, `revoked`, `last_used_at`, `created_at`; Index `(user_id, kind, revoked)` | `003_token.sql` |
| `login_attempts` | `id` PK, `ip_hash` CHAR(64) (HMAC, nie die IP), `attempted_at`; Index `(ip_hash, attempted_at)` | `003_token.sql` |
| `audit_log` | `id` PK, `garage_id` NULL, `user_id` NULL, `actor`, `action`, `target`, `before_json`, `after_json`, `created_at`; Index `(garage_id, created_at)`; seit 008 zusätzlich `vehicle_id` NULL, Index `(vehicle_id, created_at)` | `004_audit.sql`, `008_record.sql` |

| `oauth_clients` | `id` PK, `client_id` VARCHAR(500) UNIQUE (die CIMD-Adresse), `kind` ENUM(`cimd`), `name`, `redirect_uris` JSON, `metadata` JSON, `fetched_at`, `revoked`, `last_used_at`, `created_at` — nur Apps mit Metadatendokument (E6), kein Geheimnis | `005_oauth.sql` |
| `oauth_codes` | `id` PK, `request_id` CHAR(32) UNIQUE, `code_hash` UNIQUE NULL, `client_id`, `user_id` FK NULL (gesetzt bei `pending → approved`: wer zugestimmt hat), `redirect_uri`, `challenge`, `scope`, `resource`, `state`, `status` ENUM(`pending`,`approved`,`denied`,`used`), `family` CHAR(32) NULL, `expires_at`, `created_at` — eine Zeile je Anfrage; abgelaufene werden nach einem Tag beim nächsten `authorize` gelöscht (Wegwerf-Zeilen, keine Fachdaten) | `005_oauth.sql` |

| `vehicles` | `id` PK, `garage_id` FK, `label` VARCHAR(80), `status` ENUM(`aktiv`,`verkauft`,`stillgelegt`,`geloescht`), `status_reason` NULL, `created_by`, `created_at`, `updated_at`; Index `(garage_id, status)` — Stammdaten stehen nicht hier, sondern in `vehicle_facts` | `006_vehicle.sql` |
| `vehicle_facts` | `id` PK, `garage_id`, `vehicle_id` FK, `field` VARCHAR(120), `value` VARCHAR(500) (immer Text), `source` ENUM(`beleg`,`nutzer`,`recherche`,`agent`,`werkstatt`), `source_url` NULL, `source_document_id` NULL (ohne FK bis Meilenstein 3), `confidence` ENUM(`bestaetigt`,`wahrscheinlich`,`unsicher`), `status` ENUM(`aktuell`,`abgeloest`,`strittig`), `actor`, `user_id` NULL, `created_at`; Index `(vehicle_id, field, status)`, `(field, value(40))` — je Feld höchstens eine Zeile `aktuell`; erlaubte Felder: `VehicleService::FIELDS` (ADR-007) und Spezifikationen `spec.<bereich>.<schluessel>` (ADR-009, nur über `set_spec`) | `006_vehicle.sql`, `008_record.sql` |
| `open_items` | `id` PK, `garage_id`, `vehicle_id` FK NULL, `kind` ENUM(`konflikt`,`nachfragen`,`beobachten`), `text`, `ref_table`/`ref_id` NULL (Konflikt: `vehicle_facts` + ID der `strittig`-Zeile; Abgleich-Klärfall: `registrations`/`equipment` + ID des Datensatzes), `proposal_json` JSON NULL (Vorschlag `{field,value,source,source_url,confidence}` beim Abgleich-Klärfall, ADR-009), `status` ENUM(`offen`,`geloest`,`verworfen`), `resolution`, `resolved_by`, `resolved_at`, `created_by`, `created_at`; Index `(garage_id, status)`, `(ref_table, ref_id)` — Lösen nur durch Menschen (ab Meilenstein 2) | `006_vehicle.sql` |

Listen-Tabellen (Datensatz-Abgleich, ADR-009) tragen alle denselben Herkunftsblock: `garage_id`, `vehicle_id` FK, `source`, `source_url`, `source_document_id` (ohne FK bis Meilenstein 3), `confidence`, `status` ENUM(`aktiv`,`geloescht`), `status_reason`, `actor`, `user_id`, `created_at`, `updated_at`; Index `(vehicle_id, status)`.

| Tabelle | Fachspalten | Natürlicher Schlüssel | Angelegt von |
|---|---|---|---|
| `registrations` | `plate` VARCHAR(15), `valid_from` DATE, `valid_to` DATE NULL, `holder` VARCHAR(120) NULL, `holder_address` VARCHAR(200) NULL | `plate` + `valid_from` | `008_record.sql` |
| `equipment` | `name` VARCHAR(160), `code` VARCHAR(20) NULL, `category` ENUM(`serie`,`sonder`), `list_price_cents` INT UNSIGNED NULL | `name` | `008_record.sql` |

Der Schlüssel steht in `RecordMerger::TABLES`; ein weiterer Satz mit demselben Schlüssel wird abgeglichen statt angelegt.

`tokens` mit `kind` `oauth_access`/`oauth_refresh` trägt `user_id` des zustimmenden Nutzers, `client_id` und `family` (Verbindung); eine Familie ist eine „verbundene App“.

Tokens, Einladungen und Passwörter liegen nur als Hash in der Datenbank; Klartext gibt es einmal bei der Ausgabe.

## Regeln für jede neue Tabelle

- Jede Tabelle mit Fakten trägt `garage_id`, `source`, `source_document_id` oder `source_url`, `confidence` und den Akteur.
- Zeitstempel `DATETIME` in UTC; gelöscht wird per Status mit Grund, nie hart.
- `utf8mb4`, InnoDB, Fremdschlüssel ausgeschrieben.
