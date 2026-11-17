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

## Regeln

- Gestaltung: noch offen. Sie wird im Oberflächen-Meilenstein gemeinsam erarbeitet; bis dahin keine Farben, Schriften oder Layouts festlegen, die über einen funktionalen Platzhalter hinausgehen.
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
