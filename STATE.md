# STATE

**Aktiver Plan:** [`docs/planning/2026-11-16_konten-mcp-oauth/`](docs/planning/2026-11-16_konten-mcp-oauth/README.md) — Meilenstein 1, freigegeben 2026-11-16
**Phase:** 4/5 — MCP und Fahrzeug-Tools (complete, deployt, per curl auf Strato geprüft: Mandanten-Fälle, fünf Zweige der Konfliktregel, `gmcp_` und `goa_`; offen: echte Verbindung aus claude.ai/ChatGPT und Zustimmungsseite im Browser, siehe `FINDINGS.md`; aus Phase 1: abgelaufene Einladung und gesperrter Nutzer nicht prüfbar ohne Datenbankzugriff)
**Nächster Schritt:** Phase 5 „Abschluss auf Strato“ umsetzen (mechanisch). Testkonten `test-m4-a/b/c@example.com` (Garagen 12, 13) stehen noch aktiv auf Strato.

**Erledigt 2026-11-16:** Erster Deploy auf Strato (Datenbank verbunden, Rauchtest grün), danach DISPATCH mit Wurzel-`.htaccess`: `/garage/` trägt die eigene CSP, `/.well-known/oauth-protected-resource/garage/mcp` erreicht GARAGEs Brücke (jetzt mit Discovery-Antwort), `robots.txt` sperrt `/garage/` und `/garage-app/`.
