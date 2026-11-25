# Phase 8 — Reiter Kosten mit Blättern Tankung und Ausgabe

Status: complete (2026-11-25)

Rating: standard · Modell: sonnet

## Kontext

- **Entwurf (verbindlich):** `artifacts/entwurf-kosten-wert.html` → „Springen“ Golf · Kosten, Clio · Kosten; Funktionen `tabCosts`, `stackChart`, `lineChart`, Blätter `act === 'fuel'`/`'expense'`, Stil ab `/* ===== Meilenstein 5`; `artifacts/README.md` (Diagrammfarben, echt vs. Beispiel)
- `docs/conventions/frontend.md`; Breakpoints und Handy-Regeln: Archiv-README Meilenstein 4 → „Breakpoints und Handy“
- Bestand: `web/js/vehicle.js` (`TABS`, Reiter laden), `web/js/tab-measure.js` (Muster Reiter mit Diagramm), `web/js/chart.js` (`lineChart`), `web/js/view-switch.js`, `web/js/sheet.js` (`openSheet`), `web/js/vehicle-inputs.js` (Muster Blatt mit Formular, Fehler, Speichern), `web/js/dropdown.js`, `web/js/record-delete.js`, `web/js/toast.js`, `web/js/provenance.js` (`badge`, `tipText`), `web/js/widgets.js` (`formatEuro`, `emptyBlock`, `errorBlock`, `skeletons`), `web/css/tokens.css`, `web/css/base.css`
- API: `GET vehicles/{id}/costs?period=`, `GET/POST vehicles/{id}/fuel`, `GET/POST vehicles/{id}/expenses`, `POST records/{fuel|expense}/{id}/delete` (Plan-README → Kontrakt; Ausgaben aus Phase 3 und 5)
- Fehlerklassen: `frameworks/css-architecture` → „Freiraum per Innenabstand reserviert“: keinen Scroll-Container mit großem `padding-bottom` bauen (Kamera-Knopf überdeckt sonst die letzte Zeile) — Freiraum als `::after` am Ende, wie Meilenstein 4

## AK (Struktur aus dem Entwurf)

- [x] `vehicle.js`: `TABS` + `kosten` „Kosten“ nach `belege`. Wert und Papiere kommen erst mit Phase 9 und 10 in `TABS` (keine Platzhalter-Reiter).
- [x] Reiter Kosten von oben: Zeitraum-Chips `12 Monate` · `Seit Kauf` · `<aktuelles Jahr>` · `<Vorjahr>` (Auswahl in `localStorage` `garage.kosten.zeitraum`); Hinweisleiste „Unvollständig“ mit den fehlenden Blöcken und dem nächsten Schritt (bei fehlendem Kraftstoff mit Knopf „Tankung eintragen“); Kennzahlen-Leiste mit vier Werten (pro Monat laufend, pro Monat mit Wertverlust, pro km mit Wertverlust, km im Zeitraum), jeder mit Rechenweg per Tippen (`≈`-Knopf wie Meilenstein 4).
- [x] „Je Monat“: gestapelte Säulen, Reihenfolge und Farben Fixkosten `--s1`, Betrieb `--s2`, Werkstatt `--s3`, Wertverlust `--s4` (Tokens in `tokens.css` dunkel und hell mit den Werten aus `artifacts/README.md`), 2 px Fuge in Kartenfarbe, Monatsbeschriftung (am Handy jede zweite), y-Achse 0 / Hälfte / Maximum in €, Legende darunter; Tippen auf ein Segment zeigt Block, Betrag und Monatssumme. „Pro km“: eigenes Liniendiagramm (eine Achse) mit Text „Ab 12 Monaten Haltedauer rollierend über die letzten 12 Monate.“
- [x] „Blöcke“: Liste mit vier aufklappbaren Zeilen (Farbmarke, Name, Summe; darunter „x € je Monat“ und ggf. „unvollständig“); aufgeklappt die Posten als Angaben-Raster (Bezeichnung · Wert · Herkunfts-Abzeichen) und Link in den zuständigen Reiter (Scheckheft, Wert, Papiere). Diese Liste ist die Tabellenansicht des Diagramms.
- [x] „Tankbuch“: Kopf mit Zahl und Hauptknopf „Tankung eintragen“ (nur `inhaber`/`mitglied`), Umschalter Tabelle/Karten (`view-switch.js`, Schlüssel `garage.ansicht.tankbuch`, Standard Tabelle ab 768 px, sonst Karten); Tabelle: Datum, km, Liter, Betrag, €/l, Verbrauch (Rechenweg per Tippen), Voll, Löschen; Karten: Datum · Betrag, Verbrauch rechts, km · Liter · €/l. Leer: Hinweis mit Knopf.
- [x] „Sonstige Ausgaben“: Liste Kategorie · Betrag · Datum · Abzeichen · Löschen; leise Aktion „Ausgabe eintragen“.
- [x] Blatt „Tankung eintragen · <Fahrzeug>“: Datum (heute), Kilometer laut Tacho, Liter, Betrag gesamt (€) in einem Raster (2 Spalten ab 576 px), Kippschalter „Voll getankt“ (an) mit Satz „Verbrauch rechnet GARAGE zwischen zwei Volltankungen.“, Hinweis zur Herkunft und zum Klärfall, Knöpfe Abbrechen/Tankung speichern; nach dem Speichern Leiste „Tankung gespeichert · x,x l/100 km“ bzw. „… · Kilometerstand ist ein Klärfall“ und Neuladen des Reiters.
- [x] Blatt „Ausgabe eintragen“: Kategorie als gestaltete Auswahl (`dropdown.js`, Werte Parken, Wäsche, Pflege, Maut, Reifeneinlagerung, Bußgeld, Sonstiges → API-Werte `parken … sonstiges`), Betrag, Datum, Notiz; Speichern wie oben.
- [x] Löschen jeder Tankung/Ausgabe über `record-delete.js` mit Rückgängig; Rolle `lesend` sieht weder Lösch- noch Eingabe-Knöpfe.
- [x] Zustände: lädt (Skeleton), Fehler (`errorBlock` mit Neu laden), leer (`emptyBlock` „Noch keine Kosten“ mit nächstem Schritt).
- [x] 360 px hell und dunkel ohne waagerechtes Scrollen, 768 px und 1366 px je einmal (Messung wie Meilenstein 4).

