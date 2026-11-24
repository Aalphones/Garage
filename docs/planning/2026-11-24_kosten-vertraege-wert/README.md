# Meilenstein 5 — Kosten, Verträge, Wert

Stand: 2026-11-24 (freigegeben) · Entwurf: [`artifacts/entwurf-kosten-wert.html`](artifacts/entwurf-kosten-wert.html) (verbindlich, siehe [`artifacts/README.md`](artifacts/README.md))

Die Akte rechnet, was ein Auto kostet und wert ist, und führt alles, was Papier und Fristen erzeugt: Verträge mit Beitragsperioden und Vorgängen, Umlage von Garagen-Verträgen (ADAC) auf die aktiven Autos, Tankbuch, sonstige Ausgaben, Zubehör, Plaketten, Bewertungen und Vergleichsinserate. Neu am Fahrzeug die Reiter Kosten, Wert und Papiere (mit Notfallkarte, auch offline), in der Garage der Unterhalt aller aktiven Autos, im Konto ein Kalender-Abo für Fälligkeiten und Stichtage.

Wünsche des Inhabers (2026-11-24): ein Plan für den ganzen Meilenstein; erst Entwurf, dann Bau; drei neue Reiter (Kosten, Wert, Papiere); Menschen tragen im Browser Tankungen und sonstige Ausgaben ein, alles andere erfasst der Agent aus Belegen.

| Phase | Inhalt | Rating | Modell | Status |
|---|---|---|---|---|
| 1 | Datenmodell und Entscheidungen (Migrationen 020–024, ADR-022 bis 025) | standard | sonnet | complete |
| 2 | Verträge, Vorgänge, Plaketten, Zubehör: Dienste, Agenten-Werkzeuge, Löschen | heikel | opus | pending |
| 3 | Tankbuch und Ausgaben: Dienste, Werkzeuge, Eingabe-Routen | standard | sonnet | pending |
| 4 | Wertermittlung: Korridor, Regression, Wertfaktoren | heikel | opus | pending |
| 5 | Kostenrechnung: Blöcke, Verteilung, Umlage, pro km | heikel | opus | pending |
| 6 | Stichtage, Notfallkarte-Daten, Kalender-Abo | heikel | opus | pending |
| 7 | Akte, Fahrzeugliste, Prompts, Server-Anweisungen | standard | sonnet | pending |
| 8 | Reiter Kosten mit Blättern Tankung und Ausgabe | standard | sonnet | pending |
| 9 | Reiter Wert | standard | sonnet | pending |
| 10 | Reiter Papiere | standard | sonnet | pending |
| 11 | Notfallkarte offline | heikel | opus | pending |
| 12 | Garage, Übersicht, Konto | standard | sonnet | pending |
| 13 | Doku-Abgleich und Abschluss | mechanisch | sonnet | pending |

Reihenfolge: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12 → 13. Abhängigkeiten: alles braucht 1; 5 braucht 2, 3 und 4 (Wertverlust aus dem Korridor); 6 braucht 2; 7 braucht 4, 5, 6; 8 braucht 3 und 5; 9 braucht 4; 10 braucht 2 und 6; 11 braucht 10; 12 braucht 5 und 6.

Entscheidungen (entstehen in Phase 1): [ADR-022](../../decisions/022-kosten-und-vertraege.md) Kostenmodell, Verträge, Umlage · [ADR-023](../../decisions/023-wertermittlung.md) Korridor, Regression, Wertfaktoren · [ADR-024](../../decisions/024-stichtage-und-kalender-abo.md) Stichtage und Kalender-Abo · [ADR-025](../../decisions/025-tanken-ausgaben-notfallkarte.md) Eingaben Tanken/Ausgabe, Notfallkarte offline.

Breakpoints, Handy-Regeln und Prüfbreiten gelten unverändert aus Meilenstein 4 ([Archiv-README](../../archive/2026-11/2026-11-18_oberflaeche/README.md) → „Breakpoints und Handy“).

## Kontrakt

### Agenten-Werkzeuge (MCP, neu: 12 → insgesamt 50)

