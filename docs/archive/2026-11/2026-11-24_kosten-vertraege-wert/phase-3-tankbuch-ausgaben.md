# Phase 3 — Tankbuch und Ausgaben

Rating: standard · Modell: sonnet

## Kontext

- Plan-README → Kontrakt (`record_fuel`, `record_expense`, Routen `GET/POST vehicles/{id}/fuel`, `GET/POST vehicles/{id}/expenses`, Lösch-Typen `fuel`, `expense`)
- ADR-021, ADR-025
- Muster Datensatz: `app/src/Domain/PartService.php`, `app/src/Mcp/Tools/PartTools.php`; Abgleich `app/src/Domain/RecordMerger.php` (`TABLES`)
- Kilometerstand in derselben Transaktion: `app/src/Domain/OdometerService.php` → `recordInTransaction` (Aufrufer hat das Fahrzeug über `VehicleAccess::writable` gesperrt), Muster: `EventService` ruft es genauso
- Menschliche Eingabe: `OdometerService::recordByHuman` (Datum nicht in der Zukunft, `Provenance::BY_HUMAN`), `app/src/Api/VehicleController.php` → `addOdometer` (`respondJson`)
- Routen: `app/index.php`; Löschen: `app/src/Domain/LifecycleService.php` → `RECORD_TABLES`; Anhänge: `DocumentService::TARGETS`
- Fehlerklassen: keine einschlägig

## AK

- [x] `record_fuel`: Schlüssel `fueled_on` + `odometer_km`; `liters` 0,5–200 (zwei Nachkommastellen), `amount_eur` 0,01–1.000; schreibt in derselben Transaktion einen Kilometerstand (`recordInTransaction`, Notiz „Tankung“) und speichert dessen `reading_id` in `fuel_logs.odometer_reading_id`; Antwort enthält `odometer` (Ergebnis des Kilometerstands, `klaerfall` bei Rückwärts-Stand) und `consumption_l_100km` (oder `null`).
- [x] Verbrauch: für eine Volltankung = Summe der Liter aller Tankungen nach der vorherigen Volltankung bis einschließlich dieser ÷ km-Differenz × 100, auf eine Nachkommastelle; ohne vorherige Volltankung oder bei `is_full = false` → `null`. Gerechnet beim Lesen (`FuelService::list`), nie gespeichert.
- [x] `record_expense`: Schlüssel `spent_on` + `category` + `amount_cents`; `spread_months` 1–36.
- [x] `POST vehicles/{id}/fuel` und `POST vehicles/{id}/expenses`: Rolle `inhaber`/`mitglied`, Herkunft `nutzer`/`bestaetigt`, Datum nicht in der Zukunft; `lesend` → 403, fremd → 404, ungültig → 422 mit Feld und Beispiel.
- [x] `GET vehicles/{id}/fuel` (absteigend nach Datum, dann km, mit `consumption_l_100km`, `price_per_liter` als Text mit drei Nachkommastellen) und `GET vehicles/{id}/expenses` (absteigend).
- [x] Lösch-Typen `fuel`, `expense`; eine gelöschte Tankung nimmt ihren Kilometerstand mit (wie Ereignis → `OdometerRepository::setStatusForEvent`-Muster, hier über `odometer_reading_id`), Wiederherstellen bringt ihn zurück.
- [x] `attach_document` kennt `tankvorgang` → `fuel_logs`, `ausgabe` → `expenses`.

## Checkliste

- [x] `RecordMerger::TABLES` + `fuel_logs` (`key: [fueled_on, odometer_km]`, Spalten `liters, amount_cents, is_full, station, odometer_reading_id`), `expenses` (`key: [spent_on, category, amount_cents]`, Spalten `spread_months, note`).
- [x] `app/src/Domain/FuelService.php`: `record`, `recordByHuman`, `list`; `app/src/Domain/ExpenseService.php`: `record`, `recordByHuman`, `list`. Kategorien als `ExpenseService::CATEGORIES`.
- [x] `app/src/Mcp/Tools/CostTools.php` mit `record_fuel`, `record_expense` (Gruppe `Kosten` in `McpController::registry` nach `Verträge und Papiere`; `get_cost_summary` folgt in Phase 5 in dieselbe Datei).
- [x] `app/src/Api/CostController.php`: `fuel`, `addFuel`, `expenses`, `addExpense` (Muster `VehicleController::addOdometer`); Routen in `app/index.php`.
- [x] `LifecycleService`: `RECORD_TABLES` + `fuel`, `expense`; Lösch-/Wiederherstell-Zweig für `fuel_logs` setzt den Status des verknüpften Kilometerstands mit (Methode in `OdometerRepository` analog `setStatusForEvent`, Schlüssel `id`).
- [x] `DocumentService::TARGETS` + `tankvorgang`, `ausgabe`.
- [x] Doku: `docs/routes.md`, `docs/code-map.md`.
- [ ] Syntax: CI-Lauf nach dem Push ansehen (kein PHP lokal).

## Report-Back

Status: complete. Abweichungen: `record_fuel` und die Eingabe-Route liefern zusätzlich `open_item_ids` (Widerspruch zu einem bestätigten Wert in der Tankung selbst, wie bei den übrigen Werkzeugen). `spread_months` ohne Angabe wird nicht gespeichert-verglichen (Datenbank-Standard 1), damit ein Wiederholungsaufruf einen gespeicherten Wert nicht überschreibt. Der Kilometerstand einer Tankung folgt dem Löschen nur, wenn er weder zu einem Ereignis noch zu einer anderen aktiven Tankung gehört (`OdometerRepository::setStatusForFuel`). Neu: `Validate::dateNotInFuture`. PHP-Syntax lokal ungeprüft — CI-Lauf ansehen.
