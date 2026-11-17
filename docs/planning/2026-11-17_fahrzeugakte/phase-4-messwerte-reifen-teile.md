# Phase 4 — Messwerte, Reifen, Teile, Schäden, Rückrufe, Notizen

Rating: **standard** (alles nach dem Muster aus Phase 1; einzige Eigenheiten: Reifenmontage und Notiz-Revisionen). Status: **complete**.

## Kontext — vorher lesen

- [`README.md`](README.md) → E2, E9, Kontrakt (Tools dieser Phase, Ergebnis-Vokabular).
- [`phase-1-fundament-stammdaten.md`](phase-1-fundament-stammdaten.md) → `RecordMerger`, `RecordRepository`, `Validate`, `Provenance`, `VehicleAccess`, Herkunftsblock; Vorbild für Service + Tool: `RegistrationService` / `RegistrationTools`.
- `docs/design/konzept-garage.md` → „Datenmodell“ (`measurements`, `tire_sets`, `tire_mounts`, `parts`, `damages`, `recalls`, `notes`) und „Recherche“ (Messwerte und Befunde, Reifen und Räder, Schäden/Rückrufe).
- Fehlerklassen: keine einschlägigen im Vault. Wichtigster Prüfpunkt aus README-Risiko 1: `DECIMAL` normalisiert vergleichen.

## Abnahmekriterien

- [x] Je Schreib-Tool: erster Aufruf `angelegt`, zweiter identischer `unveraendert` (auch bei Dezimalwerten wie Profiltiefe `"6,5"` → gespeichert `6.50`).
- [x] `record_measurement` mit vier Profiltiefen (VL, VR, HL, HR) in einem Aufruf → vier Zeilen; gleiches Datum, gleiche Größe, gleiche Position mit anderem Wert gegen bestätigt → `klaerfall`. `get_measurements` mit `quantity: profiltiefe` liefert je Position eine Reihe nach Datum.
- [x] Mit `odometer_km` legt `record_measurement` zusätzlich einen Kilometerstand an (Regel aus Phase 2, Ergebnis im Feld `odometer`).
- [x] `record_tires`: Satz anlegen, montieren am 2026-04-01 → `get_tires` zeigt `mounted_set_id`; zweiten Satz montieren am 2026-10-15 → erster Satz bekommt `removed_on` 2026-10-15 automatisch; `unmount` ohne offene Montage → `invalid_value`. `dot_age_years` wird aus `dot` (Woche/Jahr `WWYY`) und heute berechnet.
- [x] `add_part` zweimal identisch → `unveraendert`; gleicher Schlüssel, `verified: true` dazu → `ergaenzt`. `get_parts` gruppiert nach `component`, verbaute zuerst.
- [x] `record_damage` und `record_recall` mit Schlüssel aus dem Kontrakt; Rückruf ohne `kba_reference` und ohne `manufacturer_code` → `invalid_value`.
- [x] `upsert_note`: neues Thema → `angelegt`, Revision 1; anderer Text → `aktualisiert`, Revision 2, Revision 1 bleibt (nur im Code belegt: kein Lese-Tool für Revisionen); gleicher Text → `unveraendert`.
- [x] Mandanten wie Phase 1 für jedes neue Tool (fremd → `not_found`, `lesend` → `forbidden`, `garage:read` → 403).

## Datenmodell

Alle Listen-Tabellen: `id` PK, `garage_id` INT UNSIGNED NOT NULL, `vehicle_id` FK→vehicles NOT NULL, Fachspalten, **Herkunftsblock** (Phase 1), INDEX (`vehicle_id`, `status`).

