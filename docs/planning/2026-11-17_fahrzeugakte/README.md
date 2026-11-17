# Meilenstein 2 — Fahrzeugakte

Ziel: Ein Agent führt über MCP die ganze Akte eines Fahrzeugs — Zulassung, Ausstattung, Spezifikationen, Scheckheft mit Positionen, Kilometerstände, Wartungsplan mit berechneten Fälligkeiten, Messwerte, Reifen, Teile, Schäden, Rückrufe, Notizen — jede Angabe mit Herkunft, Widersprüche als Klärfälle. Ein Mensch löst Klärfälle auf einer schlichten Seite im Konto. `get_dossier` liefert die Akte als Markdown.

Quelle des Umfangs: [`../../PROJECT.md`](../../PROJECT.md) → Meilenstein 2, Fachkonzept [`../../design/konzept-garage.md`](../../design/konzept-garage.md) → „Datenmodell“, „MCP-Oberfläche“. Vorbild für allen neuen Code: Meilenstein 1 (`VehicleService`, `VehicleRepository`, `VehicleTools`), Plan im Archiv [`../../archive/2026-11/2026-11-16_konten-mcp-oauth/`](../../archive/2026-11/2026-11-16_konten-mcp-oauth/README.md).

## Phasen

| # | Phase | Rating | Status |
|---|---|---|---|
| 1 | [Fundament und Stammdaten](phase-1-fundament-stammdaten.md) — Datensatz-Abgleich, Klärfall-Vorschlag, Zulassung, Ausstattung, Spezifikationen | heikel | pending |
| 2 | [Scheckheft und Kilometer](phase-2-scheckheft-kilometer.md) — Werkstätten, Ereignisse mit Positionen, Kilometerstände, Trockenlauf | heikel | pending |
| 3 | [Wartungsplan und Fälligkeiten](phase-3-wartungsplan.md) — Aufgaben, Intervallregeln, Kilometerprognose, Fälligkeitsrechnung | heikel | pending |
| 4 | [Messwerte, Reifen, Teile, Schäden, Rückrufe, Notizen](phase-4-messwerte-reifen-teile.md) | standard | pending |
| 5 | [Klärfälle, Verlauf, Suche](phase-5-klaerfaelle.md) — Tools, Web-API zum Lösen, Platzhalter-Seite | heikel | pending |
| 6 | [Akte, Resources, Prompts](phase-6-akte.md) — `get_dossier`, `garage://`-Resources, zwei Prompts, Server-Anweisungen | standard | pending |
| 7 | [Abschluss auf Strato](phase-7-abschluss.md) — Mandanten-Liste über alle Tools, Testkonten sperren, Doku, Archiv | mechanisch | pending |

Reihenfolge ist Pflicht: jede Phase baut auf den Klassen der vorigen. Jede Phase endet mit `deploy.cmd` und Prüfung per `curl` auf Strato — lokal gibt es kein MySQL (geprüft 2026-11-17: kein MySQL-Dienst, keine `app/.env.local`).

## Entscheidungen (fallen mit diesem Plan)

