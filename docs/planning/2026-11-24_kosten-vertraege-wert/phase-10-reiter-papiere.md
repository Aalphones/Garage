# Phase 10 — Reiter Papiere

Rating: standard · Modell: sonnet

## Kontext

- **Entwurf (verbindlich):** `artifacts/entwurf-kosten-wert.html` → Golf · Papiere (Versicherung fehlt), Clio · Papiere (Stichtag, Vorgänge); Funktionen `tabPapers`, `ctrCard`, `deadlineList`
- Phase 8/9 (Reiter-Muster), `web/js/vehicle.js`, `web/js/widgets.js`, `web/js/provenance.js`, `web/js/record-delete.js`, `web/js/vehicle-inputs.js` (`openFactSheet` für Schlüssel, Papiere, CO₂)
- API: `GET vehicles/{id}/papers` (enthält `deadlines`, `emergency`), `POST records/{contract|contract_event|accessory|permit}/{id}/delete`
- Fehlerklassen: `frameworks/css-architecture` → Freiraum als `::after`, nicht als Innenabstand

## AK (Struktur aus dem Entwurf)

- [ ] `TABS` + `papiere` „Papiere“ nach `wert`.
- [ ] Oben zweispaltig ab 992 px: „Notfallkarte“ (Karte mit Primär-Randlinie links: Fahrzeugname, Zustand „offline auf diesem Gerät“ erst ab Phase 11 — bis dahin weglassen; Kennzeichen, FIN, Versicherer mit Vertragsnummer und Telefon als `tel:`-Link, Pannenhilfe mit Mitgliedsnummer und Telefon; fehlender Versicherer gelb „fehlt — Police im Chat an GARAGE geben“; Zeile „Stand <Datum>“) und „Stichtage“ (Liste Titel · Zustand mit Restzeit · Datum; gelb bei ≤ 30 Tagen; Rechenweg per Tippen).
- [ ] „Verträge“: Raster aus Karten (min. 19 rem, gleich hoch je Reihe): Kopf Art (+ Marke „Garage“ bei Garagen-Verträgen) und Beitrag/Jahr bzw. Anteil; Anbieter; Hinweisleiste mit nächstem Kündigungsstichtag ≤ 30 Tage; Angaben (Vertrag, Hauptfälligkeit, SF/Typklasse, Fahrleistung bzw. Fälligkeit, Bemessung, Beitrag, Umlage); Prüfzeile (Steuer gegen Bescheid: passt / nicht prüfbar mit Knopf „CO₂ eintragen“; Fahrleistung gegen Prognose; Garagen-Vertrag mit Umlage-Rechenweg); aufklappbar „Beitragsverlauf · n Jahre“ (Tabelle Jahr · Beitrag · SF · Typklasse) und „Vorgänge · n offen“ (offen = aufgeklappt); Fuß: Herkunfts-Abzeichen, „Vertrag löschen“ (nicht bei Garagen-Verträgen in diesem Reiter). Fehlt eine Versicherung: gestrichelte Karte „Kfz-Versicherung fehlt“ mit nächstem Schritt.
- [ ] Prüfzeilen rechnet die Oberfläche **nicht** selbst: sie zeigt `check` je Vertrag aus der API (Phase 6).
- [ ] Darunter zweispaltig: „Plaketten und Vignetten“ (Art · Gültigkeit · Bezeichnung · Löschen) und „Zubehör“ (Name · geht mit/bleibt · Zustand und Ort, Garantie · Löschen). Darunter „Schlüssel und Papiere“ als Angaben-Raster mit Korrigieren (Felder `schluesselanzahl`, `ablageort_papiere`).
- [ ] Zustände lädt/Fehler/leer („Noch keine Papiere“ mit nächstem Schritt); Rolle `lesend` ohne Löschen/Korrigieren; 360 px hell/dunkel ohne Überlauf, 768/1366 px.

## Checkliste

- [ ] `web/js/tab-papers.js` (`renderPapers`); `web/css/base.css`: `.ecard`, `.contracts`, `.ctr` (aus dem Entwurf).
- [ ] `node --input-type=module --check < …` je Datei.
- [ ] Doku: `docs/code-map.md`, `docs/glossary.md` (Notfallkarte).

## Report-Back
