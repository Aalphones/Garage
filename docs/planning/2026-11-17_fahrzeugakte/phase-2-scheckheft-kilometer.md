# Phase 2 — Scheckheft und Kilometer

Rating: **heikel** (Idempotenz über Rechnungsnummer, Kilometer-Regel mit Klärfall, Trockenlauf). Status: **complete**.

## Kontext — vorher lesen

- [`README.md`](README.md) → E2, E3, E7, E8, Kontrakt (Klärfall-Vorschlag für `events` und `odometer_readings`, Tools dieser Phase).
- [`phase-1-fundament-stammdaten.md`](phase-1-fundament-stammdaten.md) → Bausteine (`Provenance`, `Validate`, `VehicleAccess`, `RecordMerger`, `Transaction::dryRun`) und Herkunftsblock — so gebaut, wie dort im Report-Back steht.
- `docs/design/konzept-garage.md` → „Datenmodell“ (`events`, `event_items`, `workshops`, `odometer_readings`, Regeln im Code).
- `docs/conventions/php.md` (Kilometer laufen nur vorwärts; Zeitvergleich in der Abfrage).
- Fehlerklassen: keine einschlägigen im Vault.

## Abnahmekriterien

- [x] `record_service_event` mit Werkstatt, Rechnungsnummer, km und drei Positionen → `angelegt`; Kilometerstand mit `event_id` angelegt; `get_event` zeigt Ereignis, Werkstatt, Positionen (Euro-Beträge mit zwei Stellen) und Stand.
- [x] Derselbe Aufruf noch einmal → Ereignis `unveraendert`, `items_result` `unveraendert`, Stand `unveraendert`; keine zweite Zeile in `events`, `event_items`, `odometer_readings`.
- [x] Gleiche Rechnung mit zusätzlichem `order_number` → `ergaenzt`. Gleiche Rechnung (bestätigt) mit anderer Bruttosumme → `klaerfall` (Spalte `gross_cents`). Gleiche Rechnung mit anderen Positionen → `items_result` `klaerfall`, Klärfall mit `proposal_json.items`, bestehende Positionen unverändert.
- [x] Ohne Rechnungsnummer: Schlüssel ist Art + Leistungsdatum + Werkstatt; zweiter Aufruf → `unveraendert`.
- [x] `validate_service_event` mit denselben Daten wie ein neues Ereignis → Ausgabe wie `record_service_event` plus `dry_run: true`; danach `list_events` unverändert, kein Klärfall, kein Audit-Eintrag.
- [x] `record_odometer`: 26.910 km am 2026-03-02, dann 21.000 km am 2026-05-01 → zweiter `klaerfall`, Zeile `strittig`, `proposal_json.conflicts_with` enthält die ID des ersten Stands. Ein größerer Stand am selben Tag → `angelegt`. Derselbe Stand erneut → `unveraendert`.
- [x] Ungültige Eingaben → `invalid_value` mit Feld und Beispiel: Art außerhalb der Liste, Datum falsch, km negativ oder über 2.000.000, Betrag mit drei Nachkommastellen, Position ohne `description`, mehr als 200 Positionen; ganze Anfrage schreibt nichts.
- [x] Mandanten: B liest/schreibt Ereignis oder Stand von A → `not_found` (auch `get_event` mit fremder `event_id`); `lesend` → `forbidden`; `garage:read` → 403.

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

