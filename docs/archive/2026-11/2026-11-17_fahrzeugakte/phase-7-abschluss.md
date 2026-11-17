# Phase 7 — Abschluss auf Strato

Rating: **mechanisch**. Status: **complete** (2026-11-17; echte Verbindung aus claude.ai/ChatGPT braucht den Inhaber).

## Kontext — vorher lesen

- [`README.md`](README.md) → finale AK, Risiken, Bottom-Sektionen.
- Report-Back aller Phasen und [`FINDINGS.md`](FINDINGS.md).
- `docs/conventions/dod.md`; Vorbild: `docs/archive/2026-11/2026-11-16_konten-mcp-oauth/phase-5-abschluss.md` (Mandanten-Liste, Testkonten sperren per Migration).
- Fehlerklassen: keine einschlägigen im Vault.

## Abnahmekriterien

- [x] Mandanten-Liste über **jedes** Tool aus M2 mit Nutzer B gegen ein Fahrzeug von A: alle `not_found` (lesend wie schreibend), Resources „unbekannt“, `GET /garage/api/open-items` zeigt B keine Klärfälle von A, `resolve` auf Klärfall von A → 404.
- [x] Token nur `garage:read`: `tools/list` zeigt keins der schreibenden M2-Tools; Aufruf → 403 `insufficient_scope`. Rolle `lesend`: jedes schreibende Tool → `forbidden`.
- [x] Finale AK aus der README einzeln mit Beleg abgehakt oder als „braucht Inhaber“ markiert.
- [x] Testkonten `test-m2-…@example.com` per Migration gesperrt, ihre Tokens widerrufen; Rauchtest grün; `/garage-app/.env` → 403. (Migration lief, Rauchtest grün; die Sperre selbst ist an keinem Konto gesehen, siehe Report-Back.)

## Checkliste

- [x] Mandanten-Liste per `curl` (Liste der Fälle mit Ergebnis in den Report-Back).
- [x] `app/migrations/016_testkonten_m2_sperren.sql` nach Muster `007_testkonten_sperren.sql`, Muster `test-m2-%@example.com` und `p2r%@example.com`; **nach** der Mandanten-Liste deployt.
- [x] `deploy.cmd` (Aufruf aus PowerShell: `cmd /c "cd /d C:\Users\sasch\develop\Garage && call .\deploy.cmd < nul"`), Rauchtest grün, `.env` 403.
- [x] `docs/PROJECT.md` → Meilenstein 2 „erledigt 2026-11-17“ mit Hinweis auf Offenes; `docs/models.md` Kopfzeile (Migration 011–015 und 016); `docs/knowledge/GAPS.md` aktualisiert.
- [x] README Bottom-Sektionen gefüllt, finale AK abgehakt; Plan-Ordner nach `docs/archive/2026-11/2026-11-17_fahrzeugakte/` verschoben; `STATE.md` auf „(kein aktiver Plan) — Meilenstein 3 hat noch keinen Plan“.

## Report-Back

**Ergebnis:** Mandanten-Liste auf `https://quantum-canvas.de` mit frischen Testkonten (A Inhaber, B Inhaber einer anderen Garage, C `lesend` in Garage von A): 96 Prüfungen, alle grün (Node-Skript mit `fetch`, nur Prüfzeilen ausgegeben). Danach Migration 016 deployt, Rauchtest grün.

**Belegt:**
- B gegen Fahrzeug/Ereignis von A, alle 28 Tools (Skript prüft, dass `tools/list` genau diese 28 enthält): lesend und schreibend `not_found`; `create_vehicle` in Garage von A → `not_found`; Verknüpfungen auf ein Ereignis von A aus dem eigenen Fahrzeug (`record_measurement` mit `event_id`, `record_damage` mit `repair_event_id`, `record_recall` mit `event_id`) → `not_found`.
- Listen ohne Fahrzeug (`list_vehicles`, `list_open_items`, `list_changes`, `get_due_items`, `search`): nichts von A; Gegenprobe: A findet seine Daten (`search`), A-Akte nach den Fremdversuchen unverändert (`get_vehicle`, `get_dossier`).
- Resources: B liest `akte` und `faelligkeiten` von A → `-32602` „unbekannt“, gleiche Meldung wie bei unbekannter ID; `garage://fahrzeuge` zeigt B nur die eigene Garage; A und Token nur `garage:read` lesen ihre Akte.
- Web: `GET /api/open-items` zeigt B keinen Klärfall von A; `resolve` durch B → 404, durch C (`lesend`) → 403, mit `gmcp_`-Token → 401, unbekannte ID → 404; Klärfälle von A danach noch offen.
- Rolle `lesend` (C): alle 16 schreibenden Tools `forbidden`; lesen geht.
- Token nur `garage:read`: `tools/list` zeigt die 12 lesenden Tools, keins der 16 schreibenden; jeder Aufruf eines schreibenden → HTTP 403, Fehler `-32003`, `WWW-Authenticate` mit `insufficient_scope`.
- Rauchtest: `/garage/` 200, `/garage/api/health` 200, `/garage-app/.env` 403, `/garage-app/config.php` 403, `/` 200, `/card/` 200, `/scanner/capture` 200. Deploy-Antwort: `{"migrations":["016_testkonten_m2_sperren.sql"]}`.

**Braucht Inhaber:** echte Verbindung aus claude.ai/ChatGPT, `claude mcp add` mit echtem Client, Handy (alles aus Meilenstein 1 offen geblieben).

**Nicht geprüft:** Sperre der Testkonten selbst — die Passwörter der Prüfläufe werden nicht aufbewahrt und die Datenbank ist von außen nicht erreichbar; die Anweisung gleicht der von Migration 007, die in Meilenstein 1 an Konten belegt wurde. `audit_log`-Inhalte der Phasen 1–4 und Notiz-Revision 1 nur im Code.

**Abweichungen:** Der erste Lauf des Skripts hatte einen Prüffehler (die Fehlermeldung `insufficient_scope` steht im Header `WWW-Authenticate`, nicht im Text); Skript korrigiert, Lauf wiederholt (zwei Läufe, beide Kontengruppen vor Migration 016 angelegt, daher von ihr erfasst). Ein einzelner Deploy-Versuch aus der Bash-Umgebung hing und wurde abgebrochen; der Deploy lief danach aus PowerShell durch (Hochladen ist idempotent). Eine unbenutzte Einladung („x“, 1 Tag) blieb liegen.

**Wackligste Stelle:** `app/migrations/016_testkonten_m2_sperren.sql` — zwei `LIKE`-Muster mit `OR` in `UPDATE` und Unterabfrage; ihre Wirkung ist an keinem Konto gesehen. Prüfung durch den Inhaber: Login mit einem Konto `test-m2-…@example.com` (Passwort unbekannt) ist ohnehin nicht möglich; einziger Weg wäre eine Datenbankabfrage im Strato-Kundenbereich: `SELECT email, status FROM users WHERE email LIKE 'test-m2-%' OR email LIKE 'p2r%';`.
