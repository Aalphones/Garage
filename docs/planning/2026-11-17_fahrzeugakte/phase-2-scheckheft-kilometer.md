# Phase 2 — Scheckheft und Kilometer

Rating: **heikel** (Idempotenz über Rechnungsnummer, Kilometer-Regel mit Klärfall, Trockenlauf). Status: **pending**.

## Kontext — vorher lesen

- [`README.md`](README.md) → E2, E3, E7, E8, Kontrakt (Klärfall-Vorschlag für `events` und `odometer_readings`, Tools dieser Phase).
- [`phase-1-fundament-stammdaten.md`](phase-1-fundament-stammdaten.md) → Bausteine (`Provenance`, `Validate`, `VehicleAccess`, `RecordMerger`, `Transaction::dryRun`) und Herkunftsblock — so gebaut, wie dort im Report-Back steht.
- `docs/design/konzept-garage.md` → „Datenmodell“ (`events`, `event_items`, `workshops`, `odometer_readings`, Regeln im Code).
- `docs/conventions/php.md` (Kilometer laufen nur vorwärts; Zeitvergleich in der Abfrage).
- Fehlerklassen: keine einschlägigen im Vault.

## Abnahmekriterien

- [ ] `record_service_event` mit Werkstatt, Rechnungsnummer, km und drei Positionen → `angelegt`; Kilometerstand mit `event_id` angelegt; `get_event` zeigt Ereignis, Werkstatt, Positionen (Euro-Beträge mit zwei Stellen) und Stand.
- [ ] Derselbe Aufruf noch einmal → Ereignis `unveraendert`, `items_result` `unveraendert`, Stand `unveraendert`; keine zweite Zeile in `events`, `event_items`, `odometer_readings`.
- [ ] Gleiche Rechnung mit zusätzlichem `order_number` → `ergaenzt`. Gleiche Rechnung (bestätigt) mit anderer Bruttosumme → `klaerfall` (Spalte `gross_cents`). Gleiche Rechnung mit anderen Positionen → `items_result` `klaerfall`, Klärfall mit `proposal_json.items`, bestehende Positionen unverändert.
- [ ] Ohne Rechnungsnummer: Schlüssel ist Art + Leistungsdatum + Werkstatt; zweiter Aufruf → `unveraendert`.
- [ ] `validate_service_event` mit denselben Daten wie ein neues Ereignis → Ausgabe wie `record_service_event` plus `dry_run: true`; danach `list_events` unverändert, kein Klärfall, kein Audit-Eintrag.
- [ ] `record_odometer`: 26.910 km am 2026-03-02, dann 21.000 km am 2026-05-01 → zweiter `klaerfall`, Zeile `strittig`, `proposal_json.conflicts_with` enthält die ID des ersten Stands. Ein größerer Stand am selben Tag → `angelegt`. Derselbe Stand erneut → `unveraendert`.
- [ ] Ungültige Eingaben → `invalid_value` mit Feld und Beispiel: Art außerhalb der Liste, Datum falsch, km negativ oder über 2.000.000, Betrag mit drei Nachkommastellen, Position ohne `description`, mehr als 200 Positionen; ganze Anfrage schreibt nichts.
- [ ] Mandanten: B liest/schreibt Ereignis oder Stand von A → `not_found` (auch `get_event` mit fremder `event_id`); `lesend` → `forbidden`; `garage:read` → 403.

## Datenmodell — `app/migrations/009_event.sql`