- `011_measurement.sql` — `measurements`: `measured_on` DATE NOT NULL, `quantity` ENUM('profiltiefe','reifendruck','bremsbelag','bremsscheibe','batterie_spannung','batterie_kaltstart','frostschutz','hu_bremswert','sonstiges') NOT NULL, `position` ENUM('vl','vr','hl','hr','vorne','hinten','gesamt') NOT NULL DEFAULT 'gesamt', `label` VARCHAR(80) NOT NULL DEFAULT '' (nur bei `sonstiges` gefüllt), `value` DECIMAL(10,2) NOT NULL, `unit` VARCHAR(10) NOT NULL, `odometer_km` INT UNSIGNED NULL, `note` VARCHAR(200) NULL, `event_id` INT UNSIGNED NULL.
- `012_tire.sql` — `tire_sets`: `label` VARCHAR(80) NOT NULL, `brand` VARCHAR(60) NULL, `model` VARCHAR(80) NULL, `size` VARCHAR(30) NOT NULL, `season` ENUM('sommer','winter','ganzjahr') NOT NULL, `dot` CHAR(4) NULL, `storage_location` VARCHAR(120) NULL. `tire_mounts`: `id` PK, `garage_id`, `vehicle_id` FK, `tire_set_id` FK→tire_sets, `mounted_on` DATE NOT NULL, `mounted_km` INT UNSIGNED NULL, `removed_on` DATE NULL, `removed_km` INT UNSIGNED NULL, `actor`, `created_at`, `updated_at`; INDEX (`vehicle_id`, `removed_on`).
- `013_part.sql` — `parts`: `component` VARCHAR(80) NOT NULL, `brand` VARCHAR(60) NOT NULL DEFAULT '', `article_number` VARCHAR(60) NOT NULL DEFAULT '', `oem_number` VARCHAR(60) NULL, `role` ENUM('verbaut','alternative') NOT NULL, `verified` TINYINT(1) NULL, `note` VARCHAR(200) NULL.
- `014_damage.sql` — `damages`: `occurred_on` DATE NOT NULL, `kind` ENUM('unfall','hagel','lack','glas','vandalismus','sonstiges') NOT NULL, `description` VARCHAR(500) NOT NULL, `odometer_km` INT UNSIGNED NULL, `repaired` TINYINT(1) NULL, `repair_event_id` INT UNSIGNED NULL, `appraisal` TINYINT(1) NULL (Gutachten liegt vor), `diminished_value_cents` INT UNSIGNED NULL. `recalls`: `manufacturer_code` VARCHAR(40) NOT NULL DEFAULT '', `kba_reference` VARCHAR(40) NOT NULL DEFAULT '', `title` VARCHAR(200) NOT NULL, `recall_status` ENUM('offen','erledigt','nicht_betroffen') NOT NULL, `done_on` DATE NULL, `event_id` INT UNSIGNED NULL.
- `015_note.sql` — `notes`: `id` PK, `garage_id`, `vehicle_id` FK, `topic` VARCHAR(120) NOT NULL, `status` ENUM('aktiv','geloescht') NOT NULL DEFAULT 'aktiv', `created_at`, `updated_at`; UNIQUE (`vehicle_id`, `topic`). `note_revisions`: `id` PK, `garage_id`, `note_id` FK→notes, `revision` INT UNSIGNED NOT NULL, `body` MEDIUMTEXT NOT NULL, `actor`, `user_id` NULL, `created_at`; UNIQUE (`note_id`, `revision`). Notizen sind Freitext ohne Konfliktregel; der Verlauf ersetzt sie.

`RecordMerger::TABLES` ergänzen:

```php
'measurements' => ['label' => 'Messwert', 'key' => ['measured_on', 'quantity', 'position', 'label'], 'columns' => ['value', 'unit', 'odometer_km', 'note', 'event_id']],
'tire_sets'    => ['label' => 'Reifensatz', 'key' => ['label'], 'columns' => ['brand', 'model', 'size', 'season', 'dot', 'storage_location']],
'parts'        => ['label' => 'Teil', 'key' => ['component', 'brand', 'article_number'], 'columns' => ['oem_number', 'role', 'verified', 'note']],
'damages'      => ['label' => 'Schaden', 'key' => ['occurred_on', 'kind'], 'columns' => ['description', 'odometer_km', 'repaired', 'repair_event_id', 'appraisal', 'diminished_value_cents']],
'recalls'      => ['label' => 'Rückruf', 'key' => ['manufacturer_code', 'kba_reference'], 'columns' => ['title', 'recall_status', 'done_on', 'event_id']],
```

