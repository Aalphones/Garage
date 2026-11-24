# 023 — Wertermittlung

**Status:** Akzeptiert (2026-11-24) — Meilenstein 5, Phase 1.

## Kontext

Die Akte soll zeigen, was ein Auto wert ist — für Wertverlust in der Kostenrechnung und für den Verkauf. Es gibt keine amtliche Quelle; ein Gutachten oder eine bezahlte Bewertungs-API kommt nicht infrage.

## Entscheidung

- **Kein Gutachten, keine fremde API.** Grundlage sind Ankerwerte (`valuations`; der Kaufpreis ist eine Bewertung der Art `kaufpreis`) und Vergleichsinserate mit URL und Abrufdatum (`market_listings`).
- **Passung entscheidet der Agent beim Ablegen:** `same_drivetrain` sagt, ob Motor und Getriebe passen.
- **Ein Inserat zählt,** wenn `same_drivetrain`, Erstzulassungsjahr ±1, km ±25 % des eigenen geschätzten Stands und höchstens 60 Tage alt.
- **Ab 8 zählenden Inseraten:** Median und mittlere Hälfte (Rang `round(q·(n−1))` auf aufsteigend sortierten Preisen).
- **Ab 15:** lineare Regression Preis ~ km + Alter in Monaten; Mitte = Vorhersage für das eigene Auto, Band = Vorhersage + 1. und 3. Quartil der Abweichungen; bei nicht lösbarem Gleichungssystem zurück auf den Median.
- **Acht Wertfaktoren** mit Plus/Minus/Unbekannt und Fundstelle (Regeln in Phase 4 des Plans).
- **Neue Fakten** `unfallfrei` (`ja`/`nein`) und `halterzahl` (1–20) in `VehicleService::FIELDS`.

## Verworfen

- **Halterzahl aus Zulassungen zählen:** der Halter ist oft leer.
- **Motor-/Getriebecode im Inserat vergleichen:** Inserate nennen sie selten.

## Konsequenzen

- Angebotspreise sind keine Verkaufspreise — die Oberfläche sagt das am Korridor.
- Die Qualität hängt an der Sorgfalt des Agenten beim Ablegen der Inserate.
