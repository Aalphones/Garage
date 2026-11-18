# Phase 4 — Oberfläche Eingang und Belege

**Rating:** standard (Entwurf liegt fest). Modell: `sonnet`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — E12, E13, Routen der Web-API (Phase 3).
- **Entwurf (verbindlich):** [`artifacts/entwurf-belege.html`](artifacts/entwurf-belege.html) im Browser öffnen, alle Bildschirme der Leiste oben durchklicken, Schalter „Werkzeug zeigen“ an. Erläuterung: [`artifacts/README.md`](artifacts/README.md). Der Rahmen (Leiste „Entwurf M3“, „Wofür“-Kästen, „Beispiel“-Marken, Bildschirm „Claude-Widget“) gehört nicht in die App.
- Repo: `docs/conventions/frontend.md` (CSP nur `'self'`, kein Inline-Skript, Access-Token nur im Speicher), `web/index.html`, `web/js/account.js`, `web/js/api.js` (`request`, Refresh bei 401), `web/js/dom.js`, `web/js/openitem.js` (Liste + Aktion + Fehler mit Fokus), `web/css/base.css`, `web/.htaccess`.
- Report-Back Phase 3 (Routen und Antworten).
- Skills beim Umsetzen: `mode-web-frontend` (Idiotensicherheit, a11y).
- Vault-Fehlerklassen: keine einschlägig.

## Abnahmekriterien

Struktur nach Entwurf (prüfbar):

- [x] **Navigation** „Konto · Eingang · Belege“ unter der `h1` auf `index.html`, `eingang.html`, `belege.html`; aktive Seite fett mit `aria-current="page"`.
- [x] **Eingang, Startzustand:** zwei gleich breite Knöpfe nebeneinander (Raster 1fr 1fr, Höhe ≥ 3,5 rem): primär „Foto aufnehmen“ (`<input type=file accept="image/*" capture="environment">`), sekundär „Datei wählen“ (`accept="image/*,application/pdf" multiple`); darunter Hinweis „Fotos und PDFs bis 30 MB …“; Überschrift „Eingang“ mit ?-Knopf, der einen Hinweis „sag in Claude oder ChatGPT ‚arbeite den Eingang ab‘“ auf- und zuklappt (`aria-expanded`).
- [x] **Neuer Eintrag** (nach Auswahl): Liste der Seiten (Vorschau 4 × 5 rem, Name, Größe nach Verkleinern bzw. „PDF · n Seiten werden als Bilder gerendert“, Knöpfe ↑ ↓ ✕ mit `aria-label`, ↑ an erster und ↓ an letzter Seite deaktiviert); „Weitere Seite fotografieren“; Auswahl „Fahrzeug“ mit erster Option „Weiß ich nicht – Agent ordnet zu“; Feld „Notiz (optional)“ (max. 200); primär „In den Eingang legen (n Seiten)“; leise „Abbrechen“. ✕ entfernt sofort mit Leiste „Seite entfernt. Rückgängig“ (6 s).
- [x] **Fortschritt** im `role="status"`-Bereich: „Verkleinere Foto i von n …“, „Rendere PDF-Seite i von n …“, „Lade hoch … p %“ mit Balken; danach zurück zum Startzustand, Leiste „Liegt im Eingang. Ein Agent erfasst ihn.“ mit Rückgängig (= `POST /garage/api/inbox/{id}/discard`).
- [x] **Im Eingang:** Liste neueste zuerst, je Eintrag Vorschau mit Seitenzahl, Notiz (sonst „Ohne Notiz“), Fahrzeug (sonst „Fahrzeug offen“), Datum, Zustand als Wort mit Punkt: „Neu – wartet auf einen Agenten“, „In Arbeit (<Akteur>, reserviert bis HH:MM)“, „Erfasst: <Ergebnis>“, „Klärfall: <Ergebnis>“ (fett, Raute statt Punkt, Link „Ansehen“ → `index.html#klaerfaelle`), „Verworfen: <Grund>“; nur bei „Neu“ „Eintrag verwerfen“ mit Rückgängig. Leer: „Der Eingang ist leer. Fotografiere eine Rechnung oder wähle ein PDF – ein Agent erfasst es danach.“
- [x] **Belege:** Kopf „Belege“ mit Umschalter „Liste | Galerie“ rechts (Wahl in `localStorage` `garage.belege.ansicht` — nur Anzeige-Einstellung, keine Daten); Fahrzeug-Chips „Alle“ + je Fahrzeug (aktiv gefüllt mit ✓); Liste in Gruppen „Offen“ (Status `wartet`/`unzugeordnet`) und „Zugeordnet“; Zeile: Vorschau 3 × 3,75 rem, Titel (unterstrichen, bricht um), „Typ · Schlagwort · Datum · Fahrzeug“, Zustand „Original fehlt“ (fett, Raute) / „Nicht zugeordnet“ / „Hängt an …“ (gefüllter Punkt). Galerie: Raster `minmax(6.5rem, 1fr)`, Vorschau + Titel.
- [x] **Beleg-Detail** (`belege.html#beleg/<id>`): Zurück-Zeile „← Belege“, `h1` = Titel; Vorschau (Seitenbild bzw. Foto `1568.jpg`) mit „Seite i von n“ und ← → bei mehreren Seiten; Knopf „Original öffnen (PDF)“ bzw. „Foto in voller Größe“ (signierter Link, neuer Tab); Angaben als `dl`: Typ (Auswahl, speichert bei Änderung + Leiste „Gespeichert. Rückgängig“), Fahrzeug (Auswahl, ebenso), Datum, „Hängt an“, „Abgelegt von <Akteur> über <Weg>“; „Erkannter Text“ als `details`; leise „Beleg verwerfen“ mit Rückgängig und Hinweis „Verworfene Belege bleiben gespeichert …“. Status `wartet`: Meldung „Original fehlt …“ und Ablagefläche „Original nachreichen“ mit Knopf „Datei wählen“ (→ `POST /garage/api/documents/{id}/file`). Rolle `lesend`: keine Auswahlen, keine Verwerfen-/Nachreichen-Aktion.
- [x] 360 px ohne waagerechtes Scrollen, hell und dunkel (`prefers-color-scheme`), Fokus sichtbar, jede Aktion per Tastatur; Kontrast der neuen Klassen ≥ 4,5:1 (Schwarz/Weiß aus `base.css`).
- [x] Fotos werden vor dem Hochladen im Browser auf längste Kante 2560 px als JPEG 0,85 verkleinert (`createImageBitmap` mit `imageOrientation: 'from-image'` → Canvas → `toBlob`); kleinere bleiben unverändert. PDFs: jede Seite (höchstens 20, sonst Meldung) mit pdf.js auf längste Kante 1568 px gerendert und als `pages[]` mitgeschickt; Original unverändert als `file`.
- [x] Mit einem echten Werkstatt-PDF: keine CSP-Meldung in der Browser-Konsole.

