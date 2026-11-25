# Phase 12 — Garage, Übersicht, Konto

Rating: standard · Modell: sonnet

## Kontext

- **Entwurf (verbindlich):** `artifacts/entwurf-kosten-wert.html` → Garage (Unterhalt, Verträge der Garage, Karten), Golf · Übersicht (Abschnitte „Kosten und Wert“, „Stichtage“), Konto (Kalender-Abo); Funktionen `garageCosts`, `carCard`, `tabOverview`, `viewAccount`
- `web/js/garage.js`, `web/js/vehicle-overview.js`, `web/js/account.js`, `web/js/chart.js` (`stackedBars` aus Phase 8), `web/js/view-switch.js`, `web/js/provenance.js`, `web/js/toast.js`
- API: `GET vehicles` (Felder `costs`, `value` aus Phase 7), `GET garages/{id}/costs`, `GET vehicles/{id}/deadlines`, Garagen-Verträge aus `GET garages/{id}/costs` → `garage_contracts` (Phase 5), `GET/POST garages/{id}/calendar-link…`
- Fehlerklassen: keine einschlägig

## AK (Struktur aus dem Entwurf)

- [x] Garage-Karte (aktiv): unter „km heute“/„Nächste Fälligkeit“ zwei weitere Angaben „Unterhalt ≈ x €/Monat“ (mit Wertverlust; darunter gelb „ohne <fehlende Blöcke>“) und „Wert ≈ a–b €“ bzw. „noch kein Korridor“. Karten einer Reihe gleich hoch.
- [x] Unter den Fahrzeuggruppen je Garage „Unterhalt · aktive Autos · letzte 12 Monate“: Umschalter laufend / mit Wertverlust (`garage.ansicht.unterhalt`), Kennzahlen-Leiste pro Monat, pro km (Rechenweg per Tippen), Blöcke fehlen (verlinkt auf das erste Fahrzeug mit Lücke → Reiter Kosten), Stichtage in 30 Tagen (Summe über die aktiven Fahrzeuge); gestapelte Säulen je Monat nach Auto (Reihenfarben `--s1`, `--s2`, … in der Reihenfolge der Fahrzeugliste, höchstens vier Autos einzeln, weitere als „Weitere“ in `--muted`), Legende.
- [x] „Verträge der Garage“: Liste Anbieter · Beitrag/Jahr · „Umlage gleich · x € je Auto“ mit Rechenweg per Tippen · Herkunfts-Abzeichen. Keine Garagen-Verträge → Abschnitt entfällt.
- [x] Übersicht des Fahrzeugs: linke Spalte nach „Letztes Ereignis“ der Abschnitt „Kosten und Wert“ (zwei Zeilen, führen in Reiter Kosten bzw. Wert); rechte Spalte vor „Schäden und Rückrufe“ „Stichtage“ (die nächsten zwei, Link „Papiere“). Fehlen Daten (kein Kostenposten, keine Stichtage), entfällt der Abschnitt.
- [x] Konto: Abschnitt „Kalender-Abo“ vor „Darstellung“, je Garage eine Zeile: Name, gekürzte Adresse, Satz zum Inhalt, Knopf „Link kopieren“ (primär, kopiert die volle URL, Leiste „Kalender-Link kopiert“), für Inhaber „Neuen Link erzeugen“ (Blatt mit Bestätigung „Der alte Link hört sofort auf zu funktionieren. Im Kalender neu abonnieren.“ → Leiste), Info-Knopf „Wer sieht das?“ mit dem Satz aus dem Entwurf.
- [x] 360 px hell/dunkel ohne Überlauf für Garage, Übersicht, Konto; 768/1366 px je einmal.

## Checkliste

- [x] `web/js/garage.js` (Karten-Angaben, Abschnitt Unterhalt über neues Modul `web/js/garage-costs.js`), `web/js/vehicle-overview.js`, `web/js/account.js` (+ `web/js/calendar.js` für den Abschnitt).
- [x] `node --input-type=module --check < …` je Datei.
- [x] Doku: `docs/code-map.md`.

## Report-Back

- Der Block unter den Fahrzeuggruppen liegt in neuem `web/js/garage-costs.js`, das Kalender-Abo in neuem `web/js/calendar.js`. `formatCorridor`, `formatWholeEuro` und `remainingText` stehen jetzt in `widgets.js`.
- `GET garages/{id}/costs` nennt bei Garagen-Verträgen weder Tarif noch Vorgänge. Beides kommt aus `GET vehicles/{id}/papers` des ersten Fahrzeugs der Garage (`contractPapers`); die API blieb unverändert.
- Löschen von Vertrag und Vorgang sitzt in „Verträge der Garage“ (nicht im Entwurf, nötig für die finale AK 6), nicht für die Rolle `lesend`. Unter dem Diagramm zusätzlich „Werte als Tabelle“ (Konvention: jedes Diagramm hat eine Tabelle).
- Summen und Säulen der Garage kommen vom Server und enthalten jedes Auto, das im Zeitraum aktiv war, auch ein inzwischen verkauftes; die Überschrift sagt wie im Entwurf „aktive Autos“.
- Weggelassen: der Entwurfssatz „steigt voraussichtlich … auf 114 €“ (die API liefert dazu keine Daten).
- Nebenbei behoben: die allgemeine Knopf-Regel verformte die Segment-Umschalter (`.tb .seg button` in `base.css`).
- Prüfung: `node --check` aller geänderten Module; headless Edge mit Testdaten (nicht gegen die echte API) für Garage, Übersicht, Konto bei 360 dunkel/hell, 768 und 1366 px ohne Überlauf, Karten einer Reihe gleich hoch; Umschalter, Popover, Löschen mit Rückgängig, Link kopieren und erneuern liefen ohne Konsolenfehler.
