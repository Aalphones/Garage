# Meilenstein 4 — Oberfläche

Stand: 2026-11-18 · Entwurf freigegeben: [`artifacts/entwurf-oberflaeche.html`](artifacts/entwurf-oberflaeche.html) (verbindlich, siehe [`artifacts/README.md`](artifacts/README.md))

Die Akte wird im Browser sichtbar und bedienbar: Garage, Fahrzeug mit sieben Reitern, Klärfälle, dazu Löschen, Statuswechsel und Druck durch Menschen. Gestaltung: dunkles Thema „GARAGE Dark Botanical“ (fast schwarze Flächen, Teal als Hervorhebung, Verläufe ins Dunkelgrüne, Hintergrundbild), hell umschaltbar.

| Phase | Inhalt | Rating | Modell | Status |
|---|---|---|---|---|
| 1 | Gestaltungsgrundlage und Schale | standard | sonnet | complete |
| 2 | Lese-API der Akte | standard | sonnet | complete |
| 3 | Scheckheft-Lücken | heikel | opus | complete |
| 4 | Schreib-API: Status, Löschen, Wiederherstellen, Druck-Link, Kilometer-Randfall | heikel | opus | complete |
| 4b | Schreib-API: kleine Eingaben durch Menschen (km-Stand, Wert korrigieren, Notiz, Titelbild) | heikel | opus | complete |
| 5 | Seiten Garage, Fahrzeug-Übersicht, Klärfälle | standard | sonnet | complete |
| 6 | Reiter Scheckheft und Wartung | standard | sonnet | pending |
| 7 | Reiter Messwerte, Reifen, Teile, Belege | standard | sonnet | pending |
| 8 | Fahrzeug-Aktionen, Druckansicht, gelöschte Fahrzeuge | standard | sonnet | pending |
| 8b | Kleine Eingaben in der Oberfläche | standard | sonnet | pending |
| 9 | Doku-Abgleich und Abschluss | mechanisch | sonnet | pending |

Reihenfolge: 1 → 2 → 3 → 4 → 4b → 5 → 6 → 7 → 8 → 8b → 9. Phase 1 und 2 sind unabhängig voneinander; 5–8b brauchen 1 und 2, 6 braucht 3, 8 braucht 4, 8b braucht 4b.

Wünsche des Inhabers (2026-11-18): Menschen dürfen kleine Eingaben machen (ADR-021); der Kamera-Knopf öffnet sofort die Kamera und legt das Foto ohne Seitenwechsel in den Eingang des Fahrzeugs; Klärfälle lassen sich direkt in der Fahrzeug-Übersicht lösen; Handy und Desktop sind gleich wichtig.

Entscheidungen: [ADR-017](../../decisions/017-gestaltung-dark-botanical.md) Gestaltung · [ADR-018](../../decisions/018-oberflaeche-seiten-und-lese-api.md) Seiten, Lese-API, Diagramme, Druck · [ADR-019](../../decisions/019-loeschen-und-status-durch-menschen.md) Löschen und Status · [ADR-020](../../decisions/020-scheckheft-luecken.md) Scheckheft-Lücken · [ADR-021](../../decisions/021-kleine-eingaben-durch-menschen.md) kleine Eingaben.

## Breakpoints und Handy (gilt für jede Oberflächen-Phase)

Jede Ansicht wird in allen Stufen gebaut und ist am Handy voll bedienbar — kein Nachrüsten am Ende.

| Stufe | Breite | Schale | Inhalt |
|---|---|---|---|
| Handy | unter 576 px (Prüfbreiten 360 und 390) | Kopfzeile mit Signet, untere Leiste (4 Ziele), Kamera-Knopf über der Leiste; das Dokument scrollt | eine Spalte; Tabellen als Karten bzw. Zeitstrahl (Umschalter bleibt erreichbar); Blätter von unten; Fahrzeug-Aktionen in einer Zeile unter dem Kopf; Reiter seitlich rollbar mit Ausblendung am rechten Rand, aktiver Reiter immer im Bild |
| Klein | 576–767 px | wie Handy | Klärfall-Optionen nebeneinander ab 560 px, Kennzahlen 4 in einer Reihe ab 600 px |
| Tablet | 768–991 px | wie Handy (untere Leiste) | Tabellen als Standard, Dialoge mittig statt Blatt, Aktionen rechts im Kopf |
| Laptop | 992–1407 px | Seitenleiste links **fest**, nur der Inhaltsbereich rechts scrollt (eigener Scroll-Container) | Übersicht zweispaltig, Angaben zweispaltig in voller Breite |
| Desktop | ab 1408 px | wie Laptop | Inhalt höchstens 72 rem breit |