## Checkliste

- [x] `web/css/tokens.css`: `--s1 … --s4` in beiden Themen.
- [x] `web/js/chart.js`: `stackedBars({ months, series:[{key,label,color,values}], ariaLabel, containerWidth })` und `lineChart` für die km-Kosten wiederverwenden; Tipp-Popover über `provenance.js` → `tipText`.
- [x] `web/js/tab-costs.js` (`renderCosts(host, vehicle, permissions)`), `web/js/cost-inputs.js` (`openFuelSheet`, `openExpenseSheet`).
- [x] `web/css/base.css`: Kippschalter `.toggle`, Farbmarke `.sw`, `.grid2` (aus dem Entwurf übernehmen).
- [x] `node --input-type=module --check < web/js/<datei>.js` für jede neue und geänderte Datei (prüft auch Modul-Syntax; `node --check` allein tut das nicht).
- [x] Doku: `docs/code-map.md` (Ordner `web/js`), `docs/conventions/frontend.md` (Diagrammfarben `--s1…--s4` nur für Reihen, nie für Zustände).

## Report-Back

- Abweichung: Die Linie „Pro km“ brauchte Kilometer je Monat, die die Ausgabe nicht lieferte. `CostCalculator::perKmSeries` ergänzt `per_km` (nur Fahrzeug), `blocks.*.month_cents` liefert den Monatswert je Block; im Browser wird nichts davon nachgerechnet. Wie im Entwurf fehlen die ersten zwei Haltemonate.
- Links aus den Blöcken nach „Wert“ und „Verträge“ erscheinen erst, wenn die Reiter in `TABS` stehen (Phase 9 und 10).
- Wertverlust-Posten tragen kein Herkunfts-Abzeichen (gerechnet, Quelle ist Korridor oder Bewertung ohne Sicherheit).
- Geprüft: `node --input-type=module --check` für alle geänderten Module. Headless-Edge gegen einen Testserver mit Beispieldaten: 360 px hell/dunkel, 768 px, 1366 px ohne seitlichen Überlauf (auch Blöcke aufgeklappt), leer und `lesend` ohne Lösch-Knöpfe (lesend auch ohne Eingabe-Knöpfe), Popover der Säule, beide Blätter mit gesendeten Bodys, Löschen mit „Rückgängig“, keine Konsolenfehler.
- 🟡 Nicht geprüft: PHP (lokal keins, CI-Lauf ansehen) und der Reiter gegen die echte API.
