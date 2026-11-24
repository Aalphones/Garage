# Phase 5 — Kostenrechnung

Status: complete (2026-11-24)

Rating: heikel · Modell: opus (tagesgenaue Verteilung, Umlage, Grenzfälle an Monats- und Zeitraumgrenzen)

## Kontext

- Plan-README → Kontrakt (`get_cost_summary`, `GET vehicles/{id}/costs`, `GET garages/{id}/costs`)
- ADR-022 (Blöcke, Verteilung, Aktiv-Zeitraum, Umlage), Konzept → „Gesamtkosten und Unterhalt“ und „Umlage auf alle aktiven Autos“
- Muster reine Rechenklasse: `app/src/Domain/DueCalculator.php`; Datumsrechnung mit `DateTimeImmutable` in Europe/Berlin wie `OdometerService`
- Eingangsdaten: `ContractRepository` (Phase 2: Verträge, Perioden, Anteile), `FuelService`/`ExpenseService` (Phase 3), `EventRepository` (Ereignisse mit `kind`, `performed_on`, `gross_cents`), Plaketten und Zubehör (`RecordRepository::listForVehicle`), `ValueService::purchase`/`current` (Phase 4), Kilometerstände `OdometerService::validReadings` + `OdometerForecast::compute`, Fahrzeuge der Garage mit `status`, `status_since`, `registrations.valid_from`
- Fehlerklassen: keine einschlägig

## AK

- [x] `CostCalculator` (rein, ohne Datenbank) bekommt Zeitraum, Fahrzeuge mit Aktiv-Zeitraum und alle Posten und liefert je Fahrzeug und für die Garage: je Monat Cent je Block (`fix`, `run`, `shop`, `loss`), Summen, `month_running_cents` (Summe ohne `loss` ÷ Monate), `month_full_cents`, `per_km_full_cents` (zwei Nachkommastellen als Text in Euro), `km`, `missing`, `posts` (je Block die Posten mit Betrag im Zeitraum und Herkunft) und je Garagen-Vertrag den Anteil des Fahrzeugs (`shares:[{contract_id, provider, cents}]`).
- [x] Zeitraum: `12m` = heute − 12 Monate + 1 Tag bis heute; `seit_kauf` = Beginn des Aktiv-Zeitraums bis heute; `jahr:JJJJ` = 1.1.–31.12. (Zukunft gekappt auf heute). Monate = Kalendermonate, die den Zeitraum schneiden; „pro Monat“ = Summe ÷ (Tage im Zeitraum ÷ 30,4375).
- [x] Verteilung tagesgenau: Betrag ÷ Tage der Periode (inklusive beider Enden) je Tag; nur Tage im Zeitraum **und** im Aktiv-Zeitraum zählen. Vertragsperioden (`contract_terms`) und Plaketten (`valid_from`–`valid_until`; unbegrenzt → ganzer Betrag am `valid_from`) → `fix`; HU-Ereignisse (`kind = hu`) → `fix` über 24 Monate ab `performed_on`; übrige Ereignisse außer `kauf` → `shop` am `performed_on` (`gross_cents`, sonst `net_cents + vat_cents`, sonst 0 und Hinweis); Tankungen und Zubehör (`bought_on`, `price_cents`) → `run` am Tag; Ausgaben → `run` über `spread_months` Monate ab `spent_on`.
- [x] Wertverlust: Start = `ValueService::purchase` (Betrag, Datum), Ende = `ValueService::current`; Tagesbetrag = (Start − Ende) ÷ Tage zwischen beiden Daten, verteilt nur auf diese Tage; fehlt eins von beiden oder Ende ≤ Start-Datum → `loss` fehlt (`missing` enthält `Wertverlust`).
- [x] Garagen-Verträge: Tagesbetrag × Anteil des Fahrzeugs am Tag. `gleich` = 1 ÷ Zahl der an diesem Tag aktiven Fahrzeuge der Garage; `km` = km des Fahrzeugs im Monat ÷ km aller aktiven Fahrzeuge im Monat (beide 0 → wie `gleich`); `fest` = `share_percent` der gültigen Zeile aus `contract_allocations` (fehlt → 0, Hinweis in `posts`).
- [x] Aktiv-Zeitraum: Beginn = Datum der jüngsten Bewertung `kaufpreis`, sonst ältestes `registrations.valid_from`, sonst Beginn des Zeitraums; Ende = `status_since`, wenn Status ≠ `aktiv` (fehlt `status_since` bei Status ≠ `aktiv` → Fahrzeug zählt nicht), sonst heute.
- [x] km im Zeitraum: Stand am Ende − Stand am Anfang; ein Stand an einem Tag = lineare Interpolation zwischen den umgebenden gültigen Ständen, nach dem letzten Stand Prognose (`OdometerForecast`), vor dem ersten der erste Stand. 0 km → `per_km_full_cents = null`.
- [x] `missing`: `Versicherung` (keine Fahrzeug-Vertragsperiode `kind = versicherung`, die den Zeitraum schneidet), `Steuer` (dito `steuer`; entfällt bei Fakt `kraftstoff` = „Elektro“), `Kraftstoff` (keine Tankung im Zeitraum), `Wertverlust` (s. o.).
- [x] Prüffall (im Report-Back durchrechnen): Steuer-Periode 08.01.2026–07.01.2027 (365 Tage inklusive) mit 134,00 €, Zeitraum endet am 13.11.2026 → 310 Tage im Zeitraum (24 + 28 + 31 + 30 + 31 + 30 + 31 + 31 + 30 + 31 + 13) → 134,00 € × 310 ÷ 365 = 113,81 € (gerundet auf Cent, kaufmännisch). Rundung nur am Ende je Posten, nicht je Tag.
- [x] `get_cost_summary` (Fahrzeug **oder** Garage, `period`, `group`) und die beiden Routen liefern dieselbe Ausgabe; Garage: Summe der aktiven Fahrzeuge (inkl. umgelegter Anteile) plus `vehicles:[{vehicle_id, label, month_running_cents, month_full_cents, missing, months:[{month, running_cents, full_cents}]}]` für das gestapelte Diagramm und `garage_contracts:[{contract_id, kind, provider, amount_year_cents, allocation, explanation, per_vehicle:[{vehicle_id, year_cents}], source, confidence}]` (Betrag/Jahr aus der heute gültigen Periode, Anteil heute).