| # | Entscheidung | ADR / Phase |
|---|---|---|
| E1 | **Spezifikationen sind Fakten** in `vehicle_facts` mit Feldnamen `spec.<bereich>.<schluessel>` (z. B. `spec.motoroel.viskositaet`); damit gilt die Konfliktregel aus ADR-007 unverändert (Golf: 0W-20 gegen 5W-30 wird Klärfall). Einheit steht im Wert („4,3 l“). `field` wird auf 120 Zeichen verbreitert. | ADR-009 / 1 |
| E2 | **Datensatz-Abgleich für alle Listen-Tabellen** (Zulassung, Ausstattung, Ereignisse, Messwerte, Reifensätze, Teile, Schäden, Rückrufe) über eine Klasse `RecordMerger`: natürlicher Schlüssel je Tabelle; vorhanden → leere Spalten ergänzen, nicht bestätigte überschreiben, bestätigte nie — Abweichung wird Klärfall mit gespeichertem Vorschlag. | ADR-009 / 1 |
| E3 | **Klärfall trägt den Vorschlag** (`open_items.proposal_json`), damit ein Mensch „Neuer Wert gilt“ wählen kann, ohne dass der Wert neu erfasst werden muss. | ADR-010 / 1, 5 |
| E4 | **Lösen nur im Browser:** `OpenItemService::resolve` prüft `Actor::via === web` und Rolle `inhaber`/`mitglied`; Agenten-Tokens kommen an die Web-API ohnehin nicht heran (ApiAuth). Entscheidungen: `bestehend`, `neu`, `verwerfen` (Konflikt) bzw. `erledigt`, `verwerfen` (Nachfrage, Beobachtung). | ADR-010 / 5 |
| E5 | **Platzhalter-Seite für Klärfälle** (Wahl des Inhabers 2026-11-17): Abschnitt „Klärfälle“ in `web/index.html`, Gestaltung wie die Konto-Seiten aus M1; neu gestaltet in M4. | — / 5 |
| E6 | **Fälligkeit und Kilometerprognose** entstehen beim Lesen (`OdometerForecast`, `DueCalculator`, reine Klassen ohne Datenbank). „Was zuerst eintritt“ aus Monaten und km; Schwellen „bald“ = 3 Monate oder 3.000 km (Konzept, als Konstante; einstellbar erst in M4). | ADR-011 / 3 |
| E7 | **Kilometer laufen vorwärts:** ein Stand, der einem früheren größeren oder späteren kleineren widerspricht, wird mit Status `strittig` gespeichert, zählt nicht für die Prognose und öffnet einen Klärfall. | ADR-011 / 2 |
| E8 | **Geld in Cent** (`*_cents` INT), Eingabe in Euro (`"1288.70"` oder `1288.7`). | — / 2 |
| E9 | **Schäden und Rückrufe** gehören in M2 (Wahl des Inhabers 2026-11-17). | — / 4 |
| E10 | **`audit_log.vehicle_id`** (neu, NULL) für den Verlauf je Fahrzeug; Lese-Tool `list_changes`. | — / 1, 5 |
| E11 | **Nicht in M2:** Kauf/Verkauf (`ownership_changes`, Kaufpreis) → M5; Status verkauft/stillgelegt setzen und Löschen durch Menschen → M4; Belege/`source_document_id` mit FK → M3; erlaubte Fahrzeuge je Token → Follow-up aus M1. | — |

ADR-Nummern 009–011 sind frei (geprüft: `docs/decisions/` endet bei 008, keine geparkten Pläne).

## Kontrakt

Gilt für alle Phasen; Abweichung nur per Eintrag in `FINDINGS.md` und Plan-Änderung.

### Gemeinsame Bausteine (Phase 1)

| Klasse | Aufgabe |
|---|---|
| `Domain\Provenance` | `SOURCES`, `CONFIDENCE_RANK`, `validate(array $input, string $context): array{source, source_url, confidence}` — aus `VehicleService` herausgezogen |
| `Domain\Validate` | statische Prüfer mit Fehlertext + Beispiel: `date`, `int`, `text`, `enum`, `decimal`, `euroToCents`, `bool`, `url` — aus `VehicleService` herausgezogen und ergänzt |
| `Domain\VehicleAccess` | `readable(int $vehicleId, Actor): array` (not_found) und `writable(int $vehicleId, Actor): array` (sperrt die Fahrzeugzeile, not_found/forbidden) — aus `VehicleService` herausgezogen |
| `Domain\RecordMerger` | `merge(string $table, array $values, array $provenance, array $vehicle, Actor $actor, ?array $key = null): array{id, result, open_item_ids, changed}` — Datensatz-Abgleich (E2) |
| `Db\Transaction::dryRun` | wie `run`, aber am Ende `rollBack` (für `validate_service_event`) |

### Ergebnis-Vokabular je Datensatz

`angelegt` (neu) · `ergaenzt` (nur leere Spalten gefüllt) · `aktualisiert` (nicht bestätigten Wert ersetzt oder Konfidenz gehoben) · `unveraendert` · `klaerfall` (mindestens eine Abweichung zu Bestätigtem, `open_item_ids` nennt die Klärfälle). Bei Fakten (`set_vehicle_facts`, `set_spec`) bleibt das M1-Vokabular ohne `ergaenzt`.

### Klärfall-Vorschlag (`open_items.proposal_json`)

| `ref_table` | `proposal_json` | „Neuer Wert gilt“ bewirkt |
|---|---|---|
| `vehicle_facts` | `null` (der Vorschlag ist die `strittig`-Zeile `ref_id`) | aktuelle Zeile `abgeloest`, strittige → `aktuell` |
| Listen-Tabelle aus E2 | `{"field": "<spalte>", "value": "<text>", "source": "…", "source_url": …, "confidence": "…"}` | Spalte der Zeile `ref_id` auf `value`, Herkunft der Zeile auf die des Vorschlags |
| `events` (Positionen) | `{"items": [<Positionen wie Eingabe>], "source": …, "confidence": …}` | alte Positionen `abgeloest`, Vorschlag als neue Positionen |
| `odometer_readings` | `{"conflicts_with": [<reading_id>, …]}` (Zeile `ref_id` ist der strittige Stand) | strittiger Stand → `gueltig`, `conflicts_with` → `verworfen` |
| `task_rules` | `{"interval_km": …, "interval_months": …, "first_km": …, "first_months": …, "mode": …, "valid_from": …, "source": …, "source_url": …, "confidence": …}` | neue Regelzeile `aktuell` aus dem Vorschlag, alte `abgeloest` |

