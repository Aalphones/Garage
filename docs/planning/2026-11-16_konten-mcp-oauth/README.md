# Meilenstein 1 — Konten, MCP und OAuth

Ziel: Menschen haben Konten in Garagen, melden sich mit Passwort an und verbinden ChatGPT und Claude per OAuth (oder Claude Code per festem Token) mit `/garage/mcp`. Der Agent sieht nur die Fahrzeuge der Garagen seines Nutzers und kann Fahrzeuge anlegen, lesen und Stammdaten mit Herkunft ergänzen.

Quelle des Umfangs: [`../../PROJECT.md`](../../PROJECT.md) → Meilenstein 1. Vorlage für allen kopierten Code: DISPATCH unter `C:\Users\sasch\develop\Blog\app\` (ADR-005).

## Phasen

| # | Phase | Rating | Status |
|---|---|---|---|
| 1 | [Konten und Sitzung](phase-1-konten-und-sitzung.md) — Tabellen, Einladung, Passwort-Login, Akteur | heikel | pending |
| 2 | [Konto-Seiten und feste Tokens](phase-2-konto-seiten.md) — Anmeldung, Einladung annehmen, Tokens im Browser | standard | pending |
| 3 | [OAuth und Zustimmung](phase-3-oauth.md) — Discovery, Authorize/Token, Zustimmungsseite mit Anmeldung | heikel | pending |
| 4 | [MCP und Fahrzeug-Tools](phase-4-mcp-fahrzeuge.md) — Protokollschicht, vier Tools, Fakten mit Herkunft | heikel | pending |
| 5 | [Abschluss auf Strato](phase-5-abschluss.md) — Fernprüfung, Doku, Meilenstein abhaken | mechanisch | pending |

Jede Phase endet mit `deploy.cmd` und Prüfung auf Strato: lokal gibt es kein MySQL (geprüft 2026-11-16: kein MySQL-Dienst, keine `app/.env.local`).

## Entscheidungen (fallen mit diesem Plan)

| # | Entscheidung | ADR |
|---|---|---|
| E1 | Konten nur per Einladung. Die erste Einladung (und bis Meilenstein 6 jede weitere) erzeugt ein Endpunkt mit Deploy-Token; die Einladung legt bei Bedarf die Garage mit an. | 006 |
| E2 | Anmeldung wie DISPATCH: Access-JWT 15 min im Arbeitsspeicher, Refresh-Token als Cookie mit Rotation — nur mit E-Mail + Passwort aus `users` statt `.env`. | 006 |
| E3 | Fahrzeug-Stammdaten als Zeilen je Feld (`vehicle_facts`) mit Herkunft, Konfidenz und Status statt Spalten an `vehicles`. | 007 |
| E4 | Recht eines Agenten = Scope des Tokens **und** Rolle des Nutzers in der Garage. Rolle `lesend` schreibt nie, egal welcher Scope. | 008 |
| E5 | Neues Tool `create_vehicle` (Scope write) — das Konzept nennt keins, ohne gibt es kein Fahrzeug, bevor die Oberfläche kommt. | 008 |
| E6 | Nur CIMD-Clients; der manuelle Rückfall-Client aus DISPATCH entfällt. Apps ohne CIMD nutzen ein festes Token. | 008 |
| E7 | Seiten in M1 sind funktionale Platzhalter (Frontend-Konvention: Gestaltung erst in Meilenstein 4). Als Dateien `index.html`, `zustimmung.html`, `einladung.html` direkt in `web/` — keine Unterordner, sonst schickt die `.htaccess` sie an die Brücke. | — |
| E8 | `open_items` (Klärfälle) und `audit_log` entstehen schon in M1 in Endform, weil `set_vehicle_facts` beide braucht. Tools für Klärfälle kommen in M2. | 007 |
| E9 | Erlaubte Fahrzeuge je Token (Konzept: „optional“) entfallen in M1 → Follow-up. | — |

## Kontrakt

Gilt für alle Phasen; Abweichung nur per Eintrag in `FINDINGS.md` und Plan-Änderung.

### Token-Präfixe (alle Klartexte nur einmal sichtbar, gespeichert als SHA-256)

| Präfix | Art (`tokens.kind`) | Laufzeit |
|---|---|---|
| `gref_` | `user_refresh` — Sitzung im Browser, Cookie `garage_refresh`, Pfad `/garage/api/auth` | 30 Tage, Rotation, 30 s Kulanz |
| `gmcp_` | `mcp` — festes Token je Nutzer | bis 3650 Tage oder unbegrenzt |
| `goa_` | `oauth_access` | 3600 s |
| `gor_` | `oauth_refresh` | 60 Tage, Rotation |
| — | Access-JWT (HS256, `sub` = `user_id` als Text, `aud` = Basis-URL + `/garage/api`) | 900 s |

### Scopes

`garage:read`, `garage:write`, `garage:files` (+ `offline_access` nur bei OAuth). Standard bei OAuth ohne `scope`: `garage:read garage:write offline_access`. `garage:files` ist gültig, schaltet in M1 aber nichts frei.

### Rollen (`garage_members.role`)

`inhaber`, `mitglied`, `lesend`. Lesen: alle drei. Schreiben (Web und Agent): `inhaber`, `mitglied`.

### Akteur (`Garage\Domain\Actor`, readonly)

```
userId: ?int            // null nur bei deploy
name: string            // 'user:<id>', 'mcp:<token-name>', 'oauth:<client-name>', 'deploy'
via: 'web'|'mcp'|'oauth'|'deploy'
scopes: list<string>    // bei 'web' alle drei garage:-Scopes
roles: array<int,string>// garage_id => Rolle, aus garage_members geladen
garageIds(): list<int>
canRead(int $garageId): bool   // Rolle vorhanden
canWrite(int $garageId): bool  // Rolle inhaber|mitglied UND garage:write in scopes
```

Fabriken: `Actor::deploy()`, `Actor::forUser(int $userId, string $via, string $name, list<string> $scopes, PDO $pdo)` (lädt die Rollen).

### Routen (neu in M1)

| Methode | Pfad | Schutz | Phase |
|---|---|---|---|
| POST | `/garage/api/admin/invitations` | Deploy-Token | 1 |
| POST | `/garage/api/invitations/preview` | Einladungs-Token im Body | 1 |
| POST | `/garage/api/invitations/accept` | Einladungs-Token im Body | 1 |
| POST | `/garage/api/auth/login`, `/refresh`, `/logout`, `/logout-all` | — / Cookie / JWT | 1 |
| GET | `/garage/api/me` | JWT | 1 |
| GET, POST | `/garage/api/tokens` | JWT | 2 |
| DELETE | `/garage/api/tokens/{id}` | JWT, nur eigene | 2 |
| GET | `/.well-known/oauth-protected-resource/garage/mcp` | — | 3 |
| GET | `/.well-known/oauth-authorization-server/garage` | — | 3 |
| GET | `/garage/oauth/authorize` | — | 3 |
| POST | `/garage/oauth/token`, `/garage/oauth/revoke` | Client | 3 |
| GET | `/garage/api/oauth/requests/{slug}` | JWT | 3 |
| POST | `/garage/api/oauth/consent` | JWT | 3 |
| GET | `/garage/api/oauth/grants` | JWT, nur eigene | 3 |
| DELETE | `/garage/api/oauth/grants/{slug}` | JWT, nur eigene | 3 |
| POST | `/garage/mcp` | `gmcp_` oder `goa_` | 4 |

Fehler immer `{"error":"…","message":"…"}` wie bisher.

### MCP-Tools (M1)

| Tool | Scope | Eingabe | Ausgabe (Kern) |
|---|---|---|---|
| `list_vehicles` | read | — | `garages: [{id, name, role, vehicles: [{id, label, status, marke, modell, fin, open_items}]}]` — auch leere Garagen |
| `get_vehicle` | read | `vehicle_id` | `vehicle {id, garage_id, label, status}`, `facts: {<feld>: {value, source, source_url, confidence, actor, recorded_at}}`, `disputed: [{field, value, source, confidence, open_item_id}]` |
| `create_vehicle` | write | `label`, optional `garage_id` (Pflicht, wenn mehr als eine beschreibbare Garage), optional `facts` wie bei `set_vehicle_facts` | `vehicle_id` + Ergebnis der Fakten |
| `set_vehicle_facts` | write | `vehicle_id`, `facts: [{field, value, source, source_url?, confidence}]` | je Feld `result`: `angelegt` \| `aktualisiert` \| `unveraendert` \| `klaerfall` (+ `open_item_id`) |

## Finale Abnahmekriterien

- [ ] Eine Einladung aus dem Deploy-Endpunkt ergibt nach Annahme ein Konto, eine Garage mit Rolle `inhaber` und eine laufende Sitzung.
- [ ] Anmeldung mit E-Mail und Passwort; falsches Passwort und falsche E-Mail ergeben dieselbe Meldung; nach 10 Fehlversuchen in 15 Minuten sperrt die IP.
- [ ] Im Konto: feste Tokens anlegen (Klartext einmal sichtbar), auflisten, widerrufen; verbundene Apps auflisten und trennen — jeweils nur die eigenen.
- [ ] Claude (claude.ai) und ChatGPT verbinden sich per OAuth mit `https://quantum-canvas.de/garage/mcp`; die Zustimmungsseite verlangt die Anmeldung und zeigt den Host der App.
- [ ] Claude Code verbindet sich mit einem festen `gmcp_`-Token.
- [ ] Ein Agent legt ein Fahrzeug an, ergänzt Stammdaten mit Herkunft, liest sie zurück; ein Widerspruch zu einem bestätigten Wert erzeugt einen Klärfall statt zu überschreiben.
- [ ] Nutzer B sieht über keinen Weg (MCP, API) ein Fahrzeug, Token oder Grant von Nutzer A; ein Token mit Rolle `lesend` oder ohne `garage:write` kann nicht schreiben.
- [ ] `/garage-app/.env` antwortet nach jedem Deploy mit 403; der Rauchtest von `deploy.cmd` ist grün.

