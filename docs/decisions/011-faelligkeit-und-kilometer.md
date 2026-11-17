# 011 — Kilometer vorwärts, strittige Stände; Fälligkeit beim Lesen

**Status:** Akzeptiert (2026-11-17) — Teil 1 (Kilometer, Phase 2) und Teil 2 (Kilometerprognose, Fälligkeitsrechnung, Phase 3).

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

## Teil 2 — Fälligkeit wird beim Lesen berechnet

### Kontext

Eine Wartung ist fällig nach Zeit, nach Kilometern oder nach beidem; es gilt, was zuerst eintritt. Kilometer sind nur zu den Zeitpunkten bekannt, an denen jemand abgelesen hat, heute also meist geschätzt. Ein gespeichertes Fälligkeitsdatum wäre morgen schon falsch und könnte mit der Regel oder den Ständen auseinanderlaufen.

### Entscheidung

- **Nichts Berechnetes wird gespeichert.** `DueService` rechnet bei jedem Lesen (`get_due_items`, `list_vehicles`, `get_vehicle`) aus Regel, letzter Ausführung und Ständen; `DueCalculator` und `OdometerForecast` sind reine Klassen ohne Datenbank. „Heute“ kommt aus der Datenbank (`UTC_DATE()`), nie aus dem Gerät.
- **Kilometerprognose:** Letzter gültiger Stand L. Vergleichsstand ist der jüngste im Fenster L−365 bis L−30 Tage, sonst der früheste Stand vor L−30 Tagen, sonst die Erstzulassung bei 0 km. `per_day` = Kilometerdifferenz / Tage; `km_estimate = round(L.km + per_day × Tage bis heute)`. Gibt es nur einen Stand, ist die Schätzung dieser Stand (`estimated = false`, `per_day = null`). Ohne Stand: `null`.
- **Basis einer Aufgabe:** die letzte Ausführung (aktives Ereignis mit Position desselben `task_key`, jüngstes Leistungsdatum), sonst Erstzulassung bei 0 km mit den `first_*`-Intervallen (Fallback auf die Hauptintervalle). Fehlt beides, ist der Status `unbekannt`.
- **Fälligkeit:** `due_date = Basis-Datum + Monate` (`addMonths` kürzt den Tag auf das Monatsende: 31.01. + 1 Monat = 28.02., nicht der 03.03.); `due_km = Basis-km + Intervall`; `km_due_date = L.date + aufgerundet((due_km − L.km) / per_day)` Tage. `effective_date` ist das frühere von beiden. Fehlt `per_day`, gibt es kein `km_due_date`.
- **Status:** `ueberfaellig` (effektives Datum vor heute oder geschätzter Stand ≥ `due_km`), `bald` (effektives Datum innerhalb von 3 Monaten oder höchstens 3.000 km bis `due_km`), sonst `ok`; `unbekannt`, wenn nichts berechenbar ist. Jede Aufgabe trägt den Rechenweg auf Deutsch (Daten als TT.MM.JJJJ) und `rule_uncertain`, wenn die Regel nur `unsicher` belegt ist.
- **Regelabgleich wie bei Stammdaten:** eine bestätigte Regel bleibt stehen, eine abweichende neue Regel wird zum Klärfall (`proposal_json` mit den Regelfeldern); gleiche Regel mit höherer Konfidenz oder Ersatz einer nicht bestätigten Regel legt eine neue Zeile als `aktuell` an, die alte wird `abgeloest` und bleibt als Verlauf erhalten.

### Verworfen

- **Fälligkeit speichern und nachführen:** läuft mit jedem neuen Stand, jeder Regeländerung und jedem Tag auseinander; zwei Quellen der Wahrheit.
- **Lineare Regression über alle Stände:** ein alter Fahrstil oder ein Ausreißer verzerrt die Prognose; das Fenster von 30 bis 365 Tagen folgt dem aktuellen Gebrauch und bleibt für den Nutzer nachvollziehbar.
- **`modify('+N month')` für Monate:** springt hinter das Monatsende (31.01. → 03.03.).

### Konsequenzen

- Ein strittiger oder verworfener Stand fließt nie in eine Fälligkeit ein (Teil 1).
- Die Schwellen für „bald“ (3 Monate, 3.000 km) stehen als Konstanten in `DueCalculator` und werden in der Antwort von `get_due_items` mitgeliefert (`thresholds`).
- Eine Position ohne passende Regel erzeugt keine Fälligkeit; `record_service_event` warnt, dass der `task_key` noch keine Intervallregel hat.
