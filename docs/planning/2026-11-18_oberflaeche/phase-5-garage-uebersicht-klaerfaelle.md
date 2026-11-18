# Phase 5 — Seiten Garage, Fahrzeug-Übersicht, Klärfälle

Rating: standard · Modell: sonnet · braucht Phase 1 und 2

## Kontext

- `artifacts/entwurf-oberflaeche.html` — Bildschirme „Garage“, „Golf · Übersicht“, „Klärfälle“ (Springen-Menü), je Zustand leer/lädt/Fehler und Rolle `lesend` über die Entwurfsleiste; Funktionen `viewGarage`, `carCard`, `viewVehicle`, `tabOverview`, `dueList`, `viewOpenItems`, `oiCard` im Skript des Entwurfs sind die Markup-Vorlage
- README → „Kontrakt“; `docs/routes.md` (`GET vehicles`, `open-items`, `open-items/{id}/resolve`)
- `docs/decisions/018-oberflaeche-seiten-und-lese-api.md` (Seitenaufteilung)
- `web/js/shell.js`, `dropdown.js`, `toast.js`, `sheet.js`, `icons.js` (Phase 1), `web/js/api.js`, `session.js`, `openitem.js`, `account.js`, `web/index.html`

## AK

Garage (`web/garage.html` + `web/js/garage.js`):
- [ ] Kennzahlen-Leiste: Fahrzeuge aktiv · überfällig (Summe `status = ueberfaellig` aus `GET /garage/api/due`) · Klärfälle offen · neu im Eingang; Werte verlinken auf Garage/Wartung des ersten betroffenen Fahrzeugs/`klaerfaelle.html`/`eingang.html`; ein abweichender Wert farbig (rot überfällig, gelb Klärfälle), sonst neutral.
- [ ] Status-Chips „Aktiv“ (Standard), „Verkauft“, „Stillgelegt“, „Alle“ (ohne Gelöscht); „Gelöscht“ kommt in Phase 8.
- [ ] Je Garage eine Gruppe (Kopf: Name, Rolle, Anzahl je Status) mit Karten im Raster `minmax(min(100%, 20rem), 1fr)`; Karte = Link auf `fahrzeug.html#<id>/uebersicht` mit Titelbild (`documents.cover.preview_url` aus `GET vehicles/{id}` — **nicht** pro Karte nachladen: `GET vehicles` liefert kein Titelbild; deshalb Phase-5-Erweiterung von `VehicleService::list` um `cover_url` (über `DocumentService::forVehicle`) und `plate` (Kennzeichen der aktuellen Zulassung: `valid_to` leer oder in der Zukunft, jüngstes `valid_from`; sonst `null`) je Fahrzeug; wirkt auch auf `list_vehicles`, in `routes.md` vermerken), Kennzeichen-Schild aus `plate` (ohne Zulassung kein Schild, nur Name), Name, „≈ km“ mit Rechenweg im `title`, nächste Fälligkeit mit Zustand, Klärfälle, Belegzahl. Ohne Foto: Fläche `--raised` mit Auto-Symbol.
- [ ] Leer: „Noch kein Fahrzeug“ + Satz „Sag deinem Agenten im Chat: „Leg meinen Golf in GARAGE an“ und gib ihm ein Foto vom Fahrzeugschein.“; Laden: zwei `.skel`; Fehler: `.err` mit „Neu laden“.
- [ ] Unterhalt und Wertspanne erscheinen nicht (Meilenstein 5), auch kein Platzhalter.
- [ ] Nach der Anmeldung (`account.js`/`login.js`) und als `start_url` im Manifest: `garage.html`.

