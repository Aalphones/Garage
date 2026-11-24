# Phase 4 — Schreib-API: Status, Löschen, Wiederherstellen, Druck-Link, Kilometer-Randfall

Rating: heikel (Sicherheit, Mandantentrennung, Klärfall-Wirkung) · Modell: opus · Status: complete (2026-11-23)

## Kontext

- README → „Kontrakt“, Tabelle „Schreiben“ (verbindlich)
- `docs/decisions/019-loeschen-und-status-durch-menschen.md`, `018-oberflaeche-seiten-und-lese-api.md` (Druck-Link), `010-klaerfall-mit-vorschlag-loesen-nur-im-browser.md`, `011-faelligkeit-und-kilometer.md`, `012-belegablage-und-auslieferung.md` (signierte Links)
- `docs/knowledge/GAPS.md` → Eintrag 2026-11-17 „Kilometerstand-Randfall“
- `app/src/Domain/VehicleRepository.php`, `VehicleAccess.php` (Lesen/Schreiben mit Zeilensperre), `RecordRepository.php`, `EventRepository.php`, `OdometerRepository.php`, `NoteRepository.php`, `AuditRepository.php` (`record(Actor, action, target, garageId, before, after, vehicleId)`), `Actor.php` (Rollen, `forUser`), `OpenItemEffect.php` (`resolveReading`), `OdometerService.php` (Vorwärts-Regel), `DossierService.php`, `DossierFormat.php`
- `app/src/Auth/FileLink.php` (Muster für das Signieren), `app/src/Auth/ApiAuth.php` (`Actor::forUser(…, Actor::VIA_WEB, …)`)
- `app/src/Api/OpenItemController.php` (Controller-Muster), `app/src/Api/ApiErrors.php` (neue Codes eintragen: `already_deleted`, `not_deleted` → 409, `restore_expired` → 410)

## AK

- [x] Neuer `Domain/VehicleService`-Teil oder eigener `Domain/LifecycleService.php` (Entscheidung: **`LifecycleService`**, weil er Fahrzeuge und sieben Tabellen gleich behandelt) mit `setVehicleStatus`, `deleteVehicle`, `restoreVehicle`, `deleteRecord`, `restoreRecord`. Alles in `Transaction::run`, Fahrzeug über `VehicleAccess` gesperrt, jede Abfrage mit `garage_id IN (…)` des Akteurs.
- [x] Rollen: Fahrzeug-Status/Löschen/Wiederherstellen nur `inhaber` (sonst `DomainError` → 403 `forbidden`); Datensätze löschen/wiederherstellen `inhaber`\|`mitglied` (`lesend` → 403). Nur `Actor::VIA_WEB` — die Routen nehmen ohnehin nur JWT.
- [x] `type` → Tabelle fest verdrahtet als Konstante `LifecycleService::RECORD_TABLES = ['event' => 'events', 'measurement' => 'measurements', 'tire_set' => 'tire_sets', 'part' => 'parts', 'damage' => 'damages', 'recall' => 'recalls', 'note' => 'notes']`; unbekannter Typ → 404 `not_found`. Tabellennamen nie aus der Eingabe in SQL.
- [x] Löschen setzt `status = 'geloescht'`, `status_reason` = Grund (nicht bei `notes`, die Spalte fehlt), `updated_at`; Wiederherstellen `status = 'aktiv'`, `status_reason = NULL`. Ein gelöschtes Ereignis nimmt seinen Kilometerstand (`odometer_readings.event_id`) mit und gibt ihn beim Wiederherstellen zurück.
- [x] Wiederherstellen eines Datensatzes, dessen Schlüssel inzwischen ein aktiver Datensatz belegt (z. B. Teil mit gleichem Bauteil+Marke+Artikelnummer, Notiz mit gleichem Thema): 409 `conflict` mit Meldung „Es gibt inzwischen einen gleichen Eintrag.“ — prüfen über `RecordMerger::TABLES` (Schlüsselspalten) bzw. `notes.topic`.
- [x] Fahrzeug wiederherstellen nur, wenn `updated_at` höchstens 30 Tage zurückliegt (`410 restore_expired`); Konstante `LifecycleService::RESTORE_DAYS = 30` (auch von `VehicleRepository::listDeleted` aus Phase 2 benutzt).
- [x] Audit je Aktion: `vehicle.status`, `vehicle.delete`, `vehicle.restore`, `record.delete`, `record.restore`; `target` = `<tabelle>:<id>`; vorher/nachher `{status, status_reason}`.
- [x] Druck-Link: `Auth/PrintLink.php` analog `FileLink` (Nutzlast `{v: vehicleId, u: userId, e: ablauf}`, HMAC mit demselben Geheimnis wie `FileLink`, 600 s); `POST vehicles/{id}/print-link` prüft Lesezugriff und liefert `{url: "/garage/akte/<slug>", expires_at}`; `GET /garage/akte/{slug}` (neuer `Api/PrintController::show`) prüft Signatur und Ablauf, baut `Actor::forUser(u, VIA_WEB, …)` nur, wenn der Nutzer `aktiv` ist, und rendert HTML (Phase 8 liefert Vorlage und CSS; in dieser Phase reicht `<pre>` mit dem Markdown aus `DossierService::render`). Ungültig/abgelaufen → 404 mit kurzer HTML-Seite „Link abgelaufen — in GARAGE erneut auf Drucken tippen.“ Antwort-Header: `Cache-Control: private, no-store`, `X-Robots-Tag: noindex`.
- [x] Kilometer-Randfall (`OpenItemEffect::resolveReading`): Wird bei einem strittigen Stand „neu“ gewählt, werden alle späteren gültigen Stände desselben Fahrzeugs, die kleiner sind als der neue Stand, auf `strittig` gesetzt und je einer ein Klärfall `konflikt` angelegt (Text wie bei `OdometerService` für einen rückwärts laufenden Stand). Danach in `GAPS.md` den Eintrag streichen.
- [x] Durchgespielte Fälle im Report-Back (je Statuscode): Inhaber setzt Status; Mitglied setzt Status → 403; lesend löscht Ereignis → 403; Nutzer B löscht Ereignis von A → 404; Ereignis löschen → nicht mehr in `GET …/events`, nicht in `get_dossier`, Kilometerstand weg; wiederherstellen → alles zurück; zweimal löschen → 409; Fahrzeug löschen → nicht in `GET vehicles`, in `GET vehicles?deleted=1`; Wiederherstellen nach künstlich gealtertem `updated_at` (lokal per SQL) → 410; Druck-Link abgelaufen → 404; Druck-Link eines gesperrten Nutzers → 404; Kilometer-Randfall mit drei Ständen nachgestellt.

