# 009 — Datensatz-Abgleich und Spezifikationen als Fakten

**Status:** Akzeptiert (2026-11-17)

## Kontext

Neben den Stammdaten eines Fahrzeugs (ADR-007) gibt es Listen: Zulassungen, Ausstattung, später Ereignisse, Messwerte, Reifensätze, Teile, Schäden, Rückrufe. Agenten tragen dieselben Angaben mehrfach ein, aus verschiedenen Quellen und mit verschiedener Sicherheit. Ohne Abgleich verdoppelt jeder zweite Import die Liste, und eine abweichende Angabe würde einen bestätigten Wert überschreiben. Technische Spezifikationen (Ölsorte, Füllmenge, Reifendruck …) sind dagegen einzelne Werte je Fahrzeug, wie die Stammdaten.

## Entscheidung

- **Spezifikationen sind Fakten** in `vehicle_facts` mit Feldnamen `spec.<bereich>.<schluessel>` (z. B. `spec.motoroel.viskositaet`); Bereich und Schlüssel passen auf `^[a-z][a-z0-9_]{1,30}$`. Die Einheit steht im Wert („4,3 l“). Damit gilt die Konfliktregel aus ADR-007 unverändert. `vehicle_facts.field` ist dafür auf 120 Zeichen verbreitert. Eingetragen wird über `set_spec`; `set_vehicle_facts` lehnt `spec.…` ab, damit es einen Weg gibt.
- **Datensatz-Abgleich für alle Listen-Tabellen** über eine Klasse `RecordMerger`. Je Tabelle gibt es einen natürlichen Schlüssel (Zulassung: Kennzeichen + gültig ab, Ausstattung: Name). Ein Datensatz mit demselben Schlüssel wird nicht angelegt, sondern abgeglichen: leere Spalten werden ergänzt (`ergaenzt`), nicht bestätigte Werte überschrieben (`aktualisiert`), bestätigte nie — eine Abweichung zu einem bestätigten Wert wird ein Klärfall (`klaerfall`) mit gespeichertem Vorschlag (`open_items.proposal_json`, ADR-010). Eine höhere Konfidenz hebt auch ohne Wertänderung die Herkunft der Zeile an.
- **Tabellen- und Spaltennamen im dynamischen SQL stammen ausschließlich aus der Konstante `RecordMerger::TABLES`;** nie aus einer Eingabe. Werte werden vor dem Vergleich zu Text normalisiert (Cent als Ganzzahl-Text, Dezimal mit festen Nachkommastellen, Boolean `"1"`/`"0"`), damit ein zweiter identischer Aufruf `unveraendert` meldet.
- **Herkunftsblock** in jeder Listen-Tabelle in gleicher Form: `source`, `source_url`, `source_document_id`, `confidence`, `status` (`aktiv`/`geloescht`), `status_reason`, `actor`, `user_id`, `created_at`, `updated_at`.

## Verworfen

- **Eigene Tabelle `vehicle_specs`:** bräuchte eine zweite Konfliktregel neben `vehicle_facts`; der Fall „0W-20 gegen 5W-30“ ist genau der Fakt-Fall.
- **Überschreiben ohne Schlüssel:** jeder Import legte jede Position erneut an; ein bestätigter Wert wäre nicht geschützt.

## Konsequenzen

- Jede neue Listen-Tabelle braucht einen Eintrag in `RecordMerger::TABLES` (Schlüssel, abgleichbare Spalten) und in `RecordRepository` (Sortierung); der Abgleich selbst bleibt unverändert.
- Eine Angabe lässt sich nicht auf „leer“ zurücksetzen: `null` heißt „nicht angegeben“ und wird nicht verglichen. Eine Korrektur eines bestätigten Werts geht über einen Klärfall, den ein Mensch löst.
- Die Herkunft einer Zeile (`source`, `source_url`, `confidence`) ist die der letzten Änderung; die Konfidenz wird nur angehoben, nie gesenkt.