Alle schreibenden Werkzeuge tragen die Herkunftsfelder (`ProvenanceSchema`), melden `result` = `angelegt` | `ergaenzt` | `unveraendert` | `klaerfall` (wie `add_part`) und öffnen bei Widerspruch zu einem bestätigten Wert einen Klärfall. Kein Werkzeug löscht.

| Werkzeug | Scope | Gruppe | Eingabe (Pflicht **fett**) | Ausgabe |
|---|---|---|---|---|
| `upsert_contract` | write | Verträge | **kind**, **provider**, vehicle_id *oder* **garage_id**, contract_number, tariff, starts_on, main_due (`MM-TT`), notice_months, payment_interval, ends_on, allocation (nur Garage), hotline, member_number, note | `{contract_id, result, open_item_ids}` |
| `record_contract_term` | write | Verträge | **contract_id**, **valid_from**, **valid_to**, **amount_eur**, coverage, sf_liability, sf_comprehensive, type_class_liability, type_class_partial, type_class_full, regional_class, declared_km_per_year, deductible_note | `{term_id, result, open_item_ids}` |
| `record_contract_event` | write | Verträge | **contract_id**, **kind**, **occurred_on**, amount_eur, event_status, claim_number, damage_id, note | `{contract_event_id, result, open_item_ids}` |
| `record_fuel` | write | Kosten | **vehicle_id**, **fueled_on**, **odometer_km**, **liters**, **amount_eur**, **is_full**, station | `{fuel_id, result, odometer: {reading_id, result, open_item_id?}, consumption_l_100km}` |
| `record_expense` | write | Kosten | **vehicle_id**, **spent_on**, **category**, **amount_eur**, spread_months (1–36, Standard 1), note | `{expense_id, result, open_item_ids}` |
| `get_cost_summary` | read | Kosten | vehicle_id *oder* garage_id, period (`12m` Standard, `seit_kauf`, `jahr:JJJJ`), group (`block` Standard, `monat`) | siehe Phase 5 |
| `add_valuation` | write | Wert | **vehicle_id**, **valued_on**, **kind**, **amount_eur**, value_type, odometer_km, range_low_eur, range_high_eur, provider, note | `{valuation_id, result, open_item_ids}` |
| `add_market_listing` | write | Wert | **vehicle_id**, **url**, **fetched_on**, **price_eur**, **odometer_km**, **first_registration** (`JJJJ-MM`), **same_drivetrain**, equipment_note, postal_code | `{listing_id, result, counts: bool}` |
| `get_value_summary` | read | Wert | **vehicle_id** | siehe Phase 4 |
| `upsert_accessory` | write | Papiere | **vehicle_id**, **name**, **category**, manufacturer, article_number, bought_on, price_eur, warranty_until, mount_state, storage_location, goes_with_sale | `{accessory_id, result, open_item_ids}` |
| `upsert_permit` | write | Papiere | **vehicle_id**, **kind**, **label**, number, valid_from, valid_until, price_eur | `{permit_id, result, open_item_ids}` |
| `get_papers` | read | Papiere | **vehicle_id** | `{contracts:[…mit terms, events, allocations], deadlines:[…], permits:[…], accessories:[…], emergency:{…}}` |

`attach_document` kennt neue Ziele: `vertrag` (contracts), `vorgang` (contract_events), `ausgabe` (expenses), `tankvorgang` (fuel_logs), `zubehoer` (accessories), `plakette` (permits), `bewertung` (valuations). Neue Prompts: `marktwert_recherche`, `verkaufsakte`.

### Browser-API (`/garage/api/…`, JWT über `ApiAuth::user`, fremd/unbekannt → 404, Fehler über `ApiErrors::respond`)