## Kontrakt dieser Phase (Eingaben)

- `record_measurement`: `vehicle_id`, `measured_on`, `odometer_km?`, `event_id?` (muss zum Fahrzeug gehören, sonst `not_found`), `values: [{quantity, position? (Standard gesamt), value (Dezimal, 2 Stellen), label? (Pflicht bei sonstiges, 1–80), unit? (Pflicht bei sonstiges, 1–10)}]` (1–20), Herkunft. Einheit je Größe fest (Konstante `MeasurementService::UNITS`): profiltiefe `mm`, reifendruck `bar`, bremsbelag `mm`, bremsscheibe `mm`, batterie_spannung `V`, batterie_kaltstart `A`, frostschutz `°C`, hu_bremswert `%`. Ausgabe `{measurements:[{quantity, position, measurement_id, result, open_item_ids}], odometer}`.
- `get_measurements`: `vehicle_id`, `quantity?` → `{series: [{quantity, position, label, unit, points: [{date, value, odometer_km, source, confidence}]}]}`.
- `record_tires`: `vehicle_id`, `set: {label (1–80), brand?, model?, size (1–30, z. B. "205/55 R16 91V"), season, dot? (^[0-9]{4}$, Woche 01–53), storage_location?}`, `mount?: {on, km?}`, `unmount?: {on, km?}` (nicht beides), Herkunft. Montieren schließt jede offene Montage eines anderen Satzes dieses Fahrzeugs mit `removed_on = on`, `removed_km = km`. Ausgabe `{tire_set_id, result, open_item_ids, mount: {mount_id, action: montiert|eingelagert|unveraendert} | null}`.
- `get_tires`: `vehicle_id` → `{mounted_set_id, sets: [{id, label, brand, model, size, season, dot, dot_age_years, storage_location, mounts: [{mounted_on, mounted_km, removed_on, removed_km}], source, confidence}]}`.
- `add_part`: `vehicle_id`, `component` (1–80), `brand?`, `article_number?`, `oem_number?`, `role` (`verbaut`|`alternative`), `verified?` (bool), `note?`, Herkunft. Ausgabe `{part_id, result, open_item_ids}`. `get_parts`: `{components: [{component, parts: [...]}]}`.
- `record_damage`: `vehicle_id`, `occurred_on`, `kind`, `description` (1–500), `odometer_km?`, `repaired?`, `repair_event_id?`, `appraisal?`, `diminished_value_eur?`, Herkunft.
- `record_recall`: `vehicle_id`, `title` (1–200), `manufacturer_code?`, `kba_reference?` (mindestens eins), `status` (`offen`|`erledigt`|`nicht_betroffen`, gespeichert in `recall_status`), `done_on?`, `event_id?`, Herkunft.
- `upsert_note`: `vehicle_id`, `topic` (1–120), `body` (1–20.000 Zeichen Markdown). Ausgabe `{note_id, revision, result}`. Keine Herkunft (Freitext); Akteur steht an der Revision.

## Checkliste

- [x] Migrationen `011`–`015` wie oben.
- [x] `app/src/Domain/MeasurementService.php` (+ Tools `app/src/Mcp/Tools/MeasurementTools.php`): normalisiert `value` per `Validate::decimal(…, 2)`; Odometer über `OdometerService::recordInTransaction`.
- [x] `app/src/Domain/TireService.php` + `TireRepository.php` (Montagen) + `Tools/TireTools.php`; `dot_age_years` = volle Jahre zwischen Montag der DOT-Woche und heute (`SELECT UTC_DATE()`).
- [x] `app/src/Domain/PartService.php` + `Tools/PartTools.php`; leere `brand`/`article_number` als `''` speichern (Schlüssel).
- [x] `app/src/Domain/DamageService.php` + `Tools/DamageTools.php` (beide Tools `record_damage`, `record_recall` in einer Datei; Service hat `recordDamage`, `recordRecall`, `listDamages`, `listRecalls`).
- [x] `app/src/Domain/NoteService.php` + `NoteRepository.php` + `Tools/NoteTools.php`; `listCurrent(int $vehicleId, Actor)` liefert je Thema die neueste Revision (für Phase 6). Audit `note.create`/`note.revise` (after: `revision`, Länge, nicht der ganze Text).
- [x] `get_vehicle` bleibt unverändert (Listen über die eigenen Lese-Tools); `list_damages`/`list_recalls` als Tools entfallen — die Akte (Phase 6) zeigt sie.
- [x] `McpController`: Dienste `measurements`, `tires`, `parts`, `damages`, `notes`; Gruppen „Messwerte und Reifen“, „Teile“, „Schäden und Rückrufe“, „Notizen“.

