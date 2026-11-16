# STATE

**Aktiver Plan:** [`docs/planning/2026-11-16_konten-mcp-oauth/`](docs/planning/2026-11-16_konten-mcp-oauth/README.md) — Meilenstein 1, freigegeben 2026-11-16
**Phase:** 2/5 — Konto-Seiten und feste Tokens (complete, deployt und auf Strato geprüft; offen aus Phase 1: abgelaufene Einladung und gesperrter Nutzer nicht prüfbar ohne Datenbankzugriff)
**Nächster Schritt:** Phase 3 „OAuth und Zustimmung“ umsetzen (heikel).

**Erledigt 2026-11-16:** Erster Deploy auf Strato (Datenbank verbunden, Rauchtest grün), danach DISPATCH mit Wurzel-`.htaccess`: `/garage/` trägt die eigene CSP, `/.well-known/oauth-protected-resource/garage/mcp` erreicht GARAGEs Brücke (404 bis Meilenstein 1), `robots.txt` sperrt `/garage/` und `/garage-app/`.