## Checkliste

### pdf.js (ADR-014)

- [x] pdf.js **4.10.38** (letzte 4er-Fassung, ohne WebAssembly-Pflicht) aus `https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-4.10.38.tgz` laden (Download ins Scratchpad, eigener leerer Ordner), daraus `build/pdf.min.mjs`, `build/pdf.worker.min.mjs` und `LICENSE` nach `web/vendor/pdfjs/` kopieren; SHA-256 beider Dateien in `web/vendor/pdfjs/VERSION.md` mit Quelle notieren.
- [x] Einbinden nur in `web/js/pdfpages.js`: `import * as pdfjs from '/garage/vendor/pdfjs/pdf.min.mjs'`, `GlobalWorkerOptions.workerSrc = '/garage/vendor/pdfjs/pdf.worker.min.mjs'`, `getDocument({ data, isEvalSupported: false })`; Export `renderPages(file, maxEdge = 1568, maxPages = 20, onProgress)` → `Blob[]` (JPEG 0,85).
- [x] CSP in `web/.htaccess` bleibt unverändert (Worker fällt unter `default-src 'self'`); tritt doch eine Meldung auf → FINDINGS, nicht still erweitern.
- [x] ADR-014 `docs/decisions/014-pdfjs-lokal-als-ausnahme.md` (Kontext: Agenten lesen PDFs über MCP nicht sicher, Server kann nicht rendern; Optionen: pdf.js lokal / nur Original + Text / nur Fotos; Entscheidung des Inhabers 2026-11-17; Konsequenz: Ausnahme in `docs/conventions/frontend.md` → Library Policy eintragen, Update nur bewusst mit neuer Prüfsumme).

### Seiten und Module