- [x] `app/src/Domain/WorkshopRepository.php`: `findOrCreate(int $garageId, string $name, string $city, ?string $partner, Actor $actor): int` — `SELECT … WHERE garage_id = ? AND name = ? AND city = ?`, sonst `INSERT`; `manufacturer_partner` nur setzen, wenn bisher `NULL`. `find(int $id, Actor)`.
- [x] `RecordMerger::TABLES` ergänzen: `'events' => ['label' => 'Ereignis', 'key' => ['workshop_id', 'invoice_number'], 'columns' => ['title', 'invoiced_on', 'odometer_km', 'order_number', 'net_cents', 'vat_cents', 'gross_cents', 'digital_service_record']]` und `'odometer_readings'` **nicht** (eigene Regel unten).
- [x] `app/src/Domain/EventService.php`:
  - `record(array $input, Actor $actor, bool $dryRun = false): array` — validiert alles vorab (`Validate`, `Provenance`), dann in `$dryRun ? Transaction::dryRun : Transaction::run`: `VehicleAccess::writable`; Werkstatt per `findOrCreate` (oder `null`); Schlüssel: mit `invoice_number` → `['workshop_id', 'invoice_number']`, ohne → `['kind', 'performed_on', 'workshop_id']` (über `$key`-Parameter von `RecordMerger::merge`); `kind` und `performed_on` sind dabei Schlüssel, `title` usw. Spalten. Bei Schlüssel mit Rechnungsnummer gehören `kind` und `performed_on` zu den Spalten-Werten (abweichend → Klärfall wie jede Spalte) — dafür in `TABLES['events']['columns']` zusätzlich `kind`, `performed_on` aufnehmen und beim Schlüssel ohne Rechnungsnummer aus `$values` weglassen.
  - Positionen: Ereignis neu oder ohne aktive Positionen → alle einfügen (`line_no` = Index + 1), `angelegt` bzw. `ergaenzt`. Hat aktive Positionen: gleiche Anzahl und je `line_no` gleiche `item_type`, `description`, `net_cents` → `unveraendert`; sonst nichts ändern, Klärfall `konflikt` mit `ref_table = 'events'`, `ref_id = event_id`, `proposal = {items: <validierte Eingabe>, source, source_url, confidence}`, Text „Die Positionen der Rechnung <nr> weichen von den gespeicherten ab (<alt> gegen <neu> Positionen). Ein Mensch entscheidet, welche gelten.“; vorhandener offener Klärfall mit gleicher `ref` und gleichem `proposal.items` wird wiederverwendet.
  - `odometer_km` gesetzt → `OdometerService::recordInTransaction(vehicle, performed_on, km, event_id, provenance, actor)`.
  - Warnung (kein Fehler): `gross_eur` fehlt bei `kind` `inspektion`/`reparatur` → „Bruttosumme fehlt“.
  - `list(int $vehicleId, ?string $from, ?string $to, ?string $kind, Actor)` → je Ereignis `{id, kind, title, performed_on, odometer_km, workshop, invoice_number, gross_eur, item_count, source, confidence}`, neueste zuerst.
  - `get(int $eventId, Actor)` → Ereignis + `items` (aktiv, nach `line_no`) + `odometer` (Zeile mit `event_id`) + `workshop`; fremd/unbekannt → `not_found` („Ein Ereignis mit dieser ID gibt es in deinen Garagen nicht. list_events zeigt die vorhandenen.“).
- [x] `app/src/Domain/OdometerService.php` + `OdometerRepository.php`:
  - `record(int $vehicleId, string $readOn, string $km, ?string $note, array $provenance, Actor)` (eigene Transaktion, sperrt Fahrzeug) und `recordInTransaction(...)` (läuft in der Transaktion des Aufrufers).
  - Regel: gleicher `read_on` und `km` als `gueltig` oder `strittig` vorhanden → `unveraendert` (bei höherer Konfidenz Herkunft heben). Sonst `conflicts` = IDs gültiger Stände mit (`read_on` ≤ Datum und `km` > neuer km) oder (`read_on` > Datum und `km` < neuer km) — per SQL `WHERE vehicle_id = ? AND reading_status = 'gueltig' AND garage_id IN (…) AND ((read_on <= ? AND km > ?) OR (read_on > ? AND km < ?))`. Leer → `gueltig`, `angelegt`. Sonst Zeile `strittig` + Klärfall `konflikt` (`ref_table = 'odometer_readings'`, `ref_id` = neue Zeile, `proposal = {conflicts_with: [...]}`), Text „Kilometerstand <km> am <datum> passt nicht zu <km2> am <datum2>. Kilometer laufen nur vorwärts; ein Mensch entscheidet, welcher Stand gilt.“ → `klaerfall`.
  - `validReadings(int $vehicleId, Actor): list<array{id, read_on, km}>` nach `read_on, km` (für Phase 3).
  - Audit `odometer.create` / `odometer.dispute` mit `vehicle_id`.

### Tools

- [x] `app/src/Mcp/Tools/EventTools.php`: `record_service_event`, `validate_service_event` (beide Scope write; Beschreibung von `validate_…`: „Trockenlauf: zeigt, was angelegt würde und welche Klärfälle entstünden; speichert nichts. IDs im Ergebnis sind vorläufig.“), `list_events`, `get_event` (read). Beschreibung von `record_service_event` mit vollständigem Beispiel einer Inspektion (Werkstatt „Autohaus Ostmann“, Rechnung „R-2026-0815“, Positionen Arbeit/Teil/Material, `task_key` `oelwechsel`) und dem Satz „Ein zweiter Aufruf mit derselben Rechnung ergänzt statt zu verdoppeln.“
- [x] `app/src/Mcp/Tools/OdometerTools.php`: `record_odometer` (write).
- [x] `McpController`: Dienste `events`, `odometer`; Gruppe „Scheckheft“.

### Prüfen (auf Strato, nach `deploy.cmd`)

- [x] Alle AK per `curl` mit den Testkonten aus Phase 1; je Schreib-Tool zweiter identischer Aufruf.

### Doku

- [x] ADR-011 Teil 1 „Kilometer vorwärts, strittige Stände“ (E7) — die Datei `docs/decisions/011-faelligkeit-und-kilometer.md` anlegen, Phase 3 ergänzt die Fälligkeitsrechnung.
- [x] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (`Event*`, `Odometer*`, `Workshop*`), `docs/glossary.md` (Werkstatt, Trockenlauf, strittiger Stand).

## Report-Back

**Ergebnis:** Phase 2 vollständig umgesetzt und auf Strato deployt (Migration 009 angewandt, Rauchtest grün). Alle Abnahmekriterien per `curl` mit eigenen Testkonten geprüft, bis auf einen Teil (siehe 🟡).

