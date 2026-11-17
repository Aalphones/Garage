# Phase 1 — Fundament und Stammdaten

Rating: **heikel** (gemeinsamer Abgleich für alle späteren Tabellen, dynamisches SQL nur aus Konstanten). Status: **pending**.

## Kontext — vorher lesen

- [`README.md`](README.md) → E1, E2, E3, E10, Kontrakt (Bausteine, Ergebnis-Vokabular, Klärfall-Vorschlag, Tools `set_spec`, `record_registration`, `upsert_equipment`).
- `docs/decisions/007-fahrzeugfakten-als-zeilen-mit-herkunft.md`, `008-agentenrechte-scope-und-rolle.md`.
- `docs/conventions/php.md`, `docs/conventions/dod.md`, `docs/models.md` → „Regeln für jede neue Tabelle“.
- Code: `app/src/Domain/VehicleService.php` (Validierung, Konfliktregel, `requireVehicle`, `assertCanWrite`), `VehicleRepository.php`, `OpenItemRepository.php`, `AuditRepository.php`, `app/src/Db/Transaction.php`, `app/src/Mcp/Tools/VehicleTools.php`, `app/src/Mcp/McpController.php` (`services()`, `registry()`), `app/migrations/006_vehicle.sql`.
- Fehlerklassen: keine einschlägigen im Vault (MCP-Schicht bleibt unangetastet).

## Abnahmekriterien

- [ ] Die Refaktorierung ändert kein Verhalten von M1: die fünf Zweige der Konfliktregel von `set_vehicle_facts` liefern dieselben Ergebnisse wie im M1-Report (Phase 4 im Archiv), Fehlertexte für ungültige Werte unverändert.
- [ ] `set_spec` mit `{"area":"motoroel","key":"viskositaet","value":"5W-30"}` (bestätigt) und danach `0W-20` (Beleg, bestätigt) → zweites Ergebnis `klaerfall` mit `open_item_id`; `get_vehicle` zeigt `specs.motoroel.viskositaet` = `5W-30` und unter `disputed` den Eintrag `spec.motoroel.viskositaet` = `0W-20`.
- [ ] `area`/`key` außerhalb `^[a-z][a-z0-9_]{1,30}$` → `invalid_value` mit Beispiel. `set_vehicle_facts` mit einem Feld `spec.…` → `invalid_value` („Spezifikationen über set_spec“).
- [ ] `record_registration` zweimal identisch → `angelegt`, dann `unveraendert`. Dieselbe Zulassung (`plate` + `valid_from`) mit zusätzlichem `holder` → `ergaenzt`. Mit anderem `holder` gegen bestätigte Zeile → `klaerfall`; `open_items.proposal_json` enthält `field`, `value`, Herkunft.
- [ ] `upsert_equipment` mit drei Positionen, davon eine mit `list_price_eur: "450.00"` — zweiter identischer Aufruf → alle `unveraendert` (Cent-Vergleich korrekt).
- [ ] `get_vehicle` liefert `specs` (nach Bereich gruppiert, je Wert mit Herkunft), `registrations` (neueste zuerst), `holder_count` (Zahl verschiedener nicht leerer `holder`), `equipment`.
- [ ] Jede Schreibung erzeugt einen `audit_log`-Eintrag mit `vehicle_id`.
- [ ] Mandanten: Nutzer B → `set_spec`/`record_registration`/`upsert_equipment` an Fahrzeug von A → `not_found`; Rolle `lesend` → `forbidden`; Token nur `garage:read` → 403 `insufficient_scope`.

## Datenmodell — `app/migrations/008_record.sql`

Je Anweisung eine Zeile ohne Transaktion (Migrator führt einzeln aus).

- `ALTER TABLE vehicle_facts MODIFY field VARCHAR(120) NOT NULL;`
- `ALTER TABLE open_items ADD COLUMN proposal_json JSON NULL AFTER ref_id;`
- `ALTER TABLE audit_log ADD COLUMN vehicle_id INT UNSIGNED NULL AFTER garage_id, ADD INDEX idx_audit_vehicle (vehicle_id, created_at);`
- `registrations`: `id` PK, `garage_id` INT UNSIGNED NOT NULL, `vehicle_id` FK→vehicles NOT NULL, `plate` VARCHAR(15) NOT NULL, `valid_from` DATE NOT NULL, `valid_to` DATE NULL, `holder` VARCHAR(120) NULL, `holder_address` VARCHAR(200) NULL, **Herkunftsblock**; INDEX (`vehicle_id`, `status`).
- `equipment`: `id` PK, `garage_id`, `vehicle_id` FK, `name` VARCHAR(160) NOT NULL, `code` VARCHAR(20) NULL, `category` ENUM('serie','sonder') NOT NULL, `list_price_cents` INT UNSIGNED NULL, **Herkunftsblock**; INDEX (`vehicle_id`, `status`).