- `workshops`: `id` PK, `garage_id` INT UNSIGNED NOT NULL, `name` VARCHAR(120) NOT NULL, `city` VARCHAR(80) NOT NULL DEFAULT '', `manufacturer_partner` TINYINT(1) NULL, `actor` VARCHAR(160) NOT NULL, `created_at` DATETIME NOT NULL; UNIQUE (`garage_id`, `name`, `city`).
- `events`: `id` PK, `garage_id`, `vehicle_id` FK→vehicles, `kind` ENUM('inspektion','reparatur','hu','reifen','schaden','rueckruf','kauf','sonstiges') NOT NULL, `title` VARCHAR(200) NOT NULL, `performed_on` DATE NOT NULL, `invoiced_on` DATE NULL, `odometer_km` INT UNSIGNED NULL, `workshop_id` INT UNSIGNED NULL FK→workshops, `invoice_number` VARCHAR(60) NULL, `order_number` VARCHAR(60) NULL, `net_cents` INT UNSIGNED NULL, `vat_cents` INT UNSIGNED NULL, `gross_cents` INT UNSIGNED NULL, `digital_service_record` TINYINT(1) NULL, **Herkunftsblock**; INDEX (`vehicle_id`, `status`, `performed_on`), INDEX (`workshop_id`, `invoice_number`).
- `event_items`: `id` PK, `garage_id`, `event_id` FK→events, `line_no` SMALLINT UNSIGNED NOT NULL, `item_type` ENUM('arbeit','teil','material','pauschale') NOT NULL, `description` VARCHAR(200) NOT NULL, `task_key` VARCHAR(41) NULL, `part_number` VARCHAR(60) NULL, `manufacturer` VARCHAR(80) NULL, `quantity` DECIMAL(10,3) NULL, `unit` VARCHAR(10) NULL, `net_cents` INT NULL (negativ erlaubt für Rabatte), `status` ENUM('aktiv','abgeloest') NOT NULL DEFAULT 'aktiv', `created_at`; INDEX (`event_id`, `status`), INDEX (`task_key`).
- `odometer_readings`: `id` PK, `garage_id`, `vehicle_id` FK, `read_on` DATE NOT NULL, `km` INT UNSIGNED NOT NULL, `event_id` INT UNSIGNED NULL, `note` VARCHAR(200) NULL, `reading_status` ENUM('gueltig','strittig','verworfen') NOT NULL, **Herkunftsblock**; INDEX (`vehicle_id`, `reading_status`, `read_on`).

## Kontrakt dieser Phase

### `record_service_event` / `validate_service_event` — Eingabe

```
vehicle_id (Pflicht), kind (Pflicht), title (Pflicht, 1–200), performed_on (Pflicht),
invoiced_on?, odometer_km? (0–2.000.000),
workshop?: {name (1–120), city? (1–80), manufacturer_partner? (bool)},
invoice_number? (1–60), order_number? (1–60),
net_eur?, vat_eur?, gross_eur?, digital_service_record? (bool),
items?: [{type, description, task_key? (^[a-z][a-z0-9_]{1,40}$), part_number? (1–60), manufacturer? (1–80), quantity? (Dezimal, 3 Stellen), unit? (1–10), net_eur? (darf negativ sein)}] (höchstens 200),
source, source_url?, confidence
```

### Ausgabe

```
{event_id, result, items_result: angelegt|ergaenzt|unveraendert|klaerfall, items_open_item_id?,
 odometer: {reading_id, result, open_item_id?} | null,
 open_item_ids: [...alle neuen/wiederverwendeten Klärfälle...],
 warnings: [Text, ...], dry_run?: true}
```

## Checkliste

### Fachlogik

- [ ] `app/src/Domain/WorkshopRepository.php`: `findOrCreate(int $garageId, string $name, string $city, ?string $partner, Actor $actor): int` — `SELECT … WHERE garage_id = ? AND name = ? AND city = ?`, sonst `INSERT`; `manufacturer_partner` nur setzen, wenn bisher `NULL`. `find(int $id, Actor)`.
- [ ] `RecordMerger::TABLES` ergänzen: `'events' => ['label' => 'Ereignis', 'key' => ['workshop_id', 'invoice_number'], 'columns' => ['title', 'invoiced_on', 'odometer_km', 'order_number', 'net_cents', 'vat_cents', 'gross_cents', 'digital_service_record']]` und `'odometer_readings'` **nicht** (eigene Regel unten).
- [ ] `app/src/Domain/EventService.php`:
  - `record(array $input, Actor $actor, bool $dryRun = false): array` — validiert alles vorab (`Validate`, `Provenance`), dann in `$dryRun ? Transaction::dryRun : Transaction::run`: `VehicleAccess::writable`; Werkstatt per `findOrCreate` (oder `null`); Schlüssel: mit `invoice_number` → `['workshop_id', 'invoice_number']`, ohne → `['kind', 'performed_on', 'workshop_id']` (über `$key`-Parameter von `RecordMerger::merge`); `kind` und `performed_on` sind dabei Schlüssel, `title` usw. Spalten. Bei Schlüssel mit Rechnungsnummer gehören `kind` und `performed_on` zu den Spalten-Werten (abweichend → Klärfall wie jede Spalte) — dafür in `TABLES['events']['columns']` zusätzlich `kind`, `performed_on` aufnehmen und beim Schlüssel ohne Rechnungsnummer aus `$values` weglassen.
  - Positionen: Ereignis neu oder ohne aktive Positionen → alle einfügen (`line_no` = Index + 1), `angelegt` bzw. `ergaenzt`. Hat aktive Positionen: gleiche Anzahl und je `line_no` gleiche `item_type`, `description`, `net_cents` → `unveraendert`; sonst nichts ändern, Klärfall `konflikt` mit `ref_table = 'events'`, `ref_id = event_id`, `proposal = {items: <validierte Eingabe>, source, source_url, confidence}`, Text „Die Positionen der Rechnung <nr> weichen von den gespeicherten ab (<alt> gegen <neu> Positionen). Ein Mensch entscheidet, welche gelten.“; vorhandener offener Klärfall mit gleicher `ref` und gleichem `proposal.items` wird wiederverwendet.
  - `odometer_km` gesetzt → `OdometerService::recordInTransaction(vehicle, performed_on, km, event_id, provenance, actor)`.
  - Warnung (kein Fehler): `gross_eur` fehlt bei `kind` `inspektion`/`reparatur` → „Bruttosumme fehlt“.
  - `list(int $vehicleId, ?string $from, ?string $to, ?string $kind, Actor)` → je Ereignis `{id, kind, title, performed_on, odometer_km, workshop, invoice_number, gross_eur, item_count, source, confidence}`, neueste zuerst.
  - `get(int $eventId, Actor)` → Ereignis + `items` (aktiv, nach `line_no`) + `odometer` (Zeile mit `event_id`) + `workshop`; fremd/unbekannt → `not_found` („Ein Ereignis mit dieser ID gibt es in deinen Garagen nicht. list_events zeigt die vorhandenen.“).
