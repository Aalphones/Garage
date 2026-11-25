# Phase 7 — Akte, Fahrzeugliste, Prompts, Server-Anweisungen

Rating: standard · Modell: sonnet

## Kontext

- Plan-README → Kontrakt (Felder `costs`, `value` in `GET vehicles`/`list_vehicles`; Prompts `marktwert_recherche`, `verkaufsakte`)
- `app/src/Domain/DossierService.php` (`document` → Liste der Abschnitte, Bauteile `table`, `text`, `items`, `heading`), `app/src/Domain/DossierFormat.php` (Euro, Datum), `app/templates/akte-druck.php` (rendert dieselben Abschnitte — keine Änderung nötig, prüfen)
- `app/src/Mcp/Tools/VehicleTools.php` (`list_vehicles`), `app/src/Api/VehicleController.php` (Garagenliste für `GET vehicles`), `DueService::summary` als Muster für eine Zusatzzeile je Fahrzeug
- `app/src/Mcp/Prompts.php` (Aufbau `beleg_erfassen`), `app/mcp/instructions.md`, `app/mcp/leitfaden-erfassung.md`
- `CostService::vehicleTotals` (Phase 5), `ValueService::summary`/`current` (Phase 4), `DeadlineService` (Phase 6), `PaperService` (Phase 2)
- Fehlerklassen: keine einschlägig

## AK

- [x] Akte und Druckansicht haben nach „Rückrufe“ die Abschnitte **Verträge** (Tabelle Art · Anbieter · Nummer · Beitrag/Jahr · Hauptfälligkeit · Kündigungsstichtag; darunter offene Vorgänge als Liste), **Kosten** (letzte 12 Monate: Tabelle Block · Betrag · je Monat; Zeile „pro km“; Zeile „Fehlt: …“), **Wert** (Ankerwerte als Tabelle, Korridor als Satz mit Zahl der Inserate und dem Hinweis „Angebotspreise“, Wertfaktoren als Liste „+/−/?“), **Zubehör und Plaketten** (zwei Tabellen). Leere Bereiche als kursiver Satz wie die bestehenden Abschnitte.
- [x] `list_vehicles` und `GET vehicles` tragen je aktivem Fahrzeug `costs: {month_running_cents, month_full_cents, missing}` (Zeitraum `12m`) und `value: {low_cents, high_cents, median_cents, listings}|null`; für nicht aktive Fahrzeuge beide `null`.
- [x] Prompt `marktwert_recherche` (Argument `fahrzeug`): Anleitung — `get_vehicle` lesen, Inserate mit gleichem Motor und Getriebe, EZ ±1 Jahr, km ±25 % suchen, je Inserat `add_market_listing` mit URL, Abrufdatum heute und `same_drivetrain`, am Ende `get_value_summary` zusammenfassen.
- [x] Prompt `verkaufsakte` (Argument `fahrzeug`): `get_dossier`, `get_value_summary`, `list_open_items`, `get_papers` lesen; Unterlagen, Wertargumente (Wertfaktoren plus), offene Punkte (minus/unbekannt, Klärfälle) und Stichtage nennen.
- [x] `app/mcp/instructions.md` um Regeln ergänzt: Kaufpreis = `add_valuation` Art `kaufpreis`; Beitragsrechnung = `record_contract_event` + `record_contract_term`; Steuerbescheid = Vertrag `steuer` + Periode; ADAC & Co. als Garagen-Vertrag; Inserate immer mit URL und Abrufdatum; Tankbeleg = `record_fuel`.

## Checkliste

- [x] `DossierService`: Dienste `ContractService`/`PaperService`, `CostService`, `ValueService` im Konstruktor; vier private Methoden `contractBlocks`, `costBlocks`, `valueBlocks`, `paperBlocks`; Reihenfolge in `document`.
- [x] `VehicleTools`/`VehicleController`: Felder ergänzen über `CostService::vehicleTotals` und `ValueService::current`/`summary`.
- [x] `Prompts.php`: zwei Prompts; `instructions.md`, `leitfaden-erfassung.md` ergänzen.
- [ ] Markdown der Golf-Akte einmal nach dem Deploy über `GET vehicles/{id}/dossier` holen und die vier Abschnitte ansehen (Report-Back).
- [x] Doku: `docs/code-map.md` (Absatz Akte), `docs/routes.md` (Feldliste `GET vehicles`).
- [ ] Syntax: CI-Lauf nach dem Push ansehen.

## Report-Back

Phase 7 umgesetzt (Rating standard, Modell sonnet). Offen: Akten-Markdown der Golf-Akte nach Deploy ansehen, CI-Lauf (PHP-Syntax lokal ungeprüft, kein PHP).

- `DossierService`: vier neue Abschnitte nach „Rückrufe“ (Verträge, Kosten, Wert, Zubehör und Plaketten), Dienste `PaperService`/`CostService`/`ValueService`; Druckvorlage unverändert (rendert Blöcke generisch). Tabellenzellen tragen kein `**fett**` (Vorlage maskiert sie).
- Liste: `VehicleService::list` ergänzt `costs` (`CostService::vehicleTotals` je Garage einmal) und `value` (neu `ValueService::corridorToday`, nur Korridor, ohne Faktoren) für aktive Fahrzeuge; `GET vehicles` und `list_vehicles` teilen die Ausgabe. `CostCalculator::BLOCK_NAMES` jetzt public.
- „Kündigungsstichtag“ aus `DeadlineService` (Art kuendigung), Beitrag/Jahr wie `share.eur_per_year` auf 365 Tage gerechnet; „je Monat“ je Block = Betrag × (Monatssumme ÷ Gesamtsumme), derselbe Divisor wie die Kostenrechnung.
- Prompts `marktwert_recherche`, `verkaufsakte`; `instructions.md` Regeln 12–13, `leitfaden-erfassung.md` Abschnitt 4b.
