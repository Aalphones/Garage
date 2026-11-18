# 017 — Gestaltung: „GARAGE Dark Botanical“

**Status:** Akzeptiert (2026-11-18) — Meilenstein 4, Planung; Entwurf vom Inhaber freigegeben.

## Kontext

Bis Meilenstein 3 war die Oberfläche ein Schwarz-Weiß-Platzhalter; die Gestaltung sollte gemeinsam im Oberflächen-Meilenstein entstehen. Webfonts und fremde Server verbietet die CSP (`'self'`). DISPATCH auf derselben Domain hat ein dunkles Teal-Grün-Thema.

## Entscheidung

- **Dunkel ist Standard**, hell umschaltbar (Konto → Darstellung: Wie das Gerät, Dunkel, Hell; gespeichert in `localStorage['garage.theme']`).
- **Flächen fast schwarz/grau** (`#090C0C`, `#131918`, `#1B2322`) mit Verläufen ins Dunkelgrüne; **Teal `#43D6C5` nur als Hervorhebung** (Hauptaktion, aktive Navigation, Reiter, Fokus); Grün `#78C850` nur im Signet.
- **Zustandsfarben** fest und immer mit Wort: Rot überfällig, Gelb bald (unter 3 Monaten/3.000 km) oder unsicher; unauffällig ist neutral grau, nie grüne Fläche.
- **Hintergrundbild** des Inhaltsbereichs (Bild des Inhabers, `web/img/hintergrund.jpg`) mit 16 % Deckkraft, hell farblich umgekehrt mit 7 %.
- **Systemschrift**, Radien 14 px (Karten) und rund (Knöpfe, Chips), Navigation eckig mit Akzentlinie; Bewegung 150–220 ms, aus bei `prefers-reduced-motion`.
- **Quelle der Werte:** `web/css/tokens.css`, abgeleitet aus `docs/archive/…/2026-11-18_oberflaeche/artifacts/entwurf-oberflaeche.html` (bis zur Archivierung unter `docs/planning/`).

## Verworfen

- **Kennzeichen-Blau** (papierhell, Tintenblau) und **Werkstatt-Petrol**: weniger Nähe zu DISPATCH und zum Wunsch des Inhabers nach dunklem Cockpit.
- **Exakte DISPATCH-Tokens** (`#071D1B` als Grundfläche): zu grün für eine Datenoberfläche; der Inhaber wollte mehr Schwarz/Grau mit Teal als Akzent.

## Konsequenzen

- Jede Textfarbe ist gegen Hintergrund, Fläche und erhöhte Fläche auf mindestens 4,5:1 gerechnet (Werte im Entwurfs-README); neue Farben nur über `tokens.css` und mit Kontrastrechnung.
- Das Hintergrundbild enthält eine Markenraute; bei Konten für Fremde neu bewerten.
