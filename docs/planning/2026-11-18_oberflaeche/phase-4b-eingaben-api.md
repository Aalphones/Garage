# Phase 4b — Schreib-API: kleine Eingaben durch Menschen

Rating: heikel (Konfliktregel wird für Menschen bewusst umgangen, Mandantentrennung) · Modell: opus · nach Phase 4

## Kontext

- README → „Kontrakt“, Tabelle „Kleine Eingaben“ (verbindlich)
- `docs/decisions/021-kleine-eingaben-durch-menschen.md`, `009-datensatz-abgleich-und-spezifikationen-als-fakten.md`, `011-faelligkeit-und-kilometer.md`, `013-belegbezug-ueber-anhaenge.md`
- `app/src/Domain/VehicleService.php` (`FIELDS`, `validFact`/`validValue`, `applyFact`, `writeCurrent`, `currentFact`), `VehicleRepository.php` (`supersede`, Fakt-Status), `OdometerService.php`, `NoteService.php` (`upsert`), `DocumentService.php` (`attach`, `TARGETS`), `VehicleAccess.php`, `Actor.php`
- Muster der Controller und Fehler: `app/src/Api/OpenItemController.php`, `ApiErrors.php`; Phase 4 (`LifecycleService`, Rollenprüfung)

## AK

- [ ] `POST vehicles/{id}/odometer` → `OdometerService` wie `record_odometer` mit `source: nutzer`, `confidence: bestaetigt`; Antwort wie das Tool (`result` kann `klaerfall` sein).
- [ ] `PATCH vehicles/{id}/facts` → neue Methode `VehicleService::correctByHuman(int $vehicleId, string $field, string $value, Actor $actor)`: Feld aus `FIELDS`, Wert über dieselbe Prüfung wie `set_vehicle_facts`; offener Klärfall am Feld (`disputed`) → `DomainError` 409 `open_item_pending`; sonst `writeCurrent` mit Herkunft `nutzer`/`bestaetigt` und der bisherigen Zeile als `previous` (auch wenn bestätigt). Nur `Actor::VIA_WEB`.
- [ ] `PUT vehicles/{id}/notes` → `NoteService::upsert` (Thema 1–120, Text 1–20.000); Antwort wie `upsert_note`.
- [ ] `POST vehicles/{id}/cover` → `DocumentService::attach(document_id, 'fahrzeug', vehicleId, 'titelbild')`; Dokument kein Foto oder anderes Fahrzeug → 422 `invalid_value` bzw. 404.
- [ ] Rollen: `inhaber`/`mitglied`; `lesend` → 403; fremdes Fahrzeug → 404.
- [ ] Durchgespielte Fälle im Report-Back: bestätigte FIN per Browser korrigiert → neuer Wert aktuell, alter abgelöst, Audit; strittiges Feld → 409; Rückwärts-km → `klaerfall`; Notiz mit vorhandenem Thema → neue Revision; Titelbild mit PDF → 422; lesend → 403; Nutzer B auf Fahrzeug A → 404; MCP `get_vehicle` zeigt danach `source: nutzer`.

## Checkliste

- [ ] `VehicleService::correctByHuman`; `Api/VehicleController` (`addOdometer`, `correctFact`, `upsertNote`, `setCover`); Routen; `ApiErrors` (`open_item_pending` → 409)
- [ ] Doc-Update: `docs/routes.md`, `docs/code-map.md`

## Report-Back
