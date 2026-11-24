# Phase 2 — Verträge, Vorgänge, Plaketten, Zubehör

Rating: heikel · Modell: opus (Garagen-Verträge ohne Fahrzeug: Mandantentrennung und Abgleich außerhalb von `RecordMerger`)

## Kontext

- Plan-README → Kontrakt (Werkzeuge `upsert_contract`, `record_contract_term`, `record_contract_event`, `upsert_accessory`, `upsert_permit`, `get_papers`; Lösch-Typen)
- ADR-022 (`ContractMerger`), ADR-025 (Löschen), ADR-009, ADR-010, ADR-019
- Muster Fahrzeug-Datensatz: `app/src/Domain/PartService.php` + `app/src/Mcp/Tools/PartTools.php`; Abgleich: `app/src/Domain/RecordMerger.php` (`TABLES`, `merge`, Ergebniswörter `RESULT_*`, Klärfall über `OpenItemRepository`)
- Zugriff: `app/src/Domain/VehicleAccess.php` (`readable`, `writable`, `assertCanWriteGarage`), `app/src/Domain/Actor.php` (`canRead`, `roles`)
- Löschen: `app/src/Domain/LifecycleService.php` (`RECORD_TABLES`, `lockedRecord`, `loadRecord`, `keyColumns`), `app/src/Domain/RecordRepository.php` (`findAnyStatus`, `hasActiveDuplicate`, `listForVehicle`)
- Anhänge: `app/src/Domain/DocumentService.php` → `TARGETS`; Werkzeug-Verdrahtung: `app/src/Mcp/McpController.php` (`services`, `registry`), Gruppen-Namen dort
- Geld: `app/src/Domain/Money.php`; Eingaben: `app/src/Domain/Validate.php`
- Fehlerklassen: keine einschlägig

## AK

- [ ] `upsert_contract` legt einen Fahrzeug-Vertrag (`vehicle_id`) oder Garagen-Vertrag (`garage_id`, Rolle `inhaber`/`mitglied` in dieser Garage) an; beides zugleich oder keins → 422 mit Beispiel. Schlüssel: `kind`, `provider`, `contract_number` (leer = `''`) innerhalb Fahrzeug bzw. Garage. Zweiter gleicher Aufruf → `unveraendert`; neue Felder → `ergaenzt`; abweichender Wert gegen einen bestätigten → `klaerfall` (Klärfall mit `ref_table = 'contracts'`, `vehicle_id` des Vertrags oder `NULL`).
- [ ] `allocation` nur bei Garagen-Verträgen (sonst 422), Standard `gleich`; `main_due` nur `MM-TT` mit gültigem Tag.
- [ ] `record_contract_term`: Schlüssel `contract_id` + `valid_from`; `valid_to` ≥ `valid_from`; `amount_eur` als Euro-Text oder Zahl → Cent. Bei Garagen-Verträgen mit `allocation = fest` nimmt `record_contract_term` zusätzlich `allocations: [{vehicle_id, share_percent}]` an und schreibt `contract_allocations` mit `valid_from` der Periode (Summe ≠ 100 → 422).
- [ ] `record_contract_event`: Schlüssel `contract_id` + `kind` + `occurred_on`; `damage_id` muss ein Schaden desselben Fahrzeugs sein (sonst 422).
- [ ] `upsert_accessory` (Schlüssel `name`) und `upsert_permit` (Schlüssel `kind` + `label`) über `RecordMerger::TABLES`.
- [ ] `get_papers` liefert für ein Fahrzeug: seine Verträge plus die Garagen-Verträge seiner Garage (je mit `level: fahrzeug|garage`, `terms` absteigend, `events` absteigend, bei Garagen-Verträgen `share` = Anteil dieses Fahrzeugs heute in Prozent und Euro/Jahr), `permits`, `accessories`. `deadlines` und `emergency` liefert Phase 6 — bis dahin leere Liste bzw. `null`.
- [ ] Lesen und Schreiben fremder Fahrzeuge/Garagen/Verträge → `not_found`; Rolle `lesend` darf lesen, nicht schreiben (`forbidden`).
- [ ] Menschen löschen/wiederherstellen per `records/{type}/{id}/…` die Typen `contract`, `contract_event`, `accessory`, `permit`; ein Garagen-Vertrag braucht Schreibrecht in der Garage. Agenten-Tokens weiterhin `forbidden`.
- [ ] `attach_document` kennt `vertrag`, `vorgang`, `zubehoer`, `plakette`.

