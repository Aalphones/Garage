# Phase 3 — Wartungsplan und Fälligkeiten

Rating: **heikel** (Rechenregel „was zuerst eintritt“, Datumsarithmetik, Prognose). Status: **complete**.

## Kontext — vorher lesen

- [`README.md`](README.md) → E6, Kontrakt (Klärfall-Vorschlag `task_rules`, Tools `upsert_task_rule`, `get_due_items`, `list_vehicles` erweitert).
- [`phase-2-scheckheft-kilometer.md`](phase-2-scheckheft-kilometer.md) → `event_items.task_key`, `OdometerService::validReadings`, ADR-011 (Datei anlegen ist dort passiert).
- `docs/design/konzept-garage.md` → Kurzfassung Punkt 3, „Web-Oberfläche“ (Farbregeln: Rot überfällig, Gelb unsicher oder unter 3 Monaten / 3.000 km), „Bestandsaufnahme“ (Clio-Innenraumfilter, Golf-DSG).
- `docs/conventions/php.md` → berechnete Werte nie speichern; Zeit aus der Datenbank.
- Fehlerklassen: keine im Vault; bekannte PHP-Falle hier als AK: `modify('+1 month')` springt am Monatsende in den Folgemonat.

## Abnahmekriterien

- [x] `DueCalculator::addMonths('2026-01-31', 1)` = `2026-02-28`, `addMonths('2024-01-31', 1)` = `2024-02-29`, `addMonths('2026-03-02', 12)` = `2027-03-02` — lokal per `php -r` mit `require 'app/src/Domain/DueCalculator.php'` geprüft (reine Klasse, keine Datenbank).
- [x] `OdometerForecast`: gültige Stände 20.000 km am 2025-11-17 und 26.000 km am 2026-05-17, heute 2026-11-17 → `km_estimate` 32.099, `per_day` 33,1 (in der Ausgabe auf eine Stelle gerundet, gerechnet wird ungerundet; Werte per PHP nachgerechnet), `estimated: true`; nur ein Stand → `km_estimate` = dieser Stand, `estimated: false`, `per_day: null`.
- [x] `upsert_task_rule` neu → `angelegt` (legt Aufgabe und Regel an); identisch erneut → `unveraendert`; andere Werte gegen nicht bestätigte → `aktualisiert` (alte Regel `abgeloest`); andere Werte gegen bestätigte → `klaerfall` mit `proposal_json` der neuen Regel; Regel ohne `interval_km` und ohne `interval_months` → `invalid_value`.
- [x] Clio-Fall: Regel `innenraumfilter` 12 Monate (bestätigt), Ereignis 2025-01-15 mit Position `task_key: innenraumfilter` → `get_due_items` zeigt `ueberfaellig`, `due_date` 2026-01-15 und einen Rechenweg, der Ereignis-Datum, Intervall und Ergebnis nennt.
- [x] Regel 15.000 km / 24 Monate, letzte Ausführung bei 26.000 km am 2026-05-17, Prognose wie oben → `due_km` 41.000, `due_date` 2028-05-17, `km_due_date` 2027-08-13 (453 Tage ab 2026-05-17 bei 33,149 km/Tag, nachgerechnet), `effective_date` 2027-08-13, Status `ok`.
- [x] Regel mit Konfidenz `unsicher` → `rule_uncertain: true` (Gelb im Konzept), unabhängig vom Status.
- [x] Aufgabe ohne Ausführung und ohne Erstzulassung → `due_date: null`, Status `unbekannt`, Rechenweg sagt, was fehlt („Erstzulassung fehlt — set_vehicle_facts mit erstzulassung“).
- [x] `list_vehicles` liefert je Fahrzeug `km_estimate` und `next_due`; `get_vehicle` liefert `km_estimate` mit Grundlage.
- [x] `record_service_event` mit `task_key`, für den es keine Aufgabe gibt → Warnung „task_key ‚x‘ hat noch keine Intervallregel (upsert_task_rule)“, Ereignis trotzdem gespeichert.
- [x] Mandanten: `get_due_items` ohne `vehicle_id` liefert nur Fahrzeuge der eigenen Garagen; `upsert_task_rule` an fremdem Fahrzeug → `not_found`.