### Prüfen (auf Strato, nach `deploy.cmd`)

- [x] Alle AK per HTTP gegen `/garage/mcp`, je Schreib-Tool zweiter identischer Aufruf (84 Prüfungen, alle ok).

### Doku

- [x] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (neue Domänen), `docs/glossary.md` (Reifensatz, Montage, DOT, Rückruf, Notiz-Revision).

## Report-Back

**Ergebnis:** Phase 4 ist umgesetzt und auf Strato deployt (Migrationen `011`–`015` angewandt, Rauchtest grün). Neun Tools: `record_measurement`, `get_measurements`, `record_tires`, `get_tires`, `add_part`, `get_parts`, `record_damage`, `record_recall`, `upsert_note`. Alle AK per HTTP geprüft (84 Prüfungen, 0 Fehler), jedes Schreib-Tool doppelt gesendet, das zweite Ergebnis war `unveraendert` (Dezimal `"6,5"`, `6`, `5.75` eingeschlossen).

**Dateien:** neu `app/migrations/011`–`015`, `Domain/MeasurementService`, `TireService`, `TireRepository`, `PartService`, `DamageService`, `NoteService`, `NoteRepository`, `EventLink`, `Mcp/Tools/MeasurementTools`, `TireTools`, `PartTools`, `DamageTools`, `NoteTools`; geändert `RecordMerger::TABLES`, `RecordRepository::ORDER`, `EventRepository` (`belongsToVehicle`), `Validate::decimal` (Option negativ), `McpController`, `mcp/instructions.md` (Regel 7/8). Doku: `models.md`, `routes.md`, `code-map.md`, `glossary.md`.

**Abweichungen von der Phasen-Datei:**
- `Validate::decimal` bekommt `$allowNegative`; Messwerte nehmen negative Werte (Frostschutz −25 °C), sonst wäre die Größe unbrauchbar. Betrag höchstens 99.999,99 (Spalte DECIMAL(10,2)).
- `label` an einer Größe außer `sonstiges` und eine abweichende `unit` werden abgewiesen (`invalid_value`) statt verworfen — „32 psi“ würde sonst als 32 bar stehen.
- `unmount` am selben Tag wiederholt → `unveraendert` (sonst wäre der doppelte Aufruf ein Fehler); `mount` und `unmount` zusammen → `invalid_argument`; ein schon montierter Satz mit anderem Montagedatum → `invalid_value` (erst ausbauen).
- `mounted_km`/`removed_km`, `odometer_km` am Schaden sind Angaben an der Zeile und legen keinen Kilometerstand an; nur `record_measurement` mit `odometer_km` geht über `OdometerService`.
- `EventLink` (neu) prüft `event_id`/`repair_event_id`, dass das Ereignis zum Fahrzeug gehört (sonst `not_found`).
- `RecordRepository::ORDER` reicht für alle fünf neuen Tabellen (Messwerte aufsteigend nach Datum je Reihe; Teile: `role` als ENUM ordnet verbaut vor alternative).

**🟡 Nur im Code belegt:** Revision 1 einer Notiz bleibt nach Revision 2 (kein Lese-Tool; die Akte in Phase 6 zeigt sie); Audit-Einträge `measurements.*`, `tire_mount.*`, `note.create|revise` (kein Lese-Zugang von außen, Phase 5). Testkonten dieser Phase: `test-m2-p4a|p4b|p4ro-1794912532019@example.com` (Garagen „Test A P4 …“, „Test B P4 …“); sie passen auf das Muster `test-%@example.com`.