Handy-Regeln: jedes Bedienziel mindestens 44 × 44 px (kleine Abzeichen über eine unsichtbare Trefferfläche); nichts nur per Überfahren — Rechenwege und Herkunft öffnen per Tippen ein Popover; kein Bedienelement in einem Link verschachtelt; kein waagerechtes Scrollen der Seite (Tabellen nur innerhalb ihres Containers). Prüfung je Oberflächen-Phase: 360 px hell und dunkel jede berührte Ansicht, 768 px und 1366 px je einmal, Überlauf per `document.documentElement.scrollWidth > innerWidth` (bzw. `.content` ab 992 px) gemessen.

## Kontrakt (Browser-API ↔ Oberfläche)

Alle Routen unter `/garage/api/`, Schutz JWT über `ApiAuth::user` (nur Mensch; `gmcp_`-Token → 401). Fremdes oder unbekanntes Fahrzeug/Datensatz → 404 `not_found` (nie 403). Fehler wie bisher `{"error","message"}` über `ApiErrors::respond`. Jede Lese-Route ruft **denselben Service** wie das gleichnamige MCP-Tool und liefert dessen Ausgabe unverändert (Spalte „wie“).

**Lesen (Phase 2, Lücken Phase 3)**

| Methode | Pfad | Antwort | wie |
|---|---|---|---|
| GET | `vehicles` | bestehend; neu: Query `deleted=1` → `{vehicles:[{id,garage_id,label,status_reason,deleted_at,restorable_until}]}` nur gelöschte der Garagen mit Rolle `inhaber`, gelöscht vor höchstens 30 Tagen | — |
| GET | `vehicles/{id}` | Ausgabe von `get_vehicle` plus `permissions:{write:bool, owner:bool}` (write = Rolle `inhaber`/`mitglied`, owner = `inhaber`) | `get_vehicle` |
| GET | `vehicles/{id}/due` | `get_due_items` für dieses Fahrzeug, Horizont 36 | `get_due_items` |
| GET | `vehicles/{id}/events` | `{events:[…wie list_events…], gaps:[{task_key,title,expected_by,previous:{date,km},next:{date,km},explanation,rule_uncertain}]}` | `list_events` + Lücken (Phase 3; bis dahin `gaps: []`) |
| GET | `events/{id}` | Ausgabe von `get_event` | `get_event` |
| GET | `vehicles/{id}/odometer` | `{readings:[{id,read_on,km,note,source,confidence}]}` gültige Stände aufsteigend (`OdometerRepository::validReadings`) | — |
| GET | `vehicles/{id}/measurements` | `{series:[…]}` | `get_measurements` |
| GET | `vehicles/{id}/tires` | Ausgabe von `get_tires` | `get_tires` |
| GET | `vehicles/{id}/parts` | Ausgabe von `get_parts` | `get_parts` |
| GET | `vehicles/{id}/notes` | `{notes:[…NoteService::listCurrent…]}` | — |
| GET | `vehicles/{id}/damages` | `{damages:[…], recalls:[…]}` aus `DamageService::listDamages`/`listRecalls` | — |
| GET | `vehicles/{id}/dossier` | `text/markdown; charset=utf-8`, `Content-Disposition: attachment; filename="akte-<id>-<JJJJ-MM-TT>.md"`, Text von `get_dossier` | `get_dossier` |
| GET | `due` | `get_due_items` ohne `vehicle_id` — nur Fahrzeuge mit Status `aktiv` | `get_due_items` |

