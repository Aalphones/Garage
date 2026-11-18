# 020 — Scheckheft-Lücken

**Status:** Akzeptiert (2026-11-18) — Meilenstein 4, Planung.

## Kontext

Das Konzept verlangt „Lücken rot markiert“ im Scheckheft und zählt Scheckheft-Lücken zu den berechneten Werten. Bisher rechnet der Server keine Lücken; der Wartungsplan zeigt nur, was ab der letzten Ausführung fällig ist.

## Entscheidung

- **Lücke = eine Ausführung kam zu spät.** Je Aufgabe mit aktueller Regel werden die Abstände zwischen aufeinanderfolgenden Ausführungen geprüft, dazu der Abstand von der Erstzulassung (Erstintervall) zur ersten Ausführung. Zu spät heißt: später als Fälligkeit + 3 Monate oder mehr als Fälligkeits-km + 3.000 km — dieselben Schwellen wie „bald“ (`DueCalculator::SOON_MONTHS`/`SOON_KM`).
- **Nach der letzten Ausführung keine Lücke** — das ist Überfälligkeit und gehört in den Wartungsplan.
- Lücken vor `valid_from` der Regel zählen nicht; Lücken aus einer unsicheren Regel tragen `rule_uncertain`.
- Gerechnet beim Lesen (`GapCalculator`, rein, ohne Datenbank), nie gespeichert.

## Verworfen

- **Lücke = Jahr ohne Ereignis:** meldet jedes ruhige Jahr eines Wenigfahrers.
- **Ohne Toleranz:** eine Inspektion zwei Wochen nach Termin wäre schon eine Lücke.

## Konsequenzen

- Eine falsche oder fehlende Zuordnung von Positionen zu `task_key` erzeugt Schein-Lücken; der Rechenweg steht deshalb an jeder Lücke.
- Agenten sehen die Lücken in Meilenstein 4 noch nicht (kein Tool); kann später in `list_events` oder `get_dossier` einfließen.
