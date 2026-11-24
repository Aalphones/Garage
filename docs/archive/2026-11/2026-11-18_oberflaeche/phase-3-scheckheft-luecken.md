# Phase 3 — Scheckheft-Lücken

Rating: heikel (neuer Rechenweg) · Modell: opus · Status: complete

## Kontext

- `docs/decisions/020-scheckheft-luecken.md` (Definition — verbindlich)
- `docs/decisions/011-faelligkeit-und-kilometer.md` (Fälligkeit „was zuerst eintritt“, Erstintervall ab Erstzulassung)
- `app/src/Domain/DueCalculator.php` (`addMonths`, `SOON_MONTHS` = 3, `SOON_KM` = 3000, `base` für den Startpunkt ohne Ausführung), `DueService.php` (wie `firstRegistration`, Aufgaben mit Regeln und letzte Ausführungen geladen werden), `TaskRepository.php` (`listTasksWithRules`, `lastExecutions` als Vorlage für die neue Abfrage)
- `app/src/Api/EventController.php` aus Phase 2 (`listForVehicle` liefert bisher `gaps: []`)

## Regel (aus ADR-020)

Für jede aktive Aufgabe mit aktueller Regel eines Fahrzeugs:

1. Ausführungen = alle aktiven Ereignis-Positionen mit diesem `task_key` an aktiven Ereignissen, je Ereignis einmal, Datum `performed_on`, km `odometer_km` (darf fehlen), aufsteigend nach Datum.
2. Paare bilden: (Startpunkt, erste Ausführung) und jede (Ausführung i, Ausführung i+1). Startpunkt = Erstzulassung mit `first_months`/`first_km` (fehlt `first_*`, gilt das normale Intervall); ohne bekannte Erstzulassung entfällt das erste Paar.
3. Für ein Paar (p, n): `faellig_datum` = p.Datum + Intervall-Monate (wenn die Regel Monate hat); `faellig_km` = p.km + Intervall-km (wenn die Regel km hat und p.km bekannt ist; Startpunkt hat km 0).
4. Lücke, wenn n.Datum > `faellig_datum` + 3 Monate (`SOON_MONTHS`) **oder** (n.km bekannt und n.km > `faellig_km` + 3.000 km (`SOON_KM`)).
5. Keine Lücke nach der letzten Ausführung — das ist Überfälligkeit und steht im Wartungsplan.
6. Lücken, deren `expected_by` vor `valid_from` der Regel liegt, entfallen.
7. `expected_by` = `faellig_datum`, sonst (nur km-Regel) das Datum von n; `explanation` = ein deutscher Satz mit Rechenweg, z. B. „Regel 24 Monate: nach 09.03.2022 fällig bis 09.03.2024, nächste Ausführung erst 02.03.2026.“ bzw. mit km „… fällig bei 49.800 km, nächste Ausführung bei 55.880 km.“ — Datumsformat wie `DossierFormat`.

## AK

- [x] Neue reine Klasse `app/src/Domain/GapCalculator.php` (keine Datenbank) mit `public static function find(array $rule, list<array{date: string, km: int|null}> $executions, ?string $firstRegistration): list<array{expected_by: string, previous: array{date: string, km: int|null}, next: array{date: string, km: int|null}, explanation: string}>` nach der Regel oben.
- [x] Neue Abfrage `TaskRepository::executions(int $vehicleId, Actor $actor): array<string, list<array{date: string, km: int|null}>>` (Schlüssel `task_key`), Mandantenbedingung wie in `lastExecutions`.
- [x] Neue Methode `DueService::gaps(int $vehicleId, Actor $actor): list<array{task_key, title, expected_by, previous, next, explanation, rule_uncertain: bool}>`, sortiert nach `expected_by` absteigend; `rule_uncertain` = Konfidenz der Regel `unsicher`.
- [x] `GET /garage/api/vehicles/{id}/events` füllt `gaps` damit; Kontrakt in README um `rule_uncertain` ergänzen.
- [x] Handrechnung (Laufzeitprüfung offen, s. Report-Back) im Report-Back für fünf Fälle, jeweils Eingabe und Ergebnis: (a) zwei Ausführungen im Abstand innerhalb Intervall+3 Monate → keine Lücke; (b) Abstand 4 Jahre bei 24-Monats-Regel → eine Lücke; (c) km-Regel 30.000 km, zweite Ausführung 34.000 km später → Lücke; (d) erste Ausführung 5 Jahre nach Erstzulassung bei `first_months` 24 → Lücke; (e) Regel mit `valid_from` nach der erwarteten Fälligkeit → keine Lücke. Lokal mit einem Testfahrzeug über MCP angelegt und über die Route geprüft.

