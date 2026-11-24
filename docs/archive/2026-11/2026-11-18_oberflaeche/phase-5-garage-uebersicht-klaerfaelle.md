# Phase 5 — Seiten Garage, Fahrzeug-Übersicht, Klärfälle

Rating: standard · Modell: sonnet · braucht Phase 1 und 2

## Kontext

- `artifacts/entwurf-oberflaeche.html` — Bildschirme „Garage“, „Golf · Übersicht“, „Klärfälle“ (Springen-Menü), je Zustand leer/lädt/Fehler und Rolle `lesend` über die Entwurfsleiste; Funktionen `viewGarage`, `carCard`, `viewVehicle`, `tabOverview`, `dueList`, `viewOpenItems`, `oiCard` im Skript des Entwurfs sind die Markup-Vorlage
- README → „Kontrakt“; `docs/routes.md` (`GET vehicles`, `open-items`, `open-items/{id}/resolve`)
- `docs/decisions/018-oberflaeche-seiten-und-lese-api.md` (Seitenaufteilung)
- `web/js/shell.js`, `dropdown.js`, `toast.js`, `sheet.js`, `icons.js` (Phase 1), `web/js/api.js`, `session.js`, `openitem.js`, `account.js`, `web/index.html`

## AK

Garage (`web/garage.html` + `web/js/garage.js`):
- [x] Kennzahlen-Leiste: Fahrzeuge aktiv · überfällig (Summe `status = ueberfaellig` aus `GET /garage/api/due`) · Klärfälle offen · neu im Eingang; Werte verlinken auf Garage/Wartung des ersten betroffenen Fahrzeugs/`klaerfaelle.html`/`eingang.html`; ein abweichender Wert farbig (rot überfällig, gelb Klärfälle), sonst neutral.
- [x] Status-Chips „Aktiv“ (Standard), „Verkauft“, „Stillgelegt“, „Alle“ (ohne Gelöscht); „Gelöscht“ kommt in Phase 8.
- [x] Je Garage eine Gruppe (Kopf: Name, Rolle, Anzahl je Status) mit Karten im Raster `minmax(min(100%, 20rem), 1fr)`; Karte = Link auf `fahrzeug.html#<id>/uebersicht` mit Titelbild (`documents.cover.preview_url` aus `GET vehicles/{id}` — **nicht** pro Karte nachladen: `GET vehicles` liefert kein Titelbild; deshalb Phase-5-Erweiterung von `VehicleService::list` um `cover_url` (über `DocumentService::forVehicle`) und `plate` (Kennzeichen der aktuellen Zulassung: `valid_to` leer oder in der Zukunft, jüngstes `valid_from`; sonst `null`) je Fahrzeug; wirkt auch auf `list_vehicles`, in `routes.md` vermerken), Kennzeichen-Schild aus `plate` (ohne Zulassung kein Schild, nur Name), Name, „≈ km“ als reiner Text (kein Popover in der Karte — sie ist ein Link; der Rechenweg steht in der Übersicht), nächste Fälligkeit mit Zustand, Klärfälle, Belegzahl. Ohne Foto: Fläche `--raised` mit Auto-Symbol.
- [x] Leer: „Noch kein Fahrzeug“ + Satz „Sag deinem Agenten im Chat: „Leg meinen Golf in GARAGE an“ und gib ihm ein Foto vom Fahrzeugschein.“; Laden: zwei `.skel`; Fehler: `.err` mit „Neu laden“.
- [x] Unterhalt und Wertspanne erscheinen nicht (Meilenstein 5), auch kein Platzhalter.
- [x] Nach der Anmeldung (`account.js`/`login.js`) und als `start_url` im Manifest: `garage.html`.

