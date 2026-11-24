# 022 — Kosten und Verträge

**Status:** Akzeptiert (2026-11-24) — Meilenstein 5, Phase 1.

## Kontext

Die Akte soll rechnen, was ein Auto kostet. Versicherung, Steuer und Club-Mitgliedschaft sind keine Werkstattrechnungen: sie laufen in Beitragsperioden, haben Fristen und Vorgänge (Schaden, Rückstufung, Kündigung). Manche Verträge gelten für die ganze Garage (ADAC), nicht für ein Auto.

## Entscheidung

- **Verträge sind eigene Datensätze** (`contracts`) mit Beitragsperioden (`contract_terms`) statt Rechnungsbeträgen und Vorgängen (`contract_events`). Ebene Fahrzeug (`vehicle_id`) oder Garage (`vehicle_id NULL`).
- **Garagen-Verträge werden tagesgenau umgelegt:** `gleich` (Betrag ÷ aktive Autos des Tages), `km` (Anteil an den km des Monats), `fest` (`contract_allocations`, Prozent mit Gültigkeit).
- **Kein `contract_items`:** ein Fahrzeug-Vertrag deckt sein Fahrzeug, ein Garagen-Vertrag alle aktiven.
- **Vier Blöcke nach ADAC:** Fixkosten (Vertragsperioden tagesgleich; HU-Ereignisse über 24 Monate; Plaketten über ihre Gültigkeit), Betrieb (Tankungen und Zubehör am Tag; Ausgaben über `spread_months`), Werkstatt (alle Ereignisse außer `kauf` und `hu` am Leistungsdatum, Bruttobetrag), Wertverlust (linear zwischen Kaufpreis und jüngstem Wert).
- **Immer zweimal:** laufend (ohne Wertverlust) und mit Wertverlust. Fehlende Blöcke werden genannt (Versicherung, Steuer, Kraftstoff, Wertverlust).
- **Aktiv** ist ein Auto ab Kaufpreis-Datum (sonst ältestem `registrations.valid_from`) bis `vehicles.status_since`, wenn der Status nicht `aktiv` ist.
- **Abgleich:** die vier Vertragstabellen gleicht `ContractMerger` ab (gleiche Ergebniswörter und Klärfall-Regel wie `RecordMerger`), weil Garagen-Verträge kein Fahrzeug haben.

## Verworfen

- **Beträge nur als Ausgaben:** keine SF-/Typklassen, keine Stichtage.
- **`contract_items`:** kein Fall im Haushalt.
- **`RecordMerger` um Garagen-Zeilen erweitern:** berührt jeden bestehenden Abgleich.

## Konsequenzen

- Kosten werden bei jedem Lesen gerechnet, nie gespeichert.
- Ein verkauftes Fahrzeug ohne `status_since` zählt in keinem Zeitraum; der Inhaber trägt das Datum über den Status-Dialog nach.