„Bisheriger Wert gilt“ und „Verwerfen“ ändern keine Daten außer: strittige Fakt-Zeile → `abgeloest`, strittiger Kilometerstand → `verworfen`.

### Neue MCP-Tools

Alle Eingaben mit Herkunft nehmen `source` (`beleg`,`nutzer`,`recherche`,`agent`,`werkstatt`), optional `source_url`, `confidence` (`bestaetigt`,`wahrscheinlich`,`unsicher`) — bei Listen-Tools **einmal je Aufruf** auf oberster Ebene. Daten als `YYYY-MM-DD`, Euro als Zahl oder Text mit höchstens zwei Nachkommastellen.

| Tool | Scope | Phase | Eingabe (Kern) | Ausgabe (Kern) |
|---|---|---|---|---|
| `set_spec` | write | 1 | `vehicle_id`, `specs:[{area, key, value}]`, Herkunft | `{vehicle_id, specs:[{area,key,value,result,open_item_id?}]}` |
| `record_registration` | write | 1 | `vehicle_id`, `plate`, `valid_from`, `valid_to?`, `holder?`, `holder_address?`, Herkunft | `{registration_id, result, open_item_ids}` |
| `upsert_equipment` | write | 1 | `vehicle_id`, `items:[{name, code?, category, list_price_eur?}]`, Herkunft | `{vehicle_id, items:[{name, equipment_id, result, open_item_ids}]}` |
| `get_vehicle` (erweitert) | read | 1, 3 | wie M1 | zusätzlich `specs`, `registrations`, `holder_count`, `equipment`, ab Phase 3 `km_estimate` |
| `record_service_event` | write | 2 | siehe Phase 2 | `{event_id, result, items_result, odometer, open_item_ids, warnings}` |
| `validate_service_event` | write | 2 | wie `record_service_event` | dasselbe + `dry_run: true`, nichts gespeichert |
| `list_events` | read | 2 | `vehicle_id`, `from?`, `to?`, `kind?` | `{events:[…]}` |
| `get_event` | read | 2 | `event_id` | Ereignis mit Positionen und Kilometerstand |
| `record_odometer` | write | 2 | `vehicle_id`, `read_on`, `km`, `note?`, Herkunft | `{reading_id, result, open_item_id?}` |
| `upsert_task_rule` | write | 3 | `vehicle_id`, `task_key`, `title?`, Intervalle, `mode`, `valid_from?`, Herkunft | `{task_id, rule_id, result, open_item_id?}` |
| `get_due_items` | read | 3 | `vehicle_id?`, `horizon_months?` (Standard 36) | `{today, items:[…]}` je Aufgabe mit Rechenweg |
| `list_vehicles` (erweitert) | read | 3 | wie M1 | je Fahrzeug zusätzlich `km_estimate`, `next_due` |
| `record_measurement` | write | 4 | `vehicle_id`, `measured_on`, `odometer_km?`, `values:[{quantity, position?, value, label?, unit?}]`, Herkunft | `{measurements:[{measurement_id,result,open_item_ids}]}` |
| `get_measurements` | read | 4 | `vehicle_id`, `quantity?` | `{series:[…]}` |
| `record_tires` | write | 4 | `vehicle_id`, `set:{…}`, `mount?`, `unmount?`, Herkunft | `{tire_set_id, result, mount}` |
| `get_tires` | read | 4 | `vehicle_id` | `{sets:[…], mounted_set_id}` |
| `add_part` | write | 4 | `vehicle_id`, `component`, `oem_number?`, `brand?`, `article_number?`, `role`, `verified?`, `note?`, Herkunft | `{part_id, result, open_item_ids}` |
| `get_parts` | read | 4 | `vehicle_id` | `{parts:[…]}` nach Bauteil gruppiert |
| `record_damage` | write | 4 | siehe Phase 4 | `{damage_id, result, open_item_ids}` |
| `record_recall` | write | 4 | siehe Phase 4 | `{recall_id, result, open_item_ids}` |
| `upsert_note` | write | 4 | `vehicle_id`, `topic`, `body` | `{note_id, revision, result}` |
| `list_open_items` | read | 5 | `vehicle_id?`, `status?` (Standard `offen`) | `{open_items:[…]}` |
| `open_item` | write | 5 | `vehicle_id`, `kind` (`nachfragen`\|`beobachten`), `text`, `suggestion?` | `{open_item_id, result}` |
| `list_changes` | read | 5 | `vehicle_id?`, `limit?` (Standard 50, höchstens 200) | `{changes:[…]}` |
| `search` | read | 5 | `query` (3–100 Zeichen), `vehicle_id?` | `{hits:[{type,id,vehicle_id,title,snippet}]}` |
| `get_dossier` | read | 6 | `vehicle_id` | Markdown-Text |