**Schreiben (Phase 4)** — Body JSON; Audit-Eintrag je Aufruf (`AuditRepository::record`, vorher/nachher mit Status und Grund).

| Methode | Pfad | Body | Antwort / Fehler |
|---|---|---|---|
| PATCH | `vehicles/{id}` | `status` (`aktiv`\|`verkauft`\|`stillgelegt`), `reason?` (0–200) | 200 `{id,status,status_reason}`; nicht `inhaber` → 403 `forbidden`; Audit `vehicle.status` |
| POST | `vehicles/{id}/delete` | `reason` (1–200, Pflicht) | 200 `{id,status:"geloescht",restorable_until}`; nicht `inhaber` → 403; Audit `vehicle.delete` |
| POST | `vehicles/{id}/restore` | — | 200 `{id,status:"aktiv"}`; nicht gelöscht → 409 `not_deleted`; älter als 30 Tage → 410 `restore_expired`; Audit `vehicle.restore` |
| POST | `records/{type}/{id}/delete` | `reason?` (0–200) | 200 `{type,id,status:"geloescht"}`; `type` ∈ `event`,`measurement`,`tire_set`,`part`,`damage`,`recall`,`note`; Rolle `lesend` → 403; schon gelöscht → 409 `already_deleted`; Audit `record.delete` |
| POST | `records/{type}/{id}/restore` | — | 200 `{type,id,status:"aktiv"}`; nicht gelöscht → 409 `not_deleted`; Schlüssel inzwischen neu belegt → 409 `conflict`; ohne Zeitgrenze (die Oberfläche nutzt es nur aus der Rückgängig-Leiste); Audit `record.restore` |
| POST | `vehicles/{id}/print-link` | — | 200 `{url,expires_at}` — `url` = `/garage/akte/<slug>`, signiert, 600 s |
| GET | `/garage/akte/{slug}` (ohne `/api`) | — | HTML-Druckansicht ohne Skript; ungültig/abgelaufen → 404-Seite |

Agenten bekommen keins dieser Schreibrechte; kein neues MCP-Tool (ADR-010, Projektregel 5).

**Kleine Eingaben (Phase 4b, ADR-021)** — Rolle `inhaber`\|`mitglied` (`lesend` → 403), Audit wie beim gleichnamigen Tool.

| Methode | Pfad | Body | Antwort / Fehler |
|---|---|---|---|
| POST | `vehicles/{id}/odometer` | `read_on` (Datum, nicht in der Zukunft), `km` (0–2.000.000), `note?` (0–200) | wie `record_odometer` (`result` auch `klaerfall`); Herkunft `nutzer`/`bestaetigt` |
| PATCH | `vehicles/{id}/facts` | `field` (aus `VehicleService::FIELDS`), `value` | 200 `{field,value,result:"aktualisiert"\|"angelegt"\|"unveraendert"}`; ersetzt auch bestätigte Werte; offener Klärfall am Feld → 409 `open_item_pending`; ungültig → 422 |
| PUT | `vehicles/{id}/notes` | `topic` (1–120), `body` (1–20.000) | wie `upsert_note` |
| POST | `vehicles/{id}/cover` | `document_id` | wie `attach_document` mit Ziel `fahrzeug`, Rolle `titelbild`; kein Foto → 422 |

Kamera-Knopf (Phase 5): nutzt die bestehenden Routen `POST documents` (multipart, `vehicle_id`) und `POST inbox` (`document_ids`, `vehicle_id`, `note?`) — keine neue Route.

## Finale AK

