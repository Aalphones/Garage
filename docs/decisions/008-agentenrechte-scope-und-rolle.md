# 008 — Agentenrechte: Scope und Rolle, `create_vehicle`, nur CIMD

**Status:** Akzeptiert (2026-11-17)

## Kontext

Ein Agent handelt für einen Nutzer, der in mehreren Garagen unterschiedliche Rollen haben kann, mit einem Token, das nur bestimmte Scopes trägt. Das Fachkonzept nennt kein Tool zum Anlegen eines Fahrzeugs, ohne gibt es aber kein Fahrzeug, bevor die Oberfläche kommt. DISPATCH kennt neben CIMD einen manuellen Rückfall-Client.

## Entscheidung

- **Recht = Scope des Tokens und Rolle des Nutzers.** Schreiben darf, wer in der Garage `inhaber` oder `mitglied` ist **und** `garage:write` im Token hat (`Actor::canWrite`). Die Rolle `lesend` schreibt nie, egal welcher Scope.
- **Fehlender Scope ist ein Protokollfehler:** ruft ein Token ohne `garage:write` ein schreibendes Tool auf, antwortet der Endpunkt mit 403, JSON-RPC-Fehler `-32003` und `WWW-Authenticate: Bearer error="insufficient_scope"` (wie DISPATCH), damit ein Client einen erweiterten Scope anfragen kann. `tools/list` zeigt solchen Tokens die schreibenden Tools gar nicht. Eine fehlende Rolle ist dagegen ein Tool-Fehler `forbidden` mit Satz.
- **Fremd heißt nicht gefunden:** ein Fahrzeug oder eine Garage außerhalb der Garagen des Akteurs ist `not_found`, nie `forbidden` — fremde IDs lassen sich nicht erraten.
- **Neues Tool `create_vehicle`** (Scope write): `label`, optional `garage_id` (Pflicht, wenn der Nutzer mehr als eine beschreibbare Garage hat), optional `facts`.
- **Nur CIMD-Clients.** Der manuelle Rückfall-Client aus DISPATCH entfällt; Apps ohne CIMD nutzen ein festes `gmcp_`-Token.
- **Ein gesperrter Nutzer** kann mit keinem Token weiterarbeiten: `McpAuth` prüft den Nutzerstatus bei jedem Aufruf (die Abfrage für feste Tokens tut das nicht selbst).
- **Kein Agenten-Recht** zum Löschen, Übergeben, für Mitglieder oder Klärfall-Lösungen — auch nicht mit `garage:write`.

## Konsequenzen

- Erlaubte Fahrzeuge je Token (Konzept: „optional“) entfallen in Meilenstein 1 (Follow-up).
- Rollen- und Scope-Prüfung sitzen an einer Stelle (`Actor`, `VehicleService::assertCanWrite`), nicht in den Tools.
