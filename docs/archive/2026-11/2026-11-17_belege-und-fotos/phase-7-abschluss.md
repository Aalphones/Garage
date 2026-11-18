# Phase 7 — Abschluss auf Strato

**Rating:** mechanisch. Modell: `sonnet`. Status: **complete** (2026-11-18; Inhaber-Tests offen).

## Kontext — vor dem ersten Edit lesen

- Plan-README: [`README.md`](README.md) — finale Abnahmekriterien, Kontrakt.
- Report-Backs der Phasen 1–6, `FINDINGS.md`.
- Vorbild: [`../../archive/2026-11/2026-11-17_fahrzeugakte/phase-7-abschluss.md`](../../../archive/2026-11/2026-11-17_fahrzeugakte/phase-7-abschluss.md) (Mandanten-Liste, Testkonten-Sperre per Migration, Archivieren) und `app/migrations/016_testkonten_m2_sperren.sql`.
- `mode-implementing` → Archivieren; `docs/knowledge/GAPS.md`, `docs/PROJECT.md`.
- Vault-Fehlerklassen: keine einschlägig.

## Abnahmekriterien

- [x] Mandanten-Liste grün, je Punkt mit curl gegen Strato belegt: Nutzer B gegen Dokument/Eingang von A über `get_document`, `list_documents`, `attach_document`, `store_document {ticket_id}` mit A's Ticket-ID, `request_upload {document_id}`, `confirm_document`, `list_inbox`, `claim_inbox_item {id}`, `release_inbox_item`, alle `/garage/api/documents…`- und `/garage/api/inbox…`-Routen → `not_found`/404; Rolle `lesend` → alle schreibenden `forbidden`/403; Token nur `garage:read` → keine Datei-Tools in `tools/list`, Aufruf → 403 `insufficient_scope`; `gmcp_`-Token an `/garage/api/documents` → 401.
- [x] Ticket-Missbrauch: abgelaufen 410, doppelt 410, Slug geraten 404; signierter Link mit verändertem Payload 404.
- [x] `/garage-app/.env`, `/garage-app/storage/documents/` → 403; Rauchtest von `deploy.cmd` grün (Nachbarn `/`, `/card/`, `/scanner/capture` 200).
- [x] Migration `019_testkonten_m3_sperren.sql` sperrt `test-m3-…@example.com` und widerruft deren Tokens (Form wie 016).
- [x] Doku nachgezogen und Plan archiviert; `STATE.md` zeigt den nächsten Meilenstein.

## Checkliste

- [x] Mandanten-Liste als Skript im Scratchpad (nicht im Repo), Ergebnis als Zählung im Report-Back.
- [x] `019_testkonten_m3_sperren.sql` schreiben, deployen.
- [x] Verprobungs-Matrix für den Inhaber in `docs/knowledge/GAPS.md` als offene Zeile anlegen und im Plan-README unter „Follow-ups“ als Tabelle: Zeilen Claude Web, Claude Android, ChatGPT Web, ChatGPT Android, Claude Code; Spalten Weg A (Widget / Datei-Parameter / Ticket), Weg B (`claim_inbox_item` mit Bildern), Teilen-Ziel (nur Android). Schon belegte Zellen (Claude Code per curl) eintragen, Rest „offen – Inhaber“.
- [x] `docs/PROJECT.md` Meilenstein 3 „erledigt <Datum>“ mit Belegstand; offene Frage „Belegweg auf dem Handy“ verweist auf die Matrix.
- [x] `docs/code-map.md` Kopfzeile („Steht: … Belege, Eingang …“), `docs/models.md`, `docs/routes.md`, `docs/glossary.md` gegen den Code abgleichen.
- [x] README des Plans: Summary, Files touched, Commits, Deviations, Follow-ups ausfüllen; Ordner nach `docs/archive/2026-11/` (oder Monat des Abschlusses) verschieben; `STATE.md`: kein aktiver Plan, nächster Schritt Meilenstein 4 „Oberfläche“ planen; `AGENTS.md` unverändert (Zeile „Aktive Arbeit → STATE.md“ bleibt).

## Report-Back

**Ergebnis:** Mandanten-Liste auf `https://quantum-canvas.de` mit frischen Testkonten (A Inhaber, B Inhaber einer anderen Garage, C `lesend` in Garage von A): 86 Prüfungen, alle grün (zwei Läufe eines Node-Skripts im Scratchpad, nur Prüfzeilen). Danach Migration 019 deployt, Rauchtest grün, `/garage-app/.env` und `/garage-app/storage/documents/` 403.

**Belegt:**
- B gegen Dokumente, Wartend-Dokument und Eingang von A: `get_document`, `list_documents` (auch mit Fahrzeug von A), `attach_document` (in beide Richtungen, auch an ein Ereignis von A), `store_document` mit Ticket-ID von A und mit Fahrzeug von A, `request_upload` mit Fahrzeug von A und `document_id` von A, `confirm_document`, `claim_inbox_item {id}`, `release_inbox_item`, `get_event`, `search` → `not_found` beziehungsweise nichts von A; `claim_inbox_item` ohne `id` → `item: null`; A danach unverändert (Titel, Status, Eingang weiter `neu`).
- Web-API mit B: `GET`/`PATCH`/`file`/`discard`/`restore` auf Dokument von A, `POST /api/documents` mit Garage oder Fahrzeug von A, `POST /api/inbox` mit Dokument von A, `discard`/`restore` auf Eingang von A → 404; Listen enthalten nichts von A.
- Rolle `lesend` (C): alle 7 schreibenden Datei- und Eingangs-Tools `forbidden`, alle schreibenden Web-Routen 403, Lesen geht (MCP und Web 200).
- Token nur `garage:read`: `tools/list` ohne die 4 Datei- und die 3 Schreib-Tools, Aufruf jedes davon 403 mit `insufficient_scope`; Token `garage:read garage:write` ohne `garage:files`: Datei-Tools fehlen, `request_upload` 403; `gmcp_`-Token an `/garage/api/documents`, `…/{id}`, `/garage/api/inbox` und `POST /api/inbox` → 401, ohne Anmeldung 401.
- Tickets: doppelt 410 `ticket_used`, nach über 900 s 410 `ticket_expired`, geratener und fehlgeformter Slug 404; Links: gültig 200, veränderter Payload, verlängerte Laufzeit, abgeschnittene oder fehlende Signatur 404, nach über 600 s 404.
- Nachbarn nach dem Deploy: `/garage/`, `/garage/api/health`, `/`, `/card/`, `/scanner/capture` 200.

**Braucht Inhaber:** Handy-Test Teilen-Ziel, Claude-Web-Test des Widgets, Verprobungs-Matrix (README → Follow-ups), echte Verbindung aus claude.ai/ChatGPT (Meilenstein 1).

**Nicht geprüft:** Sperre der Testkonten selbst (kein Datenbankzugriff, Passwörter und Tokens der Läufe nicht aufbewahrt); Abfrage für den Inhaber im README unter Follow-ups.

**Abweichungen:** Migration 019 sperrt auch `…@example.invalid` (Form der Testkonten aus Phase 1 bis 6, dort nicht `test-m3-…`); erster Lauf mit Prüffehler beim Ereignis (falsche Art/Quelle), die Ereignisprüfungen liefen im zweiten Lauf ohne Wartezeit.

**Wackligste Stelle:** `019_testkonten_m3_sperren.sql` — das Muster `%@example.invalid` ist breiter als im Plan; wirkt nur auf reservierte Domänen, aber an keinem Konto gesehen.