- [ ] 1. Nach der Anmeldung landet man auf `garage.html`: Fahrzeugkarten je Garage mit Kennzeichen, Modell, „≈ km heute“, nächster Fälligkeit, Klärfällen, Belegzahl; Filter Aktiv/Verkauft/Stillgelegt/Alle, für Inhaber zusätzlich Gelöscht mit „Wiederherstellen“.
- [ ] 2. `fahrzeug.html#<id>/<reiter>` zeigt die sieben Reiter Übersicht, Scheckheft, Wartung, Messwerte, Reifen, Teile, Belege mit echten Daten aus der Lese-API, jeder Wert mit Herkunfts-Abzeichen (Tippen zeigt Quelle, Sicherheit, Beleg).
- [ ] 3. Scheckheft zeigt Lücken rot im Zeitstrahl und in der Tabelle; der Rechenweg steht an der Lücke.
- [ ] 4. Klärfälle lassen sich auf `klaerfaelle.html` mit „A gilt / B gilt / Beide falsch“ bzw. „Erledigt / Verwerfen“ lösen, mit optionaler Notiz und Rückgängig-Leiste nur bis zur Entscheidung (Entscheidung selbst ist endgültig, ADR-010).
- [ ] 5. Menschen können Ereignisse, Messwerte, Reifensätze, Teile, Schäden, Rückrufe und Notizen löschen (Rückgängig in der Leiste) und als Inhaber Fahrzeugstatus ändern, Fahrzeuge löschen und binnen 30 Tagen wiederherstellen; Rolle `lesend` sieht keine dieser Aktionen und bekommt von der API 403.
- [ ] 5b. Inhaber und Mitglieder tragen im Browser einen Kilometerstand ein, korrigieren einen Stammdaten-Wert (ersetzt sofort, alter Wert im Verlauf), legen Notizen an bzw. bearbeiten sie und setzen ein Foto als Titelbild.
- [ ] 5c. Der Kamera-Knopf öffnet am Handy direkt die Kamera; das Foto liegt danach ohne Seitenwechsel im Eingang dieses Fahrzeugs (Leiste mit „Notiz“). Klärfälle lassen sich in der Fahrzeug-Übersicht aufklappen und dort lösen.
- [ ] 6. „Drucken“ öffnet eine Druckansicht ohne Skript (signierter Link), „Markdown“ lädt die Akte als Datei.
- [ ] 7. Alle Seiten in der Gestaltung des Entwurfs (Tokens aus `web/css/tokens.css`), dunkel als Standard, hell umschaltbar unter Konto → Darstellung; jede Ansicht in allen Stufen aus „Breakpoints und Handy“ gebaut, am Handy (360 px) ohne waagerechtes Scrollen und mit allen Aktionen bedienbar; ab 992 px steht die Seitenleiste, nur der Inhalt scrollt; Kontrast der Tokens AA.
- [ ] 8. Nutzer B sieht und ändert über keine neue Route ein Fahrzeug oder einen Datensatz von Nutzer A (404).
- [ ] 9. `routes.md`, `code-map.md`, `conventions/frontend.md`, `PROJECT.md`, `GAPS.md` nachgezogen; der Kilometer-Randfall aus `GAPS.md` ist behoben und dort gestrichen.

## Konfidenz-Ausweis

- Geprüft: `web/.htaccess` reicht jeden Pfad ohne Datei an die Brücke → `/garage/akte/{slug}` erreicht den Router ohne Änderung an `.htaccess`.
- Geprüft: `status_reason` gibt es in `vehicles`, `events`, `odometer_readings`, `measurements`, `tire_sets`, `parts`, `damages`, `recalls` (Migrationen 006–014), **nicht** in `notes` — dort steht der Grund nur im Audit-Log.
- 🟡 **Ereignis löschen** nimmt den zugehörigen Kilometerstand (`odometer_readings.event_id`) mit; danach rechnen Prognose und Fälligkeiten ohne ihn. Gewollt, aber sichtbar: nach dem Löschen kann sich „≈ km heute“ ändern.
- 🟡 **Hintergrundbild** enthält eine Renault-Raute (vom Inhaber geliefert, 16 % Deckkraft). Bleibt so; bei fremden Nutzern neu bewerten.

Fehlerklassen geprüft: `frameworks/css-architecture` → „Freiraum per Innenabstand reserviert“ ist Prüfpunkt in Phase 1; `systeme/strato-shared-hosting`, `frameworks/charts`, `frameworks/frontend-architecture` ohne einschlägige Fehlerklassen.

## Summary

## Files touched

## Commits

## Deviations from plan

## Follow-ups
