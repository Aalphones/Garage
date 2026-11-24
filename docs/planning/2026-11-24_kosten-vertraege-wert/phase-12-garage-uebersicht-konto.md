# Phase 12 — Garage, Übersicht, Konto

Rating: standard · Modell: sonnet

## Kontext

- **Entwurf (verbindlich):** `artifacts/entwurf-kosten-wert.html` → Garage (Unterhalt, Verträge der Garage, Karten), Golf · Übersicht (Abschnitte „Kosten und Wert“, „Stichtage“), Konto (Kalender-Abo); Funktionen `garageCosts`, `carCard`, `tabOverview`, `viewAccount`
- `web/js/garage.js`, `web/js/vehicle-overview.js`, `web/js/account.js`, `web/js/chart.js` (`stackedBars` aus Phase 8), `web/js/view-switch.js`, `web/js/provenance.js`, `web/js/toast.js`
- API: `GET vehicles` (Felder `costs`, `value` aus Phase 7), `GET garages/{id}/costs`, `GET vehicles/{id}/deadlines`, Garagen-Verträge aus `GET garages/{id}/costs` → `garage_contracts` (Phase 5), `GET/POST garages/{id}/calendar-link…`
- Fehlerklassen: keine einschlägig

## AK (Struktur aus dem Entwurf)

- [ ] Garage-Karte (aktiv): unter „km heute“/„Nächste Fälligkeit“ zwei weitere Angaben „Unterhalt ≈ x €/Monat“ (mit Wertverlust; darunter gelb „ohne <fehlende Blöcke>“) und „Wert ≈ a–b €“ bzw. „noch kein Korridor“. Karten einer Reihe gleich hoch.
- [ ] Unter den Fahrzeuggruppen je Garage „Unterhalt · aktive Autos · letzte 12 Monate“: Umschalter laufend / mit Wertverlust (`garage.ansicht.unterhalt`), Kennzahlen-Leiste pro Monat, pro km (Rechenweg per Tippen), Blöcke fehlen (verlinkt auf das erste Fahrzeug mit Lücke → Reiter Kosten), Stichtage in 30 Tagen (Summe über die aktiven Fahrzeuge); gestapelte Säulen je Monat nach Auto (Reihenfarben `--s1`, `--s2`, … in der Reihenfolge der Fahrzeugliste, höchstens vier Autos einzeln, weitere als „Weitere“ in `--muted`), Legende.
- [ ] „Verträge der Garage“: Liste Anbieter · Beitrag/Jahr · „Umlage gleich · x € je Auto“ mit Rechenweg per Tippen · Herkunfts-Abzeichen. Keine Garagen-Verträge → Abschnitt entfällt.
- [ ] Übersicht des Fahrzeugs: linke Spalte nach „Letztes Ereignis“ der Abschnitt „Kosten und Wert“ (zwei Zeilen, führen in Reiter Kosten bzw. Wert); rechte Spalte vor „Schäden und Rückrufe“ „Stichtage“ (die nächsten zwei, Link „Papiere“). Fehlen Daten (kein Kostenposten, keine Stichtage), entfällt der Abschnitt.
- [ ] Konto: Abschnitt „Kalender-Abo“ vor „Darstellung“, je Garage eine Zeile: Name, gekürzte Adresse, Satz zum Inhalt, Knopf „Link kopieren“ (primär, kopiert die volle URL, Leiste „Kalender-Link kopiert“), für Inhaber „Neuen Link erzeugen“ (Blatt mit Bestätigung „Der alte Link hört sofort auf zu funktionieren. Im Kalender neu abonnieren.“ → Leiste), Info-Knopf „Wer sieht das?“ mit dem Satz aus dem Entwurf.
- [ ] 360 px hell/dunkel ohne Überlauf für Garage, Übersicht, Konto; 768/1366 px je einmal.

## Checkliste

- [ ] `web/js/garage.js` (Karten-Angaben, Abschnitt Unterhalt über neues Modul `web/js/garage-costs.js`), `web/js/vehicle-overview.js`, `web/js/account.js` (+ `web/js/calendar.js` für den Abschnitt).
- [ ] `node --input-type=module --check < …` je Datei.
- [ ] Doku: `docs/code-map.md`.

## Report-Back