| Methode | Pfad | Antwort | wie |
|---|---|---|---|
| GET | `vehicles/{id}/costs?period=` | Ausgabe von `get_cost_summary` für das Fahrzeug | `CostService::summary` |
| GET | `garages/{id}/costs?period=` | Ausgabe von `get_cost_summary` für die Garage | `CostService::garageSummary` |
| GET | `vehicles/{id}/value` | Ausgabe von `get_value_summary` | `ValueService::summary` |
| GET | `vehicles/{id}/papers` | Ausgabe von `get_papers` | `PaperService::forVehicle` |
| GET | `vehicles/{id}/deadlines` | `{deadlines:[{kind,title,date,status,explanation,ref}]}` | `DeadlineService::forVehicle` |
| GET | `vehicles/{id}/emergency` | `{vehicle_id,label,plate,fin,insurer,breakdown,as_of}` | `PaperService::emergency` |
| GET | `vehicles/{id}/fuel` | `{fuel:[…absteigend, mit consumption_l_100km]}` | `FuelService::list` |
| GET | `vehicles/{id}/expenses` | `{expenses:[…absteigend]}` | `ExpenseService::list` |
| POST | `vehicles/{id}/fuel` | Body wie `record_fuel` ohne Herkunft → wie `record_fuel`; Herkunft `nutzer`/`bestaetigt`; `lesend` → 403 | `FuelService::recordByHuman` |
| POST | `vehicles/{id}/expenses` | Body `spent_on, category, amount_eur, note?` → wie `record_expense`; `lesend` → 403 | `ExpenseService::recordByHuman` |
| GET | `garages/{id}/calendar-link` | `{url}` (jede Rolle) | `CalendarLink::url` |
| POST | `garages/{id}/calendar-link/rotate` | `{url}`; nur `inhaber`, sonst 403; Audit `calendar.rotate` | `CalendarService::rotate` |
| GET | `/garage/kalender/{slug}.ics` (ohne `/api`) | `text/calendar; charset=utf-8`; ungültig → 404 ohne Text | `CalendarController::feed` |
| POST | `records/{type}/{id}/delete` · `…/restore` | bestehend; neue `type`: `fuel`, `expense`, `contract`, `contract_event`, `valuation`, `listing`, `accessory`, `permit` | `LifecycleService` |

`GET vehicles` und `list_vehicles` bekommen je Fahrzeug `costs: {month_running_cents, month_full_cents, missing:[…]}` und `value: {low_cents, high_cents, median_cents, listings}|null` (Phase 7).

## Finale AK

- [ ] 1. Ein Agent erfasst per MCP Versicherung, Steuer und Club (Fahrzeug- oder Garagen-Vertrag) mit Beitragsperioden und Vorgängen, Tankungen, Ausgaben, Bewertungen, Vergleichsinserate, Zubehör und Plaketten; jedes der zwölf neuen Werkzeuge erkennt eine Wiederholung (`unveraendert`) und macht aus einem Widerspruch zu einem bestätigten Wert einen Klärfall.
- [ ] 2. `get_cost_summary` liefert für Fahrzeug und Garage die vier Blöcke Fixkosten, Betrieb, Werkstatt, Wertverlust je Monat, laufend und mit Wertverlust, pro Monat und pro km, für 12 Monate, seit Kauf oder ein Kalenderjahr, und nennt fehlende Blöcke; Garagen-Verträge werden tagesgenau gleich, nach km oder nach festen Anteilen auf die aktiven Autos umgelegt; der Prüffall aus Phase 5 (Kfz-Steuer 134 €, 310 Tage = 113,81 €) stimmt.
- [ ] 3. `get_value_summary` zeigt Ankerwerte, ab 8 passenden Inseraten Median und mittlere Hälfte, ab 15 den auf km und Alter umgerechneten Korridor, Inserate älter als 60 Tage zählen nicht; acht Wertfaktoren je mit Plus, Minus oder Unbekannt und Fundstelle.
- [ ] 4. Stichtage (Kündigung = Hauptfälligkeit − Kündigungsfrist − 1 Tag, Sonderkündigung nach Beitragserhöhung, Steuer, Plaketten, Garantie) stehen am Fahrzeug und mit den Fälligkeiten im Kalender-Abo der Garage; der Abo-Link ist geheim, jede Rolle kann ihn kopieren, nur der Inhaber erneuert ihn, ein alter Link antwortet danach 404.
- [ ] 5. Die Reiter Kosten, Wert und Papiere, die Garage (Unterhalt, Garagen-Verträge, Unterhalt und Wert je Karte), die Übersicht (Kosten und Wert, Stichtage) und das Konto (Kalender-Abo) entsprechen dem freigegebenen Entwurf in allen Stufen; am Handy (360 px) ohne waagerechtes Scrollen, hell und dunkel.
- [ ] 6. Inhaber und Mitglieder tragen im Browser eine Tankung (mit km-Stand, Liter, Betrag, voll) und eine Ausgabe ein und löschen beides sowie Verträge, Vorgänge, Bewertungen, Inserate, Zubehör und Plaketten mit Rückgängig; `lesend` sieht keine dieser Aktionen und bekommt 403.
- [ ] 7. Die Notfallkarte eines Fahrzeugs öffnet sich nach einmaligem Laden auch ohne Netz; nach dem Abmelden ist sie vom Gerät verschwunden.
- [ ] 8. Akte (Markdown) und Druckansicht enthalten die Abschnitte Verträge, Kosten, Wert, Zubehör und Plaketten.
- [ ] 9. Nutzer B sieht und ändert über keine neue Route und kein neues Werkzeug Daten von Nutzer A (404); Agenten-Tokens erreichen keine Browser-Route.
- [ ] 10. `routes.md`, `models.md`, `code-map.md`, `glossary.md`, `PROJECT.md`, `GAPS.md`, `mcp/instructions.md` nachgezogen.