**Herkunftsblock** (gleich in jeder Listen-Tabelle dieses Plans, in dieser Reihenfolge): `source` ENUM('beleg','nutzer','recherche','agent','werkstatt') NOT NULL, `source_url` VARCHAR(500) NULL, `source_document_id` INT UNSIGNED NULL, `confidence` ENUM('bestaetigt','wahrscheinlich','unsicher') NOT NULL, `status` ENUM('aktiv','geloescht') NOT NULL DEFAULT 'aktiv', `status_reason` VARCHAR(300) NULL, `actor` VARCHAR(160) NOT NULL, `user_id` INT UNSIGNED NULL, `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL. Tabellen `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

## Checkliste

### Bausteine herausziehen (Verhalten von M1 bleibt gleich)

Zweck des bestehenden Codes verstanden: `VehicleService` validiert Felder, prüft Rechte (`requireVehicle` = fremd heißt `not_found`; `assertCanWrite` = Rolle vor Scope, Texte aus ADR-008) und wendet die Konfliktregel an. Nur verschieben, Texte und Reihenfolge der Prüfungen unverändert.

- [ ] `app/src/Domain/Provenance.php`: `final class Provenance` mit `SOURCES`, `CONFIDENCE_RANK`, `CONFIRMED = 'bestaetigt'` (aus `VehicleService` verschoben); `public static function validate(array $input, string $context): array{source: string, source_url: string|null, confidence: string}` — Texte wie `VehicleService::validFact` (Feld „…“ → `$context`). `VehicleService` und `VehicleTools` auf `Provenance::` umstellen.
- [ ] `app/src/Domain/Validate.php`: `final class Validate`, alle Methoden `public static`, werfen `DomainError('invalid_value', '<Feld> „…“: <Grund> Beispiel: …', ['field' => …])`:
  - `date(string $field, mixed $raw, string $example): string` (aus `validDate`),
  - `int(string $field, mixed $raw, string $example, int $min = 0, ?int $max = null): string` (aus `validInt`, plus Grenzen),
  - `text(string $field, mixed $raw, int $min, int $max, string $example): string` (aus `validText`),
  - `optionalText(...)`: `null`/`''` → `null`, sonst `text`,
  - `enum(string $field, mixed $raw, array $allowed): string`,
  - `decimal(string $field, mixed $raw, int $scale, string $example): string` — akzeptiert Zahl oder Text mit `.` oder `,`, liefert immer genau `$scale` Nachkommastellen mit Punkt (`"2,5"`, Scale 2 → `"2.50"`),
  - `euroToCents(string $field, mixed $raw): ?string` — `null`/`''` → `null`; Muster `^\d{1,7}([.,]\d{1,2})?$` bzw. Zahl ≥ 0; Ergebnis Cent als Ganzzahl-Text (`"1288.7"` → `"128870"`),
  - `bool(string $field, mixed $raw): ?string` — `true`/`false` → `"1"`/`"0"`, `null` → `null`,
  - `url(string $field, mixed $raw): ?string` (aus `validUrl`).
  `VehicleService` nutzt diese statt der eigenen privaten Prüfer (die privaten löschen).
- [ ] `app/src/Domain/VehicleAccess.php`: `final class VehicleAccess` (Konstruktor `PDO`), `readable(int $vehicleId, Actor $actor): array{id,garage_id,label,status}` und `writable(int $vehicleId, Actor $actor): array` (mit Zeilensperre `find(..., true)`, dann Schreibrecht). Texte aus `requireVehicle`/`assertCanWrite`. Außerdem `public function assertCanWriteGarage(int $garageId, Actor $actor): void`. `VehicleService` nutzt sie.
- [ ] `Db\Transaction::dryRun(PDO $pdo, callable $work): mixed` — `beginTransaction`, `$work()`, immer `rollBack`, Ergebnis zurück; wirft `LogicException`, wenn schon eine Transaktion läuft.
- [ ] `AuditRepository::record(...)` bekommt als letzten Parameter `?int $vehicleId = null` und schreibt ihn; alle Aufrufe in `VehicleService` übergeben die Fahrzeug-ID (bei `vehicle.create` die neue).
- [ ] `OpenItemRepository`: Konstanten `KIND_QUESTION = 'nachfragen'`, `KIND_WATCH = 'beobachten'`; `insert(...)` bekommt `?array $proposal = null` (als JSON gespeichert); neu `findOpenByRef(string $refTable, int $refId, Actor $actor): list<array{id:int, proposal: array|null}>` (nur `status = 'offen'`, `garage_id IN (…)`).