## Checkliste

- [x] `app/src/Domain/CostCalculator.php` (rein), `app/src/Domain/CostService.php` (`summary`, `garageSummary`, `vehicleTotals` für Phase 7).
- [x] `get_cost_summary` in `app/src/Mcp/Tools/CostTools.php`; `CostController::costs`, `CostController::garageCosts` + Routen (`garages/{id}/costs`: jede Rolle der Garage, fremd → 404).
- [x] Rechenweg-Texte: je Block ein Satz (z. B. „Kfz-Steuer 134,00 € für 08.01.2026–07.01.2027, davon 310 Tage im Zeitraum = 113,81 €“), damit die Oberfläche sie im Popover zeigt.
- [x] Doku: `docs/routes.md`, `docs/code-map.md`, `docs/glossary.md` (Block, laufend, Vollkosten, Umlage, Aktiv-Zeitraum).
- [ ] Syntax: CI-Lauf nach dem Push ansehen (lokal kein PHP).

## Report-Back

- Prüffall Steuer: Periode 08.01.2026–07.01.2027 = 365 Tage; Zeitraum endet 13.11.2026 → `daysPerMonth` zählt 24 + 28 + 31 + 30 + 31 + 30 + 31 + 31 + 30 + 31 + 13 = 310 Tage; 13.400 Cent × 310 ÷ 365 = 11.380,82 → ganzzahlig kaufmännisch gerundet (`divideRounded`) 11.381 Cent = 113,81 €. Rechenweg-Text: „Kfz-Steuer (…) 134,00 € für 08.01.2026–07.01.2027, davon 310 Tage im Zeitraum = 113,81 €.“
- Rundung je Posten einmal; die Monatswerte verteilen den gerundeten Betrag nach größtem Rest, Monate und Summe passen auf den Cent.
- Entscheidung: „pro Monat“ teilt durch die Tage im Zeitraum **und** im Aktiv-Zeitraum des Autos (ein im Zeitraum gekauftes Auto würde sonst zu billig erscheinen); bei der Garage durch die Tage des Zeitraums. Auch „km im Zeitraum“ zählt nur im Aktiv-Zeitraum.
- Entscheidung: Ereignis-Betrag = brutto, sonst netto + MwSt. nur wenn beide da sind, sonst 0 mit Hinweis im Rechenweg. Plaketten und Zubehör ohne Preis oder Datum erzeugen keinen Posten.
- `seit_kauf` ohne bekannten Beginn rechnet wie `12m` und sagt es in `period.note`; für die Garage beginnt `seit_kauf` am frühesten Aktiv-Beginn.
- `group=monat` liefert `posts` je Monat (`YYYY-MM`) mit `month_cents` je Posten; `group=block` je Block.
- `garage_contracts.per_vehicle` bei Umlage `km`: Anteil nach km im gewählten Zeitraum (heute gibt es keinen Monats-km-Wert).
- 🟡 PHP-Syntax lokal ungeprüft (kein PHP, Prüfung per php-wasm abgelehnt) — CI-Lauf ansehen.