Fahrzeug (`web/fahrzeug.html` + `web/js/vehicle.js`):
- [x] Adresse `fahrzeug.html#<id>/<reiter>`, Reiter `uebersicht`, `scheckheft`, `wartung`, `messwerte`, `reifen`, `teile`, `belege`; ein Reiterwechsel ändert nur den Hash, Kopf und Leiste bleiben stehen, Inhalt blendet 220 ms ein (nicht bei `prefers-reduced-motion`). Reiter-Inhalte der Phasen 6–7 zeigen bis dahin `.empty` „Kommt mit Phase 6/7“ — wird dort ersetzt.
- [x] Kopf: „‹ Garage“, Kennzeichen-Schild, Name, Status-Wort wenn nicht aktiv; Aktionsleiste `.vacts` bleibt bis Phase 8 leer.
- [x] Reiter `.tabs` angeheftet; „Übersicht“ mit Zähler offener Klärfälle (neutral).
- [x] Übersicht: Kennzahlen (≈ km heute als `button.est`, Tippen zeigt den Rechenweg aus `km_estimate.basis` und `per_day`, überfällig, bald oder unsicher = `bald` + `rule_uncertain`, Klärfälle); zweispaltig ab 992 px: links Fälligkeiten (vier dringendste aus `GET vehicles/{id}/due`, Zeile → Reiter Wartung) und letztes Ereignis (erstes aus `GET vehicles/{id}/events`), rechts Klärfälle des Fahrzeugs (alle offenen als aufklappbare Zeilen `.xrow`: Antippen zeigt denselben Inhalt wie auf der Seite Klärfälle — beide Werte, Knöpfe, Notiz — und löst dort mit derselben verzögerten Entscheidung; Inhalt aus einem gemeinsamen Modul `web/js/openitem-view.js`, das auch `klaerfaelle.html` nutzt; Link „Alle Fahrzeuge“ → `klaerfaelle.html`), Notizen (aufklappbar, `GET …/notes`), Schäden und Rückrufe (`GET …/damages`, je eine Zeile); darunter in voller Breite „Angaben“: `.facts.two` mit drei Spalten Bezeichnung · Wert · Herkunfts-Abzeichen, strittige Werte gelb mit Link „strittig“; Knopf „Alle Angaben, Ausstattung, Zulassungen“ klappt die restlichen Fakten, Spezifikationen, Ausstattung und Zulassungen in derselben Darstellung auf.
- [x] Herkunfts-Abzeichen (`web/js/provenance.js`, exportiert `badge(source, confidence, detail)`, das Herkunfts-Popover wie `tip()` und `tipText(anchor, title, text)` für Rechenwege wie im Entwurf; Popover schließt bei Tippen daneben und mit Escape): Quelle, Sicherheit, Beleg, erfasst von/am; bei Quelle Beleg Knopf „Beleg öffnen“ → `belege.html#beleg/<source_document_id>` (nur wenn vorhanden).
- [x] Kamera-Knopf `.fab` „Beleg“ unten rechts nur bei Status `aktiv` und `permissions.write`, auf jedem Reiter: ein `<label class="fab">` mit verstecktem `<input type="file" accept="image/*" capture="environment">` (am Handy öffnet sich direkt die Kamera, am Rechner die Dateiauswahl; Fokusrahmen über `:focus-within`). Nach der Auswahl ohne Seitenwechsel: Foto über `capture.js`/`image.js` verkleinern, `POST documents` (multipart, `vehicle_id`), dann `POST inbox` (`document_ids`, `vehicle_id`); Leiste mit Fortschritt, danach „Foto liegt im Eingang des <Name>“ mit Knopf „Notiz“ (Blatt, 0–200 Zeichen → Notiz am Eingangs-Eintrag; fehlt dafür eine Route, `PATCH inbox/{id}` mit `note` in `InboxController` ergänzen und in `routes.md` vermerken). Fehler → Leiste mit „Erneut versuchen“. PDFs bleiben auf der Seite Eingang.

Klärfälle (`web/klaerfaelle.html` + `web/js/openitem.js` umgebaut):
- [x] Liste aus `GET open-items`, Chips „Alle Fahrzeuge“ + je Fahrzeug; Hash `#fz=<id>` filtert vor.
- [x] Konflikt: zwei Optionen nebeneinander (ab 560 px), „Bisher gespeichert“ / „Neu gemeldet“ mit Wert und Abzeichen; Knöpfe „<Wert A> gilt“, „<Wert B> gilt“ (primär), „Beide falsch“; nachfragen/beobachten: „Erledigt“ (primär), „Verwerfen“; „Notiz“ öffnet ein Blatt (höchstens 500 Zeichen) — die Notiz geht mit der Entscheidung mit.
- [x] Entscheidung wird 6 s verzögert gesendet; Leiste „Entscheidung gespeichert · Rückgängig“ bricht vor dem Senden ab (danach endgültig, ADR-010). `can_resolve = false` → Knöpfe fehlen, Satz „Nur Inhaber und Mitglieder können Klärfälle lösen.“
- [x] Leer: „Alles geklärt“. Klärfälle-Abschnitt in `index.html` (Konto) entfällt.
- [x] Reiter-Leiste: aktiver Reiter wird beim Laden und nach jedem Wechsel in den sichtbaren Bereich gerollt (`tabs.scrollLeft`, wie `route()` im Entwurf).
- [x] Prüfung nach README → „Breakpoints und Handy“ für Garage, alle sieben Reiter-Rahmen, Klärfälle; Rolle `lesend` (Testkonto) ohne Aktionen; Tastatur: Reiter, Abzeichen-Popover, Blatt; am Handy jede Aktion mit dem Daumen erreichbar.

## Checkliste

- [x] Seiten und Module; `VehicleService::list` um `cover_url` und `plate`
- [x] Manifest `start_url`, Weiterleitung nach Anmeldung
- [x] Doc-Update: `docs/code-map.md`, `docs/conventions/frontend.md` → „Seiten“, `docs/routes.md` (`cover_url`)

## Report-Back

Status: complete.

- Umgesetzt: `garage.html`, `fahrzeug.html` (Reiter Übersicht, sechs Platzhalter), `klaerfaelle.html`; gemeinsame Module `openitem-view.js`, `provenance.js`, `widgets.js`, `capture-fab.js`; `VehicleService::list` mit `plate`/`cover_url`/`document_count` (auch `list_vehicles`), `GET vehicles/{id}` mit `plate`, neue Route `PATCH inbox/{id}` (Notiz, nur Status `neu`).
- Geprüft: Chrome mit Attrappen-API bei 360/390/768/1366 px, dunkel und hell — kein Überlauf, keine CSP-/JS-Fehler. PHP-Teil zur Laufzeit ungeprüft (kein PHP lokal), CI-Lint abwarten.
- Abweichung: kleine Links und der km-Knopf bekommen am Handy per `::after` eine 44-px-Trefferfläche (nur zusätzliches CSS in `base.css`).
- Grenze: `beforeunload`-Warnung bei wartender Entscheidung greift nur im Browser-Tab; wird die Seite vom System beendet, geht die 6-s-Entscheidung verloren (Phase 8 prüfen).
- Nicht durchgeklickt: Rolle `lesend`, Leerzustand, Fehlerzustand in der Attrappe.
