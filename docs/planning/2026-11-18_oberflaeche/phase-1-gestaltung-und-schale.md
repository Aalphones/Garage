# Phase 1 — Gestaltungsgrundlage und Schale

Rating: standard · Modell: sonnet

## Kontext

Lesen vor dem Start:

- `artifacts/entwurf-oberflaeche.html` — verbindlich. Den `<style>`-Block 1:1 als Quelle der Tokens und Bauteile nehmen; im Browser öffnen und mit der Entwurfsleiste (Thema, Rolle, Zustand, Springen) durchklicken. `artifacts/README.md` sagt, was Platzhalter ist.
- `docs/decisions/017-gestaltung-dark-botanical.md`
- `docs/conventions/frontend.md` (CSP nur `'self'`, kein Inline-Skript, Token nur im Speicher)
- `docs/code-map.md` → „Ordner in `web/`“
- `web/css/base.css`, `web/js/dom.js`, `web/js/session.js`, `web/js/undo.js`, `web/index.html`, `web/eingang.html`, `web/belege.html`, `web/einladung.html`, `web/zustimmung.html`, `web/manifest.webmanifest`
- Vault-Fehlerklasse `frameworks/css-architecture` → „Freiraum per Innenabstand reserviert“: Freiraum für untere Leiste und Kamera-Knopf **nicht** als großes `padding-bottom` an einem Scroll-Container, sondern am `body`-Fluss (das Dokument scrollt, kein innerer Scroll-Container) oder als `::after`-Abstandhalter.

## Ziel

Alle Seiten tragen die Gestaltung des Entwurfs; eine gemeinsame Schale (Seitenleiste ab 992 px, untere Leiste darunter) steht für die neuen Seiten der Phasen 5–8 bereit.

## AK

