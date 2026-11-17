# 011 — Kilometer vorwärts, strittige Stände; Fälligkeit beim Lesen

**Status:** Akzeptiert (2026-11-17) — Teil 1 (Kilometer, Phase 2). Teil 2 (Fälligkeitsrechnung, Kilometerprognose) folgt mit Phase 3.

## Teil 1 — Kilometer laufen vorwärts

### Kontext

Kilometerstände kommen aus Werkstattrechnungen, vom Nutzer und aus Recherche; Tippfehler, vertauschte Ziffern und Tachotausch gibt es. Ein falscher Stand würde die Kilometerprognose und damit jede Fälligkeit verfälschen. Löschen oder stilles Überschreiben scheidet aus (Kernregeln: Herkunft ist Pflicht, nichts wird hart gelöscht).

### Entscheidung

- **Ein Stand ist `gueltig`, `strittig` oder `verworfen`** (`odometer_readings.reading_status`). Nur gültige Stände zählen für die Prognose (`OdometerService::validReadings`).
- **Vorwärts-Regel, in der Abfrage geprüft:** Ein neuer Stand widerspricht gültigen Ständen, die früher oder am selben Tag datiert und größer sind, oder später datiert und kleiner. Ohne Widerspruch wird er `gueltig` (`angelegt`). Mit Widerspruch wird er als `strittig` gespeichert und öffnet einen Klärfall (`konflikt`, `ref_table = odometer_readings`, `proposal_json = {"conflicts_with": [<id>, …]}`); das Ergebnis ist `klaerfall`. Ein größerer Stand am selben Tag ist kein Widerspruch.
- **Derselbe Stand (Datum + Wert) noch einmal** legt nichts an (`unveraendert`); eine höhere Konfidenz hebt die Herkunft (`aktualisiert`). Ist der vorhandene Stand strittig, meldet der Aufruf weiter `klaerfall` mit demselben Klärfall.
- **Auflösen nur durch einen Menschen** (Phase 5): „Neuer Wert gilt“ macht den strittigen Stand `gueltig` und die Widersprechenden `verworfen`; „Bisheriger Wert gilt“ und „Verwerfen“ setzen den strittigen Stand auf `verworfen`.
- **Ereignisse tragen ihren Stand mit:** `odometer_km` an einem Werkstattereignis legt den Stand zum Leistungsdatum über denselben Weg an und verknüpft ihn mit dem Ereignis (`event_id`). Grenzen: 0 bis 2.000.000 km.

### Verworfen

- **Kleineren Stand ablehnen:** ein echter Tachotausch oder ein Tippfehler im bisherigen Stand wäre nie korrigierbar; der Agent bekäme einen Fehler statt einer Entscheidung des Menschen.
- **Neuesten Stand gewinnen lassen:** ein Tippfehler im jüngsten Beleg verfälscht still jede Prognose.

### Konsequenzen

- Die Prognose in Phase 3 liest nur `validReadings`; ein strittiger Stand verändert keine Fälligkeit, bis ein Mensch entschieden hat.
- Ein vorhandener gültiger Stand wird nie automatisch verdächtigt: widerspricht ein neuer Stand, ist der neue strittig, nicht der alte.