**Commits (main):** `7c8d68b` Migration 009 · `0bd1458` `Money` aus `EquipmentService` gezogen · `1633da4` Fachlogik (`EventService`, `OdometerService`, Repositories) · `3f46de8` fünf Tools · `3b6b8a8` und `1f22b45` zwei Korrekturen · Doku in einem eigenen `docs`-Commit danach.

**Geprüft (Strato, Konten `p2ra/b/c`, rund 55 Einzelprüfungen):** Rechnung `angelegt` → `unveraendert` (Ereignis, Positionen, Stand; je eine Zeile) → `ergaenzt` (Auftragsnummer) → `klaerfall` (andere Bruttosumme gegen bestätigt) → Positionen anders → `items_result` `klaerfall`, gespeicherte Positionen unverändert, Wiederholung liefert denselben Klärfall; ohne Rechnungsnummer zweiter Aufruf `unveraendert`; Warnung „Bruttosumme fehlt“; Trockenlauf mit `dry_run: true`, danach `list_events` unverändert; Kilometer 26.910 → 21.000 `klaerfall` (Wiederholung gleicher Klärfall), größerer Stand am selben Tag `angelegt`, derselbe Stand `unveraendert`; Ereignis mit rückwärts laufendem Stand `angelegt`, Stand `klaerfall`; elf ungültige Eingaben (Art, Datum, km −5 und 2.000.001, Betrag mit drei Stellen, Position ohne Text, 201 Positionen, `task_key`) → `invalid_value` mit Feld und Beispiel, nichts geschrieben; B → `not_found` bei `get_event`, `list_events`, `record_service_event`, `record_odometer`, `validate_service_event`; `lesend` liest, schreibt nirgends (`forbidden`); Token nur `garage:read` → `-32003`, `tools/list` zeigt nur `list_events`, `get_event`.

**Abweichungen vom Plan:**

- `OdometerService::record` nimmt die Eingabe als Array und prüft sie selbst (statt fertig normalisierter Strings); `recordInTransaction` hat zusätzlich `$note`. Das Ergebnis der Tools ist wie im Kontrakt.
- Derselbe Stand mit höherer Konfidenz meldet `aktualisiert` (Vokabular des Abgleichs) statt `unveraendert`; Herkunft wird dabei gehoben.
- Ein Stand, der schon ohne `event_id` besteht, wird vom später meldenden Ereignis verknüpft (kein Ergebnis-Wechsel).
- Eigene Klassen `EventRepository`, `Money`; Ereignisse laufen nicht durch `RecordRepository` (Lesen mit Werkstatt-Join), deshalb kein Eintrag in `RecordRepository::ORDER`. `event_items.quantity` ist `DECIMAL(12,3)` statt `(10,3)` (die Prüfung lässt neun Vorkommastellen zu).
- `Registry` prüft jetzt auch Typlisten (`['number','string']`) auf oberster Ebene; vorher wäre ein Geldfeld dort ein TypeError gewesen.
- `RecordMerger` lässt `NULL` als Schlüsselwert zu (Ereignis ohne Werkstatt); die Suche nutzt ohnehin `<=>`.
- `invoice_number` im Schlüssel und `kind`/`performed_on` als Spalten gelten nur mit Rechnungsnummer; ohne sie sind `kind`, `performed_on`, `workshop_id` der Schlüssel (wie geplant, über den `$key`-Parameter).

**Befund und Fix:** Die JSON-Spalte sortiert Objektschlüssel um; der strikte Vergleich der Positions-Vorschläge legte bei jeder Wiederholung einen neuen Klärfall an (`3b6b8a8`). Behoben, auf Strato nachgeprüft.

**🟡 Nicht von außen belegt:** „Trockenlauf legt keinen Klärfall und keinen Audit-Eintrag an“ (Rollback) und der Inhalt von `proposal_json.items`/`conflicts_with` lassen sich ohne Lese-Zugang zur Datenbank nicht sehen; belegt über das Verhalten (Wiederholung liefert denselben Klärfall, `list_events` nach Trockenlauf unverändert) und im Code. Prüfung mit Phase 5.

**🟡 Deploy und OPcache:** Direkt nach einem Deploy lieferte Strato kurz noch den alten Code (Prüfung nach dem Fix zeigte das alte Verhalten, eine Minute später das neue). Nach einem Deploy vor der Prüfung etwa eine Minute warten.

**Aufräumen:** Testkonten `p2ra-1794910740`, `p2rb-…`, `p2rc-…@example.com` (Garagen „Test A P2“, „Test B P2“) samt Tokens bleiben bis Phase 7; ihre Adressen passen nicht auf `test-%@example.com` — die Sperr-Migration in Phase 7 muss sie ausdrücklich nennen (Eintrag in `FINDINGS.md`). Zwei nie angenommene Einladungen („Probe“, „Probe2“) laufen nach 7 Tagen ab.
