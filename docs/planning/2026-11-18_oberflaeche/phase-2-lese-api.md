# Phase 2 — Lese-API der Akte

Rating: standard · Modell: sonnet

## Kontext

- README → „Kontrakt“, Tabelle „Lesen“ (verbindlich: Pfade, Antworten)
- `docs/decisions/018-oberflaeche-seiten-und-lese-api.md`
- `docs/routes.md` (Format der Tabelle, MCP-Tool-Ausgaben)
- `app/index.php` (Routen registrieren), `app/src/Api/VehicleController.php`, `app/src/Api/OpenItemController.php` (Muster: `ApiAuth::user`, Service, `try/catch DomainError` → `ApiErrors::respond`)
- `app/src/Mcp/McpController.php` (welche Services es gibt) und die Tools, deren Ausgabe gespiegelt wird: `Mcp/Tools/VehicleTools.php` (`get_vehicle` hängt `documents` an und castet `facts`/`specs` zu Objekten), `EventTools.php` (`list_events`, `get_event` inkl. `documents`), `TaskTools.php` (`get_due_items`), `MeasurementTools.php`, `TireTools.php`, `PartTools.php`, `DossierTools.php`
- `app/src/Domain/DueService.php` (`dueItems`), `VehicleRepository.php` (`listForActor`, `STATUS_DELETED`), `VehicleAccess.php`, `GarageRepository.php` (Rolle des Nutzers je Garage), `DamageService.php` (`listDamages`, `listRecalls`), `NoteService.php` (`listCurrent`)
- `app/src/Http/Response.php` (für die Markdown-Antwort: gibt es eine Text-Methode? sonst `Response::text(string $body, string $contentType, array $headers)` ergänzen)

## AK

- [ ] Jede Route aus „Lesen“ ist in `app/index.php` registriert und antwortet wie im Kontrakt; die Antwort ist byte-gleich zur Tool-Ausgabe desselben Services (gleiche Felder, gleiche Typen, `facts`/`specs` als Objekt).
- [ ] Neue Controller nach Namensschema: `Api/VehicleController` bekommt `show`, `due`, `odometer` (über `OdometerRepository::validReadings`; liefert es nicht alle Kontrakt-Felder, eine Lesemethode `listValid` mit diesen Spalten ergänzen), `measurements`, `tires`, `parts`, `notes`, `damages`, `dossier`, `deleted` (Query `deleted=1` an `list`); `Api/EventController` (neu) bekommt `listForVehicle` und `show`; `Api/DueController` (neu) bekommt `list` (Route `GET /garage/api/due`).
- [ ] `permissions` in `GET vehicles/{id}`: aus der Rolle des Nutzers in `vehicle.garage_id` (`GarageRepository`): `write` = `inhaber`\|`mitglied`, `owner` = `inhaber`.
- [ ] `GET vehicles/{id}/events` liefert `gaps: []` (Phase 3 füllt es).
- [ ] `DueService::dueItems` mit `vehicleId === null` nimmt nur Fahrzeuge mit Status `aktiv` (verkauft/stillgelegt fallen heraus). Folge auch für MCP `get_due_items` ohne `vehicle_id` und die Resource `garage://fahrzeug/{id}/faelligkeiten` (bleibt mit ID unverändert) — in `routes.md` beim Tool vermerken.
- [ ] `GET vehicles?deleted=1`: neue Methode `VehicleRepository::listDeleted(Actor $actor, int $days)` — Fahrzeuge mit Status `geloescht` in Garagen, in denen der Nutzer `inhaber` ist, `updated_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY`; `deleted_at` = `updated_at` (ISO 8601 UTC), `restorable_until` = `updated_at` + 30 Tage.
- [ ] Mandantentrennung durchgespielt (Liste im Report-Back): Nutzer B ruft jede neue Route mit einer Fahrzeug-/Ereignis-ID von Nutzer A → 404 `not_found`; ohne JWT → 401; mit `gmcp_`-Token → 401.

## Checkliste

- [ ] Controller und Routen
- [ ] `DueService`-Filter auf `aktiv`
- [ ] `VehicleRepository::listDeleted`
- [ ] Ggf. `Response::text`
- [ ] Lokal jede Route per `curl` mit JWT aufgerufen (Statuscode und Auszug der Antwort im Report-Back)
- [ ] Doc-Update: `docs/routes.md` (alle Routen dieser Phase), `docs/code-map.md` (`Api/EventController`, `Api/DueController`)

## Report-Back