## Checkliste

- [ ] `app/src/Domain/ContractMerger.php`: `KEYS` je Tabelle (`contracts` → `[vehicle_id, kind, provider, contract_number]` mit `vehicle_id IS NULL` für Garage; `contract_terms` → `[contract_id, valid_from]`; `contract_allocations` → `[contract_id, vehicle_id, valid_from]`; `contract_events` → `[contract_id, kind, occurred_on]`), `COLUMNS` je Tabelle (alle übrigen fachlichen Spalten), `merge(string $table, array $values, array $provenance, array $scope, Actor $actor): array{id,result,open_item_ids}` mit `$scope = ['garage_id' => int, 'vehicle_id' => ?int]`. Regeln 1:1 wie `RecordMerger::merge` (vorher lesen und Ablauf übernehmen: suchen → anlegen / unverändert / ergänzen leerer Felder / abweichend: Konfidenz höher oder bisher nicht bestätigt → überschreiben, sonst Klärfall). Audit `contract.create|update` mit vorher/nachher.
- [ ] `app/src/Domain/ContractRepository.php`: Lesen der Verträge eines Fahrzeugs inkl. Garagen-Verträge, Perioden, Vorgänge, Anteile; jede Abfrage mit `garage_id IN (Akteur)`.
- [ ] `app/src/Domain/ContractService.php`: `upsert`, `recordTerm`, `recordEvent`, `forVehicle` (für `get_papers`), `shareFor(contract, vehicleId, date)` (gleich: 1 ÷ aktive Autos; fest: Anteil aus `contract_allocations`; km: `null` — rechnet Phase 5). „Aktive Autos“ hier: Status `aktiv` heute (genaue Zeiträume rechnet Phase 5).
- [ ] `app/src/Domain/PaperService.php`: `forVehicle` setzt `get_papers` zusammen (Verträge über `ContractService`, Plaketten und Zubehör über `RecordRepository::listForVehicle`).
- [ ] `AccessoryService`, `PermitService` nach Muster `PartService` (Werte validieren, `RecordMerger::merge`); `RecordMerger::TABLES` um `accessories` (`key: [name]`) und `permits` (`key: [kind, label]`) mit ihren Spalten erweitern.
- [ ] `app/src/Mcp/Tools/ContractTools.php` (`upsert_contract`, `record_contract_term`, `record_contract_event`), `app/src/Mcp/Tools/PaperTools.php` (`upsert_accessory`, `upsert_permit`, `get_papers`); Beschreibungen mit Beispiel-JSON wie `add_part`; Gruppe `Verträge und Papiere` in `McpController::registry` nach „Schäden und Rückrufe“; Dienste in `McpController` (Liste der `new …Service($pdo)`) eintragen.
- [ ] `LifecycleService`: `RECORD_TABLES` + `contract`, `contract_event`, `accessory`, `permit`. **Erst prüfen** (Konfidenz-Ausweis): `RecordRepository::findAnyStatus` für eine Zeile ohne `vehicle_id` — `contract_events` haben keins, `contracts` evtl. `NULL`. `loadRecord` für `contracts`/`contract_events` über `ContractRepository::findAnyStatus` (liefert `garage_id`, `vehicle_id` des Vertrags oder `NULL`); `lockedRecord`: `vehicle_id === null` → `VehicleAccess::assertCanWriteGarage(garage_id)`, sonst wie bisher. `keyColumns` für Vertragstabellen aus `ContractMerger::KEYS`.
- [ ] `DocumentService::TARGETS` + `vertrag` → `contracts`, `vorgang` → `contract_events`, `zubehoer` → `accessories`, `plakette` → `permits`; prüfen, wie `attach` das Fahrzeug des Ziels findet, und für Verträge ohne `vehicle_id` den Garagen-Zweig ergänzen (Rolle in `garage_id` des Vertrags).
- [ ] Audit: jede Änderung mit `AuditRepository::record` wie bei `add_part`.
- [ ] Doku: `docs/routes.md` (Lösch-Typen), `docs/code-map.md` (neue Dateien im Absatz `src/Domain/` und `src/Mcp/`).
- [ ] Syntax: CI-Lauf nach dem Push ansehen.

## Report-Back
