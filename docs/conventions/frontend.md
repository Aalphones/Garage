# Frontend Conventions — GARAGE

> **Source-of-truth references:**
> - Fachkonzept: [`../design/konzept-garage.md`](../design/konzept-garage.md) → „Web-Oberfläche"
> - [WCAG 2.2](https://www.w3.org/TR/WCAG22/)

## Stack

| Layer | Choice |
|---|---|
| Markup | statische HTML-Hülle in `web/` |
| Script | Vanilla-JS als ES-Module, kein Build-Schritt; lädt alle Daten über `/garage/api` |
| Styling | eigenes CSS ohne Präprozessor; Design-Tokens in `web/css/tokens.css` (dunkel Standard, hell über `data-theme`), ADR-017 |
| Diagramme | SVG im Browser (`web/js/chart.js`), ADR-018 |
| Tests | keine (ADR-004) |

## Library Policy

**Forbidden:**
- JS- und CSS-Bibliotheken, Webfonts, alles von fremden Servern — die CSP in `web/.htaccess` erlaubt nur `'self'`.
- Inline-Skripte und Event-Attribute (`onclick=…`).

**Einzige Ausnahme:** pdf.js 4.10.38 liegt lokal unter `web/vendor/pdfjs/` (ADR-014) und wird nur von `web/js/pdfpages.js` geladen, per `import()` erst bei einem PDF. Ein Update ist eine bewusste Entscheidung mit neuer Prüfsumme in `web/vendor/pdfjs/VERSION.md`; die CSP bleibt `'self'`.

## Service Worker

`web/sw.js` hat zwei Aufgaben. **Teilen-Ziel** (Android, E14): er fängt den POST auf `/garage/teilen` ab. **Hülle der Notfallkarte** (ADR-025): beim `install` legt er `notfall.html` und die Dateien, die sie braucht (Liste `SHELL_FILES`), in `garage-notfall-huelle-v<n>`; GET auf genau diese Pfade geht erst ans Netz und frischt die Hülle auf, bei Netzfehler kommt sie aus dem Cache. Jede andere Anfrage fasst er nicht an (kein `respondWith`); Seiten laden frisch vom Server. Braucht `notfall.html` eine neue Datei (auch einen neuen Import in `notfall.js`, `emergency.js`, `share.js`, `dom.js`), gehört sie in `SHELL_FILES` und der Cache-Name bekommt die nächste Nummer — `activate` löscht die alten. `sw.js` wird mit `no-cache` und `Service-Worker-Allowed: /garage/` ausgeliefert; angemeldet wird er per `registerWorker()` auf jeder Seite außer Anmeldung, Einladung und Zustimmung.

Caches im Gerät und wer sie löscht:

| Cache | Inhalt | Schreibt | Löscht |
|---|---|---|---|
| `garage-geteilt` | geteilte Dateien eines Teilen-Vorgangs | `sw.js` (Teilen-POST) | `eingang.html` beim Übernehmen; der nächste Teilen-Vorgang |
| `garage-notfall` | je Fahrzeug `/garage/notfall/<id>.json` (Antwort von `GET vehicles/{id}/emergency`) | Reiter Papiere nach jedem Laden, `garage.html` für jedes aktive Fahrzeug | `logout()` in `api.js` |
| `garage-notfall-huelle-v<n>` | Hülle von `notfall.html`, keine Daten | `sw.js` | `sw.js` beim Aktivieren einer neuen Version |

Keine weiteren Daten im Gerät: Konto-Daten liegen weder in `localStorage` noch in einem anderen Cache (ADR-018).

## Seiten

`garage.html` (Garage: Kennzahlen, Fahrzeugkarten je Garage, Filter Aktiv/Verkauft/Stillgelegt/Alle, für Inhaber Gelöscht mit „Wiederherstellen“), `fahrzeug.html` (Adresse `#<id>/<reiter>`, acht Reiter Übersicht, Scheckheft, Wartung, Messwerte, Reifen, Teile, Belege, Kosten; Kopf-Aktionen Drucken, Markdown, Status, Löschen; Kamera-Knopf), `klaerfaelle.html` (offene Klärfälle, Filter je Fahrzeug, Entscheidung nach 6 s mit „Rückgängig“), `eingang.html` (Fotos und PDFs in den Eingang legen, Eingang ansehen), `belege.html` (Belege als Liste oder Galerie, Detail unter `#beleg/<id>`), `notfall.html` (Notfallkarte ohne Netz und ohne Anmeldung aus dem Gerätecache; `#<id>` eine Karte, sonst alle; ohne Seitenschale), `index.html` (Anmeldung und Konto mit Darstellung; nach der Anmeldung geht es zu `garage.html`), `einladung.html`, `zustimmung.html`. Dazu die Druckansicht `/garage/akte/{slug}`: serverseitig aus `templates/akte-druck.php` und `css/print.css`, ohne Skript, signierter Link (600 s), kein statisches HTML in `web/`. Die Seitenschale (Seitenleiste ab 992 px, darunter untere Leiste) baut `js/shell.js` auf jeder Seite. Der Entwurf liegt in `docs/archive/2026-11/2026-11-17_belege-und-fotos/artifacts/entwurf-belege.html`.

## Regeln

- Gestaltung: „GARAGE Dark Botanical“, Entscheidung in ADR-017. Alle Farben, Radien und Zeiten kommen als Tokens aus `web/css/tokens.css` (dunkel Standard, hell über `data-theme`); keine harten Farbwerte in `base.css` außer dem Kennzeichen. Das Thema setzt `js/theme.js` aus `localStorage['garage.theme']`. Jede Seite bindet `tokens.css` vor `base.css` ein und lädt `theme.js` als Modul im `<head>`.
- Diagrammfarben `--s1 … --s4` (Kosten-Blöcke) gelten nur für Reihen, nie für Zustände; Rot und Gelb bleiben den Zuständen. Jedes Diagramm hat eine Achse und eine Tabellen- oder Listenansicht seiner Werte.
- Mobil zuerst: 360 px Breite ohne waagerechtes Scrollen, Hauptaktion in Daumenreichweite, hell und dunkel.
- Herkunft sichtbar: jeder Wert mit Herkunfts-Abzeichen; geschätzte Werte mit „≈" und Rechenweg.
- Leere Zustände nennen den nächsten Schritt statt einer leeren Tabelle.
- Das Access-Token liegt nur im Arbeitsspeicher, nie in `localStorage`. Anzeige-Einstellungen (Ansicht je Liste, Thema) liegen in `localStorage` unter `garage.*` (`garage.theme`, `garage.view.<liste>`, `garage.ansicht.tankbuch`, `garage.kosten.zeitraum`, `garage.ansicht.unterhalt`), nie Daten oder Tokens.
- Alle Pfade zur API absolut mit Präfix `/garage/api/` (ADR-001).
- Druckansicht funktioniert ohne Skript.
- Sprunglink zum Inhalt, sichtbare Fokusrahmen, Kontrast mindestens WCAG AA, Alt-Text, genau eine `h1` pro Seite.

## Critical Rules

1. **Kein Inline-Skript** — die CSP blockiert es, und eine Ausnahme würde private Fahrzeugdaten angreifbar machen.
2. **Private Daten nur nach Anmeldung** — die Hülle selbst enthält keine Daten, alles kommt über die API.