### Datensatz-Abgleich

- [ ] `app/src/Domain/RecordMerger.php`: `final class RecordMerger`, Konstruktor `PDO`. Konstante `TABLES` — **einzige Quelle für Tabellen- und Spaltennamen im dynamischen SQL; nie Namen aus der Eingabe übernehmen**:
  ```php
  'registrations' => ['label' => 'Zulassung', 'key' => ['plate', 'valid_from'], 'columns' => ['valid_to', 'holder', 'holder_address']],
  'equipment'     => ['label' => 'Ausstattung', 'key' => ['name'], 'columns' => ['code', 'category', 'list_price_cents']],
  ```
  (Phasen 2 und 4 ergänzen hier ihre Tabellen.)
- [ ] `merge(string $table, array $values, array $provenance, array $vehicle, Actor $actor, ?array $key = null): array{id: int, result: string, open_item_ids: list<int>, changed: list<string>}`:
  - `$values`: Spalte → normalisierter Text oder `null` (Aufrufer normalisiert: Zahl als Ganzzahl-Text, Dezimal per `Validate::decimal`, Boolean `"1"`/`"0"`). `$key` überschreibt `TABLES[$table]['key']` (für Ereignisse, Phase 2).
  - Suche: `SELECT id, <key>, <columns>, confidence FROM <table> WHERE vehicle_id = ? AND garage_id IN (…) AND status = 'aktiv' AND <k1> <=> ? AND … ORDER BY id LIMIT 1 FOR UPDATE` (`<=>` vergleicht `NULL` richtig).
  - Keine Zeile → `INSERT` mit Schlüssel, Spalten, Herkunftsblock (`UTC_TIMESTAMP()` für `created_at`/`updated_at`), Audit `<table>.create` (after = alle Werte) → `angelegt`.
  - Zeile da, je Spalte in `$values`, deren Wert nicht `null` ist: DB-Wert als Text (`null` bleibt `null`) gegen Eingabe:
    - DB `null` → setzen (`ergaenzt`);
    - gleich → nichts;
    - verschieden, Zeile nicht `bestaetigt` → setzen (`aktualisiert`);
    - verschieden, Zeile `bestaetigt` → nichts ändern; vorhandenen offenen Klärfall mit gleicher `ref_table`/`ref_id` und gleichem `proposal.field` + `proposal.value` wiederverwenden, sonst `OpenItemRepository::insert(kind 'konflikt', ref_table = $table, ref_id = id, proposal = {field, value, source, source_url, confidence})`, Text: `Widerspruch bei „<spalte>“ (<label>) am Fahrzeug „<fahrzeug-label>“: bestätigt ist „<alt>“, neu gemeldet wird „<neu>“ (Quelle: <source>, Konfidenz <confidence>). Ein Mensch entscheidet, welcher Wert gilt.`
  - Neue Konfidenz höher als die der Zeile → `confidence`, `source`, `source_url` der Zeile auf die neue Herkunft (`aktualisiert`); wird eine Spalte gesetzt, ebenfalls Herkunft der Zeile auf die neue, aber Konfidenz nie senken.
  - Eine `UPDATE`-Anweisung für alle Änderungen plus `updated_at = UTC_TIMESTAMP()`, Audit `<table>.update` (before/after nur der geänderten Spalten); Klärfälle Audit `<table>.dispute`.
  - Ergebnis: `klaerfall` vor `aktualisiert` vor `ergaenzt` vor `unveraendert`.
  - Läuft innerhalb der Transaktion des Aufrufers (`Transaction::join`); der Aufrufer hat das Fahrzeug über `VehicleAccess::writable` gesperrt.
- [ ] `app/src/Domain/RecordRepository.php`: `listForVehicle(string $table, int $vehicleId, Actor $actor, string $orderBy): list<array>` — `$orderBy` nur aus einer Konstanten-Liste je Tabelle (`registrations` → `valid_from DESC, id DESC`, `equipment` → `category, name`), liefert alle Spalten `aktiv` samt `source`, `source_url`, `confidence`, `actor`, `updated_at`.

### Spezifikationen (Fakten)

- [ ] `VehicleService`: Konstante `SPEC_PREFIX = 'spec.'`, `SPEC_PART = '/^[a-z][a-z0-9_]{1,30}$/'`. `validFact` weist Felder mit `spec.` ab: „Spezifikationen über set_spec, z. B. {"area": "motoroel", "key": "viskositaet", "value": "0W-20"}“.
- [ ] `VehicleService::setSpecs(int $vehicleId, array $specs, array $provenance, Actor $actor): array` — `specs` nicht leer, je Eintrag `area`/`key` per Muster, `value` per `Validate::text(…, 1, 200, '0W-20')`, kein Paar doppelt; baut `field = 'spec.' . area . '.' . key` und läuft durch denselben Weg wie `setFacts` (Transaktion, Sperre, `applyFact`). Ergebnis je Eintrag `{area, key, value, result, open_item_id?}`.
- [ ] `VehicleService::get` trennt: `facts` ohne `spec.`-Felder; `specs` = `{<area>: {<key>: {value, source, source_url, confidence, actor, recorded_at}}}`; `disputed` unverändert (enthält auch `spec.…`).