- [ ] `app/src/Domain/OdometerService.php` + `OdometerRepository.php`:
  - `record(int $vehicleId, string $readOn, string $km, ?string $note, array $provenance, Actor)` (eigene Transaktion, sperrt Fahrzeug) und `recordInTransaction(...)` (läuft in der Transaktion des Aufrufers).
  - Regel: gleicher `read_on` und `km` als `gueltig` oder `strittig` vorhanden → `unveraendert` (bei höherer Konfidenz Herkunft heben). Sonst `conflicts` = IDs gültiger Stände mit (`read_on` ≤ Datum und `km` > neuer km) oder (`read_on` > Datum und `km` < neuer km) — per SQL `WHERE vehicle_id = ? AND reading_status = 'gueltig' AND garage_id IN (…) AND ((read_on <= ? AND km > ?) OR (read_on > ? AND km < ?))`. Leer → `gueltig`, `angelegt`. Sonst Zeile `strittig` + Klärfall `konflikt` (`ref_table = 'odometer_readings'`, `ref_id` = neue Zeile, `proposal = {conflicts_with: [...]}`), Text „Kilometerstand <km> am <datum> passt nicht zu <km2> am <datum2>. Kilometer laufen nur vorwärts; ein Mensch entscheidet, welcher Stand gilt.“ → `klaerfall`.
  - `validReadings(int $vehicleId, Actor): list<array{id, read_on, km}>` nach `read_on, km` (für Phase 3).
  - Audit `odometer.create` / `odometer.dispute` mit `vehicle_id`.

### Tools

- [ ] `app/src/Mcp/Tools/EventTools.php`: `record_service_event`, `validate_service_event` (beide Scope write; Beschreibung von `validate_…`: „Trockenlauf: zeigt, was angelegt würde und welche Klärfälle entstünden; speichert nichts. IDs im Ergebnis sind vorläufig.“), `list_events`, `get_event` (read). Beschreibung von `record_service_event` mit vollständigem Beispiel einer Inspektion (Werkstatt „Autohaus Ostmann“, Rechnung „R-2026-0815“, Positionen Arbeit/Teil/Material, `task_key` `oelwechsel`) und dem Satz „Ein zweiter Aufruf mit derselben Rechnung ergänzt statt zu verdoppeln.“
- [ ] `app/src/Mcp/Tools/OdometerTools.php`: `record_odometer` (write).
- [ ] `McpController`: Dienste `events`, `odometer`; Gruppe „Scheckheft“.

### Prüfen (auf Strato, nach `deploy.cmd`)

- [ ] Alle AK per `curl` mit den Testkonten aus Phase 1; je Schreib-Tool zweiter identischer Aufruf.

### Doku

- [ ] ADR-011 Teil 1 „Kilometer vorwärts, strittige Stände“ (E7) — die Datei `docs/decisions/011-faelligkeit-und-kilometer.md` anlegen, Phase 3 ergänzt die Fälligkeitsrechnung.
- [ ] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (`Event*`, `Odometer*`, `Workshop*`), `docs/glossary.md` (Werkstatt, Trockenlauf, strittiger Stand).

## Report-Back
