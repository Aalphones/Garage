# Frontend Conventions — GARAGE

> **Source-of-truth references:**
> - Fachkonzept: [`../design/konzept-garage.md`](../design/konzept-garage.md) → „Web-Oberfläche"
> - [WCAG 2.2](https://www.w3.org/TR/WCAG22/)

## Stack

| Layer | Choice |
|---|---|
| Markup | statische HTML-Hülle in `web/` |
| Script | Vanilla-JS als ES-Module, kein Build-Schritt; lädt alle Daten über `/garage/api` |
| Styling | eigenes CSS ohne Präprozessor; Design-Tokens entstehen mit dem Oberflächen-Meilenstein |
| Diagramme | Server-SVG (Renderer aus DISPATCH) |
| Tests | keine (ADR-004) |

## Library Policy

**Forbidden:**
- JS- und CSS-Bibliotheken, Webfonts, alles von fremden Servern — die CSP in `web/.htaccess` erlaubt nur `'self'`.
- Inline-Skripte und Event-Attribute (`onclick=…`).

**Einzige Ausnahme:** pdf.js 4.10.38 liegt lokal unter `web/vendor/pdfjs/` (ADR-014) und wird nur von `web/js/pdfpages.js` geladen, per `import()` erst bei einem PDF. Ein Update ist eine bewusste Entscheidung mit neuer Prüfsumme in `web/vendor/pdfjs/VERSION.md`; die CSP bleibt `'self'`.

## Service Worker

`web/sw.js` existiert nur fürs Teilen-Ziel auf Android (E14): er fängt den POST auf `/garage/teilen` ab. Er legt kein Offline-Cache der Oberfläche an und fasst keine andere Anfrage an (kein `respondWith` außerhalb des Teilen-POST); Seiten laden immer frisch vom Server. Der Cache `garage-geteilt` wird beim Übernehmen in `eingang.html` geleert. `sw.js` wird mit `no-cache` und `Service-Worker-Allowed: /garage/` ausgeliefert.

## Seiten

`index.html` (Anmeldung, Konto; nach der Anmeldung geht es zu `garage.html`), `garage.html` (Garage: Kennzahlen, Fahrzeugkarten je Garage), `fahrzeug.html` (Adresse `#<id>/<reiter>`, sieben Reiter, nur Übersicht gefüllt, Kamera-Knopf), `klaerfaelle.html` (offene Klärfälle, Filter je Fahrzeug, Entscheidung nach 6 s mit „Rückgängig“), `eingang.html` (Fotos und PDFs in den Eingang legen, Eingang ansehen), `belege.html` (Belege als Liste oder Galerie, Detail unter `#beleg/<id>`), `einladung.html`, `zustimmung.html`. Die Navigation „Konto · Eingang · Belege“ steht unter der `h1` der drei Hauptseiten. Der Entwurf liegt in `docs/archive/2026-11/2026-11-17_belege-und-fotos/artifacts/entwurf-belege.html`.

## Regeln

- Gestaltung: „GARAGE Dark Botanical“, Entscheidung in ADR-017. Alle Farben, Radien und Zeiten kommen als Tokens aus `web/css/tokens.css` (dunkel Standard, hell über `data-theme`); keine harten Farbwerte in `base.css` außer dem Kennzeichen. Das Thema setzt `js/theme.js` aus `localStorage['garage.theme']`. Jede Seite bindet `tokens.css` vor `base.css` ein und lädt `theme.js` als Modul im `<head>`.
- Mobil zuerst: 360 px Breite ohne waagerechtes Scrollen, Hauptaktion in Daumenreichweite, hell und dunkel.
- Herkunft sichtbar: jeder Wert mit Herkunfts-Abzeichen; geschätzte Werte mit „≈" und Rechenweg.
- Leere Zustände nennen den nächsten Schritt statt einer leeren Tabelle.
- Das Access-Token liegt nur im Arbeitsspeicher, nie in `localStorage`.
- Alle Pfade zur API absolut mit Präfix `/garage/api/` (ADR-001).
- Druckansicht funktioniert ohne Skript.
- Sprunglink zum Inhalt, sichtbare Fokusrahmen, Kontrast mindestens WCAG AA, Alt-Text, genau eine `h1` pro Seite.

## Critical Rules

1. **Kein Inline-Skript** — die CSP blockiert es, und eine Ausnahme würde private Fahrzeugdaten angreifbar machen.
2. **Private Daten nur nach Anmeldung** — die Hülle selbst enthält keine Daten, alles kommt über die API.
