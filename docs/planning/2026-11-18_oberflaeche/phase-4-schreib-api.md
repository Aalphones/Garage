# Phase 4 — Schreib-API: Status, Löschen, Wiederherstellen, Druck-Link, Kilometer-Randfall

Rating: heikel (Sicherheit, Mandantentrennung, Klärfall-Wirkung) · Modell: opus

## Kontext

- README → „Kontrakt“, Tabelle „Schreiben“ (verbindlich)
- `docs/decisions/019-loeschen-und-status-durch-menschen.md`, `018-oberflaeche-seiten-und-lese-api.md` (Druck-Link), `010-klaerfall-mit-vorschlag-loesen-nur-im-browser.md`, `011-faelligkeit-und-kilometer.md`, `012-belegablage-und-auslieferung.md` (signierte Links)
- `docs/knowledge/GAPS.md` → Eintrag 2026-11-17 „Kilometerstand-Randfall“
- `app/src/Domain/VehicleRepository.php`, `VehicleAccess.php` (Lesen/Schreiben mit Zeilensperre), `RecordRepository.php`, `EventRepository.php`, `OdometerRepository.php`, `NoteRepository.php`, `AuditRepository.php` (`record(Actor, action, target, garageId, before, after, vehicleId)`), `Actor.php` (Rollen, `forUser`), `OpenItemEffect.php` (`resolveReading`), `OdometerService.php` (Vorwärts-Regel), `DossierService.php`, `DossierFormat.php`
- `app/src/Auth/FileLink.php` (Muster für das Signieren), `app/src/Auth/ApiAuth.php` (`Actor::forUser(…, Actor::VIA_WEB, …)`)
- `app/src/Api/OpenItemController.php` (Controller-Muster), `app/src/Api/ApiErrors.php` (neue Codes eintragen: `already_deleted`, `not_deleted` → 409, `restore_expired` → 410)

## AK

- [ ] Neuer `Domain/VehicleService`-Teil oder eigener `Domain/LifecycleService.php` (Entscheidung: **`LifecycleService`**, weil er Fahrzeuge und sieben Tabellen gleich behandelt) mit `setVehicleStatus`, `deleteVehicle`, `restoreVehicle`, `deleteRecord`, `restoreRecord`. Alles in `Transaction::run`, Fahrzeug über `VehicleAccess` gesperrt, jede Abfrage mit `garage_id IN (…)` des Akteurs.
- [ ] Rollen: Fahrzeug-Status/Löschen/Wiederherstellen nur `inhaber` (sonst `DomainError` → 403 `forbidden`); Datensätze löschen/wiederherstellen `inhaber`\|`mitglied` (`lesend` → 403). Nur `Actor::VIA_WEB` — die Routen nehmen ohnehin nur JWT.
- [ ] `type` → Tabelle fest verdrahtet als Konstante `LifecycleService::RECORD_TABLES = ['event' => 'events', 'measurement' => 'measurements', 'tire_set' => 'tire_sets', 'part' => 'parts', 'damage' => 'damages', 'recall' => 'recalls', 'note' => 'notes']`; unbekannter Typ → 404 `not_found`. Tabellennamen nie aus der Eingabe in SQL.
- [ ] Löschen setzt `status = 'geloescht'`, `status_reason` = Grund (nicht bei `notes`, die Spalte fehlt), `updated_at`; Wiederherstellen `status = 'aktiv'`, `status_reason = NULL`. Ein gelöschtes Ereignis nimmt seinen Kilometerstand (`odometer_readings.event_id`) mit und gibt ihn beim Wiederherstellen zurück.
- [ ] Wiederherstellen eines Datensatzes, dessen Schlüssel inzwischen ein aktiver Datensatz belegt (z. B. Teil mit gleichem Bauteil+Marke+Artikelnummer, Notiz mit gleichem Thema): 409 `conflict` mit Meldung „Es gibt inzwischen einen gleichen Eintrag.“ — prüfen über `RecordMerger::TABLES` (Schlüsselspalten) bzw. `notes.topic`.
- [ ] Fahrzeug wiederherstellen nur, wenn `updated_at` höchstens 30 Tage zurückliegt (`410 restore_expired`); Konstante `LifecycleService::RESTORE_DAYS = 30` (auch von `VehicleRepository::listDeleted` aus Phase 2 benutzt).
- [ ] Audit je Aktion: `vehicle.status`, `vehicle.delete`, `vehicle.restore`, `record.delete`, `record.restore`; `target` = `<tabelle>:<id>`; vorher/nachher `{status, status_reason}`.
- [ ] Druck-Link: `Auth/PrintLink.php` analog `FileLink` (Nutzlast `{v: vehicleId, u: userId, e: ablauf}`, HMAC mit demselben Geheimnis wie `FileLink`, 600 s); `POST vehicles/{id}/print-link` prüft Lesezugriff und liefert `{url: "/garage/akte/<slug>", expires_at}`; `GET /garage/akte/{slug}` (neuer `Api/PrintController::show`) prüft Signatur und Ablauf, baut `Actor::forUser(u, VIA_WEB, …)` nur, wenn der Nutzer `aktiv` ist, und rendert HTML (Phase 8 liefert Vorlage und CSS; in dieser Phase reicht `<pre>` mit dem Markdown aus `DossierService::render`). Ungültig/abgelaufen → 404 mit kurzer HTML-Seite „Link abgelaufen — in GARAGE erneut auf Drucken tippen.“ Antwort-Header: `Cache-Control: private, no-store`, `X-Robots-Tag: noindex`.
- [ ] Kilometer-Randfall (`OpenItemEffect::resolveReading`): Wird bei einem strittigen Stand „neu“ gewählt, werden alle späteren gültigen Stände desselben Fahrzeugs, die kleiner sind als der neue Stand, auf `strittig` gesetzt und je einer ein Klärfall `konflikt` angelegt (Text wie bei `OdometerService` für einen rückwärts laufenden Stand). Danach in `GAPS.md` den Eintrag streichen.
- [ ] Durchgespielte Fälle im Report-Back (je Statuscode): Inhaber setzt Status; Mitglied setzt Status → 403; lesend löscht Ereignis → 403; Nutzer B löscht Ereignis von A → 404; Ereignis löschen → nicht mehr in `GET …/events`, nicht in `get_dossier`, Kilometerstand weg; wiederherstellen → alles zurück; zweimal löschen → 409; Fahrzeug löschen → nicht in `GET vehicles`, in `GET vehicles?deleted=1`; Wiederherstellen nach künstlich gealtertem `updated_at` (lokal per SQL) → 410; Druck-Link abgelaufen → 404; Druck-Link eines gesperrten Nutzers → 404; Kilometer-Randfall mit drei Ständen nachgestellt.

## Checkliste

- [ ] `LifecycleService`, Repository-Methoden (in den bestehenden Repositories, nicht in einem neuen)
- [ ] `Api/VehicleController` (`updateStatus`, `delete`, `restore`, `printLink`), `Api/RecordController` (neu: `delete`, `restore`), `Api/PrintController` (neu), `Auth/PrintLink`
- [ ] Routen in `app/index.php`; `ApiErrors`
- [ ] `OpenItemEffect::resolveReading`
- [ ] Doc-Update: `docs/routes.md`, `docs/code-map.md`, `docs/knowledge/GAPS.md`, ADR-019 bei Abweichung

## Report-Back
