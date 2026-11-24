# Phase 9 — Reiter Wert

Rating: standard · Modell: sonnet

## Kontext

- **Entwurf (verbindlich):** `artifacts/entwurf-kosten-wert.html` → Golf · Wert, Clio · Wert; Funktionen `tabValue`, `valueChart`, `scatterChart`
- Phase 8 (Reiter-Muster `tab-costs.js`, `chart.js` mit `stackedBars`), `web/js/vehicle.js` (`TABS`), `web/js/widgets.js`, `web/js/provenance.js`, `web/js/record-delete.js`, `web/js/vehicle-inputs.js` → `openFactSheet` (für „Unfallfrei angeben“)
- API: `GET vehicles/{id}/value`, `POST records/{valuation|listing}/{id}/delete` (Phase 4)
- Fehlerklassen: keine einschlägig

## AK (Struktur aus dem Entwurf)

- [ ] `TABS` + `wert` „Wert“ nach `kosten`.
- [ ] Mit Korridor: Kennzahlen-Leiste Marktmitte (≈, Rechenweg per Tippen), mittlere Hälfte (`11.600–12.450 €`-Form), Kaufpreis mit Monat, Wertfaktoren „3 · 2 · 3“ mit Unterzeile „Faktoren dafür · dagegen · offen“ (gelb, wenn ein Minus); darunter der Satz „Angebotspreise sind keine Verkaufspreise. Ab 15 passenden Inseraten …— noch n.“ (bei Regression: „auf deinen km-Stand und dein Alter umgerechnet aus n Inseraten“).
- [ ] Zwei Diagramme nebeneinander ab 992 px, sonst untereinander: „Verlauf“ (Ankerwerte als hohle Punkte, Marktmitte als Linie mit Punkten, mittlere Hälfte als Band in Primärfarbe 18 %) und „Inserate“ (Preis gegen km; zählende gefüllt `--s1`, nicht zählende hohl; senkrechte gestrichelte Linie „dein Stand“; Band der mittleren Hälfte); Tippen auf einen Punkt zeigt Preis, km, EZ, Alter des Abrufs, ggf. „zählt nicht“. Legende unter jedem Diagramm.
- [ ] Ohne Korridor (unter 8): leerer Zustand „Noch kein Marktkorridor“ mit „n von 8 passenden Inseraten“ und dem Satz an den Agenten; Diagramme entfallen.
- [ ] „Wertfaktoren“: Liste mit Symbol (+ / − / ? im Kreis, Farbe primär / rot / gelb gestrichelt) und Wort (spricht dafür / spricht dagegen / unbekannt), Unterzeile Detail; jede Zeile führt in ihren Reiter (`ref`), „Unfallfrei“ öffnet für `inhaber`/`mitglied` das Blatt „Unfallfrei korrigieren“ (`openFactSheet`, Feld `unfallfrei`, Auswahl ja/nein).
- [ ] „Ankerwerte“: Liste Art · Betrag · Datum · km · Abzeichen · Löschen. „Vergleichsinserate“: Kopf „n gesammelt · m passen“, Tabelle Preis (Link auf die URL, `rel="noopener noreferrer"`, neuer Tab) · km · EZ · Abruf · Zählt (Grund, wenn nicht) · Löschen; nicht zählende ausgegraut, zählende zuerst nach Preis.
- [ ] Zustände lädt/Fehler/leer; Rolle `lesend` ohne Lösch- und Eingabe-Knöpfe; 360 px hell/dunkel ohne Überlauf, 768/1366 px.

## Checkliste

- [ ] `web/js/chart.js`: `bandLine(...)` (Verlauf) und `scatter(...)` (Inserate), eine Achse je Diagramm.
- [ ] `web/js/tab-value.js` (`renderValue`).
- [ ] `web/css/base.css`: `.vf` (aus dem Entwurf).
- [ ] `node --input-type=module --check < …` je Datei.
- [ ] Doku: `docs/code-map.md`.

## Report-Back
