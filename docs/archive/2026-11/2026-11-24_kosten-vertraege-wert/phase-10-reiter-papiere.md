# Phase 10 — Reiter Papiere

Rating: standard · Modell: sonnet

## Kontext

- **Entwurf (verbindlich):** `artifacts/entwurf-kosten-wert.html` → Golf · Papiere (Versicherung fehlt), Clio · Papiere (Stichtag, Vorgänge); Funktionen `tabPapers`, `ctrCard`, `deadlineList`
- Phase 8/9 (Reiter-Muster), `web/js/vehicle.js`, `web/js/widgets.js`, `web/js/provenance.js`, `web/js/record-delete.js`, `web/js/vehicle-inputs.js` (`openFactSheet` für Schlüssel, Papiere, CO₂)
- API: `GET vehicles/{id}/papers` (enthält `deadlines`, `emergency`), `POST records/{contract|contract_event|accessory|permit}/{id}/delete`
- Fehlerklassen: `frameworks/css-architecture` → Freiraum als `::after`, nicht als Innenabstand

## AK (Struktur aus dem Entwurf)

- [x] `TABS` + `papiere` „Papiere“ nach `wert`.
- [x] Oben zweispaltig ab 992 px: „Notfallkarte“ (Karte mit Primär-Randlinie links: Fahrzeugname, Zustand „offline auf diesem Gerät“ erst ab Phase 11 — bis dahin weglassen; Kennzeichen, FIN, Versicherer mit Vertragsnummer und Telefon als `tel:`-Link, Pannenhilfe mit Mitgliedsnummer und Telefon; fehlender Versicherer gelb „fehlt — Police im Chat an GARAGE geben“; Zeile „Stand <Datum>“) und „Stichtage“ (Liste Titel · Zustand mit Restzeit · Datum; gelb bei ≤ 30 Tagen; Rechenweg per Tippen).
- [x] „Verträge“: Raster aus Karten (min. 19 rem, gleich hoch je Reihe): Kopf Art (+ Marke „Garage“ bei Garagen-Verträgen) und Beitrag/Jahr bzw. Anteil; Anbieter; Hinweisleiste mit nächstem Kündigungsstichtag ≤ 30 Tage; Angaben (Vertrag, Hauptfälligkeit, SF/Typklasse, Fahrleistung bzw. Fälligkeit, Bemessung, Beitrag, Umlage); Prüfzeile (Steuer gegen Bescheid: passt / nicht prüfbar mit Knopf „CO₂ eintragen“; Fahrleistung gegen Prognose; Garagen-Vertrag mit Umlage-Rechenweg); aufklappbar „Beitragsverlauf · n Jahre“ (Tabelle Jahr · Beitrag · SF · Typklasse) und „Vorgänge · n offen“ (offen = aufgeklappt); Fuß: Herkunfts-Abzeichen, „Vertrag löschen“ (nicht bei Garagen-Verträgen in diesem Reiter). Fehlt eine Versicherung: gestrichelte Karte „Kfz-Versicherung fehlt“ mit nächstem Schritt.
- [x] Prüfzeilen rechnet die Oberfläche **nicht** selbst: sie zeigt `check` je Vertrag aus der API (Phase 6).
- [x] Darunter zweispaltig: „Plaketten und Vignetten“ (Art · Gültigkeit · Bezeichnung · Löschen) und „Zubehör“ (Name · geht mit/bleibt · Zustand und Ort, Garantie · Löschen). Darunter „Schlüssel und Papiere“ als Angaben-Raster mit Korrigieren (Felder `schluesselanzahl`, `ablageort_papiere`).
- [x] Zustände lädt/Fehler/leer („Noch keine Papiere“ mit nächstem Schritt); Rolle `lesend` ohne Löschen/Korrigieren; 360 px hell/dunkel ohne Überlauf, 768/1366 px.

## Checkliste

- [x] `web/js/tab-papers.js` (`renderPapers`); `web/css/base.css`: `.ecard`, `.contracts`, `.ctr` (aus dem Entwurf).
- [x] `node --input-type=module --check < …` je Datei.
- [x] Doku: `docs/code-map.md`, `docs/glossary.md` (Notfallkarte).

## Report-Back

- Reiter in `web/js/tab-papers.js`; `factRow`, `factActions`, `entryDetail` aus `vehicle-overview.js` exportiert und für „Schlüssel und Papiere“ mitbenutzt.
- Geprüft mit Testdaten im Headless-Edge (360 hell/dunkel, 768, 1366; ohne Versicherung, leer, `lesend`): kein Überlauf, keine Konsolenfehler, Vertrag löschen ruft `records/contract/{id}/delete`. Nicht gegen die echte API geprüft.
- Auch Vorgänge an Garagen-Verträgen haben hier keinen Lösch-Knopf (wie der Vertrag selbst); Phase 12 braucht ihn in der Garage.
- „Umlage“ nennt Schlüssel und Anteil in Prozent, nicht die Zahl der aktiven Autos — die liefert die API nicht.
