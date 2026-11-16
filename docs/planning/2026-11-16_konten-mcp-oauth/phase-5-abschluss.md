# Phase 5 — Abschluss auf Strato

Rating: **mechanisch**.

## Kontext — vorher lesen

- [`README.md`](README.md) → Finale Abnahmekriterien.
- `docs/conventions/dod.md`, `docs/PROJECT.md` → Meilensteine, Offene Fragen.
- Report-Backs der Phasen 1–4 und `FINDINGS.md`.

## Abnahmekriterien

- [ ] Alle finalen AK aus der README sind entweder belegt (Kommando + Ergebnis im Report-Back) oder ausdrücklich als „braucht Inhaber“ markiert (ChatGPT-Verbindung, Handy).
- [ ] Rauchtest von `deploy.cmd` grün, `/garage-app/.env` → 403, Nachbarn (`/`, `/dispatch/`, `/card/`, `/scanner/`) unverändert erreichbar.
- [ ] Testkonten aus den Phasen stehen auf `gesperrt`, Test-Tokens widerrufen.

## Checkliste

- [ ] Letzter `deploy.cmd`-Lauf, Rauchtest-Ausgabe im Report-Back.
- [ ] Mandanten-Liste aus Phase 4 einmal komplett wiederholen (nach dem letzten Deploy).
- [ ] Testkonten sperren (`UPDATE users SET status='gesperrt' WHERE email LIKE 'test-%'` über den Strato-Datenbankzugang — oder, falls nicht erreichbar, als Punkt „braucht Inhaber“ melden).
- [ ] `docs/PROJECT.md`: Meilenstein 1 als erledigt mit Datum; Offene Frage „Registrierung“ als entschieden (ADR-006) streichen; „Auf Strato fehlt noch die eigene MySQL-Datenbank“ streichen, falls erledigt.
- [ ] `docs/knowledge/GAPS.md`: Erkenntnisse zur Discovery mit Pfad-Aussteller eintragen, falls in Phase 3 aufgetreten.
- [ ] `AGENTS.md`: Stack-Zeile „Agenten“ um „Konten per Einladung (ADR-006)“ ergänzen; Lokal-starten-Abschnitt um `GARAGE_JWT_SECRET`.
- [ ] Bottom-Sektionen der README füllen, Plan nach `docs/archive/2026-11/` verschieben, `STATE.md` auf nächsten Plan (Meilenstein 2) setzen.

## Report-Back