## Datenmodell — `app/migrations/010_task.sql`

- `tasks`: `id` PK, `garage_id`, `vehicle_id` FK→vehicles, `task_key` VARCHAR(41) NOT NULL, `title` VARCHAR(120) NOT NULL, `status` ENUM('aktiv','inaktiv') NOT NULL DEFAULT 'aktiv', `actor`, `created_at`, `updated_at`; UNIQUE (`vehicle_id`, `task_key`).
- `task_rules`: `id` PK, `garage_id`, `task_id` FK→tasks, `interval_km` INT UNSIGNED NULL, `interval_months` SMALLINT UNSIGNED NULL, `first_km` INT UNSIGNED NULL, `first_months` SMALLINT UNSIGNED NULL, `mode` ENUM('fest','flexibel') NOT NULL DEFAULT 'fest', `valid_from` DATE NOT NULL, `rule_status` ENUM('aktuell','abgeloest') NOT NULL, `source`, `source_url`, `source_document_id`, `confidence` (wie Herkunftsblock), `actor`, `user_id`, `created_at`; INDEX (`task_id`, `rule_status`).

## Kontrakt dieser Phase

`upsert_task_rule` Eingabe: `vehicle_id`, `task_key` (`^[a-z][a-z0-9_]{1,40}$`), `title` (1–120, Pflicht beim Anlegen), `interval_km?` (1–1.000.000), `interval_months?` (1–240), `first_km?`, `first_months?`, `mode?` (Standard `fest`), `valid_from?` (Standard heute UTC), Herkunft. Ausgabe `{task_id, rule_id, result, open_item_id?}`.

`get_due_items` Ausgabe:

```
{today: "2026-11-17", thresholds: {months: 3, km: 3000},
 items: [{vehicle_id, vehicle_label, task_key, title,
          rule: {interval_km, interval_months, first_km, first_months, mode, confidence, source, source_url},
          last_done: {event_id, date, km} | null,
          due_date, due_km, km_due_date, effective_date,
          km_estimate, status: ueberfaellig|bald|ok|unbekannt, rule_uncertain, explanation}]}
```

Sortierung: `ueberfaellig`, `bald`, `unbekannt`, `ok`; innerhalb nach `effective_date` (null zuletzt).

## Rechenregel (ADR-011)

1. **Heute** = `SELECT UTC_DATE()` einmal je Aufruf in `DueService`, als Text an die reinen Klassen.
2. **Prognose** (`OdometerForecast::compute(list $readings, ?string $firstRegistration, string $today)`): `L` = letzter gültiger Stand (nach `read_on`, dann `km`). `E` = frühester Stand mit `read_on` zwischen `L − 365 Tage` und `L − 30 Tage`; gibt es keinen, der früheste Stand mit `read_on ≤ L − 30 Tage`; gibt es keinen und ist die Erstzulassung ≤ `L − 30 Tage`, dann `(Erstzulassung, 0 km)`. `per_day = (L.km − E.km) / Tage(E→L)`. `km_estimate = round(L.km + per_day × Tage(L→heute))`. Ohne `E`: `km_estimate = L.km`, `per_day = null`, `estimated = false`. Ohne Stand: `null`.
3. **Basis** je Aufgabe: letzte Ausführung = jüngstes aktives Ereignis mit aktiver Position `task_key` (nach `performed_on`, dann `id`), km = `events.odometer_km` (darf fehlen). Ohne Ausführung: Datum = Erstzulassung (Fakt `erstzulassung`), km = 0, Intervalle = `first_months ?? interval_months` und `first_km ?? interval_km`.
4. `due_date = addMonths(Basisdatum, Monate)` (wenn Monate und Basisdatum da); `due_km = Basis-km + km-Intervall` (wenn km-Intervall und Basis-km da).
5. `km_due_date = L.read_on + ceil((due_km − L.km) / per_day)` Tage, nur wenn `due_km`, `L` und `per_day > 0`.
6. `effective_date` = früheres von `due_date` und `km_due_date` (die vorhandenen).
7. Status: `ueberfaellig`, wenn `effective_date < heute` oder (`due_km` und `km_estimate` da und `km_estimate ≥ due_km`); sonst `bald`, wenn `effective_date ≤ addMonths(heute, 3)` oder (`due_km − km_estimate ≤ 3000`); sonst `ok`, wenn `effective_date` da; sonst `unbekannt`.
8. `addMonths(string $date, int $months): string` — Jahr/Monat rechnen, Tag = min(Tag, letzter Tag des Zielmonats); nie `modify('+N months')`.
9. Rechenweg (`explanation`), deutsch, Datumsformat `TT.MM.JJJJ`, Tausenderpunkt: „Zuletzt am 02.03.2026 bei 26.921 km (Ereignis 14). + 12 Monate = 02.03.2027. + 15.000 km = 41.921 km, bei ≈ 33,1 km/Tag am ≈ 29.05.2027. Was zuerst eintritt: 02.03.2027.“ Bei `mode = flexibel` angehängt: „Flexibel nach Bordanzeige: das sind Höchstwerte.“ Bei unsicherer Regel: „Regel unbelegt (unsicher).“