Fahrzeug (`web/fahrzeug.html` + `web/js/vehicle.js`):
- [ ] Adresse `fahrzeug.html#<id>/<reiter>`, Reiter `uebersicht`, `scheckheft`, `wartung`, `messwerte`, `reifen`, `teile`, `belege`; ein Reiterwechsel ändert nur den Hash, Kopf und Leiste bleiben stehen, Inhalt blendet 220 ms ein (nicht bei `prefers-reduced-motion`). Reiter-Inhalte der Phasen 6–7 zeigen bis dahin `.empty` „Kommt mit Phase 6/7“ — wird dort ersetzt.
- [ ] Kopf: „‹ Garage“, Kennzeichen-Schild, Name, Status-Wort wenn nicht aktiv; Aktionsleiste `.vacts` bleibt bis Phase 8 leer.
- [ ] Reiter `.tabs` angeheftet; „Übersicht“ mit Zähler offener Klärfälle (neutral).
- [ ] Übersicht: Kennzahlen (≈ km heute mit Rechenweg, überfällig, bald oder unsicher = `bald` + `rule_uncertain`, Klärfälle); zweispaltig ab 992 px: links Fälligkeiten (vier dringendste aus `GET vehicles/{id}/due`, Zeile → Reiter Wartung) und letztes Ereignis (erstes aus `GET vehicles/{id}/events`), rechts Klärfälle des Fahrzeugs (drei, → `klaerfaelle.html#fz=<id>`), Notizen (aufklappbar, `GET …/notes`), Schäden und Rückrufe (`GET …/damages`, je eine Zeile); darunter in voller Breite „Angaben“: `.facts.two` mit drei Spalten Bezeichnung · Wert · Herkunfts-Abzeichen, strittige Werte gelb mit Link „strittig“; Knopf „Alle Angaben, Ausstattung, Zulassungen“ klappt die restlichen Fakten, Spezifikationen, Ausstattung und Zulassungen in derselben Darstellung auf.
- [ ] Herkunfts-Abzeichen (`web/js/provenance.js`, exportiert `badge(source, confidence, detail)` und Popover wie `tip()` im Entwurf): Quelle, Sicherheit, Beleg, erfasst von/am; bei Quelle Beleg Knopf „Beleg öffnen“ → `belege.html#beleg/<source_document_id>` (nur wenn vorhanden).
- [ ] Kamera-Knopf `.fab` „Beleg“ unten rechts nur bei Status `aktiv` und `permissions.write` → `eingang.html?fahrzeug=<id>` (Eingang wählt das Fahrzeug vor; `inbox.js` liest den Parameter).

Klärfälle (`web/klaerfaelle.html` + `web/js/openitem.js` umgebaut):
- [ ] Liste aus `GET open-items`, Chips „Alle Fahrzeuge“ + je Fahrzeug; Hash `#fz=<id>` filtert vor.
- [ ] Konflikt: zwei Optionen nebeneinander (ab 560 px), „Bisher gespeichert“ / „Neu gemeldet“ mit Wert und Abzeichen; Knöpfe „<Wert A> gilt“, „<Wert B> gilt“ (primär), „Beide falsch“; nachfragen/beobachten: „Erledigt“ (primär), „Verwerfen“; „Notiz“ öffnet ein Blatt (höchstens 500 Zeichen) — die Notiz geht mit der Entscheidung mit.
- [ ] Entscheidung wird 6 s verzögert gesendet; Leiste „Entscheidung gespeichert · Rückgängig“ bricht vor dem Senden ab (danach endgültig, ADR-010). `can_resolve = false` → Knöpfe fehlen, Satz „Nur Inhaber und Mitglieder können Klärfälle lösen.“
- [ ] Leer: „Alles geklärt“. Klärfälle-Abschnitt in `index.html` (Konto) entfällt.
- [ ] Prüfung: 360 px hell/dunkel ohne waagerechtes Scrollen, Rolle `lesend` (Testkonto) ohne Aktionen, Tastatur: Reiter, Abzeichen-Popover, Blatt.

## Checkliste

- [ ] Seiten und Module; `VehicleService::list` um `cover_url` und `plate`
- [ ] Manifest `start_url`, Weiterleitung nach Anmeldung
- [ ] Doc-Update: `docs/code-map.md`, `docs/conventions/frontend.md` → „Seiten“, `docs/routes.md` (`cover_url`)

## Report-Back