## Checkliste

- [x] `LifecycleService`, Repository-Methoden (in den bestehenden Repositories, nicht in einem neuen)
- [x] `Api/VehicleController` (`updateStatus`, `delete`, `restore`, `printLink`), `Api/RecordController` (neu: `delete`, `restore`), `Api/PrintController` (neu), `Auth/PrintLink`
- [x] Routen in `app/index.php`; `ApiErrors`
- [x] `OpenItemEffect::resolveReading`
- [x] Doc-Update: `docs/routes.md`, `docs/code-map.md`, `docs/knowledge/GAPS.md`, ADR-019 bei Abweichung

## Report-Back

Stand 2026-11-23. Kein PHP und kein MySQL lokal, Docker nicht gestartet: weder Syntaxprüfung noch Läufe lokal. Die Fälle unten sind **am Code nachvollzogen, nicht ausgeführt**; die Syntax prüft die CI nach dem Push.

| Fall | Erwartung | Weg im Code |
|---|---|---|
| Inhaber setzt Status | 200 `{id,status,status_reason}` | `LifecycleService::setVehicleStatus` → `VehicleAccess::writable` (sperrt) → `ownedVehicle` (Rolle `inhaber`) → `VehicleRepository::setStatus`, Audit `vehicle.status` |
| Mitglied setzt Status | 403 `forbidden` | `writable` lässt Mitglied durch, `ownedVehicle` wirft `forbidden` |
| lesend löscht Ereignis | 403 `forbidden` | `lockedRecord` → `writable` → `assertCanWriteGarage` (Rolle `lesend`) |
| Nutzer B löscht Ereignis von A | 404 `not_found` | `RecordRepository::findAnyStatus` mit `garage_id IN (B)` findet nichts |
| Ereignis löschen | weg aus `…/events`, `get_dossier`, Stand weg | `status='geloescht'`; `EventRepository::listForVehicle`/`find` und alle `odometer_readings`-Leser filtern `status='aktiv'`; `OdometerRepository::setStatusForEvent` nimmt die Stände mit |
| wiederherstellen | alles zurück | `restoreRecord` → Schlüssel-Prüfung → `aktiv`, Stände `geloescht → aktiv` |
| zweimal löschen | 409 `already_deleted` | Status-Prüfung nach der Sperre |
| Fahrzeug löschen | nicht in `GET vehicles`, in `?deleted=1` | `listForActor` filtert `status <> geloescht`; `listDeleted` liest `geloescht` der letzten 30 Tage |
| Wiederherstellen nach gealtertem `updated_at` | 410 `restore_expired` | `findForLifecycle` rechnet `is_restorable` in SQL gegen `UTC_TIMESTAMP() - INTERVAL 30 DAY` |
| Druck-Link abgelaufen | 404-Seite | `PrintLink::verify` prüft `e > time()` |
| Druck-Link eines gesperrten Nutzers | 404-Seite | `PrintController::show` prüft `users.status = 'aktiv'` vor dem Akteur |
| Kilometer-Randfall, drei Stände | A 10.000 km (Jan) und C 25.000 km (Apr) gültig; B 30.000 km (Mär) wird strittig gegen C; danach D 27.000 km (Mai) gültig, weil B nicht zählt. „neu“ für B → C verworfen, D strittig mit Klärfall „Kilometerstand 27.000 km am … passt nicht zu 30.000 km am …“, `proposal.conflicts_with = [B]` | `OpenItemEffect::resolveReading` → `disputeContradicting` → `OdometerRepository::findConflicts` → `OdometerService::disputeExisting` |

Abweichungen vom Plan:
- Randfall prüft **beide Richtungen** (später und kleiner, früher und größer), über dieselbe Abfrage wie beim Erfassen (`findConflicts`). Ein früher datierter größerer Stand, der während des offenen Klärfalls dazukam, widerspricht genauso.
- `RESTORE_DAYS` liegt nur noch in `LifecycleService`; `VehicleRepository::RESTORE_DAYS` entfällt, `VehicleController::list` nutzt die neue Konstante.
- Notiz wiederherstellen prüft das Thema nicht: der eindeutige Schlüssel `uq_notes_topic (vehicle_id, topic)` gilt auch für gelöschte Notizen, ein zweites gleiches Thema kann es nicht geben (ein `upsert_note` mit demselben Thema belebt die gelöschte Notiz wieder, wie bisher).
- `PrintLink` nimmt dasselbe Geheimnis wie `FileLink`, aber einen eigenen Zweck-Schlüssel (`garage-print`), damit ein Dateilink nie als Druck-Link durchgeht.
- Router kennt jetzt den Platzhalter `{type}` (Kleinbuchstaben und Unterstrich, höchstens 20 Zeichen); `Response::html` ist neu.