- [x] `web/css/tokens.css` enthält genau die Tokens aus dem Entwurf (`:root`/`[data-theme='dark']` und `[data-theme='light']`, inkl. `--grad-*`, `--radius-*`, `--t-*`, `--touch`, `--nav-h`, `--side-w`). Dunkel ist Standard: ohne `data-theme` gilt dunkel. `data-theme` setzt `web/js/theme.js` aus `localStorage['garage.theme']` (`dark`\|`light`\|`system`; `system` folgt `prefers-color-scheme`), bevor die Seite sichtbar wird (Modul im `<head>`, kein Inline-Skript).
- [x] `web/css/base.css` neu: Bauteile aus dem Entwurf mit denselben Klassennamen — `.btn` (+`.primary`, `.quiet`, `.danger`, `.sm`, `[aria-disabled]`), `.plate`, `.st` (+`.bad`, `.warn`, `.ok`, `.unk`), `.src` (Herkunfts-Abzeichen), `.est`, `.kpis`/`.kpi`, `.list`/`.row`, `.cars`/`.group`/`.car`, `.vhead`/`.vacts`/`.tabs`, `.cols`, `.bar`, `.tbl`, `.tb`/`.seg`/`.chip`, `.tl`/`.ev`/`.gapcard`/`.year`, `.att`, `.oi`/`.vs`/`.opt`, `.field`/`.radio`, `.scrim`/`.sheet`, `.fab`, `.toast`, `.tip`, `.empty`/`.skel`/`.err`/`.banner`, `.chart`/`.wheels`, `.tires`/`.tire`, `.copy`, `.docs`/`.doc`, `.facts`/`.fact` (drei Spalten: Bezeichnung, Wert, Abzeichen), `.dd` (gestaltete Auswahl), `.content::before` (Hintergrundbild). Nicht übernehmen: `.dz*`, `.bsp`, `.tool`, `.print` (kommt in Phase 8 als eigene Datei).
- [x] Die Klassen aus Meilenstein 3 (Eingang, Belege, Erfassen; in der alten `base.css` mit „NEU in Phase 4“ bzw. unter den Seitenabschnitten) bleiben funktionsgleich und werden auf Tokens umgestellt (keine harten Farbwerte außer `.plate`).
- [x] Hintergrundbild liegt als `web/img/hintergrund.jpg` (Kopie aus `artifacts/_entwurf-oberflaeche/hintergrund.jpg`), Einbindung wie im Entwurf: `.content::before`, `position: fixed`, Deckkraft 0,16 dunkel / 0,07 hell mit `filter: invert(1) hue-rotate(180deg)`, ab 992 px links ab `--side-w`.
- [x] Schalen-Layout nach README → „Breakpoints und Handy“: unter 992 px scrollt das Dokument (Kopfzeile, untere Leiste fest); ab 992 px `body { height: 100vh; overflow: hidden }`, Raster Seitenleiste + `.content`, die Seitenleiste steht, `.content` ist der einzige Scroll-Container (`overflow-y: auto; scrollbar-gutter: stable`) — angeheftete Reiter kleben dann an `.content`. Skripte, die Bildlauf merken oder zurücksetzen, nehmen ab 992 px `.content`, sonst `document.scrollingElement` (Helfer `scroller()` in `shell.js`, wie im Entwurf).
- [x] Handy-Bedienung in `base.css`: unter 768 px `.chip`, `.seg button`, `.btn.sm`, `.copy`, `.vacts .btn` mindestens `--touch` hoch; `.src` und Zustands-Knöpfe (`.stbtn`) bekommen über `::after` eine Trefferfläche von 44 px Höhe; `.tabs` unter 768 px mit Ausblend-Maske rechts; `button.est` und `.stbtn` öffnen ihr Popover per Tippen (Popover-Helfer `tipText(anchor, title, text)` in `web/js/provenance.js` aus Phase 5 — hier nur die Klassen).
- [x] `web/js/shell.js` exportiert `mountShell({ active })`: baut Seitenleiste (`nav.side`) und untere Leiste (`nav.bottom`) mit „Garage“ (`garage.html`), „Eingang“ (`eingang.html`), „Klärfälle“ (`klaerfaelle.html`), „Konto“ (`index.html`); setzt `aria-current="page"`; Zähler „Eingang“ = Einträge mit Status `neu` aus `GET /garage/api/inbox?status=neu`, „Klärfälle“ = Länge von `GET /garage/api/open-items`; Zähler ausgeblendet bei 0; Zähler-Farbe Primär (nicht Rot). Symbole wie im Entwurf (`I.garage`, `I.inbox`, `I.oi`, `I.user`) in `web/js/icons.js` als exportierte Strings.
- [x] `web/js/dropdown.js` exportiert `dropdown(container, { label, options, value, onChange })` mit Verhalten wie im Entwurf: Knopf mit `aria-haspopup="listbox"`/`aria-expanded`, Liste `role=listbox`, Optionen `role=option` mit Haken bei `aria-selected`, Pfeiltasten, Enter/Leertaste, Escape, Klick außerhalb schließt, öffnet nach oben, wenn unten weniger als 240 px Platz sind.
- [x] `web/js/toast.js` exportiert `toast(text, { undo })` (Leiste unten, 6 s, „Rückgängig“-Knopf); `undo.js` aus Meilenstein 3 wird darauf umgestellt oder durch `toast.js` ersetzt (alle Aufrufer angepasst).
- [x] `web/js/sheet.js` exportiert `openSheet({ title, body, actions })`: Blatt von unten unter 768 px, Dialog darüber; Fokus hinein, gefangen, beim Schließen zurück; Escape schließt.
- [x] Bestehende Seiten `index.html`, `eingang.html`, `belege.html` nutzen die Schale (Navigation „Konto · Eingang · Belege“ unter der `h1` entfällt); `einladung.html` und `zustimmung.html` bekommen Tokens und Bauteile, aber keine Navigation. Genau eine `h1` je Seite bleibt (bei Schale visuell versteckt mit `.sr-only`).
- [x] Konto (`index.html`): neuer Abschnitt „Darstellung“ mit `dropdown` „Wie das Gerät / Dunkel / Hell“ → schreibt `garage.theme` und setzt das Thema sofort. Die Klärfälle-Liste verschwindet aus dem Konto erst in Phase 5.
- [x] `manifest.webmanifest`: `background_color` `#090C0C`, `theme_color` `#090C0C`; `start_url` bleibt bis Phase 5.
- [x] Prüfung nach README → „Breakpoints und Handy“ für jede bestehende Seite (360 px hell und dunkel, 768 px, 1366 px mit stehender Seitenleiste beim Scrollen); Konsole ohne CSP-Fehler.

## Checkliste

- [x] `tokens.css`, `base.css`, `img/hintergrund.jpg` anlegen bzw. ersetzen; alle Seiten binden `tokens.css` vor `base.css` ein
- [x] `theme.js`, `icons.js`, `shell.js`, `dropdown.js`, `toast.js`, `sheet.js`
- [x] Bestehende Seiten umstellen
- [x] Doc-Update: `docs/code-map.md` (`web/`-Tabelle: neue Module, `css/tokens.css`, `img/`), `docs/conventions/frontend.md` → „Regeln“: Satz „Gestaltung: noch offen …“ ersetzen durch Verweis auf ADR-017 und `tokens.css`

## Report-Back

Status: complete (2026-11-23). Geprüft mit Chrome headless (Geräteemulation, API-Attrappe): Seiten Konto, Eingang, Belege, Einladung, Zustimmung bei 360/768/1366 px hell und dunkel, kein waagerechter Überlauf, keine CSP-Meldungen; Seitenleiste steht ab 992 px, Dropdown öffnet.

Abweichung: Meilenstein-3-Klassen `.row`/`.tip`/`.facts` kollidierten mit gleichnamigen Bauteilen des Entwurfs und heißen jetzt `.entry`/`.note`/`.details` (JS und HTML angepasst). Bloße `<button>` ohne Klasse und `.button` übernehmen das Aussehen von `.btn`. Navigationsziele `garage.html` und `klaerfaelle.html` existieren erst ab Phase 5.