## Checkliste

- [x] `GapCalculator`, `TaskRepository::executions`, `DueService::gaps`, Verdrahtung in `EventController`
- [x] ADR-020 bei Abweichung nachziehen
- [x] Doc-Update: `docs/routes.md` (`gaps`-Felder), `docs/code-map.md` (`GapCalculator` unter Wartungsplan)

## Report-Back

Umgesetzt 2026-11-23: `GapCalculator::find` nach der Regel oben, `TaskRepository::executions` (je Ereignis und Schlüssel einmal per `DISTINCT` über Ereignis-ID), `DueService::gaps` (prüft Lesezugriff, überspringt Aufgaben ohne Regel, sortiert `expected_by` absteigend, bei Gleichstand `task_key`), Route füllt `gaps`. `DueCalculator::CONFIDENCE_UNCERTAIN` ist jetzt öffentlich. Keine Abweichung von ADR-020; `rule_uncertain` stand schon im Kontrakt.

Rechenweg-Satz: „Regel …“ bzw. „Erstintervall …“, die Intervalle der Regel mit „oder“, danach nur das überschrittene Maß („fällig bis <Datum>“ und/oder „bei <km> km“), dann die nächste Ausführung mit Datum und, falls bekannt, km.

Handrechnung (am Code nachvollzogen, nicht ausgeführt):

- (a) Regel 24 Monate, `valid_from` 2015-01-01, keine Erstzulassung; Ausführungen 01.03.2020 und 15.05.2022 → fällig 01.03.2022, Toleranz bis 01.06.2022, 15.05.2022 liegt davor → keine Lücke.
- (b) Regel 24 Monate, Ausführungen 09.03.2018 und 09.03.2022 → fällig 09.03.2020, Toleranz bis 09.06.2020 überschritten → eine Lücke, `expected_by` 2020-03-09, „Regel 24 Monate: nach 09.03.2018 fällig bis 09.03.2020, nächste Ausführung erst am 09.03.2022.“
- (c) Regel 30.000 km (ohne Monate), Ausführungen 10.01.2020 bei 40.000 km und 01.06.2021 bei 74.000 km → fällig bei 70.000 km, Toleranz bis 73.000 km überschritten → Lücke, `expected_by` 2021-06-01 (Datum der nächsten Ausführung), „Regel 30.000 km: nach 10.01.2020 bei 40.000 km fällig bei 70.000 km, nächste Ausführung erst am 01.06.2021 bei 74.000 km.“
- (d) Erstzulassung 20.04.2015, `first_months` 24, `interval_months` 12; erste Ausführung 20.04.2020 bei 60.000 km → fällig 20.04.2017, Toleranz bis 20.07.2017 überschritten → Lücke, `expected_by` 2017-04-20, „Erstintervall 24 Monate: nach Erstzulassung am 20.04.2015 fällig bis 20.04.2017, nächste Ausführung erst am 20.04.2020 bei 60.000 km.“
- (e) wie (b), aber `valid_from` 2021-01-01 → `expected_by` 2020-03-09 liegt davor → keine Lücke.

🟡 Nicht zur Laufzeit geprüft: lokal weder PHP noch MySQL noch Docker; die Prüfung mit Testfahrzeug über MCP und Route entfällt hier (Inhaber testet im Betrieb, CI-Lint abwarten).
