# Phase 5 — Abschluss auf Strato

Rating: **mechanisch**. Status: **complete** (2026-11-17; echte Verbindung aus claude.ai/ChatGPT braucht den Inhaber).

## Kontext — vorher lesen

- [`README.md`](README.md) → Finale Abnahmekriterien.
- `docs/conventions/dod.md`, `docs/PROJECT.md` → Meilensteine, Offene Fragen.
- Report-Backs der Phasen 1–4 und `FINDINGS.md`.

## Abnahmekriterien

- [x] Alle finalen AK aus der README sind entweder belegt (Kommando + Ergebnis im Report-Back) oder ausdrücklich als „braucht Inhaber“ markiert (ChatGPT-Verbindung, Handy) — claude.ai und ChatGPT: braucht Inhaber.
- [x] Rauchtest von `deploy.cmd` grün, `/garage-app/.env` → 403, Nachbarn (`/`, `/card/`, `/scanner/`) unverändert erreichbar; `/dispatch/` selbst antwortet 404 (kein Index in der DISPATCH-Brücke, `/dispatch/mcp` 405/401 wie vorgesehen).
- [x] Testkonten aus den Phasen stehen auf `gesperrt`, Test-Tokens widerrufen — per Migration `007_testkonten_sperren.sql`; belegt an den Konten dieser Phase, die Konten aus Phase 1–4 tragen Adressen nach demselben Muster, einsehen lässt sich das nicht (kein Datenbankzugriff).

## Checkliste

- [x] Letzter `deploy.cmd`-Lauf, Rauchtest-Ausgabe im Report-Back.
- [x] Mandanten-Liste aus Phase 4 einmal komplett wiederholen (nach dem letzten Deploy) — vor dem letzten Deploy, auf identischem Code (der letzte Deploy brachte nur die Daten-Migration 007; sie sperrt die Konten, danach wäre die Liste nicht mehr laufbar).
- [x] Testkonten sperren — Datenbank von außen nicht erreichbar (Port 3306 zu), deshalb Migration `007_testkonten_sperren.sql` über den Deploy-Endpunkt (nur Adressen `test-…@example.com`).
- [x] `docs/PROJECT.md`: Meilenstein 1 als erledigt mit Datum; „Registrierung“, „Schreibrechte der Agenten“ (entschieden, ADR-006/008) und „Auf Strato fehlt noch die eigene MySQL-Datenbank“ gestrichen.
- [x] `docs/knowledge/GAPS.md`: Pfad-Aussteller-Discovery und Zustimmungsseite im Browser eingetragen.
- [x] `AGENTS.md`: Stack-Zeile „Agenten“ um ADR-006 ergänzt; Lokal-starten-Abschnitt um `GARAGE_JWT_SECRET`.
- [x] Bottom-Sektionen der README gefüllt, Plan nach `docs/archive/2026-11/` verschoben, `STATE.md` auf „kein aktiver Plan“ gesetzt (Meilenstein 2 hat noch keinen Plan).

## Report-Back

**Ergebnis:** Fernprüfung der finalen AK auf `https://quantum-canvas.de` mit frischen Testkonten (A, B, C; Rolle `lesend` für C): 76 Prüfungen, alle grün (zwei Prüfskript-Fehler beim ersten Lauf korrigiert: `server/discover` braucht Header `MCP-Protocol-Version`, `Mcp-Method` und `_meta`; die Zustimmung nimmt nur `garage:`-Scopes). Danach Migration 007 deployt, Rauchtest grün, gesperrte Konten geprüft.

**Belegt (Kommando: Node-Skript mit `fetch` gegen Strato, Ausgabe nur Prüfzeilen):**
- Einladung → Konto, Garage mit Rolle `inhaber`, Sitzung (`/me`); Einladung ein zweites Mal → 410.
- Login: falsches Passwort und falsche E-Mail → 401, gleicher Text; IP-Sperre: nach 10 Fehlversuchen in 15 Minuten 429 (der 11. Versuch).
- Feste Tokens anlegen/auflisten/widerrufen, nur eigene (B auf Token von A → 404); widerrufenes Token → 401.
- Mandanten: B liest/schreibt Fahrzeug von A → `not_found`; A legt in Garage von B an → `not_found`; `list_vehicles` je Nutzer nur eigene Garage; Token nur `garage:read` schreibt nicht (403 `insufficient_scope`); Rolle `lesend` schreibt nicht (`forbidden`); Klärfälle von A bei B unsichtbar.
- Fünf Zweige der Konfliktregel plus Wiederholung desselben Widerspruchs, `get_vehicle` danach wie erwartet; zehn ungültige Werte → `invalid_value`; Mischanfrage und `create_vehicle` mit ungültigem Fakt schreiben nichts.
- OAuth mit dem CIMD-Dokument von claude.ai: Discovery (beide Dokumente, Aussteller mit Pfad), `authorize`, Zustimmungs-Anfrage ohne Anmeldung 401 und mit Anmeldung mit Host `claude.ai`, Zustimmung, Token-Tausch (`goa_`/`gor_`), MCP-Aufrufe, Apps nur eigene (B sieht keine, B trennt nicht), Trennen → 401, Code zweimal → `invalid_grant`.
- `initialize` und `server/discover` (Protokoll 2026-07-28).
- Nach Migration 007: alle drei Testkonten — Login mit richtigem Passwort abgewiesen, festes Token → 401. Das schließt die offenen Fälle „gesperrter Nutzer“ aus Phase 1 und 4.
- Rauchtest: `/garage/` 200, `/garage/api/health` 200, `/garage-app/.env` 403, `/garage-app/config.php` 403, `/` `/card/` `/scanner/capture` 200; zusätzlich `/scanner/`, Discovery-Adressen, `robots.txt` 200.

**Braucht Inhaber (nicht stellvertretend abgehakt):**
- Verbindung aus claude.ai mit `https://quantum-canvas.de/garage/mcp`: findet der Client den Pfad-Aussteller? Zustimmungsseite im Browser mit echter App.
- Verbindung aus ChatGPT (gleiche Frage) — und alles auf dem Handy.
- `claude mcp add --transport http garage https://quantum-canvas.de/garage/mcp --header "Authorization: Bearer <gmcp_-Token aus dem Konto>"` mit dem echten `claude`-Client.

**Nicht geprüft:** `audit_log`-Inhalte (kein Lese-Endpunkt, keine Datenbank); Konten aus Phase 1–4 nicht einzeln als gesperrt gesehen; abgelaufene Einladung (kein Weg, die Zeit zu verschieben).

**Abweichungen:** Testkonten per Migration statt `UPDATE` über die Datenbank. `/dispatch/` liefert 404 (war nie ein Rauchtest-Pfad, `/dispatch/mcp` verhält sich wie vorgesehen).

**Wackligste Stelle:** `app/migrations/007_testkonten_sperren.sql` sperrt per Muster `test-%@example.com`; ein künftiges echtes Konto mit solcher Adresse wäre nur betroffen, wenn es vor dem Deploy der Datei existiert — danach läuft sie nie wieder.
