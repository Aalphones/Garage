# STATE

**Aktiver Plan:** [`docs/planning/2026-11-16_konten-mcp-oauth/`](docs/planning/2026-11-16_konten-mcp-oauth/README.md) — Meilenstein 1, freigegeben 2026-11-16
**Phase:** 1/5 — Konten und Sitzung (complete, deployt und auf Strato geprüft; offen: abgelaufene Einladung und gesperrter Nutzer nicht prüfbar ohne Datenbankzugriff)
**Nächster Schritt:** Phase 2 „Konto-Seiten und feste Tokens“ umsetzen.

**Erledigt 2026-11-16:** Erster Deploy auf Strato (Datenbank verbunden, Rauchtest grün), danach DISPATCH mit Wurzel-`.htaccess`: `/garage/` trägt die eigene CSP, `/.well-known/oauth-protected-resource/garage/mcp` erreicht GARAGEs Brücke (404 bis Meilenstein 1), `robots.txt` sperrt `/garage/` und `/garage-app/`.