Annotationen: lesende `R`, schreibende `W`; keins ist destruktiv. Fehler wie M1: `not_found` (auch für fremde IDs), `forbidden` (Rolle `lesend`), `invalid_value` (Feld + Beispiel), `invalid_argument`.

### Neue Routen (Phase 5)

| Methode | Pfad | Schutz | Antwort |
|---|---|---|---|
| GET | `/garage/api/open-items` | JWT | `{open_items:[…]}` aller Garagen des Nutzers, Status `offen`, mit `current`/`proposed`/`decisions` |
| POST | `/garage/api/open-items/{id}/resolve` | JWT, Rolle `inhaber`/`mitglied` | Body `{decision, note?}` → 200 `{open_item_id, status, decision}`; 404 `not_found`, 403 `forbidden`, 409 `already_resolved`, 422 `invalid_value` |

### Migrationen

`008_record.sql` (1) · `009_event.sql` (2) · `010_task.sql` (3) · `011_measurement.sql`, `012_tire.sql`, `013_part.sql`, `014_damage.sql`, `015_note.sql` (4) · `016_testkonten_m2_sperren.sql` (7).

## Finale Abnahmekriterien

- [ ] Ein Agent erfasst für ein Testfahrzeug Zulassung, Ausstattung, Spezifikationen, zwei Werkstattrechnungen mit Positionen, drei Kilometerstände, vier Intervallregeln, Messwerte, einen Reifensatz mit Montage, Teile, einen Schaden, einen Rückruf und eine Notiz — jede Angabe mit Herkunft; derselbe Aufruf ein zweites Mal ergibt `unveraendert` und keine Doppelung.
- [ ] Widersprüche werden Klärfälle statt Überschreibungen: Spezifikation gegen bestätigte, Rechnung mit anderen Positionen, kleinerer Kilometerstand nach größerem, abweichende bestätigte Intervallregel.
- [ ] `get_due_items` rechnet „was zuerst eintritt“ mit Rechenweg; das Clio-Beispiel aus dem Konzept (Innenraumfilter jährlich, zuletzt 01/2025) erscheint als `ueberfaellig`.
- [ ] Ein Mensch löst auf der Platzhalter-Seite je einen Klärfall mit „Bisheriger Wert gilt“, „Neuer Wert gilt“ und „Verwerfen“; die Akte zeigt danach den gewählten Wert. Ein Agenten-Token kann nichts lösen.
- [ ] `get_dossier` liefert die ganze Akte als Markdown mit Herkunft an jedem Wert; `garage://fahrzeug/{id}/akte` liefert denselben Text.
- [ ] Nutzer B sieht über keinen Weg (MCP, Resource, API) Daten von Nutzer A; Rolle `lesend` und Token ohne `garage:write` schreiben nichts.
- [ ] `/garage-app/.env` antwortet nach jedem Deploy mit 403; der Rauchtest von `deploy.cmd` ist grün.

## Risiken und Konfidenz

- 🟡 **Werte-Vergleich im Datensatz-Abgleich:** MySQL liefert `DECIMAL` als `"2.50"`, Ganzzahl als `"110"`, Boolean als `"1"`. Vergleicht der Abgleich unnormalisiert, meldet ein zweiter identischer Aufruf fälschlich `aktualisiert` oder sogar `klaerfall`. Check in Phase 1 und 4: jeden Schreib-Aufruf zweimal senden, zweites Ergebnis muss `unveraendert` sein.
- 🟡 **Monatsrechnung:** `DateTimeImmutable::modify('+1 month')` macht aus dem 31.01. den 03.03. Check in Phase 3: `DueCalculator::addMonths('2026-01-31', 1)` muss `2026-02-28` ergeben (per `php -r` lokal, ohne Datenbank).
- 🟡 **Korrektur von Agenten-Fehlern** geht in M2 nur über Klärfälle und Überschreiben nicht bestätigter Werte; ein falsch angelegtes Ereignis bleibt bis M4 (Löschen durch Menschen) stehen.
- 🟡 **Kein lokales MySQL:** jede Prüfung läuft auf Strato; Testkonten `test-m2-a/b/c@example.com`, gesperrt per Migration in Phase 7.
- Fehlerklassen geprüft: MCP — Server nach altem Schema (abgedeckt: die Schicht aus M1 spricht beide Generationen, nichts daran ändern); Strato und PHP/MySQL haben im Vault keinen Fehlerklassen-Abschnitt.

## Summary

## Files touched

## Commits

## Deviations from plan

## Follow-ups
