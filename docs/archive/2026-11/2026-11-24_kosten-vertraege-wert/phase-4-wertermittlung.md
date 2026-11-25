# Phase 4 — Wertermittlung

Rating: heikel · Modell: opus (Rechenverfahren mit Grenzfällen)

## Kontext

- Plan-README → Kontrakt (`add_valuation`, `add_market_listing`, `get_value_summary`, `GET vehicles/{id}/value`, Lösch-Typen `valuation`, `listing`)
- ADR-023 (Regeln), Konzept `docs/design/konzept-garage.md` → „Wertermittlung in der App“
- Muster reine Rechenklasse ohne Datenbank: `app/src/Domain/DueCalculator.php`, `app/src/Domain/GapCalculator.php` (Eingaben als Arrays, Ausgabe mit Rechenweg in Worten)
- Eingangsdaten: `DueService::forecastFor` (km heute geschätzt), `DueService::dueItems` (Fälligkeiten), `DueService::gaps` (Scheckheft-Lücken), `VehicleService::get` (Fakten `erstzulassung`, `unfallfrei`, `halterzahl`, Ausstattung), `MeasurementService` (Profiltiefe, Größe `profiltiefe` — Name im Code prüfen), `DamageService::listRecalls`
- Muster Datensatz + Werkzeug: `PartService`, `PartTools`; Abgleich `RecordMerger::TABLES`
- Fehlerklassen: keine einschlägig

## AK

- [x] `add_valuation` (Schlüssel `valued_on` + `kind` + `provider`, leer = `''`) und `add_market_listing` (Schlüssel `url`; erneuter Abruf derselben URL aktualisiert Preis, km und `fetched_on` → `ergaenzt` bzw. bei bestätigtem Altwert wie üblich) über `RecordMerger`. `add_market_listing` antwortet zusätzlich `counts` (zählt das Inserat heute für den Korridor).
- [x] `ValueCalculator::corridor(array $listings, array $own, string $today): array` — reine Klasse. `$own = {first_registration_year, km_today, age_months}`. Zählend: `same_drivetrain`, `|EZ-Jahr − eigenes| ≤ 1`, `|km − km_today| ≤ 0,25 × km_today`, `fetched_on ≥ today − 60 Tage`. Ergebnis `{counting, needed_for_corridor: 8, needed_for_regression: 15, method: none|median|regression, median_cents, low_cents, high_cents, explanation}`; `none` bei < 8.
- [x] Median/Quartile: aufsteigend sortieren, Wert an Index `round(q × (n − 1))` für q = 0,25 / 0,5 / 0,75. Prüffall: Preise 11.300, 11.400, 11.600, 11.800, 11.950, 12.200, 12.450, 12.650, 12.900 → Median 11.950, Band 11.600–12.450.
- [x] Regression ab 15 zählenden: Kleinste Quadrate `preis = a + b·km + c·alter_monate` (3×3-Normalgleichungen, Gauß mit Pivotsuche); Mitte = Vorhersage für das eigene Auto; Band = Mitte + 1. bzw. 3. Quartil der Abweichungen (Preis − Vorhersage) der zählenden Inserate; Determinante |det| < 1e-9 → `method: median`.
- [x] Verlauf: für jedes Monatsende der letzten 24 Monate, an dem mit den bis dahin abgerufenen Inseraten (Abruf ≤ Monatsende und ≥ Monatsende − 60 Tage, km-Vergleich gegen den Stand zu diesem Datum laut Prognose bzw. Messung) ≥ 8 zählen, ein Punkt `{month, median_cents, low_cents, high_cents}`; dazu alle Ankerwerte als Punkte.
- [x] Wertfaktoren, je `{key, label, verdict: plus|minus|unbekannt, detail, ref}` (`ref` = Reiter der Oberfläche: `scheckheft`, `wartung`, `uebersicht`, `messwerte`):
  1. `scheckheft` — keine Lücke aus `DueService::gaps` → plus; Lücken → unbekannt („Lücke: …“, erste Lücke nennen).
  2. `hu` — Fälligkeit der Aufgabe HU (Aufgaben-Schlüssel im Code prüfen, z. B. `hu`): ≥ 12 Monate entfernt → plus, < 12 → minus, keine Regel → unbekannt.
  3. `unfallfrei` — Fakt `ja` → plus, `nein` → minus, fehlt → unbekannt.
  4. `halterzahl` — ≤ 2 → plus, > 2 → minus, fehlt → unbekannt.
  5. `profiltiefe` — jüngste Messung, kleinster Wert der Räder: ≥ 4 mm plus, < 4 mm minus, keine → unbekannt.
  6. `arbeiten` — eine Fälligkeit `overdue` oder mit `effective_date` ≤ heute + 12 Monate → minus (Titel nennen), sonst plus, keine Regeln → unbekannt.
  7. `rueckrufe` — ein Rückruf mit Status `offen` → minus, sonst plus.
  8. `ausstattung` — mindestens ein Ausstattungs-Eintrag → plus, sonst unbekannt.
