# Datenmodell — GARAGE

Quelle der Wahrheit sind die Migrationen in `app/migrations/`. Geplantes Modell (43 Tabellen in sechs Bereichen): [`design/konzept-garage.md`](design/konzept-garage.md) → „Datenmodell".

`007_testkonten_sperren.sql` legt keine Tabelle an: sie setzt Nutzer mit Adresse `test-…@example.com` auf `gesperrt` und widerruft deren Tokens (Aufräumen der Handprüfungen aus Meilenstein 1).

`008_record.sql` legt `registrations` und `equipment` an und erweitert drei Tabellen: `vehicle_facts.field` auf VARCHAR(120) (Spezifikationen `spec.<bereich>.<schluessel>`), `open_items.proposal_json` (Vorschlag am Klärfall), `audit_log.vehicle_id` mit Index `(vehicle_id, created_at)` (Verlauf je Fahrzeug).

`009_event.sql` legt `workshops`, `events`, `event_items` und `odometer_readings` an (Phase 2: Scheckheft und Kilometer).

`010_task.sql` legt `tasks` und `task_rules` an (Phase 3: Wartungsplan). Fälligkeiten werden nie gespeichert, sondern beim Lesen berechnet (ADR-011 Teil 2).

`011_measurement.sql` bis `015_note.sql` legen Messwerte, Reifensätze mit Montagen, Teile, Schäden und Rückrufe sowie Notizen mit Revisionen an (Phase 4); die Spalten stehen in der Tabelle unten.

`016_testkonten_m2_sperren.sql` legt keine Tabelle an: sie setzt Nutzer mit Adresse `test-m2-…@example.com` oder `p2r…@example.com` auf `gesperrt` und widerruft deren Tokens (Aufräumen der Prüfungen aus Meilenstein 2).

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
| `events` | `kind` ENUM(`inspektion`,`reparatur`,`hu`,`reifen`,`schaden`,`rueckruf`,`kauf`,`sonstiges`), `title` VARCHAR(200), `performed_on` DATE, `invoiced_on` DATE NULL, `odometer_km` INT UNSIGNED NULL, `workshop_id` FK `workshops` NULL, `invoice_number` VARCHAR(60) NULL, `order_number` VARCHAR(60) NULL, `net_cents`/`vat_cents`/`gross_cents` INT UNSIGNED NULL, `digital_service_record` TINYINT(1) NULL; Index `(vehicle_id, status, performed_on)`, `(workshop_id, invoice_number)` | mit Rechnungsnummer: `workshop_id` + `invoice_number`; ohne: `kind` + `performed_on` + `workshop_id` (`EventService` übergibt den Schlüssel) | `009_event.sql` |

Der Schlüssel steht in `RecordMerger::TABLES`; ein weiterer Satz mit demselben Schlüssel wird abgeglichen statt angelegt. Ein Schlüsselwert darf `NULL` sein (Ereignis ohne Werkstatt); die Suche vergleicht mit `<=>`.

Weitere Tabellen aus `009_event.sql`:

| Tabelle | Spalten | Hinweis |
|---|---|---|
| `workshops` | `id` PK, `garage_id`, `name` VARCHAR(120), `city` VARCHAR(80) (leer statt NULL), `manufacturer_partner` TINYINT(1) NULL, `actor`, `created_at`; UNIQUE `(garage_id, name, city)` | je Garage, Groß-/Kleinschreibung egal; `manufacturer_partner` wird nur gesetzt, solange es unbekannt ist (`WorkshopRepository::findOrCreate`) |
| `event_items` | `id` PK, `garage_id`, `event_id` FK, `line_no` SMALLINT, `item_type` ENUM(`arbeit`,`teil`,`material`,`pauschale`), `description` VARCHAR(200), `task_key` VARCHAR(41) NULL, `part_number` VARCHAR(60) NULL, `manufacturer` VARCHAR(80) NULL, `quantity` DECIMAL(12,3) NULL, `unit` VARCHAR(10) NULL, `net_cents` INT NULL (negativ für Rabatte), `status` ENUM(`aktiv`,`abgeloest`), `created_at`; Index `(event_id, status)`, `(task_key)` | Positionen sind ein Ganzes: abweichende Positionen ändern nichts, sondern öffnen einen Klärfall (`proposal_json.items`) |
| `odometer_readings` | `id` PK, `garage_id`, `vehicle_id` FK, `read_on` DATE, `km` INT UNSIGNED, `event_id` NULL, `note` VARCHAR(200) NULL, `reading_status` ENUM(`gueltig`,`strittig`,`verworfen`), Herkunftsblock; Index `(vehicle_id, reading_status, read_on)`, `(event_id)` | Vorwärts-Regel: ADR-011; kein `RecordMerger` |
| `tasks` | `id` PK, `garage_id`, `vehicle_id` FK, `task_key` VARCHAR(41), `title` VARCHAR(120), `status` ENUM(`aktiv`,`inaktiv`), `actor`, `created_at`, `updated_at`; UNIQUE `(vehicle_id, task_key)` | eine Aufgabe je Fahrzeug und Schlüssel; derselbe `task_key` steht an Positionen in `event_items` (Verbindung über den Schlüssel, kein FK) |
| `task_rules` | `id` PK, `garage_id`, `task_id` FK, `interval_km` INT UNSIGNED NULL, `interval_months` SMALLINT UNSIGNED NULL, `first_km`, `first_months` (Erstintervall, NULL = wie Intervall), `mode` ENUM(`fest`,`flexibel`), `valid_from` DATE, `rule_status` ENUM(`aktuell`,`abgeloest`), Herkunftsblock (`source`, `source_url`, `source_document_id`, `confidence`), `actor`, `user_id`, `created_at`; Index `(task_id, rule_status)` | je Aufgabe höchstens eine Zeile `aktuell`; Abgleich in `TaskService` (ADR-009-Muster, abweichende Regel gegen bestätigte → Klärfall mit `proposal_json`); abgelöste Zeilen bleiben als Verlauf |

Weitere Listen-Tabellen aus Phase 4 (`011`–`014`; alle mit `id` PK, `garage_id`, `vehicle_id` FK, Herkunftsblock wie oben, `status`, `actor`, `user_id`, `created_at`, `updated_at`, Index `(vehicle_id, status)`; Abgleich über `RecordMerger::TABLES`):

| Tabelle | Fachspalten | Schlüssel / Hinweis |
|---|---|---|
| `measurements` | `measured_on` DATE, `quantity` ENUM(`profiltiefe`,`reifendruck`,`bremsbelag`,`bremsscheibe`,`batterie_spannung`,`batterie_kaltstart`,`frostschutz`,`hu_bremswert`,`sonstiges`), `position` ENUM(`vl`,`vr`,`hl`,`hr`,`vorne`,`hinten`,`gesamt`) DEFAULT `gesamt`, `label` VARCHAR(80) (leer außer bei `sonstiges`), `value` DECIMAL(10,2) (auch negativ), `unit` VARCHAR(10), `odometer_km` INT NULL, `note`, `event_id` NULL | Schlüssel `measured_on`+`quantity`+`position`+`label`; Einheit je Größe fest (`MeasurementService::UNITS`); `odometer_km` legt zusätzlich einen Stand über `OdometerService` an |
| `tire_sets` | `label` VARCHAR(80), `brand`, `model`, `size` VARCHAR(30), `season` ENUM(`sommer`,`winter`,`ganzjahr`), `dot` CHAR(4) NULL (`WWYY`), `storage_location` | Schlüssel `label` |
| `tire_mounts` | `tire_set_id` FK, `mounted_on` DATE, `mounted_km`, `removed_on` DATE NULL, `removed_km`, `actor`, `created_at`, `updated_at`; Index `(vehicle_id, removed_on)` | kein Herkunftsblock, kein Abgleich: `TireService` — je Fahrzeug höchstens eine Montage ohne `removed_on`; Montieren schließt die offene eines anderen Satzes |
| `parts` | `component` VARCHAR(80), `brand` VARCHAR(60) (leer statt NULL), `article_number` VARCHAR(60) (leer statt NULL), `oem_number` NULL, `role` ENUM(`verbaut`,`alternative`), `verified` TINYINT(1) NULL, `note` | Schlüssel `component`+`brand`+`article_number` |
| `damages` | `occurred_on` DATE, `kind` ENUM(`unfall`,`hagel`,`lack`,`glas`,`vandalismus`,`sonstiges`), `description` VARCHAR(500), `odometer_km` NULL, `repaired` NULL, `repair_event_id` NULL, `appraisal` NULL, `diminished_value_cents` NULL | Schlüssel `occurred_on`+`kind`; `repair_event_id` muss zum Fahrzeug gehören (`EventLink`) |
| `recalls` | `manufacturer_code` VARCHAR(40) (leer statt NULL), `kba_reference` VARCHAR(40) (leer statt NULL), `title` VARCHAR(200), `recall_status` ENUM(`offen`,`erledigt`,`nicht_betroffen`), `done_on` NULL, `event_id` NULL | Schlüssel `manufacturer_code`+`kba_reference`, mindestens eins gefüllt |
| `notes` | `id` PK, `garage_id`, `vehicle_id` FK, `topic` VARCHAR(120), `status` ENUM(`aktiv`,`geloescht`), `created_at`, `updated_at`; UNIQUE `(vehicle_id, topic)` | Thema je Fahrzeug, Groß-/Kleinschreibung egal; kein Abgleich, keine Herkunft |
| `note_revisions` | `id` PK, `garage_id`, `note_id` FK, `revision` INT, `body` MEDIUMTEXT, `actor`, `user_id` NULL, `created_at`; UNIQUE `(note_id, revision)` | jede Änderung eine neue Revision; die alten bleiben (`NoteService`, `NoteRepository`) |

