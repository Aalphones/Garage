# 007 — Fahrzeugfakten als Zeilen mit Herkunft

**Status:** Akzeptiert (2026-11-17)

## Kontext

Jede Angabe zu einem Fahrzeug braucht Herkunft (Beleg, Nutzer, Recherche, Agent, Werkstatt), Konfidenz und den Akteur, und ein Widerspruch zu einem bestätigten Wert darf diesen nie überschreiben (AGENTS.md, Regeln 3–4). Agenten tragen Stammdaten feldweise und aus unterschiedlichen Quellen ein.

## Entscheidung

- **Stammdaten sind Zeilen in `vehicle_facts`** (ein Wert je Feld und Zeitpunkt), nicht Spalten an `vehicles`. `vehicles` trägt nur Anzeigename, Status und Garage. Jede Faktenzeile hat Herkunft, Konfidenz, Akteur, `status` (`aktuell`, `abgeloest`, `strittig`) und `garage_id`; der Wert steht immer als Text, den Typ prüft `VehicleService`.
- **Konfliktregel im Code, je Feld:** kein aktueller Wert → neue Zeile `aktuell` (`angelegt`); gleicher Wert und gleiche oder niedrigere Konfidenz → nichts schreiben (`unveraendert`); gleicher Wert, höhere Konfidenz → alte `abgeloest`, neue `aktuell` (`aktualisiert`); anderer Wert, aktueller nicht `bestaetigt` → ebenso (`aktualisiert`); anderer Wert gegen einen bestätigten → neue Zeile `strittig` daneben plus Klärfall in `open_items` (`klaerfall`). Meldet jemand denselben Widerspruch noch einmal, bleibt es beim bestehenden Klärfall.
- **Eine Anfrage ist eine Transaktion** (Fahrzeugzeile gesperrt, alle Felder vorab validiert); jede Schreibung erzeugt einen `audit_log`-Eintrag mit vorher/nachher.
- **`open_items` und `audit_log` entstehen schon in Meilenstein 1** in Endform, weil die Konfliktregel beide braucht. Tools zum Lesen und Lösen kommen in Meilenstein 2; Lösen bleibt Menschen vorbehalten.

## Verworfen

- **Spalten an `vehicles` plus JSON mit Herkunft:** der Widerspruch „daneben speichern“ bräuchte ohnehin eine Nebentabelle, und jede neue Angabe wäre eine Migration.

## Konsequenzen

- Eine Abfrage „Fahrzeugdaten“ liest immer die Zeilen mit `status = 'aktuell'`; Verlauf und Widersprüche bleiben erhalten.
- Ein schwächerer Wert ersetzt einen nicht bestätigten stärkeren (`wahrscheinlich` → `unsicher`), die alte Zeile bleibt als `abgeloest` sichtbar. Wer das nicht will, muss die Regel in `VehicleService::applyFact` ändern.
