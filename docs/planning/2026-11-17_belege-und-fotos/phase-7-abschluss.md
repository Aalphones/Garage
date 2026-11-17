# Phase 7 — Abschluss auf Strato

**Rating:** mechanisch. Modell: `sonnet`.

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — finale Abnahmekriterien, Kontrakt.
- Report-Backs der Phasen 1–6, `FINDINGS.md`.
- Vorbild: [`../../archive/2026-11/2026-11-17_fahrzeugakte/phase-7-abschluss.md`](../../archive/2026-11/2026-11-17_fahrzeugakte/phase-7-abschluss.md) (Mandanten-Liste, Testkonten-Sperre per Migration, Archivieren) und `app/migrations/016_testkonten_m2_sperren.sql`.
- `mode-implementing` → Archivieren; `docs/knowledge/GAPS.md`, `docs/PROJECT.md`.
- Vault-Fehlerklassen: keine einschlägig.

## Abnahmekriterien

- [ ] Mandanten-Liste grün, je Punkt mit curl gegen Strato belegt: Nutzer B gegen Dokument/Eingang von A über `get_document`, `list_documents`, `attach_document`, `store_document {ticket_id}` mit A's Ticket-ID, `request_upload {document_id}`, `confirm_document`, `list_inbox`, `claim_inbox_item {id}`, `release_inbox_item`, alle `/garage/api/documents…`- und `/garage/api/inbox…`-Routen → `not_found`/404; Rolle `lesend` → alle schreibenden `forbidden`/403; Token nur `garage:read` → keine Datei-Tools in `tools/list`, Aufruf → 403 `insufficient_scope`; `gmcp_`-Token an `/garage/api/documents` → 401.
- [ ] Ticket-Missbrauch: abgelaufen 410, doppelt 410, Slug geraten 404; signierter Link mit verändertem Payload 404.
- [ ] `/garage-app/.env`, `/garage-app/storage/documents/` → 403; Rauchtest von `deploy.cmd` grün (Nachbarn `/`, `/card/`, `/scanner/capture` 200).
- [ ] Migration `019_testkonten_m3_sperren.sql` sperrt `test-m3-…@example.com` und widerruft deren Tokens (Form wie 016).
- [ ] Doku nachgezogen und Plan archiviert; `STATE.md` zeigt den nächsten Meilenstein.

## Checkliste

- [ ] Mandanten-Liste als Skript im Scratchpad (nicht im Repo), Ergebnis als Zählung im Report-Back.
- [ ] `019_testkonten_m3_sperren.sql` schreiben, deployen.
- [ ] Verprobungs-Matrix für den Inhaber in `docs/knowledge/GAPS.md` als offene Zeile anlegen und im Plan-README unter „Follow-ups“ als Tabelle: Zeilen Claude Web, Claude Android, ChatGPT Web, ChatGPT Android, Claude Code; Spalten Weg A (Widget / Datei-Parameter / Ticket), Weg B (`claim_inbox_item` mit Bildern), Teilen-Ziel (nur Android). Schon belegte Zellen (Claude Code per curl) eintragen, Rest „offen – Inhaber“.
- [ ] `docs/PROJECT.md` Meilenstein 3 „erledigt <Datum>“ mit Belegstand; offene Frage „Belegweg auf dem Handy“ verweist auf die Matrix.
- [ ] `docs/code-map.md` Kopfzeile („Steht: … Belege, Eingang …“), `docs/models.md`, `docs/routes.md`, `docs/glossary.md` gegen den Code abgleichen.
- [ ] README des Plans: Summary, Files touched, Commits, Deviations, Follow-ups ausfüllen; Ordner nach `docs/archive/2026-11/` (oder Monat des Abschlusses) verschieben; `STATE.md`: kein aktiver Plan, nächster Schritt Meilenstein 4 „Oberfläche“ planen; `AGENTS.md` unverändert (Zeile „Aktive Arbeit → STATE.md“ bleibt).

## Report-Back