## Checkliste

### Fachlogik

- [x] `app/src/Domain/OdometerForecast.php` (reine Klasse, nur `DateTimeImmutable`), Rückgabe `array{km_estimate: int, per_day: float|null, estimated: bool, basis: array{from: array{date,km}|null, to: array{date,km}}}|null`.
- [x] `app/src/Domain/DueCalculator.php` (reine Klasse): `addMonths`, `compute(array $task, ?array $rule, ?array $lastDone, ?string $firstRegistration, ?array $forecast, ?array $lastReading, string $today): array` nach Rechenregel 3–9; Konstanten `SOON_MONTHS = 3`, `SOON_KM = 3000`.
- [x] `app/src/Domain/TaskRepository.php`: `findTask(vehicleId, taskKey, Actor)`, `insertTask`, `currentRule(taskId, Actor)`, `insertRule`, `supersedeRule`, `listTasksWithRules(list<int> $vehicleIds, Actor)`, `lastExecutions(list<int> $vehicleIds, Actor): array<int vehicle_id, array<string task_key, array{event_id, date, km}>>` (eine Abfrage über `events` ⋈ `event_items`, aktiv, je Fahrzeug und `task_key` das jüngste).
- [x] `app/src/Domain/TaskService.php`: `upsertRule(array $input, Actor)` — Regel aus Kontrakt; Vergleich über `interval_km`, `interval_months`, `first_km`, `first_months`, `mode` (als Text, `null` = leer); gleich → `unveraendert` (höhere Konfidenz → neue Zeile `aktuell`, alte `abgeloest`, `aktualisiert`); `title` abweichend → Titel überschreiben (kein Klärfall). Konflikt-Text: „Intervallregel ‚<task_key>‘ am Fahrzeug ‚<label>‘: bestätigt ist <alt>, neu gemeldet <neu> (Quelle …). Ein Mensch entscheidet.“ Audit `task_rule.create|update|dispute`.
- [x] `app/src/Domain/DueService.php`: `dueItems(?int $vehicleId, int $horizonMonths, Actor)` — Fahrzeuge (eins über `VehicleAccess::readable` oder alle über `VehicleRepository::listForActor`, ohne Status `geloescht`), Erstzulassung über `VehicleRepository::currentValues(..., ['erstzulassung'])`, Stände über `OdometerService::validReadings`, Rest über `TaskRepository`; Horizont: Einträge mit `effective_date` null oder ≤ `addMonths(heute, horizon)`. `summary(list<int> $vehicleIds, Actor): array<int, array{km_estimate: int|null, next_due: array|null}>` für `list_vehicles`. `horizon_months` 1–120, Standard 36.
- [x] `VehicleService::list` ergänzt `km_estimate`, `next_due` (`{task_key, title, effective_date, status}` oder `null`) über `DueService::summary`; `VehicleService::get` ergänzt `km_estimate` (Ergebnis von `OdometerForecast`). `VehicleService` bekommt `DueService` im Konstruktor erzeugt (kein Kreis: `DueService` nutzt nur Repositories).
- [x] `EventService::record`: Warnung je `task_key` ohne Aufgabe (nach Schreiben der Positionen prüfen).

