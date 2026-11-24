# Phase 4b — Schreib-API: kleine Eingaben durch Menschen

Rating: heikel (Konfliktregel wird für Menschen bewusst umgangen, Mandantentrennung) · Modell: opus · nach Phase 4

## Kontext

- README → „Kontrakt“, Tabelle „Kleine Eingaben“ (verbindlich)
- `docs/decisions/021-kleine-eingaben-durch-menschen.md`, `009-datensatz-abgleich-und-spezifikationen-als-fakten.md`, `011-faelligkeit-und-kilometer.md`, `013-belegbezug-ueber-anhaenge.md`
- `app/src/Domain/VehicleService.php` (`FIELDS`, `validFact`/`validValue`, `applyFact`, `writeCurrent`, `currentFact`), `VehicleRepository.php` (`supersede`, Fakt-Status), `OdometerService.php`, `NoteService.php` (`upsert`), `DocumentService.php` (`attach`, `TARGETS`), `VehicleAccess.php`, `Actor.php`
- Muster der Controller und Fehler: `app/src/Api/OpenItemController.php`, `ApiErrors.php`; Phase 4 (`LifecycleService`, Rollenprüfung)

## AK

- [x] `POST vehicles/{id}/odometer` → `OdometerService` wie `record_odometer` mit `source: nutzer`, `confidence: bestaetigt`; Antwort wie das Tool (`result` kann `klaerfall` sein).
- [x] `PATCH vehicles/{id}/facts` → neue Methode `VehicleService::correctByHuman(int $vehicleId, string $field, string $value, Actor $actor)`: Feld aus `FIELDS`, Wert über dieselbe Prüfung wie `set_vehicle_facts`; offener Klärfall am Feld (`disputed`) → `DomainError` 409 `open_item_pending`; sonst `writeCurrent` mit Herkunft `nutzer`/`bestaetigt` und der bisherigen Zeile als `previous` (auch wenn bestätigt). Nur `Actor::VIA_WEB`.
- [x] `PUT vehicles/{id}/notes` → `NoteService::upsert` (Thema 1–120, Text 1–20.000); Antwort wie `upsert_note`.
- [x] `POST vehicles/{id}/cover` → `DocumentService::attach(document_id, 'fahrzeug', vehicleId, 'titelbild')`; Dokument kein Foto oder anderes Fahrzeug → 422 `invalid_value` bzw. 404.
- [x] Rollen: `inhaber`/`mitglied`; `lesend` → 403; fremdes Fahrzeug → 404.
- [x] Durchgespielte Fälle im Report-Back: bestätigte FIN per Browser korrigiert → neuer Wert aktuell, alter abgelöst, Audit; strittiges Feld → 409; Rückwärts-km → `klaerfall`; Notiz mit vorhandenem Thema → neue Revision; Titelbild mit PDF → 422; lesend → 403; Nutzer B auf Fahrzeug A → 404; MCP `get_vehicle` zeigt danach `source: nutzer`.

## Checkliste

- [x] `VehicleService::correctByHuman`; `Api/VehicleController` (`addOdometer`, `correctFact`, `upsertNote`, `setCover`); Routen; `ApiErrors` (`open_item_pending` → 409)
- [x] Doc-Update: `docs/routes.md`, `docs/code-map.md`

## Report-Back

Status: **fertig** (2026-11-23). Zur Laufzeit ungeprüft und ohne lokale Syntaxprüfung (kein PHP, Docker-Dienst aus); CI-Lint abwarten.

- `VehicleService::correctByHuman(vehicleId, mixed field, mixed value, actor)` — Abweichung vom Plan: `field`/`value` als `mixed`, weil sie roh aus dem JSON-Body kommen und dort geprüft werden. Reihenfolge in der Transaktion: Schreibrecht (404/403) → Feld/Wert (422) → offener Klärfall (409) → schreiben. Gleicher Wert mit anderer Herkunft wird auf `nutzer`/`bestaetigt` gehoben (`aktualisiert`); nur gleicher Wert schon von `nutzer`/`bestaetigt` ist `unveraendert`.
- `OdometerService::recordByHuman`: Datum in der Zukunft (deutsche Zeit) → 422, sonst `record` mit `Provenance::BY_HUMAN`.
- `setCover` prüft das Schreibrecht am Fahrzeug vor dem Dokument, damit `lesend` immer 403 bekommt.

Durchgespielte Fälle (am Code, nicht live):
- Bestätigte FIN per Browser korrigiert → `writeCurrent` mit `previous` = bisherige Zeile: alte Zeile `abgeloest`, neue `aktuell` mit `nutzer`/`bestaetigt`, Audit `vehicle_fact.set` mit Vorher/Nachher und `via: web` (neu in den Nachher-Daten, gilt für jede Faktenschreibung).
- Strittiges Feld mit offenem Klärfall → `disputedFacts` liefert `open_item_id > 0` → 409 `open_item_pending`.
- Rückwärts-km → `record` → `findConflicts` → `dispute` → `result: klaerfall`.
- Notiz mit vorhandenem Thema und anderem Text → `upsert` → Revision + 1, `aktualisiert`.
- Titelbild mit PDF (doc_type ≠ `foto`) → `attach` → 422 `invalid_value`.
- `lesend` → `writable`/`assertCanWriteGarage` → 403 `forbidden` (Kilometerstand: bei ungültigem Body kommt vorher 422).
- Nutzer B auf Fahrzeug A → `find` mit Garagen des Akteurs → 404 `not_found`.
- MCP `get_vehicle` danach → `currentFacts` liefert die neue Zeile mit `source: nutzer`.