- [x] `web/eingang.html` und `web/belege.html` nach Form von `web/index.html` (Sprunglink, eine `h1` „GARAGE“, `role="status"`, Modul-Skript am Ende), Navigation wie AK.
- [x] `web/js/inbox.js` (Einstieg `eingang.html`: Anmeldung über `api.restore()`, sonst Verweis auf `index.html`), `web/js/capture.js` (Auswahl, Seitenliste, Verkleinern, Hochladen mit `XMLHttpRequest` für den Fortschritt — `api.js` um `upload(path, formData, onProgress)` mit demselben Access-Token und Refresh-Logik ergänzen), `web/js/document.js` (Einstieg `belege.html`: Liste, Galerie, Detail per Hash `#beleg/<id>`), `web/js/undo.js` (Leiste mit Rückgängig, 6 s, ein Exemplar je Seite), `web/js/pdfpages.js`.
- [x] Ablauf „In den Eingang legen“: je ausgewählte Datei `POST /garage/api/documents` (Foto: verkleinertes JPEG als `file`; PDF: Original als `file` + `pages[]`), Seitenreihenfolge = Reihenfolge der Liste (Fotos sind je eine Seite), dann `POST /garage/api/inbox {document_ids, vehicle_id?, note}`. Bricht ein Upload ab: Meldung „Hochladen fehlgeschlagen: <Grund>. Erneut versuchen“ mit Knopf, bereits hochgeladene Dokumente werden beim erneuten Versuch nicht doppelt geschickt (IDs merken).
- [x] Fehlertexte aus der API (`message`) zeigen; `too_large`, `type_rejected` je Datei vor dem Hochladen schon im Browser prüfen (Größe, `type`), Server bleibt maßgeblich.
- [x] `web/css/base.css` um die mit „NEU in Phase 4“ markierten Klassen des Entwurfs ergänzen (`.primary`, `.capture-row`, `.pages`, `.page`, `.thumb`, `.row`, `.state` + Varianten, `.progress`, `.toast`, `.chips`, `.toolbar`, `.view-toggle`, `.gallery`, `.preview`, `.preview-nav`, `.back`, `details`/`summary`, `.info`, `.tip`, `.dropzone`, `.appnav`), Werte 1:1 aus dem Entwurf; `color-mix` nur für die Raster-Andeutung leerer Vorschauen.
- [x] `web/index.html`: Navigation einfügen, Abschnitt Klärfälle bekommt `id="klaerfaelle"`.

### Doku

- [x] `docs/conventions/frontend.md` (Ausnahme pdf.js, neue Seiten), `docs/code-map.md` (`web/`-Zeilen), `README.md` des Plans: Status.

## Report-Back

**Status:** complete (2026-11-17), deployt auf Strato, Smoke-Test grün. Alle Kästchen oben erfüllt.

**Geprüft (Chrome per DevTools-Protokoll gegen `https://quantum-canvas.de/garage`, 360 px, hell und dunkel, mit echten Daten):** Foto 3200×2400 → „Foto · 95 KB nach Verkleinern“; PDF mit drei Seiten (mit Chrome gedruckt) → „3 Seiten werden als Bilder gerendert“, Absenden „4 Seiten“, Entfernen mit Rückgängig, Eintrag „Neu – wartet auf einen Agenten“; Belege als Liste und Galerie, Ansicht gemerkt; Detail mit Seitenwechsel, Original über signierten Link (200 `application/pdf`), Typ ändern und Rückgängig, Original nachreichen bei Status `wartet`. Kein waagerechtes Scrollen, Knopfhöhe 56 px, keine CSP-Meldung und keine Konsolenfehler (außer dem erwarteten 401 auf `/auth/refresh` vor der Anmeldung).

**Abweichungen:**

- **Zwei Server-Ergänzungen ohne Auftrag:** `GET /garage/api/vehicles` (Garagen mit Fahrzeugen für Auswahlen und Chips, neuer `Api\VehicleController`) und das Feld `created_by` in den Dokument-Details (für „Abgelegt von …“).
- **„Hängt an …“ nennt die Art des Ziels** (Ereignis, Schaden …), nicht dessen Titel: der Server liefert für Anhänge nur Tabelle und ID.
- **PDF-Seiten entstehen schon bei der Auswahl**, nicht erst beim Absenden; der Fortschritt „Rendere PDF-Seite i von n“ erscheint dort.
- **`.mjs` als `text/javascript`** per `AddType` in `web/.htaccess` (sonst verweigert der Browser das Modul); CSP unverändert. `.gitattributes`: `web/vendor/** -text`, damit die Prüfsummen byte-genau bleiben. Prüfsummen: `pdf.min.mjs` `27fc2a05…`, `pdf.worker.min.mjs` `1baa1844…` (vollständig in `VERSION.md`).
- **Nicht im Browser geprüft:** Rolle `lesend` (kein zweites Konto), Kamera-Aufnahme auf Android, Breiten über 360 px, ein echtes Werkstatt-PDF (stattdessen ein gedrucktes). Standardschrift-Warnungen von pdf.js traten nicht auf.
- **Testdaten auf Strato:** Garage „M3P4 Garage …“ mit Testkonto (`@example.invalid`), zwei Fahrzeugen, Dokumenten 30–32 und einem Eingangseintrag; in Phase 7 sperren oder löschen.