## Risiken und Konfidenz

- 🟡 **Discovery mit Pfad im Aussteller** (`https://quantum-canvas.de/garage`): Clients müssen die Metadaten unter `/.well-known/oauth-authorization-server/garage` suchen (RFC 8414, Pfad eingefügt). DISPATCH hat den Aussteller an der Wurzel und ist deshalb kein Beleg. Check: Phase 3, Verbindung aus claude.ai und ChatGPT; schlägt sie fehl, zuerst im Server-Log (`garage-app/storage/logs/`) nachsehen, welche `/.well-known/…`-Adresse der Client abgefragt hat.
- 🟡 **Kein lokales MySQL:** jede Handprüfung läuft auf Strato gegen die echte Datenbank. Testkonten heißen `test-a@…`/`test-b@…` und bleiben nach M1 als Status `gesperrt` stehen (nichts wird hart gelöscht).
- 🟡 **Kopierter Code driftet:** Fehlerkorrekturen in DISPATCH müssen nachgezogen werden (ADR-005). Jede Phase listet, welche DISPATCH-Dateien sie kopiert.
- Fehlerklassen geprüft: MCP — Server nach altem Schema (abgedeckt: DISPATCH-Schicht spricht 2026-07-28 und `initialize`); Strato — Werte in `.env` in einfache Anführungszeichen, Migrationen ohne Transaktion (Migrator tut das bereits).

## Summary

## Files touched

## Commits

## Deviations from plan

## Follow-ups

- Erlaubte Fahrzeuge je Token (E9).
- Einladungen und Mitgliederverwaltung in der Oberfläche (Meilenstein 6).