- [x] `get_value_summary` / `GET vehicles/{id}/value` → `{anchors:[…], corridor:{…}, history:[…], listings:[…mit counts, reason_not_counting: drivetrain|year|km|age|null], factors:[…], note: "Angebotspreise sind keine Verkaufspreise."}`.
- [x] `ValueService::current(int $vehicleId, Actor): ?array{amount_cents, on, source: corridor|valuation}` — jüngster Wert für Phase 5: der jüngere von (Korridor-Mitte heute) und (jüngste Bewertung außer `kaufpreis`); keiner → `null`. `ValueService::purchase(...)`: jüngste Bewertung `kaufpreis` oder `null`.
- [x] Lösch-Typen `valuation`, `listing`; `attach_document` kennt `bewertung` → `valuations`.

## Checkliste

- [x] `RecordMerger::TABLES` + `valuations`, `market_listings`.
- [x] `app/src/Domain/ValueCalculator.php` (rein), `app/src/Domain/ValueService.php` (liest, ruft Rechner), `app/src/Domain/ValuationService.php` (schreibt Bewertungen und Inserate).
- [x] `app/src/Mcp/Tools/ValueTools.php` (Gruppe `Wert` nach `Kosten`).
- [x] `app/src/Api/ValueController.php` + Route; `LifecycleService::RECORD_TABLES` + `valuation`, `listing`; `DocumentService::TARGETS` + `bewertung`.
- [x] Prüffall Median als Kommentar-freies Beispiel in der Report-Back-Sektion durchrechnen (kein Test-Framework, ADR-004).
- [x] Doku: `docs/routes.md`, `docs/code-map.md`, `docs/glossary.md` (Marktkorridor, Ankerwert, Wertfaktor).
- [ ] Syntax: CI-Lauf nach dem Push ansehen (kein PHP lokal).

## Report-Back

Umgesetzt 2026-11-24. `ValueCalculator` (rein: `corridor`, `reasonNotCounting`, `ownAt`, `history`, `factors`, `monthsBetween`), `ValuationService` (`addValuation`, `addListing`), `ValueService` (`summary`, `current`, `purchase`, `countsToday`), `ValueTools` (Gruppe `Wert` nach `Kosten`), `ValueController::summary` + Route `GET vehicles/{id}/value`; Register in `RecordMerger::TABLES`, `RecordRepository::ORDER`, `LifecycleService::RECORD_TABLES` (`valuation`, `listing`), `DocumentService::TARGETS` (`bewertung`).

Prüffall Median: Preise aufsteigend 11.300, 11.400, 11.600, 11.800, 11.950, 12.200, 12.450, 12.650, 12.900 (n = 9). Rang `round(q·8)`: q = 0,25 → 2 → 11.600; q = 0,5 → 4 → 11.950; q = 0,75 → 6 → 12.450. Ergebnis Median 11.950, Band 11.600–12.450 — wie gefordert.

Regression gegengerechnet (Python-Nachbau derselben Schritte, 20 Inserate mit bekanntem Zusammenhang): Vorhersage 10.965 € gegen wahre 11.000 €; km und Alter im Gleichlauf → kein Ergebnis → Median.

Abweichungen:
- Die Normalgleichungen laufen auf **standardisierten** km und Monaten (Mittelwert 0, Streuung 1) und durch n geteilt. Mit Rohwerten (km² ≈ 10¹⁰) sagt die Schwelle |det| < 1e-9 nichts; so bedeutet sie „km und Alter laufen praktisch gleich“. Streut km oder Alter gar nicht, gilt ebenfalls der Median.
- Alter eines Inserats = ganze Monate von Erstzulassung (Monatserster) bis Abrufdatum; das eigene Alter = Monate von der Erstzulassung bis heute bzw. bis zum Monatsende im Verlauf.
- Fehlt am eigenen Auto Erstzulassung oder km-Stand, zählt kein Inserat (Grund `year` bzw. `km`), die Erklärung nennt das.
- Ein Inserat mit Abrufdatum nach „heute“ zählt nicht (Grund `age`) — nötig für den Verlauf.
- `add_market_listing` antwortet zusätzlich `open_item_ids` (Klärfall bei bestätigtem Altwert). Ein erneuter Abruf eines nicht bestätigten Inserats meldet wie jeder Abgleich `aktualisiert`.
- Verlauf: Punkte `{type: korridor, month, on, median_cents, low_cents, high_cents}` für die 24 abgeschlossenen Monate vor heute, Ankerwerte als `{type: anker, month, on, kind, amount_cents}`, nach Datum aufsteigend.
- `ValueService::current`: am selben Tag gilt die Bewertung vor dem Korridor. `purchase` liefert `{amount_cents, on}`.
- Wertfaktor `rueckrufe` und `ausstattung` verweisen auf den Reiter `uebersicht`.
