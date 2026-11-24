# 025 — Tanken, Ausgaben, Notfallkarte

**Status:** Akzeptiert (2026-11-24) — Meilenstein 5, Phase 1; erweitert [ADR-021](021-kleine-eingaben-durch-menschen.md).

## Kontext

Tankungen und kleine Ausgaben (Parken, Wäsche, Maut) fallen unterwegs an und sind für Menschen schneller im Browser eingetragen als über den Agenten. Im Pannenfall braucht man Kennzeichen, FIN und Versicherung auch ohne Netz.

## Entscheidung

- **Zwei weitere Eingaben im Browser** für Inhaber und Mitglieder: Tankung (Datum, km, Liter, Betrag, voll) und Ausgabe (Datum, Kategorie, Betrag, Notiz); Herkunft `nutzer`/`bestaetigt`. Der km-Stand einer Tankung wird ein Kilometerstand; ein Rückwärts-Stand wird ein Klärfall.
- **Löschen durch Menschen** (mit Rückgängig): Tankungen, Ausgaben, Verträge, Vorgänge, Bewertungen, Inserate, Zubehör und Plaketten; Garagen-Verträge nur mit Schreibrecht in der Garage.
- **Notfallkarte offline:** nach dem Laden liegt sie im Cache des Geräts (`garage-notfall`), `notfall.html` öffnet sie ohne Netz; Abmelden löscht den Cache.

## Verworfen

- **Ganze App offline:** Aktualität nach einem Deploy, Aufwand.
- **Notfallkarte in `localStorage`:** [ADR-018](018-oberflaeche-seiten-und-lese-api.md) erlaubt dort keine Daten.

## Konsequenzen

- Kennzeichen, FIN und Vertragsnummern stehen unverschlüsselt im Browser-Cache, bis abgemeldet wird.
- Wer sich nicht abmeldet (Gerät verloren), lässt die Karte auf dem Gerät.
