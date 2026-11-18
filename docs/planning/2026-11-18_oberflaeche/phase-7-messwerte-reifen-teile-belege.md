# Phase 7 — Reiter Messwerte, Reifen, Teile, Belege

Rating: standard · Modell: sonnet · braucht Phase 4 und 5

## Kontext

- `artifacts/entwurf-oberflaeche.html` — „Golf · Messwerte“, „Clio · Reifen“ (und Golf ohne Reifen = leerer Zustand), „Golf · Teile“, „Golf · Belege“; Vorlage `tabMeasure`, `kmChart`, `tabTires`, `tabParts`, `tabDocs`
- `docs/decisions/018-oberflaeche-seiten-und-lese-api.md` (Diagramme als SVG im Browser)
- README → „Kontrakt“; `docs/routes.md` (`get_measurements`, `get_tires`, `get_parts`, `GET documents`)
- `web/js/document.js`, `web/belege.html` (Meilenstein 3), `web/js/vehicle.js`, `provenance.js`, `toast.js`

## AK

Messwerte (`web/js/tab-measure.js`, `web/js/chart.js`):
- [ ] `chart.js` exportiert `lineChart({ points, forecast, yLabel, ariaLabel })` → SVG-String: Viewbox 640×220 ab 600 px Containerbreite, sonst 340×200 und nur jede zweite x-Beschriftung; drei Gitterlinien mit Beschriftung; Linie in `--primary`, Prognose gestrichelt; Punkte mit `<title>`; `role="img"` + `aria-label` mit erstem/letztem Wert und Prognose. Farben nur über CSS-Variablen (`stroke="var(--primary)"`).
- [ ] Kilometerstand: Punkte = gültige Stände aus `GET vehicles/{id}/odometer` (Phase 2), Prognose vom letzten Stand über heute (`km_estimate.km_estimate`) bis heute + 4 Monate mit `km_estimate.per_day`; ohne `per_day` keine Prognoselinie. Legende „belegte Stände“, „Prognose ≈ <per_day> km/Tag“.
- [ ] Profiltiefe: jüngster Messtag je Rad (VL, VR, HL, HR) als 2×2-Raster, Vortag-Wert klein darunter; unter 3 mm gelb, unter 1,6 mm rot, jeweils mit Wort. Satz „Gesetzlich 1,6 mm, empfohlen 3 mm.“
- [ ] Weitere Werte: übrige Reihen je jüngster Wert mit Datum und Abzeichen; Löschen je Wert nicht (Messwerte löschen nur über die Reihe: Knopf „Messung löschen“ im Popover eines Punkts → `records/measurement/{id}` — dafür liefert `get_measurements` je Punkt die `id`; fehlt sie, in `MeasurementService::series` ergänzen und in `routes.md` vermerken).
- [ ] Leer: „Noch keine Messwerte“ + „Profiltiefe, Batterie und Drücke stehen meist im Werkstattbericht — gib ihn deinem Agenten.“

Reifen (`web/js/tab-tires.js`):
- [ ] Karten je Satz (Raster `minmax(min(100%, 17rem), 1fr)`): Name, „montiert“/„eingelagert“, Reifen, Größe · Saison, DOT · Alter (ab 6 Jahren gelb „alt“), Profil (jüngste Profiltiefe des Satzes, wenn messbar, sonst weglassen), seit/Lager; montierter Satz mit Teal-Rand links. „Satz löschen“ leise destruktiv → `records/tire_set/{id}` mit Rückgängig.
- [ ] Leer: „Noch keine Reifensätze“ + „Fotografier die Reifenflanke mit DOT und gib das Bild deinem Agenten.“

Teile (`web/js/tab-parts.js`):
- [ ] Je Bauteil eine Gruppe; Zeile: Marke + „verbaut“ (Wort mit Punkt) oder „Alternative“, Artikelnummer als `.copy`-Knopf (kopiert, Leiste „<Nummer> kopiert“), „OEM <Nummer>“, „geprüft“/„ungeprüft“ (gelb); „Löschen“ je Zeile → `records/part/{id}` mit Rückgängig.
- [ ] Leer: „Noch keine Teile“ + „Frag deinen Agenten nach den Teilenummern für Öl, Filter und Kerzen.“

Belege (`web/js/tab-docs.js` + Umbau `document.js`):
- [ ] `document.js` exportiert die Galerie- und Listen-Darstellung als `renderDocuments(container, { vehicleId })`; `belege.html` nutzt dieselbe Funktion ohne Filter (Verhalten unverändert).
- [ ] Reiter zeigt die Belege des Fahrzeugs (`GET documents?vehicle_id=`), Umschalter Galerie/Liste (`garage.view.belege`), Tippen → `belege.html#beleg/<id>`.
- [ ] Prüfung nach README → „Breakpoints und Handy“ für alle vier Reiter; Popover eines Diagrammpunkts per Tippen erreichbar; Diagramm in beiden Breiten lesbar (Beschriftung ≥ 11 px nach Skalierung); Kopieren im Browser geprüft.

## Checkliste

- [ ] Module und Anbindung in `vehicle.js`; ggf. `id` in `get_measurements`
- [ ] Doc-Update: `docs/code-map.md`, `docs/routes.md` (falls `id` ergänzt), `docs/conventions/frontend.md` → Tabelle „Diagramme“: „SVG im Browser (`web/js/chart.js`), ADR-018“

## Report-Back
