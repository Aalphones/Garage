# 021 — Kleine Eingaben durch Menschen im Browser

**Status:** Akzeptiert (2026-11-18) — Meilenstein 4, Planung; Wunsch des Inhabers („nur kleine Eingaben“).

## Kontext

Bis Meilenstein 3 erfassen nur Agenten. Menschen wollen im Browser ohne Chat einen Kilometerstand eintragen, eine Notiz schreiben, ein Titelbild wählen und einen falschen Wert korrigieren. Rechnungen mit Positionen bleiben beim Agenten.

## Entscheidung

- **Vier Eingaben**, je eine Route, Rolle `inhaber` oder `mitglied`, nur JWT: Kilometerstand (`OdometerService`, Herkunft `nutzer`, Sicherheit `bestaetigt`; ein Rückwärts-Stand wird wie beim Agenten ein Klärfall), Notiz anlegen oder als neue Revision bearbeiten (`NoteService::upsert`), Titelbild setzen (`DocumentService::attach`, Ziel `fahrzeug`, Rolle `titelbild`, nur Fotos), Stammdaten-Wert korrigieren.
- **Korrigieren ersetzt sofort**, auch einen bestätigten Wert: der Mensch im Browser ist die Instanz, die sonst Klärfälle entscheidet (ADR-010). Der neue Wert wird mit Herkunft `nutzer`/`bestaetigt` aktuell, der alte bleibt als abgelöste Zeile im Verlauf, Audit `vehicle_fact.set` mit `via: web`. Technisch: dieselbe Ablösung wie `VehicleService::writeCurrent`, ohne den Weg über `recordDispute`.
- **Ein strittiges Feld** (offener Klärfall) lässt sich nicht korrigieren — die Oberfläche verweist auf den Klärfall, die API antwortet 409 `open_item_pending`.
- Korrigierbar sind die Stammdaten aus `VehicleService::FIELDS`; Spezifikationen, Ausstattung, Zulassungen und Ereignisse nicht.

## Verworfen

- **Korrektur als Klärfall** wie beim Agenten: der Mensch müsste seinen eigenen Klärfall gleich danach selbst lösen.
- **Alles im Browser** (jedes Agenten-Werkzeug als Dialog): verdoppelt den Meilenstein; Rechnungen erfasst der Agent besser.

## Konsequenzen

- Ein Mensch kann einen belegten Wert mit einem falschen überschreiben; der alte Wert und der Beleg bleiben im Verlauf, und `list_changes` zeigt die Änderung.
- Agenten sehen menschliche Korrekturen als `nutzer`/`bestaetigt` und können sie nicht überschreiben, nur bestreiten.
