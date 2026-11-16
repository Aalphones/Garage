# STATE

**Aktiver Plan:** [`docs/planning/2026-11-16_konten-mcp-oauth/`](docs/planning/2026-11-16_konten-mcp-oauth/README.md) — Meilenstein 1, freigegeben 2026-11-16
**Phase:** 3/5 — OAuth und Zustimmung (complete, deployt, per curl auf Strato geprüft; offen: echte Verbindung aus claude.ai, Zustimmungsseite nicht im Browser gesehen — siehe `FINDINGS.md`; aus Phase 1: abgelaufene Einladung und gesperrter Nutzer nicht prüfbar ohne Datenbankzugriff)
**Nächster Schritt:** Phase 4 „MCP und Fahrzeug-Tools“ umsetzen (heikel).

**Erledigt 2026-11-16:** Erster Deploy auf Strato (Datenbank verbunden, Rauchtest grün), danach DISPATCH mit Wurzel-`.htaccess`: `/garage/` trägt die eigene CSP, `/.well-known/oauth-protected-resource/garage/mcp` erreicht GARAGEs Brücke (jetzt mit Discovery-Antwort), `robots.txt` sperrt `/garage/` und `/garage-app/`.