### Tools

- [x] `app/src/Mcp/Tools/TaskTools.php`: `upsert_task_rule` (write; Beispiel Ölwechsel 15.000 km / 24 Monate flexibel, Quelle `werkstatt`), `get_due_items` (read; Beschreibung nennt Status-Werte und „rule_uncertain = Regel ohne Beleg“).
- [x] `McpController`: Dienste `tasks`, `due`; Gruppe „Wartungsplan“.

### Prüfen

- [x] `php -r` lokal für `addMonths` und `OdometerForecast` (AK 1, 2) — ohne Datenbank.
- [x] Auf Strato per `curl` die übrigen AK; Fälle mit festen Daten anlegen (Stände 2025-11-17 und 2026-05-17 usw.), damit die Zahlen aus den AK herauskommen; `today` steht in der Antwort.

### Doku

- [x] ADR-011 ergänzen: Rechenregel 1–9 in Kurzform, Schwellen als Konstanten bis M4 (verworfen: Fälligkeit speichern — veraltet bei jedem neuen Stand; lineare Regression über alle Stände — zu empfindlich bei wenigen Werten).
- [x] `docs/models.md`, `docs/routes.md`, `docs/code-map.md` (`Task*`, `Due*`, `OdometerForecast`), `docs/glossary.md` (Basis, effektives Fälligkeitsdatum, km-Fälligkeitsdatum).

## Report-Back

**Ergebnis:** Phase 3 ist umgesetzt und auf Strato deployt (Migration `010_task.sql` angewandt, Rauchtest grün). AK 1, 2, 5 (Rechenbeispiel mit den festen Zahlen), 6 und 7 lokal ohne Datenbank per PHP nachgerechnet; alle AK zusätzlich per HTTP gegen `/garage/mcp` geprüft (34 Prüfungen, 33 ok; die eine rote Zeile war ein Zählfehler im Prüfskript, kein Fehler im Code).

**Dateien:** neu `app/migrations/010_task.sql`, `Domain/OdometerForecast`, `DueCalculator`, `TaskRepository`, `TaskService`, `DueService`, `Mcp/Tools/TaskTools`; geändert `VehicleService` (`list`/`get`), `EventService` (Warnung bei `task_key` ohne Regel; Muster `TaskService::KEY_PATTERN`), `McpController`, `VehicleTools` (Beschreibungen), `mcp/instructions.md` (Regel 6). Doku: ADR-011 Teil 2, `models.md`, `routes.md`, `code-map.md`, `glossary.md`.

**Abweichungen von der Phasen-Datei:**
- `DueCalculator::compute` bekommt `(rule, lastDone, firstRegistration, forecast, today)` statt mit Aufgabe und letztem Stand; die Prognose trägt den letzten Stand selbst (`basis.to`).
- `DueService::summary` nimmt die Fahrzeugliste `[{id,label}]` statt nur IDs, damit `list_vehicles` ohne zweite Abfrage auskommt.
- Für Prüfskripte: Die Rolle des Eigentümers heißt `inhaber`, die Token-Scopes `garage:read`/`garage:write`.

**🟡 Nur im Code belegt:** Audit-Einträge `task_rule.create|update|dispute` und `proposal_json` des Regel-Klärfalls (kein Lese-Zugang von außen, Phase 5); `valid_from` wirkt bisher nur als Datum der Regel, die Rechnung nutzt es nicht.