### Zulassung und Ausstattung

- [ ] `app/src/Domain/RegistrationService.php`: `record(int $vehicleId, array $input, Actor $actor): array` — `plate` = `Validate::text(1–15)`, in Großbuchstaben, Mehrfach-Leerzeichen zu einem; `valid_from`/`valid_to` per `Validate::date`, `valid_to` ≥ `valid_from` sonst `invalid_value`; `holder` optional 1–120, `holder_address` optional 1–200; Herkunft per `Provenance::validate`; in `Transaction::run`: `VehicleAccess::writable`, `RecordMerger::merge('registrations', …)`. Rückgabe `{registration_id, result, open_item_ids}`. `list(int $vehicleId, Actor)` über `RecordRepository`.
- [ ] `app/src/Domain/EquipmentService.php`: `upsert(int $vehicleId, array $items, array $provenance, Actor $actor)` — `items` 1–200 Einträge; `name` 1–160, `code` optional 1–20 (Großbuchstaben), `category` `serie`|`sonder`, `list_price_eur` per `Validate::euroToCents`; Namen innerhalb eines Aufrufs eindeutig; alles in einer Transaktion. Rückgabe `{vehicle_id, items:[{name, equipment_id, result, open_item_ids}]}`. `list(...)`.
- [ ] `VehicleService::get` ergänzt `registrations` (je `{id, plate, valid_from, valid_to, holder, holder_address, source, source_url, confidence}`), `holder_count`, `equipment` (je `{id, name, code, category, list_price_eur, source, confidence}`; Cent → Euro-Text mit zwei Stellen).

### Tools

- [ ] `app/src/Mcp/Tools/VehicleTools.php`: neues Tool `set_spec` (Scope write, `W`), Eingabe `vehicle_id`, `specs` (Liste von `{area, key, value}`), `source`, `source_url`, `confidence`; Beschreibung mit Beispiel `{"vehicle_id": 12, "specs": [{"area": "motoroel", "key": "viskositaet", "value": "0W-20"}, {"area": "motoroel", "key": "fuellmenge", "value": "4,3 l"}], "source": "beleg", "confidence": "bestaetigt"}` und Liste üblicher Bereiche: `motoroel`, `kuehlmittel`, `bremsfluessigkeit`, `getriebeoel`, `batterie`, `reifen`, `steuertrieb`.
- [ ] `app/src/Mcp/Tools/RegistrationTools.php` (`record_registration`) und `app/src/Mcp/Tools/EquipmentTools.php` (`upsert_equipment`) nach Muster `VehicleTools`; Beispiele in der Beschreibung; Herkunfts-Schema als gemeinsame Hilfe `Tools\ProvenanceSchema::properties(): array` (neu, liefert `source`, `source_url`, `confidence` für `Schema::object`).
- [ ] `McpController::services()` ergänzt `registrations`, `equipment`; `registry()` ergänzt Gruppe „Stammdaten“ mit beiden Tool-Klassen.

### Prüfen (auf Strato, nach `deploy.cmd`)

- [ ] Testkonten anlegen: Einladungen per Deploy-Endpunkt für `test-m2-a@example.com` (Garage „Test A M2“, `inhaber`), `test-m2-b@example.com` (Garage „Test B M2“), `test-m2-c@example.com` (Garage A, Rolle `lesend`); je ein festes Token, A zusätzlich eins nur `garage:read`. Token-Klartexte nur in der Session, nie in Dateien.
- [ ] Alle AK oben per `curl` gegen `https://quantum-canvas.de/garage/mcp`; M1-Konfliktregel erneut mit den fünf Zweigen.

### Doku

- [ ] ADR-009 „Datensatz-Abgleich und Spezifikationen als Fakten“ (E1, E2; verworfen: eigene Tabelle `vehicle_specs` — bräuchte eine zweite Konfliktregel; Überschreiben ohne Schlüssel — doppelt jeden Import).
- [ ] `docs/models.md` (neue Tabellen, Herkunftsblock, geänderte Spalten), `docs/routes.md` (Tools), `docs/code-map.md` (`Domain/` Bausteine, `Registration*`, `Equipment*`), `docs/glossary.md` (Datensatz-Abgleich, Spezifikation, Zulassung).

## Report-Back