## Konfidenz-Ausweis

- Geprüft: `open_items.vehicle_id` ist `NULL`-fähig (Migration 006) → Klärfälle an Garagen-Verträgen gehen ohne Schemaänderung.
- Geprüft: `attachments.target_table` ist `VARCHAR(40)` (Migration 017) → neue Anhang-Ziele brauchen nur `DocumentService::TARGETS`.
- Geprüft: `DueCalculator` liefert `effective_date` je Fälligkeit → das Kalender-Abo braucht keine eigene Fälligkeitsrechnung.
- Geprüft: `web/sw.js` hält heute keinen Offline-Cache; Phase 11 baut ihn neu und darf das Teilen-Ziel nicht brechen.
- 🟡 **Löschen an Garagen-Verträgen**: `LifecycleService::lockedRecord` sperrt immer über `vehicle_id`; Garagen-Verträge haben keins. Check in Phase 2: `RecordRepository::findAnyStatus` auf eine Zeile mit `vehicle_id IS NULL` lesen und den Zweig über `VehicleAccess::assertCanWriteGarage` einbauen, bevor `contract` in `RECORD_TABLES` kommt.
- 🟡 **Aktiv-Zeitraum eines Autos** für die Umlage braucht das Datum des Statuswechsels; heute steht es nur im Audit-Log. Phase 1 legt `vehicles.status_since` an; für schon verkaufte Fahrzeuge bleibt es leer, sie zählen dann in keinem Zeitraum mit. Check: `SELECT id, status FROM vehicles WHERE status <> 'aktiv'` nach dem Deploy — Inhaber trägt fehlende Daten ggf. über den Status-Dialog nach.
- 🟡 **Kalender-Abo in fremden Kalendern**: Google und Apple holen Abos selten (Stunden bis zu einem Tag) und verzeihen kleine Formfehler nicht. Check nach dem Deploy: Link in Google Kalender abonnieren und den Kündigungsstichtag dort sehen; vorher `.ics` gegen einen Online-Validator (z. B. icalendar.org) laufen lassen.

Fehlerklassen geprüft: `frameworks/css-architecture` → „Freiraum per Innenabstand reserviert“ ist Prüfpunkt in Phase 8 und 10; `systeme/strato-shared-hosting`, `frameworks/charts`, `frameworks/frontend-architecture` ohne Abschnitt Fehlerklassen; `protokolle/mcp` → „Server nach altem Schema“ nicht einschlägig (Server steht).

## Summary

## Files touched

## Commits

## Deviations from plan

## Follow-ups
