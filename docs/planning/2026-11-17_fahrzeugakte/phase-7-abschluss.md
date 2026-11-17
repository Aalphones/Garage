# Phase 7 — Abschluss auf Strato

Rating: **mechanisch**. Status: **pending**.

## Kontext — vorher lesen

- [`README.md`](README.md) → finale AK, Risiken, Bottom-Sektionen.
- Report-Back aller Phasen und [`FINDINGS.md`](FINDINGS.md).
- `docs/conventions/dod.md`; Vorbild: `docs/archive/2026-11/2026-11-16_konten-mcp-oauth/phase-5-abschluss.md` (Mandanten-Liste, Testkonten sperren per Migration).
- Fehlerklassen: keine einschlägigen im Vault.

## Abnahmekriterien

- [ ] Mandanten-Liste über **jedes** Tool aus M2 mit Nutzer B gegen ein Fahrzeug von A: alle `not_found` (lesend wie schreibend), Resources „unbekannt“, `GET /garage/api/open-items` zeigt B keine Klärfälle von A, `resolve` auf Klärfall von A → 404.
- [ ] Token nur `garage:read`: `tools/list` zeigt keins der schreibenden M2-Tools; Aufruf → 403 `insufficient_scope`. Rolle `lesend`: jedes schreibende Tool → `forbidden`.
- [ ] Finale AK aus der README einzeln mit Beleg abgehakt oder als „braucht Inhaber“ markiert.
- [ ] Testkonten `test-m2-…@example.com` per Migration gesperrt, ihre Tokens widerrufen; Rauchtest grün; `/garage-app/.env` → 403.

## Checkliste

- [ ] Mandanten-Liste per `curl` (Liste der Fälle mit Ergebnis in den Report-Back).
- [ ] `app/migrations/016_testkonten_m2_sperren.sql` nach Muster `007_testkonten_sperren.sql`, Muster `test-m2-%@example.com`; **nach** der Mandanten-Liste deployen.
- [ ] `deploy.cmd` (Aufruf aus PowerShell: `cmd /c "cd /d C:\Users\sasch\develop\Garage && call .\deploy.cmd < nul"`), Rauchtest grün, `.env` 403.
- [ ] `docs/PROJECT.md` → Meilenstein 2 „erledigt <Datum>“ mit Hinweis auf Offenes; `docs/models.md` Kopfzeile (Migration 016); `docs/knowledge/GAPS.md` aktualisieren.
- [ ] README Bottom-Sektionen füllen (Summary, Files touched, Commits, Deviations, Follow-ups), finale AK abhaken; Plan-Ordner nach `docs/archive/2026-11/2026-11-17_fahrzeugakte/` verschieben; `STATE.md` auf „(kein aktiver Plan) — Meilenstein 3 hat noch keinen Plan“.

## Report-Back