Belege (`017_document.sql`, ADR-012; alle mit `garage_id` FK, Zugriff nur über `DocumentRepository` mit Akteur):

| Tabelle | Spalten | Hinweis |
|---|---|---|
| `documents` | `id` PK, `garage_id`, `vehicle_id` FK NULL, `uid` CHAR(12) UNIQUE (Ordnername), `doc_type` ENUM(`foto`,`rechnung`,`hu_bericht`,`police`,`bescheid`,`fahrzeugschein`,`kaufvertrag`,`sonstiges`), `photo_tag` NULL, `title`, `mime` NULL, `bytes`, `sha256` CHAR(64) NULL, `width`/`height` NULL, `page_count`, `captured_on` DATE NULL, `text_content` MEDIUMTEXT NULL, `status` ENUM(`wartet`,`unzugeordnet`,`zugeordnet`,`verworfen`), `status_reason`, `channel`, `actor`, `user_id` NULL, `created_at`, `updated_at`; UNIQUE `(garage_id, sha256)`, Index `(garage_id, status)` | `mime`/`sha256` leer, solange `wartet`; Bilder immer `image/jpeg`; Dublette = gleicher SHA je Garage |
| `attachments` | `id` PK, `garage_id`, `document_id` FK, `target_table`, `target_id`, `role`, `actor`, `user_id` NULL, `created_at`; UNIQUE `(document_id, target_table, target_id, role)` | Logik ab Phase 2 |
| `upload_tickets` | `id` PK, `ticket_hash` CHAR(64) UNIQUE (SHA-256 des Slugs), `user_id` FK, `garage_id`, `vehicle_id` NULL, `document_id` NULL, `max_bytes`, `expires_at`, `redeemed_at` NULL, `is_duplicate` TINYINT(1), `created_at` | Ablauf in SQL gegen `UTC_TIMESTAMP()`; `is_duplicate` = hochgeladene Datei existierte schon |

`tokens` mit `kind` `oauth_access`/`oauth_refresh` trägt `user_id` des zustimmenden Nutzers, `client_id` und `family` (Verbindung); eine Familie ist eine „verbundene App“.

Tokens, Einladungen und Passwörter liegen nur als Hash in der Datenbank; Klartext gibt es einmal bei der Ausgabe.

## Regeln für jede neue Tabelle

- Jede Tabelle mit Fakten trägt `garage_id`, `source`, `source_document_id` oder `source_url`, `confidence` und den Akteur.
- Zeitstempel `DATETIME` in UTC; gelöscht wird per Status mit Grund, nie hart.
- `utf8mb4`, InnoDB, Fremdschlüssel ausgeschrieben.
